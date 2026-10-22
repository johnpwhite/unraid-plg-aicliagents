<?php
/**
 * <module_context>
 * Description: HTML layout for the Configuration tab in AICliAgents Manager.
 * Dependencies: $config, $csrf_token, $users.
 * Constraints: Atomic UI fragment (< 150 lines).
 * </module_context>
 */
require_once __DIR__ . '/SettingsCard.php'; // aicli_help_btn(), aicli_help_panel(): the help is behind an (i).
require_once __DIR__ . '/SettingsAdvanced.php'; // aicli_adv_open(), aicli_adv_close(): the Advanced part of a card.
$autoSave = 'onchange="autoSaveConfig()"';


// SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md Phase 3 and Phase 5: the one-line status of each card, built here
// from $config. The same format sits in data-aicli-status-format, so assets/ui/settings-cards.js can
// rebuild it from the form after a save. A status that starts with "Off" makes its card start collapsed.
$cardOnOff = static fn($v, string $default): string => (string)($v ?? $default) === '1' ? 'On' : 'Off';
$stGeneral = 'Tab shown: ' . ((string)($config['enable_tab'] ?? '1') === '0' ? 'No' : 'Yes')
    . ' · user ' . (string)($config['user'] ?? 'root') . ' · ' . (string)($config['root_path'] ?? '/mnt/user');
$cardKindWord = static fn($v): string => (string)($v ?? 'layering') === 'passthrough' ? 'Plain folders' : 'Compressed layers';
$stStorage = 'Homes: ' . $cardKindWord($config['storage_backend_homes'] ?? null)
    . ' · Agents: ' . $cardKindWord($config['storage_backend_agents'] ?? null);
// The row "Save unsaved home changes every" has no use when every home is a plain folder. The saved kind is the
// first guess; the page script (aicliRenderKindState) hides or shows the row again from the real storage status.
$bakeHidden = (string)($config['storage_backend_homes'] ?? 'layering') === 'passthrough';
$stTerminal = ucfirst((string)($config['theme'] ?? 'dark')) . ' · ' . (string)($config['font_size'] ?? 12) . ' px';
$cardModelError = ['recommended' => 'Verified agents', 'all' => 'All agents', 'off' => 'Off'];
$stContinue = 'After a restart: ' . (\AICliAgents\Services\ConfigService::autoContinueOnRestart() ? 'On' : 'Off')
    . ' · After a model error: ' . ($cardModelError[\AICliAgents\Services\TransientErrorService::settings($config)['mode']] ?? 'Verified agents');
$stRelayNotices = ucfirst((string)($config['relay_notice_style'] ?? 'short')) . ' · resend after ' . (string)($config['relay_notice_resend_minutes'] ?? '10') . ' min';
$cardTtsUrl = trim((string)($config['tts_url'] ?? ''));
$cardTtsHost = $cardTtsUrl === '' ? '' : (string)(parse_url($cardTtsUrl, PHP_URL_HOST) ?: $cardTtsUrl);
$stVoice = $cardOnOff($config['voice_enabled'] ?? null, '0')
    . ($cardTtsHost !== '' ? ' · voice server ' . $cardTtsHost : ((string)($config['voice_enabled'] ?? '0') === '1' ? ' · browser voice' : ''));
