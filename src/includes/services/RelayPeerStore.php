<?php
/**
 * <module_context>
 *     <name>RelayPeerStore</name>
 *     <description>docs/specs/RELAY_LINKED_BOXES.md §1–§2 — where linked-box
 *     state lives. The box identity and the peer rows (no secrets) are on the
 *     flash drive in relay-peers.json, because a home can be restored onto a
 *     different box and the flash drive belongs to one box only. Link keys and
 *     the one-time pairing secret are in the secret vault. Per-peer runtime
 *     files (outbox, contact cache, seen ids, nonces, audit) are in the Relay
 *     store under peers/&lt;peer-id&gt;/.</description>
 *     <dependencies>AgentRelayService (baseDir), AtomicWriteService,
 *     SecretService, RelayGrants, EventLedger</dependencies>
 *     <constraints>Never logs or returns a key. A row id is
 *     `peer_&lt;12 hex&gt;` and never changes, so contact ids and threads
 *     survive a rename. Maximum 8 peers.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class RelayPeerStore {
    const FLASH_FILE = '/boot/config/plugins/unraid-aicliagents/relay-peers.json';
    const MAX_PEERS = 8;
    const LABEL_MAX = 40;
    const VAULT_PREFIX = 'AICLI_RELAY_PEER_';
    const PAIRING_VAULT_KEY = 'AICLI_RELAY_PAIRING';
    const AUDIT_MAX_LINES = 1000;
    /** States a row can hold. Online / offline is derived from last_ok_at, not stored. */
    const STATES = ['linked', 'needs_repair', 'cert_changed', 'unlinked', 'unlinked_by_peer'];
    /**
     * Fields that change on every call. They live in the Relay store
     * (peers/<id>/status.json), not on flash: a busy link must not wear the
     * USB flash drive. Everything that defines the link stays on flash.
     */
    const VOLATILE = ['last_ok_at', 'last_in_at', 'last_error', 'clock_skew'];

    public static function file(): string {
        $e = getenv('AICLI_RELAY_PEERS_FILE');   // test seam; unset in production
        return ($e !== false && $e !== '') ? $e : self::FLASH_FILE;
    }

    public static function read(): array {
        $file = self::file();
        $d = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        if (!is_array($d)) $d = [];
        $d['schema'] = 1;
        $d['box_id'] = preg_match('/^[a-f0-9]{16}$/', (string)($d['box_id'] ?? '')) ? (string)$d['box_id'] : '';
        $rows = [];
        foreach ((is_array($d['peers'] ?? null) ? $d['peers'] : []) as $r) {
            if (!is_array($r) || !self::validId((string)($r['id'] ?? ''))) continue;
            $status = self::readRuntime((string)$r['id'], 'status', []);
            foreach (self::VOLATILE as $k) if (array_key_exists($k, $status)) $r[$k] = $status[$k];
            $rows[] = self::normaliseRow($r);
        }
        $d['peers'] = $rows;
        $d['pairing'] = is_array($d['pairing'] ?? null) ? $d['pairing'] : null;
        return $d;
    }

    public static function write(array $d): bool {
        $d['schema'] = 1;
        $flash = [];
        foreach (array_values($d['peers'] ?? []) as $r) {
            $status = [];
            foreach (self::VOLATILE as $k) { if (array_key_exists($k, $r)) $status[$k] = $r[$k]; unset($r[$k]); }
            if ($status !== [] && self::readRuntime((string)$r['id'], 'status', []) != $status) self::writeRuntime((string)$r['id'], 'status', $status);
            $flash[] = $r;
        }
        $d['peers'] = $flash;
        // Skip the flash write when nothing that lives on flash changed.
        $file = self::file();
        $current = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        if (is_array($current) && $current == $d) return true;
        $ok = AtomicWriteService::writeJson($file, $d);
        if ($ok) @chmod($file, 0600);
        return $ok;
    }

    /** This box's id. Minted and saved on first use (spec §1). */
    public static function boxId(): string {
        $d = self::read();
        if ($d['box_id'] !== '') return $d['box_id'];
        $d['box_id'] = bin2hex(random_bytes(8));
        return self::write($d) ? $d['box_id'] : '';
    }

    public static function validId(string $id): bool { return (bool)preg_match('/^peer_[a-f0-9]{12}$/', $id); }

    private static function normaliseRow(array $r): array {
        $state = in_array((string)($r['state'] ?? ''), self::STATES, true) ? (string)$r['state'] : 'linked';
        return [
            'id' => (string)$r['id'],
            'box_id' => preg_match('/^[a-f0-9]{16}$/', (string)($r['box_id'] ?? '')) ? (string)$r['box_id'] : '',
            'name' => self::cleanLabel((string)($r['name'] ?? ''), 'box'),
            'label' => self::cleanLabel((string)($r['label'] ?? ''), ''),
            'url' => (string)($r['url'] ?? ''),
            'spki_sha256' => (string)($r['spki_sha256'] ?? ''),
            'state' => $state,
            'grants' => RelayGrants::normalise(RelayGrants::KIND_PEER, is_array($r['grants'] ?? null) ? $r['grants'] : null),
            'rate_per_min' => RelayGrants::normaliseRate(RelayGrants::KIND_PEER, $r['rate_per_min'] ?? null),
            'protocol' => (int)($r['protocol'] ?? RelayPeerCrypto::PROTOCOL),
            'remote_version' => (string)($r['remote_version'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
            'last_ok_at' => (string)($r['last_ok_at'] ?? ''),
            'last_in_at' => (string)($r['last_in_at'] ?? ''),
            'last_error' => (string)($r['last_error'] ?? ''),
            'notice' => (string)($r['notice'] ?? ''),
            'old_key_until' => (int)($r['old_key_until'] ?? 0),
            'clock_skew' => (int)($r['clock_skew'] ?? 0),
        ];
    }

    /** Printable, bounded display text. The label is local, so a peer cannot choose how it is shown here. */
    public static function cleanLabel(string $s, string $fallback): string {
        $s = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^\p{L}\p{N} ._\x27()-]/u', '', $s)));
        if (mb_strlen($s) > self::LABEL_MAX) $s = mb_substr($s, 0, self::LABEL_MAX);
        return $s !== '' ? $s : $fallback;
    }

    /** The name this box shows for a peer: the local label, else the peer's own name. */
    public static function displayName(array $row): string {
        return $row['label'] !== '' ? $row['label'] : ($row['name'] !== '' ? $row['name'] : $row['id']);
    }

    public static function peers(): array { return self::read()['peers']; }

    public static function find(string $id): ?array {
        foreach (self::peers() as $r) if ($r['id'] === $id) return $r;
        return null;
    }

    public static function findByBox(string $boxId): ?array {
        if ($boxId === '') return null;
        foreach (self::peers() as $r) if ($r['box_id'] === $boxId) return $r;
        return null;
    }

    /** Insert or replace one row by id. */
    public static function save(array $row): bool {
        $d = self::read(); $found = false;
        foreach ($d['peers'] as $i => $r) {
            if ($r['id'] === $row['id']) { $d['peers'][$i] = self::normaliseRow($row); $found = true; break; }
        }
        if (!$found) {
            if (count($d['peers']) >= self::MAX_PEERS) return false;
            $d['peers'][] = self::normaliseRow($row);
        }
        return self::write($d);
    }

    /** Apply $patch to one row. Returns the new row, or null when unknown. */
    public static function update(string $id, array $patch): ?array {
        $row = self::find($id); if ($row === null) return null;
        $row = array_merge($row, $patch);
        return self::save($row) ? self::normaliseRow($row) : null;
    }

    public static function newId(): string { return 'peer_' . bin2hex(random_bytes(6)); }

    /** Two peers with the same name get "tower (2)" (spec §4). */
    public static function uniqueLabel(string $name, string $exceptId = ''): string {
        $taken = [];
        foreach (self::peers() as $r) if ($r['id'] !== $exceptId && $r['state'] !== 'unlinked') $taken[strtolower(self::displayName($r))] = true;
        if (!isset($taken[strtolower($name)])) return '';
        for ($n = 2; $n < 100; $n++) if (!isset($taken[strtolower("$name ($n)")])) return "$name ($n)";
        return '';
    }

    // ---- vault ----------------------------------------------------------------

    public static function vaultKey(string $id): string { return self::VAULT_PREFIX . strtoupper(substr($id, 5)); }

    public static function linkKey(string $id): string {
        $k = (string)(SecretService::getAgentSecrets()[self::vaultKey($id)] ?? '');
        return preg_match('/^[a-f0-9]{64}$/', $k) ? $k : '';
    }

    public static function oldLinkKey(string $id): string {
        $k = (string)(SecretService::getAgentSecrets()[self::vaultKey($id) . '_OLD'] ?? '');
        return preg_match('/^[a-f0-9]{64}$/', $k) ? $k : '';
    }

    /** Set the current key; $keepOld keeps the previous key as _OLD (rekey grace). */
    public static function setLinkKey(string $id, string $keyHex, bool $keepOld = false): bool {
        $vault = SecretService::getAgentSecrets(); $k = self::vaultKey($id);
        if ($keepOld && isset($vault[$k])) $vault[$k . '_OLD'] = $vault[$k]; else unset($vault[$k . '_OLD']);
        $vault[$k] = $keyHex;
        return SecretService::saveAgentSecrets($vault);
    }

    public static function deleteLinkKeys(string $id): bool {
        $vault = SecretService::getAgentSecrets(); $k = self::vaultKey($id);
        unset($vault[$k], $vault[$k . '_OLD']);
        return SecretService::saveAgentSecrets($vault);
    }

    public static function pairingSecret(): string {
        $s = (string)(SecretService::getAgentSecrets()[self::PAIRING_VAULT_KEY] ?? '');
        return preg_match('/^[a-f0-9]{32}$/', $s) ? $s : '';
    }

    public static function setPairingSecret(?string $hex): bool {
        $vault = SecretService::getAgentSecrets();
        if ($hex === null) unset($vault[self::PAIRING_VAULT_KEY]); else $vault[self::PAIRING_VAULT_KEY] = $hex;
        return SecretService::saveAgentSecrets($vault);
    }

    // ---- per-peer runtime files -----------------------------------------------------

    public static function dir(string $id): string { return AgentRelayService::baseDir() . '/peers/' . $id; }

    public static function readRuntime(string $id, string $name, array $fallback): array {
        $f = self::dir($id) . "/$name.json";
        $v = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        return is_array($v) ? $v : $fallback;
    }

    public static function writeRuntime(string $id, string $name, array $data): bool {
        return AtomicWriteService::writeJson(self::dir($id) . "/$name.json", $data);
    }

    /**
     * Durable audit line (no message text) plus one event-ledger line. The
     * ledger is cleared at reboot; the audit file keeps the last 1 000 lines.
     */
    public static function audit(string $id, string $event, array $data = []): void {
        $line = json_encode(['at' => gmdate('c'), 'event' => $event] + $data, JSON_UNESCAPED_SLASHES) . "\n";
        $f = self::dir($id) . '/audit.jsonl';
        @mkdir(dirname($f), 0770, true);
        @file_put_contents($f, $line, FILE_APPEND);
        $lines = @file($f) ?: [];
        if (count($lines) > self::AUDIT_MAX_LINES + 100) {
            AtomicWriteService::write($f, implode('', array_slice($lines, -self::AUDIT_MAX_LINES)));
        }
        try {
            if (class_exists('\AICliAgents\Services\EventLedger')) {
                EventLedger::append('relay.peer.' . $event, ['peer' => $id], "Linked box $id: $event", $data);
            }
        } catch (\Throwable $e) {
            // The ledger never breaks a Relay call.
        }
    }

    /** @return array<int,array<string,mixed>> newest last */
    public static function auditTail(string $id, int $n = 20): array {
        $out = [];
        foreach (array_slice(@file(self::dir($id) . '/audit.jsonl') ?: [], -$n) as $l) {
            $v = json_decode($l, true); if (is_array($v)) $out[] = $v;
        }
        return $out;
    }
}
