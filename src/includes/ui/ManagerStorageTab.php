<?php
/**
 * <module_context>
 * Description: HTML layout for the Storage tab in AICliAgents Manager (the tab was called
 *   "Home Storage" until Phase 6 of docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md).
 *   The tab is one list of cards (data-aicli-cards="storage"): one card for each home
 *   (home-<user>, made by renderHomeStats() in ManagerStorageScripts.php), Boot disk space
 *   (boot-space, filled by bootspace/00-card.php) and System memory (system-memory).
 *   All cards share the header of SettingsCard.php, so they collapse and move like the cards
 *   of the Configuration tab. Default order: homes, Boot disk space, System memory.
 * Dependencies: $csrf_token, SettingsCard.php, assets/ui/settings-cards.js.
 * Constraints: Atomic UI fragment (< 100 lines).
 * </module_context>
 */
require_once __DIR__ . '/SettingsCard.php'; // aicli_card_open(), aicli_help_btn(), aicli_help_panel().
?>
<!-- TAB 3: STORAGE -->
<div id="tab-storage" class="aicli-tab-content aicli-layout">
    <div style="width: 100%;">

        <!-- Phase 4a: Boot Integrity Banner (populated by JS on tab open) -->
        <div id="aicli-boot-integrity-banner" style="display:none; margin-bottom:12px;"></div>

        <!-- #131: home consolidation in-progress banner (toggled by the
             get_force_reclaim_state poll). Storage actions are guarded while it shows. -->
        <div id="aicli-consolidate-banner" style="display:none; margin-bottom:12px; background:rgba(37,99,235,0.12); border:1px solid #2563eb; border-radius:6px; padding:10px 16px; font-size:12px; color:#2563eb; align-items:center; gap:8px;">
            <i class="fa fa-spinner fa-spin"></i>
            <span>Storage tidy-up in progress — reclaiming disk space for this home. This can take a few minutes; storage actions are paused and sessions resume automatically when it finishes.</span>
        </div>

        <!-- Agent Storage section removed in v2026.05.13.05 (WP #748 J / Phase B):
             under single-layer-per-agent, the per-agent storage cards were vestigial.
             See docs/specs/STORAGE_DURABILITY_SUPERVISOR.md "Storage-tab redesign under J". -->

        <!-- Heading of the homes. The intro of a home is behind the (i) of its own card. -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:13px; font-weight:700;">Homes <?=aicli_help_btn('storage-homes', 'Homes')?></span>
                <span id="homes-text-summary" style="font-size:11px; opacity:0.6;">...</span>
            </div>
        </div>
        <?=aicli_help_panel('storage-homes')?>
        <div id="home-stats-empty"></div>

        <!-- The cards. renderHomeStats() puts the home cards in front of the two fixed cards
             (default place 100 and up); the fixed cards have the places 200 and 300. -->
        <div id="home-stats-container" class="aicli-storage-cards" data-aicli-cards="storage">

            <!-- BOOT_DISK_SPACE.md R1 (epic #534): the Boot disk space card. The body is filled by the
                 bootspace/ fragments (ManagerBootSpaceScripts.php); the status line is set with it. -->
            <?=aicli_card_open('boot-space', 'fa-hdd-o', 'Boot disk space', 'Not measured yet', 'storage-boot', '', ['pos' => 200, 'domId' => 'boot-space'])?>
                <div id="aicli-boot-space-card" data-testid="boot-space-card"></div>
            <?=aicli_card_close()?>

            <!-- The memory of Unraid (the RAM disk). Closed while it is under 80 % full. -->
            <?=aicli_card_open('system-memory', 'fa-heartbeat', 'System memory', 'Loading…', 'storage-memory', '', ['pos' => 300, 'closed' => true])?>
                <div style="padding:10px; background:rgba(255,255,255,0.02); border-radius:4px;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                        <span>Memory used by Unraid</span>
                        <span id="rootfs-text">...</span>
                    </div>
                    <div class="stat-bar-wrap">
                        <div id="rootfs-bar" class="stat-bar-fill" style="background:#9C27B0;"></div>
                        <div class="stat-bar-text" id="rootfs-percent">0%</div>
                    </div>
                </div>
            <?=aicli_card_close()?>
        </div>

        <!-- HOME_BACKUP.md "2026-09-24 redesign (#287)": backup settings are per
             home now, behind the Backup… button on each home card above
             (aicliOpenHomeBackup in ManagerStorageScripts.php). They save at once
             through set_home_backup_setting — never through the shared settings
             form, which this tab sits outside of (Bug #710). -->
    </div>
</div>
