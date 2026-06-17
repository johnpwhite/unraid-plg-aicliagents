<?php
/**
 * <module_context>
 * Description: Layout fragments (overlay, headers) for the AICliAgents Manager page.
 * Dependencies: $csrf_token.
 * Constraints: Atomic UI fragment (< 150 lines).
 * </module_context>
 */
?>
<div id="migration-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.95); z-index:20000; flex-direction:column; align-items:center; justify-content:center; color:#fff; text-align:center; padding:20px;">
    <i class="fa fa-database fa-spin" style="font-size:60px; margin-bottom:20px; color:var(--orange, #ff8c00);"></i>
    <h1 style="margin:0 0 10px 0;">Storage Migration in Progress</h1>
    <p id="migration-status-text" style="opacity:0.8; max-width:500px; line-height:1.5;">Preparing storage conversion...</p>
    
    <div style="margin-top:30px; display:flex; flex-direction:column; align-items:center; gap:15px;">
        <div style="width:300px; height:6px; background:rgba(255,255,255,0.1); border-radius:3px; overflow:hidden;">
            <div id="migration-bar" style="width:0%; height:100%; background:var(--orange, #ff8c00);"></div>
        </div>
        <?php /* WP #903: ring via inset box-shadow (not border) — .aicli-btn-slim
           now uses transparent block borders as its 44px touch-target hit area,
           so a real border would paint at the enlarged hit box, not the pill. */ ?>
        <button type="button" class="aicli-btn-slim" onclick="openMigrationLog()" style="background:var(--title-header-background-color, #444); color:var(--text-color, #fff); box-shadow:inset 0 0 0 1px var(--border-color, #666);"><i class="fa fa-file-text-o"></i> View Migration Log</button>
    </div>
</div>

<div style="display:flex; align-items:center; margin-bottom:12px; border-bottom:1px solid var(--border-color, #333);">
    <?php /* WP #903 a11y: tabindex="0" — at <= 600px the strip becomes a
       horizontal scroller (overflow-x:auto) and axe scrollable-region-focusable
       (serious) requires keyboard users to be able to focus + scroll it. */ ?>
    <div class="aicli-tabs" role="group" aria-label="Manager sections" tabindex="0" style="margin-bottom:0; border-bottom:none;">
        <div class="aicli-tab-btn active" onclick="switchMainTab('config', this)">Configuration</div>
        <div class="aicli-tab-btn" onclick="switchMainTab('store', this)">Agent Store</div>
        <div class="aicli-tab-btn" onclick="switchMainTab('storage', this)">Home Storage</div>
        <div class="aicli-tab-btn" onclick="switchMainTab('hub', this)">Config Hub</div>
        <div class="aicli-tab-btn" id="aicli-tab-btn-debug" onclick="switchMainTab('debug', this)">Debug Console</div>
    </div>
    <!-- R-09 (#1372): plugin health chip — green/amber/red dot, tooltip lists
         non-ok checks, click opens the Debug Console. Populated by
         ManagerHealthScripts.php via aicliAjax('health_status') + 60s refresh. -->
    <span id="aicli-health-chip" onclick="aicliHealthChipClick()" title="Plugin health: checking..."
          style="margin-left:auto; display:inline-flex; align-items:center; gap:6px; cursor:pointer; padding:0 10px; font-size:0.85em; opacity:0.9;">
        <span id="aicli-health-dot" style="width:10px; height:10px; border-radius:50%; background:#888; display:inline-block;"></span>
        <span id="aicli-health-label" style="color:var(--text-color, inherit);">Health</span>
    </span>
</div>

<form onsubmit="saveAICliAgentsManager(this, true); return false;" id="aicli-settings-form">
    <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
    <!-- Configuration tabs (tab-config, tab-store, tab-storage, tab-debug) follow inside here -->
