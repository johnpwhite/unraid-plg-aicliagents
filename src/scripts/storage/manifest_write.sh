#!/bin/bash
# manifest_write.sh — Epic #1310 #1320 / WP#1331 (F6): the SINGLE manifest writer.
#
# The spec mandated one bash-side manifest writer because bash can't call PHP at
# shutdown via a long-lived service — every site that records/replaces/removes a
# manifest entry from bash MUST go through these functions, so there is exactly ONE
# php wrapper, ONE stderr policy, ONE `kind` rule, and a failure is SURFACED via the
# return code (callers gate their lifecycle log on it). Before this existed the same
# ~18-line `php -r addLayer` heredoc was copy-pasted across op_bake, event/stopping,
# stop-plugin.sh and the supervisor — already drifting (stderr routing, kind logic,
# whether the lifecycle event fired). LayerManifestService stays the PHP owner; this
# is the thin, single bash→PHP seam onto it.
#
# Each writer is a one-shot `php -d display_errors=0 -r '...'` — SINGLE-quoted so bash
# never eats the `\AICliAgents` namespace backslashes (the publish anti-pattern that
# the old double-quoted consolidate copy tripped). The plugin bootstrap path is passed
# as an argv so AICLI_PLUGIN_ROOT can redirect it in tests. Returns 0 only when the
# PHP mutation ran successfully.
#
# Usage: source this file, then call manifest_record_layer / manifest_remove_layer /
#        manifest_replace_layers / manifest_remove_entity. Idempotent where the owner is
#        (addLayer upserts by filename).

_MW_BOOTSTRAP="${AICLI_PLUGIN_ROOT:-/usr/local/emhttp/plugins/unraid-aicliagents}/src/includes/AICliAgentsManager.php"
# Single stderr policy for every writer; override to capture (e.g. a log) in tests.
_MW_STDERR="${AICLI_MANIFEST_WRITE_LOG:-/dev/null}"
# S-04 (#1352): the SAME lock file PHP LayerManifestService::LOCK_PATH uses, so the
# shared read below actually excludes the PHP writer's LOCK_EX.
_MW_MANIFEST_LOCK="/var/run/aicli-supervisor.manifest.lock"

# manifest_read_locked [manifest_path]
#   S-04 (#1352): print the manifest contents under a SHARED flock against the
#   writer's lock file, so a bash reader can never observe the (vfat) transient
#   torn-read window mid-write. Falls back to a plain read when flock is
#   unavailable or the lock file can't be created (e.g. early boot, /var/run
#   missing) — degraded but never blocking. -w 2 bounds the wait so a wedged
#   writer degrades to the fallback instead of hanging a status poll.
#   Default path honours AICLI_MANIFEST_PATH (the #1254 test redirect).
#   Returns 1 if the manifest is absent.
manifest_read_locked() {
    local mpath="${1:-${AICLI_MANIFEST_PATH:-/boot/config/plugins/unraid-aicliagents/layer_manifest.json}}"
    [ -f "$mpath" ] || return 1
    if command -v flock >/dev/null 2>&1 \
        && { [ -e "$_MW_MANIFEST_LOCK" ] || touch "$_MW_MANIFEST_LOCK" 2>/dev/null; }; then
        if flock -s -w 2 "$_MW_MANIFEST_LOCK" cat "$mpath" 2>/dev/null; then
            return 0
        fi
    fi
    cat "$mpath" 2>/dev/null
}

# manifest_record_layer <type> <id> <persist> <basename> [kind]
#   Append (upsert) one layer to the entity's manifest. `kind` defaults to the
#   basename-derived consolidated/delta; pass it explicitly for 'recovered'. Returns
#   0 on success, non-zero if php is missing, the file is absent, or addLayer failed.
manifest_record_layer() {
    command -v php >/dev/null 2>&1 || return 1
    php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once $argv[6];
        [$t,$i,$pp,$bn,$kind] = [$argv[1],$argv[2],$argv[3],$argv[4],$argv[5]];
        $f = "$pp/$bn";
        if (!is_file($f)) { fwrite(STDERR, "manifest_record_layer: missing $f\n"); exit(1); }
        if ($kind === "") { $kind = (strpos($bn,"consolidated")!==false ? "consolidated" : "delta"); }
        $ok = \AICliAgents\Services\LayerManifestService::addLayer("$t/$i", [
            "filename"     => $bn,
            "sha256"       => \AICliAgents\Services\LayerManifestService::computeFileSha256($f) ?? "",
            "bytes"        => (int)filesize($f),
            "kind"         => $kind,
            "created_at"   => gmdate("Y-m-d\TH:i:s\Z"),
            "persist_path" => $pp,
        ]);
        exit($ok ? 0 : 1);
    ' "$1" "$2" "$3" "$4" "${5:-}" "$_MW_BOOTSTRAP" 2>>"$_MW_STDERR"
}

