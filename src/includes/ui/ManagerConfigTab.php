<?php
/**
 * <module_context>
 * Description: HTML layout for the Configuration tab in AICliAgents Manager.
 * Dependencies: $config, $csrf_token, $users.
 * Constraints: Atomic UI fragment (< 150 lines).
 * </module_context>
 */
$autoSave = 'onchange="autoSaveConfig()"';
?>
<!-- TAB 1: CONFIGURATION -->
<div id="tab-config" class="aicli-tab-content active aicli-layout">
    <div class="aicli-config-grid">

            <div class="aicli-card">
                <div class="aicli-card-header"><i class="fa fa-globe text-orange-500"></i> Global Configuration</div>
                <div class="aicli-card-body">
                    <dl>
                        <dt>Enable Main Tab</dt>
                        <dd>
                            <select name="enable_tab" aria-label="Enable Main Tab" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['enable_tab'], "1", _('Yes'))?>
                                <?=mk_option($config['enable_tab'], "0", _('No'))?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Choose No to hide the AI Cli Agents tab from Unraid's top menu without removing the plugin.</div>
                        </dd>

                        <dt>Persistence engine</dt>
                        <dd>
                            <select name="storage_backend_mode" aria-label="Persistence engine" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['storage_backend_mode'] ?? 'layering', 'layering', _('SquashFS layers + zram (recommended)'))?>
                                <?=mk_option($config['storage_backend_mode'] ?? 'layering', 'passthrough', _('Plain directories (advanced)'))?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">One setting applies to every installed agent and home. Changing it migrates all existing storage. Plain directories write directly to the selected path; removable/USB targets require an acknowledgement and SquashFS + zram is recommended to reduce wear.</div>
                        </dd>

                        <dt>Logging Level</dt>
                        <dd>
                            <select name="log_level" aria-label="Logging Level" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['log_level'], "0", _('Errors Only'))?>
                                <?=mk_option($config['log_level'], "1", _('Warnings'))?>
                                <?=mk_option($config['log_level'], "2", _('Normal (Info)'))?>
                                <?=mk_option($config['log_level'], "3", _('Debug (Verbose)'))?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">How much detail the plugin writes to its log; a higher level helps with troubleshooting but produces more output.</div>
                        </dd>

                        <dt>Backup Interval</dt>
                        <dd>
                            <div class="input-row">
                                <?php
                                // #153: this control drives the REAL automatic-save cadence the
                                // storage supervisor reads (bake_schedule_minutes). The old
                                // sync_interval_hours/mins selects were wired to nothing.
                                $bakeEvery = (string)($config['bake_schedule_minutes'] ?? '120');
                                $bakeChoices = ['15' => 'Every 15 minutes', '30' => 'Every 30 minutes', '60' => 'Every hour', '120' => 'Every 2 hours', '240' => 'Every 4 hours', '480' => 'Every 8 hours', '720' => 'Every 12 hours', '1440' => 'Once a day'];
                                if (!isset($bakeChoices[$bakeEvery]) && ctype_digit($bakeEvery)) $bakeChoices[$bakeEvery] = "Every $bakeEvery minutes";
                                ?>
                                <select name="bake_schedule_minutes" aria-label="Automatic backup interval" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                    <?php foreach ($bakeChoices as $mins => $label): echo mk_option($bakeEvery, (string)$mins, $label); endforeach; ?>
                                </select>
                                <button type="button" class="aicli-btn-slim" onclick="persistEntity('home', activeTerminalUser)"><i class="fa fa-save"></i> Persist</button>
                            </div>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">How often the plugin saves your home directory automatically when it has unsaved changes. Click Persist to save it now instead of waiting for the schedule.</div>
                        </dd>

                        <dt>Workspace working directory</dt>
                        <dd>
                            <?php $cwdMode = (string)($config['workspace_cwd'] ?? 'auto'); if ($cwdMode === 'share') $cwdMode = 'auto'; ?>
                            <select name="workspace_cwd" aria-label="Workspace working directory" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($cwdMode, 'auto', _('Automatic safe path (direct pool for cache-only shares)'))?>
                                <?=mk_option($cwdMode, 'pool', _('Pool path when the share is cache-only (legacy explicit mode)'))?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">The workspace still displays and stores its /mnt/user identity, but new or reopened sessions run directly on the pool when Unraid confirms the share is cache-only. Array, mixed, remote and unresolved paths stay on their real user-share location. Existing sessions are never moved underfoot.</div>
                        </dd>

                        <dt>Version Check Schedule</dt>
                        <dd>
                            <select name="version_check_schedule" aria-label="Version check schedule" <?=$autoSave?>>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 */6 * * *', 'Every 6 hours')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 6 * * *', 'Daily at 6am')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 6 * * 1', 'Weekly (Monday 6am)')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '', 'Disabled')?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">How often the plugin checks online for new agent versions.</div>
                        </dd>

                        <dt>Version History (months)</dt>
                        <dd>
                            <select name="version_check_months" aria-label="Version history retention in months" <?=$autoSave?>>
                                <?=mk_option($config['version_check_months']??'3', '1', '1 month')?>
                                <?=mk_option($config['version_check_months']??'3', '3', '3 months')?>
                                <?=mk_option($config['version_check_months']??'3', '6', '6 months')?>
                                <?=mk_option($config['version_check_months']??'3', '12', '12 months')?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">How long past version check results are kept before they're cleared out.</div>
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="aicli-card">
                <div class="aicli-card-header"><i class="fa fa-user-circle text-orange-500"></i> Session & Environment</div>
                <div class="aicli-card-body">
                    <dl>
                        <dt>Terminal Theme</dt>
                        <dd>
                            <select name="theme" aria-label="Terminal theme" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                <?=mk_option($config['theme']??'dark', "dark", _('Dark'))?>
                                <?=mk_option($config['theme']??'dark', "light", _('Light'))?>
                                <?=mk_option($config['theme']??'dark', "solarized", _('Solarized'))?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">The color scheme for the terminal windows in this plugin.</div>
                        </dd>

                        <dt>Font Size</dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="font_size" aria-label="Terminal font size in pixels" value="<?=$config['font_size'] ?? 12?>" min="8" max="32" style="width: 70px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">px</span>
                            </div>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">The text size, in pixels, for the terminal windows.</div>
                        </dd>

                        <!-- CONTINUE_ON_RESTART.md (2026-09-09): relocated from the Agent Relay
                             tab (Relay messaging never owned this). Saves through the same
                             autoSaveConfig()/`save` path as every other select on this tab. -->
                        <dt>Continue after a restart</dt>
                        <dd>
                            <select name="auto_continue_on_restart" aria-label="Continue after a restart" style="width: 100%;" <?=$autoSave?>>
                                <?php $autoContinue = \AICliAgents\Services\ConfigService::autoContinueOnRestart() ? '1' : '0'; ?>
                                <?=mk_option($autoContinue, "1", _('On (default)'))?>
                                <?=mk_option($autoContinue, "0", _('Off'))?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">When on, a workspace continues its work by itself after a restart, upgrade, or reload it detects, never after a plain page load; on by default.</div>
                        </dd>

                        <!-- TRANSIENT_ERROR_AUTO_CONTINUE.md (#312). Same autoSaveConfig()/`save`
                             path as the select above. -->
                        <dt>Continue after a model error</dt>
                        <dd>
                            <select name="transient_error_continue" aria-label="Continue after a temporary model error" style="width: 100%;" <?=$autoSave?>>
                                <?php $transientMode = \AICliAgents\Services\TransientErrorService::settings($config)['mode']; ?>
                                <?=mk_option($transientMode, "recommended", _('Verified agents (default)'))?>
                                <?=mk_option($transientMode, "all", _('All agents'))?>
                                <?=mk_option($transientMode, "off", _('Off'))?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">When a model call fails with a temporary provider error (for example "503 overloaded") and the agent stops at its prompt, the workspace types a short Continue by itself. Verified agents: OpenCode, Kilo Code, Claude Code, Codex CLI, Gemini CLI and Qwen Code. To stop one planned continue, cancel the clock tag on the workspace.</div>
                        </dd>

                        <dt>Model error: wait and limit</dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" name="transient_error_backoff_minutes" aria-label="Minutes to wait before each automatic continue" value="<?=htmlspecialchars((string)($config['transient_error_backoff_minutes'] ?? '1,5,15'), ENT_QUOTES, 'UTF-8')?>" style="width: 110px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">min, at most</span>
                                <input type="number" name="transient_error_max_continues" aria-label="Automatic continues per error before it needs you" value="<?=htmlspecialchars((string)($config['transient_error_max_continues'] ?? '3'), ENT_QUOTES, 'UTF-8')?>" min="0" max="10" style="width: 60px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">times</span>
                            </div>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">The wait before the first, second and third automatic continue for the same error. After the last one, the Activity tray shows that the workspace needs you.</div>
                        </dd>

                        <dt>Terminal User</dt>
                        <dd>
                            <div class="input-row">
                                <?php /* Bug #1054 follow-up: stamp the original user value at render time so
                                   saveAICliAgentsManager can detect a user-switch and force a full page reload
                                   afterwards. Without the reload the Store-card Args panel, workspace list, and
                                   any other per-user UI state stay populated from the previous user's home
                                   overlay (since the textareas are PHP-pre-rendered from the old request). */ ?>
                                <input type="hidden" id="aicli-original-user" value="<?=htmlspecialchars($config['user'] ?? 'root', ENT_QUOTES, 'UTF-8')?>">
                                <select name="user" id="user_select" aria-label="Terminal user" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                    <?php // Bug #1053: getUnraidUsers() returns a numeric-indexed LIST of
                                          // usernames, so iterate as a list (the value is $u, the username),
                                          // not as an associative array with $u => $d (which made the option
                                          // value the list INDEX — saved "4" for the 5th user).
                                    foreach ($users as $u): ?>
                                        <?=mk_option($config['user'], $u, $u)?>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="aicli-btn-slim" onclick="window.open('/Users/UserAdd', '_blank')" title="Add User"><i class="fa fa-user-plus"></i></button>
                                <button type="button" class="aicli-btn-slim" onclick="safeReload()" title="Refresh"><i class="fa fa-refresh"></i></button>
                            </div>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">The Unraid user account that terminal sessions run as. Switching it changes whose home directory, files, and settings you see.</div>
                        </dd>

                        <dt>Workspace Root</dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" name="root_path" id="root_path" aria-label="Workspace root path" value="<?=htmlspecialchars($config['root_path'] ?? '/mnt/user', ENT_QUOTES, 'UTF-8')?>" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                <button type="button" class="aicli-btn-slim" onclick="openPathPicker('root_path')" title="Browse"><i class="fa fa-folder-open"></i></button>
                            </div>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">The starting folder for a terminal or file browser when you open one for an agent.</div>
                        </dd>

                        <!-- WORKSPACE_UPLOAD_MULTI_CHUNKED.md R3: the setting is stored in bytes
                             (upload_max_bytes) but shown and edited here in MB. The visible field
                             has no name attribute, so the form never submits it directly; onchange
                             writes the byte value into the hidden field, which autoSaveConfig()
                             then serializes and saves like every other setting on this tab. -->
                        <dt>Upload size limit</dt>
                        <dd>
                            <?php
                            $uploadMaxBytes = (int)($config['upload_max_bytes'] ?? 536870912);
                            $uploadMaxMb = $uploadMaxBytes > 0 ? (int) round($uploadMaxBytes / 1048576) : 0;
                            ?>
                            <div class="input-row">
                                <input type="number" id="upload_max_mb" aria-label="Upload size limit in megabytes" value="<?=$uploadMaxMb?>" min="0" step="1" style="width:100px !important; flex-shrink:0;" onchange="aicliApplyUploadMaxBytes(this.value)">
                                <span style="opacity:0.75; font-size:11px;">MB (0 = no limit)</span>
                            </div>
                            <input type="hidden" name="upload_max_bytes" id="upload_max_bytes" value="<?=$uploadMaxBytes?>">
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">The largest file the upload overlay accepts. Set it to 0 to allow a file of any size.</div>
                        </dd>
                        <script>
                        function aicliApplyUploadMaxBytes(mb) {
                            var n = Math.max(0, parseInt(mb, 10) || 0);
                            document.getElementById('upload_max_bytes').value = n > 0 ? (n * 1048576) : 0;
                            autoSaveConfig();
                        }
                        </script>

                        <dt>Home Storage</dt>
                        <dd>
                            <?php /* S-11 (#1355): storage target picker. The path input is read-only —
                               the value is set by the picker (ranked candidates from
                               enumerate_storage_targets, or a probed custom path) and routes through
                               the EXISTING preflight_migrate → swal → execute_migrate flow via
                               saveAICliAgentsManager. */ ?>
                            <div class="input-row">
                                <input type="text" name="home_storage_path" id="home_storage_path" readonly value="<?=htmlspecialchars($config['home_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence', ENT_QUOTES, 'UTF-8')?>" style="flex: 1; min-width: 0; opacity: 0.85;" title="Current home storage location — use Change to move it">
                                <button type="button" class="aicli-btn-slim" onclick="aicliToggleStoragePicker('home')" title="Choose a storage target"><i class="fa fa-exchange"></i> Change…</button>
                            </div>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Where this user's persistent home directory data lives on disk. Use Change to move it to a different disk or pool.</div>
                            <div id="aicli-storage-picker-home" class="aicli-storage-picker" style="display:none; width:100%; margin-top:4px;"></div>
                            <?php $homeClass = \AICliAgents\Services\StorageMountService::classifyPath($config['home_storage_path'] ?? ''); ?>
                            <?php if ($homeClass === 'array'): ?>
                                <div style="font-size:10px; color:#eab308; margin-top:2px; padding:3px 6px; background:rgba(234,179,8,0.08); border-radius:3px; display:flex; align-items:center; gap:4px; width:100%;"><i class="fa fa-exclamation-triangle"></i> On array — unavailable when stopped. Emergency mode will activate.</div>
                            <?php elseif (strpos($homeClass, 'pool:') === 0): ?>
                                <div style="font-size:10px; color:#3b82f6; margin-top:2px; padding:3px 6px; background:rgba(59,130,246,0.08); border-radius:3px; display:flex; align-items:center; gap:4px; width:100%;"><i class="fa fa-info-circle"></i> On pool '<?=htmlspecialchars(substr($homeClass, 5), ENT_QUOTES, 'UTF-8')?>' — unavailable if pool is stopped.</div>
                            <?php endif; ?>
                        </dd>

                        <dt>Agent Storage</dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" name="agent_storage_path" id="agent_storage_path" readonly value="<?=htmlspecialchars($config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence', ENT_QUOTES, 'UTF-8')?>" style="flex: 1; min-width: 0; opacity: 0.85;" title="Current agent storage location — use Change to move it">
                                <button type="button" class="aicli-btn-slim" onclick="aicliToggleStoragePicker('agent')" title="Choose a storage target"><i class="fa fa-exchange"></i> Change…</button>
                            </div>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Where each agent's installed files and workspace data live on disk. Use Change to move it to a different disk or pool.</div>
                            <div id="aicli-storage-picker-agent" class="aicli-storage-picker" style="display:none; width:100%; margin-top:4px;"></div>
                            <?php $agentClass = \AICliAgents\Services\StorageMountService::classifyPath($config['agent_storage_path'] ?? ''); ?>
                            <?php if ($agentClass === 'array'): ?>
                                <div style="font-size:10px; color:#eab308; margin-top:2px; padding:3px 6px; background:rgba(234,179,8,0.08); border-radius:3px; display:flex; align-items:center; gap:4px; width:100%;"><i class="fa fa-exclamation-triangle"></i> On array — agents unavailable when stopped.</div>
                            <?php elseif (strpos($agentClass, 'pool:') === 0): ?>
                                <div style="font-size:10px; color:#3b82f6; margin-top:2px; padding:3px 6px; background:rgba(59,130,246,0.08); border-radius:3px; display:flex; align-items:center; gap:4px; width:100%;"><i class="fa fa-info-circle"></i> On pool '<?=htmlspecialchars(substr($agentClass, 5), ENT_QUOTES, 'UTF-8')?>' — unavailable if pool is stopped.</div>
                            <?php endif; ?>
                            <?php /* S-11: informational record of the /mnt/user path the user actually
                               picked when the picker stored a resolved exclusive-share pool path.
                               Serialized with every form save (action=save). */ ?>
                            <input type="hidden" name="storage_picked_via" id="storage_picked_via" value="<?=htmlspecialchars($config['storage_picked_via'] ?? '', ENT_QUOTES, 'UTF-8')?>">
                        </dd>

                        <dt style="align-self: flex-start; padding-top: 6px;">Consolidate Layer Ceiling</dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="consolidate_max_layers" aria-label="Consolidate layer ceiling"
                                       value="<?=\AICliAgents\Services\ConfigService::getConsolidateMaxLayers()?>"
                                       min="4" max="40" step="1" style="width: 90px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="font-size:11px; opacity:0.7; margin-left:8px;">layers</span>
                            </div>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">
                                How many saved layers a home can build up before the plugin merges them into one; higher means fewer merges but a slower first load.
                                Default 30: merging starts at 28, or sooner when disk space is low, and agents always use one layer.
                            </div>
                        </dd>

                        <?php /* PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md (2026-09-11): per-browser
                           only — stored in localStorage, never sent to the server on its own, and
                           never round-tripped through this form's own action=save. It travels only
                           as the X-AICli-Device header/aicli_device param on every OTHER AJAX call
                           this browser makes (CommonLogging.php's aicliAjax(), ajaxUrl() in the SPA),
                           so an event this browser causes can say which device it came from. */ ?>
                        <dt>Device label (this browser only)</dt>
                        <dd>
                            <input type="text" id="aicli_device_label" aria-label="Device label" maxlength="40"
                                   placeholder="for example phone, laptop" style="flex: 1; min-width: 0;"
                                   oninput="window.aicliSaveDeviceLabel(this.value)">
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">
                                Names this browser in the event record, for example "phone" or "office
                                laptop." The name stays on this device and reaches the plugin only when you
                                use this browser again.
                            </div>
                        </dd>
                    </dl>

                    <script>
                    (function () {
                        'use strict';
                        var KEY = 'aicli_device_label';
                        window.aicliSaveDeviceLabel = function (value) {
                            try {
                                var trimmed = (value || '').trim();
                                if (trimmed === '') localStorage.removeItem(KEY);
                                else localStorage.setItem(KEY, trimmed);
                            } catch (e) { /* best-effort — a blocked localStorage must not break the field */ }
                        };
                        document.addEventListener('DOMContentLoaded', function () {
                            var el = document.getElementById('aicli_device_label');
                            if (!el) return;
                            try { el.value = localStorage.getItem(KEY) || ''; } catch (e) { /* leave blank */ }
                        });
                    }());
                    </script>


                    <!-- RELAY_WAITING_PILL.md (2026-09-09) Part 3: capture-on-deliver. Beside the
                         pane-input controls above because it feeds the SAME readiness gate — a
                         sample is only useful for improving the rules just above it. Saves
                         through the same autoSaveConfig()/`save` path as every other select on
                         this tab. -->
                    <dl style="margin-top:14px;">
                        <dt>Record a screen sample when I deliver a waiting message</dt>
                        <dd>
                            <select name="relay_gate_sampling_enabled" aria-label="Record a screen sample when I deliver a waiting message" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['relay_gate_sampling_enabled'] ?? '0', "1", _('On'))?>
                                <?=mk_option($config['relay_gate_sampling_enabled'] ?? '0', "0", _('Off (default)'))?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">When you click to deliver a waiting Relay message, this also keeps a scrubbed screen sample on this server to help the plugin recognise the agent's screen; nothing is sent anywhere, and it is off by default.</div>
                        </dd>
                    </dl>

                    <!-- Moved to the bottom of the card (operator request 2026-09-13) so the settings rows stay together. -->
                    <!-- PANE_INPUT_HYBRID_ALLOWLIST.md (2026-09-09): moved here from the Agent
                         Relay tab and renamed from "Relay delivery rules". These rules decide
                         when the plugin may type into an agent's terminal — they apply to a
                         Relay notice, to Continue, and to Reload, not only to Relay, so they
                         belong with session behaviour, not Relay messaging. The JS stays in
                         ManagerRelayScripts.php (loaded on every tab already); only the control
                         moved. -->
                    <details style="border:1px solid var(--border-color,#e0e0e0);border-radius:6px;padding:10px 14px;margin-top:14px;" id="pane-input-rules-section">
                        <summary style="cursor:pointer;font-weight:700;">Terminal input safety rules</summary>
                        <p style="margin:10px 0 8px;opacity:.7;font-size:12px;">
                          Before the plugin types into an agent's terminal, it reads the bottom of the screen. It
                          does this for a Relay notice, for Continue, and for Reload. A <strong>block</strong> rule
                          (shared by every agent) holds the input when the screen shows a question, a menu, a yes/no
                          prompt, a pager, or a settings overlay. An <strong>idle</strong> rule (per agent) describes
                          that agent's empty prompt. When an agent has idle rules, the plugin types only when it
                          recognises the prompt; otherwise it holds. An agent with no idle rules uses the block
                          rules only. Write each rule as a regular expression, in <code>/body/flags</code> form. You
                          (or your coding agent) can also edit
                          <code>/boot/config/plugins/unraid-aicliagents/pane-input-rules.json</code> directly. A
                          pattern that does not compile is dropped, and the built-in rule stays in force, so this
                          check can never break.
                        </p>
                        <div id="pane-input-errors" style="display:none;color:#b45309;font-size:12px;margin-bottom:8px;"></div>
                        <h4 style="margin:8px 0 4px;font-size:13px;">Block rules (all agents)</h4>
                        <div id="pane-input-block" role="list" style="display:grid;gap:4px;font-size:12px;"><span style="opacity:.7">Loading…</span></div>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:6px 0 12px;">
                          <input id="pane-input-block-new" placeholder="/pattern to hold input/i" style="flex:1 1 260px;min-width:0;font-family:monospace;" aria-label="New block pattern">
                          <button type="button" class="aicli-btn-slim" onclick="paneInputAdd('block')">Add block rule</button>
                        </div>
                        <h4 style="margin:8px 0 4px;font-size:13px;">Idle rules for
                          <select id="pane-input-agent" aria-label="Agent" onchange="paneInputRenderIdle()"></select>
                        </h4>
                        <div id="pane-input-idle" role="list" style="display:grid;gap:4px;font-size:12px;"></div>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:6px 0 12px;">
                          <input id="pane-input-idle-new" placeholder="/pattern that matches this agent's empty prompt/mu" style="flex:1 1 260px;min-width:0;font-family:monospace;" aria-label="New idle pattern">
                          <button type="button" class="aicli-btn-slim" onclick="paneInputAdd('idle')">Add idle rule</button>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                          <button type="button" class="aicli-btn-slim" onclick="paneInputSave()">Save rules</button>
                          <button type="button" class="aicli-btn-slim" onclick="paneInputReset()">Discard changes</button>
                          <span style="font-size:12px;opacity:.75;">Test:</span>
                          <select id="pane-input-probe-session" aria-label="Workspace to test"></select>
                          <button type="button" class="aicli-btn-slim" onclick="paneInputProbe()">Check screen now</button>
                          <span id="pane-input-probe-result" style="font-size:12px;"></span>
                        </div>
                    </details>
                </div>
            </div>

            <!-- Secrets Vault moved to per-agent Store cards (Secrets panel). See AGENT_LEVEL_TMUX_CONF.md
                 and the av2 card mockup. Single-key-per-agent agents (env_prefix + _API_KEY) still
                 work unchanged; the new Secrets panel also supports multi-field agents like Goose. -->

            <!-- VOICE_SWITCHES.md (R3/R7/R8; supersedes AGENT_VOICE.md R7/R8's
                 per-device toggle): the ONE global Voice switch plus the
                 plugin-wide text-to-speech (TTS) settings. The toggle sets
                 `voice_enabled` through save_voice_settings and, when it
                 turns voice ON, calls window.aicliVoice.enable() (voice.js,
                 loaded once by both .page files) inside the same click so
                 this device is unlocked; after the save, voice.js tries the
                 configured API for the confirmation and falls back to the
                 browser. It never goes through this form's
                 autoSaveConfig()/action=save path. The
                 endpoint/voice/speed/key fields load and save through their
                 own get_voice_settings / save_voice_settings AJAX actions
                 (VoiceHandler), the same way the SSH Keys card above uses its
                 own AJAX actions instead of the settings form. The key field
                 never shows the stored value — only whether one is set — and
                 is sent over POST, not as a URL query parameter, because
                 aicliAjax()'s GET helper would put a secret in server access
                 logs and browser history. AGENT_VOICE.md R13: the Voice id
                 field is a `<select>` filled from the read-only `list_voices`
                 action when the engine can answer it, falling back to the
                 plain text field — see aicliVoiceRefreshVoiceList() below. -->
            <div class="aicli-card">
                <div class="aicli-card-header"><i class="fa fa-volume-up text-orange-500"></i> Agent voice</div>
                <div class="aicli-card-body">
                    <p style="font-size:12px; opacity:0.8; margin-bottom:12px;">
                        An agent can speak to get your attention. By default it uses the
                        browser's own voice. Set an endpoint URL to use a real text-to-speech
                        server instead.
                    </p>
                    <dl>
                        <?php /* AGENT_VOICE.md "#323 guided setup": the "Set up natural voice" row. */ require __DIR__ . '/VoiceSetupCard.php'; ?>
                        <dt>Voice (all devices)</dt>
                        <dd>
                            <button type="button" id="aicli-voice-toggle" class="aicli-btn-slim" onclick="aicliVoiceToggleGlobal()">
                                <i id="aicli-voice-toggle-icon" class="fa fa-volume-off"></i> <span id="aicli-voice-toggle-label">Off</span>
                            </button>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Turns speech on or off for every open tab on every device; to mute one workspace only, use its "..." menu in the terminal drawer.</div>
                        </dd>

                        <dt>Endpoint URL</dt>
                        <dd>
                            <input type="text" id="aicli-voice-tts-url" aria-label="Text-to-speech endpoint URL" onchange="aicliVoiceSaveField('tts_url')" placeholder="http://192.168.1.4:8880" style="width:100%;">
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Leave empty to use the browser's own voice, or enter the address of an OpenAI-compatible text-to-speech server such as Kokoro-FastAPI.</div>
                        </dd>

                        <dt>Voice id</dt>
                        <dd>
                            <!-- R13: when the engine can list its own voices
                                 (GET /v1/audio/voices, a Kokoro-FastAPI
                                 extension), the select replaces the text
                                 field. Both share the same "tts_voice" value;
                                 aicliVoiceSaveField('tts_voice') reads
                                 whichever one is visible. -->
                            <select id="aicli-voice-tts-voice-select" aria-label="Voice id" onchange="aicliVoiceSelectChanged()" style="width:100%; display:none;"></select>
                            <input type="text" id="aicli-voice-tts-voice" aria-label="Voice id" onchange="aicliVoiceSaveField('tts_voice')" placeholder="af_heart" style="width:100%;">
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Which of the engine's voices to use; ignored in browser mode. When the engine can list its own voices, choose from this list, or pick "Other…" to type one by hand.</div>
                        </dd>

                        <dt>Speed</dt>
                        <dd>
                            <input type="number" id="aicli-voice-tts-speed" aria-label="Speech speed" onchange="aicliVoiceSaveField('tts_speed')" min="0.5" max="2.0" step="0.1" style="width:90px !important;">
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">How fast the engine speaks, where 1.0 is normal speed. Ignored in browser mode.</div>
                        </dd>

                        <dt>API key</dt>
                        <dd>
                            <div class="input-row">
                                <input type="password" id="aicli-voice-tts-key" aria-label="Text-to-speech API key" onchange="aicliVoiceSaveField('tts_api_key')" placeholder="Leave empty to keep the current key" style="flex:1; min-width:0;" autocomplete="new-password">
                                <button type="button" id="aicli-voice-key-clear" class="aicli-btn-slim" onclick="aicliVoiceClearKey();" title="Remove the stored API key" style="font-size:11px; min-height:24px; min-width:44px; padding:2px 10px; white-space:nowrap;">Clear</button>
                            </div>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Key status: <strong id="aicli-voice-key-status">unknown</strong>. Only needed when the endpoint requires one; the stored key is never shown here.</div>
                        </dd>

                        <dt aria-hidden="true">&nbsp;</dt>
                        <dd>
                            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <button type="button" class="aicli-btn-slim" onclick="aicliVoiceTest()"><i class="fa fa-play"></i> Test</button>
                            </div>
                            <!-- The result has its own full-width line with a fixed minimum
                                 height, so a long message can never resize the card or move
                                 the buttons. -->
                            <div id="aicli-voice-test-result" style="font-size:11px; min-height:1.4em; width:100%; overflow-wrap:anywhere;"></div>
                        </dd>

                        <!-- VOICE_MAIL.md R8: how much voice mail is kept. Saved one field
                             per request through save_voice_settings, like the fields above. -->
                        <dt style="border-top:1px solid var(--border-color, rgba(128,128,128,0.25)); padding-top:12px; margin-top:8px; font-weight:600;">Voice mail</dt>
                        <dd style="border-top:1px solid var(--border-color, rgba(128,128,128,0.25)); padding-top:12px; margin-top:8px;">
                            <div style="font-size:12px; opacity:0.8;">Every spoken message is also kept as voice mail, so you can play one you missed from the terminal drawer.</div>
                        </dd>

                        <dt>Messages kept per workspace</dt>
                        <dd>
                            <input type="number" id="aicli-voicemail-max-per-workspace" aria-label="Voice mail messages kept per workspace" onchange="aicliVoiceSaveField('voicemail_max_per_workspace')" min="1" max="500" step="1" placeholder="50" style="width:90px !important;">
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">1 to 500. When a workspace has more, the oldest messages you have heard are removed first.</div>
                        </dd>

                        <dt>Days kept</dt>
                        <dd>
                            <input type="number" id="aicli-voicemail-max-age-days" aria-label="Days to keep voice mail" onchange="aicliVoiceSaveField('voicemail_max_age_days')" min="1" max="90" step="1" placeholder="7" style="width:90px !important;">
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">1 to 90. Older messages are removed, heard or not.</div>
                            <div id="aicli-voicemail-save-result" style="font-size:11px; min-height:1.4em; width:100%; overflow-wrap:anywhere;"></div>
                        </dd>

                        <!-- VOICE_INPUT.md: "Voice input" section, in the SAME card — the
                             reverse direction from the TTS settings above (dictating INTO a
                             workspace, not the agent speaking). `stt_url` empty means browser
                             mode: the Web Speech API runs entirely on this device and nothing
                             leaves it. Loads/saves through the same get_voice_settings /
                             save_voice_settings actions and the same masked-key handling as
                             the TTS key above (aicliVoiceSaveField / aicliVoicePost). The Test
                             button here records 3 s on this device and shows the recognised
                             text — it calls window.aicliVoice.startInput() with a fixed
                             non-workspace id ('manager') and never dictates anywhere. -->
                        <dt style="border-top:1px solid var(--border-color, rgba(128,128,128,0.25)); padding-top:12px; margin-top:8px; font-weight:600;">Voice input</dt>
                        <dd style="border-top:1px solid var(--border-color, rgba(128,128,128,0.25)); padding-top:12px; margin-top:8px;">
                            <div style="font-size:12px; opacity:0.8;">Dictate into a workspace from the microphone icon in the terminal drawer. Leave the endpoint empty to use the browser's own speech recognition.</div>
                        </dd>

                        <?php /* VOICE_ENGINE_SETUP.md #381 #382: the "Set up local dictation" row. */ require __DIR__ . '/VoiceSttSetupCard.php'; ?>

                        <dt>Transcription endpoint URL</dt>
                        <dd>
                            <input type="text" id="aicli-voice-stt-url" aria-label="Transcription endpoint URL" onchange="aicliVoiceSaveField('stt_url')" placeholder="http://192.168.1.4:8000" style="width:100%;">
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Leave empty to use the browser's own speech recognition, or enter the address of an OpenAI-compatible transcription server such as Speaches.</div>
                        </dd>

                        <dt>Model</dt>
                        <dd>
                            <input type="text" id="aicli-voice-stt-model" aria-label="Transcription model" onchange="aicliVoiceSaveField('stt_model')" placeholder="whisper-1" style="width:100%;">
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Which model the endpoint should use. Ignored in browser mode.</div>
                        </dd>

                        <dt>Language</dt>
                        <dd>
                            <input type="text" id="aicli-voice-stt-language" aria-label="Spoken language" onchange="aicliVoiceSaveField('stt_language')" placeholder="en" style="width:100%;">
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">The language you will speak, for example "en". Leave empty to let the browser or the engine detect it.</div>
                        </dd>

                        <dt>API key</dt>
                        <dd>
                            <div class="input-row">
                                <input type="password" id="aicli-voice-stt-key" aria-label="Transcription API key" onchange="aicliVoiceSaveField('stt_api_key')" placeholder="Leave empty to keep the current key" style="flex:1; min-width:0;" autocomplete="new-password">
                                <button type="button" id="aicli-voice-stt-key-clear" class="aicli-btn-slim" onclick="aicliVoiceClearSttKey();" title="Remove the stored API key" style="font-size:11px; min-height:24px; min-width:44px; padding:2px 10px; white-space:nowrap;">Clear</button>
                            </div>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Key status: <strong id="aicli-voice-stt-key-status">unknown</strong>. Only needed when the endpoint requires one; the stored key is never shown here.</div>
                        </dd>

                        <dt aria-hidden="true">&nbsp;</dt>
                        <dd>
                            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <button type="button" class="aicli-btn-slim" onclick="aicliVoiceInputTest()"><i class="fa fa-microphone"></i> Test (records 3 s)</button>
                            </div>
                            <div id="aicli-voice-input-test-result" style="font-size:11px; min-height:1.4em; width:100%; overflow-wrap:anywhere;"></div>
                        </dd>
                    </dl>
                </div>
            </div>

            <script>
            (function () {
                'use strict';

                function csrfTok() { return window.csrf_token || ''; }

                // Sensitive fields (the key) go over POST, with csrf_token in the
                // body, not the URL — matching aicliSshAjax below for the same
                // reason: aicliAjax()'s GET helper would put the value in a
                // query string, which server access logs and browser history
                // both keep.
                function aicliVoicePost(action, body) {
                    var token = csrfTok();
                    var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=' + encodeURIComponent(action) + '&csrf_token=' + encodeURIComponent(token);
                    var postBody = Object.assign({}, body, { csrf_token: token });
                    return fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams(postBody).toString()
                    }).then(function (r) { return r.json(); });
                }

                // VOICE_SWITCHES.md R3/R8: `voice_enabled` (the one global
                // switch) travels in the same settings object as the TTS
                // engine fields. Every call here that carries it also tells
                // voice.js, so this tab's known state (and its toggle) is
                // never behind what the server just reported.
                function applySettings(r) {
                    if (!r || r.status !== 'ok' || !r.settings) return;
                    var s = r.settings;
                    $('#aicli-voice-tts-url').val(s.tts_url || '');
                    $('#aicli-voice-tts-voice').val(s.tts_voice || 'af_heart');
                    $('#aicli-voice-tts-speed').val(s.tts_speed || '1.0');
                    $('#aicli-voice-key-status').text(s.tts_api_key_set ? 'set' : 'not set');
                    // VOICE_INPUT.md: the reverse-direction (dictation) settings,
                    // loaded/saved the same way as the TTS fields above.
                    $('#aicli-voice-stt-url').val(s.stt_url || '');
                    $('#aicli-voicemail-max-per-workspace').val(s.voicemail_max_per_workspace || '50');
                    $('#aicli-voicemail-max-age-days').val(s.voicemail_max_age_days || '7');
                    $('#aicli-voice-stt-model').val(s.stt_model || 'whisper-1');
                    $('#aicli-voice-stt-language').val(s.stt_language || '');
                    $('#aicli-voice-stt-key-status').text(s.stt_api_key_set ? 'set' : 'not set');
                    if (typeof s.voice_enabled !== 'undefined' && window.aicliVoice) {
                        window.aicliVoice.setState(!!s.voice_enabled);
                    }
                    syncToggle();
                }

                // R13: fill the select from the engine's own voice list, or
                // fall back to the plain text field when the engine cannot
                // list its voices. currentVoice is kept selected even when
                // the engine's own list does not carry it.
                function applyVoiceList(result, currentVoice) {
                    var select = $('#aicli-voice-tts-voice-select');
                    var input = $('#aicli-voice-tts-voice');
                    if (!result || !result.supported) {
                        select.hide().empty();
                        input.show();
                        return;
                    }
                    var voices = result.voices || [];
                    var found = false;
                    select.empty();
                    voices.forEach(function (v) {
                        var label = v.grade ? (v.id + ' — ' + v.grade) : v.id;
                        select.append($('<option>').attr('value', v.id).text(label));
                        if (v.id === currentVoice) found = true;
                    });
                    if (currentVoice && !found) {
                        select.append($('<option>').attr('value', currentVoice).text(currentVoice));
                    }
                    select.append($('<option>').attr('value', '__other__').text('Other…'));
                    if (currentVoice) select.val(currentVoice);
                    select.show();
                    input.hide();
                }

                // Whichever control currently holds the value the operator
                // means to keep — the select unless it is hidden or sitting
                // on the "Other…" placeholder.
                function currentVoiceValue() {
                    var select = $('#aicli-voice-tts-voice-select');
                    if (select.is(':visible') && select.val() && select.val() !== '__other__') return select.val();
                    return ($('#aicli-voice-tts-voice').val() || '').trim();
                }

                // Called on load, after an Endpoint URL save, and after Test —
                // the three moments the engine's voice list can have changed.
                window.aicliVoiceRefreshVoiceList = function () {
                    var current = currentVoiceValue();
                    aicliAjax('list_voices', {}, function (r) {
                        applyVoiceList(r, current);
                    });
                };

                // Picking "Other…" swaps to the text field instead of saving.
                // Picking a real id copies it into the text field (so the two
                // controls never disagree) and saves at once.
                window.aicliVoiceSelectChanged = function () {
                    var select = $('#aicli-voice-tts-voice-select');
                    var value = select.val();
                    if (value === '__other__') {
                        $('#aicli-voice-tts-voice').val('').show();
                        select.hide();
                        $('#aicli-voice-tts-voice').trigger('focus');
                        return;
                    }
                    $('#aicli-voice-tts-voice').val(value);
                    aicliVoiceSaveField('tts_voice');
                };

                function syncToggle() {
                    var on = !!(window.aicliVoice && window.aicliVoice.enabled());
                    $('#aicli-voice-toggle-icon').attr('class', 'fa ' + (on ? 'fa-volume-up' : 'fa-volume-off'));
                    $('#aicli-voice-toggle-label').text(on ? 'On' : 'Off');
                }

                // The one global Voice switch (VOICE_SWITCHES.md R3). Turning it
                // ON runs window.aicliVoice.enable() synchronously inside this
                // click so this device is unlocked. After the save succeeds,
                // confirm() tries the configured API voice for this tab only;
                // the browser voice is its bounded fallback. Every open tab
                // (including this one) repaints from the `state` message the
                // save triggers, via applySettings()'s setState() above.
                window.aicliVoiceToggleGlobal = function () {
                    var v = window.aicliVoice;
                    var turningOn = !(v && v.enabled());
                    if (turningOn && v) v.enable();
                    aicliVoicePost('save_voice_settings', { voice_enabled: turningOn ? '1' : '0' }).then(function (r) {
                        applySettings(r);
                        if (!(r && r.status === 'ok')) {
                            swal('Error', (r && r.message) || 'Failed to save voice settings.', 'error');
                        } else if (turningOn && v && v.confirm) {
                            v.confirm();
                        }
                    }).catch(function () { swal('Error', 'Network error — could not save voice settings.', 'error'); });
                };

                window.aicliVoiceLoadSettings = function () {
                    aicliAjax('get_voice_settings', {}, function (r) {
                        applySettings(r);
                        window.aicliVoiceRefreshVoiceList();
                    });
                };

                // Save on change, like every other card on this tab: one field
                // per request (save_voice_settings changes only the fields it
                // receives). The key field is sent only when it holds a value
                // and is blanked afterwards; the stored key is never shown.
                // tts_voice reads from whichever control (select or text
                // field) is visible right now, per currentVoiceValue() above.
                window.aicliVoiceSaveField = function (key) {
                    var ids = {
                        tts_url: "#aicli-voice-tts-url", tts_voice: "#aicli-voice-tts-voice", tts_speed: "#aicli-voice-tts-speed", tts_api_key: "#aicli-voice-tts-key",
                        // VOICE_INPUT.md: the reverse-direction fields save through the
                        // SAME save_voice_settings action, one field per request.
                        stt_url: "#aicli-voice-stt-url", stt_model: "#aicli-voice-stt-model", stt_language: "#aicli-voice-stt-language", stt_api_key: "#aicli-voice-stt-key",
                        // VOICE_MAIL.md R8: retention, same action, own result line.
                        voicemail_max_per_workspace: "#aicli-voicemail-max-per-workspace", voicemail_max_age_days: "#aicli-voicemail-max-age-days"
                    };
                    if (!ids[key]) return;
                    var value = key === "tts_voice" ? currentVoiceValue() : ($(ids[key]).val() || "").trim();
                    if ((key === "tts_api_key" || key === "stt_api_key") && value === "") return;
                    var body = {}; body[key] = value;
                    var box = key === "stt_url" || key === "stt_model" || key === "stt_language" || key === "stt_api_key"
                        ? $("#aicli-voice-input-test-result")
                        : (key.indexOf("voicemail_") === 0 ? $("#aicli-voicemail-save-result") : $("#aicli-voice-test-result"));
                    aicliVoicePost("save_voice_settings", body).then(function (r) {
                        applySettings(r);
                        if (key === "tts_api_key") $("#aicli-voice-tts-key").val("");
                        if (key === "stt_api_key") $("#aicli-voice-stt-key").val("");
                        if (r && r.status === "ok") {
                            box.css("color", "").text("Saved."); setTimeout(function () { if (box.text() === "Saved.") box.text(""); }, 1500);
                            // The endpoint changing is the one field save that
                            // can change the voice catalogue itself.
                            if (key === "tts_url") window.aicliVoiceRefreshVoiceList();
                        } else box.css("color", "#f87171").text((r && r.message) || "Failed to save voice settings.");
                    }).catch(function () { box.css("color", "#f87171").text("Network error — could not save voice settings."); });
                };
                window.aicliVoiceClearKey = function () {
                    swal({ title: 'Clear the API key?', text: 'The endpoint will be called without a key.', type: 'warning', showCancelButton: true, confirmButtonText: 'Clear' }, function (confirmed) {
                        if (!confirmed) return;
                        aicliVoicePost('save_voice_settings', { tts_api_key: '__clear__' }).then(function (r) {
                            applySettings(r);
                            if (r && r.status === 'ok') swal({ title: 'Cleared', type: 'success', timer: 1200, showConfirmButton: false });
                            else swal('Error', (r && r.message) || 'Failed to clear the key.', 'error');
                        }).catch(function () { swal('Error', 'Network error — could not clear the key.', 'error'); });
                    });
                };
                // VOICE_INPUT.md: same __clear__ contract as the TTS key, for stt_api_key.
                window.aicliVoiceClearSttKey = function () {
                    swal({ title: 'Clear the transcription API key?', text: 'The endpoint will be called without a key.', type: 'warning', showCancelButton: true, confirmButtonText: 'Clear' }, function (confirmed) {
                        if (!confirmed) return;
                        aicliVoicePost('save_voice_settings', { stt_api_key: '__clear__' }).then(function (r) {
                            applySettings(r);
                            if (r && r.status === 'ok') swal({ title: 'Cleared', type: 'success', timer: 1200, showConfirmButton: false });
                            else swal('Error', (r && r.message) || 'Failed to clear the key.', 'error');
                        }).catch(function () { swal('Error', 'Network error — could not clear the key.', 'error'); });
                    });
                };

                window.aicliVoiceTest = function () {
                    var box = $('#aicli-voice-test-result');
                    box.css('color', '').text('Testing…');
                    aicliVoicePost('test_voice', {}).then(function (r) {
                        if (r && r.status === 'ok') box.css('color', '').text(r.message || ('Done (' + (r.mode || '') + ').'));
                        else box.css('color', '#f87171').text((r && r.message) || 'Test failed.');
                        // A Test click is also a good moment to see whether
                        // the engine's voice list has changed.
                        window.aicliVoiceRefreshVoiceList();
                    }).catch(function () { box.css('color', '#f87171').text('Network error.'); });
                };

                // VOICE_INPUT.md R7: records 3 s on this device through the SAME
                // window.aicliVoice module the terminal drawer's mic uses, and
                // only ever shows the recognised text — it never dictates
                // anywhere. A fixed 'manager' workspace id satisfies voice.js's
                // one eligibility check (this page has no active workspace of
                // its own); engine mode calls voice_transcribe exactly the way
                // the terminal drawer's mic does, browser mode never leaves
                // this device.
                window.aicliVoiceInputTest = function () {
                    var box = $('#aicli-voice-input-test-result');
                    var api = window.aicliVoice;
                    if (!api || !api.startInput) { box.css('color', '#f87171').text('Voice input is not available on this page.'); return; }
                    var sttUrl = ($('#aicli-voice-stt-url').val() || '').trim();
                    var sttLanguage = ($('#aicli-voice-stt-language').val() || '').trim();
                    var done = false;
                    var timerStarted = false;
                    // R11 live typing: voice.js sends each finished phrase at
                    // once as {state:'recording', phrase}, and the last idle
                    // event's `text` holds only the words still in progress.
                    // So the result is every phrase plus that last text.
                    var heard = [];
                    var interim = '';
                    var baseSeconds = null;
                    var stopTimer = null;
                    var answerTimer = null;
                    // The box shows what is going on at every step: the seconds left,
                    // the words heard so far (finished phrases plus the ones still
                    // in progress), then "Transcribing…" while the engine answers.
                    var show = function (head) {
                        var said = heard.concat(interim.trim() ? [interim.trim()] : []).join(' ');
                        box.css('color', '').text(said ? head + ' ' + said : head);
                    };
                    var finish = function () {
                        done = true;
                        window.removeEventListener('aicli-voice-input', onEvt);
                        if (stopTimer) { clearTimeout(stopTimer); stopTimer = null; }
                        if (answerTimer) { clearTimeout(answerTimer); answerTimer = null; }
                    };
                    box.css('color', '').text('Listening… 3 s left');
                    var onEvt = function (e) {
                        var d = (e && e.detail) || {};
                        if (d.state === 'refused') {
                            finish();
                            box.css('color', '#f87171').text(d.error || 'Test failed.');
                        } else if (d.state === 'recording') {
                            if (typeof d.phrase === 'string' && d.phrase.trim()) heard.push(d.phrase.trim());
                            if (typeof d.interim === 'string') interim = d.interim;
                            if (typeof d.error === 'string' && d.error) { box.css('color', '#f87171').text(d.error); return; }
                            if (typeof d.seconds === 'number') {
                                if (baseSeconds === null) baseSeconds = d.seconds;
                                var left = Math.max(0, 3 - (d.seconds - baseSeconds));
                                show('Listening… ' + left + ' s left');
                            } else {
                                show('Listening…');
                            }
                            // The 3 s start when the microphone really records
                            // (after a permission prompt), not at the click.
                            if (!timerStarted) {
                                timerStarted = true;
                                stopTimer = setTimeout(function () {
                                    stopTimer = null;
                                    if (done) return;
                                    interim = '';
                                    show('Transcribing…');
                                    api.stopInput();
                                    // Never wait for ever: the engine may be loading its model.
                                    answerTimer = setTimeout(function () {
                                        if (done) return;
                                        finish();
                                        box.css('color', '#f87171').text('No answer from the transcription server after 60 s. Check the endpoint and try again.');
                                    }, 60000);
                                }, 3000);
                            }
                        } else if (d.state === 'transcribing') {
                            interim = '';
                            show('Transcribing…');
                        } else if (d.state === 'idle' && typeof d.text === 'string') {
                            finish();
                            if (d.text.trim()) heard.push(d.text.trim());
                            if (!heard.length && typeof d.error === 'string' && d.error) box.css('color', '#f87171').text(d.error);
                            else box.css('color', '').text(heard.length ? heard.join(' ') : 'Nothing was recognised.');
                        }
                    };
                    window.addEventListener('aicli-voice-input', onEvt);
                    api.startInput({ workspaceId: 'manager', sttUrl: sttUrl, sttLanguage: sttLanguage });
                };

                window.addEventListener('aicli-voice-state', syncToggle);

                document.addEventListener('DOMContentLoaded', function () {
                    syncToggle();
                    window.aicliVoiceLoadSettings();
                    var cfgTab = document.querySelector('[data-tab="config"]');
                    if (cfgTab) cfgTab.addEventListener('click', window.aicliVoiceLoadSettings);
                });
            }());
            </script>

            <!-- AUTO_CONTINUE_PATTERNS.md: the operator's own auto-continue patterns.
                 Rendered by ManagerAutoContinueScripts.php through its own AJAX
                 actions (AutoContinueHandler), like the Agent voice card. No control
                 here has a `name`, so the settings form never saves them. -->
            <div class="aicli-card" id="acp-card">
                <div class="aicli-card-header"><i class="fa fa-repeat text-orange-500" aria-hidden="true"></i> Auto-continue patterns</div>
                <div class="aicli-card-body acp-body">
                    <p class="acp-help">The screen messages that let a workspace continue by itself: a temporary model error, or a usage quota with a retry time. Add your own pattern when an agent stops on a message the plugin does not know yet. Each change is saved at once.</p>
                    <div class="acp-toolbar">
                        <h3 class="acp-h" id="acp-mine-h">Your patterns</h3>
                        <button type="button" class="aicli-btn-slim" id="acp-add"><i class="fa fa-plus" aria-hidden="true"></i> Add pattern</button>
                    </div>
                    <div id="acp-status" class="acp-status" aria-live="polite"></div>
                    <ul id="acp-list" class="acp-list" aria-labelledby="acp-mine-h"></ul>
                    <details id="acp-builtins" class="acp-details">
                        <summary>Built-in patterns (read-only)</summary>
                        <div id="acp-builtin-list" class="acp-builtin-list"></div>
                    </details>
                </div>
            </div>

            <!-- PLUGIN_MANAGEMENT_TOOLS.md Phase 1: the one master switch for the
                 read-only admin MCP tool catalogue (AdminMcpTools). Saves through
                 the same autoSaveConfig()/`save` path as every other select on this
                 tab, so ConfigService::saveConfig() calls AdminMcpTools::ensureMcpRegistered()
                 for us the instant this is changed — no separate Apply button needed. -->
            <div class="aicli-card">
                <div class="aicli-card-header"><i class="fa fa-wrench text-orange-500"></i> Plugin management</div>
                <div class="aicli-card-body">
                    <dl>
                        <dt>Plugin management tools</dt>
                        <dd>
                            <select name="admin_tools_enabled" aria-label="Plugin management tools" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['admin_tools_enabled'] ?? '0', "1", _('On'))?>
                                <?=mk_option($config['admin_tools_enabled'] ?? '0', "0", _('Off (default)'))?>
                            </select>
                            <div style="font-size:10px; opacity:0.65; margin-top:3px; width:100%;">Lets agents in your workspaces ask the plugin about its own state, for example to list workspaces or check storage; every tool is read-only, and this is off by default.</div>
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="aicli-card">
                <div class="aicli-card-header" style="display:flex; align-items:center; justify-content:space-between; padding:5px 15px;">
                    <span><i class="fa fa-key text-orange-500"></i> SSH Keys</span>
                    <button type="button" onclick="aicliShowSshHelp()" class="aicli-btn-slim" title="How to generate an SSH public key" style="font-size:10px;">
                        <i class="fa fa-question-circle"></i> Help
                    </button>
                </div>
                <div class="aicli-card-body">
                    <p style="font-size:12px; opacity:0.8; margin-bottom:12px;">
                        Add your SSH public key to connect directly to workspace tmux sessions from your local terminal.
                        The key is stored in the plugin user's <code>~/.ssh/authorized_keys</code> with a forced command.
                    </p>
                    <div id="aicli-ssh-keys-list" style="margin-bottom:12px; min-height:40px;">
                        <!-- populated by aicliLoadSshKeys() -->
                    </div>
                    <div style="display:flex; gap:8px; flex-direction:column;">
                        <textarea id="aicli-ssh-pubkey-input" placeholder="Paste your public key here (ssh-ed25519 AAA...)" rows="3"
                                  style="width:100%; font-family:monospace; font-size:11px; resize:vertical; padding:6px;"
                                  onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();}"></textarea>
                        <input id="aicli-ssh-key-label" type="text" placeholder="Label (for example MacBook Pro)" style="width:100%; padding:6px;"
                               onkeydown="if(event.key==='Enter'){event.preventDefault();aicliAddSshKey();}">
                        <button type="button" class="aicli-btn-slim" onclick="aicliAddSshKey()" style="align-self:flex-start;">
                            <i class="fa fa-plus"></i> Add Key
                        </button>
                    </div>
                    <p style="font-size:10px; opacity:0.75; margin-top:8px;">
                        <strong>Connect:</strong> <code>ssh <?=htmlspecialchars($config['user'] ?? 'root', ENT_QUOTES)?> aicli-agent-&lt;name&gt;</code>
                    </p>
                </div>
            </div>
    </div>
