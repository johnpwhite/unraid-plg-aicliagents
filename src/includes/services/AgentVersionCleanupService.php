<?php
/**
 * <module_context>
 * Description: Lists and removes the old versions of agents (BOOT_DISK_SPACE.md R4).
 *   An old version is a plain-folder version, a layer file or a rollback copy that
 *   the keep rules do not protect. The rules live in the shell file
 *   src/scripts/installer/generation.sh. This class calls the script
 *   src/scripts/installer/agent-versions.sh and turns its lines into arrays. It
 *   has no second copy of the rules.
 * Dependencies: ConfigService, agent-versions.sh.
 * Constraints: A version in use is never removed. It is reported as skipped with
 *   the reason ("in use by workspace X"). Nothing here touches prod01 plugin
 *   state on its own: it only runs for the host it is installed on.
 * </module_context>
 */
namespace AICliAgents\Services;

class AgentVersionCleanupService {
    private const SCRIPT = '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/installer/agent-versions.sh';
    /** The longest a remove may run, in seconds. */
    private const REMOVE_TIMEOUT = 300;
    private const LIST_TIMEOUT = 60;

    /** Test seam: AICLI_AGENT_VERSIONS_SH replaces the script path. */
    private static function script(): string {
        $env = getenv('AICLI_AGENT_VERSIONS_SH');
        return ($env !== false && $env !== '') ? $env : self::SCRIPT;
    }

