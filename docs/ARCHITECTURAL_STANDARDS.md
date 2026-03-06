# AICliAgents Architectural Standards & Learnings

This document captures foundational engineering patterns and "gotchas" discovered during the transition from a single-agent wrapper to a generalized multi-agent platform.

## 1. Network & API Security

### CSRF Form Data Handling (Unraid `emhttp`)
Unraid's built-in webserver (`emhttp`) aggressively intercepts all incoming `POST` requests before passing them to the plugin's PHP scripts.
*   **The Trap:** If a `POST` request carries a body payload (like a `FormData` object for a file upload), `emhttp` will explicitly parse the body searching for a `csrf_token` key.
*   **The Consequence:** Appending the `csrf_token` solely to the query string URL (e.g., `?action=upload&csrf_token=123`) is **insufficient** for `POST` requests with bodies. If the token is missing from the actual body payload, `emhttp` will instantly reject the request with an `HTTP 500` error and return an HTML error page, bypassing the plugin entirely.
*   **The Standard:** When using `fetch()` or `XMLHttpRequest` to send `POST` requests with `FormData`, you **must** append the CSRF token directly into the form data:
    ```javascript
    const csrf = (window as any).csrf_token || '';
    const formData = new FormData();
    formData.append('csrf_token', csrf); // MANDATORY for emhttp validation
    formData.append('file', myFile);
    
    fetch(`/plugins/my-plugin/Ajax.php?action=upload`, {
        method: 'POST',
        body: formData
    });
    ```

## 1. Filesystem & Runtime Standards

### Flash-to-RAM Payload Model
Unraid plugins must balance persistence with performance and flash drive longevity.
*   **Persistence:** All persistent data (binaries, large configs, agent executables) MUST be stored in `/boot/config/plugins/unraid-aicliagents/`.
*   **Execution:** Binaries MUST be executed from `/usr/local/emhttp/plugins/unraid-aicliagents/bin/` (RAM).
*   **Synchronization:** The PHP backend must implement a "Pre-Start Sync" logic that verifies the binary exists in RAM and copies it from the Flash source if missing (e.g., after a reboot).

### Internal vs. Public Pathing
When rebranding a plugin, third-party tool compatibility must be maintained.
*   **Rule:** Standardize on keeping internal hidden tool paths (e.g., `~/.gemini/`) static if they are hardcoded in the underlying binaries, even if the public plugin identity changes to `AICliAgents`.

## 2. Process & Session Management

### Process-Level Source of Truth
Multi-agent environments cannot rely on PID files or socket existence alone.
*   **Standard:** Use `pgrep` combined with `/proc/[pid]/environ` inspection to verify that a running process matches the requested configuration (specifically checking the `AGENT_ID` environment variable).
*   **Cleanup:** When switching agents on the same session socket, implement an aggressive "Hard Reset" that kills all processes associated with that `AICLI_SESSION_ID` before launching the new agent.

### Transparent Metadata Sync
Distinguish between changes that require user-facing loading states and those that don't.
*   **Structural State:** (Agent, Path, Session ID) -> Requires "Waking" overlay to prevent input during re-initialization.
*   **Metadata State:** (Chat ID, Title) -> Should be updated "transparently" via background polling without interrupting the terminal view.

## 3. UI/UX (React + Unraid)

### Stability via `useRef`
To prevent infinite loops during high-frequency polling:
*   **Standard:** Use a `lastStartedKey` (stored in a React `useRef`) to lock execution. If the current Agent/ChatID/Session combination matches the ref, inhibit the start call regardless of re-renders or state updates.

### LocalStorage Namespacing
*   **Standard:** Use unique, versioned namespaces for `localStorage` (e.g., `aicliagents_sessions`) to avoid collisions with legacy versions.
*   **Migration:** Implement a one-time migration bridge to copy data from old namespaces (e.g., `gemini_sessions`) if detected.

## 4. Maintenance & Deployment

### Installer Validation
*   **Learning:** The `.plg` download list must be manually audited or scripted to match the `src/` directory whenever new assets (like icons) are added.
*   **Cleanup Hardening:** The installer's aggressive cleanup must target both legacy naming patterns (e.g., `gemini-cli-*`) and new patterns to ensure a clean upgrade path.
