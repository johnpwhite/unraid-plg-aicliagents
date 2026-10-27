<?php
/**
 * <module_context>
 * Description: The help texts of the Configuration tab. One entry for each (i) button.
 *   The key is the help id. The value is the HTML of the help panel.
 * Dependencies: none. Read by aicli_help_panel() in ../SettingsCard.php and by tests.
 * Constraints: Phase 2 of docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md, table "Plain-language
 *   rewrite table". Plain words (ASD-STE100): short sentences, the same word for the same thing.
 *   The long form of each text is the entry in notebook/notes/ug-settings-reference.md.
 *   Keep one entry for each aicli_help_btn() id in ManagerConfigTab.php, and no other entry.
 * </module_context>
 */
return [
    'enable-tab' => <<<'HTML'
<p>Shows or hides the tab in Unraid's top menu. The plugin keeps running when the tab is hidden.</p>
HTML,
    'storage-backend-homes' => <<<'HTML'
<p>Compressed layers save space on a small boot disk and protect a USB stick from wear. Plain folders need no saves or tidy-ups. The line shows the real state now. Changing this converts the data. You are asked first.</p>
HTML,
    'storage-backend-agents' => <<<'HTML'
<p>Compressed layers save space on a small boot disk and protect a USB stick from wear. Plain folders need no saves or tidy-ups. The line shows the real state now. Changing this converts the data. You are asked first.</p>
HTML,
    'storage-upper-mode' => <<<'HTML'
<p>Changes to a home wait in memory or on disk until the next save. Memory is fast and causes no disk wear, but a crash loses the unsaved changes. Disk keeps them through a crash or a power cut. Changing this closes open workspaces, saves the changes, and reopens the home. You are asked first.</p>
HTML,
    'keep-previous-version' => <<<'HTML'
<p>After an upgrade, the plugin keeps the last version so you can go back. Turn this off to keep only the version in use and free space on the boot disk.</p>
HTML,
    'log-level' => <<<'HTML'
<p>How much the plugin writes to its log on the Debug Console. "Errors only" is the quietest. "Debug" helps when you report a problem and writes the most.</p>
HTML,
    'bake-schedule' => <<<'HTML'
<p>A home in compressed layers collects changes in memory. This is how often the plugin saves them to disk. "Save now" saves at once. This is not a backup: the backup is on the Storage tab, on the home card.</p>
HTML,
    'workspace-cwd' => <<<'HTML'
<p>A share under /mnt/user can be slow for an agent that reads and writes many files. With "Automatic", a new session runs on the pool's own path when Unraid says the share is on one pool only. The workspace still shows its /mnt/user name. A running session never moves.</p>
HTML,
    'version-check-schedule' => <<<'HTML'
<p>How often the plugin looks online for new versions of the installed agents. "Never" turns the check off. "Check for updates" on the Agent Store still works.</p>
HTML,
    'version-check-months' => <<<'HTML'
<p>How long the plugin keeps old results of the version check. Older results are removed.</p>
HTML,
    'theme' => <<<'HTML'
<p>The colours of the terminal windows. Dark is the default.</p>
HTML,
    'font-size' => <<<'HTML'
<p>The size of the text in the terminal windows, in pixels. 12 to 16 is usual.</p>
HTML,
    'auto-continue-restart' => <<<'HTML'
<p>When the plugin restarts a workspace (after a server restart, an agent upgrade or a reload), the agent is told to continue its work. A plain page reload does not do this. On by default.</p>
HTML,
    'transient-error-continue' => <<<'HTML'
<p>When the AI service answers with a temporary error (for example "overloaded") and the agent stops, the plugin types "Continue" for you. "Verified agents" are the agents the plugin has tested: OpenCode, Kilo Code, Claude Code, Codex CLI, Gemini CLI and Qwen Code. To stop one planned continue, cancel the clock tag on the workspace.</p>
HTML,
    'transient-error-wait' => <<<'HTML'
<p>The minutes to wait before the first, second and third continue for the same error, for example 1,5,15. After the last one, the Activity tray says the workspace needs you.</p>
HTML,
    'terminal-user' => <<<'HTML'
<p>The Unraid user account that agents run as. Each user has its own home, files and settings. Changing it reloads the page.</p>
HTML,
    'root-path' => <<<'HTML'
<p>The folder a new terminal or file browser opens in.</p>
HTML,
    'upload-max' => <<<'HTML'
<p>The largest file you can drop or paste into a workspace. 0 means no limit.</p>
HTML,
    'home-storage-path' => <<<'HTML'
<p>The disk or pool that holds the homes of every user. "Change…" moves them. You are asked first, and the move runs in the background.</p>
HTML,
    'agent-storage-path' => <<<'HTML'
<p>The disk or pool that holds the installed agents. "Change…" moves them. You are asked first.</p>
HTML,
    'consolidate-max-layers' => <<<'HTML'
<p>Each save of a home adds one layer. Many layers make the first open slower. When a home reaches this number, the plugin merges them into one layer. Default 30. Merging starts at 28, or sooner when the disk is nearly full.</p>
HTML,
    'consolidate-stage-path' => <<<'HTML'
<p>A storage job (a merge, a conversion, a move) needs a place for temporary copies when the boot disk is full. Leave this empty and the plugin picks a folder on a pool. It never uses memory or the boot disk.</p>
HTML,
    'home-cache-location' => <<<'HTML'
<p>Tools such as npm and pip keep downloaded files in caches. In a plain-folder home, the caches live in memory or on a pool, never on the boot disk. A cache is only a copy. A tool downloads it again when it is gone.</p>
HTML,
    'home-cache-path' => <<<'HTML'
<p>Used when the caches are on a pool. Give a folder on a pool, not under /mnt/user. Each home gets its own folder inside it. For example: /mnt/cache/aicli-caches.</p>
HTML,
    'home-cache-ram-mb' => <<<'HTML'
<p>The most memory the caches of one home may use. A tool gets a "no space" error when it is full, until you clear the caches on the Storage tab. The new size applies the next time the home starts.</p>
HTML,
    'history-move-path' => <<<'HTML'
<p>"Move old sessions" on the Storage tab moves old agent conversations here to free the boot disk. A moved conversation cannot be resumed, and is not reachable while the array is stopped. Leave this empty until you move sessions. For example: /mnt/cache/aicli-history.</p>
HTML,
    'responsive-priority' => <<<'HTML'
<p>Gives the Unraid web page and the terminals a higher priority than the plugin's own background work. Docker containers, virtual machines and the array are not changed. Off puts everything back.</p>
HTML,
    'agent-tmp-location' => <<<'HTML'
<p>Where an agent keeps its temporary files, for example a downloaded repository. Memory is the default and is fast, but many temporary files use the server's memory and can make typing slow. A new session uses the new place. A running session keeps the old one.</p>
HTML,
    'agent-tmp-path' => <<<'HTML'
<p>Used when the temporary files are on a pool. Give a folder on a pool, not under /mnt/user. Each user gets its own folder inside it. For example: /mnt/cache/agent-tmp.</p>
HTML,
    'agent-tmp-warn-mb' => <<<'HTML'
<p>When the temporary files in memory pass this size, the Storage tab shows a warning and a button that removes the files of closed sessions. Default 1024 MB.</p>
HTML,
    'device-label' => <<<'HTML'
<p>Names this browser in the Activity tray, so you can see which device did what. The name stays in this browser. For example: phone or laptop.</p>
HTML,
    'relay-gate-sampling' => <<<'HTML'
<p>When you deliver a waiting Relay message by hand, the plugin keeps a copy of the agent's screen, with secrets removed, on this server. It helps the plugin learn when an agent is ready. Nothing leaves the server. Off by default.</p>
HTML,
    'relay-notice-style' => <<<'HTML'
<p>The Relay (the plugin's mailbox between agents) types a short line into an agent so it reads its inbox. A shorter line costs fewer tokens. An agent without the Relay skill always gets the full text. "Custom" lets you write the line. It must contain <code>{what}</code> and <code>{command}</code>. Use "Show example" under the list to see the line.</p>
HTML,
    'relay-notice-resend' => <<<'HTML'
<p>After one notice, the plugin waits for the agent to read its inbox. If it has not read it after this time, the plugin types the notice again.</p>
HTML,
    'voice-toggle' => <<<'HTML'
<p>Turns speech on or off for every open tab on every device. To mute one workspace, use its "…" menu in the terminal drawer.</p>
HTML,
    'tts-url' => <<<'HTML'
<p>Leave empty to use the browser's own voice. Or give the address of a text-to-speech server that speaks the OpenAI API, such as Kokoro.</p>
HTML,
    'tts-voice' => <<<'HTML'
<p>Which of the server's voices to use. When the server lists its voices, pick one here, or pick "Other…" to type a name. Not used with the browser's voice.</p>
HTML,
    'tts-speed' => <<<'HTML'
<p>How fast the server speaks. Not used with the browser's voice.</p>
HTML,
    'tts-key' => <<<'HTML'
<p>Only needed when the server asks for one. The stored key is never shown.</p>
HTML,
    'voicemail-intro' => <<<'HTML'
<p>Every spoken message is also kept as voice mail, so you can play one you missed from the terminal drawer. The oldest heard messages are removed first.</p>
HTML,
    'voicemail-max-per-workspace' => <<<'HTML'
<p>1 to 500. When a workspace has more, the oldest messages you have heard are removed first.</p>
HTML,
    'voicemail-max-age-days' => <<<'HTML'
<p>1 to 90. Older messages are removed, heard or not.</p>
HTML,
    'stt-url' => <<<'HTML'
<p>Leave empty to use the browser's own speech recognition. Or give the address of a server that speaks the OpenAI transcription API, such as Speaches.</p>
HTML,
    'stt-stream-url' => <<<'HTML'
<p>A live server types your words while you speak and corrects them. Give a ws:// or wss:// address. An HTTPS page needs wss://. When it cannot be reached, dictation uses the server above, or the browser.</p>
HTML,
    'stt-model' => <<<'HTML'
<p>The speech model the server uses. Not used with the browser. For example: whisper-1.</p>
HTML,
    'stt-language' => <<<'HTML'
<p>The language you speak. Leave it empty and the browser or the server detects it. For example: en.</p>
HTML,
    'stt-key' => <<<'HTML'
<p>Only needed when the server asks for one. The stored key is never shown.</p>
HTML,
    'admin-tools' => <<<'HTML'
<p>Gives agents tools to read the plugin's state and make routine changes. Reads change nothing. Changes appear in the Activity tray. Delete, upgrade, back up, restore and merge only propose, and wait for your approval. Off by default.</p>
HTML,
    'spawn-enabled' => <<<'HTML'
<p>Lets an agent start a sub-agent workspace with one tool call. The sub-agent gets its own folder, task and agent. Each start shows in the Activity tray and names the agent that asked. Off by default. An agent can still start a workspace that already exists when this is off.</p>
HTML,
    'human-display-name' => <<<'HTML'
<p>The name that follows your Unraid login in the Activity tray, the event list and the log. For example, "root (John)". The login always shows, so this name only adds to it. Leave it empty to show the login only.</p>
HTML,
    'spawn-caps' => <<<'HTML'
<p>The most sub-agents that run at the same time: for one workspace, for one agent kind, and on the whole server. A start above a limit is refused. The first two limits are 1 to 10, and the total is 1 to 20.</p>
HTML,
    'spawn-unattended' => <<<'HTML'
<p>Lets a sub-agent run without asking you for permission, for example Codex without its sandbox. This works only in a worktree that the plugin made. Off by default, because it lowers the protection of the agent.</p>
HTML,
    'notebook-enabled' => <<<'HTML'
<p>The Notebook is the drawer beside the terminal where an agent puts pages for you. Off hides the drawer and refuses the tools. Nothing is deleted.</p>
HTML,
    'wiki-page' => <<<'HTML'
<p>Adds a Wiki item to Unraid's top menu, where you read and edit a project's Notebook without a terminal. Reload the page to see it.</p>
HTML,
    'notebook-auto-open' => <<<'HTML'
<p>When an agent adds a page and asks you to look, the drawer opens by itself. With No, the Notebook button only shows a badge.</p>
HTML,
    'notebook-folder' => <<<'HTML'
<p>The folder inside each project that holds its Notebook. Letters, digits, dot, dash and underscore, up to 40 characters, not "assets". Changing it does not move an existing Notebook.</p>
HTML,
    'notebook-prod-secrets' => <<<'HTML'
<p>A secret on a connection card goes to the encrypted vault, never to a file. With "Refuse", a value that looks like a live production key is turned away.</p>
HTML,
    'notebook-notify-agent' => <<<'HTML'
<p>When you answer a question, finish a checklist, add a comment or save a login, the plugin types one short line into the workspace. The agent can then read it. The line never holds your answer. A busy agent is not interrupted.</p>
HTML,
    'notebook-spoken-notice' => <<<'HTML'
<p>Needs Agent voice to be on. Speaks one short sentence when an agent adds a page that waits for you.</p>
HTML,
    'notebook-quiet-hours' => <<<'HTML'
<p>No Notebook notice is spoken in these hours, in the server's local time. The window may cross midnight. Leave it empty for none. Write it as HH:MM-HH:MM, for example 22:00-07:00.</p>
HTML,
    'notebook-journal' => <<<'HTML'
<p>With On, an agent may add short "what I did and why" lines to a page called Agent journal. The page keeps the newest 500 lines. Older lines move to the archive. Off by default.</p>
HTML,
];