</div>
</form>
<!-- Bug #710: outer aicli-settings-form opened in ManagerLayout.php scopes
     ONLY the Configuration tab. Earlier the closing tag was at the end of
     ManagerLogTab.php, which made store/storage/debug content nested under
     the form — browsers flatten nested forms, so inner Save buttons (e.g.
     av2-secrets-form) were silently submitting the OUTER form (action=save)
     instead of running their onsubmit handlers (action=save_vault). Closing
     the outer form here keeps each tab's forms independent. -->

<script>
(function () {
    'use strict';

    function aicliSshAjax(action, body) {
        var csrf = (window.csrf_token || '');
        var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=' + action + '&csrf_token=' + encodeURIComponent(csrf);
        // Include csrf_token in POST body: Unraid's auto_prepend_file (local_prepend.php)
        // validates CSRF only from $_POST or X-CSRF-TOKEN header — GET params are ignored.
        var postBody = Object.assign({}, body, { csrf_token: csrf });
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(postBody).toString()
        }).then(function (r) { return r.json(); });
    }

    function clearNode(el) { while (el.firstChild) el.removeChild(el.firstChild); }

    function mkEl(tag, cssText, textContent) {
        var el = document.createElement(tag);
        if (cssText) el.style.cssText = cssText;
        if (textContent != null) el.textContent = textContent;
        return el;
    }

    function buildKeyRow(k) {
        var fp = k.fingerprint || '', label = k.label || '(unlabelled)', dt = k.date || '';
        var row = mkEl('div', 'display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid rgba(128,128,128,0.15);');
        var icon = document.createElement('i'); icon.className = 'fa fa-key'; icon.style.cssText = 'opacity:0.5; font-size:11px;';
        row.appendChild(icon);
        var info = mkEl('div', 'flex:1; min-width:0;');
        info.appendChild(mkEl('div', 'font-size:12px; font-weight:600;', label));
        info.appendChild(mkEl('div', 'font-size:10px; font-family:monospace; opacity:0.75; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;', fp));
        if (dt) info.appendChild(mkEl('div', 'font-size:10px; opacity:0.75;', dt));
        row.appendChild(info);
        var btn = mkEl('button', 'flex-shrink:0;'); btn.type = 'button'; btn.className = 'aicli-btn-slim'; btn.title = 'Remove key';
        var trashIcon = document.createElement('i'); trashIcon.className = 'fa fa-trash-o';
        btn.appendChild(trashIcon);
        btn.addEventListener('click', (function (f) { return function () { window.aicliRemoveSshKey(f); }; })(fp));
        row.appendChild(btn);
        return row;
    }

    window.aicliLoadSshKeys = function () {
        var list = document.getElementById('aicli-ssh-keys-list');
        if (!list) return;
        clearNode(list);
        list.appendChild(mkEl('span', 'opacity:0.75; font-size:11px;', 'Loading…'));
        aicliSshAjax('list_keys', {}).then(function (data) {
            clearNode(list);
            if (!data.keys || data.keys.length === 0) {
                list.appendChild(mkEl('span', 'opacity:0.75; font-size:11px;', 'No keys registered.')); return;
            }
            data.keys.forEach(function (k) { list.appendChild(buildKeyRow(k)); });
        }).catch(function () {
            clearNode(list);
            list.appendChild(mkEl('span', 'color:#f87171; font-size:11px;', 'Failed to load keys.'));
        });
    };

    window.aicliAddSshKey = function () {
        var pubkey = ((document.getElementById('aicli-ssh-pubkey-input') || {}).value || '').trim();
        var label  = ((document.getElementById('aicli-ssh-key-label')    || {}).value || '').trim();
        if (!pubkey) { swal('No key', 'Paste a public key first.', 'warning'); return; }
        if (!label) label = 'Unnamed key';
        aicliSshAjax('add_key', { pubkey: pubkey, label: label }).then(function (data) {
            if (data.status === 'ok') {
                document.getElementById('aicli-ssh-pubkey-input').value = '';
                document.getElementById('aicli-ssh-key-label').value    = '';
                localStorage.setItem('aicli_has_ssh_key', '1');
                swal('Key added', 'Your SSH public key has been registered.', 'success');
                window.aicliLoadSshKeys();
            } else { swal('Error', data.message || 'Failed to add key.', 'error'); }
        }).catch(function () { swal('Error', 'Network error — could not add key.', 'error'); });
    };

    window.aicliRemoveSshKey = function (fingerprint) {
        swal({ title: 'Remove key?', text: 'This will delete the key from authorized_keys.',
               type: 'warning', showCancelButton: true, confirmButtonText: 'Remove' },
        function (confirmed) {
            if (!confirmed) return;
            aicliSshAjax('remove_key', { fingerprint: fingerprint }).then(function (data) {
                if (data.status === 'ok') { localStorage.removeItem('aicli_has_ssh_key'); window.aicliLoadSshKeys(); }
                else { swal('Error', data.message || 'Failed to remove key.', 'error'); }
            }).catch(function () { swal('Error', 'Network error — could not remove key.', 'error'); });
        });
    };

    window.aicliShowSshHelp = function () {
        if (document.getElementById('aicli-ssh-help-overlay')) {
            document.getElementById('aicli-ssh-help-overlay').remove(); return;
        }

        if (!document.getElementById('aicli-ssh-help-styles')) {
            var st = document.createElement('style');
            st.id = 'aicli-ssh-help-styles';
            st.textContent = [
                '.aicli-help-backdrop{position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:20px;}',
                '.aicli-help-modal{width:480px;max-width:100%;background:var(--background-color,#fff);border:1px solid var(--border-color,#ddd);border-radius:8px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.2);}',
                '.aicli-help-hdr{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:var(--title-header-background-color,#ebebeb);border-bottom:1px solid var(--border-color,#ddd);}',
                '.aicli-help-hdr-title{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--orange,#e68a00);}',
                '.aicli-help-x{all:unset !important;cursor:pointer !important;color:var(--alt-text-color,#888) !important;padding:3px 7px !important;border-radius:3px !important;font-size:14px !important;line-height:1 !important;background:transparent !important;border:0 !important;}',
                '.aicli-help-x:hover{background:var(--border-color,rgba(0,0,0,.08)) !important;color:var(--text-color,#222) !important;}',
                '.aicli-help-tabbar{display:flex;border-bottom:1px solid var(--border-color,#ddd);background:var(--mild-background-color,#f7f9f9);}',
                '.aicli-help-tab{all:unset !important;box-sizing:border-box !important;flex:1 !important;display:flex !important;align-items:center !important;justify-content:center !important;gap:5px !important;padding:10px 6px !important;border:0 !important;border-bottom:2px solid transparent !important;cursor:pointer !important;font-size:10px !important;font-weight:700 !important;letter-spacing:.07em !important;text-transform:uppercase !important;color:var(--disabled-text-color,#999) !important;background:transparent !important;transition:color .15s,border-color .15s,background .15s !important;}',
                '.aicli-help-tab:hover{color:var(--text-color,#333) !important;background:rgba(0,0,0,.04) !important;}',
                '.aicli-help-tab.ah-active{color:var(--orange,#e68a00) !important;border-bottom-color:var(--orange,#e68a00) !important;}',
                '.aicli-help-panels{padding:16px;background:var(--background-color,#fff);}',
                '.aicli-help-slabel{font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--alt-text-color,#999);margin-bottom:6px;margin-top:14px;}',
                '.aicli-help-slabel:first-child{margin-top:0;}',
                '.aicli-cmd-blk{display:flex;align-items:center;gap:8px;background:#0d0d0d;border-radius:5px;padding:10px 12px;font-family:monospace;font-size:12px;border:1px solid rgba(255,255,255,.07);transition:border-color .15s;}',
                '.aicli-cmd-blk:hover{border-color:var(--orange,#e68a00);}',
                '.aicli-cmd-prompt{color:var(--orange,#e68a00);opacity:.7;flex-shrink:0;}',
                '.aicli-cmd-txt{color:#e8e8e8;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
                '.aicli-cmd-cp{all:unset !important;flex-shrink:0 !important;cursor:pointer !important;color:rgba(255,255,255,.3) !important;padding:2px 6px !important;border:0 !important;border-radius:3px !important;font-size:12px !important;background:transparent !important;transition:color .15s !important;}',
                '.aicli-cmd-cp:hover{color:rgba(255,255,255,.8) !important;}',
                '.aicli-help-path{font-family:monospace;font-size:11px;padding:6px 10px;background:var(--mild-background-color,#f7f9f9);border-radius:4px;border:1px solid var(--border-color,#ddd);color:var(--text-color,#333);word-break:break-all;}',
                '.aicli-help-note{font-size:10px;color:var(--alt-text-color,#888);margin-top:12px;line-height:1.6;padding:8px 10px;border-radius:4px;border:1px solid var(--border-color,rgba(128,128,128,.15));background:var(--mild-background-color,rgba(128,128,128,.03));}',
                // Epic #307: at phone size every control is a 44 px touch target,
                // the dialog scrolls instead of being cut off, and a long command
                // wraps so the whole line can be read. Desktop is unchanged.
                '@media (max-width:600px){',
                '.aicli-help-backdrop{padding:12px;align-items:flex-start;overflow-y:auto;}',
                '.aicli-help-x,.aicli-cmd-cp{min-width:44px !important;min-height:44px !important;box-sizing:border-box !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;}',
                '.aicli-help-tab{min-height:44px !important;}',
                '.aicli-cmd-blk{padding:0 0 0 12px;}',
                '.aicli-cmd-txt{white-space:normal;word-break:break-all;padding:8px 0;}',
                // Readable text: the grey and orange-on-grey labels were below
                // the 4.5:1 contrast minimum. Orange stays on the icon and tab line.
                '.aicli-help-hdr-title,.aicli-help-slabel,.aicli-help-note,.aicli-help-tab,.aicli-help-tab.ah-active{color:var(--text-color,#222) !important;}',
                '.aicli-help-hdr-title i{color:var(--orange,#e68a00);}',
                '.aicli-help-note{font-size:12px;}',
                '.aicli-cmd-prompt{opacity:1;}',
                '.aicli-cmd-cp{color:rgba(255,255,255,.8) !important;}',
                '}'
            ].join('');
            document.head.appendChild(st);
        }

        // macOS and Linux use identical commands — one combined tab
        var OS = [
            { id:'win',  label:'Windows',      icons:['fa-windows'],
              generate:'ssh-keygen -t ed25519 -C "my-unraid-key"',
              display:'Get-Content "$env:USERPROFILE\\.ssh\\id_ed25519.pub"',
              path:'C:\\Users\\YourName\\.ssh\\id_ed25519.pub', shell:'PowerShell' },
            { id:'unix', label:'macOS / Linux', icons:['fa-apple','fa-linux'],
              generate:'ssh-keygen -t ed25519 -C "my-unraid-key"',
              display:'cat ~/.ssh/id_ed25519.pub',
              path:'~/.ssh/id_ed25519.pub', shell:'Terminal' }
        ];

        function mkSection(text) {
            var el = document.createElement('div');
            el.className = 'aicli-help-slabel';
            el.textContent = text;
            return el;
        }

        function mkCmdBlock(cmd) {
            var blk = document.createElement('div');
            blk.className = 'aicli-cmd-blk';
            blk.title = 'Click to copy';
            var prompt = document.createElement('span');
            prompt.className = 'aicli-cmd-prompt';
            prompt.textContent = '$';
            blk.appendChild(prompt);
            var code = document.createElement('span');
            code.className = 'aicli-cmd-txt';
            code.textContent = cmd;
            blk.appendChild(code);
            var cpBtn = document.createElement('button');
            cpBtn.type = 'button';
            cpBtn.className = 'aicli-cmd-cp';
            cpBtn.title = 'Copy';
            cpBtn.setAttribute('aria-label', 'Copy command');
            var cpIcon = document.createElement('i');
            cpIcon.className = 'fa fa-copy';
            cpBtn.appendChild(cpIcon);
            blk.appendChild(cpBtn);
            function doCopy(e) {
                e.stopPropagation();
                navigator.clipboard.writeText(cmd).then(function () {
                    cpIcon.className = 'fa fa-check';
                    cpBtn.style.color = '#6ee86e';
                    setTimeout(function () { cpIcon.className = 'fa fa-copy'; cpBtn.style.color = ''; }, 1500);
                }).catch(function () {});
            }
            blk.addEventListener('click', doCopy);
            cpBtn.addEventListener('click', doCopy);
            return blk;
        }

        var backdrop = document.createElement('div');
        backdrop.id = 'aicli-ssh-help-overlay';
        backdrop.className = 'aicli-help-backdrop';
        backdrop.addEventListener('click', function (e) { if (e.target === backdrop) backdrop.remove(); });

        var modal = document.createElement('div');
        modal.className = 'aicli-help-modal unapi';
        backdrop.appendChild(modal);

        // Header
        var hdr = document.createElement('div'); hdr.className = 'aicli-help-hdr';
        var hdrTitle = document.createElement('div'); hdrTitle.className = 'aicli-help-hdr-title';
        var hdrIcon = document.createElement('i'); hdrIcon.className = 'fa fa-key';
        hdrTitle.appendChild(hdrIcon);
        var hdrText = document.createElement('span'); hdrText.textContent = 'SSH Key Setup';
        hdrTitle.appendChild(hdrText);
        hdr.appendChild(hdrTitle);
        var xBtn = document.createElement('button'); xBtn.type = 'button'; xBtn.className = 'aicli-help-x'; xBtn.title = 'Close'; xBtn.setAttribute('aria-label', 'Close');
        var xIcon = document.createElement('i'); xIcon.className = 'fa fa-times'; xBtn.appendChild(xIcon);
        xBtn.addEventListener('click', function () { backdrop.remove(); });
        hdr.appendChild(xBtn);
        modal.appendChild(hdr);

        // Tab bar + panels
        var tabBar = document.createElement('div'); tabBar.className = 'aicli-help-tabbar';
        var panels = document.createElement('div'); panels.className = 'aicli-help-panels';
        var tabEls = [], panelEls = [];

        OS.forEach(function (os, i) {
            var tab = document.createElement('button'); tab.type = 'button';
            tab.className = 'aicli-help-tab' + (i === 0 ? ' ah-active' : '');
            os.icons.forEach(function (ic, ii) {
                if (ii > 0) { var sep = document.createElement('span'); sep.textContent = '/'; sep.style.cssText = 'opacity:.3;font-size:9px;margin:0 2px;'; tab.appendChild(sep); }
                var ti = document.createElement('i'); ti.className = 'fa ' + ic; tab.appendChild(ti);
            });
            var tl = document.createElement('span'); tl.textContent = os.label; tab.appendChild(tl);
            tab.addEventListener('click', function () {
                tabEls.forEach(function (t, j) { t.className = 'aicli-help-tab' + (j === i ? ' ah-active' : ''); });
                panelEls.forEach(function (p, j) { p.style.display = j === i ? '' : 'none'; });
            });
            tabEls.push(tab); tabBar.appendChild(tab);

            var panel = document.createElement('div');
            panel.style.display = i === 0 ? '' : 'none';

            panel.appendChild(mkSection('Step 1 — Generate a new key (' + os.shell + ')'));
            panel.appendChild(mkCmdBlock(os.generate));
            panel.appendChild(mkSection('Step 2 — Display your public key'));
            panel.appendChild(mkCmdBlock(os.display));
            panel.appendChild(mkSection('Public key file location'));
            var pathEl = document.createElement('div'); pathEl.className = 'aicli-help-path';
            pathEl.textContent = os.path; panel.appendChild(pathEl);
            var note = document.createElement('div'); note.className = 'aicli-help-note';
            var ni = document.createElement('i'); ni.className = 'fa fa-info-circle'; note.appendChild(ni);
            var nt = document.createElement('span');
            nt.textContent = ' The key file contains a single line starting with ssh-ed25519 or ssh-rsa — copy that whole line and paste it into the SSH Keys card.';
            note.appendChild(nt); panel.appendChild(note);

            panelEls.push(panel); panels.appendChild(panel);
        });

        modal.appendChild(tabBar);
        modal.appendChild(panels);
        document.body.appendChild(backdrop);
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.aicliLoadSshKeys();
        var cfgTab = document.querySelector('[data-tab="config"]');
        if (cfgTab) cfgTab.addEventListener('click', window.aicliLoadSshKeys);
    });
}());

