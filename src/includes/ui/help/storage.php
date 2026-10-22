<?php
/**
 * <module_context>
 * Description: The help texts of the Storage tab. One entry for each (i) button. The key is the
 *   help id. The value is the HTML of the help panel. The cards of the tab are: one card for each
 *   home (two texts, chosen by the state of the home), Boot disk space and System memory.
 * Dependencies: none. Read by aicli_help_texts() in ../SettingsCard.php, by the Storage tab
 *   (ManagerStorageTab.php, ManagerStorageScripts.php) and by tests.
 * Constraints: Phases 2 and 6 of docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md, table
 *   "Storage tab". Plain words (ASD-STE100). A key that starts with "storage-home-" is also
 *   sent to the browser, because the home cards are made there.
 * </module_context>
 */
return [
    'storage-homes' => <<<'HTML'
<p>A home is the folder where an agent keeps its settings, its logins and its history for one user. Each home has its own card. Open a card to save, merge, repair or delete that home, or to set up its backup.</p>
HTML,
    'storage-home-layers' => <<<'HTML'
<p>This home is saved as compressed layers on the boot disk, so it survives a restart. Save now writes the unsaved changes. Merge layers joins all layers into one. Repair fixes a home that did not mount. Delete removes the data of the home for good.</p>
HTML,
    'storage-home-plain' => <<<'HTML'
<p>This home is in plain folders on the disk. It needs no saves and no tidy-ups. Delete removes the data of the home for good.</p>
HTML,
    'storage-home-backup' => <<<'HTML'
<p>A backup is a second, plain copy of the home in a folder you choose. Damage to the boot disk does not reach it. Set it up, run it now, or restore a copy.</p>
HTML,
    'storage-home-convert' => <<<'HTML'
<p>Plain folders need no saves and no tidy-ups. The old layers are kept for a while, so you can go back.</p>
HTML,
    'storage-boot' => <<<'HTML'
<p>Where the space on the boot disk goes. The sizes are the space really used on disk, so compression is counted. The largest part is first.</p>
HTML,
    'storage-memory' => <<<'HTML'
<p>Unraid runs from memory. Unsaved home changes and agent temporary files can live in memory too. When this is near full, save the changes or move the temporary files to a pool (Configuration &gt; Storage &gt; Advanced).</p>
HTML,
];
