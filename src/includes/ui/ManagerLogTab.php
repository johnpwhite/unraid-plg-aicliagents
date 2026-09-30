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
            <!-- 2026-09-29 (DEBUG_CONSOLE_HEADER in docs/specs/NATIVE_BUTTON_STYLE.md):
                 ONE compact row on a desktop — log tabs (segmented), the filters
                 inline, the paused dot, icon buttons for Reset / Copy / Clear, and
                 the four support actions + "Strict anonymize" folded into the
                 "Support" menu button. It was three rows (146 px); the log gets the
                 height. On a phone the row wraps (44 px targets). -->
            <div class="log-header" id="log-toolbar">
                <div class="log-tabs" role="group" aria-label="Log">
                    <div class="log-tab active" data-type="debug" onclick="switchLog('debug', this)" title="The plugin's live activity log, showing what it is doing right now.">Debug</div>
                    <div class="log-tab" data-type="migration" onclick="switchLog('migration', this)" title="The log from the last time your settings were upgraded to a new plugin version.">Migration</div>
                    <div class="log-tab" data-type="install" onclick="switchLog('install', this)" title="The log from when this plugin was installed.">Install</div>
                    <div class="log-tab" data-type="uninstall" onclick="switchLog('uninstall', this)" title="The log from when this plugin was last removed.">Uninstall</div>
                </div>
                <!-- R-07 (#1370): server-side filters — wired to the extended get_log
                     (ctx/trace/level/tail params) + get_log_contexts for the dropdown.
                     switchLog() hides this group for the raw install/uninstall logs. -->
                <div id="log-filter-row" class="log-filters">
                    <label title="Show only log lines from one internal part of the plugin. Leave on All to see everything.">Context
                        <select id="log-filter-ctx" onchange="refreshLog(true)">
                            <option value="">All</option>
                        </select>
                    </label>
                    <label title="Only show lines at or above this severity: ERR! is errors only, WARN+ adds warnings, INFO+ adds routine activity, DBUG+ shows everything including fine-grained debug detail.">Level
                        <select id="log-filter-level" onchange="refreshLog(true)">
                            <option value="">All</option>
                            <option value="0">ERR! only</option>
                            <option value="1">WARN+</option>
                            <option value="2">INFO+</option>
                            <option value="3">DBUG+</option>
                        </select>
                    </label>
                    <label title="Narrow the log to one request's trace ID, so you can follow a single operation from start to finish.">Trace
                        <input type="text" id="log-filter-trace" placeholder="t:id" maxlength="16" size="10"
                               onkeyup="if(event.key==='Enter')refreshLog(true)" onchange="refreshLog(true)">
                    </label>
                    <label title="How many of the most recent log lines to load.">Tail
                        <select id="log-filter-tail" onchange="refreshLog(true)">
                            <option value="100">100</option>
                            <option value="500" selected>500</option>
                            <option value="1000">1000</option>
                            <option value="2000">2000</option>
                        </select>
                    </label>
                    <button type="button" class="log-action-btn log-icon-btn" onclick="resetLogFilters()" title="Reset filters" aria-label="Reset filters"><i class="fa fa-undo" aria-hidden="true"></i></button>
                </div>
                <div class="log-actions">
                    <span id="autoscroll-status" class="log-paused" role="status" style="opacity:0; visibility:hidden;" title="New lines are on hold while the mouse is over the log."><span class="log-paused-dot" aria-hidden="true"></span><span class="log-paused-text">Paused</span></span>
                    <button type="button" class="log-action-btn log-icon-btn" onclick="copyLogToClipboard()" title="Copy to Clipboard" aria-label="Copy log to clipboard"><i class="fa fa-copy" aria-hidden="true"></i></button>
                    <button type="button" class="log-action-btn log-icon-btn danger" onclick="clearSelectedLog()" title="Clear Log" aria-label="Clear log"><i class="fa fa-eraser" aria-hidden="true"></i></button>
                    <div class="log-menu-wrap">
                        <button type="button" id="log-support-btn" class="log-action-btn log-menu-btn" onclick="toggleLogSupportMenu()"
                                aria-haspopup="menu" aria-expanded="false" aria-controls="diag-support-row"
                                title="Support tools: a redacted support bundle, a forum post, a GitHub issue and the known-issues check"><i class="fa fa-life-ring" aria-hidden="true"></i> <span class="log-btn-text">Support</span> <i class="fa fa-caret-down" aria-hidden="true"></i></button>
                        <!-- R-08 (#1371): support/share actions — redacted bundle + summary share UX.
                             Everything is server-side redacted; nothing is ever auto-posted. -->
                        <div id="diag-support-row" class="log-menu" role="menu" aria-label="Support" hidden>
                            <button type="button" role="menuitem" class="log-action-btn log-menu-item" onclick="diagDownloadBundle()" title="Build a redacted support bundle zip and download it"><i class="fa fa-download" aria-hidden="true"></i> Download support bundle</button>
                            <button type="button" role="menuitem" class="log-action-btn log-menu-item" onclick="diagCopyForumPost()" title="Copy a redacted BBCode summary for the Unraid forum"><i class="fa fa-comments" aria-hidden="true"></i> Copy forum post</button>
                            <button type="button" role="menuitem" class="log-action-btn log-menu-item" onclick="diagCreateGithubIssue()" title="Open a prefilled GitHub issue (nothing is posted until you submit it)"><i class="fa fa-github" aria-hidden="true"></i> Create GitHub issue</button>
                            <button type="button" role="menuitem" class="log-action-btn log-menu-item" onclick="diagCheckKnownIssues()" title="Fetch the known-issues list and match it against recent logs (explicit action — never automatic)"><i class="fa fa-search" aria-hidden="true"></i> Check known issues</button>
                            <div class="log-menu-sep" role="separator"></div>
                            <label class="log-menu-check" title="Replace share names, hostname and LAN IPs in the bundle">
                                <input type="checkbox" id="diag-anon" role="menuitemcheckbox"> Strict anonymize
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div id="diag-known-issues" style="display:none; padding:6px 8px; border-bottom:1px solid rgba(128,128,128,0.25); font-size:12px;"></div>
            <div class="log-body" id="log-content" style="height: 710px;" title="New log lines are added automatically. Move your mouse over this area to pause that so you can read; move it away to resume.">Loading console data...</div>
        </div>
    </div>
</div>
<!-- Bug #710: outer aicli-settings-form is now closed at the end of
     ManagerConfigTab.php. The closing </form> that used to live here was
     wrapping store/storage/debug tabs inside the config form, breaking
     inner forms (secrets, args, tmux). -->
