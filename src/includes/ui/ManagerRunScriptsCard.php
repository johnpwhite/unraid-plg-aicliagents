<?php
/**
 * <module_context>
 *     <name>ManagerRunScriptsCard</name>
 *     <description>The Settings card "Script runs" (docs/specs/SCRIPT_RUNS_CARD_REDESIGN.md, issue #788). It holds
 *     the master switch, the short Authorised scripts list, the dialog template and the Advanced limits.</description>
 *     <dependencies>$config and $autoSave from ManagerConfigTab.php; RunScriptsHandler; help/runs.php; the
 *     assets/ui/run-scripts-card.js behaviour.</dependencies>
 *     <constraints>The settings controls keep their names and auto-save. Controls in the inert dialog template have
 *     no name. The list is saved only through the Settings AJAX actions. Dynamic text uses textContent.</constraints>
 * </module_context>
 */
$runsEnabled = (string)($config['run_enabled'] ?? '0') === '1';
$runDescriptions = \AICliAgents\Services\AgentRunService::describeList();
$runsCount = count($runDescriptions);
$runsAttention = count(array_filter($runDescriptions, static fn(array $entry): bool => (string)($entry['attention'] ?? '') !== ''));
$stRuns = ($runsEnabled ? 'On' : 'Off') . ' · ' . $runsCount . ($runsCount === 1 ? ' script' : ' scripts')
    . ($runsAttention > 0 ? ' · ' . $runsAttention . ' needs attention' : '');
