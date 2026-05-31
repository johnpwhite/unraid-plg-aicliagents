#!/bin/bash
# commit_stack.sh -- SHIM (Phase 5).
#
# The bake logic now lives in storage_ops.sh (op_bake), dispatched by storagectl.sh.
# This shim preserves the historic positional CLI --
#   commit_stack.sh <type: agent|home> <id> <persist_path>
# -- for the existing callers (installer/cleanup.sh, migrate, dev harness; the
# supervisor + PHP now call storagectl directly) by translating to `storagectl bake`.
#
# Exit code == op_bake's (0 ok / 1 fail / 2 deferred-busy), preserving the contract.
# The defer-reason marker (read by TaskService / StorageMountService) is still
# written by op_bake exactly as before. MKSQUASHFS_ARGS (compression override) is
# inherited through the exec.
_SD="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"
[ -d "$_SD" ] || _SD="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage"
exec bash "$_SD/storagectl.sh" bake --type "${1:-}" --id "${2:-}" --persist "${3:-}"
