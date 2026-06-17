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
                        </dd>

                        <dt>Logging Level</dt>
                        <dd>
                            <select name="log_level" aria-label="Logging Level" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['log_level'], "0", _('Errors Only'))?>
                                <?=mk_option($config['log_level'], "1", _('Warnings'))?>
                                <?=mk_option($config['log_level'], "2", _('Normal (Info)'))?>
                                <?=mk_option($config['log_level'], "3", _('Debug (Verbose)'))?>
                            </select>
                        </dd>

                        <dt>Backup Interval</dt>
                        <dd>
                            <div class="input-row">
                                <select name="sync_interval_hours" aria-label="Backup interval, hours" style="width: 60px !important; flex-shrink: 0;" <?=$autoSave?>>
                                    <?php for($i=0; $i<=23; $i++): echo mk_option($config['sync_interval_hours']??0, $i, $i."h"); endfor; ?>
                                </select>
                                <select name="sync_interval_mins" aria-label="Backup interval, minutes" style="width: 60px !important; flex-shrink: 0;" <?=$autoSave?>>
                                    <?php for($i=0; $i<=59; $i++): echo mk_option($config['sync_interval_mins']??30, $i, $i."m"); endfor; ?>
                                </select>
                                <button type="button" class="aicli-btn-slim" onclick="persistEntity('home', activeTerminalUser)"><i class="fa fa-save"></i> Persist</button>
                            </div>
                        </dd>

                        <dt>Version Check Schedule</dt>
                        <dd>
                            <select name="version_check_schedule" aria-label="Version check schedule" <?=$autoSave?>>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 */6 * * *', 'Every 6 hours')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 6 * * *', 'Daily at 6am')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '0 6 * * 1', 'Weekly (Monday 6am)')?>
                                <?=mk_option($config['version_check_schedule']??'0 6 * * *', '', 'Disabled')?>
                            </select>
                        </dd>

                        <dt>Version History (months)</dt>
                        <dd>
                            <select name="version_check_months" aria-label="Version history retention in months" <?=$autoSave?>>
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
                            <select name="theme" aria-label="Terminal theme" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                <?=mk_option($config['theme']??'dark', "dark", _('Dark'))?>
                                <?=mk_option($config['theme']??'dark', "light", _('Light'))?>
                                <?=mk_option($config['theme']??'dark', "solarized", _('Solarized'))?>
                            </select>
                        </dd>

                        <dt>Font Size</dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="font_size" aria-label="Terminal font size in pixels" value="<?=$config['font_size'] ?? 12?>" min="8" max="32" style="width: 70px !important; flex-shrink: 0;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">px</span>
                            </div>
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
                        </dd>

                        <dt>Workspace Root</dt>
                        <dd>
                            <div class="input-row">
                                <input type="text" name="root_path" id="root_path" aria-label="Workspace root path" value="<?=htmlspecialchars($config['root_path'] ?? '/mnt/user', ENT_QUOTES, 'UTF-8')?>" style="flex: 1; min-width: 0;" <?=$autoSave?>>
                                <button type="button" class="aicli-btn-slim" onclick="openPathPicker('root_path')" title="Browse"><i class="fa fa-folder-open"></i></button>
                            </div>
                        </dd>

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
                                Home overlay layer ceiling. Consolidation runs automatically at this minus 2
                                (or under disk-space pressure, or on a manual "Consolidate Layers" click).
                                Higher = fewer consolidations (less Flash churn) but slower cold mounts.
                                Range 4–40; default 30 (consolidates at 28). Agents are unaffected — one layer per install.
                            </div>
                        </dd>
                    </dl>
                </div>
            </div>

            <!-- Secrets Vault moved to per-agent Store cards (Secrets panel). See AGENT_LEVEL_TMUX_CONF.md
                 and the av2 card mockup. Single-key-per-agent agents (env_prefix + _API_KEY) still
                 work unchanged; the new Secrets panel also supports multi-field agents like Goose. -->

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
                        <input id="aicli-ssh-key-label" type="text" placeholder="Label (e.g. MacBook Pro)" style="width:100%; padding:6px;"
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
                '.aicli-help-note{font-size:10px;color:var(--alt-text-color,#888);margin-top:12px;line-height:1.6;padding:8px 10px;border-radius:4px;border:1px solid var(--border-color,rgba(128,128,128,.15));background:var(--mild-background-color,rgba(128,128,128,.03));}'
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
        var xBtn = document.createElement('button'); xBtn.type = 'button'; xBtn.className = 'aicli-help-x'; xBtn.title = 'Close';
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
        via_user_share:    'FUSE user share',
        user_share:        'FUSE overhead',
        array_rotational:  'HDD — spins on every persist',
        posix_none:        'no symlinks/xattrs',
        facts_uncertain:   'device facts uncertain',
        network_target:    'network share',
        rejected_for_home: 'not allowed for home storage',
        remote_agent_warn: 'remote — agents only, not recommended',
        volatile_target:   'RAM-backed — data lost on reboot',
        probe_unavailable: 'probe unavailable'
    };

    function chip(text, fg, bg) {
        return '<span style="display:inline-block; font-size:9px; padding:1px 6px; border-radius:8px; margin:1px 3px 1px 0; color:' + fg + '; background:' + bg + '; white-space:nowrap;">' + esc(text) + '</span>';
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
        return '<label style="display:flex; gap:8px; align-items:flex-start; padding:6px 8px; border:1px solid var(--border-color, rgba(128,128,128,0.25)); border-radius:4px; margin-bottom:4px; cursor:' + (t.refuse ? 'not-allowed' : 'pointer') + ';' + (t.refuse ? ' opacity:.55;' : '') + '">'
            + '<input type="radio" name="aicli-target-' + kind + '" value="' + esc(t.path) + '" data-picked-via="' + esc(t.picked_via || '') + '" style="margin-top:3px;"' + disabled + (t.current && !t.refuse ? ' checked' : '') + '>'
            + '<div style="flex:1; min-width:0;">'
            +   '<div style="font-size:12px; font-weight:600;">' + esc(t.label) + ' ' + badges + '</div>'
            +   '<div style="font-family:monospace; font-size:10px; opacity:.75; word-break:break-all;">' + esc(t.path) + '</div>'
            +   note
            +   '<div style="margin-top:2px;">' + meta + '</div>'
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
                html += '<div style="opacity:.7; margin-top:2px;"><i class="fa fa-link"></i> Exclusive share — will be stored as <code>' + esc(resolved) + '</code></div>';
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

