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
                            <select name="enable_tab" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['enable_tab'], "1", _('Yes'))?>
                                <?=mk_option($config['enable_tab'], "0", _('No'))?>
                            </select>
                        </dd>

                        <dt>Logging Level</dt>
                        <dd>
                            <select name="log_level" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['log_level'], "0", _('Errors Only'))?>
                                <?=mk_option($config['log_level'], "1", _('Warnings'))?>
                                <?=mk_option($config['log_level'], "2", _('Normal (Info)'))?>
                                <?=mk_option($config['log_level'], "3", _('Debug (Verbose)'))?>
                            </select>
                        </dd>

                        <dt>Backup Interval</dt>
                        <dd>
                            <div class="input-row">
                                <select name="sync_interval_hours" style="width: 60px !important; flex-shrink: 0;" <?=$autoSave?>>
                                    <?php for($i=0; $i<=23; $i++): echo mk_option($config['sync_interval_hours']??0, $i, $i."h"); endfor; ?>
                                </select>
                                <select name="sync_interval_mins" style="width: 60px !important; flex-shrink: 0;" <?=$autoSave?>>
                                    <?php for($i=0; $i<=59; $i++): echo mk_option($config['sync_interval_mins']??30, $i, $i."m"); endfor; ?>
                                </select>
                                <button type="button" class="aicli-btn-slim" onclick="persistEntity('home', activeTerminalUser)"><i class="fa fa-save"></i> Persist</button>
                            </div>
                        </dd>

                        <dt>Version Check Schedule</dt>
                        <dd>
                            <select name="version_check_schedule" <?=$autoSave?>>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 */6 * * *', 'Every 6 hours')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 6 * * *', 'Daily at 6am')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 6 * * 1', 'Weekly (Monday 6am)')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '', 'Disabled')?>
                            </select>
                        </dd>

                        <dt>Version History (months)</dt>
                        <dd>
                            <select name="version_check_months" <?=$autoSave?>>
                                <?=mk_option($config['version_check_months']??'3', '1', '1 month')?>
                                <?=mk_option($config['version_check_months']??'3', '3', '3 months')?>
                                <?=mk_option($config['version_check_months']??'3', '6', '6 months')?>
                                <?=mk_option($config['version_check_months']??'3', '12', '12 months')?>
                            </select>
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
                            <select name="theme" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                <?=mk_option($config['theme']??'dark', "dark", _('Dark'))?>
                                <?=mk_option($config['theme']??'dark', "light", _('Light'))?>
                                <?=mk_option($config['theme']??'dark', "solarized", _('Solarized'))?>
                            </select>
                        </dd>

                        <dt>Font Size</dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="font_size" value="<?=$config['font_size'] ?? 12?>" min="8" max="32" style="width: 70px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.5; font-size:11px;">px</span>
                            </div>
                        </dd>

                        <dt>Terminal User</dt>
                        <dd>
                            <div class="input-row">
                                <select name="user" id="user_select" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                    <?php foreach ($users as $u => $d): ?>
                                        <?=mk_option($config['user'], $u, $u . (empty($d) || $u === $d ? "" : " ($d)"))?>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="aicli-btn-slim" onclick="window.open('/Users/UserAdd', '_blank')" title="Add User"><i class="fa fa-user-plus"></i></button>
                                <button type="button" class="aicli-btn-slim" onclick="safeReload()" title="Refresh"><i class="fa fa-refresh"></i></button>
                            </div>
                        </dd>

                        <dt>Workspace Root</dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" name="root_path" id="root_path" value="<?=htmlspecialchars($config['root_path'] ?? '/mnt/user', ENT_QUOTES, 'UTF-8')?>" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                <button type="button" class="aicli-btn-slim" onclick="openPathPicker('root_path')" title="Browse"><i class="fa fa-folder-open"></i></button>
                            </div>
                        </dd>

                        <dt>Home Storage</dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" name="home_storage_path" id="home_storage_path" value="<?=htmlspecialchars($config['home_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence', ENT_QUOTES, 'UTF-8')?>" style="flex: 1; min-width: 0;">
                                <button type="button" class="aicli-btn-slim" onclick="openPathPicker('home_storage_path')" title="Browse"><i class="fa fa-folder-open"></i></button>
                                <button type="button" class="aicli-btn-slim" onclick="saveAICliAgentsManager(document.getElementById('aicli-settings-form'), false)" title="Move storage to this path"><i class="fa fa-truck"></i></button>
                            </div>
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
                                <input type="text" name="agent_storage_path" id="agent_storage_path" value="<?=htmlspecialchars($config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence', ENT_QUOTES, 'UTF-8')?>" style="flex: 1; min-width: 0;">
                                <button type="button" class="aicli-btn-slim" onclick="openPathPicker('agent_storage_path')" title="Browse"><i class="fa fa-folder-open"></i></button>
                                <button type="button" class="aicli-btn-slim" onclick="saveAICliAgentsManager(document.getElementById('aicli-settings-form'), false)" title="Move storage to this path"><i class="fa fa-truck"></i></button>
                            </div>
                            <?php $agentClass = \AICliAgents\Services\StorageMountService::classifyPath($config['agent_storage_path'] ?? ''); ?>
                            <?php if ($agentClass === 'array'): ?>
                                <div style="font-size:10px; color:#eab308; margin-top:2px; padding:3px 6px; background:rgba(234,179,8,0.08); border-radius:3px; display:flex; align-items:center; gap:4px; width:100%;"><i class="fa fa-exclamation-triangle"></i> On array — agents unavailable when stopped.</div>
                            <?php elseif (strpos($agentClass, 'pool:') === 0): ?>
                                <div style="font-size:10px; color:#3b82f6; margin-top:2px; padding:3px 6px; background:rgba(59,130,246,0.08); border-radius:3px; display:flex; align-items:center; gap:4px; width:100%;"><i class="fa fa-info-circle"></i> On pool '<?=htmlspecialchars(substr($agentClass, 5), ENT_QUOTES, 'UTF-8')?>' — unavailable if pool is stopped.</div>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <!-- Secrets Vault moved to per-agent Store cards (Secrets panel). See AGENT_LEVEL_TMUX_CONF.md
                 and the av2 card mockup. Single-key-per-agent agents (env_prefix + _API_KEY) still
                 work unchanged; the new Secrets panel also supports multi-field agents like Goose. -->
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

