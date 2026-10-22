<?php
/**
 * <module_context>
 * Description: The help texts of the Config Hub tab. One entry for each card (i) button. The key is the
 *   help id. The value is the HTML of the help panel.
 * Dependencies: none. Read by aicli_help_texts() in ../SettingsCard.php and by tests.
 * Constraints: Phase 7 of docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md, table "Config Hub tab".
 *   Plain words (ASD-STE100). A sentence has at most 25 words. The list of agents for each surface
 *   is in the user guide page, not here.
 * </module_context>
 */
return [
    'hub-drift' => <<<'HTML'
<p>An agent's config file was changed outside the hub. The hub never overwrites such a change by itself. For each key choose: Adopt (take the change into the hub), Overwrite (put the hub's value back), or Release (stop managing the key).</p>
HTML,
    'hub-mcp' => <<<'HTML'
<p>One definition of each MCP server (a tool server that agents can call). The hub writes it into each agent's own config file, in that agent's format. Keys you wrote by hand are never touched.</p>
HTML,
    'hub-instr' => <<<'HTML'
<p>One instruction document that the hub copies into each agent's instruction file, inside a marked block. Text outside the block is never touched.</p>
HTML,
    'hub-skills' => <<<'HTML'
<p>Skills and slash commands stored in the hub and copied to the agents that can use them. The hub only touches the copies it made.</p>
HTML,
    'hub-history' => <<<'HTML'
<p>Every change here is saved in a git history. Commit now saves one at once. Restore settings puts the last saved settings back.</p>
HTML,
];