?>
            <?=aicli_card_open('script-runs', 'fa-play-circle', 'Script runs', $stRuns, 'card-script-runs', '')?>
                    <dl>
                        <dt>Let scripts start agent runs <?=aicli_help_btn('run-enabled', 'Let scripts start agent runs')?></dt>
                        <dd>
                            <select name="run_enabled" aria-label="Let scripts start agent runs" data-testid="run-enabled" style="width:100%;" <?=$autoSave?>>
                                <?=mk_option($config['run_enabled'] ?? '0', '1', _('On'))?>
                                <?=mk_option($config['run_enabled'] ?? '0', '0', _('Off (default)'))?>
                            </select>
                            <?=aicli_help_panel('run-enabled')?>
                        </dd>
                        <dt>Authorised scripts <?=aicli_help_btn('run-scripts', 'Authorised scripts')?></dt>
                        <dd>
                            <p id="aicli-runs-off" class="aicli-runs-off" hidden>Script runs are off. No script on this list can start a run.</p>
                            <ul id="aicli-runs-list" class="aicli-runs-list" data-testid="runs-list" aria-label="Authorised scripts"></ul>
                            <button type="button" class="aicli-btn-slim aicli-runs-btn" id="aicli-runs-add" data-testid="runs-add"><i class="fa fa-plus" aria-hidden="true"></i> Add a script</button>
                            <div id="aicli-runs-msg" class="aicli-runs-msg" role="status" data-testid="runs-msg" aria-live="polite"></div>
                            <?=aicli_help_panel('run-scripts')?>
                        </dd>
                    </dl>
                    <template id="aicli-runs-dialog-tpl">
                        <div class="hb-backdrop runs-backdrop">
                            <div class="hb-dialog runs-dialog" role="dialog" aria-modal="true" aria-labelledby="runs-title" tabindex="-1">
                                <header class="hb-header"><h2 class="hb-title" id="runs-title" tabindex="-1"></h2><button type="button" class="acp-x runs-close" aria-label="Close">×</button></header>
                                <div class="hb-body runs-body">
                                    <div class="runs-callout" data-role="stale-callout" role="note" hidden>
                                        <p><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <strong>This entry points at a temporary copy.</strong> User Scripts makes a new copy for each run. The plugin checks the original file, so this entry can never match a call.</p>
                                        <p data-role="stale-original"></p>
                                        <div class="runs-callout-actions"><button type="button" class="aicli-btn-slim" data-action="repoint"></button> <?=aicli_help_btn('run-script-stale', 'Temporary User Scripts copy')?> <?=aicli_help_panel('run-script-stale')?></div>
                                    </div>
                                    <div class="runs-error" data-role="error" role="alert" hidden></div>
                                    <section class="runs-view" data-view-group="what-runs"><h3>What runs</h3><dl class="runs-facts">
                                        <dt>Name</dt><dd><span data-view="name"></span><span class="hb-muted" data-view="name-source"></span></dd>
                                        <dt>Description</dt><dd data-view="description"></dd>
                                        <dt>Script file</dt><dd><strong data-view="script-kind"></strong><code class="runs-path" data-view="path"></code></dd>
                                        <dt>Fingerprint <?=aicli_help_btn('run-script-fingerprint', 'Fingerprint')?> <?=aicli_help_panel('run-script-fingerprint')?></dt><dd><span data-view="fingerprint"></span><button type="button" class="aicli-btn-slim runs-inline-action" data-action="trust" hidden>Trust the file as it is now</button><details class="runs-hash"><summary>Show the fingerprint</summary><code data-view="hash"></code><button type="button" class="aicli-btn-slim runs-inline-action" data-action="copy-hash">Copy</button></details></dd>
                                    </dl></section>
                                    <section class="runs-view" data-view-group="what-may-do"><h3>What it may do</h3><dl class="runs-facts">
                                        <dt>Agents</dt><dd data-view="agents"></dd>
                                        <dt>Folders</dt><dd data-view="folders"></dd>
                                        <dt>Highest mode</dt><dd data-view="mode"></dd>
                                        <dt>Run as</dt><dd>root</dd>
                                    </dl></section>
                                    <section class="runs-view" data-view-group="limits"><h3>Limits</h3><dl class="runs-facts"><dt>Runs each day</dt><dd data-view="perday"></dd></dl></section>
                                    <fieldset class="hb-fields runs-edit" data-edit-group="what-runs" hidden><legend>What runs</legend>
                                        <div class="hb-field"><div class="hb-label-row"><label class="hb-label" for="runs-edit-name">Name</label> <?=aicli_help_btn('run-script-name', 'Name')?> <?=aicli_help_panel('run-script-name')?></div><input id="runs-edit-name" type="text" maxlength="60" autocomplete="off"></div>
                                        <div class="hb-field"><div class="hb-label-row"><label class="hb-label" for="runs-edit-description">Description</label> <?=aicli_help_btn('run-script-description', 'Description')?> <?=aicli_help_panel('run-script-description')?></div><input id="runs-edit-description" type="text" maxlength="120" autocomplete="off" aria-describedby="runs-description-count"><span id="runs-description-count" class="hb-help" data-role="description-count" aria-live="off"></span></div>
                                        <div class="runs-existing-path" data-role="existing-path" hidden><span class="hb-label">Script file</span><code class="runs-path" data-role="existing-path-text"></code></div>
                                        <div class="runs-add-fields" data-role="add-fields" hidden>
                                            <fieldset class="hb-fields runs-source"><legend>Where is the script?</legend><label class="runs-choice"><input type="radio" data-source="userScript"> A User Script</label><label class="runs-choice"><input type="radio" data-source="file"> Another script file</label></fieldset>
                                            <div class="hb-field" data-role="user-script-picker"><span class="hb-label">Choose a User Script</span><input class="runs-filter" data-role="user-script-filter" type="text" placeholder="Filter scripts" autocomplete="off" hidden><div class="runs-user-script-list" data-role="user-script-list"></div><p class="hb-help" data-role="user-script-empty" hidden></p><code class="runs-path" data-role="selected-script-path" hidden></code></div>
                                            <div class="hb-field" data-role="file-picker" hidden><div class="hb-label-row"><label class="hb-label" for="runs-add-path">Script file</label> <?=aicli_help_btn('run-script-file', 'Script file')?> <?=aicli_help_panel('run-script-file')?></div><div class="hb-target-row"><input id="runs-add-path" type="text" autocomplete="off" spellcheck="false" placeholder="/boot/config/plugins/user.scripts/scripts/name/script"><button type="button" class="aicli-btn-slim" data-action="browse-file">Browse…</button></div><input id="runs-add-path-picker" class="runs-hidden-input" type="text" value="/boot/config"><p class="runs-path-check" data-role="path-check" aria-live="polite"></p><button type="button" class="aicli-btn-slim runs-inline-action" data-action="use-original" hidden>Use the original script</button></div>
                                        </div>
                                        <div class="runs-fingerprint-edit"><span class="hb-label">Fingerprint</span><p data-role="edit-fingerprint"></p><label class="hb-check-label" data-role="trust-wrap" hidden><input type="checkbox" data-control="trust"> Trust the file as it is now</label></div>
                                    </fieldset>
                                    <fieldset class="hb-fields runs-edit" data-edit-group="what-may-do" hidden><legend>What it may do</legend>
                                        <div class="hb-field"><div class="hb-label-row"><span class="hb-label">Agents it may start</span> <?=aicli_help_btn('run-script-agents', 'Agents it may start')?> <?=aicli_help_panel('run-script-agents')?></div><div class="runs-agent-list" data-role="agents"></div></div>
                                        <div class="hb-field"><div class="hb-label-row"><label class="hb-label" for="runs-edit-folders">Folders it may use</label> <?=aicli_help_btn('run-script-folders', 'Folders it may use')?> <?=aicli_help_panel('run-script-folders')?></div><textarea id="runs-edit-folders" rows="3" placeholder="/mnt/cache/appdata/example"></textarea><div class="runs-target-row"><button type="button" class="aicli-btn-slim" data-action="browse-folder">Browse…</button><input id="runs-folder-path-picker" class="runs-hidden-input" type="text" value="/mnt"></div></div>
                                        <div class="hb-field"><div class="hb-label-row"><span class="hb-label">Highest mode</span> <?=aicli_help_btn('run-script-mode', 'Highest mode')?> <?=aicli_help_panel('run-script-mode')?></div><label class="runs-choice runs-mode-choice"><input type="radio" data-mode="read-only"> <span><strong>Read-only</strong><span>Starts at once and only reads files.</span></span></label><label class="runs-choice runs-mode-choice"><input type="radio" data-mode="may-change"> <span><strong>May change files</strong><span>Waits for your approval each time.</span></span></label></div>
                                        <div class="hb-field"><div class="hb-label-row"><span class="hb-label">Run as</span> <?=aicli_help_btn('run-script-users', 'Run as')?> <?=aicli_help_panel('run-script-users')?></div><span class="runs-root">root</span></div>
                                    </fieldset>
                                    <fieldset class="hb-fields runs-edit" data-edit-group="limits" hidden><legend>Limits</legend><div class="hb-field"><div class="hb-label-row"><label class="hb-label" for="runs-edit-perday">Runs each day</label> <?=aicli_help_btn('run-script-perday', 'Runs each day')?> <?=aicli_help_panel('run-script-perday')?></div><input id="runs-edit-perday" type="number" inputmode="numeric" min="1" max="1000" value="6"></div></fieldset>
                                    <div class="runs-discard" data-role="discard" role="group" aria-label="Discard changes" hidden><span>Discard your changes?</span><button type="button" class="aicli-btn-slim" data-action="discard">Discard</button><button type="button" class="aicli-btn-slim" data-action="keep-editing">Keep editing</button></div>
                                </div>
                                <footer class="hb-footer runs-footer"></footer>
                            </div>
                        </div>
                    </template>
            <?=aicli_adv_open('script-runs', 'Script runs')?>
                    <dl>
                        <dt>Runs open at one time <?=aicli_help_btn('run-caps', 'Runs open at one time')?></dt>
                        <dd><div class="input-row"><input type="number" name="run_max_concurrent" aria-label="Runs open at one time" data-testid="run-max-concurrent" value="<?=htmlspecialchars((string)($config['run_max_concurrent'] ?? '2'), ENT_QUOTES, 'UTF-8')?>" min="1" max="10" style="width:5em;" <?=$autoSave?>><span style="opacity:.75;font-size:11px;">open ·</span><input type="number" name="run_daily_budget" aria-label="Runs each day, all scripts" data-testid="run-daily-budget" value="<?=htmlspecialchars((string)($config['run_daily_budget'] ?? '6'), ENT_QUOTES, 'UTF-8')?>" min="1" max="500" style="width:5em;" <?=$autoSave?>><span style="opacity:.75;font-size:11px;">each day</span></div><?=aicli_help_panel('run-caps')?></dd>
                        <dt>Wait before the same problem runs again <?=aicli_help_btn('run-cooldown', 'Wait before the same problem runs again')?></dt><dd><input type="number" name="run_cooldown_hours" aria-label="Hours to wait before the same problem runs again" data-testid="run-cooldown-hours" value="<?=htmlspecialchars((string)($config['run_cooldown_hours'] ?? '24'), ENT_QUOTES, 'UTF-8')?>" min="0" max="720" style="width:6em;" <?=$autoSave?>> hours <?=aicli_help_panel('run-cooldown')?></dd>
                        <dt>Time to approve a run <?=aicli_help_btn('run-approval', 'Time to approve a run')?></dt><dd><input type="number" name="run_approval_hours" aria-label="Hours to approve a run" data-testid="run-approval-hours" value="<?=htmlspecialchars((string)($config['run_approval_hours'] ?? '24'), ENT_QUOTES, 'UTF-8')?>" min="1" max="168" style="width:6em;" <?=$autoSave?>> hours <?=aicli_help_panel('run-approval')?></dd>
                        <dt>Time limit for a run <?=aicli_help_btn('run-time-limit', 'Time limit for a run')?></dt><dd><input type="number" name="run_max_minutes" aria-label="Minutes before a run closes" data-testid="run-max-minutes" value="<?=htmlspecialchars((string)($config['run_max_minutes'] ?? '60'), ENT_QUOTES, 'UTF-8')?>" min="1" max="1440" style="width:6em;" <?=$autoSave?>> minutes <?=aicli_help_panel('run-time-limit')?></dd>
                        <dt>Notify me when a run starts and ends <?=aicli_help_btn('run-notify', 'Notify me when a run starts and ends')?></dt><dd><select name="run_notify_routine" aria-label="Notify me when a run starts and ends" data-testid="run-notify-routine" style="width:100%;" <?=$autoSave?>><?=mk_option($config['run_notify_routine'] ?? '1', '1', _('Yes (default)'))?><?=mk_option($config['run_notify_routine'] ?? '1', '0', _('No'))?></select><?=aicli_help_panel('run-notify')?></dd>
                        <dt>Folder for temporary run folders <?=aicli_help_btn('run-temp-root', 'Folder for temporary run folders')?></dt><dd><input type="text" name="run_temp_root" aria-label="Folder for temporary run folders" data-testid="run-temp-root" value="<?=htmlspecialchars((string)($config['run_temp_root'] ?? ''), ENT_QUOTES, 'UTF-8')?>" placeholder="<?=htmlspecialchars(_('Appdata pool path + /_tmp/aicliagents-runs'), ENT_QUOTES, 'UTF-8')?>" maxlength="300" spellcheck="false" style="width:100%;" <?=$autoSave?>><?=aicli_help_panel('run-temp-root')?></dd>
                        <dt>Keep a temporary folder after the run for <?=aicli_help_btn('run-temp-keep', 'Keep a temporary folder after the run for')?></dt><dd><input type="number" name="run_temp_keep_hours" aria-label="Hours to keep a temporary run folder after the run closed" data-testid="run-temp-keep-hours" value="<?=htmlspecialchars((string)($config['run_temp_keep_hours'] ?? '24'), ENT_QUOTES, 'UTF-8')?>" min="1" max="720" style="width:6em;" <?=$autoSave?>> hours <?=aicli_help_panel('run-temp-keep')?></dd>
                    </dl>
            <?=aicli_adv_close()?>
            <?=aicli_card_close()?>
<?php
$aicli_runs_file = '/usr/local/emhttp/plugins/unraid-aicliagents/assets/ui/run-scripts-card.js';
$aicli_runs_ver = (string)(@filemtime($aicli_runs_file) ?: time());
?>
<script src="/plugins/unraid-aicliagents/assets/ui/run-scripts-card.js?v=<?=htmlspecialchars($aicli_runs_ver, ENT_QUOTES, 'UTF-8')?>"></script>
