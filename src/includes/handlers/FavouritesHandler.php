<?php
/**
 * <module_context>
 *     <name>FavouritesHandler</name>
 *     <description>AJAX handler for workspace favourites
 *     (docs/specs/WORKSPACE_FAVOURITES.md R2): list/add/update/remove/touch a saved
 *     (agentId, path) bookmark. `add_favourite` upserts from a live workspace
 *     record. Every favourite a read returns also carries whether its agent is
 *     installed and, when a workspace with the same (agentId, path) is open right
 *     now, that workspace's id.</description>
 *     <dependencies>FavouritesService (storage), AgentRegistry (agentInstalled),
 *     ConfigService (the open-workspace lookup — the same sessions list
 *     AdminService::getWorkspace() reads).</dependencies>
 *     <constraints>CSRF is validated once, centrally, by the AJAX dispatcher
 *     (AICliAjax.php) for every action, GET or POST — this handler, like every
 *     other handler in this codebase (see TmuxHandler's own doc: "CSRF done at
 *     dispatcher"), does not repeat that check itself. REVIEW_2026-09-13_
 *     EVENTS_AND_SECURITY.md E1: every browser-path add/update/remove/touch
 *     also publishes {kind, id} on the 'favourites' Nchan channel via
 *     EventBus::publish('favourite', ...) — the SAME call AdminService's Tier
 *     2 tools would need for a live UI update, so a favourite change made
 *     from EITHER surface now reaches every open tab. The publish's own tee
 *     (NchanService -> EventLedger::kindForChannel('favourites', ...)) is the
 *     ledger append; this handler never calls EventLedger::append() a second
 *     time.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\ConfigService;
use AICliAgents\Services\FavouritesService;
use AICliAgents\Services\EventBus;

require_once __DIR__ . '/../services/FavouritesService.php';
require_once __DIR__ . '/../services/EventBus.php';

class FavouritesHandler
{
    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'list_favourites':  return self::listFavourites();
            case 'add_favourite':    return self::addFavourite();
            case 'update_favourite': return self::updateFavourite();
            case 'remove_favourite': return self::removeFavourite();
            case 'touch_favourite':  return self::touchFavourite();
            default:                 return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['list_favourites', 'add_favourite', 'update_favourite', 'remove_favourite', 'touch_favourite'];
    }

    private static function listFavourites(): array
    {
        return ['status' => 'ok', 'favourites' => self::decorateAll(FavouritesService::list())];
    }

    /** POST workspaceId. */
    private static function addFavourite(): array
    {
        $workspaceId = trim((string)($_REQUEST['workspaceId'] ?? ''));
        if ($workspaceId === '') {
            return ['status' => 'error', 'message' => 'Missing workspaceId'];
        }
        $result = FavouritesService::addFromWorkspace($workspaceId);
        if (isset($result['error'])) {
            return ['status' => 'error', 'message' => (string)$result['error']];
        }
        // E1: always 'favourite.added', matching AdminService::addFavourite() —
        // both authorship paths call the SAME upsert, so an existing
        // (agentId, path) pair refreshed by this call is still the "added"
        // kind, never a separate "updated" for this action.
        self::publishChange('favourite.added', (string)$result['favourite']['id']);
        return [
            'status'    => 'ok',
            'favourite' => self::decorate($result['favourite']),
            'updated'   => (bool)$result['updated'],
        ];
    }

    /** POST id, and optionally name / voice. */
    private static function updateFavourite(): array
    {
        $id = trim((string)($_REQUEST['id'] ?? ''));
        if ($id === '') {
            return ['status' => 'error', 'message' => 'Missing id'];
        }
        $patch = [];
        if (array_key_exists('name', $_REQUEST))  $patch['name']  = (string)$_REQUEST['name'];
        if (array_key_exists('voice', $_REQUEST)) $patch['voice'] = self::toBool($_REQUEST['voice']);

        $result = FavouritesService::update($id, $patch);
        if (isset($result['error'])) {
            return ['status' => 'error', 'message' => (string)$result['error']];
        }
        self::publishChange('favourite.updated', $id);
        return ['status' => 'ok', 'favourite' => self::decorate($result['favourite'])];
    }

    /** POST id. */
    private static function removeFavourite(): array
    {
        $id = trim((string)($_REQUEST['id'] ?? ''));
        if ($id === '') {
            return ['status' => 'error', 'message' => 'Missing id'];
        }
        if (!FavouritesService::remove($id)) {
            return ['status' => 'error', 'message' => "No favourite with id '$id' was found."];
        }
        self::publishChange('favourite.removed', $id);
        return ['status' => 'ok'];
    }

    /** POST id. */
    private static function touchFavourite(): array
    {
        $id = trim((string)($_REQUEST['id'] ?? ''));
        if ($id === '') {
            return ['status' => 'error', 'message' => 'Missing id'];
        }
        if (!FavouritesService::touch($id)) {
            return ['status' => 'error', 'message' => "No favourite with id '$id' was found."];
        }
        self::publishChange('favourite.opened', $id);
        return ['status' => 'ok'];
    }

    /**
     * REVIEW_2026-09-13_EVENTS_AND_SECURITY.md E1: publish {kind, id} on the
     * 'favourites' Nchan channel for every successful browser-path change.
     * EventBus::publish() stamps `ts` (via NchanService) and, through the
     * same tee (EventLedger::kindForChannel('favourites', ...)), appends the
     * SAME ledger row AdminService::emitEvent() would append for its own
     * Tier 2 favourite tools — one code path, whichever surface made the
     * change. Best-effort: a publish failure must never turn a successful
     * favourite change into an error response (EventBus::publish() already
     * never throws; this wrapper is belt-and-braces for a future change to
     * that).
     */
    private static function publishChange(string $kind, string $id): void
    {
        try {
            EventBus::publish('favourite', [], ['kind' => $kind, 'id' => $id]);
        } catch (\Throwable $e) {
            // Best-effort — see doc comment above.
        }
    }

    /**
     * Adds agentInstalled + openWorkspaceId to every favourite in one list,
     * reading the registry/sessions only once. Public: AdminService::
     * listFavourites() (WORKSPACE_FAVOURITES.md R7) reuses this SAME
     * decoration for its `aicli_list_favourites` tool rather than a second
     * copy of the registry/sessions lookup.
     */
    public static function decorateAll(array $favourites): array
    {
        $registry = AgentRegistry::getRegistry();
        $sessions = ConfigService::getWorkspaces()['sessions'] ?? [];
        return array_map(
            static fn(array $f): array => self::decorateWith($f, $registry, $sessions),
            $favourites
        );
    }

    /**
     * Same as decorateAll(), for a single favourite (add/update results).
     * Public: AdminService::addFavourite() (WORKSPACE_FAVOURITES.md R7) reuses
     * this SAME decoration for its `aicli_add_favourite` tool.
     */
    public static function decorate(array $favourite): array
    {
        return self::decorateWith($favourite, AgentRegistry::getRegistry(), ConfigService::getWorkspaces()['sessions'] ?? []);
    }

    private static function decorateWith(array $favourite, array $registry, array $sessions): array
    {
        // The plain terminal is a built-in pseudo-agent: it has no registry entry
        // and is always available (found by smoke A219, 2026-09-12).
        $agentId = (string)($favourite['agentId'] ?? '');
        $favourite['agentInstalled'] = $agentId === 'terminal' || !empty($registry[$agentId]['is_installed']);

        $openWorkspaceId = '';
        foreach ($sessions as $w) {
            if (!is_array($w)) continue;
            if ((string)($w['agentId'] ?? '') === $favourite['agentId'] && (string)($w['path'] ?? '') === $favourite['path']) {
                $openWorkspaceId = (string)($w['id'] ?? '');
                break;
            }
        }
        $favourite['openWorkspaceId'] = $openWorkspaceId;

        return $favourite;
    }

    private static function toBool($value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'on', 'yes'], true);
    }
}
