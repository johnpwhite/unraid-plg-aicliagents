<?php
/**
 * <module_context>
 *     <name>ManagerRunScriptsCard</name>
 *     <description>The Settings card "Script runs" (docs/specs/AGENT_RUN_ENTRY_POINT.md R9, issue #745, and
 *     docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md). It holds the master switch, the list of Authorised
 *     scripts (add, change, remove) and, in its Advanced part, the caps. Included by ManagerConfigTab.php between
 *     the Notebook card and the SSH keys card.</description>
 *     <dependencies>$config and $autoSave from ManagerConfigTab.php; the AJAX actions of RunScriptsHandler
 *     (run_scripts_list, run_scripts_save, run_scripts_remove); help/runs.php; window.aicliCards.setStatus.</dependencies>
 *     <constraints>The switch and the caps are named controls and save through autoSaveConfig(), like every other
 *     setting. The list editor has no `name` attribute: the form must never send it, the AJAX actions save it
 *     (Bug #710). Every control of the list is at least 44 px high. All text from the server is set with
 *     textContent, never as markup. No run user and no temporary folder in this slice: the card says so in the
 *     (i) text, not in a visible note.</constraints>
 * </module_context>
 */
$runsEnabled = (string)($config['run_enabled'] ?? '0') === '1';
$runsCount = count(\AICliAgents\Services\AgentRunService::loadList());
$stRuns = ($runsEnabled ? 'On' : 'Off') . ' · ' . $runsCount . ($runsCount === 1 ? ' script' : ' scripts');
?>
            <?=aicli_card_open('script-runs', 'fa-play-circle', 'Script runs', $stRuns, 'card-script-runs', '')?>
                    <style>
                        #aicli-runs-list { width: 100%; }
                        .aicli-runs-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid rgba(128,128,128,0.15); }
                        .aicli-runs-info { flex: 1 1 14em; min-width: 0; }
                        .aicli-runs-label { font-size: 12px; font-weight: 600; }
                        .aicli-runs-path { font-size: 11px; font-family: monospace; overflow-wrap: anywhere; }
                        .aicli-runs-meta { font-size: 11px; }
                        .aicli-runs-warn { font-weight: 600; }
                        .aicli-runs-btn { min-height: 44px; min-width: 44px; }
                        #aicli-runs-editor { width: 100%; margin-top: 12px; }
                        #aicli-runs-editor[hidden] { display: none !important; }
                        .aicli-runs-lbl { display: flex; align-items: center; gap: 6px; margin-top: 8px; }
                        #aicli-runs-editor label { font-size: 12px; }
                        #aicli-runs-editor input[type="text"], #aicli-runs-editor input[type="number"], #aicli-runs-editor select, #aicli-runs-editor textarea { width: 100%; min-height: 44px; box-sizing: border-box; }
                        #aicli-runs-editor input[type="checkbox"] { min-width: 24px; min-height: 24px; vertical-align: middle; }
                        #aicli-runs-msg { font-size: 12px; min-height: 1.4em; margin-top: 8px; }
                    </style>
                    <dl>
                        <dt>Let scripts start agent runs <?=aicli_help_btn('run-enabled', 'Let scripts start agent runs')?></dt>
                        <dd>
                            <select name="run_enabled" aria-label="Let scripts start agent runs" data-testid="run-enabled" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['run_enabled'] ?? '0', "1", _('On'))?>
                                <?=mk_option($config['run_enabled'] ?? '0', "0", _('Off (default)'))?>
                            </select>
                            <?=aicli_help_panel('run-enabled')?>
                        </dd>

                        <dt>Authorised scripts <?=aicli_help_btn('run-scripts', 'Authorised scripts')?></dt>
                        <dd>
                            <div id="aicli-runs-list" data-testid="runs-list" aria-live="polite"></div>
                            <div style="margin-top:8px;">
                                <button type="button" class="aicli-btn-slim aicli-runs-btn" id="aicli-runs-add" data-testid="runs-add"><i class="fa fa-plus" aria-hidden="true"></i> Add a script</button>
                            </div>
                            <?=aicli_help_panel('run-scripts')?>

                            <div id="aicli-runs-editor" data-testid="runs-editor" hidden>
                                <div class="aicli-runs-lbl"><label for="aicli-runs-f-path">Path of the script</label> <?=aicli_help_btn('run-script-path', 'Path of the script')?></div>
                                <input type="text" id="aicli-runs-f-path" placeholder="/boot/config/plugins/user.scripts/scripts/kev-check/script" autocomplete="off">
                                <?=aicli_help_panel('run-script-path')?>
                                <label for="aicli-runs-f-label">Name</label>
                                <input type="text" id="aicli-runs-f-label" maxlength="60" placeholder="Daily KEV check" autocomplete="off">
                                <div class="aicli-runs-lbl"><label for="aicli-runs-f-agents">Agents it may start</label> <?=aicli_help_btn('run-script-agents', 'Agents it may start')?></div>
                                <input type="text" id="aicli-runs-f-agents" placeholder="claude-code" autocomplete="off">
                                <?=aicli_help_panel('run-script-agents')?>
                                <div class="aicli-runs-lbl"><label for="aicli-runs-f-folders">Folders it may use</label> <?=aicli_help_btn('run-script-folders', 'Folders it may use')?></div>
                                <textarea id="aicli-runs-f-folders" rows="3" placeholder="/mnt/cache/appdata/example"></textarea>
                                <?=aicli_help_panel('run-script-folders')?>
                                <div class="aicli-runs-lbl"><label for="aicli-runs-f-mode">Highest mode</label> <?=aicli_help_btn('run-script-mode', 'Highest mode')?></div>
                                <select id="aicli-runs-f-mode">
                                    <option value="read-only">Read-only</option>
                                    <option value="may-change">May change (needs my approval)</option>
                                </select>
                                <?=aicli_help_panel('run-script-mode')?>
                                <div class="aicli-runs-lbl"><label for="aicli-runs-f-users">Run as</label> <?=aicli_help_btn('run-script-users', 'Run as')?></div>
                                <input type="text" id="aicli-runs-f-users" value="root" autocomplete="off">
                                <?=aicli_help_panel('run-script-users')?>
                                <div class="aicli-runs-lbl"><label for="aicli-runs-f-perday">Runs each day</label> <?=aicli_help_btn('run-script-perday', 'Runs each day')?></div>
                                <input type="number" id="aicli-runs-f-perday" min="1" max="1000" value="6">
                                <?=aicli_help_panel('run-script-perday')?>
                                <label id="aicli-runs-f-current-wrap" for="aicli-runs-f-current" hidden><input type="checkbox" id="aicli-runs-f-current"> Trust the file as it is now</label>
                                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;">
                                    <button type="button" class="aicli-btn-slim aicli-runs-btn" id="aicli-runs-save" data-testid="runs-save">Save</button>
                                    <button type="button" class="aicli-btn-slim aicli-runs-btn" id="aicli-runs-cancel" data-testid="runs-cancel">Cancel</button>
                                </div>
                            </div>
                            <div id="aicli-runs-msg" role="status" data-testid="runs-msg"></div>
                        </dd>
                    </dl>
            <?=aicli_adv_open('script-runs', 'Script runs')?>
                    <dl>
                        <dt>Runs open at one time <?=aicli_help_btn('run-caps', 'Runs open at one time')?></dt>
                        <dd>
                            <div class="input-row">
                                <input type="number" name="run_max_concurrent" aria-label="Runs open at one time" data-testid="run-max-concurrent" value="<?=htmlspecialchars((string)($config['run_max_concurrent'] ?? '2'), ENT_QUOTES, 'UTF-8')?>" min="1" max="10" style="width:5em;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">open ·</span>
                                <input type="number" name="run_daily_budget" aria-label="Runs each day, all scripts" data-testid="run-daily-budget" value="<?=htmlspecialchars((string)($config['run_daily_budget'] ?? '6'), ENT_QUOTES, 'UTF-8')?>" min="1" max="500" style="width:5em;" <?=$autoSave?>>
                                <span style="opacity:0.75; font-size:11px;">each day</span>
                            </div>
                            <?=aicli_help_panel('run-caps')?>
                        </dd>
                        <dt>Wait before the same problem runs again <?=aicli_help_btn('run-cooldown', 'Wait before the same problem runs again')?></dt>
                        <dd>
                            <input type="number" name="run_cooldown_hours" aria-label="Hours to wait before the same problem runs again" data-testid="run-cooldown-hours" value="<?=htmlspecialchars((string)($config['run_cooldown_hours'] ?? '24'), ENT_QUOTES, 'UTF-8')?>" min="0" max="720" style="width:6em;" <?=$autoSave?>> hours
                            <?=aicli_help_panel('run-cooldown')?>
                        </dd>
                        <dt>Time to approve a run <?=aicli_help_btn('run-approval', 'Time to approve a run')?></dt>
                        <dd>
                            <input type="number" name="run_approval_hours" aria-label="Hours to approve a run" data-testid="run-approval-hours" value="<?=htmlspecialchars((string)($config['run_approval_hours'] ?? '24'), ENT_QUOTES, 'UTF-8')?>" min="1" max="168" style="width:6em;" <?=$autoSave?>> hours
                            <?=aicli_help_panel('run-approval')?>
                        </dd>
                        <dt>Time limit for a run <?=aicli_help_btn('run-time-limit', 'Time limit for a run')?></dt>
                        <dd>
                            <input type="number" name="run_max_minutes" aria-label="Minutes before a run closes" data-testid="run-max-minutes" value="<?=htmlspecialchars((string)($config['run_max_minutes'] ?? '60'), ENT_QUOTES, 'UTF-8')?>" min="1" max="1440" style="width:6em;" <?=$autoSave?>> minutes
                            <?=aicli_help_panel('run-time-limit')?>
                        </dd>
                        <dt>Notify me when a run starts and ends <?=aicli_help_btn('run-notify', 'Notify me when a run starts and ends')?></dt>
                        <dd>
                            <select name="run_notify_routine" aria-label="Notify me when a run starts and ends" data-testid="run-notify-routine" style="width: 100%;" <?=$autoSave?>>
                                <?=mk_option($config['run_notify_routine'] ?? '1', "1", _('Yes (default)'))?>
                                <?=mk_option($config['run_notify_routine'] ?? '1', "0", _('No'))?>
                            </select>
                            <?=aicli_help_panel('run-notify')?>
                        </dd>
                    </dl>
            <?=aicli_adv_close()?>
            <?=aicli_card_close()?>
            <script>
            (function () {
                'use strict';
                var editingId = '';
                var scripts = [];

                function post(action, body) {
                    var csrf = (window.csrf_token || '');
                    var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=' + action + '&csrf_token=' + encodeURIComponent(csrf);
                    var payload = Object.assign({}, body, { csrf_token: csrf });
                    return fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams(payload).toString()
                    }).then(function (r) { return r.json(); });
                }
                function el(id) { return document.getElementById(id); }
                function node(tag, cls, text) {
                    var n = document.createElement(tag);
                    if (cls) n.className = cls;
                    if (text != null) n.textContent = text;
                    return n;
                }
                function say(text, bad) {
                    var m = el('aicli-runs-msg');
                    if (!m) return;
                    m.textContent = text || '';
                    m.style.color = bad ? '#f87171' : '';
                }
                function switchOn() {
                    var s = document.querySelector('select[name="run_enabled"]');
                    return !!s && s.value === '1';
                }
                function setStatus() {
                    if (!window.aicliCards) return;
                    var n = scripts.length;
                    window.aicliCards.setStatus('script-runs', (switchOn() ? 'On' : 'Off') + ' · ' + n + (n === 1 ? ' script' : ' scripts'));
                }
                function limitsText(s) {
                    var folders = (s.folders && s.folders.length) ? s.folders.join(', ') : 'temporary folder only';
                    return 'Agents: ' + ((s.agents || []).join(', ') || 'none') + ' · Folders: ' + folders + ' · Highest mode: ' + s.maxMode
                        + ' · Run as: ' + (s.users || []).join(', ') + ' · ' + s.maxPerDay + ' runs a day';
                }
                function row(s) {
                    var r = node('div', 'aicli-runs-row');
                    r.setAttribute('data-testid', 'runs-row');
                    var info = node('div', 'aicli-runs-info');
                    info.appendChild(node('div', 'aicli-runs-label', s.label));
                    info.appendChild(node('div', 'aicli-runs-path', s.path));
                    var state = s.fileState === 'same' ? 'the file is unchanged' : (s.fileState === 'changed' ? 'THE FILE CHANGED: it is refused until you trust it again' : 'THE FILE IS MISSING');
                    var meta = node('div', 'aicli-runs-meta', 'Fingerprint ' + s.shortHash + ' · ' + state);
                    if (s.fileState !== 'same') meta.className += ' aicli-runs-warn';
                    info.appendChild(meta);
                    info.appendChild(node('div', 'aicli-runs-meta', limitsText(s)));
                    r.appendChild(info);
                    var edit = node('button', 'aicli-btn-slim aicli-runs-btn', 'Edit');
                    edit.type = 'button';
                    edit.setAttribute('aria-label', 'Edit ' + s.label);
                    edit.addEventListener('click', function () { openEditor(s); });
                    var rm = node('button', 'aicli-btn-slim aicli-runs-btn', 'Remove');
                    rm.type = 'button';
                    rm.setAttribute('aria-label', 'Remove ' + s.label);
                    rm.addEventListener('click', function () { removeEntry(s); });
                    r.appendChild(edit);
                    r.appendChild(rm);
                    return r;
                }
                function load() {
                    var list = el('aicli-runs-list');
                    if (!list) return;
                    post('run_scripts_list', {}).then(function (d) {
                        while (list.firstChild) list.removeChild(list.firstChild);
                        scripts = (d && d.scripts) || [];
                        if (!scripts.length) list.appendChild(node('span', 'aicli-runs-meta', 'No script is authorised yet.'));
                        scripts.forEach(function (s) { list.appendChild(row(s)); });
                        setStatus();
                    }).catch(function () {
                        while (list.firstChild) list.removeChild(list.firstChild);
                        var bad = node('span', 'aicli-runs-meta', 'The list could not be loaded.');
                        bad.style.color = '#f87171';
                        list.appendChild(bad);
                    });
                }
                function openEditor(s) {
                    editingId = s ? s.id : '';
                    el('aicli-runs-f-path').value = s ? s.path : '';
                    el('aicli-runs-f-path').readOnly = !!s;
                    el('aicli-runs-f-label').value = s ? s.label : '';
                    el('aicli-runs-f-agents').value = s ? (s.agents || []).join(', ') : '';
                    el('aicli-runs-f-folders').value = s ? (s.folders || []).join('\n') : '';
                    el('aicli-runs-f-mode').value = s ? s.maxMode : 'read-only';
                    el('aicli-runs-f-users').value = s ? (s.users || []).join(', ') : 'root';
                    el('aicli-runs-f-perday').value = s ? s.maxPerDay : 6;
                    el('aicli-runs-f-current').checked = false;
                    el('aicli-runs-f-current-wrap').hidden = !s;
                    el('aicli-runs-editor').hidden = false;
                    say('');
                    el('aicli-runs-f-path').focus();
                }
                function closeEditor() {
                    el('aicli-runs-editor').hidden = true;
                    editingId = '';
                    say('');
                }
                function save() {
                    say('Saving…');
                    post('run_scripts_save', {
                        id: editingId,
                        path: el('aicli-runs-f-path').value,
                        label: el('aicli-runs-f-label').value,
                        agents: el('aicli-runs-f-agents').value,
                        folders: el('aicli-runs-f-folders').value,
                        maxMode: el('aicli-runs-f-mode').value,
                        users: el('aicli-runs-f-users').value,
                        maxPerDay: el('aicli-runs-f-perday').value,
                        authoriseCurrent: el('aicli-runs-f-current').checked ? '1' : ''
                    }).then(function (d) {
                        if (d && d.status === 'ok') { closeEditor(); say('Saved.'); load(); }
                        else say((d && d.message) || 'Not saved.', true);
                    }).catch(function () { say('Network error. Nothing was saved.', true); });
                }
                function removeEntry(s) {
                    swal({ title: 'Remove this script?', text: 'It can no longer start agent runs.', type: 'warning', showCancelButton: true, confirmButtonText: 'Remove' },
                    function (confirmed) {
                        if (!confirmed) return;
                        post('run_scripts_remove', { id: s.id }).then(function (d) {
                            if (d && d.status === 'ok') load(); else say((d && d.message) || 'Not removed.', true);
                        }).catch(function () { say('Network error. Nothing was removed.', true); });
                    });
                }
                document.addEventListener('DOMContentLoaded', function () {
                    if (!el('aicli-runs-list')) return;
                    el('aicli-runs-add').addEventListener('click', function () { openEditor(null); });
                    el('aicli-runs-save').addEventListener('click', save);
                    el('aicli-runs-cancel').addEventListener('click', closeEditor);
                    // Enter in a field saves the entry. It must never submit the settings form.
                    el('aicli-runs-editor').addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' && e.target && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'BUTTON') { e.preventDefault(); save(); }
                    });
                    var sw = document.querySelector('select[name="run_enabled"]');
                    if (sw) sw.addEventListener('change', setStatus);
                    load();
                });
            }());
            </script>
