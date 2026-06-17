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
            <!-- R-07 (#1370): server-side filter row — wired to the extended get_log
                 (ctx/trace/level/tail params) + get_log_contexts for the dropdown. -->
            <div id="log-filter-row" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:4px 8px; border-bottom:1px solid rgba(128,128,128,0.25); font-size:11px;">
                <label style="display:flex; align-items:center; gap:4px;">Context
                    <select id="log-filter-ctx" onchange="refreshLog(true)" style="min-width:120px;">
                        <option value="">All</option>
                    </select>
                </label>
                <label style="display:flex; align-items:center; gap:4px;">Level
                    <select id="log-filter-level" onchange="refreshLog(true)">
                        <option value="">All</option>
                        <option value="0">ERR! only</option>
                        <option value="1">WARN+</option>
                        <option value="2">INFO+</option>
                        <option value="3">DBUG+</option>
                    </select>
                </label>
                <label style="display:flex; align-items:center; gap:4px;">Trace
                    <input type="text" id="log-filter-trace" placeholder="t:id" maxlength="16" size="10"
                           onkeyup="if(event.key==='Enter')refreshLog(true)" onchange="refreshLog(true)">
                </label>
                <label style="display:flex; align-items:center; gap:4px;">Tail
                    <select id="log-filter-tail" onchange="refreshLog(true)">
                        <option value="100">100</option>
                        <option value="500" selected>500</option>
                        <option value="1000">1000</option>
                        <option value="2000">2000</option>
                    </select>
                </label>
                <button type="button" class="log-action-btn" onclick="resetLogFilters()" title="Reset filters"><i class="fa fa-times"></i> Reset</button>
            </div>
            <!-- R-08 (#1371): support/share row — redacted bundle + summary share UX.
                 Everything is server-side redacted; nothing is ever auto-posted. -->
            <div id="diag-support-row" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:4px 8px; border-bottom:1px solid rgba(128,128,128,0.25); font-size:11px;">
                <button type="button" class="log-action-btn" onclick="diagDownloadBundle()" title="Build a redacted support bundle zip and download it"><i class="fa fa-download"></i> Download support bundle</button>
                <button type="button" class="log-action-btn" onclick="diagCopyForumPost()" title="Copy a redacted BBCode summary for the Unraid forum"><i class="fa fa-comments"></i> Copy forum post</button>
                <button type="button" class="log-action-btn" onclick="diagCreateGithubIssue()" title="Open a prefilled GitHub issue (nothing is posted until you submit it)"><i class="fa fa-github"></i> Create GitHub issue</button>
                <button type="button" class="log-action-btn" onclick="diagCheckKnownIssues()" title="Fetch the known-issues list and match it against recent logs (explicit action — never automatic)"><i class="fa fa-search"></i> Check known issues</button>
                <label style="display:flex; align-items:center; gap:4px;" title="Replace share names, hostname and LAN IPs in the bundle">
                    <input type="checkbox" id="diag-anon"> Strict anonymize
                </label>
            </div>
            <div id="diag-known-issues" style="display:none; padding:6px 8px; border-bottom:1px solid rgba(128,128,128,0.25); font-size:12px;"></div>
            <div class="log-body" id="log-content" style="height: 600px;">Loading console data...</div>
        </div>
    </div>
</div>
<!-- Bug #710: outer aicli-settings-form is now closed at the end of
     ManagerConfigTab.php. The closing </form> that used to live here was
     wrapping store/storage/debug tabs inside the config form, breaking
     inner forms (secrets, args, tmux). -->
