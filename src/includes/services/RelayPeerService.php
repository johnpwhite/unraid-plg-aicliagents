<?php
/**
 * <module_context>
 *     <name>RelayPeerService</name>
 *     <description>docs/specs/RELAY_LINKED_BOXES.md — linked boxes, Phase 1:
 *     workspaces on two explicitly paired Unraid boxes send each other direct
 *     messages. It owns pairing (one-time code), the signed box-to-box path
 *     `/peer/v1/*` on the existing consent-first listener, the per-peer outbox
 *     (store and forward, idempotent message ids, back-off), key rotation,
 *     unlink, the automatic re-pin after a signed channel-binding check, and
 *     the cached peer contacts. An inbound message enters the SAME local
 *     delivery path as a local direct message:
 *     AgentRelayService::storeAndDeliverDirect().</description>
 *     <dependencies>AgentRelayService, RelayPeerStore, RelayPeerCrypto,
 *     RelayPeerClient, RelayGrants, ConfigService, ProcessManager</dependencies>
 *     <constraints>The listener process never makes an outbound call (no
 *     box-to-box deadlock). Sender identity comes only from the signed
 *     connection. Forwarded text is data, never authority. A message is never
 *     forwarded a second time (hop 1). No message text in any log or audit.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class RelayPeerService {
    const PREFIX = '/peer/v1/';
    const FEATURES = ['dm', 'contacts'];
    const PAIRING_TTL = 900;              // 15 minutes (spec §2)
    const PAIRING_MAX_FAILURES = 5;       // then the code is burned
    const REKEY_GRACE = 600;              // old key accepted 10 more minutes
    const NONCE_TTL = 600;
    const DELIVER_MAX_ITEMS = 20;         // receiver cap per batch
    const SEND_BATCH = 10;                // sender batch size
    const ONLINE_WINDOW = 120;            // a peer is online when it answered in the last 2 minutes
    const HELLO_INTERVAL = 60;            // hello + contacts refresh
    const BACKOFF = [5, 15, 30, 60, 120, 300];
    const EXPIRE_AFTER = 604800;          // 7 days
    const QUEUE_NOTICE_AFTER = 300;       // tray entry after 5 minutes
    const IMMEDIATE_BUDGET = 3;           // first send attempt from directMessage()
    const CONTACTS_MAX = 500;
    const SEEN_MAX = 5000;
    /** remote_state values a sender can see (spec §5). */
    const REMOTE_STATES = ['queued', 'forwarded', 'delivered', 'deferred', 'recipient_offline', 'rejected', 'expired', 'peer_unlinked'];

    /** True inside relay-http-server.php: the listener must never call out. */
    public static bool $inListener = false;
    /** Test seam: the clock. */
    public static ?int $now = null;

    /** @var array<string,array{0:int,1:int}> per-IP failed-auth counter */
    private static array $failures = [];
    /** @var array<string,array{0:int,1:int}> per-credential counter */
    private static array $credential = [];

    public static function resetLimiters(): void { self::$failures = []; self::$credential = []; }
    private static function now(): int { return self::$now ?? time(); }

    // =====================================================================
    // Identity of this box
    // =====================================================================

    public static function enabled(): bool { return AgentRelayService::peerLinksEnabled(); }

    public static function selfName(): string {
        $e = getenv('AICLI_RELAY_BOX_NAME');   // test seam
        if ($e !== false && $e !== '') return RelayPeerStore::cleanLabel($e, 'Unraid');
        $ident = @parse_ini_file('/boot/config/ident.cfg') ?: [];
        return RelayPeerStore::cleanLabel((string)($ident['NAME'] ?? 'Unraid'), 'Unraid');
    }

    public static function selfCertFile(): string {
        $e = getenv('AICLI_RELAY_TLS_CERT');   // test seam, also read by relay-http-server.php
        if ($e !== false && $e !== '') return $e;
        return (string)AgentRelayService::unraidSslProfile()['cert'];
    }

    public static function selfSpki(): string { return RelayPeerCrypto::spkiFromFile(self::selfCertFile()); }

    /**
     * The address this box offers in its pairing code: the LAN IP address first
     * (spec §3, "URL choice"). The administrator can edit it on the other box.
     */
    public static function selfUrl(): string {
        $e = getenv('AICLI_RELAY_PEER_SELF_URL');   // test seam
        if ($e !== false && $e !== '') return $e;
        $l = AgentRelayService::httpListenerSettings();
        $ip = $l['bind'] !== '0.0.0.0' ? $l['bind'] : '';
        if ($ip === '') {
            $net = @parse_ini_file('/boot/config/network.cfg') ?: [];
            foreach (['IPADDR:0', 'IPADDR'] as $k) if (!empty($net[$k]) && filter_var($net[$k], FILTER_VALIDATE_IP)) { $ip = (string)$net[$k]; break; }
        }
        if ($ip === '') {
            $h = trim((string)@shell_exec('hostname -I 2>/dev/null'));
            $first = strtok($h, ' ');
            if ($first !== false && filter_var($first, FILTER_VALIDATE_IP)) $ip = $first;
        }
        if ($ip === '') $ip = gethostname() ?: 'localhost';
        if (strpos($ip, ':') !== false) $ip = "[$ip]";
        return "https://$ip:{$l['port']}/";
    }

    private static function version(): string {
        try { return (string)ConfigService::getVersion(); } catch (\Throwable $e) { return ''; }
    }

    // =====================================================================
    // Addressing (spec §4)
    // =====================================================================

    /** A contact id for a workspace on a linked box: peer_<12 hex>__<remote session id>. */
    public static function isPeerContactId(string $id): bool {
        return (bool)preg_match('/^peer_[a-f0-9]{12}__[A-Za-z0-9_-]{1,109}$/', $id);
    }

    /** @return array{0:string,1:string} [peer row id, remote session id] */
    public static function splitContactId(string $id): array {
        if (!self::isPeerContactId($id)) return ['', ''];
        return [substr($id, 0, 17), substr($id, 19)];
    }

    public static function contactId(string $peerId, string $remoteSession): string {
        return $peerId . '__' . $remoteSession;
    }

    /** "<text> @ <label>", printable ASCII, short enough for the terminal notice sanitiser (48). */
    public static function remoteDisplayName(string $name, array $row): string {
        $clean = static function (string $s, int $max): string {
            $s = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^\x20-\x7E]/', '', $s)));
            return strlen($s) > $max ? rtrim(substr($s, 0, $max - 1)) . '~' : $s;
        };
        $label = $clean(RelayPeerStore::displayName($row), 16);
        $name = $clean($name, 28);
        return ($name !== '' ? $name : 'workspace') . ' @ ' . ($label !== '' ? $label : 'peer');
    }

    public static function peerOnline(array $row): bool {
        $t = $row['last_ok_at'] !== '' ? (strtotime($row['last_ok_at']) ?: 0) : 0;
        return $row['state'] === 'linked' && $t > 0 && (self::now() - $t) <= self::ONLINE_WINDOW;
    }

    private static function peerState(array $row): string {
        if ($row['state'] !== 'linked') return $row['state'];
        return self::peerOnline($row) ? 'online' : 'offline';
    }

    /**
     * Contacts of every usable peer, from the cache written by the tick. Never
     * a network call, so relay_list_contacts stays fast.
     */
    public static function contactRows(): array {
        if (!self::enabled()) return [];
        $out = [];
        foreach (RelayPeerStore::peers() as $row) {
            if (in_array($row['state'], ['unlinked', 'unlinked_by_peer'], true)) continue;
            $cache = RelayPeerStore::readRuntime($row['id'], 'contacts', []);
            $online = self::peerOnline($row);
            foreach ((array)($cache['contacts'] ?? []) as $c) {
                if (!is_array($c)) continue;
                $sid = (string)($c['session_id'] ?? '');
                $id = self::contactId($row['id'], $sid);
                if (!self::isPeerContactId($id)) continue;
                $out[] = [
                    'session_id' => $id,
                    'name' => self::remoteDisplayName((string)($c['name'] ?? $sid), $row),
                    'agent_id' => (string)($c['agent_id'] ?? ''),
                    'online' => $online && !empty($c['online']),
                    'box' => RelayPeerStore::displayName($row),
                    'peer_state' => self::peerState($row),
                ];
            }
        }
        return $out;
    }

    /** A peer id known only from DM history is a contact while its link is usable. */
    public static function historyContactUsable(string $id): bool {
        if (!self::enabled()) return false;
        [$peerId] = self::splitContactId($id);
        $row = $peerId !== '' ? RelayPeerStore::find($peerId) : null;
        return $row !== null && !in_array($row['state'], ['unlinked', 'unlinked_by_peer'], true);
    }

    public static function decorateHistoryContact(array $contact): array {
        [$peerId] = self::splitContactId((string)$contact['session_id']);
        $row = RelayPeerStore::find($peerId);
        if ($row === null) return $contact;
        $contact['online'] = false;
        $contact['box'] = RelayPeerStore::displayName($row);
        $contact['peer_state'] = self::peerState($row);
        return $contact;
    }

    /** Session ids this box exports to one peer, without the (slower) online check. */
    public static function exportedIds(array $row): array {
        return array_column(self::exportContacts($row, false), 'session_id');
    }

    /** Local saved workspaces this box exports to one peer (spec §4, "Contacts"). */
    public static function exportContacts(array $row, bool $withOnline = true): array {
        $principal = RelayGrants::principal(RelayGrants::KIND_PEER, $row);
        if (!RelayGrants::allows($principal, 'contacts.read')) return [];
        $seen = []; $out = [];
        $all = ConfigService::getWorkspaces()['sessions'] ?? [];
        foreach (ConfigService::getManagedWorkspaces() as $mid => $m) if (is_array($m)) $all[] = $m + ['id' => (string)$mid];
        foreach ($all as $w) {
            if (!is_array($w)) continue;
            $sid = (string)($w['id'] ?? '');
            if (isset($seen[$sid]) || !self::exportable($sid)) continue;
            if (!RelayGrants::allows($principal, 'contacts.read', $sid)) continue;
            $seen[$sid] = true;
            $out[] = [
                'session_id' => $sid,
                'name' => (string)substr((string)preg_replace('/[^\x20-\x7E]/', '', (string)($w['name'] ?? $sid)), 0, 48),
                'agent_id' => (string)($w['agentId'] ?? ''),
                'online' => $withOnline && ProcessManager::isRunning($sid),
            ];
            if (count($out) >= self::CONTACTS_MAX) break;
        }
        return $out;
    }

    /** A local id that may be offered to a peer: a plain saved workspace id that fits a contact id. */
    private static function exportable(string $sid): bool {
        if (!preg_match('/^[A-Za-z0-9_-]{1,109}$/', $sid)) return false;
        if (strpos($sid, 'peer_') === 0 || strpos($sid, 'remote_') === 0) return false;
        return AgentRelayService::remoteName($sid) === '';
    }

    // =====================================================================
    // Pairing — this box shows a code (box A)
    // =====================================================================

    public static function createPairingCode(string $urlOverride = ''): array {
        if (!self::enabled()) return ['status' => 'error', 'message' => 'Turn on the listener and linked boxes first.'];
        $spki = self::selfSpki();
        if ($spki === '') return ['status' => 'error', 'message' => 'This box has no usable HTTPS certificate for the listener.'];
        $live = array_filter(RelayPeerStore::peers(), static fn(array $r): bool => $r['state'] !== 'unlinked');
        if (count($live) >= RelayPeerStore::MAX_PEERS) return ['status' => 'error', 'message' => 'This box is linked to ' . RelayPeerStore::MAX_PEERS . ' boxes already. Unlink one first.'];
        $url = $urlOverride !== '' ? $urlOverride : self::selfUrl();
        if (!RelayPeerClient::validBaseUrl($url)) return ['status' => 'error', 'message' => 'Enter an address like https://192.168.1.10:8237/.'];
        $secret = bin2hex(random_bytes(16));
        $box = RelayPeerStore::boxId();
        $name = self::selfName();
        // One code at a time: a new code burns the previous one.
        if (!RelayPeerStore::setPairingSecret($secret)) return ['status' => 'error', 'message' => 'Could not save the pairing code.'];
        $d = RelayPeerStore::read();
        $d['pairing'] = ['expires_at' => self::now() + self::PAIRING_TTL, 'failures' => 0, 'created_at' => gmdate('c', self::now())];
        RelayPeerStore::write($d);
        RelayPeerStore::audit('_box', 'pairing_code_created', ['expires_at' => $d['pairing']['expires_at']]);
        return [
            'status' => 'ok',
            'code' => RelayPeerCrypto::encodePairingCode($box, $spki, $secret, $name, $url),
            'expires_at' => $d['pairing']['expires_at'],
            'expires_in' => self::PAIRING_TTL,
            'name' => $name, 'url' => $url, 'spki' => $spki,
        ];
    }

    public static function cancelPairingCode(): array {
        RelayPeerStore::setPairingSecret(null);
        $d = RelayPeerStore::read(); $d['pairing'] = null; RelayPeerStore::write($d);
        return ['status' => 'ok'];
    }

    private static function burnPairing(): void {
        RelayPeerStore::setPairingSecret(null);
        $d = RelayPeerStore::read(); $d['pairing'] = null; RelayPeerStore::write($d);
    }

    /** Inbound POST /peer/v1/pair (spec §2 steps 4–5). */
    private static function handlePair(array $req, string $ip): array {
        $d = RelayPeerStore::read();
        $secret = RelayPeerStore::pairingSecret();
        $p = $d['pairing'];
        if ($secret === '' || !is_array($p)) return self::fail($ip, 403, 'no_pairing_code');
        if ((int)($p['expires_at'] ?? 0) < self::now()) { self::burnPairing(); return self::fail($ip, 403, 'pairing_code_expired'); }
        $joinBox = (string)($req['box_id'] ?? ''); $joinSpki = (string)($req['spki'] ?? ''); $joinUrl = (string)($req['url'] ?? '');
        $ts = (string)($req['ts'] ?? ''); $nonce = (string)($req['nonce'] ?? '');
        $proof = (string)($req['proof'] ?? '');
        $valid = preg_match('/^[a-f0-9]{16}$/', $joinBox) && RelayPeerCrypto::validSpki($joinSpki) && RelayPeerClient::validBaseUrl($joinUrl)
            && preg_match('/^[a-f0-9]{16}$/', $nonce) && RelayPeerCrypto::clockSkew($ts, self::now()) === 0;
        $expected = $valid ? RelayPeerCrypto::pairRequestProof($secret, $d['box_id'], $joinBox, $joinSpki, $joinUrl, $ts, $nonce) : '';
        if (!$valid || !hash_equals($expected, $proof)) {
            $p['failures'] = (int)($p['failures'] ?? 0) + 1;
            if ($p['failures'] >= self::PAIRING_MAX_FAILURES) self::burnPairing();
            else { $d['pairing'] = $p; RelayPeerStore::write($d); }
            RelayPeerStore::audit('_box', 'pairing_rejected', ['from_ip' => $ip]);
            return self::fail($ip, 403, 'bad_pairing_proof');
        }
        if ($joinBox === $d['box_id']) { self::burnPairing(); return self::reply(409, ['error' => 'self_pair']); }
        // The code is used now, whatever happens next (one use only).
        self::burnPairing();

        $existing = RelayPeerStore::findByBox($joinBox);   // re-pair of the same box keeps its row id
        $id = $existing['id'] ?? RelayPeerStore::newId();
        $name = RelayPeerStore::cleanLabel((string)($req['name'] ?? ''), 'box');
        $key = RelayPeerCrypto::newKey();
        $row = [
            'id' => $id, 'box_id' => $joinBox, 'name' => $name,
            'label' => $existing['label'] ?? RelayPeerStore::uniqueLabel($name, $id),
            'url' => $joinUrl, 'spki_sha256' => $joinSpki, 'state' => 'linked',
            'grants' => $existing['grants'] ?? RelayGrants::defaults(RelayGrants::KIND_PEER),
            'rate_per_min' => $existing['rate_per_min'] ?? RelayGrants::defaultRate(RelayGrants::KIND_PEER),
            'protocol' => (int)($req['protocol'] ?? 1), 'remote_version' => substr((string)($req['version'] ?? ''), 0, 40),
            'created_at' => gmdate('c', self::now()), 'last_ok_at' => '', 'last_in_at' => gmdate('c', self::now()),
            'last_error' => '', 'notice' => '', 'old_key_until' => 0, 'clock_skew' => 0,
        ];
        if (!RelayPeerStore::setLinkKey($id, $key) || !RelayPeerStore::save($row)) return self::reply(500, ['error' => 'could_not_save']);
        RelayPeerStore::audit($id, 'paired', ['role' => 'code_owner', 'box_id' => $joinBox, 'name' => $name, 'url' => $joinUrl, 'spki' => $joinSpki, 'replaced' => $existing !== null]);
        return self::reply(200, [
            'box_id' => $d['box_id'], 'name' => self::selfName(), 'version' => self::version(),
            'protocol' => RelayPeerCrypto::PROTOCOL, 'features' => self::FEATURES,
            'key_enc' => RelayPeerCrypto::wrapKey($key, $secret, 'pair', $nonce),
            'proof' => RelayPeerCrypto::pairResponseProof($secret, $d['box_id'], $joinBox, $nonce, $key),
        ]);
    }

    // =====================================================================
    // Pairing — this box enters a code (box B)
    // =====================================================================

    /** Decode a code for the confirm step. Makes no network call. */
    public static function previewPairingCode(string $code): array {
        $c = RelayPeerCrypto::decodePairingCode($code);
        if (isset($c['error'])) return ['status' => 'error', 'message' => $c['error']];
        if ($c['box_id'] === RelayPeerStore::read()['box_id']) return ['status' => 'error', 'message' => 'This code comes from this box. Enter it on the OTHER box.'];
        $replace = [];
        foreach (RelayPeerStore::peers() as $r) {
            if ($r['state'] === 'linked' && $r['box_id'] !== $c['box_id']) continue;
            if (strcasecmp($r['name'], $c['name']) === 0 || $r['box_id'] === $c['box_id']) $replace[] = ['id' => $r['id'], 'name' => RelayPeerStore::displayName($r), 'state' => $r['state']];
        }
        return ['status' => 'ok', 'name' => $c['name'], 'url' => $c['url'], 'box_id' => $c['box_id'], 'spki' => $c['spki'], 'replace_candidates' => $replace];
    }

    /** Redeem a code from box A: pinned TLS, proof both ways, key unwrap, then hello (spec §2 steps 4–6). */
    public static function redeemPairingCode(string $code, string $urlOverride = '', string $replaceId = ''): array {
        if (!self::enabled()) return ['status' => 'error', 'message' => 'Turn on the listener and linked boxes first.'];
        $c = RelayPeerCrypto::decodePairingCode($code);
        if (isset($c['error'])) return ['status' => 'error', 'message' => $c['error']];
        $self = RelayPeerStore::boxId();
        if ($c['box_id'] === $self) return ['status' => 'error', 'message' => 'This code comes from this box. Enter it on the OTHER box.'];
        $url = $urlOverride !== '' ? $urlOverride : $c['url'];
        if (!RelayPeerClient::validBaseUrl($url)) return ['status' => 'error', 'message' => 'Enter an address like https://192.168.1.10:8237/.'];
        $mySpki = self::selfSpki();
        if ($mySpki === '') return ['status' => 'error', 'message' => 'This box has no usable HTTPS certificate for the listener.'];
        $myUrl = self::selfUrl();
        $ts = (string)self::now(); $nonce = RelayPeerCrypto::newNonce();
        $body = json_encode([
            'v' => 1, 'box_id' => $self, 'name' => self::selfName(), 'url' => $myUrl, 'spki' => $mySpki,
            'protocol' => RelayPeerCrypto::PROTOCOL, 'version' => self::version(), 'ts' => $ts, 'nonce' => $nonce,
            'proof' => RelayPeerCrypto::pairRequestProof($c['secret'], $c['box_id'], $self, $mySpki, $myUrl, $ts, $nonce),
        ], JSON_UNESCAPED_SLASHES);
        $res = RelayPeerClient::post($url, self::PREFIX . 'pair', [], $body, $c['spki']);
        if ($res['pin_mismatch']) return ['status' => 'error', 'message' => 'The box at that address does not have the key in the code. Check the address. Nothing was linked.'];
        if (!$res['ok']) return ['status' => 'error', 'message' => 'Could not reach ' . $c['name'] . ' at ' . $url . ': ' . $res['error']];
        $data = json_decode($res['body'], true); $data = is_array($data) ? $data : [];
        if (in_array($res['status'], [400, 404], true) && !isset($data['error'])) return ['status' => 'error', 'message' => $c['name'] . ' runs an older plugin version. Update it first.'];
        if ($res['status'] !== 200) return ['status' => 'error', 'message' => self::pairErrorText((string)($data['error'] ?? ('HTTP ' . $res['status'])), $c['name'])];
        // Check the format BEFORE unwrapping: wrapKey() refuses anything that is
        // not a 32-byte hex key, and a malformed answer must fail as "no proof".
        $keyEnc = (string)($data['key_enc'] ?? '');
        $key = preg_match('/^[a-f0-9]{64}$/', $keyEnc) ? RelayPeerCrypto::wrapKey($keyEnc, $c['secret'], 'pair', $nonce) : '';
        $proofOk = $key !== ''
            && ($data['box_id'] ?? '') === $c['box_id']
            && hash_equals(RelayPeerCrypto::pairResponseProof($c['secret'], $c['box_id'], $self, $nonce, $key), (string)($data['proof'] ?? ''));
        if (!$proofOk) return ['status' => 'error', 'message' => 'The answer from ' . $c['name'] . ' did not prove it holds the code. Nothing was linked.'];

        $replace = ($replaceId !== '' && RelayPeerStore::validId($replaceId)) ? RelayPeerStore::find($replaceId) : null;
        $existing = $replace ?? RelayPeerStore::findByBox($c['box_id']);
        $id = $existing['id'] ?? RelayPeerStore::newId();
        $name = RelayPeerStore::cleanLabel((string)($data['name'] ?? $c['name']), 'box');
        $row = [
            'id' => $id, 'box_id' => $c['box_id'], 'name' => $name,
            'label' => $existing['label'] ?? RelayPeerStore::uniqueLabel($name, $id),
            'url' => $url, 'spki_sha256' => $c['spki'], 'state' => 'linked',
            'grants' => $existing['grants'] ?? RelayGrants::defaults(RelayGrants::KIND_PEER),
            'rate_per_min' => $existing['rate_per_min'] ?? RelayGrants::defaultRate(RelayGrants::KIND_PEER),
            'protocol' => (int)($data['protocol'] ?? 1), 'remote_version' => substr((string)($data['version'] ?? ''), 0, 40),
            'created_at' => gmdate('c', self::now()), 'last_ok_at' => gmdate('c', self::now()), 'last_in_at' => '',
            'last_error' => '', 'notice' => '', 'old_key_until' => 0, 'clock_skew' => 0,
        ];
        if (!RelayPeerStore::setLinkKey($id, $key) || !RelayPeerStore::save($row)) return ['status' => 'error', 'message' => 'Could not save the link.'];
        RelayPeerStore::audit($id, 'paired', ['role' => 'code_entered', 'box_id' => $c['box_id'], 'name' => $name, 'url' => $url, 'spki' => $c['spki'], 'replaced' => $existing !== null]);
        // Step 6: a signed hello proves the key works in this direction; the
        // other box proves the reverse direction at its next tick.
        self::syncPeer($id, true);
        return ['status' => 'ok', 'peer' => self::peerView(RelayPeerStore::find($id) ?? $row)];
    }

    private static function pairErrorText(string $code, string $name): string {
        switch ($code) {
            case 'pairing_code_expired': return 'The pairing code has expired. Create a new code on ' . $name . '.';
            case 'no_pairing_code': return 'The code was already used or cancelled. Create a new code on ' . $name . '.';
            case 'bad_pairing_proof': return 'The code was not accepted by ' . $name . '. Create a new code and try again.';
            case 'self_pair': return 'A box cannot link to itself.';
            case 'peer_links_disabled': return 'Linked boxes are off on ' . $name . '. Turn them on there first.';
        }
        return 'Pairing failed: ' . $code;
    }

    // =====================================================================
    // Inbound: the signed box-to-box path
    // =====================================================================

    /**
     * Handle one request under /peer/v1/. Called by RelayHttpService only.
     *
     * @param array<string,string> $headers lower-case keys
     * @return array{status:int,body:string,headers?:array<string,string>}
     */
    public static function handle(string $method, string $path, array $headers, string $body, string $ip): array {
        if (!self::enabled()) return self::reply(404, ['error' => 'peer_links_disabled']);
        if (self::failuresExceeded($ip)) return self::reply(429, ['error' => 'rate_limited']);
        if (strtoupper($method) !== 'POST') return self::reply(405, ['error' => 'POST required']);
        $action = substr($path, strlen(self::PREFIX));
        $req = json_decode($body, true);
        if (!is_array($req)) return self::fail($ip, 400, 'malformed_json');
        if ($action === 'pair') return self::handlePair($req, $ip);
        if (!in_array($action, ['hello', 'contacts', 'deliver', 'rekey', 'unlink'], true)) return self::reply(404, ['error' => 'unknown_endpoint']);

        // ---- authenticate: the signing box, not anything in the body ----
        $box = (string)($headers['x-aicli-box'] ?? '');
        $ts = (string)($headers['x-aicli-ts'] ?? '');
        $nonce = (string)($headers['x-aicli-nonce'] ?? '');
        $sig = (string)($headers['x-aicli-sig'] ?? '');
        $row = preg_match('/^[a-f0-9]{16}$/', $box) ? RelayPeerStore::findByBox($box) : null;
        if ($row === null || in_array($row['state'], ['unlinked', 'unlinked_by_peer'], true)) return self::fail($ip, 401, 'unknown_peer');
        $key = RelayPeerStore::linkKey($row['id']);
        if ($key === '') return self::fail($ip, 401, 'unknown_peer');
        $self = RelayPeerStore::boxId();
        if (!preg_match('/^[a-f0-9]{16}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $sig)) return self::fail($ip, 401, 'bad_signature');
        $verifyKey = '';
        foreach ([$key, $row['old_key_until'] > self::now() ? RelayPeerStore::oldLinkKey($row['id']) : ''] as $k) {
            if ($k !== '' && hash_equals(RelayPeerCrypto::signRequest($k, $box, $self, $method, $path, $ts, $nonce, $body), $sig)) { $verifyKey = $k; break; }
        }
        if ($verifyKey === '') {
            RelayPeerStore::audit($row['id'], 'rejected', ['reason' => 'bad_signature', 'from_ip' => $ip]);
            return self::fail($ip, 401, 'bad_signature');
        }
        $skew = RelayPeerCrypto::clockSkew($ts, self::now());
        if ($skew !== 0) {
            RelayPeerStore::audit($row['id'], 'rejected', ['reason' => 'clock_skew', 'skew' => $skew]);
            return self::signed(401, ['error' => 'clock_skew', 'server_time' => self::now()], $verifyKey, $self, $box, $nonce);
        }
        if (!self::rememberNonce($row['id'], $nonce)) {
            RelayPeerStore::audit($row['id'], 'rejected', ['reason' => 'replay']);
            return self::signed(401, ['error' => 'replay'], $verifyKey, $self, $box, $nonce);
        }
        $cost = 1 + ($action === 'deliver' ? min(self::DELIVER_MAX_ITEMS, count((array)($req['items'] ?? []))) : 0);
        if (!self::withinCredentialLimit($row['id'], $row['rate_per_min'], $cost)) {
            RelayPeerStore::audit($row['id'], 'rejected', ['reason' => 'rate_limited']);
            return self::signed(429, ['error' => 'rate_limited'], $verifyKey, $self, $box, $nonce);
        }
        RelayPeerStore::update($row['id'], ['last_in_at' => gmdate('c', self::now())]);

        $after = null;
        switch ($action) {
            case 'hello':    [$status, $payload] = [200, self::helloPayload($row, $req)]; break;
            case 'contacts': [$status, $payload] = [200, ['contacts' => self::exportContacts($row), 'granted' => RelayGrants::allows(RelayGrants::principal(RelayGrants::KIND_PEER, $row), 'contacts.read')]]; break;
            case 'deliver':  [$status, $payload] = self::handleDeliver($row, $req); break;
            case 'rekey':    [$status, $payload] = self::handleRekey($row, $req, $verifyKey, $nonce); break;
            case 'unlink':
                [$status, $payload] = [200, ['unlinked' => true]];
                $after = static function () use ($row): void { self::markUnlinked($row['id'], 'unlinked_by_peer'); };
                break;
            default: [$status, $payload] = [404, ['error' => 'unknown_endpoint']];
        }
        $resp = self::signed($status, $payload, $verifyKey, $self, $box, $nonce);
        if ($after !== null) $after();
        return $resp;
    }

    private static function helloPayload(array $row, array $req): array {
        $patch = [];
        if (isset($req['name'])) $patch['name'] = RelayPeerStore::cleanLabel((string)$req['name'], $row['name']);
        if (isset($req['version'])) $patch['remote_version'] = substr((string)$req['version'], 0, 40);
        if ($patch) RelayPeerStore::update($row['id'], $patch);
        return [
            'box_id' => RelayPeerStore::boxId(), 'name' => self::selfName(), 'version' => self::version(),
            'protocol' => RelayPeerCrypto::PROTOCOL, 'features' => self::FEATURES,
            // The channel binding for a re-pin: the key THIS box serves, signed.
            'spki' => self::selfSpki(), 'server_time' => self::now(),
        ];
    }

    private static function handleRekey(array $row, array $req, string $verifyKey, string $nonce): array {
        $enc = (string)($req['key_enc'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $enc)) return [400, ['error' => 'bad_key']];
        $new = RelayPeerCrypto::wrapKey($enc, $verifyKey, 'rekey', $nonce);
        if (!RelayPeerStore::setLinkKey($row['id'], $new, true)) return [500, ['error' => 'could_not_save']];
        RelayPeerStore::update($row['id'], ['old_key_until' => self::now() + self::REKEY_GRACE]);
        RelayPeerStore::audit($row['id'], 'rekeyed', ['by' => 'peer']);
        return [200, ['rekeyed' => true]];
    }

    /**
     * Inbound batch (spec §5). Each item gets its own result; a bad item never
     * stops the others.
     */
    private static function handleDeliver(array $row, array $req): array {
        $items = $req['items'] ?? null;
        if (!is_array($items) || $items === []) return [400, ['error' => 'no_items']];
        if (count($items) > self::DELIVER_MAX_ITEMS) return [413, ['error' => 'too_many_items', 'max' => self::DELIVER_MAX_ITEMS]];
        $results = [];
        $exported = self::exportedIds($row);
        foreach (array_values($items) as $item) $results[] = self::acceptDirect($row, is_array($item) ? $item : [], $exported);
        return [200, ['results' => $results]];
    }

    /**
     * Accept one forwarded direct message for a local workspace. Checks the
     * grant, the hop, the recipient, the ids, the size and the thread, then
     * calls the SAME AgentRelayService::storeAndDeliverDirect() as a local DM.
     */
    public static function acceptDirect(array $row, array $env, ?array $exported = null): array {
        $id = (string)($env['id'] ?? '');
        $out = static fn(string $state, string $reason = '', array $extra = []): array => ['id' => $id, 'state' => $state] + ($reason !== '' ? ['reason' => $reason] : []) + $extra;
        if (!preg_match('/^msg_[a-f0-9]{24}$/', $id)) return $out('rejected', 'bad_id');
        $principal = RelayGrants::principal(RelayGrants::KIND_PEER, $row);
        if (!RelayGrants::allows($principal, 'dm.send')) return self::rejectItem($row, $out('rejected', 'not_granted'));

        // Idempotency first: a repeat returns the first result (spec §3).
        $seen = RelayPeerStore::readRuntime($row['id'], 'seen', []);
        if (isset($seen[$id]) && is_array($seen[$id])) return ['id' => $id, 'duplicate' => true] + $seen[$id];

        if ((int)($env['hop'] ?? 0) !== 1) return self::rejectItem($row, $out('rejected', 'hop'));
        $recipient = (string)($env['recipient_session'] ?? '');
        if (strpos($recipient, 'peer_') === 0 || strpos($recipient, 'remote_') === 0 || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $recipient)) return self::rejectItem($row, $out('rejected', 'no_forwarding'));
        $senderSession = (string)($env['sender_session'] ?? '');
        $senderId = self::contactId($row['id'], $senderSession);
        if (!self::isPeerContactId($senderId)) return self::rejectItem($row, $out('rejected', 'bad_sender'));
        $threadId = (string)($env['thread_id'] ?? '');
        if (!preg_match('/^dm_[a-f0-9]{24}$/', $threadId)) return self::rejectItem($row, $out('rejected', 'bad_thread'));
        $summary = trim((string)($env['summary'] ?? ''));
        if ($summary === '' || strlen($summary) > AgentRelayService::MAX_SUMMARY) return self::rejectItem($row, $out('rejected', 'size'));
        // The recipient must be a saved workspace that this peer is allowed to see.
        $exported = $exported ?? self::exportedIds($row);
        if (!in_array($recipient, $exported, true)) return self::rejectItem($row, $out('rejected', 'unknown_recipient'));
        // Thread safety (spec §4): a peer can only write into a thread between
        // this recipient and itself.
        $existing = AgentRelayService::directMessageRecord($threadId, $id);
        if ($existing !== null) return self::rejectItem($row, $out('rejected', 'id_conflict'));
        $parts = AgentRelayService::threadParticipants($threadId);
        if (array_diff($parts, [$recipient, $senderId]) !== []) return self::rejectItem($row, $out('rejected', 'thread_conflict'));

        $message = [
            'schema' => AgentRelayService::SCHEMA, 'id' => $id, 'thread_id' => $threadId,
            // Identity from the signed connection only; the name is display data.
            'sender_session' => $senderId,
            'sender_name' => self::remoteDisplayName((string)($env['sender_name'] ?? ''), $row),
            'recipient_session' => $recipient, 'summary' => $summary,
            // The recipient index uses this box's clock (spec edge case, clock skew).
            'created_at' => gmdate('c', self::now()),
            'sent_at' => substr((string)($env['created_at'] ?? ''), 0, 40),
            'origin' => ['peer' => $row['id'], 'box' => RelayPeerStore::displayName($row)],
        ];
        $env2 = AgentRelayService::storeAndDeliverDirect($message);
        if (($env2['status'] ?? '') === 'error') return $out('retry', 'store_failed');
        $state = ($env2['status'] ?? '') === 'recipient_offline' ? 'recipient_offline'
            : (!empty($env2['delivered']) ? 'delivered' : (!empty($env2['deferred']) ? 'deferred' : 'forwarded'));
        $result = ['state' => $state] + (!empty($env2['defer_reason']) ? ['reason' => (string)$env2['defer_reason']] : []);
        self::rememberSeen($row['id'], $id, $result);
        RelayPeerStore::audit($row['id'], 'received', ['id' => $id, 'thread_id' => $threadId, 'recipient' => $recipient, 'state' => $state]);
        return ['id' => $id] + $result;
    }

    private static function rejectItem(array $row, array $result): array {
        self::rememberSeen($row['id'], (string)$result['id'], array_diff_key($result, ['id' => 1]));
        RelayPeerStore::audit($row['id'], 'rejected', ['id' => $result['id'], 'reason' => $result['reason'] ?? '']);
        return $result;
    }

    private static function rememberSeen(string $peerId, string $id, array $result): void {
        if (!preg_match('/^msg_[a-f0-9]{24}$/', $id)) return;
        $seen = RelayPeerStore::readRuntime($peerId, 'seen', []);
        $seen[$id] = $result + ['at' => self::now()];
        $cut = self::now() - self::EXPIRE_AFTER - 86400;
        $seen = array_filter($seen, static fn($v): bool => is_array($v) && (int)($v['at'] ?? 0) >= $cut);
        if (count($seen) > self::SEEN_MAX) $seen = array_slice($seen, -self::SEEN_MAX, null, true);
        RelayPeerStore::writeRuntime($peerId, 'seen', $seen);
    }

    /** False when this nonce was already used inside the window (a replay). */
    private static function rememberNonce(string $peerId, string $nonce): bool {
        $n = RelayPeerStore::readRuntime($peerId, 'nonces', []);
        $cut = self::now() - self::NONCE_TTL;
        $n = array_filter($n, static fn($t): bool => (int)$t >= $cut);
        if (isset($n[$nonce])) return false;
        $n[$nonce] = self::now();
        RelayPeerStore::writeRuntime($peerId, 'nonces', $n);
        return true;
    }

    // ---- limiters ----------------------------------------------------------------

    /** Per-IP counter of FAILED authentication only (spec §6): a busy peer is never blocked by it. */
    private static function failuresExceeded(string $ip): bool {
        $e = self::$failures[$ip] ?? null;
        if ($e === null || self::now() - $e[0] >= 60) return false;
        return $e[1] >= RelayHttpService::RATE_LIMIT;
    }

    private static function fail(string $ip, int $status, string $error): array {
        $e = self::$failures[$ip] ?? [self::now(), 0];
        if (self::now() - $e[0] >= 60) $e = [self::now(), 0];
        $e[1]++;
        self::$failures[$ip] = $e;
        return self::reply($status, ['error' => $error]);
    }

    public static function withinCredentialLimit(string $credential, int $perMin, int $cost = 1): bool {
        $e = self::$credential[$credential] ?? [self::now(), 0];
        if (self::now() - $e[0] >= 60) $e = [self::now(), 0];
        if ($e[1] + $cost > $perMin) { self::$credential[$credential] = $e; return false; }
        $e[1] += $cost;
        self::$credential[$credential] = $e;
        return true;
    }

    // ---- responses -----------------------------------------------------------------

    private static function reply(int $status, array $payload): array {
        return ['status' => $status, 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES)];
    }

    private static function signed(int $status, array $payload, string $key, string $self, string $to, string $nonce): array {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return ['status' => $status, 'body' => $body, 'headers' => [
            'X-Aicli-Box' => $self,
            'X-Aicli-Sig' => RelayPeerCrypto::signResponse($key, $self, $to, $status, $nonce, $body),
        ]];
    }

    // =====================================================================
    // Outbound: signed calls to a peer
    // =====================================================================

    /**
     * One signed call. Returns {ok, status, data, error, unsigned?}. A response
     * without a valid signature is never trusted (ok=false).
     */
    public static function call(array $row, string $action, array $payload, int $timeout = RelayPeerClient::TOTAL_TIMEOUT, ?string $pin = null, ?string &$spkiSeen = null): array {
        $key = RelayPeerStore::linkKey($row['id']);
        if ($key === '') return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'no_key'];
        $self = RelayPeerStore::boxId();
        $path = self::PREFIX . $action;
        $body = json_encode($payload === [] ? new \stdClass() : $payload, JSON_UNESCAPED_SLASHES);
        $ts = (string)self::now(); $nonce = RelayPeerCrypto::newNonce();
        $headers = [
            'X-Aicli-Box' => $self, 'X-Aicli-Ts' => $ts, 'X-Aicli-Nonce' => $nonce,
            'X-Aicli-Sig' => RelayPeerCrypto::signRequest($key, $self, $row['box_id'], 'POST', $path, $ts, $nonce, $body),
        ];
        $res = RelayPeerClient::post($row['url'], $path, $headers, $body, $pin ?? $row['spki_sha256'], $timeout);
        $spkiSeen = $res['spki_seen'];
        if ($res['pin_mismatch']) return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'pin_mismatch'];
        if (!$res['ok']) return ['ok' => false, 'status' => $res['status'], 'data' => [], 'error' => 'unreachable', 'detail' => $res['error']];
        $data = json_decode($res['body'], true); $data = is_array($data) ? $data : [];
        $rsig = (string)($res['headers']['x-aicli-sig'] ?? '');
        $valid = $rsig !== '' && hash_equals(RelayPeerCrypto::signResponse($key, $row['box_id'], $self, $res['status'], $nonce, $res['body']), $rsig);
        if (!$valid) {
            // Unsigned answers are only used to show a state, never to accept data.
            return ['ok' => false, 'status' => $res['status'], 'data' => $data, 'error' => (string)($data['error'] ?? ('http_' . $res['status'])), 'unsigned' => true];
        }
        return ['ok' => $res['status'] === 200, 'status' => $res['status'], 'data' => $data, 'error' => $res['status'] === 200 ? '' : (string)($data['error'] ?? 'http_' . $res['status'])];
    }

    /** Record the outcome of a call on the row and the back-off state. */
    private static function noteResult(array $row, array $r): void {
        $sync = RelayPeerStore::readRuntime($row['id'], 'sync', []);
        if ($r['ok']) {
            $sync['fail_count'] = 0; $sync['next_at'] = 0; unset($sync['offline_since']);
            RelayPeerStore::writeRuntime($row['id'], 'sync', $sync);
            RelayPeerStore::update($row['id'], ['last_ok_at' => gmdate('c', self::now()), 'last_error' => '', 'clock_skew' => 0]);
            return;
        }
        $err = (string)$r['error'];
        $patch = ['last_error' => $err];
        if (!empty($r['unsigned']) && $err === 'unknown_peer') {
            // The other box has no key for us any more: unlinked there, or reinstalled.
            self::markUnlinked($row['id'], 'unlinked_by_peer');
            return;
        }
        if ($err === 'clock_skew') $patch['clock_skew'] = (int)($r['data']['server_time'] ?? self::now()) - self::now();
        if ($err === 'no_key') $patch['state'] = 'needs_repair';
        $sync['fail_count'] = (int)($sync['fail_count'] ?? 0) + 1;
        $sync['next_at'] = self::now() + self::BACKOFF[min($sync['fail_count'] - 1, count(self::BACKOFF) - 1)];
        $sync['offline_since'] = $sync['offline_since'] ?? self::now();
        RelayPeerStore::writeRuntime($row['id'], 'sync', $sync);
        RelayPeerStore::update($row['id'], $patch);
    }

    /**
     * Hello with automatic re-pin (spec §2, "Certificate change"; decision 2).
     * On a pin mismatch nothing but one signed hello with an EMPTY body is sent,
     * unpinned. The peer signs its own SPKI; the client re-pins only when that
     * signed value equals the key it saw on the same TLS connection.
     */
    public static function hello(array $row): array {
        $r = self::call($row, 'hello', ['name' => self::selfName(), 'version' => self::version()]);
        if ($r['error'] === 'pin_mismatch') {
            $seen = '';
            $probe = self::call($row, 'hello', [], RelayPeerClient::TOTAL_TIMEOUT, '', $seen);
            $signedSpki = (string)($probe['data']['spki'] ?? '');
            if ($probe['ok'] && $seen !== '' && RelayPeerCrypto::validSpki($signedSpki) && hash_equals($signedSpki, $seen)) {
                RelayPeerStore::update($row['id'], ['spki_sha256' => $seen, 'state' => 'linked',
                    'notice' => 'Certificate key changed on ' . gmdate('Y-m-d H:i', self::now()) . ' UTC; re-pinned after a signed check.']);
                RelayPeerStore::audit($row['id'], 'repinned', ['old_spki' => $row['spki_sha256'], 'new_spki' => $seen]);
                $row = RelayPeerStore::find($row['id']) ?? $row;
                $r = self::call($row, 'hello', ['name' => self::selfName(), 'version' => self::version()]);
            } else {
                RelayPeerStore::update($row['id'], ['state' => 'cert_changed', 'last_error' => 'cert_changed',
                    'notice' => 'Certificate changed — check. The new key did not pass the signed check, so nothing is sent.']);
                RelayPeerStore::audit($row['id'], 'repin_refused', ['seen_spki' => $seen]);
                return ['ok' => false, 'error' => 'cert_changed'];
            }
        }
        if ($r['ok']) {
            $d = $r['data'];
            $patch = ['remote_version' => substr((string)($d['version'] ?? ''), 0, 40), 'protocol' => (int)($d['protocol'] ?? 1)];
            if (isset($d['name'])) $patch['name'] = RelayPeerStore::cleanLabel((string)$d['name'], $row['name']);
            if ($row['state'] === 'cert_changed') $patch['state'] = 'linked';
            RelayPeerStore::update($row['id'], $patch);
        }
        self::noteResult($row, $r);
        return $r;
    }

    public static function fetchContacts(array $row): array {
        $r = self::call($row, 'contacts', []);
        if (!$r['ok']) { self::noteResult($row, $r); return $r; }
        $clean = [];
        foreach (array_slice((array)($r['data']['contacts'] ?? []), 0, self::CONTACTS_MAX) as $c) {
            if (!is_array($c)) continue;
            $sid = (string)($c['session_id'] ?? '');
            if (!self::isPeerContactId(self::contactId($row['id'], $sid))) continue;
            $clean[] = [
                'session_id' => $sid,
                'name' => substr((string)preg_replace('/[^\x20-\x7E]/', '', (string)($c['name'] ?? $sid)), 0, 48),
                'agent_id' => preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', (string)($c['agent_id'] ?? '')) ? (string)$c['agent_id'] : '',
                'online' => !empty($c['online']),
            ];
        }
        RelayPeerStore::writeRuntime($row['id'], 'contacts', ['fetched_at' => gmdate('c', self::now()), 'contacts' => $clean]);
        self::noteResult($row, $r);
        return $r;
    }

    // =====================================================================
    // Outbox (spec §3 "Outbox")
    // =====================================================================

    private static function outboxDir(string $peerId): string { return RelayPeerStore::dir($peerId) . '/outbox'; }

    /** @return string[] outbox files, oldest first */
    public static function outboxFiles(string $peerId): array {
        $files = glob(self::outboxDir($peerId) . '/*.json') ?: [];
        sort($files);
        return $files;
    }

    /**
     * Sender side of a DM to a linked-box workspace. Stores the sender's copy
     * locally, writes the outbox entry, then tries one send at once.
     */
    public static function sendDirect(array $message): array {
        $recipient = (string)$message['recipient_session'];
        [$peerId, $remoteSession] = self::splitContactId($recipient);
        $row = $peerId !== '' ? RelayPeerStore::find($peerId) : null;
        if (!self::enabled()) return ['status' => 'error', 'message' => 'Linked boxes are turned off on this box.'];
        if ($row === null) return ['status' => 'error', 'message' => 'Recipient is not an available saved workspace.'];
        if (in_array($row['state'], ['unlinked', 'unlinked_by_peer'], true)) return ['status' => 'error', 'message' => 'The link to ' . RelayPeerStore::displayName($row) . ' was removed. Pair the boxes again to send.'];
        $message['remote_state'] = 'queued';
        if (!AgentRelayService::storeDirect($message)) return ['status' => 'error', 'message' => 'Could not save private message.'];
        $env = [
            'id' => $message['id'], 'thread_id' => $message['thread_id'],
            'sender_session' => $message['sender_session'], 'sender_name' => $message['sender_name'],
            'recipient_session' => $remoteSession, 'summary' => $message['summary'],
            'created_at' => $message['created_at'], 'hop' => 1,
            'queued_at' => self::now(),
        ];
        $file = self::outboxDir($peerId) . '/' . gmdate('Ymd\THis\Z', self::now()) . '_' . $message['id'] . '.json';
        if (!AtomicWriteService::writeJson($file, $env)) return ['status' => 'error', 'message' => 'Could not queue the message for ' . RelayPeerStore::displayName($row) . '.'];
        RelayPeerStore::audit($peerId, 'queued', ['id' => $message['id'], 'thread_id' => $message['thread_id'], 'sender' => $message['sender_session']]);

        $state = 'queued'; $reason = '';
        if (!self::$inListener && $row['state'] === 'linked') {
            // #332: a box that answered in the last ONLINE_WINDOW is tried at
            // once, even inside a back-off window. The back-off is for the
            // background tick against a box that is away; it made a send right
            // after the tick (or the pairing) reported the box online come back
            // "queued_for_peer … not reachable now" until the next sync. One
            // attempt per send, bounded by IMMEDIATE_BUDGET, as before.
            $stats = self::flush($peerId, self::IMMEDIATE_BUDGET, self::peerOnline($row));
            // Another process (the supervisor tick) holds the outbox lock and is
            // sending now. Wait briefly for it, then try once more, so this
            // message does not wait for the next tick.
            for ($i = 0; ($stats['stopped'] ?? '') === 'busy' && $i < 10; $i++) {
                usleep(200000);
                $stats = self::flush($peerId, self::IMMEDIATE_BUDGET, self::peerOnline($row));
            }
            $m = AgentRelayService::directMessageRecord($message['thread_id'], $message['id']);
            $state = (string)($m['remote_state'] ?? 'queued'); $reason = (string)($m['remote_reason'] ?? '');
        }
        return self::senderEnvelope($row, $message, $state, $reason);
    }

    /** What the sending agent is told, in the style of AgentRelayService::deliveryEnvelope(). */
    public static function senderEnvelope(array $row, array $message, string $state, string $reason = ''): array {
        $box = RelayPeerStore::displayName($row);
        $base = ['id' => $message['id'], 'thread_id' => $message['thread_id'], 'box' => $box, 'remote_state' => $state];
        switch ($state) {
            case 'delivered': return ['status' => 'ok', 'delivered' => true, 'recipient_online' => true] + $base;
            case 'forwarded': return ['status' => 'ok', 'delivered' => false, 'recipient_online' => true] + $base
                + ['message' => "Stored on $box. The recipient reads it from its inbox."];
            case 'deferred': return ['status' => 'ok', 'delivered' => false, 'recipient_online' => true, 'deferred' => true, 'defer_reason' => $reason] + $base
                + ['message' => "Stored on $box and QUEUED there. The recipient pane was busy ($reason); the notice re-fires when it frees. Do not resend."];
            case 'recipient_offline': return ['status' => 'recipient_offline', 'delivered' => false, 'recipient_online' => false] + $base
                + ['message' => "Stored on $box, but that workspace is not running there. It sees the message when it starts."];
            case 'rejected': return ['status' => 'error', 'delivered' => false] + $base + ['reason' => $reason,
                'message' => "$box refused the message ($reason). It was not delivered."];
        }
        return ['status' => 'queued_for_peer', 'delivered' => false, 'recipient_online' => false] + $base
            + ['message' => "Stored and queued for $box, which is not reachable now. It is sent automatically when $box answers. Do not resend."];
    }

    /**
     * Send one peer's outbox, oldest first, in batches. Stops at the first
     * temporary failure so messages keep their order (spec §3). Returns counts.
     */
    public static function flush(string $peerId, int $budgetSeconds = 30, bool $force = false): array {
        $row = RelayPeerStore::find($peerId);
        $stats = ['sent' => 0, 'rejected' => 0, 'expired' => 0, 'left' => 0, 'stopped' => ''];
        if ($row === null) return $stats;
        $lock = self::lock($peerId);
        if ($lock === null) { $stats['stopped'] = 'busy'; return $stats; }
        try {
            $deadline = self::now() + max(1, $budgetSeconds);
            // Expire first: 7 days, whatever the link state.
            foreach (self::outboxFiles($peerId) as $f) {
                $e = json_decode((string)@file_get_contents($f), true);
                if (!is_array($e) || (int)($e['queued_at'] ?? 0) < self::now() - self::EXPIRE_AFTER) {
                    if (is_array($e)) self::finish($row, $f, $e, 'expired', '');
                    else @unlink($f);
                    $stats['expired']++;
                }
            }
            if ($row['state'] !== 'linked' || !self::enabled()) { $stats['stopped'] = $row['state']; $stats['left'] = count(self::outboxFiles($peerId)); return $stats; }
            $sync = RelayPeerStore::readRuntime($peerId, 'sync', []);
            if (!$force && (int)($sync['next_at'] ?? 0) > self::now()) { $stats['stopped'] = 'backoff'; $stats['left'] = count(self::outboxFiles($peerId)); return $stats; }
            while (self::now() <= $deadline) {
                $files = array_slice(self::outboxFiles($peerId), 0, self::SEND_BATCH);
                if ($files === []) break;
                $items = []; $byId = [];
                foreach ($files as $f) {
                    $e = json_decode((string)@file_get_contents($f), true);
                    if (!is_array($e)) { @unlink($f); continue; }
                    $items[] = array_diff_key($e, ['queued_at' => 1]);
                    $byId[(string)$e['id']] = [$f, $e];
                }
                if ($items === []) continue;
                $timeout = max(1, min(RelayPeerClient::TOTAL_TIMEOUT, $deadline - self::now() + 1));
                $r = self::call($row, 'deliver', ['items' => $items], $timeout);
                if ($r['error'] === 'pin_mismatch') {
                    $h = self::hello($row);
                    if (!$h['ok']) { $stats['stopped'] = 'cert_changed'; break; }
                    $row = RelayPeerStore::find($peerId) ?? $row;
                    continue;
                }
                if (!$r['ok']) { self::noteResult($row, $r); $stats['stopped'] = $r['error']; break; }
                self::noteResult($row, $r);
                $stop = false;
                foreach ((array)($r['data']['results'] ?? []) as $res) {
                    $id = (string)($res['id'] ?? '');
                    if (!isset($byId[$id])) continue;
                    [$f, $e] = $byId[$id];
                    $state = (string)($res['state'] ?? '');
                    if ($state === 'retry' || $state === 'rate_limited') { $stop = true; break; }   // head-of-line stop
                    if (!in_array($state, self::REMOTE_STATES, true)) $state = 'rejected';
                    self::finish($row, $f, $e, $state, (string)($res['reason'] ?? ''));
                    if ($state === 'rejected') $stats['rejected']++; else $stats['sent']++;
                    unset($byId[$id]);
                }
                if ($stop || $byId !== []) { $stats['stopped'] = 'partial'; break; }
            }
            $stats['left'] = count(self::outboxFiles($peerId));
            return $stats;
        } finally {
            self::unlock($lock);
        }
    }

    /** Remove an outbox entry and write the final state onto the sender's copy. */
    private static function finish(array $row, string $file, array $env, string $state, string $reason): void {
        AgentRelayService::setDirectRemoteState((string)($env['thread_id'] ?? ''), (string)($env['id'] ?? ''), $state, $reason);
        @unlink($file);
        RelayPeerStore::audit($row['id'], $state === 'rejected' ? 'send_rejected' : 'sent', ['id' => (string)($env['id'] ?? ''), 'state' => $state] + ($reason !== '' ? ['reason' => $reason] : []));
    }

    /** Non-blocking per-peer lock (never a blocking flock). Null when another process holds it. */
    private static function lock(string $peerId) {
        $dir = RelayPeerStore::dir($peerId);
        @mkdir($dir, 0770, true);
        $h = @fopen("$dir/.lock", 'c');
        if ($h === false) return null;
        if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); return null; }
        return $h;
    }

    private static function unlock($h): void { if (is_resource($h)) { flock($h, LOCK_UN); fclose($h); } }

    // =====================================================================
    // Supervisor tick: relay-agent.php peer-sync
    // =====================================================================

    /** One peer: hello + contacts when due, then the outbox. */
    public static function syncPeer(string $peerId, bool $force = false): array {
        $row = RelayPeerStore::find($peerId);
        if ($row === null) return ['status' => 'error', 'message' => 'Unknown linked box.'];
        if (in_array($row['state'], ['unlinked', 'unlinked_by_peer'], true)) {
            return ['status' => 'ok', 'peer' => $peerId, 'skipped' => $row['state'], 'flush' => self::flush($peerId, 5)];
        }
        if (RelayPeerStore::linkKey($peerId) === '') { RelayPeerStore::update($peerId, ['state' => 'needs_repair', 'last_error' => 'no_key']); return ['status' => 'ok', 'peer' => $peerId, 'skipped' => 'needs_repair']; }
        $sync = RelayPeerStore::readRuntime($peerId, 'sync', []);
        if (!$force && (int)($sync['next_at'] ?? 0) > self::now()) return ['status' => 'ok', 'peer' => $peerId, 'skipped' => 'backoff'];
        $out = ['status' => 'ok', 'peer' => $peerId];
        if ($force || (int)($sync['hello_at'] ?? 0) + self::HELLO_INTERVAL <= self::now()) {
            $h = self::hello($row);
            $out['hello'] = $h['ok'] ? 'ok' : $h['error'];
            $sync = RelayPeerStore::readRuntime($peerId, 'sync', []);
            $sync['hello_at'] = self::now();
            RelayPeerStore::writeRuntime($peerId, 'sync', $sync);
            $row = RelayPeerStore::find($peerId) ?? $row;
            if ($h['ok']) { $c = self::fetchContacts($row); $out['contacts'] = $c['ok'] ? count((array)($c['data']['contacts'] ?? [])) : $c['error']; }
            else return $out + ['flush' => ['stopped' => $out['hello']]];
        }
        $out['flush'] = self::flush($peerId, 20, $force);
        return $out;
    }

    public static function syncAll(bool $force = false): array {
        if (!self::enabled()) return ['status' => 'ok', 'skipped' => 'disabled'];
        $out = [];
        foreach (RelayPeerStore::peers() as $row) $out[] = self::syncPeer($row['id'], $force);
        return ['status' => 'ok', 'peers' => $out];
    }

    // =====================================================================
    // Administrator actions (Manager)
    // =====================================================================

    public static function rotate(string $peerId): array {
        $row = RelayPeerStore::find($peerId);
        if ($row === null || $row['state'] !== 'linked') return ['status' => 'error', 'message' => 'Only a linked box can get a new key.'];
        $key = RelayPeerStore::linkKey($peerId);
        if ($key === '') return ['status' => 'error', 'message' => 'This link has no key. Pair the boxes again.'];
        $new = RelayPeerCrypto::newKey();
        $r = self::rekeyCall($row, $new, $key);
        if (!$r['ok']) { self::noteResult($row, $r); return ['status' => 'error', 'message' => RelayPeerStore::displayName($row) . ' did not confirm the new key (' . $r['error'] . '). The old key stays in use.']; }
        if (!RelayPeerStore::setLinkKey($peerId, $new, true)) return ['status' => 'error', 'message' => 'Could not save the new key.'];
        RelayPeerStore::update($peerId, ['old_key_until' => self::now() + self::REKEY_GRACE]);
        RelayPeerStore::audit($peerId, 'rekeyed', ['by' => 'this_box']);
        self::noteResult($row, $r);
        return ['status' => 'ok', 'peer' => self::peerView(RelayPeerStore::find($peerId) ?? $row)];
    }

    /**
     * The new key is wrapped with the CURRENT link key and the request nonce,
     * so rekey builds its own signed call (call() makes its nonce inside).
     */
    private static function rekeyCall(array $row, string $newKey, string $key): array {
        $self = RelayPeerStore::boxId();
        $path = self::PREFIX . 'rekey';
        $ts = (string)self::now(); $nonce = RelayPeerCrypto::newNonce();
        $body = json_encode(['key_enc' => RelayPeerCrypto::wrapKey($newKey, $key, 'rekey', $nonce)], JSON_UNESCAPED_SLASHES);
        $headers = ['X-Aicli-Box' => $self, 'X-Aicli-Ts' => $ts, 'X-Aicli-Nonce' => $nonce,
            'X-Aicli-Sig' => RelayPeerCrypto::signRequest($key, $self, $row['box_id'], 'POST', $path, $ts, $nonce, $body)];
        $res = RelayPeerClient::post($row['url'], $path, $headers, $body, $row['spki_sha256']);
        if ($res['pin_mismatch']) return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'pin_mismatch'];
        if (!$res['ok']) return ['ok' => false, 'status' => $res['status'], 'data' => [], 'error' => 'unreachable'];
        $data = json_decode($res['body'], true); $data = is_array($data) ? $data : [];
        $rsig = (string)($res['headers']['x-aicli-sig'] ?? '');
        $valid = $rsig !== '' && hash_equals(RelayPeerCrypto::signResponse($key, $row['box_id'], $self, $res['status'], $nonce, $res['body']), $rsig);
        if (!$valid) return ['ok' => false, 'status' => $res['status'], 'data' => $data, 'error' => (string)($data['error'] ?? 'unsigned'), 'unsigned' => true];
        return ['ok' => $res['status'] === 200 && !empty($data['rekeyed']), 'status' => $res['status'], 'data' => $data, 'error' => $res['status'] === 200 ? '' : (string)($data['error'] ?? 'http_' . $res['status'])];
    }

    public static function unlink(string $peerId): array {
        $row = RelayPeerStore::find($peerId);
        if ($row === null) return ['status' => 'error', 'message' => 'Unknown linked box.'];
        $told = false;
        if ($row['state'] === 'linked' && RelayPeerStore::linkKey($peerId) !== '' && !self::$inListener) {
            $r = self::call($row, 'unlink', [], 5);   // best effort (spec §2)
            $told = $r['ok'];
        }
        self::markUnlinked($peerId, 'unlinked');
        return ['status' => 'ok', 'peer_told' => $told, 'peer' => self::peerView(RelayPeerStore::find($peerId) ?? $row)];
    }

    /** Delete the keys, set the state, and expire the outbox as peer_unlinked. */
    private static function markUnlinked(string $peerId, string $state): void {
        $row = RelayPeerStore::find($peerId); if ($row === null) return;
        RelayPeerStore::deleteLinkKeys($peerId);
        RelayPeerStore::update($peerId, ['state' => $state, 'old_key_until' => 0,
            'notice' => $state === 'unlinked_by_peer' ? 'Link revoked by peer — pair the boxes again to send.' : 'Unlinked on ' . gmdate('Y-m-d H:i', self::now()) . ' UTC.']);
        foreach (self::outboxFiles($peerId) as $f) {
            $e = json_decode((string)@file_get_contents($f), true);
            if (is_array($e)) self::finish($row, $f, $e, 'peer_unlinked', '');
            else @unlink($f);
        }
        @unlink(RelayPeerStore::dir($peerId) . '/contacts.json');
        RelayPeerStore::audit($peerId, $state === 'unlinked' ? 'unlinked' : 'unlinked_by_peer', []);
    }

    public static function rename(string $peerId, string $label): array {
        $label = RelayPeerStore::cleanLabel($label, '');
        if ($label === '') return ['status' => 'error', 'message' => 'A box needs a name (letters, digits, spaces, . _ - ( )).'];
        $row = RelayPeerStore::update($peerId, ['label' => $label]);
        if ($row === null) return ['status' => 'error', 'message' => 'Unknown linked box.'];
        RelayPeerStore::audit($peerId, 'renamed', ['label' => $label]);
        return ['status' => 'ok', 'peer' => self::peerView($row)];
    }

    /** Set a peer's grants and rate (the Permissions dialog). Default-deny for anything unknown. */
    public static function setGrants(string $peerId, array $grants, $rate): array {
        $row = RelayPeerStore::find($peerId);
        if ($row === null) return ['status' => 'error', 'message' => 'Unknown linked box.'];
        $clean = RelayGrants::normalise(RelayGrants::KIND_PEER, $grants);
        $rate = RelayGrants::normaliseRate(RelayGrants::KIND_PEER, $rate);
        $row = RelayPeerStore::update($peerId, ['grants' => $clean, 'rate_per_min' => $rate]);
        if ($row === null) return ['status' => 'error', 'message' => 'Could not save the permissions.'];
        RelayPeerStore::audit($peerId, 'grants_changed', ['grants' => $clean, 'rate_per_min' => $rate]);
        return ['status' => 'ok', 'peer' => self::peerView($row)];
    }

    public static function test(string $peerId): array {
        $r = self::syncPeer($peerId, true);
        $row = RelayPeerStore::find($peerId);
        return ['status' => 'ok', 'result' => $r, 'peer' => $row ? self::peerView($row) : null];
    }

    /** Non-secret view of one row for the Manager. */
    public static function peerView(array $row): array {
        $sync = RelayPeerStore::readRuntime($row['id'], 'sync', []);
        $waiting = count(self::outboxFiles($row['id']));
        $cache = RelayPeerStore::readRuntime($row['id'], 'contacts', []);
        return [
            'id' => $row['id'], 'box_id' => $row['box_id'], 'name' => $row['name'], 'label' => $row['label'],
            'display' => RelayPeerStore::displayName($row), 'url' => $row['url'],
            'spki_short' => substr($row['spki_sha256'], 0, 12), 'state' => $row['state'],
            'status' => self::peerState($row), 'online' => self::peerOnline($row),
            'last_ok_at' => $row['last_ok_at'], 'last_in_at' => $row['last_in_at'], 'last_error' => $row['last_error'],
            'offline_since' => isset($sync['offline_since']) ? gmdate('c', (int)$sync['offline_since']) : '',
            'notice' => $row['notice'], 'clock_skew' => $row['clock_skew'],
            'waiting' => $waiting, 'grants' => $row['grants'], 'grants_summary' => RelayGrants::summary($row['grants']),
            'rate_per_min' => $row['rate_per_min'], 'remote_version' => $row['remote_version'],
            'contacts' => count((array)($cache['contacts'] ?? [])), 'created_at' => $row['created_at'],
        ];
    }

    /** Everything the Manager section needs in one read. */
    public static function overview(): array {
        $l = AgentRelayService::httpListenerSettings();
        $d = RelayPeerStore::read();
        $pairing = is_array($d['pairing']) && (int)($d['pairing']['expires_at'] ?? 0) > self::now() && RelayPeerStore::pairingSecret() !== ''
            ? ['expires_at' => (int)$d['pairing']['expires_at']] : null;
        $workspaces = [];
        foreach (ConfigService::getWorkspaces()['sessions'] ?? [] as $w) {
            $sid = (string)($w['id'] ?? '');
            if (self::exportable($sid)) $workspaces[] = ['session_id' => $sid, 'name' => (string)($w['name'] ?? $sid), 'agent_id' => (string)($w['agentId'] ?? '')];
        }
        return [
            'status' => 'ok',
            'enabled' => AgentRelayService::peerLinksSwitch(),
            'active' => self::enabled(),
            'listener_enabled' => $l['enabled'],
            'box_id' => $d['box_id'], 'box_name' => self::selfName(),
            'pairing' => $pairing,
            'max_peers' => RelayPeerStore::MAX_PEERS,
            'peers' => array_map([self::class, 'peerView'], $d['peers']),
            'workspaces' => $workspaces,
        ];
    }

    /** Activity-tray entries: messages waiting over 5 minutes for a peer that does not answer (spec UI). */
    public static function queueActivities(): array {
        if (!self::enabled()) return [];
        $out = [];
        foreach (RelayPeerStore::peers() as $row) {
            $files = self::outboxFiles($row['id']);
            if ($files === [] || self::peerOnline($row)) continue;
            $first = json_decode((string)@file_get_contents($files[0]), true);
            $age = self::now() - (int)(is_array($first) ? ($first['queued_at'] ?? self::now()) : self::now());
            if ($age < self::QUEUE_NOTICE_AFTER) continue;
            $n = count($files); $box = RelayPeerStore::displayName($row);
            $out[] = [
                'opId' => 'relay_peer_queue__' . $row['id'], 'type' => 'relay_peer_queue',
                'label' => "$n message" . ($n > 1 ? 's' : '') . " waiting for $box (not reachable)",
                'step' => '', 'progress' => 0, 'status' => 'waiting',
                'startedAt' => self::now() - $age, 'heartbeatAt' => self::now(),
                'error' => null, 'recovery' => null, 'count' => $n,
                'meta' => ['peer' => $row['id'], 'box' => $box, 'count' => $n],
            ];
        }
        return $out;
    }
}
