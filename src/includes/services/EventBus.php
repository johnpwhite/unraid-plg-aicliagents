<?php
/**
 * <module_context>
 *     <name>EventBus</name>
 *     <description>One publish facade for every server-side event
 *     (docs/specs/EVENT_STREAM_MULTIPLEX.md, "Server: EventBus", R4).
 *     EventBus::publish($kind, $subject, $data) resolves the nchan channel,
 *     the buffer depth and the ledger-kind list from ONE registry
 *     (EventBus::REGISTRY) and hands the wire payload to NchanService
 *     unchanged, so an open tab on an older generation still understands it.
 *     NchanService stays the transport: its test seams ($transport,
 *     $statusPath, $socketPath), its failure counter and its ledger tee
 *     behave exactly as before EventBus existed. Only EventBus (and
 *     NchanService itself) may call NchanService::publish() /
 *     publishInstallProgress() — RegressionGuardsTest pins that.</description>
 *     <dependencies>NchanService (the transport, required AFTER this class is
 *     declared — see the require_once at the foot of this file); EventLedger
 *     (kindForChannel(), read only so tests can prove REGISTRY.ledgerKinds is
 *     complete).</dependencies>
 *     <constraints>publish() never throws. An unknown $kind, or `install`
 *     with no `agentId` in $subject, does not publish: it logs one WARN via
 *     aicli_log() (guarded with function_exists, since this class can load
 *     before the manager defines that helper) and returns.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/EventLedger.php';

class EventBus {

    /**
     * Per-channel Nchan buffer depth. This is the ONE literal list;
     * NchanService::CHANNELS is defined FROM it (`= EventBus::CHANNEL_DEPTHS`)
     * so there is exactly one place to add a channel. A key ending in `_` is
     * a prefix match (e.g. `install_` matches `install_claude-code`).
     */
    public const CHANNEL_DEPTHS = [
        'activity'         => 20,
        'workspaces'       => 20,
        'storage_status'   => 1,
        'deploy'           => 1,
        'migrate_progress' => 1,
        'install_'         => 1,
        'voice'            => 1,
        'favourites'       => 1,
        'voicemail'        => 1,
    ];

    /**
     * The one registry: bus kind => channel, buffer depth, and every ledger
     * kind EventLedger::kindForChannel() can produce for that channel. A
     * `channel` ending in `_` is a prefix template — publish() appends
     * `$subject['agentId']` to build the real channel (today only `install`
     * uses this).
     */
    public const REGISTRY = [
        'activity' => [
            'channel'     => 'activity',
            'depth'       => 20,
            'ledgerKinds' => [
                'activity.dismissed', 'activity.proposed', 'activity.approved',
                'activity.rejected', 'activity.failed', 'activity.finished',
                'activity.updated',
            ],
        ],
        'workspace' => [
            'channel'     => 'workspaces',
            'depth'       => 20,
            'ledgerKinds' => [
                // EventLedger::kindForChannel() builds 'workspace.' . $event,
                // defaulting to 'workspace.updated' when $event is empty —
                // these are every $event a publisher sends today.
                'workspace.created', 'workspace.updated', 'workspace.removed',
                'workspace.started', 'workspace.stopped', 'workspace.bridge',
                'workspace.exited',
            ],
        ],
        'storage.status' => [
            'channel'     => 'storage_status',
            'depth'       => 1,
            'ledgerKinds' => ['storage.status', 'storage.maintenance'],
        ],
        'deploy' => [
            'channel'     => 'deploy',
            'depth'       => 1,
            'ledgerKinds' => ['deploy.activated'],
        ],
        'storage.migrate' => [
            'channel'     => 'migrate_progress',
            'depth'       => 1,
            'ledgerKinds' => ['storage.migrate'],
        ],
        'install' => [
            // Prefix template: the real channel is 'install_' . $subject['agentId'].
            'channel'     => 'install_',
            'depth'       => 1,
            'ledgerKinds' => ['install.progress', 'install.complete'],
        ],
        'voice' => [
            'channel'     => 'voice',
            'depth'       => 1,
            // A `mode=state` message is not teed at all (VOICE_SWITCHES.md
            // R8 — nobody spoke, the switch just moved); every other message
            // on this channel is one spoken utterance.
            'ledgerKinds' => ['agent.spoke'],
        ],
        'favourite' => [
            'channel'     => 'favourites',
            'depth'       => 1,
            'ledgerKinds' => [
                'favourite.added', 'favourite.updated', 'favourite.removed',
                'favourite.opened',
            ],
        ],
        'voicemail' => [
            'channel'     => 'voicemail',
            'depth'       => 1,
            'ledgerKinds' => [
                'voicemail.kept', 'voicemail.heard', 'voicemail.deleted',
                'voicemail.updated',
            ],
        ],
    ];

    /**
     * Publish one event. $kind is a REGISTRY key. $subject resolves a
     * per-subject channel (`install` needs `agentId`) and is otherwise
     * informational — it is never sent as part of the wire payload. $data is
     * the wire payload, sent to NchanService unchanged (it stamps `ts`).
     * Never throws.
     */
    public static function publish(string $kind, array $subject, array $data): void {
        try {
            if (!array_key_exists($kind, self::REGISTRY)) {
                self::warn("EventBus::publish() called with an unknown kind '$kind'.");
                return;
            }
            $entry = self::REGISTRY[$kind];
            $channelTemplate = (string) $entry['channel'];
            if (substr($channelTemplate, -1) === '_') {
                $agentId = (string) ($subject['agentId'] ?? '');
                if ($agentId === '') {
                    self::warn("EventBus::publish('$kind', ...) called with no agentId in \$subject.");
                    return;
                }
                $channel = $channelTemplate . $agentId;
            } else {
                $channel = $channelTemplate;
            }
            NchanService::publish($channel, $data);
        } catch (\Throwable $e) {
            // Fire and forget — mirrors NchanService::publish()'s own contract.
        }
    }

    /**
     * Publish install progress for an agent. Delegates to publish() so the
     * install channel, buffer depth and ledger tee all stay wired through
     * the one registry. Payload shape is unchanged from the pre-EventBus
     * NchanService::publishInstallProgress().
     */
    public static function publishInstallProgress(string $agentId, int $progress, string $step, string $reason = ''): void {
        self::publish('install', ['agentId' => $agentId], [
            'agentId'   => $agentId,
            'progress'  => $progress,
            'step'      => $step,
            'completed' => $progress >= 100,
            'reason'    => $reason,
            'timestamp' => time(),
        ]);
    }

    /**
     * Reverse lookup for event-publish.php (the shell publishers' CLI entry):
     * an nchan channel name => its bus kind, or null when the channel
     * matches nothing in REGISTRY. Handles the `install_` prefix — any
     * `install_<agentId>` resolves to kind `install`.
     */
    public static function kindForChannel(string $channel): ?string {
        foreach (self::REGISTRY as $kind => $entry) {
            $entryChannel = (string) $entry['channel'];
            if (substr($entryChannel, -1) === '_') {
                if (strlen($channel) > strlen($entryChannel)
                    && strncmp($channel, $entryChannel, strlen($entryChannel)) === 0) {
                    return $kind;
                }
                continue;
            }
            if ($entryChannel === $channel) {
                return $kind;
            }
        }
        return null;
    }

    /** One WARN line via aicli_log(), guarded — a missing/broken logger must never break publish()'s no-throw contract. */
    private static function warn(string $message): void {
        if (function_exists('aicli_log')) {
            aicli_log($message, defined('AICLI_LOG_WARN') ? AICLI_LOG_WARN : 1, 'EventBus');
        }
    }
}

// Required AFTER the class above is declared (not before): NchanService's own
// class-constant declaration (`CHANNELS = EventBus::CHANNEL_DEPTHS`) needs
// EventBus already defined, so NchanService.php requires THIS file before its
// own class body. Requiring NchanService.php here, at the foot of this file,
// avoids the reverse ordering problem — by the time either file needs the
// other's class at runtime, both are fully declared.
require_once __DIR__ . '/NchanService.php';
