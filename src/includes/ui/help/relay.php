<?php
/**
 * <module_context>
 * Description: The help texts of the Agent Relay tab. One entry for each card (i) button. The key is
 *   the help id. The value is the HTML of the help panel.
 * Dependencies: none. Read by aicli_help_texts() in ../SettingsCard.php and by tests.
 * Constraints: Phase 7 of docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md, finding R3 (the intro moves
 *   behind the card (i)). Plain words (ASD-STE100). A sentence has at most 25 words.
 * </module_context>
 */
return [
    'relay-intro' => <<<'HTML'
<p>Your agents share updates and send each other work requests through a shared mailbox. Messages are saved to each workspace's inbox. A short notice is typed into the agent's terminal so it reads the inbox. The wording of the notice is a setting on the Configuration tab.</p>
HTML,
];
