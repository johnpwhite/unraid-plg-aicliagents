# AICliAgents Atomic Services: Semantic Index

This directory contains the core business logic of the AICliAgents plugin, decomposed into atomic, stateless, and semantically indexed service classes.

## Service Registry

| Service | Responsibility | Constraints |
| :--- | :--- | :--- |
| `LogService.php` | Handles centralized plugin logging (rotation, trace tags, JSONL option — #1370). | Supports syslog & debug log. Only dep: TraceContext. |
| `TraceContext.php` | Per-request trace-correlation id (R-06 #1370): AJAX → PHP → shell → queue join key. | < 60 lines. Dependency-free, static only. |
| `ConfigService.php` | Persists plugin settings to Flash. | < 150 lines. Handles Nginx proxy generation. |
| `ProcessManager.php` | Manages tmux sessions and agent PIDs. | < 150 lines. Handles clean termination. |
| `TerminalGenerationService.php` | Identifies the current per-session ttyd generation for browser reconnect recovery. | Read-only; PID plus `/proc` start time prevents PID-reuse collisions. |
| `InitService.php` | Scaffolds directories and plugin state. | < 100 lines. Runs on every boot/install. |
| `PermissionService.php` | Enforces UID/GID and chmod safety. | < 100 lines. Focuses on RAM/Flash security. |
| `AgentRegistry.php` | Manages the dynamic agent manifest. | < 150 lines. Handles versioning & discovery. |
| `StorageMountService.php` | Handles ZRAM mounts and image locking/unlocking. | < 150 lines. Focuses strictly on mounting operations. |
| `StorageMetricsService.php` | Provides status and usage statistics for agent and home storage. | < 150 lines. |
| `StorageMigrationService.php` | Handles volume resizing and path migrations. | < 150 lines. |
| `StorageTargetService.php` | S-11 (#1355): read-only ranked enumeration of persistence targets (pools/appdata/UD/array/user/flash via probeTarget) + shared per-kind preflight validation. | READ-ONLY; candidates capped at 12; AICLI_DISKS_INI/AICLI_SHARES_INI/AICLI_MNT_ROOT/AICLI_ITEST_MOUNTED_PATHS test hooks. |
| `ValidationService.php` | Centralized input validation and sanitization. | < 150 lines. Pure validation, no I/O. |
| `RedactionService.php` | Fail-closed layered redaction for the support bundle (R-08 #1371). | Verbatim value match (never regex); never logs values; AICLI_SECRETS_VAULT/DIR test hooks. |
| `DiagnosticsService.php` | Redacted support bundle zip + ≤3KB share summary + known-issues match (R-08 #1371). | Allowlist-only sections; redaction self-test aborts the build; feed fetched only on explicit action. |
| `HealthService.php` | Proactive health checks (9 ok/warn/fail checks, worst-of overall) + 60s tmpfs cache + deduped degradation notify (R-09/R-14 #1372). | Cheap checks only (cached sweeps/ledgers/tick files); pure eval* decision seams; AICLI_RUNTIME_DIR / AICLI_HEALTH_TTL_S / AICLI_NOTIFY_SCRIPT test hooks. |
| `hub/HubStore.php` | Config Hub canonical store (mcp.json + state.json ledger). | Static. Never logs env values. AICLI_HUB_STATE_DIR test hook. |
| `hub/Transpiler.php` | Pure canonical-MCP → Codex TOML / Goose YAML transpilers. | Pure static, deterministic output, no I/O. |
| `hub/VendorProjector.php` | Abstract base for per-vendor MCP projectors (+ JsonMcpProjector). | Managed keys only; AtomicWriteService writes. |
| `hub/HubProjector.php` | Projection orchestrator: projectAll / detectDrift / resolveDrift. | Three-way drift; requires mounted home (no implicit mount). |
| `hub/*Projector.php` | Claude/Gemini/Qwen/Opencode/Copilot/Codex/Goose vendor projectors. | One vendor file each; user keys never touched. |
| `hub/GitHomeService.php` | Git-backed home config, opt-in (H-04 #1365): deny-by-default .gitignore, debounced auto-commit, per-file restore, explicit push. | proc_open array form only; takes the persistHome bake interlock; PAT via GIT_ASKPASS env (never argv/disk/logs). AICLI_HUB_GIT_TEST_ENABLED / AICLI_HUB_GIT_DEBOUNCE_S test hooks. |

## Architectural Rules
1. **Atomaticity**: Files MUST stay under 150 lines.
2. **XML Docblocks**: Every service MUST start with a `<module_context>` block for LLM indexing.
3. **Statelessness**: Prefer static methods or pass dependencies explicitly to avoid side-effects.
4. **UI Separation**: Pure UI logic is now offloaded to `src/includes/ui/`.
