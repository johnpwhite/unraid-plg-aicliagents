#!/bin/bash
# consolidate_layers.sh -- SHIM (Phase 5).
#
# The consolidation logic now lives in storage_ops.sh (op_consolidate), dispatched
# by storagectl.sh. This shim preserves the historic positional CLI --
#   consolidate_layers.sh <type: agent|home> <id> <persist_path>
# -- for the existing callers (dev harness; the supervisor + PHP now call
# storagectl directly) by translating to `storagectl consolidate`.
#
# Exit code == op_consolidate's (0 ok / 1 fail / 2 deferred), preserving the
# contract. The task-status progress file (/tmp/.../task-status-<id>) and the
# defer-reason marker are still written by op_consolidate exactly as before.
_SD="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"
[ -d "$_SD" ] || _SD="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage"
exec bash "$_SD/storagectl.sh" consolidate --type "${1:-}" --id "${2:-}" --persist "${3:-}"
