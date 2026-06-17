#!/bin/bash
# mount_stack.sh -- SHIM (Phase 5).
#
# The OverlayFS assembly logic now lives in storage_ops.sh (op_mount), dispatched
# by storagectl.sh. This shim preserves the historic positional CLI --
#   mount_stack.sh <type: agent|home> <id> <persist_path> [owner]
# -- for the existing callers (StorageMountService, events, installer,
# migrate-btrfs, dev harness) by translating to storagectl flags.
#
# Bug #1054: the optional 4th OWNER arg is forwarded as --owner so non-root home
# overlays still get their upperdir chowned to the user.
#
# Exit code == storagectl's == op_mount's (0 ok / 1 fail), preserving the contract
# callers check. storagectl additionally prints a one-line JSON status to stdout;
# every current caller inspects only the exit code, so this is invisible to them.
_SD="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"
[ -d "$_SD" ] || _SD="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage"
exec bash "$_SD/storagectl.sh" mount --type "${1:-}" --id "${2:-}" --persist "${3:-}" --owner "${4:-}"
