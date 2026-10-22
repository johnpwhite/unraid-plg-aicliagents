<?php
/**
 * <module_context>
 * Description: The help texts of the Agent Store tab. One entry for each (i) button that has a fixed
 *   text. The text of an agent card (description, release notes, install method, package) is data, so
 *   ManagerStoreTab.php builds it for each agent. The key is the help id. The value is the HTML.
 * Dependencies: none. Read by aicli_help_texts() in ../SettingsCard.php and by tests.
 * Constraints: Phase 7 of docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md, table "Agent Store tab".
 *   Plain words (ASD-STE100). A sentence has at most 25 words.
 * </module_context>
 */
return [
    'store-chips' => <<<'HTML'
<p>Version: which release to install. Variables: the environment values the agent starts with. Storage: how much space the agent uses. Terminal: the terminal settings for this agent. Start options: the command-line options the agent starts with.</p>
HTML,
];