    /** The agent id of one agent, or null for all agents. Throws on a bad id. */
    private static function target(?string $agentId): string {
        if ($agentId === null || $agentId === '' || $agentId === 'all') return 'all';
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $agentId)) {
            throw new \InvalidArgumentException('invalid_agent');
        }
        return $agentId;
    }

    /** The persistence folder where agents keep their data. */
    private static function persistPath(): string {
        return StoragePathResolver::agentPersistPathFromConfig(ConfigService::getConfig());
    }

    /** True when $agentId is "all", empty, or a valid agent id. Cheap: it reads nothing. */
    public static function isValidTarget(?string $agentId): bool {
        try { self::target($agentId); return true; } catch (\InvalidArgumentException $e) { return false; }
    }

    /** 1 when the setting "Keep the previous version for rollback" is on (the default). */
    public static function keepPrevious(): bool {
        $cfg = ConfigService::getConfig();
        return (string)($cfg['keep_previous_version'] ?? '1') !== '0';
    }

    /**
     * PURE: turn the script's tab-separated lines into the result shape.
     * ITEM <agent> <kind> <bytes> <path>; SKIP <agent> <path> <reason>; LOG <agent> <text>.
     * @return array{items:array,skipped:array,total_bytes:int,log:array}
     */
    public static function parse(string $text): array {
        $items = []; $skipped = []; $log = []; $total = 0;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if ($line === '') continue;
            $f = explode("\t", $line);
            switch ($f[0]) {
                case 'ITEM':
                    if (count($f) < 5 || !in_array($f[2], ['plain', 'layer', 'rollback'], true)) break;
                    $bytes = max(0, (int)$f[3]);
                    $items[] = ['agent' => $f[1], 'kind' => $f[2], 'bytes' => $bytes, 'path' => $f[4]];
                    $total += $bytes;
                    break;
                case 'SKIP':
                    if (count($f) < 4) break;
                    $skipped[] = ['agent' => $f[1], 'path' => $f[2], 'reason' => $f[3]];
                    break;
                case 'LOG':
                    if (count($f) >= 3) $log[] = ['agent' => $f[1], 'text' => $f[2]];
                    break;
            }
        }
        return ['items' => $items, 'skipped' => $skipped, 'total_bytes' => $total, 'log' => $log];
    }

    /**
     * Run the script. Returns its standard output and its exit code (#621: the code
     * was dropped, so a timeout (124) or a missing generation.sh (64) looked like
     * "nothing to remove"). Its standard error goes to the log.
     * @return array{out:string,exit:int}
     */
    private static function run(string $verb, string $target, int $timeout): array {
        $cmd = 'timeout ' . (int)$timeout . ' bash ' . escapeshellarg(self::script())
            . ' ' . escapeshellarg($verb) . ' ' . escapeshellarg($target)
            . ' --persist ' . escapeshellarg(self::persistPath())
            . ' --keep-rollback ' . (self::keepPrevious() ? '1' : '0');
        $out = [];
        $exit = 1;
        $errFile = (string)@tempnam(sys_get_temp_dir(), 'avc-err');
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec($cmd . ' 2>' . escapeshellarg($errFile !== '' ? $errFile : '/dev/null'), $out, $exit);
        if ($errFile !== '') {
            $err = trim((string)@file_get_contents($errFile, false, null, 0, 2000));
            @unlink($errFile);
            if ($exit !== 0) {
                LogService::log("agent-versions.sh $verb $target exited $exit" . ($err !== '' ? ": $err" : ''), LogService::LOG_ERROR, "AgentVersionCleanupService");
            }
        }
        return ['out' => implode("\n", $out), 'exit' => (int)$exit];
    }

    /** One plain sentence for a script that did not finish. */
    private static function failureText(int $exit, int $timeout): string {
        return $exit === 124
            ? "The clean-up did not finish in $timeout seconds."
            : "The clean-up script stopped with an error (code $exit).";
    }

    /**
     * What "Remove old versions" removes now, for one agent or for all agents.
     * Plain folders and layers use the rule "do not keep the previous version".
     * Rollback copies use the setting: the newest stays when it is on.
     * @return array{items:array<int,array{agent:string,kind:string,bytes:int,path:string}>,skipped:array<int,array{agent:string,path:string,reason:string}>,total_bytes:int}
     */
    public static function oldVersions(?string $agentId = null): array {
        // The list runs du on every version. It belongs to the background measurement and to the
        // remove job only; no web request calls it (#552). The time left on the shared deadline
        // caps the script.
        require_once __DIR__ . '/MeasureDeadline.php';
        $target = self::target($agentId);
        $t = MeasureDeadline::remaining(self::LIST_TIMEOUT);
        if ($t < 1) {
            MeasureDeadline::markPartial();
            return ['items' => [], 'skipped' => [], 'total_bytes' => 0];
        }
        $res = self::run('list', $target, $t);
        // A list that timed out or failed is partial: say so, so the card does not claim "nothing to remove".
        if ($res['exit'] !== 0) MeasureDeadline::markPartial();
        $r = self::parse($res['out']);
        return ['items' => $r['items'], 'skipped' => $r['skipped'], 'total_bytes' => $r['total_bytes']];
    }

    /**
     * Remove the old versions now. Each clean-up takes its own lock without
     * waiting and never removes a version in use.
     * @return array{status:string,freed_bytes:int,removed:array,kept:array,skipped:array,log:array}
     */
    public static function removeOldVersions(?string $agentId = null): array {
        $target = self::target($agentId);
        $before = self::oldVersions($agentId);
        $res = self::run('remove', $target, self::REMOVE_TIMEOUT);
        $run = self::parse($res['out']);
        $removed = []; $kept = []; $freed = 0;
        foreach ($before['items'] as $it) {
            if (file_exists($it['path'])) { $kept[] = $it; continue; }
            $removed[] = $it;
            $freed += $it['bytes'];
        }
        $skipped = $before['skipped'];
        foreach ($kept as $it) {
            $skipped[] = ['agent' => $it['agent'], 'path' => $it['path'],
                          'reason' => 'kept: it was busy or a lock was held. Try again later.'];
        }
        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'storage', 'agent_old_versions_removed',
            ['agent' => $target, 'freed_bytes' => $freed, 'removed' => count($removed), 'skipped' => count($skipped)]);
        $result = ['status' => 'ok', 'freed_bytes' => $freed, 'removed' => $removed, 'kept' => $kept,
                   'skipped' => $skipped, 'log' => $run['log']];
        if ($res['exit'] !== 0) {
            // #621: report what did go, but not as a success.
            $result['status'] = 'error';
            $result['message'] = self::failureText($res['exit'], self::REMOVE_TIMEOUT);
        }
        return $result;
    }
}
