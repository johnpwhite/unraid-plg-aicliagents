<?php
/**
 * <module_context>
 * Description: HTML layout for the Log Viewer (Debug Console) tab in AICliAgents Manager.
 * Dependencies: $csrf_token.
 * Constraints: Atomic UI fragment (< 100 lines).
 * </module_context>
 */
?>
<!-- TAB 4: DEBUG CONSOLE -->
<div id="tab-debug" class="aicli-tab-content aicli-layout">
    <div style="width: 100%;">
        <div class="log-terminal">
            <div class="log-header">
                <div style="display:flex;">
                    <div class="log-tab active" data-type="debug" onclick="switchLog('debug', this)">Debug</div>
                    <div class="log-tab" data-type="migration" onclick="switchLog('migration', this)">Migration</div>
                    <div class="log-tab" data-type="install" onclick="switchLog('install', this)">Install</div>
                    <div class="log-tab" data-type="uninstall" onclick="switchLog('uninstall', this)">Uninstall</div>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <span id="autoscroll-status" style="font-size:8px; color:#0f0; opacity:0; visibility:hidden; white-space:nowrap;"><i class="fa fa-mouse-pointer"></i> PAUSED</span>
                    <button type="button" class="log-action-btn" onclick="copyLogToClipboard()" title="Copy to Clipboard"><i class="fa fa-copy"></i> Copy</button>
                    <button type="button" class="log-action-btn danger" onclick="clearSelectedLog()" title="Clear Log"><i class="fa fa-eraser"></i> Clear</button>
                </div>
            </div>
            <div class="log-body" id="log-content" style="height: 600px;">Loading console data...</div>
        </div>
    </div>
</div>
<!-- Bug #710: outer aicli-settings-form is now closed at the end of
     ManagerConfigTab.php. The closing </form> that used to live here was
     wrapping store/storage/debug tabs inside the config form, breaking
     inner forms (secrets, args, tmux). -->
