<?php
/**
 * <module_context>
 * Description: The help text of the Health chip (i) on the tab strip of the Manager page. The key is
 *   the help id. The value is the HTML of the help panel. The list of checks that are not OK is
 *   added by ManagerHealthScripts.php, inside the element #aicli-health-detail.
 * Dependencies: none. Read by aicli_help_texts() in ../SettingsCard.php and by tests.
 * Constraints: Phase 8 of docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md, finding D2. Plain words
 *   (ASD-STE100). A sentence has at most 25 words.
 * </module_context>
 */
return [
    'health-chip' => <<<'HTML'
<p>The Health chip shows if the plugin checks pass. Green means all checks are OK. Amber means a check needs your attention. Red means a check failed.</p>
<div id="aicli-health-detail" data-testid="health-detail" aria-live="polite">Checking the plugin health.</div>
<p><button type="button" class="aicli-btn-slim" id="aicli-health-open-debug" data-testid="health-open-debug" onclick="aicliHealthChipClick()">Open the Debug Console</button></p>
HTML,
];