# manifest_remove_layer <type> <id> <basename>
#   Remove one superseded layer from the entity manifest. The caller must update
#   the manifest before deleting the file so a crash can leave only recoverable,
#   untracked debris — never a manifest reference to a missing layer.
manifest_remove_layer() {
    command -v php >/dev/null 2>&1 || return 1
    php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once $argv[4];
        $ok = \AICliAgents\Services\LayerManifestService::removeLayer(
            $argv[1] . "/" . $argv[2],
            $argv[3]
        );
        exit($ok ? 0 : 1);
    ' "$1" "$2" "$3" "$_MW_BOOTSTRAP" 2>>"$_MW_STDERR"
}

# manifest_replace_layers <type> <id> <persist> <consolidated_basename>
#   Replace the entity's expected_layers with the SINGLE consolidated layer (the
#   op_consolidate "manifest update before old-layer delete" step). Returns 0 on
#   success. (The only bash replaceLayers caller; PHP consumers use the facade.)
manifest_replace_layers() {
    command -v php >/dev/null 2>&1 || return 1
    php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once $argv[5];
        [$t,$i,$pp,$bn] = [$argv[1],$argv[2],$argv[3],$argv[4]];
        $f = "$pp/$bn";
        if (!is_file($f)) { fwrite(STDERR, "manifest_replace_layers: missing $f\n"); exit(1); }
        $ok = \AICliAgents\Services\LayerManifestService::replaceLayers("$t/$i", [[
            "filename"   => $bn,
            "sha256"     => \AICliAgents\Services\LayerManifestService::computeFileSha256($f) ?? "",
            "bytes"      => (int)filesize($f),
            "kind"       => "consolidated",
            "created_at" => gmdate("Y-m-d\TH:i:s\Z"),
        ]], $pp);
        exit($ok ? 0 : 1);
    ' "$1" "$2" "$3" "$4" "$_MW_BOOTSTRAP" 2>>"$_MW_STDERR"
}

# manifest_set_backend <type> <id> <flash|passthrough>
#   S-10 (#1354): record the entity's backend in the manifest (additive per-entity
#   "backend" key). Setting PASSTHROUGH also clears the entity's expected_layers in
#   the SAME locked write (LayerManifestService::setBackend runs under the manifest
#   flock + atomicWrite) — the graduate migration's single authority-flip. Setting
#   FLASH only writes the field (the rollback path re-adds layers via reconcile's
#   untracked-layer recovery). Returns 0 on success.
manifest_set_backend() {
    case "${3:-}" in flash|passthrough) : ;; *) echo "manifest_set_backend: invalid backend '${3:-}'" >&2; return 1 ;; esac
    command -v php >/dev/null 2>&1 || return 1
    php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once $argv[4];
        $ok = \AICliAgents\Services\LayerManifestService::setBackend($argv[1] . "/" . $argv[2], $argv[3]);
        exit($ok ? 0 : 1);
    ' "$1" "$2" "$3" "$_MW_BOOTSTRAP" 2>>"$_MW_STDERR"
}

# manifest_remove_entity <type> <id>
#   Remove the entity's manifest entry entirely (do_wipe). Idempotent (absent → ok).
manifest_remove_entity() {
    command -v php >/dev/null 2>&1 || return 1
    php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"]="/usr/local/emhttp";
        require_once $argv[3];
        $ok = \AICliAgents\Services\LayerManifestService::removeEntity($argv[1] . "/" . $argv[2]);
        exit($ok ? 0 : 1);
    ' "$1" "$2" "$_MW_BOOTSTRAP" 2>>"$_MW_STDERR"
}