$stNotebook = $cardOnOff($config['notebook_enabled'] ?? null, '1') . ' · wiki page ' . $cardOnOff($config['wiki_page_enabled'] ?? null, '0');
?>
<!-- TAB 1: CONFIGURATION -->
<div id="tab-config" class="aicli-tab-content active aicli-layout">
    <?php /* Phase 3: the layout note and the Reset layout button. The live region says "Saved" after a silent save. */ ?>
    <div class="aicli-settings-toolbar">
        <span class="aicli-settings-note" data-testid="settings-save-note">Changes save as you make them.</span>
        <button type="button" class="aicli-btn-slim" data-aicli-card-action="reset-layout" data-testid="reset-layout">Reset layout</button>
        <div id="aicli-settings-live" class="aicli-sr-only" aria-live="polite" aria-atomic="true" data-testid="settings-live"></div>
    </div>
    <div class="aicli-config-grid" data-aicli-cards="config">

            <?php /* Phase 5: nine cards. Rows moved between cards; every control keeps its name, id, data-testid and aria-label. */ ?>
            <?=aicli_card_open('general', 'fa-globe', 'General', $stGeneral, 'card-general', '',
                ['format' => 'Tab shown: {enable_tab} · user {user} · {root_path}'])?>
                    <dl>
                        <dt>Show the AI CLI Agents tab <?=aicli_help_btn('enable-tab', 'Show the AI CLI Agents tab')?></dt>
                        <dd>
                            <select name="enable_tab" aria-label="Show the AI CLI Agents tab" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['enable_tab'], "1", _('Yes'))?>
                                <?=mk_option($config['enable_tab'], "0", _('No'))?>
                            </select>
                            <?=aicli_help_panel('enable-tab')?>
                        </dd>

                        <dt>Terminal user <?=aicli_help_btn('terminal-user', 'Terminal user')?></dt>
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
                            <?=aicli_help_panel('terminal-user')?>
                        </dd>

                        <dt>Starting folder <?=aicli_help_btn('root-path', 'Starting folder')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" name="root_path" id="root_path" aria-label="Workspace root path" value="<?=htmlspecialchars($config['root_path'] ?? '/mnt/user', ENT_QUOTES, 'UTF-8')?>" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                <button type="button" class="aicli-btn-slim" onclick="openPathPicker('root_path')" title="Browse"><i class="fa fa-folder-open"></i></button>
                            </div>
                            <?=aicli_help_panel('root-path')?>
                        </dd>

                        <!-- WORKSPACE_UPLOAD_MULTI_CHUNKED.md R3: the setting is stored in bytes
                             (upload_max_bytes) but shown and edited here in MB. The visible field
                             has no name attribute, so the form never submits it directly; onchange
                             writes the byte value into the hidden field, which autoSaveConfig()
                             then serializes and saves like every other setting on this tab. -->
                        <dt>Largest upload <?=aicli_help_btn('upload-max', 'Largest upload')?></dt>
                        <dd>
                            <?php
                            $uploadMaxBytes = (int)($config['upload_max_bytes'] ?? 536870912);
                            $uploadMaxMb = $uploadMaxBytes > 0 ? (int) round($uploadMaxBytes / 1048576) : 0;
                            ?>
                            <div class="input-row">
                                <input type="number" id="upload_max_mb" aria-label="Largest upload in megabytes" value="<?=$uploadMaxMb?>" min="0" step="1" style="width:100px !important; flex-shrink:0;" onchange="aicliApplyUploadMaxBytes(this.value)">
                                <span style="opacity:0.75; font-size:11px;">MB · 0 = no limit</span>
                            </div>
                            <input type="hidden" name="upload_max_bytes" id="upload_max_bytes" value="<?=$uploadMaxBytes?>">
                            <?=aicli_help_panel('upload-max')?>
                        </dd>
                        <script>
                        function aicliApplyUploadMaxBytes(mb) {
                            var n = Math.max(0, parseInt(mb, 10) || 0);
                            document.getElementById('upload_max_bytes').value = n > 0 ? (n * 1048576) : 0;
                            autoSaveConfig();
                        }
                        </script>
                    </dl>
            <?=aicli_adv_open('general', 'General')?>
                    <dl>
                        <dt>Log detail <?=aicli_help_btn('log-level', 'Log detail')?></dt>
                        <dd>
                            <select name="log_level" aria-label="Log detail" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['log_level'], "0", _('Errors only'))?>
                                <?=mk_option($config['log_level'], "1", _('Warnings'))?>
                                <?=mk_option($config['log_level'], "2", _('Normal'))?>
                                <?=mk_option($config['log_level'], "3", _('Debug'))?>
                            </select>
                            <?=aicli_help_panel('log-level')?>
                        </dd>

                        <dt>Check for new agent versions <?=aicli_help_btn('version-check-schedule', 'Check for new agent versions')?></dt>
                        <dd>
                            <select name="version_check_schedule" aria-label="Check for new agent versions" <?=$autoSave?>>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 */6 * * *', 'Every 6 hours')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 6 * * *', 'Daily at 6am')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 6 * * 1', 'Weekly (Monday 6am)')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '', 'Never')?>
                            </select>
                            <?=aicli_help_panel('version-check-schedule')?>
                        </dd>

                        <dt>Keep version check results for <?=aicli_help_btn('version-check-months', 'Keep version check results for')?></dt>
                        <dd>
                            <select name="version_check_months" aria-label="Version history retention in months" <?=$autoSave?>>
                                <?=mk_option($config['version_check_months']??'3', '1', '1 month')?>
                                <?=mk_option($config['version_check_months']??'3', '3', '3 months')?>
                                <?=mk_option($config['version_check_months']??'3', '6', '6 months')?>
                                <?=mk_option($config['version_check_months']??'3', '12', '12 months')?>
                            </select>
                            <?=aicli_help_panel('version-check-months')?>
                        </dd>

                        <?php /* PLUGIN_MANAGEMENT_TOOLS.md Phase 1: the one master switch for the read-only admin MCP tool catalogue (AdminMcpTools). It saves through the same autoSaveConfig()/`save` path as every other select, so ConfigService::saveConfig() calls AdminMcpTools::ensureMcpRegistered() when it changes. */ ?>
                        <dt>Let agents manage the plugin <?=aicli_help_btn('admin-tools', 'Let agents manage the plugin')?></dt>
                        <dd>
                            <select name="admin_tools_enabled" aria-label="Let agents manage the plugin"style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['admin_tools_enabled'] ?? '0', "1", _('On'))?>
                                <?=mk_option($config['admin_tools_enabled'] ?? '0', "0", _('Off (default)'))?>
                            </select>
                            <?=aicli_help_panel('admin-tools')?>
                        </dd>

                        <?php /* AGENT_SPAWN_WORKSPACE.md: the master switch, the three caps and the unattended switch of the helper-workspace tools. A person sets them here; none is on the agent allow-list. They save through the same autoSaveConfig()/`save` path, and ConfigService::saveConfig() replaces a bad value. */ ?>
                        <dt>Let agents start helper workspaces <?=aicli_help_btn('spawn-enabled', 'Let agents start helper workspaces')?></dt>
                        <dd>
                            <select name="spawn_enabled" aria-label="Let agents start helper workspaces" data-testid="spawn-enabled" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['spawn_enabled'] ?? '0', "1", _('On'))?>
                                <?=mk_option($config['spawn_enabled'] ?? '0', "0", _('Off (default)'))?>
                            </select>
                            <?=aicli_help_panel('spawn-enabled')?>
                        </dd>

                        <dt>Helpers allowed at once <?=aicli_help_btn('spawn-caps', 'Helpers allowed at once')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="spawn_max_children" aria-label="Helpers for each workspace" data-testid="spawn-max-children" value="<?=htmlspecialchars((string)($config['spawn_max_children'] ?? '3'), ENT_QUOTES, 'UTF-8')?>" min="1" max="10" style="width: 60px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">per workspace ·</span>
                                <input type="number" name="spawn_max_per_agent" aria-label="Helpers for each agent kind" data-testid="spawn-max-per-agent" value="<?=htmlspecialchars((string)($config['spawn_max_per_agent'] ?? '2'), ENT_QUOTES, 'UTF-8')?>" min="1" max="10" style="width: 60px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">per agent ·</span>
                                <input type="number" name="spawn_max_total" aria-label="Helpers on this server" data-testid="spawn-max-total" value="<?=htmlspecialchars((string)($config['spawn_max_total'] ?? '6'), ENT_QUOTES, 'UTF-8')?>" min="1" max="20" style="width: 60px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">in total</span>
                            </div>
                            <?=aicli_help_panel('spawn-caps')?>
                        </dd>

                        <dt>Let helpers run without asking <?=aicli_help_btn('spawn-unattended', 'Let helpers run without asking')?></dt>
                        <dd>
                            <select name="spawn_allow_unattended" aria-label="Let helpers run without asking" data-testid="spawn-allow-unattended" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['spawn_allow_unattended'] ?? '0', "1", _('On'))?>
                                <?=mk_option($config['spawn_allow_unattended'] ?? '0', "0", _('Off (default)'))?>
                            </select>
                            <?=aicli_help_panel('spawn-unattended')?>
                        </dd>

                        <?php /* PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md (2026-09-11): per-browser
                           only — stored in localStorage, never sent to the server on its own, and
                           never round-tripped through this form's own action=save. It travels only
                           as the X-AICli-Device header/aicli_device param on every OTHER AJAX call
                           this browser makes (CommonLogging.php's aicliAjax(), ajaxUrl() in the SPA),
                           so an event this browser causes can say which device it came from. */ ?>
                        <dt>Device name (this browser) <?=aicli_help_btn('device-label', 'Device name (this browser)')?></dt>
                        <dd>
                            <input type="text" id="aicli_device_label" aria-label="Device name"maxlength="40"
                                   placeholder="for example phone, laptop" style="flex: 1; min-width: 0;"
                                   oninput="window.aicliSaveDeviceLabel(this.value)">
                            <?=aicli_help_panel('device-label')?>
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
            <?=aicli_adv_close()?>
            <?=aicli_card_close()?>

            <?=aicli_card_open('storage', 'fa-hdd-o', 'Storage', $stStorage, 'card-storage', '',
                ['format' => 'Homes: {storage_backend_homes} · Agents: {storage_backend_agents}'])?>
                    <dl>
                        <?php // GLOBAL_STORAGE_BACKEND_MIGRATION.md: two settings, one per kind. Each line below the select shows the REAL state. ?>
                        <dt>Home storage <?=aicli_help_btn('storage-backend-homes', 'Home storage')?></dt>
                        <dd>
                            <select name="storage_backend_homes" aria-label="Home storage" data-testid="storage-backend-homes" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['storage_backend_homes'] ?? 'layering', 'layering', _('Compressed layers'))?>
                                <?=mk_option($config['storage_backend_homes'] ?? 'layering', 'passthrough', _('Plain folders'))?>
                            </select>
                            <div id="aicli-kind-state-homes" data-testid="storage-kind-state-homes" role="status" aria-live="polite" style="font-size:12px; margin-top:4px;">Checking the real state…</div>
                            <?=aicli_help_panel('storage-backend-homes')?>
                        </dd>

                        <dt>Agent storage <?=aicli_help_btn('storage-backend-agents', 'Agent storage')?></dt>
                        <dd>
                            <select name="storage_backend_agents" aria-label="Agent storage" data-testid="storage-backend-agents" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['storage_backend_agents'] ?? 'layering', 'layering', _('Compressed layers'))?>
                                <?=mk_option($config['storage_backend_agents'] ?? 'layering', 'passthrough', _('Plain folders'))?>
                            </select>
                            <div id="aicli-kind-state-agents" data-testid="storage-kind-state-agents" role="status" aria-live="polite" style="font-size:12px; margin-top:4px;">Checking the real state…</div>
                            <div id="aicli-backend-migration-status" data-aicli-warns-card="storage" role="status" aria-live="polite" hidden style="font-size:12px; margin-top:8px; padding:8px 10px; border:1px solid var(--border-color, #aaa); border-radius:4px;"></div>
                            <?=aicli_help_panel('storage-backend-agents')?>
                        </dd>

                        <dt>Where homes are stored <?=aicli_help_btn('home-storage-path', 'Where homes are stored')?></dt>
                        <dd>
                            <?php /* S-11 (#1355): storage target picker. The path input is read-only —
                               the value is set by the picker (ranked candidates from
                               enumerate_storage_targets, or a probed custom path) and routes through
                               the EXISTING preflight_migrate → swal → execute_migrate flow via
                               saveAICliAgentsManager. */ ?>
                            <div class="input-row">
                                <input type="text" name="home_storage_path" id="home_storage_path" readonly value="<?=htmlspecialchars(\AICliAgents\Services\StoragePathResolver::homePersistPathFromConfig($config), ENT_QUOTES, 'UTF-8')?>" style="flex: 1; min-width: 0; opacity: 0.85;" title="Current home storage location — use Change to move it">
                                <button type="button" class="aicli-btn-slim" onclick="aicliToggleStoragePicker('home')" title="Choose a storage target"><i class="fa fa-exchange"></i> Change…</button>
                            </div>
                            <?=aicli_help_panel('home-storage-path')?>
                            <div id="aicli-storage-picker-home" class="aicli-storage-picker" style="display:none; width:100%; margin-top:4px;"></div>
                            <?php $homeClass = \AICliAgents\Services\StorageMountService::classifyPath($config['home_storage_path'] ?? ''); ?>
                            <?php if ($homeClass === 'array'): ?>
                                <div style="font-size:10px; color:#eab308; margin-top:2px; padding:3px 6px; background:rgba(234,179,8,0.08); border-radius:3px; display:flex; align-items:center; gap:4px; width:100%;"><i class="fa fa-exclamation-triangle"></i> On array — unavailable when stopped. Emergency mode will activate.</div>
                            <?php elseif (strpos($homeClass, 'pool:') === 0): ?>
                                <div style="font-size:10px; color:#3b82f6; margin-top:2px; padding:3px 6px; background:rgba(59,130,246,0.08); border-radius:3px; display:flex; align-items:center; gap:4px; width:100%;"><i class="fa fa-info-circle"></i> On pool '<?=htmlspecialchars(substr($homeClass, 5), ENT_QUOTES, 'UTF-8')?>' — unavailable if pool is stopped.</div>
                            <?php endif; ?>
                        </dd>

                        <dt>Where agents are stored <?=aicli_help_btn('agent-storage-path', 'Where agents are stored')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" name="agent_storage_path" id="agent_storage_path" readonly value="<?=htmlspecialchars($config['agent_storage_path'] ?? \AICliAgents\Services\SecretPaths::flashRoot() . '/persistence', ENT_QUOTES, 'UTF-8')?>" style="flex: 1; min-width: 0; opacity: 0.85;" title="Current agent storage location — use Change to move it">
                                <button type="button" class="aicli-btn-slim" onclick="aicliToggleStoragePicker('agent')" title="Choose a storage target"><i class="fa fa-exchange"></i> Change…</button>
                            </div>
                            <?=aicli_help_panel('agent-storage-path')?>
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

                        <?php /* BOOT_DISK_SPACE.md R4 */ ?>
                        <dt>Keep the previous agent version <?=aicli_help_btn('keep-previous-version', 'Keep the previous agent version')?></dt>
                        <dd>
                            <select name="keep_previous_version" aria-label="Keep the previous agent version" data-testid="keep-previous-version" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['keep_previous_version'] ?? '1', '1', _('Yes'))?>
                                <?=mk_option($config['keep_previous_version'] ?? '1', '0', _('No'))?>
                            </select>
                            <?=aicli_help_panel('keep-previous-version')?>
                        </dd>
                    </dl>
            <?=aicli_adv_open('storage', 'Storage')?>
                    <?php /* Phase 5: hidden when every home is a plain folder. The saved kind decides at render time (this style rule, no flash); aicliRenderKindState() in ManagerScripts.php then follows the real storage status. */ ?>
                    <?php if ($bakeHidden): ?><style id="aicli-bake-hide">[data-aicli-hide-plain-homes] { display: none !important; }</style><?php endif; ?>
                    <dl>
                        <dt data-aicli-hide-plain-homes>Save unsaved home changes every <?=aicli_help_btn('bake-schedule', 'Save unsaved home changes every')?></dt>
                        <dd data-aicli-hide-plain-homes>
                            <div class="input-row">
                                <?php
                                // #153: this control drives the REAL automatic-save cadence the
                                // storage supervisor reads (bake_schedule_minutes). The old
                                // sync_interval_hours/mins selects were wired to nothing.
                                $bakeEvery = (string)($config['bake_schedule_minutes'] ?? '120');
                                $bakeChoices = ['15' => 'Every 15 minutes', '30' => 'Every 30 minutes', '60' => 'Every hour', '120' => 'Every 2 hours', '240' => 'Every 4 hours', '480' => 'Every 8 hours', '720' => 'Every 12 hours', '1440' => 'Once a day'];
                                if (!isset($bakeChoices[$bakeEvery]) && ctype_digit($bakeEvery)) $bakeChoices[$bakeEvery] = "Every $bakeEvery minutes";
                                ?>
                                <select name="bake_schedule_minutes" aria-label="Save unsaved home changes every" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                    <?php foreach ($bakeChoices as $mins => $label): echo mk_option($bakeEvery, (string)$mins, $label); endforeach; ?>
                                </select>
                                <button type="button" class="aicli-btn-slim" onclick="persistEntity('home', activeTerminalUser)"><i class="fa fa-save"></i> Save now</button>
                            </div>
                            <?=aicli_help_panel('bake-schedule')?>
                        </dd>

                        <dt>Where unsaved changes are kept <?=aicli_help_btn('storage-upper-mode', 'Where unsaved changes are kept')?></dt>
                        <dd>
                            <select name="storage_upper_mode" aria-label="Where unsaved changes are kept" data-testid="storage-upper-mode" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['storage_upper_mode'] ?? 'zram', 'zram', _('Memory (zram): fast, no disk wear, lost on a crash until saved'))?>
                                <?=mk_option($config['storage_upper_mode'] ?? 'zram', 'disk', _('Disk: survives a crash or power cut, uses space on the persistence drive'))?>
                            </select>
                            <?=aicli_help_panel('storage-upper-mode')?>
                        </dd>

                        <dt style="align-self: flex-start; padding-top: 6px;">Merge layers after <?=aicli_help_btn('consolidate-max-layers', 'Merge layers after')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="consolidate_max_layers" aria-label="Merge layers after"
                                       value="<?=\AICliAgents\Services\ConfigService::getConsolidateMaxLayers()?>"
                                       min="4" max="40" step="1" style="width: 90px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="font-size:11px; opacity:0.7; margin-left:8px;">layers</span>
                            </div>
                            <?=aicli_help_panel('consolidate-max-layers')?>
                        </dd>

                        <?php /* #496 (CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R6): the setting
                           consolidate_stage_path. Empty = the plugin picks a folder by itself
                           (Docker appdata, then VM domains, then a protected pool). */ ?>
                        <dt>Work folder for storage jobs <?=aicli_help_btn('consolidate-stage-path', 'Work folder for storage jobs')?></dt>
                        <dd>
                            <input type="text" name="consolidate_stage_path" aria-label="Work folder for storage jobs" data-testid="consolidate-stage-path"
                                   value="<?=htmlspecialchars((string)($config['consolidate_stage_path'] ?? ''), ENT_QUOTES, 'UTF-8')?>"
                                   placeholder="Automatic" style="width: 100%;" <?=$autoSave?>>
                            <?=aicli_help_panel('consolidate-stage-path')?>
                        </dd>

                        <?php /* BOOT_DISK_SPACE.md R5 (#537): where the caches of a plain-folder home live.
                           Keys: home_cache_location, home_cache_path, home_cache_ram_mb. */ ?>
                        <dt>Caches of plain-folder homes <?=aicli_help_btn('home-cache-location', 'Caches of plain-folder homes')?></dt>
                        <dd>
                            <select name="home_cache_location" aria-label="Caches of plain-folder homes" data-testid="home-cache-location" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['home_cache_location'] ?? 'ram', 'ram', _('Memory (default): no boot disk use, emptied when the server restarts'))?>
                                <?=mk_option($config['home_cache_location'] ?? 'ram', 'pool', _('A folder on a pool'))?>
                            </select>
                            <?=aicli_help_panel('home-cache-location')?>
                        </dd>

                        <dt>Cache folder on a pool <?=aicli_help_btn('home-cache-path', 'Cache folder on a pool')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" name="home_cache_path" id="home_cache_path" aria-label="Cache folder on a pool" data-testid="home-cache-path"
                                       value="<?=htmlspecialchars((string)($config['home_cache_path'] ?? ''), ENT_QUOTES, 'UTF-8')?>"
                                       placeholder="/mnt/cache/aicli-caches" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                <button type="button" class="aicli-btn-slim" onclick="openPathPicker('home_cache_path')" title="Browse"><i class="fa fa-folder-open"></i></button>
                            </div>
                            <?=aicli_help_panel('home-cache-path')?>
                        </dd>

                        <dt>Memory for caches, per home <?=aicli_help_btn('home-cache-ram-mb', 'Memory for caches, per home')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="home_cache_ram_mb" aria-label="Memory for caches, per home" data-testid="home-cache-ram-mb"
                                       value="<?=htmlspecialchars((string)($config['home_cache_ram_mb'] ?? '1024'), ENT_QUOTES, 'UTF-8')?>"
                                       min="256" max="8192" step="256" style="width: 110px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="font-size:11px; opacity:0.7; margin-left:8px;">MB · 256 to 8192</span>
                            </div>
                            <?=aicli_help_panel('home-cache-ram-mb')?>
                        </dd>

                        <?php /* BOOT_DISK_SPACE.md R6: the setting history_move_path. */ ?>
                        <dt>Folder for moved sessions <?=aicli_help_btn('history-move-path', 'Folder for moved sessions')?></dt>
                        <dd>
                            <input type="text" name="history_move_path" aria-label="Folder for moved sessions" data-testid="history-move-path"
                                   value="<?=htmlspecialchars((string)($config['history_move_path'] ?? ''), ENT_QUOTES, 'UTF-8')?>"
                                   placeholder="/mnt/pool/aicli-history" style="width: 100%;" <?=$autoSave?>>
                            <?=aicli_help_panel('history-move-path')?>
                        </dd>

                        <?php /* AGENT_TEMP_FOLDER.md R2: the settings agent_tmp_location, agent_tmp_path and agent_tmp_warn_mb.
                           The choice "boot" shows only when the boot device is an internal disk (not a USB stick). */
                        $aicliTmpBootOk = false;
                        try { $aicliTmpBootOk = \AICliAgents\Services\AgentTempService::bootAllowed(); } catch (\Throwable $e) { $aicliTmpBootOk = false; } ?>
                        <dt>Agent temporary files <?=aicli_help_btn('agent-tmp-location', 'Agent temporary files')?></dt>
                        <dd>
                            <select name="agent_tmp_location" aria-label="Agent temporary files" data-testid="agent-tmp-location" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['agent_tmp_location'] ?? 'ram', 'ram', _('Memory (default)'))?>
                                <?=mk_option($config['agent_tmp_location'] ?? 'ram', 'pool', _('A folder on a pool'))?>
                                <?php if ($aicliTmpBootOk): ?>
                                <?=mk_option($config['agent_tmp_location'] ?? 'ram', 'boot', _('The internal boot disk'))?>
                                <?php endif; ?>
                            </select>
                            <?=aicli_help_panel('agent-tmp-location')?>
                        </dd>
                        <dt>Temporary folder on a pool <?=aicli_help_btn('agent-tmp-path', 'Temporary folder on a pool')?></dt>
                        <dd>
                            <input type="text" name="agent_tmp_path" aria-label="Temporary folder on a pool"data-testid="agent-tmp-path"
                                   value="<?=htmlspecialchars((string)($config['agent_tmp_path'] ?? ''), ENT_QUOTES, 'UTF-8')?>"
                                   placeholder="/mnt/cache/agent-tmp" style="width: 100%;" <?=$autoSave?>>
                            <?=aicli_help_panel('agent-tmp-path')?>
                        </dd>
                        <dt>Warn when temporary files in memory pass <?=aicli_help_btn('agent-tmp-warn-mb', 'Warn when temporary files in memory pass')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="agent_tmp_warn_mb" aria-label="Warn when temporary files in memory pass"data-testid="agent-tmp-warn-mb"
                                       value="<?=htmlspecialchars((string)($config['agent_tmp_warn_mb'] ?? '1024'), ENT_QUOTES, 'UTF-8')?>"
                                       min="64" max="1048576" step="64" style="width: 110px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="font-size:11px; opacity:0.7; margin-left:8px;">MB</span>
                            </div>
                            <?=aicli_help_panel('agent-tmp-warn-mb')?>
                        </dd>

                        <dt>Which path a workspace runs on <?=aicli_help_btn('workspace-cwd', 'Which path a workspace runs on')?></dt>
                        <dd>
                            <?php $cwdMode = (string)($config['workspace_cwd'] ?? 'auto'); if ($cwdMode === 'share') $cwdMode = 'auto'; ?>
                            <select name="workspace_cwd" aria-label="Which path a workspace runs on" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($cwdMode, 'auto', _('Automatic'))?>
                                <?=mk_option($cwdMode, 'pool', _('Pool path when the share is on one pool only'))?>
                            </select>
                            <?=aicli_help_panel('workspace-cwd')?>
                        </dd>
                    </dl>
            <?=aicli_adv_close()?>
            <?=aicli_card_close()?>

            <?=aicli_card_open('terminal', 'fa-terminal', 'Terminal', $stTerminal, 'card-terminal', '',
                ['format' => '{theme} · {font_size} px'])?>
                    <dl>
                        <dt>Colour scheme <?=aicli_help_btn('theme', 'Colour scheme')?></dt>
                        <dd>
                            <select name="theme" aria-label="Colour scheme"style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                <?=mk_option($config['theme']??'dark', "dark", _('Dark'))?>
                                <?=mk_option($config['theme']??'dark', "light", _('Light'))?>
                                <?=mk_option($config['theme']??'dark', "solarized", _('Solarized'))?>
                            </select>
                            <?=aicli_help_panel('theme')?>
                        </dd>

                        <dt>Text size <?=aicli_help_btn('font-size', 'Text size')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="font_size" aria-label="Terminal font size in pixels" value="<?=$config['font_size'] ?? 12?>" min="8" max="32" style="width: 70px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">px</span>
                            </div>
                            <?=aicli_help_panel('font-size')?>
                        </dd>

                        <?php /* RESPONSIVE_PRIORITY.md: the setting responsive_priority. */ ?>
                        <dt>Keep typing fast when the server is busy <?=aicli_help_btn('responsive-priority', 'Keep typing fast when the server is busy')?></dt>
                        <dd>
                            <select name="responsive_priority" aria-label="Keep typing fast when the server is busy"data-testid="responsive-priority" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['responsive_priority'] ?? '0', '1', _('On'))?>
                                <?=mk_option($config['responsive_priority'] ?? '0', '0', _('Off (default)'))?>
                            </select>
                            <?=aicli_help_panel('responsive-priority')?>
                        </dd>
                    </dl>
            <?=aicli_card_close()?>

            <?=aicli_card_open('continue', 'fa-repeat', 'Automatic continue', $stContinue, 'card-continue', '',
                ['format' => 'After a restart: {auto_continue_on_restart} · After a model error: {transient_error_continue}'])?>
                    <dl>
                        <!-- CONTINUE_ON_RESTART.md (2026-09-09): relocated from the Agent Relay
                             tab (Relay messaging never owned this). Saves through the same
                             autoSaveConfig()/`save` path as every other select on this tab. -->
                        <dt>Continue after a restart <?=aicli_help_btn('auto-continue-restart', 'Continue after a restart')?></dt>
                        <dd>
                            <select name="auto_continue_on_restart" aria-label="Continue after a restart" style="width: 100%;" <?=$autoSave?>>
                                <?php $autoContinue = \AICliAgents\Services\ConfigService::autoContinueOnRestart() ? '1' : '0'; ?>
                                <?=mk_option($autoContinue, "1", _('On (default)'))?>
                                <?=mk_option($autoContinue, "0", _('Off'))?>
                            </select>
                            <?=aicli_help_panel('auto-continue-restart')?>
                        </dd>

                        <!-- TRANSIENT_ERROR_AUTO_CONTINUE.md (#312). Same autoSaveConfig()/`save`
                             path as the select above. -->
                        <dt>Continue after a model error <?=aicli_help_btn('transient-error-continue', 'Continue after a model error')?></dt>
                        <dd>
                            <select name="transient_error_continue" aria-label="Continue after a temporary model error" style="width: 100%;" <?=$autoSave?>>
                                <?php $transientMode = \AICliAgents\Services\TransientErrorService::settings($config)['mode']; ?>
                                <?=mk_option($transientMode, "recommended", _('Verified agents (default)'))?>
                                <?=mk_option($transientMode, "all", _('All agents'))?>
                                <?=mk_option($transientMode, "off", _('Off'))?>
                            </select>
                            <?=aicli_help_panel('transient-error-continue')?>
                        </dd>

                        <dt>Wait before each continue, and how many <?=aicli_help_btn('transient-error-wait', 'Wait before each continue, and how many')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" name="transient_error_backoff_minutes" aria-label="Minutes to wait before each automatic continue" value="<?=htmlspecialchars((string)($config['transient_error_backoff_minutes'] ?? '1,5,15'), ENT_QUOTES, 'UTF-8')?>" style="width: 110px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">minutes ·</span>
                                <input type="number" name="transient_error_max_continues" aria-label="Automatic continues per error before it needs you" value="<?=htmlspecialchars((string)($config['transient_error_max_continues'] ?? '3'), ENT_QUOTES, 'UTF-8')?>" min="0" max="10" style="width: 60px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">times</span>
                            </div>
                            <?=aicli_help_panel('transient-error-wait')?>
                        </dd>
                    </dl>
            <?=aicli_adv_open('continue', 'Automatic continue')?>
                    <!-- AUTO_CONTINUE_PATTERNS.md: the operator's own auto-continue patterns. Rendered by ManagerAutoContinueScripts.php through its own AJAX actions (AutoContinueHandler), like the Agent voice card. No control here has a `name`, so the settings form never saves them. -->
                    <div id="acp-card" class="acp-body" data-testid="acp-section">
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
                        <?php /* Phase 3: the long intro is in the (i) of this card (help/cards.php, adv-continue). */ ?>
                        <p class="aicli-hint" style="margin:10px 0 8px;">
                          Rules file: <code><?=htmlspecialchars(\AICliAgents\Services\SecretPaths::flashRoot(), ENT_QUOTES, 'UTF-8')?>/pane-input-rules.json</code>
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
            <?=aicli_adv_close()?>
            <?=aicli_card_close()?>

            <?=aicli_card_open('relay-notices', 'fa-envelope-o', 'Relay notices', $stRelayNotices, 'card-relay-notices', '',
                ['format' => '{relay_notice_style} · resend after {relay_notice_resend_minutes} min'])?>
                    <!-- RELAY_NOTICE_WORDING.md (#461): the wording of the notices the plugin types into an agent. RELAY_TOKEN_SAVINGS.md R15: how long an unread notice blocks a second one. -->
                    <dl>
                        <dt>Notice typed into an agent <?=aicli_help_btn('relay-notice-style', 'Notice typed into an agent')?></dt>
                        <dd>
                            <select name="relay_notice_style" id="aicli-relay-notice-style" aria-label="Notice typed into an agent"data-testid="relay-notice-style" style="width: 100%;" onchange="aicliRelayNoticeChanged()">
                                <?=mk_option($config['relay_notice_style'] ?? 'short', 'short', _('Short (default): about 20 words'))?>
                                <?=mk_option($config['relay_notice_style'] ?? 'short', 'minimal', _('Minimal: about 10 words'))?>
                                <?=mk_option($config['relay_notice_style'] ?? 'short', 'full', _('Full: the long original text'))?>
                                <?=mk_option($config['relay_notice_style'] ?? 'short', 'custom', _('Custom: my own words'))?>
                            </select>
                            <textarea name="relay_notice_custom" id="aicli-relay-notice-custom" aria-label="Custom Relay notice text" data-testid="relay-notice-custom" rows="2" maxlength="300" style="width:100%; margin-top:6px; display:none;" onchange="aicliRelayNoticeChanged()"><?=htmlspecialchars((string)($config['relay_notice_custom'] ?? ''), ENT_QUOTES)?></textarea>
                            <?=aicli_help_panel('relay-notice-style')?>
                            <?php /* The example of the notice sits behind a "Show example" link. A bad custom text opens it, so the reason is never hidden. */ ?>
                            <a href="#" id="aicli-relay-notice-example-link" data-testid="relay-notice-example-link" class="aicli-hint" role="button" aria-expanded="false" aria-controls="aicli-relay-notice-preview" style="display:inline-block; margin-top:6px;" onclick="return aicliRelayNoticeExample(this);">Show example</a>
                            <div id="aicli-relay-notice-preview" data-testid="relay-notice-preview" hidden style="font-family:monospace; font-size:12px; margin-top:6px; white-space:pre-wrap; word-break:break-word;"></div>
                        </dd>

                        <dt>Resend a notice after <?=aicli_help_btn('relay-notice-resend', 'Resend a notice after')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="relay_notice_resend_minutes" id="aicli-relay-notice-resend" aria-label="Resend a notice after" data-testid="relay-notice-resend" value="<?=htmlspecialchars((string)($config['relay_notice_resend_minutes'] ?? '10'), ENT_QUOTES, 'UTF-8')?>" min="1" max="240" style="width: 70px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">minutes · 1 to 240</span>
                            </div>
                            <?=aicli_help_panel('relay-notice-resend')?>
                        </dd>
                    </dl>
                    <script>
                    // Shows or hides the example of the notice. A text that is not allowed always shows it, with the reason.
                    function aicliRelayNoticeShow(show) {
                        var pv = document.getElementById('aicli-relay-notice-preview');
                        var link = document.getElementById('aicli-relay-notice-example-link');
                        if (!pv) return;
                        pv.hidden = !show;
                        if (link) { link.textContent = show ? 'Hide example' : 'Show example'; link.setAttribute('aria-expanded', show ? 'true' : 'false'); }
                    }
                    function aicliRelayNoticeExample() {
                        var pv = document.getElementById('aicli-relay-notice-preview');
                        aicliRelayNoticeShow(!!(pv && pv.hidden));
                        return false;
                    }
                    function aicliRelayNoticeChanged() {
                        var st = document.getElementById('aicli-relay-notice-style');
                        var tx = document.getElementById('aicli-relay-notice-custom');
                        var pv = document.getElementById('aicli-relay-notice-preview');
                        if (!st || !tx || !pv) return;
                        tx.style.display = st.value === 'custom' ? 'block' : 'none';
                        // Answers can arrive out of order. Only the answer of the newest request may write the preview.
                        var seq = window.aicliRelayNoticeSeq = (window.aicliRelayNoticeSeq || 0) + 1;
                        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_get_notice_preview&style=' + encodeURIComponent(st.value) + '&custom=' + encodeURIComponent(tx.value) + '&csrf_token=' + csrf, function (r) {
                            if (seq !== window.aicliRelayNoticeSeq) return;
                            if (r.status !== 'ok') { pv.style.color = '#d03b3b'; pv.textContent = r.message || 'This text cannot be used.'; aicliRelayNoticeShow(true); return; }
                            pv.style.color = '';
                            pv.textContent = 'Actor notice (' + r.words_actor + ' words): ' + r.actor + '\nDirect message notice (' + r.words_direct + ' words): ' + r.direct;
                            autoSaveConfig();
                        }).fail(function () { pv.textContent = 'Could not check the text. Nothing was saved.'; aicliRelayNoticeShow(true); });
                    }
                    (function () { if (window.jQuery) jQuery(function () { if (document.getElementById('aicli-relay-notice-style')) aicliRelayNoticeChanged_init(); }); })();
                    function aicliRelayNoticeChanged_init() {
                        var st = document.getElementById('aicli-relay-notice-style');
                        var tx = document.getElementById('aicli-relay-notice-custom');
                        if (tx) tx.style.display = st.value === 'custom' ? 'block' : 'none';
                        var seq = window.aicliRelayNoticeSeq = (window.aicliRelayNoticeSeq || 0) + 1;
                        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_get_notice_preview&style=' + encodeURIComponent(st.value) + '&custom=' + encodeURIComponent(tx ? tx.value : '') + '&csrf_token=' + csrf, function (r) {
                            if (seq !== window.aicliRelayNoticeSeq) return;
                            var pv = document.getElementById('aicli-relay-notice-preview');
                            if (pv && r.status === 'ok') pv.textContent = 'Actor notice (' + r.words_actor + ' words): ' + r.actor + '\nDirect message notice (' + r.words_direct + ' words): ' + r.direct;
                        });
                    }
                    </script>

            <?=aicli_adv_open('relay-notices', 'Relay notices')?>
                    <!-- RELAY_WAITING_PILL.md Part 3: capture-on-deliver. It feeds the same readiness gate as the Terminal input safety rules (card Automatic continue). -->
                    <dl>
                        <dt>Keep a screen sample when I deliver a message <?=aicli_help_btn('relay-gate-sampling', 'Keep a screen sample when I deliver a message')?></dt>
                        <dd>
                            <select name="relay_gate_sampling_enabled" aria-label="Keep a screen sample when I deliver a message"style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['relay_gate_sampling_enabled'] ?? '0', "1", _('On'))?>
                                <?=mk_option($config['relay_gate_sampling_enabled'] ?? '0', "0", _('Off (default)'))?>
                            </select>
                            <?=aicli_help_panel('relay-gate-sampling')?>
                        </dd>
                    </dl>
            <?=aicli_adv_close()?>
            <?=aicli_card_close()?>

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
                 logs and browser history. AGENT_VOICE.md R13: the Voice name
                 field is a `<select>` filled from the read-only `list_voices`
                 action when the engine can answer it, falling back to the
                 plain text field — see aicliVoiceRefreshVoiceList() below. -->

            <?=aicli_card_open('voice', 'fa-volume-up', 'Agent voice', $stVoice, 'card-voice', '')?>
                    <dl>
                        <?php /* AGENT_VOICE.md "#323 guided setup": the "Set up natural voice" row. */ require __DIR__ . '/VoiceSetupCard.php'; ?>
                        <dt>Voice <?=aicli_help_btn('voice-toggle', 'Voice on or off')?></dt>
                        <dd>
                            <button type="button" id="aicli-voice-toggle" class="aicli-btn-slim" onclick="aicliVoiceToggleGlobal()">
                                <i id="aicli-voice-toggle-icon" class="fa fa-volume-off"></i> <span id="aicli-voice-toggle-label">Off</span>
                            </button>
                            <?=aicli_help_panel('voice-toggle')?>
                        </dd>

                        <dt>Voice server address <?=aicli_help_btn('tts-url', 'Voice server address')?></dt>
                        <dd>
                            <input type="text" id="aicli-voice-tts-url" aria-label="Voice server address"onchange="aicliVoiceSaveField('tts_url')" placeholder="http://192.168.1.4:8880" style="width:100%;">
                            <?=aicli_help_panel('tts-url')?>
                        </dd>

                        <dt>Voice <?=aicli_help_btn('tts-voice', 'Voice name')?></dt>
                        <dd>
                            <!-- R13: when the engine can list its own voices
                                 (GET /v1/audio/voices, a Kokoro-FastAPI
                                 extension), the select replaces the text
                                 field. Both share the same "tts_voice" value;
                                 aicliVoiceSaveField('tts_voice') reads
                                 whichever one is visible. -->
                            <select id="aicli-voice-tts-voice-select" aria-label="Voice name" onchange="aicliVoiceSelectChanged()" style="width:100%; display:none;"></select>
                            <input type="text" id="aicli-voice-tts-voice" aria-label="Voice name"onchange="aicliVoiceSaveField('tts_voice')" placeholder="af_heart" style="width:100%;">
                            <?=aicli_help_panel('tts-voice')?>
                        </dd>

                        <dt>Speed <?=aicli_help_btn('tts-speed', 'Speed')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" id="aicli-voice-tts-speed" aria-label="Speech speed" onchange="aicliVoiceSaveField('tts_speed')" min="0.5" max="2.0" step="0.1" style="width:90px !important;">
                                <span style="opacity:0.75; font-size:11px;">1.0 = normal</span>
                            </div>
                            <?=aicli_help_panel('tts-speed')?>
                        </dd>

                        <dt>API key <?=aicli_help_btn('tts-key', 'API key')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="password" data-aicli-eye id="aicli-voice-tts-key" aria-label="Text-to-speech API key" onchange="aicliVoiceSaveField('tts_api_key')" placeholder="Leave empty to keep the current key" style="flex:1; min-width:0;" autocomplete="new-password">
                                <button type="button" id="aicli-voice-key-clear" class="aicli-btn-slim" onclick="aicliVoiceClearKey();" title="Remove the stored API key" style="font-size:11px; min-height:24px; min-width:44px; padding:2px 10px; white-space:nowrap;">Clear</button>
                            </div>
                            <div class="aicli-hint">Status: <strong id="aicli-voice-key-status">unknown</strong></div>
                            <?=aicli_help_panel('tts-key')?>
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
                    </dl>
            <?=aicli_adv_open('voice', 'Agent voice')?>
                    <dl>
                        <!-- VOICE_MAIL.md R8: how much voice mail is kept. Saved one field
                             per request through save_voice_settings, like the fields above. -->
                        <dt style="border-top:1px solid var(--border-color, rgba(128,128,128,0.25)); padding-top:12px; margin-top:8px; font-weight:600;">Voice mail <?=aicli_help_btn('voicemail-intro', 'Voice mail')?></dt>
                        <dd style="border-top:1px solid var(--border-color, rgba(128,128,128,0.25)); padding-top:12px; margin-top:8px;">
                            <?=aicli_help_panel('voicemail-intro')?>
                        </dd>

                        <dt>Messages kept per workspace <?=aicli_help_btn('voicemail-max-per-workspace', 'Messages kept per workspace')?></dt>
                        <dd>
                            <input type="number" id="aicli-voicemail-max-per-workspace" aria-label="Voice mail messages kept per workspace" onchange="aicliVoiceSaveField('voicemail_max_per_workspace')" min="1" max="500" step="1" placeholder="50" style="width:90px !important;">
                            <?=aicli_help_panel('voicemail-max-per-workspace')?>
                        </dd>

                        <dt>Days kept <?=aicli_help_btn('voicemail-max-age-days', 'Days kept')?></dt>
                        <dd>
                            <input type="number" id="aicli-voicemail-max-age-days" aria-label="Days to keep voice mail" onchange="aicliVoiceSaveField('voicemail_max_age_days')" min="1" max="90" step="1" placeholder="7" style="width:90px !important;">
                            <?=aicli_help_panel('voicemail-max-age-days')?>
                            <div id="aicli-voicemail-save-result" style="font-size:11px; min-height:1.4em; width:100%; overflow-wrap:anywhere;"></div>
                        </dd>
                    </dl>
            <?=aicli_adv_close()?>
            <?=aicli_card_close()?>

                        <!-- VOICE_INPUT.md: the Dictation card — the
                             reverse direction from the TTS settings above (dictating INTO a
                             workspace, not the agent speaking). `stt_url` empty means browser
                             mode: the Web Speech API runs entirely on this device and nothing
                             leaves it. Loads/saves through the same get_voice_settings /
                             save_voice_settings actions and the same masked-key handling as
                             the TTS key above (aicliVoiceSaveField / aicliVoicePost). The Test
                             button here records 3 s (8 s with a live endpoint, words shown as you speak) on this device and shows the recognised
                             text — it calls window.aicliVoice.startInput() with a fixed
                             non-workspace id ('manager') and never dictates anywhere. -->

            <?=aicli_card_open('dictation', 'fa-microphone', 'Dictation', 'Loading…', 'card-dictation', '')?>
                    <dl>
                        <?php /* VOICE_ENGINE_SETUP.md #381 #382: the "Set up local dictation" row. */ require __DIR__ . '/VoiceSttSetupCard.php'; ?>

                        <dt>Transcription server address <?=aicli_help_btn('stt-url', 'Transcription server address')?></dt>
                        <dd>
                            <input type="text" id="aicli-voice-stt-url" aria-label="Transcription server address"onchange="aicliVoiceSaveField('stt_url')" placeholder="http://192.168.1.4:8000" style="width:100%;">
                            <?=aicli_help_panel('stt-url')?>
                        </dd>

                        <!-- VOICE_LIVE_ENGINE.md R1/R7 (#731): the live (streaming) engine. The
                             browser connects to it directly; the Test button opens the socket from
                             this page and sends one second of silence. -->
                        <dt>Live transcription server (optional) <?=aicli_help_btn('stt-stream-url', 'Live transcription server')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" id="aicli-voice-stt-stream-url" aria-label="Live transcription server"onchange="aicliVoiceSaveField('stt_stream_url')" placeholder="wss://stt-live.example.com/" style="flex:1; min-width:0;">
                                <button type="button" class="aicli-btn-slim" onclick="aicliVoiceLiveTest()" title="Connect to the live endpoint from this browser" style="font-size:11px; min-height:24px; min-width:44px; padding:2px 10px; white-space:nowrap;">Test</button>
                            </div>
                            <?=aicli_help_panel('stt-stream-url')?>
                            <div id="aicli-voice-live-test-result" style="font-size:11px; min-height:1.4em; width:100%; overflow-wrap:anywhere;"></div>
                        </dd>

                        <dt>Model <?=aicli_help_btn('stt-model', 'Model')?></dt>
                        <dd>
                            <input type="text" id="aicli-voice-stt-model" aria-label="Transcription model" onchange="aicliVoiceSaveField('stt_model')" placeholder="whisper-1" style="width:100%;">
                            <?=aicli_help_panel('stt-model')?>
                        </dd>

                        <dt>Language <?=aicli_help_btn('stt-language', 'Language')?></dt>
                        <dd>
                            <input type="text" id="aicli-voice-stt-language" aria-label="Spoken language" onchange="aicliVoiceSaveField('stt_language')" placeholder="en" style="width:100%;">
                            <?=aicli_help_panel('stt-language')?>
                        </dd>

                        <dt>API key <?=aicli_help_btn('stt-key', 'API key')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="password" data-aicli-eye id="aicli-voice-stt-key" aria-label="Transcription API key" onchange="aicliVoiceSaveField('stt_api_key')" placeholder="Leave empty to keep the current key" style="flex:1; min-width:0;" autocomplete="new-password">
                                <button type="button" id="aicli-voice-stt-key-clear" class="aicli-btn-slim" onclick="aicliVoiceClearSttKey();" title="Remove the stored API key" style="font-size:11px; min-height:24px; min-width:44px; padding:2px 10px; white-space:nowrap;">Clear</button>
                            </div>
                            <div class="aicli-hint">Status: <strong id="aicli-voice-stt-key-status">unknown</strong></div>
                            <?=aicli_help_panel('stt-key')?>
                        </dd>

                        <dt aria-hidden="true">&nbsp;</dt>
                        <dd>
                            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <button type="button" class="aicli-btn-slim" onclick="aicliVoiceInputTest()"><i class="fa fa-microphone"></i> Test</button>
                            </div>
                            <div id="aicli-voice-input-test-result" style="font-size:11px; min-height:1.4em; width:100%; overflow-wrap:anywhere;"></div>
                        </dd>
                    </dl>
            <?=aicli_card_close()?>

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
                    $('#aicli-voice-stt-stream-url').val(s.stt_stream_url || '');   // #731
                    $('#aicli-voicemail-max-per-workspace').val(s.voicemail_max_per_workspace || '50');
                    $('#aicli-voicemail-max-age-days').val(s.voicemail_max_age_days || '7');
                    $('#aicli-voice-stt-model').val(s.stt_model || 'whisper-1');
                    $('#aicli-voice-stt-language').val(s.stt_language || '');
                    $('#aicli-voice-stt-key-status').text(s.stt_api_key_set ? 'set' : 'not set');
                    if (typeof s.voice_enabled !== 'undefined' && window.aicliVoice) {
                        window.aicliVoice.setState(!!s.voice_enabled);
                    }
                    syncToggle();
                    // Phase 5: the status of the card "Dictation" follows the stored settings.
                    if (window.aicliCards) {
                        var dHost = function (u) { return (u || '').replace(/^[a-z]+:\/\//i, '').replace(/[\/:].*$/, ''); };
                        var dText = s.stt_url ? 'Server at ' + dHost(s.stt_url) : 'Browser dictation';
                        if (s.stt_stream_url) dText += ' · live engine set';
                        window.aicliCards.setStatus('dictation', dText);
                    }
                    // Phase 3: the one-line status of the card header follows the stored settings.
                    if (window.aicliCards && typeof s.voice_enabled !== 'undefined') {
                        var vHost = (s.tts_url || '').replace(/^[a-z]+:\/\//i, '').replace(/[\/:].*$/, '');
                        window.aicliCards.setStatus('voice', (s.voice_enabled ? 'On' : 'Off')
                            + (vHost ? ' · voice server ' + vHost : (s.voice_enabled ? ' · browser voice' : '')));
                    }
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

                // Called on load, after a voice server address save, and after Test —
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
                        stt_stream_url: "#aicli-voice-stt-stream-url",   // VOICE_LIVE_ENGINE.md R1 (#731)
                        // VOICE_MAIL.md R8: retention, same action, own result line.
                        voicemail_max_per_workspace: "#aicli-voicemail-max-per-workspace", voicemail_max_age_days: "#aicli-voicemail-max-age-days"
                    };
                    if (!ids[key]) return;
                    var value = key === "tts_voice" ? currentVoiceValue() : ($(ids[key]).val() || "").trim();
                    if ((key === "tts_api_key" || key === "stt_api_key") && value === "") return;
                    var body = {}; body[key] = value;
                    var box = key === "stt_stream_url" ? $("#aicli-voice-live-test-result")
                        : key === "stt_url" || key === "stt_model" || key === "stt_language" || key === "stt_api_key"
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

                // VOICE_LIVE_ENGINE.md R7 (#731): connect from THIS browser (the live engine is
                // reached by the browser, never by the server), send 1 s of silence and "Done",
                // and wait for the end of the stream. Records nothing from the microphone.
                window.aicliVoiceLiveTest = function () {
                    var box = $('#aicli-voice-live-test-result');
                    var url = ($('#aicli-voice-stt-stream-url').val() || '').trim();
                    if (!url) { box.css('color', '#f87171').text('Enter a ws:// or wss:// address first.'); return; }
                    if (location.protocol === 'https:' && /^ws:/i.test(url)) { box.css('color', '#f87171').text('This page is HTTPS, so it cannot use a ws:// address. Use a wss:// address.'); return; }
                    var done = false, ws;
                    var finish = function (ok, text) {
                        if (done) return; done = true;
                        try { ws && ws.close(); } catch (e) { /* noop */ }
                        box.css('color', ok ? '' : '#f87171').text(text);
                        aicliVoicePost('voice_live_result', { ok: ok ? '1' : '0', message: text }).catch(function () { /* best effort */ });
                    };
                    box.css('color', '').text('Connecting…');
                    try { ws = new WebSocket(url); } catch (e) { finish(false, 'That is not a valid websocket address.'); return; }
                    ws.binaryType = 'arraybuffer';
                    var timer = setTimeout(function () { finish(false, 'The live endpoint did not answer in 5 seconds.'); }, 5000);
                    ws.onopen = function () {
                        for (var i = 0; i < 10; i++) ws.send(new Float32Array(1600).buffer);
                        ws.send('Done');
                    };
                    ws.onmessage = function (evt) {
                        var d = null; try { d = JSON.parse(String(evt.data)); } catch (e) { return; }
                        if (d && d.is_eof) { clearTimeout(timer); finish(true, 'Connected. Live transcription works.'); }
                    };
                    ws.onclose = function () { clearTimeout(timer); finish(false, 'The live endpoint closed the connection without an answer.'); };
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
                    var streamUrl = ($('#aicli-voice-stt-stream-url').val() || '').trim();
                    // VOICE_LIVE_ENGINE.md (#731): with a live endpoint the Test streams and shows the
                    // words while you speak, so it records longer than the 3 s of a clip test.
                    var testSeconds = streamUrl ? 8 : 3;
                    var head = streamUrl ? 'Listening (live)… ' : 'Listening… ';
                    box.css('color', '').text(head + testSeconds + ' s left');
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
                                var left = Math.max(0, testSeconds - (d.seconds - baseSeconds));
                                show(head + left + ' s left');
                            } else {
                                show(head.trim());
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
                                }, testSeconds * 1000);
                            }
                        } else if (d.state === 'transcribing') {
                            // #485: a piece can answer after the stop; its words still count.
                            if (typeof d.phrase === 'string' && d.phrase.trim()) heard.push(d.phrase.trim());
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
                    // noEngineFallback (#484): this button tests the engine, so it must not quietly use the browser instead.
                    var startOpts = { workspaceId: 'manager', sttUrl: sttUrl, sttLanguage: sttLanguage, noEngineFallback: true };
                    if (streamUrl) startOpts.sttStreamUrl = streamUrl;
                    api.startInput(startOpts);
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

            <!-- ARTEFACTS.md: the Notebook card. Plain form fields, saved through the
                 same autoSaveConfig()/`save` path as the card above (UtilityHandler::save
                 checks notebook_folder). Only a person changes these: none of the five
                 keys is on AdminService::SETTINGS_ALLOWLIST, so an agent cannot. -->

            <?=aicli_card_open('notebook', 'fa-book', 'Notebook', $stNotebook, 'card-notebook', '',
                ['format' => '{notebook_enabled} · wiki page {wiki_page_enabled}', 'domId' => 'notebook-settings-card'])?>
                    <dl>
                        <dt>Notebook <?=aicli_help_btn('notebook-enabled', 'Notebook')?></dt>
                        <dd>
                            <select name="notebook_enabled" aria-label="Notebook" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['notebook_enabled'] ?? '1', "1", _('On (default)'))?>
                                <?=mk_option($config['notebook_enabled'] ?? '1', "0", _('Off'))?>
                            </select>
                            <?=aicli_help_panel('notebook-enabled')?>
                        </dd>
                        <dt>Show the Wiki page <?=aicli_help_btn('wiki-page', 'Show the Wiki page')?></dt>
                        <dd>
                            <select name="wiki_page_enabled" aria-label="Show the Wiki page"style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['wiki_page_enabled'] ?? '0', "1", _('On'))?>
                                <?=mk_option($config['wiki_page_enabled'] ?? '0', "0", _('Off (default)'))?>
                            </select>
                            <?=aicli_help_panel('wiki-page')?>
                        </dd>
                        <dt>Open the Notebook when an agent asks <?=aicli_help_btn('notebook-auto-open', 'Open the Notebook when an agent asks')?></dt>
                        <dd>
                            <select name="notebook_auto_open" aria-label="Open the Notebook when an agent asks" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['notebook_auto_open'] ?? '0', "1", _('Yes'))?>
                                <?=mk_option($config['notebook_auto_open'] ?? '0', "0", _('No (default)'))?>
                            </select>
                            <?=aicli_help_panel('notebook-auto-open')?>
                        </dd>

                        <dt>Tell the agent when I answer <?=aicli_help_btn('notebook-notify-agent', 'Tell the agent when I answer')?></dt>
                        <dd>
                            <select name="notebook_notify_agent" aria-label="Tell the agent when I answer in the Notebook" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['notebook_notify_agent'] ?? '1', "1", _('Yes (default)'))?>
                                <?=mk_option($config['notebook_notify_agent'] ?? '1', "0", _('No'))?>
                            </select>
                            <?=aicli_help_panel('notebook-notify-agent')?>
                        </dd>
                    </dl>
            <?=aicli_adv_open('notebook', 'Notebook')?>
                    <dl>
                        <dt>Folder name <?=aicli_help_btn('notebook-folder', 'Folder name')?></dt>
                        <dd>
                            <input type="text" name="notebook_folder" aria-label="Folder name" placeholder="notebook" value="<?=htmlspecialchars((string)($config['notebook_folder'] ?? 'notebook'), ENT_QUOTES, 'UTF-8')?>" maxlength="40" pattern="[A-Za-z0-9_\-][A-Za-z0-9._\-]{0,39}" style="width: 100%;" <?=$autoSave?>>
                            <?=aicli_help_panel('notebook-folder')?>
                        </dd>
                        <dt>Production keys on connection cards <?=aicli_help_btn('notebook-prod-secrets', 'Production keys on connection cards')?></dt>
                        <dd>
                            <select name="notebook_allow_prod_secrets" aria-label="Production keys on connection cards"style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['notebook_allow_prod_secrets'] ?? '0', "1", _('Allow'))?>
                                <?=mk_option($config['notebook_allow_prod_secrets'] ?? '0', "0", _('Refuse (default)'))?>
                            </select>
                            <?=aicli_help_panel('notebook-prod-secrets')?>
                        </dd>

                        <dt>Speak a notice when a page waits for me <?=aicli_help_btn('notebook-spoken-notice', 'Speak a notice when a page waits for me')?></dt>
                        <dd>
                            <select name="notebook_spoken_notice" aria-label="Speak a notice when a page waits for me"style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['notebook_spoken_notice'] ?? '0', "1", _('Yes'))?>
                                <?=mk_option($config['notebook_spoken_notice'] ?? '0', "0", _('No (default)'))?>
                            </select>
                            <?=aicli_help_panel('notebook-spoken-notice')?>
                        </dd>
                        <dt>Quiet hours <?=aicli_help_btn('notebook-quiet-hours', 'Quiet hours')?></dt>
                        <dd>
                            <input type="text" name="notebook_quiet_hours" aria-label="Quiet hours"value="<?=htmlspecialchars((string)($config['notebook_quiet_hours'] ?? ''), ENT_QUOTES, 'UTF-8')?>" maxlength="11" placeholder="22:00-07:00" pattern="([01][0-9]|2[0-3]):[0-5][0-9]-([01][0-9]|2[0-3]):[0-5][0-9]" style="width: 100%;" <?=$autoSave?>>
                            <?=aicli_help_panel('notebook-quiet-hours')?>
                        </dd>
                        <dt>Agent journal <?=aicli_help_btn('notebook-journal', 'Agent journal')?></dt>
                        <dd>
                            <select name="notebook_journal" aria-label="Agent journal" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['notebook_journal'] ?? '0', "1", _('On'))?>
                                <?=mk_option($config['notebook_journal'] ?? '0', "0", _('Off (default)'))?>
                            </select>
                            <?=aicli_help_panel('notebook-journal')?>
                        </dd>
                    </dl>
            <?=aicli_adv_close()?>
            <?=aicli_card_close()?>

            <?=aicli_card_open('ssh', 'fa-key', 'SSH keys', 'Loading…', 'card-ssh',
                '<button type="button" onclick="aicliShowSshHelp()" class="aicli-btn-slim" title="How to generate an SSH public key" style="font-size:10px;">'
                . '<i class="fa fa-question-circle"></i> Help</button>')?>
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
            <?=aicli_card_close()?>
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
            var nKeys = data.keys ? data.keys.length : 0;
            if (window.aicliCards) window.aicliCards.setStatus('ssh', nKeys === 0 ? 'No keys' : (nKeys === 1 ? '1 key' : nKeys + ' keys'));
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
   changes the column count. The input is the saved card order (Phase 4):
   aicliCards.sortCards() gives the cards back in that order; without it the
   cards keep their page order. */
(function () {
    'use strict';
    var COL_MIN = 400, GAP = 20;
    var grid = null, lastN = 0, lastW = 0;

    function collectCards() {
        var list = grid.querySelectorAll('.aicli-card');
        var found = Array.prototype.filter.call(list, function (c) {
            return c.parentNode === grid || (c.parentNode && c.parentNode.classList.contains('aicli-config-col'));
        });
        // The default place of each card, stamped once before any card moves.
        found.forEach(function (c, i) { if (!c.hasAttribute('data-aicli-pos')) c.setAttribute('data-aicli-pos', String(i)); });
        if (window.aicliCards && typeof window.aicliCards.sortCards === 'function') {
            try { found = window.aicliCards.sortCards(grid, found); } catch (e) { /* keep the page order */ }
        }
        return found;
    }

    function layout(force) {
        if (!grid) return;
        var w = grid.clientWidth;
        if (!w) return; // the tab is hidden; layout when it is shown
        var n = Math.max(1, Math.floor((w + GAP) / (COL_MIN + GAP)));
        if (!force && n === lastN && Math.abs(w - lastW) < 2) return;
        lastN = n; lastW = w;
        var cards = collectCards();
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