/* ---------------------------------------------------------------------------
 * S-11 (#1355): storage target picker — ranked candidates from the
 * enumerate_storage_targets AJAX (StorageTargetService + probeTarget), plus a
 * 'Custom path…' escape hatch probed through the existing preflight_migrate.
 * Applying a pick sets the (read-only) path input and hands off to
 * saveAICliAgentsManager → the UNCHANGED preflight → swal → execute_migrate
 * migration flow. Read-only until the user confirms the migration swal.
 * ------------------------------------------------------------------------- */
(function () {
    'use strict';

    var state = { home: {}, agent: {} };

    function tok() { return window.csrf_token || window.csrf || ''; }
    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    function fmtBytes(b) {
        b = Number(b) || 0;
        if (b >= 1099511627776) return (b / 1099511627776).toFixed(1) + ' TB';
        if (b >= 1073741824)   return (b / 1073741824).toFixed(1) + ' GB';
        if (b >= 1048576)      return (b / 1048576).toFixed(0) + ' MB';
        return b + ' B';
    }

    var WARN_LABEL = {
        // Bug #297: plain words, not the internal warning code. The full
        // explanation is userShareAdvice() below — this chip is just the label.
        via_user_share:    'on /mnt/user — see advice below',
        user_share:        'on /mnt/user — see advice below',
        resolved_via_share_config: 'stored on the pool, not /mnt/user',
        array_rotational:  'HDD — spins on every persist',
        posix_none:        'no symlinks/xattrs',
        facts_uncertain:   'device facts uncertain',
        network_target:    'network share',
        rejected_for_home: 'not allowed for home storage',
        remote_agent_warn: 'remote — agents only, not recommended',
        volatile_target:   'RAM-backed — data lost on reboot',
        probe_unavailable: 'probe unavailable'
    };

    // Epic #307: --chip-fg carries the hue so the phone-size rule in
    // ManagerStyles.php (.aicli-sp-chip) can mix it toward the theme's text
    // colour for readable contrast; desktop still paints fg as before.
    function chip(text, fg, bg) {
        return '<span class="aicli-sp-chip" style="--chip-fg:' + fg + '; display:inline-block; font-size:9px; padding:1px 6px; border-radius:8px; margin:1px 3px 1px 0; color:' + fg + '; background:' + bg + '; white-space:nowrap;">' + esc(text) + '</span>';
    }
    function warnChips(warnings) {
        var html = '';
        $.each(warnings || [], function (i, w) {
            html += chip(WARN_LABEL[w] || w, '#eab308', 'rgba(234,179,8,0.12)');
        });
        return html;
    }
    function errBox(msg) {
        return '<div style="padding:8px 10px; font-size:11px; color:#f87171; background:rgba(248,113,113,0.08); border-radius:4px;"><i class="fa fa-exclamation-circle"></i> ' + esc(msg) + '</div>';
    }

    /**
     * Bug #297: the plain-English advice for a target that stays on
     * /mnt/user (Unraid's shared-folder layer, "shfs") — shown ONLY when the
     * path is NOT already resolved onto a direct pool path (a resolved path
     * carries its own "will be stored as…" note instead, since its data
     * never touches shfs). John's direction: /mnt/user stays a valid choice
     * — this explains why to avoid it when another path is available, and
     * says plainly that it is fine when it is the only option.
     */
    function userShareAdvice() {
        return '<div style="font-size:10px; margin-top:3px; padding:6px 8px; border-radius:4px; background:rgba(234,179,8,0.10); border:1px solid rgba(234,179,8,0.35);">'
            + '<i class="fa fa-info-circle"></i> This path uses <code>/mnt/user</code>, Unraid\'s shared-folder layer ("shfs"). '
            + 'Heavy save or merge activity through shfs can slow down or freeze the whole server. '
            + 'Prefer a pool path (<code>/mnt/cache/…</code>), an Unassigned Device path (<code>/mnt/disks/…</code>), '
            + 'or a single-disk path (<code>/mnt/diskN/…</code>) when one of those is available. '
            + '<code>/mnt/user</code> is fine to use when no other path is available to you.'
            + '</div>';
    }
    /** True when $t is a genuinely still-FUSE /mnt/user target (unresolved). */
    function isUnresolvedUserShare(t) {
        return (t.warnings || []).indexOf('via_user_share') !== -1 && !t.note;
    }

    window.aicliToggleStoragePicker = function (kind) {
        var panel = $('#aicli-storage-picker-' + kind);
        if (panel.is(':visible')) { panel.hide().empty(); return; }
        state[kind] = {};
        panel.show().html('<div style="padding:10px; font-size:11px; opacity:.6;"><i class="fa fa-spinner fa-spin"></i> Probing storage targets…</div>');
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=enumerate_storage_targets&kind=' + encodeURIComponent(kind) + '&csrf_token=' + encodeURIComponent(tok()), function (data) {
            if (data.status !== 'ok') { panel.html(errBox(data.message || 'Enumeration failed')); return; }
            renderPicker(panel, kind, data.targets || []);
        }).fail(function () { panel.html(errBox('Server error during target enumeration')); });
    };

    function candidateRow(kind, t) {
        var disabled = t.refuse ? ' disabled' : '';
        var badges = '';
        if (t.recommended) badges += chip('recommended', '#22c55e', 'rgba(34,197,94,0.14)');
        if (t.current)     badges += chip('current', 'var(--alt-text-color, #888)', 'rgba(128,128,128,0.15)');
        if (t.advanced)    badges += chip('advanced', '#a78bfa', 'rgba(167,139,250,0.12)');
        if (t.refuse)      badges += chip('unavailable', '#f87171', 'rgba(248,113,113,0.12)');
        var meta = chip(t.mount_class, '#3b82f6', 'rgba(59,130,246,0.10)')
                 + chip(fmtBytes(t.free_bytes) + ' free', 'var(--alt-text-color, #888)', 'rgba(128,128,128,0.10)')
                 + warnChips(t.warnings);
        var note = t.note ? '<div style="font-size:10px; opacity:.6; margin-top:1px;"><i class="fa fa-link"></i> ' + esc(t.note) + '</div>' : '';
        var advice = isUnresolvedUserShare(t) ? userShareAdvice() : '';
        return '<label style="display:flex; gap:8px; align-items:flex-start; padding:6px 8px; border:1px solid var(--border-color, rgba(128,128,128,0.25)); border-radius:4px; margin-bottom:4px; cursor:' + (t.refuse ? 'not-allowed' : 'pointer') + ';' + (t.refuse ? ' opacity:.55;' : '') + '">'
            + '<input type="radio" name="aicli-target-' + kind + '" value="' + esc(t.path) + '" data-picked-via="' + esc(t.picked_via || '') + '" style="margin-top:3px;"' + disabled + (t.current && !t.refuse ? ' checked' : '') + '>'
            + '<div style="flex:1; min-width:0;">'
            +   '<div style="font-size:12px; font-weight:600;">' + esc(t.label) + ' ' + badges + '</div>'
            +   '<div style="font-family:monospace; font-size:10px; opacity:.75; word-break:break-all;">' + esc(t.path) + '</div>'
            +   note
            +   '<div style="margin-top:2px;">' + meta + '</div>'
            +   advice
            + '</div></label>';
    }

    function renderPicker(panel, kind, targets) {
        var html = '<div style="border:1px solid var(--border-color, rgba(128,128,128,0.25)); border-radius:5px; padding:8px; background:var(--mild-background-color, rgba(128,128,128,0.04));">';
        $.each(targets, function (i, t) { html += candidateRow(kind, t); });
        // Custom path escape hatch — probed through preflight_migrate on change/blur
        html += '<label style="display:flex; gap:8px; align-items:center; padding:6px 8px; border:1px dashed var(--border-color, rgba(128,128,128,0.25)); border-radius:4px; cursor:pointer;">'
            + '<input type="radio" name="aicli-target-' + kind + '" value="__custom__">'
            + '<span style="font-size:12px; font-weight:600;"><i class="fa fa-pencil"></i> Custom path…</span></label>'
            + '<div id="aicli-custom-row-' + kind + '" style="display:none; margin:4px 0 0 24px;">'
            +   '<div style="display:flex; gap:6px;">'
            +     '<input type="text" id="aicli-custom-path-' + kind + '" placeholder="/mnt/…" style="flex:1; min-width:0; font-family:monospace; font-size:11px;">'
            +     '<button type="button" class="aicli-btn-slim" id="aicli-custom-browse-' + kind + '" title="Browse"><i class="fa fa-folder-open"></i></button>'
            +   '</div>'
            +   '<div id="aicli-custom-result-' + kind + '" style="font-size:10px; margin-top:3px; min-height:14px;"></div>'
            + '</div>'
            + '<div style="display:flex; gap:8px; justify-content:flex-end; margin-top:8px;">'
            +   '<button type="button" class="aicli-btn-slim" id="aicli-picker-cancel-' + kind + '">Cancel</button>'
            +   '<button type="button" class="aicli-btn-slim" id="aicli-picker-apply-' + kind + '" style="font-weight:700;"><i class="fa fa-truck"></i> Move storage here</button>'
            + '</div></div>';
        panel.html(html);

        panel.find('input[name="aicli-target-' + kind + '"]').on('change', function () {
            $('#aicli-custom-row-' + kind).toggle($(this).val() === '__custom__');
        });
        $('#aicli-custom-path-' + kind).on('blur change', function () { probeCustom(kind); });
        $('#aicli-custom-browse-' + kind).on('click', function () { openPathPicker('aicli-custom-path-' + kind); });
        $('#aicli-picker-cancel-' + kind).on('click', function () { panel.hide().empty(); });
        $('#aicli-picker-apply-' + kind).on('click', function () { applyPick(kind); });
    }

    function probeCustom(kind) {
        var typed = ($('#aicli-custom-path-' + kind).val() || '').trim();
        var box = $('#aicli-custom-result-' + kind);
        state[kind] = {};
        if (!typed) { box.empty(); return; }
        var h = (kind === 'home')  ? typed : ($('#home_storage_path').val() || '');
        var a = (kind === 'agent') ? typed : ($('#agent_storage_path').val() || '');
        box.html('<i class="fa fa-spinner fa-spin"></i> Probing…');
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=preflight_migrate&agent_storage_path=' + encodeURIComponent(a) + '&home_storage_path=' + encodeURIComponent(h) + '&csrf_token=' + encodeURIComponent(tok()), function (pf) {
            if (pf.status !== 'ok') {
                state[kind] = { error: pf.message || 'Target rejected' };
                box.html('<span style="color:#f87171;"><i class="fa fa-times-circle"></i> ' + esc(state[kind].error) + '</span>');
                return;
            }
            var warns = (pf.warnings && pf.warnings[kind]) || [];
            var resolved = pf['resolved_' + kind + '_path'] || null;
            state[kind] = { resolved: resolved };
            var html = '<span style="color:#22c55e;"><i class="fa fa-check-circle"></i> Valid target</span> ' + warnChips(warns);
            if (resolved) {
                // Bug #297: this share's data is on one pool (either the
                // OS-level exclusive-share bypass, or the share's own
                // useCache="only" config) — the resolved pool path never
                // touches /mnt/user, so no ADVICE box is needed here.
                html += '<div style="opacity:.7; margin-top:2px;"><i class="fa fa-link"></i> This share\'s data is on one pool — will be stored as <code>' + esc(resolved) + '</code></div>';
            } else if (warns.indexOf('via_user_share') !== -1) {
                html += userShareAdvice();
            }
            box.html(html);
        }).fail(function () {
            state[kind] = { error: 'Server error during probe' };
            box.html('<span style="color:#f87171;">Server error during probe</span>');
        });
    }

    function applyPick(kind) {
        var sel = $('input[name="aicli-target-' + kind + '"]:checked');
        if (!sel.length) { swal('No target', 'Select a storage target first.', 'warning'); return; }
        var path, pickedVia = '';
        if (sel.val() === '__custom__') {
            var typed = ($('#aicli-custom-path-' + kind).val() || '').trim();
            if (!typed) { swal('No path', 'Enter a custom path first.', 'warning'); return; }
            if (state[kind].error) { swal('Invalid target', state[kind].error, 'error'); return; }
            path = state[kind].resolved || typed;
            if (state[kind].resolved) pickedVia = typed;
        } else {
            path = sel.val();
            pickedVia = sel.attr('data-picked-via') || '';
        }
        $('#' + kind + '_storage_path').val(path);
        if (pickedVia) $('#storage_picked_via').val(pickedVia);
        $('#aicli-storage-picker-' + kind).hide().empty();
        // Same machinery as before the picker existed: preflight → confirm swal
        // (size/disk-space summary) → execute_migrate with Nchan progress.
        saveAICliAgentsManager(document.getElementById('aicli-settings-form'), false);
    }
}());
</script>

