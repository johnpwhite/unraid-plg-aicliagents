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
        <button type="button" class="aicli-btn-slim" onclick="openMigrationLog()" style="background:#444; color:#fff; border:1px solid #666;"><i class="fa fa-file-text-o"></i> View Migration Log</button>
    </div>
</div>

<div style="display:flex; align-items:center; margin-bottom:12px; border-bottom:1px solid var(--border-color, #333);">
    <div class="aicli-tabs" style="margin-bottom:0; border-bottom:none;">
        <div class="aicli-tab-btn active" onclick="switchMainTab('config', this)">Configuration</div>
        <div class="aicli-tab-btn" onclick="switchMainTab('store', this)">Agent Store</div>
        <div class="aicli-tab-btn" onclick="switchMainTab('storage', this)">Home Storage</div>
        <div class="aicli-tab-btn" onclick="switchMainTab('debug', this)">Debug Console</div>
    </div>
</div>

<form onsubmit="saveAICliAgentsManager(this, true); return false;" id="aicli-settings-form">
    <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
    <!-- Configuration tabs (tab-config, tab-store, tab-storage, tab-debug) follow inside here -->
