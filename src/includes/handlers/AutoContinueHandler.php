<?php
/**
 * <module_context>
 *     <name>AutoContinueHandler</name>
 *     <description>AJAX actions for Settings, Auto-continue patterns
 *     (docs/specs/AUTO_CONTINUE_PATTERNS.md): list the built-in and user
 *     patterns, save / enable / delete a user pattern, test an expression on a
 *     workspace's current screen, and build the prefilled GitHub report URL.</description>
 *     <dependencies>AutoContinueRules (storage, validation, report URL),
 *     WorkspaceScreenService (the masked, logged screen capture), AgentRegistry,
 *     ConfigService, ProcessManager.</dependencies>
 *     <constraints>CSRF is validated once by the dispatcher (AICliAjax.php).
 *     Every expression goes through AutoContinueRules::validate() — the same
 *     validator as the MCP tool. A test capture is logged like the MCP read.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\AutoContinueRules;
use AICliAgents\Services\ConfigService;
use AICliAgents\Services\ProcessManager;
use AICliAgents\Services\WorkspaceScreenService;

class AutoContinueHandler
{
    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'get_autocontinue_patterns':        return self::listAll();
            case 'save_autocontinue_pattern':        return self::save();
            case 'set_autocontinue_pattern_enabled': return self::setEnabled();
            case 'delete_autocontinue_pattern':      return self::delete();
            case 'test_autocontinue_pattern':        return self::test();
            case 'build_autocontinue_report':        return self::report();
            default:                                 return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['get_autocontinue_patterns', 'save_autocontinue_pattern', 'set_autocontinue_pattern_enabled', 'delete_autocontinue_pattern', 'test_autocontinue_pattern', 'build_autocontinue_report'];
    }

    private static function req(string $k): string
    {
        return (string)($_REQUEST[$k] ?? '');
    }

    private static function listAll(): array
    {
        $loaded = AutoContinueRules::load();
        $agents = [];
        try {
            foreach (AgentRegistry::getRegistry() as $aid => $a) {
                if ($aid === 'terminal') continue;
                $agents[] = ['id' => (string)$aid, 'name' => (string)($a['name'] ?? $aid)];
            }
        } catch (\Throwable $e) { /* the list still works with "All agents" only */ }
        $workspaces = [];
        foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
            if (!is_array($w)) continue;
            $sid = (string)($w['id'] ?? '');
            if ($sid === '' || (string)($w['agentId'] ?? '') === '') continue;
            $running = false;
            try { $running = ProcessManager::isRunning($sid); } catch (\Throwable $e) {}
            if (!$running) continue;
            $workspaces[] = ['id' => $sid, 'name' => (string)($w['name'] ?? $sid), 'agentId' => (string)$w['agentId']];
        }
        return [
            'status' => 'ok',
            'kinds' => AutoContinueRules::KIND_LABELS,
            'builtins' => AutoContinueRules::builtins(),
            'patterns' => $loaded['patterns'],
            'errors' => $loaded['errors'],
            'agents' => $agents,
            'workspaces' => $workspaces,
        ];
    }

    private static function input(): array
    {
        return [
            'id' => self::req('id'),
            'name' => self::req('name'),
            'agent' => self::req('agent'),
            'kind' => self::req('kind'),
            're' => self::req('re'),
            'sample' => self::req('sample'),
            'enabled' => self::req('enabled') === '' ? '1' : self::req('enabled'),
        ];
    }

    private static function save(): array
    {
        $r = AutoContinueRules::save(self::input(), 'ui');
        if (isset($r['error'])) return ['status' => 'error', 'message' => (string)$r['error'], 'errors' => $r['errors'] ?? []];
        return ['status' => 'ok', 'pattern' => $r['pattern']];
    }

    private static function setEnabled(): array
    {
        $r = AutoContinueRules::setEnabled(self::req('id'), filter_var(self::req('enabled'), FILTER_VALIDATE_BOOLEAN));
        return isset($r['error']) ? ['status' => 'error', 'message' => (string)$r['error']] : ['status' => 'ok', 'pattern' => $r['pattern']];
    }

    private static function delete(): array
    {
        $r = AutoContinueRules::delete(self::req('id'));
        return isset($r['error']) ? ['status' => 'error', 'message' => (string)$r['error']] : ['status' => 'ok', 'deleted' => $r['deleted']];
    }

    /**
     * Run an expression on a workspace's current screen. The capture is the
     * same one the MCP read tool uses (masked, logged with caller "Manager UI").
     */
    private static function test(): array
    {
        $kind = self::req('kind');
        if (!in_array($kind, AutoContinueRules::KINDS, true)) return ['status' => 'error', 'message' => 'Choose a kind first.'];
        $re = AutoContinueRules::normaliseRegex(self::req('re'));
        $err = $re === '' ? 'Enter a regular expression.' : AutoContinueRules::regexError($re, $kind);
        if ($err !== null) return ['status' => 'error', 'message' => 'The expression is refused: ' . $err . '.'];
        $screen = WorkspaceScreenService::read(self::req('workspaceId'), self::req('lines') !== '' ? self::req('lines') : 60, 'Manager UI (pattern test)');
        if (isset($screen['error'])) return ['status' => 'error', 'message' => (string)$screen['error']];
        $m = AutoContinueRules::matchLines($re, $kind, (string)$screen['text']);
        return [
            'status' => 'ok',
            'workspaceId' => $screen['workspaceId'],
            'agentId' => $screen['agentId'],
            'agentVersion' => $screen['agentVersion'],
            'model' => $screen['model'],
            'provider' => $screen['provider'],
            'lines' => $m['lines'],
            'matched' => $m['matched'],
            'retryText' => $m['retryText'],
            'retryAt' => $m['retry'],
        ];
    }

    /** POST id, optional title/sample edits and workspaceId (for version/model). */
    private static function report(): array
    {
        $p = AutoContinueRules::find(self::req('id'));
        if ($p === null) return ['status' => 'error', 'message' => 'That pattern no longer exists.'];
        $edit = [];
        if (isset($_REQUEST['title'])) $edit['title'] = self::req('title');
        if (isset($_REQUEST['sample'])) $edit['sample'] = self::req('sample');
        foreach (['agentVersion', 'model', 'provider'] as $k) if (self::req($k) !== '') $edit[$k] = self::req($k);
        $r = AutoContinueRules::reportFor($p, self::req('workspaceId'), $edit);
        return ['status' => 'ok', 'url' => $r['url'], 'title' => $r['title'], 'fields' => $r['fields'], 'trimmed' => $r['trimmed'], 'length' => strlen($r['url'])];
    }
}
