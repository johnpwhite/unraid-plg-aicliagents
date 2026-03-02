# Specification: Persistent Multi-Session Terminal Architecture
**Project:** Gemini CLI Unraid Plugin  
**Version:** 1.0 (March 2026)  
**Status:** Implemented (v2026.03.02.06)

## 1. Overview
This feature provides a robust, multi-tabbed terminal interface for the Gemini CLI AI agent within the Unraid 7.2+ WebGUI. It enables persistent, resumable AI chat sessions mapped to specific filesystem paths, surviving both tab navigation and server reboots.

---

## 2. Core Architecture

### 2.1 Backend Management (PHP/Shell)
- **Execution Environment:** Uses `ttyd` to bridge the terminal to the web and `tmux` to maintain session persistence in the background.
- **Process Isolation:** Each tab is assigned a unique alphanumeric ID (e.g., `sXXXXX`). This ID is used to create:
    - A dedicated Unix Socket: `/var/run/geminiterm-$id.sock`
    - A dedicated Tmux Session: `gemini-cli-$id`
    - A dedicated PID file: `/var/run/unraid-geminicli-$id.pid`
- **Persistence Policy:** 
    - **Ephemeral State:** `/var/run` tracks active processes but is wiped on reboot.
    - **Persistent Data:** The plugin's `home_path` (on the Unraid flash drive) stores the Gemini CLI's `.gemini` folder, containing `projects.json` and `tmp/` (logs/history).
- **Session "Reality" Tracking:** The backend tracks the active `chatSessionId` for each running `ttyd` instance in `/var/run/unraid-geminicli-$id.chatid`.

### 2.2 Frontend (React/TypeScript)
- **Tab Management:** Standardized workspace tabs replace the legacy "Main" tab concept.
- **State Persistence:** `localStorage` caches the open tab paths and their last known session IDs to facilitate instant UI restoration on page load.
- **Dynamic Sizing:** A Javascript-driven `resizeRoot` function dynamically calculates the Unraid `#footer` height to ensure the terminal is perfectly flush with the bottom of the viewport (0px padding).

---

## 3. Key Technical Challenges & Solutions

### 3.1 The "Final Boss" Scrollbar Gap
**Issue:** A persistent 16px-wide gap appeared on the right side of the terminal, even when `overflow: hidden` was applied.
**Root Cause:** The browser and the `xterm.js` engine were reserving space for a scrollbar gutter.
**Solution (Quadruple-Layer Fix):**
1.  **Nuclear CSS:** `scrollbar-gutter: none !important` and `overflow: clip !important` applied to all Unraid containers.
2.  **Iframe Attribute:** `scrolling="no"` on the terminal iframe.
3.  **Cross-Origin Bridge:** An "invisible bridge" (transparent padding) and `onMouseLeave` logic that allows the mouse to travel from the tab to the metadata overlay without the UI closing.
4.  **Surgical Resize:** A JS function that reaches into the `iframe.contentWindow` to dispatch a `resize` event, forcing `xterm.js` to recalculate its character grid and expand to the full container width.

### 3.2 Session Desynchronization
**Issue:** Tabs would lose their chat history or "tug-of-war" over the same session ID after a reboot.
**Solution:**
1.  **Upward Path Traversal:** The backend matches filesystem paths to Gemini projects by traversing upward (e.g., `/mnt/user/python/app` -> `/mnt/user/python`). It validates that a project folder actually exists in `tmp/` before claiming a match.
2.  **Short-ID Extraction:** Extracted the 8-character resumable prefix from the full UUID found in `logs.json` to satisfy the `gemini --resume` command requirements.
3.  **Live Status Polling:** The UI polls `/plugins/unraid-geminicli/GeminiAjax.php?action=get_session_status` every 4 seconds. This returns the "Live Truth" from the `logs.json` file on disk. If the user switches sessions inside the terminal, the UI updates automatically.
4.  **Loop Killer Fallback:** If a specific session ID fails to resume (e.g., deleted files), the shell script attempts `--resume latest`. If that fails, it starts a fresh session after 3 seconds, preventing an infinite reload loop.

---

## 4. User Interface Features
- **Tab Metadata Overlay:** Hovering over a tab shows the full path and the active Gemini `chatSessionId`.
- **Manual Reset:** A **RESET** button in the overlay allows users to "forget" a stuck or incorrect session ID and start fresh for that specific workspace.
- **Symmetrical Design:** Symmetrical horizontal padding ensures the terminal text feels balanced relative to the Unraid sidebar.

## 5. Deployment Workflow
All changes are built in the `ui-build/` directory and deployed via the `unraid-factory` skill. 
- **Versioning:** Adheres to the `YYYY.MM.DD.XX` format.
- **Rebuild Requirement:** Compiled assets (`index.js`/`index.css`) must be manually deployed to `src/assets/ui/` before publishing to ensure the production environment reflects source code changes.
