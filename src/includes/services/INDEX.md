# AICliAgents Atomic Services: Semantic Index

This directory contains the core business logic of the AICliAgents plugin, decomposed into atomic, stateless, and semantically indexed service classes.

## Service Registry

| Service | Responsibility | Constraints |
| :--- | :--- | :--- |
| `LogService.php` | Handles centralized plugin logging. | < 100 lines. Supports syslog & debug log. |
| `ConfigService.php` | Persists plugin settings to Flash. | < 150 lines. Handles Nginx proxy generation. |
| `ProcessManager.php` | Manages tmux sessions and agent PIDs. | < 150 lines. Handles clean termination. |
| `InitService.php` | Scaffolds directories and plugin state. | < 100 lines. Runs on every boot/install. |
| `PermissionService.php` | Enforces UID/GID and chmod safety. | < 100 lines. Focuses on RAM/Flash security. |
| `AgentRegistry.php` | Manages the dynamic agent manifest. | < 150 lines. Handles versioning & discovery. |
| `StorageMountService.php` | Handles ZRAM mounts and image locking/unlocking. | < 150 lines. Focuses strictly on mounting operations. |
| `StorageMetricsService.php` | Provides status and usage statistics for agent and home storage. | < 150 lines. |
| `StorageMigrationService.php` | Handles volume resizing and path migrations. | < 150 lines. |
| `ValidationService.php` | Centralized input validation and sanitization. | < 150 lines. Pure validation, no I/O. |

## Architectural Rules
1. **Atomaticity**: Files MUST stay under 150 lines.
2. **XML Docblocks**: Every service MUST start with a `<module_context>` block for LLM indexing.
3. **Statelessness**: Prefer static methods or pass dependencies explicitly to avoid side-effects.
4. **UI Separation**: Pure UI logic is now offloaded to `src/includes/ui/`.
