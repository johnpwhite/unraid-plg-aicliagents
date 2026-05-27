# AICliAgents UI Component Registry: Semantic Index

This directory contains the modular UI fragments of the AICliAgents plugin, decomposed to follow the "Atomic File" (< 150 lines) standard.

## UI Components

| File | Responsibility | Dependencies |
| :--- | :--- | :--- |
| `ManagerStyles.php` | CSS styles for the Manager settings page. | Unraid Dynamix CSS. |
| `ManagerLayout.php` | Shared HTML fragments (Overlays, Tab headers). | `$csrf_token`. |
| `ManagerConfigTab.php` | HTML for the "Configuration" tab. | `$config`, `$csrf_token`, `$users`. |
| `ManagerStoreTab.php` | HTML for the "Agent Store" marketplace. | `$registry`, `$csrf_token`. |
| `ManagerLogScripts.php` | JS logic for log viewer (copy, clear, switch). | jQuery, SweetAlert. |
| `ManagerStorageScripts.php` | JS logic for storage status and maintenance. | jQuery, SweetAlert. |
| `ManagerStoreScripts.php` | JS logic for agent marketplace actions. | jQuery, SweetAlert. |
| `ManagerScripts.php` | Core JS initialization and tab management. | ManagerLogScripts, ManagerStorageScripts, ManagerStoreScripts. |
| `TerminalStyles.php` | CSS for the main terminal and upload overlay. | Unraid 7.2 Flexbox. |
| `UploadOverlay.php` | HTML for the drag-and-drop file upload UI. | |
| `TerminalUploadScripts.php` | JS logic for terminal file uploads (paste, drag-drop). | jQuery, SweetAlert. |
| `TerminalScripts.php` | Core JS logic for terminal (resize, sync). | TerminalUploadScripts. |

## Architectural Rules
1. **Scope Inheritance:** These files are intended for server-side inclusion via `require_once`. They inherit the PHP variable scope of the parent `.page` file.
2. **Context Tags:** Every UI fragment MUST start with a `<module_context>` block for LLM indexing.
3. **Atomaticity:** Files MUST stay under 150 lines.
