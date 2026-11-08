<?php
/**
 * <module_context>
 * Description: The help texts of the Settings card "Script runs" (docs/specs/AGENT_RUN_ENTRY_POINT.md,
 *   issue #745). One entry for each (i) button that ManagerRunScriptsCard.php prints. The value is the HTML of
 *   the help panel.
 * Dependencies: none. Read by aicli_help_panel() in ../SettingsCard.php (it loads every file in help/).
 * Constraints: Plain words (ASD-STE100). A sentence has at most 25 words. Keep one entry for each help id used in
 *   ManagerRunScriptsCard.php, and no other entry.
 * </module_context>
 */
return [
    'card-script-runs' => <<<'HTML'
<p>Let your own scripts on this server start an agent for you, while you stay in control of what each script may do. A script on this server can start an agent with the command <code>aicli-run</code>. Only scripts on the list below may do it. A read-only run starts at once. A run that may change files waits for your approval in the Activity tray.</p>
<p>A run uses the restricted user aicli-run unless the entry of the script says root. The restricted user sees only the run folder and the folders listed on the Authorised scripts entry under /mnt. The listed folders are read-only, and every other path under /mnt stays hidden. An allowed temporary run folder can be written. Otherwise, the run folder can be written only for a May change run. The plugin keeps the task of a restricted run in its own private folder, outside the folder of the run. A run as root has its task in a folder named .aicli-task inside the folder of the run. The data of a call goes to a private file in the plugin's own state folder.</p>
<p>Create the user aicli-run on the Users page first. The button <strong>Open sign-in workspace</strong> below lets you sign in to an agent for this user once.</p>
HTML,
    'adv-script-runs' => <<<'HTML'
<p>Inside: how many runs may be open, how many may start in one day, and how long a person has to approve a run. Also the switch for the start notice.</p>
HTML,
    'run-enabled' => <<<'HTML'
<p>With On, a script on the list can start an agent. With Off, the command refuses every call and creates nothing. Off by default.</p>
HTML,
    'run-scripts' => <<<'HTML'
<p>Each entry shows its name and what it is for. Open an entry to see and change its limits, or to remove it.</p>
<p>The plugin finds the calling script from the server process list. It compares the file with its stored fingerprint (a sha256 hash). A changed script is refused until you trust it again.</p>
<p>This check guards against a mistake, a stray script and a changed script. A process that runs as root can fake its name, so this is not a guard against a hostile root process.</p>
<p>An unknown script makes one item in the Activity tray and one Unraid notice. Approve stores its fingerprint as a read-only script that may start the agent it asked for.</p>
HTML,
    'run-script-file' => <<<'HTML'
<p>The script that may call. For a User Script, choose it by name. The plugin checks the original file, not the copy that User Scripts runs.</p>
HTML,
    'run-script-name' => <<<'HTML'
<p>A short name for the list. Leave it empty to use the User Scripts name or the file name.</p>
HTML,
    'run-script-description' => <<<'HTML'
<p>What the script is for, in a few words. It shows in the list.</p>
HTML,
    'run-script-fingerprint' => <<<'HTML'
<p>A code made from the content of the file (a sha256 hash). A changed file has another code, and its runs are refused until you trust it again.</p>
HTML,
    'run-script-stale' => <<<'HTML'
<p>User Scripts makes a new copy of a script for each run. An entry that names a copy can never match. Switch it to the original.</p>
HTML,
    'run-script-agents' => <<<'HTML'
<p>Tick the agents this script may start. A call for another agent is refused.</p>
HTML,
    'run-script-folders' => <<<'HTML'
<p>One folder on each line. The script may start an agent in these folders and in the folders inside them. No folder means a temporary folder only: the script may ask for a new empty folder for each run (aicli-run --temp). Use Browse to pick a folder.</p>
HTML,
    'run-script-mode' => <<<'HTML'
<p>A read-only run starts at once, with no approval. A run that may change files waits in the Activity tray until you approve it. For a read-only run, the agent may use only the tools Read, Grep and Glob and the read tools of the plugin. It has no shell and no file editing, and the plugin tools that change things are denied. The file tools stay in the run folder, and the agent ignores the settings files of that folder. It can still report its result.</p>
<p>The user under Run as sets the limit on the disks. The restricted user aicli-run sees only its run folder and the folders listed on the Authorised scripts entry under /mnt. The listed folders are read-only. Every other path under /mnt stays hidden. An allowed temporary run folder can be written. Otherwise, the run folder can be written only for a May change run. The view does not use /mnt/user unless the run folder or a listed folder is under it. A run as root has only the limits of the agent itself, which are not a lock on the disks. A read-only run still counts against all the limits, and the Activity tray shows it as started. A May change run goes without permission questions after you approve it.</p>
HTML,
    'run-script-users' => <<<'HTML'
<p>The user the agent may run as. aicli-run is the restricted user and the default: it gets the private disk view. Choose root only for a script that you trust and that needs it. A script that does not name a user gets aicli-run, and a call is refused when the entry does not allow it.</p>
HTML,
    'run-script-perday' => <<<'HTML'
<p>The most runs this script may start in 24 hours. A call over the limit is refused.</p>
HTML,
    'run-caps' => <<<'HTML'
<p>The most runs open at the same time, and the most runs that start in 24 hours, for all scripts together. A call over a limit is held, and the script may call again.</p>
HTML,
    'run-cooldown' => <<<'HTML'
<p>A script can give a call a key that names one problem. The same key does not start a new run until this many hours pass.</p>
HTML,
    'run-approval' => <<<'HTML'
<p>A run that may change things waits for your approval. With no answer in this time, the run ends and starts nothing.</p>
HTML,
    'run-time-limit' => <<<'HTML'
<p>The plugin closes a run when it reports, or when this time limit passes. It keeps the report and its Notebook card.</p>
HTML,
    'run-notify' => <<<'HTML'
<p>With On, Unraid shows a notice when a run starts and when it reports done. Warnings, for example a refused script, a failed run or a run that waits for approval, always show.</p>
HTML,
    'run-temp-root' => <<<'HTML'
<p>A script can ask for a temporary folder with <code>aicli-run --temp</code>. The plugin makes one folder for each run inside this folder. Leave it empty to use the appdata folder of Unraid on its pool, plus <code>_tmp/aicliagents-runs</code>. The folder cannot be under <code>/mnt/user</code>, and it cannot be a new top-level folder on a pool.</p>
HTML,
    'run-temp-keep' => <<<'HTML'
<p>A temporary run folder stays this many hours after its run closed, so you can look at what the agent made. Then the plugin removes it. It removes only a folder that it made and marked itself.</p>
HTML,
];
