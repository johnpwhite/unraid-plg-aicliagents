<?php
/**
 * <module_context>
 * Description: The help texts of the cards. One entry for each card (i) button that
 *   aicli_card_open() prints (key "card-<id>"), and one entry for each Advanced (i) button that
 *   aicli_adv_open() prints (key "adv-<id>"). The value is the HTML of the help panel.
 * Dependencies: none. Read by aicli_help_panel() in ../SettingsCard.php and by tests.
 * Constraints: Phase 3 and Phase 5 of docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md. Plain
 *   words (ASD-STE100): short sentences, the same word for the same thing. The card intros that
 *   stood in the card body before Phase 3 (Agent voice, Auto-continue patterns, SSH keys,
 *   Terminal input safety rules) live here now. Keep one entry for each card help id and each
 *   Advanced help id used in ManagerConfigTab.php, and no other entry.
 * </module_context>
 */
return [
    'card-general' => <<<'HTML'
<p>These settings apply to the whole plugin. They decide if the tab shows in Unraid's top menu, which user the terminal runs as, and where a new workspace starts.</p>
HTML,
    'adv-general' => <<<'HTML'
<p>Inside: the log detail and the version checks. It also holds the switch that lets agents manage the plugin. The last row is the name of this browser.</p>
HTML,
    'card-storage' => <<<'HTML'
<p>Here you choose how homes and agents are stored, and where. A home is the folder where an agent keeps its settings and history.</p>
HTML,
    'adv-storage' => <<<'HTML'
<p>Inside: how often unsaved home changes are saved, where they are kept, when layers merge, and the work folders. It also holds the caches, the temporary files and the path that a workspace runs on. Most people never change these.</p>
HTML,
    'card-terminal' => <<<'HTML'
<p>These settings decide how the terminal looks. They also decide if typing stays fast when the server is busy.</p>
HTML,
    'card-continue' => <<<'HTML'
<p>An agent can stop on a restart or on a temporary model error. These settings decide when the plugin types "continue" for you.</p>
HTML,
    'adv-continue' => <<<'HTML'
<p>Inside: your own auto-continue patterns and the built-in ones, and the Terminal input safety rules.</p>
<p>Auto-continue patterns are the screen messages that mean "the agent stopped on a temporary error and may continue". Add your own when an agent stops on a message the plugin does not know. Each change saves at once.</p>
<p>Terminal input safety rules: before the plugin types into an agent, it reads the bottom of the screen. A block rule stops the typing when the screen shows a question, a menu or a pager. An idle rule says what the empty prompt of an agent looks like. Rules are regular expressions (text patterns), written as <code>/body/flags</code>. A rule that does not compile is ignored and the built-in rule stays.</p>
HTML,
    'card-relay-notices' => <<<'HTML'
<p>When a message waits for an agent, the plugin types a short notice into its terminal. These settings decide the words of the notice and when it is sent again.</p>
HTML,
    'adv-relay-notices' => <<<'HTML'
<p>Inside: the switch that keeps a screen sample each time the plugin delivers a message. The samples help to improve the Terminal input safety rules.</p>
HTML,
    'card-voice' => <<<'HTML'
<p>An agent can speak to get your attention. By default it uses your browser's voice. Set up a voice server for a natural voice.</p>
HTML,
    'adv-voice' => <<<'HTML'
<p>Inside: how many voice mail messages are kept for each workspace, and for how many days.</p>
HTML,
    'card-dictation' => <<<'HTML'
<p>You can dictate into a workspace from the microphone icon in the terminal drawer. Leave the server fields empty to use the speech recognition of your browser. Nothing then leaves your device.</p>
HTML,
    'card-notebook' => <<<'HTML'
<p>The Notebook is the drawer beside the terminal where an agent puts pages for you. These settings decide when it opens and what an agent may tell you.</p>
HTML,
    'adv-notebook' => <<<'HTML'
<p>Inside: the folder name, the rule for production keys, the spoken notice, the quiet hours and the agent journal.</p>
HTML,
    'card-ssh' => <<<'HTML'
<p>Add your public key to open a workspace's terminal from your own computer with <code>ssh &lt;user&gt; aicli-agent-&lt;name&gt;</code>. The key can run only that command.</p>
HTML,
];
