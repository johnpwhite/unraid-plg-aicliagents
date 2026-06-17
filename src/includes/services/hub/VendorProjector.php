<?php
/**
 * <module_context>
 *     <name>VendorProjector</name>
 *     <description>Abstract base for the per-vendor MCP config projectors (OP #1362 / H-01).
 *     A projector renders the hub's canonical servers into ONE vendor file inside the
 *     agent home, touches ONLY hub-managed keys (user-owned keys are never rewritten),
 *     and exposes the desired/current/write trio the HubProjector orchestrator drives
 *     for three-way drift detection. All writes go through AtomicWriteService.</description>
 *     <dependencies>AtomicWriteService</dependencies>
 *     <constraints>Stateless. Never logs config values (env values may be secrets).
 *     Vendor paths are HOME-RELATIVE — the orchestrator supplies the mounted home.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

use AICliAgents\Services\AtomicWriteService;

abstract class VendorProjector {

    /** AgentRegistry id this vendor belongs to (e.g. 'claude-code'). */
    abstract public function agentId(): string;

    /** Vendor file path relative to the agent home, no leading slash (e.g. '.claude.json'). */
    abstract public function relPath(): string;

    /** Human label for the UI (e.g. 'Claude Code'). */
    abstract public function label(): string;

    /**
     * Desired managed-key map for the given canonical servers (env placeholders
     * already resolved). JSON vendors: 'mcpServers.<name>' => vendor-shape array.
     * Fenced vendors: single fence key => full fenced block string.
     * An empty $servers map MUST return [] (nothing managed → managed keys removed).
     * @param array<string,array> $servers
     * @return array<string,mixed>
     */
    abstract public function desired(array $servers): array;

    /**
     * Current on-disk value of each requested managed key. Keys absent from the
     * file (or an absent/unparseable file) are omitted from the result.
     * @param string $file absolute vendor file path
     * @param string[] $keys managed keys of interest
     * @return array<string,mixed>
     */
    abstract public function current(string $file, array $keys): array;

    /**
     * Read-modify-write: set $set (key => value) and remove $remove (keys),
     * preserving ALL unmanaged content. Returns false on parse/write failure
     * (never clobbers a file it cannot parse).
     * @param array<string,mixed> $set
     * @param string[] $remove
     */
    abstract public function write(string $file, array $set, array $remove): bool;

    /** Ledger key for this vendor file ('~/' + relPath). */
    public function ledgerKey(): string {
        return '~/' . $this->relPath();
    }

    /** Absolute vendor file path inside $home. */
    public function absPath(string $home): string {
        return rtrim($home, '/') . '/' . $this->relPath();
    }

    /**
     * Stable content hash of a managed value: strings hash raw; arrays/objects
     * hash a canonical (recursively key-sorted) JSON encoding.
     */
    public static function valueHash($v): string {
        if (is_string($v)) return sha1($v);
        return sha1(json_encode(self::canonicalize($v), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Recursively convert stdClass→array and ksort maps for stable hashing. */
    public static function canonicalize($v) {
        if ($v instanceof \stdClass) $v = (array)$v;
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            $out = [];
            foreach ($v as $k => $item) $out[$k] = self::canonicalize($item);
            if (!$isList) ksort($out);
            return $out;
        }
        return $v;
    }

    /** Atomic write + best-effort chown to the session user (root web ctx → user home). */
    protected function atomicWrite(string $file, string $content): bool {
        $existedBefore = is_dir(dirname($file));
        if (!AtomicWriteService::write($file, $content)) return false;
        $this->fixOwnership($file, !$existedBefore);
        return true;
    }

    /**
     * Vendor files live in the session user's home; the web ctx runs as root.
     * Chown the file (and a freshly created parent dir) to the home owner so the
     * agent itself can rewrite its own config. Best-effort, never fatal.
     */
    private function fixOwnership(string $file, bool $chownParent): void {
        $home = dirname($file);
        // Walk up to find the home root's owner: use the directory two levels up
        // is fragile — instead stat the home dir passed via the file path's
        // closest existing ancestor that is not root-owned. Pragmatic: match the
        // owner of the HOME root, which the orchestrator's writes always sit under.
        $uid = null; $gid = null;
        $probe = $home;
        for ($i = 0; $i < 6 && $probe !== '/' && $probe !== ''; $i++) {
            if (basename($probe) === 'home') { // /tmp/unraid-aicliagents/work/<user>/home
                $st = @stat($probe);
                if (is_array($st)) { $uid = (int)$st['uid']; $gid = (int)$st['gid']; }
                break;
            }
            $probe = dirname($probe);
        }
        if ($uid === null || $uid === 0) return; // root session or unknown — nothing to fix
        @chown($file, $uid); @chgrp($file, $gid);
        if ($chownParent) { @chown(dirname($file), $uid); @chgrp(dirname($file), $gid); }
    }
}