<script>
/* Settings card columns (see .aicli-config-grid in ManagerStyles.php). Each
   card goes into the shortest of N equal columns, N from the grid width at
   400 px per column plus the 20 px gap, so the page width is used and no
   card is taller than its content. Cards are MOVED, never cloned, so their
   handlers survive. Runs when the tab is shown and again when the width
   changes the column count. */
(function () {
    'use strict';
    var COL_MIN = 400, GAP = 20;
    var grid = null, cards = [], lastN = 0, lastW = 0;

    function collectCards() {
        var list = grid.querySelectorAll('.aicli-card');
        return Array.prototype.filter.call(list, function (c) {
            return c.parentNode === grid || (c.parentNode && c.parentNode.classList.contains('aicli-config-col'));
        });
    }

    function layout(force) {
        if (!grid) return;
        var w = grid.clientWidth;
        if (!w) return; // the tab is hidden; layout when it is shown
        var n = Math.max(1, Math.floor((w + GAP) / (COL_MIN + GAP)));
        if (!force && n === lastN && Math.abs(w - lastW) < 2) return;
        lastN = n; lastW = w;
        if (!cards.length) cards = collectCards();
        cards.forEach(function (c) { grid.appendChild(c); });
        Array.prototype.forEach.call(grid.querySelectorAll('.aicli-config-col'), function (col) { col.parentNode.removeChild(col); });
        if (n === 1) { grid.classList.remove('aicli-config-grid--cols'); return; }
        var cols = [];
        for (var i = 0; i < n; i++) {
            var d = document.createElement('div');
            d.className = 'aicli-config-col';
            grid.appendChild(d);
            cols.push(d);
        }
        grid.classList.add('aicli-config-grid--cols');
        cards.forEach(function (c) {
            var best = cols[0];
            for (var k = 1; k < cols.length; k++) {
                if (cols[k].offsetHeight < best.offsetHeight) best = cols[k];
            }
            best.appendChild(c);
        });
    }

    function init() {
        grid = document.querySelector('#tab-config .aicli-config-grid');
        if (!grid) return;
        layout(true);
        var timer = null;
        window.addEventListener('resize', function () {
            if (timer) clearTimeout(timer);
            timer = setTimeout(function () { layout(false); }, 120);
        });
        // The tab is display:none until its button is clicked: lay out on show.
        var tab = document.getElementById('tab-config');
        if (tab && typeof MutationObserver !== 'undefined') {
            new MutationObserver(function () {
                if (tab.classList.contains('active')) layout(true);
            }).observe(tab, { attributes: true, attributeFilter: ['class'] });
        }
        window.aicliConfigColumns = function () { layout(true); };
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>
