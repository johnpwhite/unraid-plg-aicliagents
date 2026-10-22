<?php
/**
 * <module_context>
 * Description: The help texts of the Configuration tab. One entry for each (i) button.
 *   The key is the help id. The value is the HTML of the help panel.
 * Dependencies: none. Read by aicli_help_panel() in ../SettingsCard.php and by tests.
 * Constraints: Phase 1 of docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md.
 *   These are the texts that were visible under each field before. Phase 2 rewrites them.
 *   Keep one entry for each aicli_help_btn() id in ManagerConfigTab.php, and no other entry.
 * </module_context>
 */
return [
    'enable-tab' => <<<'HTML'
<p>Choose No to hide the AI Cli Agents tab from Unraid's top menu without removing the plugin.</p>
HTML,
    'storage-backend-homes' => <<<'HTML'
<p>How the homes are kept on the persistence drive. Changing it converts only the homes that are not on the new kind yet. Plain folders write directly to the drive; a removable/USB drive needs your acknowledgement, and SquashFS layers reduce wear. New homes use this setting.</p>
HTML,
    'storage-backend-agents' => <<<'HTML'
<p>How the agents are kept on the persistence drive. Changing it converts only the agents that are not on the new kind yet. SquashFS layers use less space on a small boot disk; plain folders are simpler. New agent installs and updates use this setting.</p>
HTML,
    'storage-upper-mode' => <<<'HTML'
<p>Where each home keeps what you change between saves. Changing it moves the data: open workspaces are closed, the changes are saved into a verified layer, and the home is mounted again on the new placement. You are shown what will happen before anything moves.</p>
HTML,
    'keep-previous-version' => <<<'HTML'
<p>Each agent keeps its last version so you can roll back. Turn this off to keep only the version in use and save space on the boot disk.</p>
HTML,
    'log-level' => <<<'HTML'
<p>How much detail the plugin writes to its log; a higher level helps with troubleshooting but produces more output.</p>
HTML,
    'bake-schedule' => <<<'HTML'
<p>How often the plugin saves your home directory automatically when it has unsaved changes. Click Persist to save it now instead of waiting for the schedule.</p>
HTML,
    'workspace-cwd' => <<<'HTML'
<p>The workspace still displays and stores its /mnt/user identity, but new or reopened sessions run directly on the pool when Unraid confirms the share is cache-only. Array, mixed, remote and unresolved paths stay on their real user-share location. Existing sessions are never moved underfoot.</p>
HTML,
    'version-check-schedule' => <<<'HTML'
<p>How often the plugin checks online for new agent versions.</p>
HTML,
    'version-check-months' => <<<'HTML'
<p>How long past version check results are kept before they're cleared out.</p>
HTML,
    'theme' => <<<'HTML'
<p>The color scheme for the terminal windows in this plugin.</p>
HTML,
    'font-size' => <<<'HTML'
<p>The text size, in pixels, for the terminal windows.</p>
HTML,
    'auto-continue-restart' => <<<'HTML'
<p>When on, a workspace continues its work by itself after a restart, upgrade, or reload it detects, never after a plain page load; on by default.</p>
HTML,
    'transient-error-continue' => <<<'HTML'
<p>When a model call fails with a temporary provider error (for example "503 overloaded") and the agent stops at its prompt, the workspace types a short Continue by itself. Verified agents: OpenCode, Kilo Code, Claude Code, Codex CLI, Gemini CLI and Qwen Code. To stop one planned continue, cancel the clock tag on the workspace.</p>
HTML,
    'transient-error-wait' => <<<'HTML'
<p>The wait before the first, second and third automatic continue for the same error. After the last one, the Activity tray shows that the workspace needs you.</p>
HTML,
    'terminal-user' => <<<'HTML'
<p>The Unraid user account that terminal sessions run as. Switching it changes whose home directory, files, and settings you see.</p>
HTML,
    'root-path' => <<<'HTML'
<p>The starting folder for a terminal or file browser when you open one for an agent.</p>
HTML,
    'upload-max' => <<<'HTML'
<p>The largest file the upload overlay accepts. Set it to 0 to allow a file of any size.</p>
HTML,
    'home-storage-path' => <<<'HTML'
<p>Where this user's persistent home directory data lives on disk. Use Change to move it to a different disk or pool.</p>
HTML,
    'agent-storage-path' => <<<'HTML'
<p>Where each agent's installed files and workspace data live on disk. Use Change to move it to a different disk or pool.</p>
HTML,
    'consolidate-max-layers' => <<<'HTML'
<p>How many saved layers a home can build up before the plugin merges them into one; higher means fewer merges but a slower first load. Default 30: merging starts at 28, or sooner when disk space is low, and agents always use one layer.</p>
HTML,
    'consolidate-stage-path' => <<<'HTML'
<p>A folder on a pool or array disk. Storage jobs use it for temporary copies when the boot disk has no room, for example a merge or a conversion to plain folders. Leave it empty and the plugin chooses a folder by itself. It is never RAM and never the boot disk.</p>
HTML,
    'home-cache-location' => <<<'HTML'
<p>Tools such as npm and pip keep downloaded files in cache folders. In a plain-folder home these folders do not use the boot disk: they live in RAM, or in a folder on a pool. A cache is only a copy that a tool downloads again, so a restart that empties it loses nothing. When the pool is not mounted, the caches use RAM.</p>
HTML,
    'home-cache-path' => <<<'HTML'
<p>Used when the cache location is a pool. The folder must be on a pool, for example /mnt/cache/aicli-caches. A user share (/mnt/user) is not accepted. Each home gets its own folder inside it.</p>
HTML,
    'home-cache-ram-mb' => <<<'HTML'
<p>The most RAM the caches of one plain-folder home can use. The RAM is used only while the caches hold data. A tool whose cache is full gets a "no space" error until you clear the caches. The new size applies the next time the home starts. From 256 to 8192 MB.</p>
HTML,
    'history-move-path' => <<<'HTML'
<p>A folder on a pool, for example /mnt/cache/aicli-history. Home Storage, Boot disk space, Move old sessions puts old conversation sessions here to free the boot disk. Moved sessions cannot be resumed, and are not reachable while the array is stopped. Leave it empty until you move sessions.</p>
HTML,
    'responsive-priority' => <<<'HTML'
<p>Gives the Unraid web page and the terminals a higher priority than other programs, and the plugin's own background work a lower one. Typing in a terminal then stays quick when the server is busy. Docker containers, virtual machines and array services are not changed. Agents keep the normal priority. Off puts everything back at once.</p>
HTML,
    'agent-tmp-location' => <<<'HTML'
<p>Where agents keep their temporary files, for example the git clones of an offline session. RAM is the default and is fast, but a large temporary folder uses the memory of the server and can make typing slow. A new session uses the new place. Sessions that already run keep the old one. If the folder is not ready when a session starts, the session uses RAM.</p>
HTML,
    'agent-tmp-path' => <<<'HTML'
<p>Used when the temporary folder is "A folder on a pool". Give a full path under a mounted pool or disk, for example /mnt/cache/agent-tmp. A path under /mnt/user or /mnt/user0 is not accepted. Each user gets a folder of their own inside it.</p>
HTML,
    'agent-tmp-warn-mb' => <<<'HTML'
<p>The Boot disk space card on the Storage tab then shows a warning with a button that removes the temporary files of closed sessions. Default 1024 MB.</p>
HTML,
    'device-label' => <<<'HTML'
<p>Names this browser in the event record, for example "phone" or "office laptop." The name stays on this device and reaches the plugin only when you use this browser again.</p>
HTML,
    'relay-gate-sampling' => <<<'HTML'
<p>When you click to deliver a waiting Relay message, this also keeps a scrubbed screen sample on this server to help the plugin recognise the agent's screen; nothing is sent anywhere, and it is off by default.</p>
HTML,
    'relay-notice-style' => <<<'HTML'
<p>Agents that get the Relay skill already know the rules, so a shorter notice saves tokens on every message. An agent without the skill always gets the Full text. Custom text: one line, up to 300 characters, must contain <code>{what}</code> (the plugin's own phrase, for example "request req_1 is waiting on topic T") and <code>{command}</code> (how to read it), and must end with at least 8 plain characters after the last one. The plugin adds <code>[Relay]</code> in front. Sender text is never inserted.</p>
HTML,
    'relay-notice-resend' => <<<'HTML'
<p>After one notice, the plugin types no new notice into a workspace until it reads its inbox; if it has not read it by this time, the plugin types a fresh notice. Allowed range: 1 to 240.</p>
HTML,
    'voice-toggle' => <<<'HTML'
<p>Turns speech on or off for every open tab on every device; to mute one workspace only, use its "..." menu in the terminal drawer.</p>
HTML,
    'tts-url' => <<<'HTML'
<p>Leave empty to use the browser's own voice, or enter the address of an OpenAI-compatible text-to-speech server such as Kokoro-FastAPI.</p>
HTML,
    'tts-voice' => <<<'HTML'
<p>Which of the engine's voices to use; ignored in browser mode. When the engine can list its own voices, choose from this list, or pick "Other…" to type one by hand.</p>
HTML,
    'tts-speed' => <<<'HTML'
<p>How fast the engine speaks, where 1.0 is normal speed. Ignored in browser mode.</p>
HTML,
    'tts-key' => <<<'HTML'
<p>Only needed when the endpoint requires one; the stored key is never shown here.</p>
HTML,
    'voicemail-intro' => <<<'HTML'
<p>Every spoken message is also kept as voice mail, so you can play one you missed from the terminal drawer.</p>
HTML,
    'voicemail-max-per-workspace' => <<<'HTML'
<p>1 to 500. When a workspace has more, the oldest messages you have heard are removed first.</p>
HTML,
    'voicemail-max-age-days' => <<<'HTML'
<p>1 to 90. Older messages are removed, heard or not.</p>
HTML,
    'stt-intro' => <<<'HTML'
<p>Dictate into a workspace from the microphone icon in the terminal drawer. Leave the endpoint empty to use the browser's own speech recognition.</p>
HTML,
    'stt-url' => <<<'HTML'
<p>Leave empty to use the browser's own speech recognition, or enter the address of an OpenAI-compatible transcription server such as Speaches.</p>
HTML,
    'stt-stream-url' => <<<'HTML'
<p>Optional. A streaming engine (ws:// or wss://) that types your words while you speak and corrects them. When it cannot be reached, dictation uses the endpoint above, or the browser. An HTTPS page needs a wss:// address.</p>
HTML,
    'stt-model' => <<<'HTML'
<p>Which model the endpoint should use. Ignored in browser mode.</p>
HTML,
    'stt-language' => <<<'HTML'
<p>The language you will speak, for example "en". Leave empty to let the browser or the engine detect it.</p>
HTML,
    'stt-key' => <<<'HTML'
<p>Only needed when the endpoint requires one; the stored key is never shown here.</p>
HTML,
    'admin-tools' => <<<'HTML'
<p>Lets agents in your workspaces ask the plugin about its state and make routine changes to it. Reads change nothing; changes are recorded in the Activity tray; deleting, upgrading, backing up, restoring and merging only propose, and wait for your approval. Off by default.</p>
HTML,
    'notebook-enabled' => <<<'HTML'
<p>The Notebook drawer beside the terminal, and the tools that let an agent put pages in it. Off hides the drawer and refuses the tools. Nothing is deleted.</p>
HTML,
    'wiki-page' => <<<'HTML'
<p>Adds a Wiki item to the Tasks menu. You read and edit the Notebook of a project there, without a terminal. The menu item shows after you reload the page.</p>
HTML,
    'notebook-auto-open' => <<<'HTML'
<p>When an agent adds a page and asks to show it, open the drawer for you. With No, the button only shows a badge.</p>
HTML,
    'notebook-folder' => <<<'HTML'
<p>The folder, inside each project folder, that holds its notebook. Letters, digits, dot, dash and underscore; up to 40 characters; not starting with a dot; not "assets". Changing it does not move an existing notebook.</p>
HTML,
    'notebook-prod-secrets' => <<<'HTML'
<p>A secret on a connection card goes to the encrypted vault, never to the file. With Refuse, a value that looks like a live production key is turned away.</p>
HTML,
    'notebook-notify-agent' => <<<'HTML'
<p>When you finish a question card or a checklist, mark an item as failed, add a comment, or save a login, type one short line into the workspace so the agent reads it. The line never holds your answer. A busy agent is not interrupted.</p>
HTML,
    'notebook-spoken-notice' => <<<'HTML'
<p>Needs Agent voice to be on. Speaks one short sentence when an agent adds a page that waits for you.</p>
HTML,
    'notebook-quiet-hours' => <<<'HTML'
<p>From and to, as HH:MM-HH:MM in the server's local time, for example 22:00-07:00. The window may cross midnight. No notebook notice is spoken in these hours. Leave it empty for none.</p>
HTML,
    'notebook-journal' => <<<'HTML'
<p>With On, an agent may add short "what I did and why" lines to a page called Agent journal in the working area. Off by default. The page keeps the newest 500 lines; older lines move to the archive and are never deleted.</p>
HTML,
];
