<?php
/**
 * <module_context>
 *     <name>ArgsService</name>
 *     <description>Two-tier CLI args storage: agent-wide defaults and per-workspace overrides. Server-side metachar validation.</description>
 *     <dependencies>ConfigService</dependencies>
 *     <constraints>Static methods only. Follows TmuxService path-hashing conventions.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ArgsService {

    // ---------- Paths ----------

    public static function getAgentArgsPath(string $agentId): string {
        return ConfigService::getUserStatePath() . "/args/args_agent_{$agentId}.json";
    }

    public static function getWorkspaceArgsPath(string $path, string $agentId): string {
        $hash = md5($path);
        return ConfigService::getUserStatePath() . "/args/args_ws_{$hash}_{$agentId}.json";
    }

    // ---------- Agent-level ----------

    public static function getAgentArgs(string $agentId): string {
        $file = self::getAgentArgsPath($agentId);
        if (!file_exists($file)) return '';
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? ($data['args'] ?? '') : '';
    }

    public static function saveAgentArgs(string $agentId, string $args): bool {
        $file = self::getAgentArgsPath($agentId);
        if ($args === '') {
            @unlink($file);
            return true;
        }
        return AtomicWriteService::writeJson($file, ['args' => $args]);
    }

    // ---------- Workspace-level ----------

    public static function getWorkspaceArgs(string $path, string $agentId): string {
        $file = self::getWorkspaceArgsPath($path, $agentId);
        if (!file_exists($file)) return '';
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? ($data['args'] ?? '') : '';
    }

    public static function saveWorkspaceArgs(string $path, string $agentId, string $args): bool {
        $file = self::getWorkspaceArgsPath($path, $agentId);
        if ($args === '') {
            @unlink($file);
            return true;
        }
        return AtomicWriteService::writeJson($file, ['args' => $args]);
    }

    // ---------- Effective (workspace wins) ----------

    public static function getEffectiveArgs(string $path, string $agentId): string {
        $ws = self::getWorkspaceArgs($path, $agentId);
        if ($ws !== '') return $ws;
        return self::getAgentArgs($agentId);
    }

    // ---------- Validation ----------

    /**
     * Returns [] on pass, or list of human-readable rejected char names on fail.
     */
    public static function validateArgs(string $args): array {
        $rejected = [];
        $checks = [
            ';'  => ';',
            '|'  => '|',
            '&'  => '&',
            '`'  => 'backtick',
            '$'  => '$',
            "\n" => 'newline',
            "\r" => 'CR',
            "\0" => 'null byte',
        ];
        foreach ($checks as $char => $label) {
            if (str_contains($args, $char)) {
                $rejected[] = $label;
            }
        }
        return $rejected;
    }
}
