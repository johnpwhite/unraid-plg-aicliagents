<?php
/**
 * <module_context>
 * Description: HTML for the "Config Hub" tab (OP #1362 / H-01 phase 1 — canonical MCP
 *              server store with per-vendor projection; OP #1363 / H-02 phase 2 —
 *              global instruction-file projection; OP #1364 / H-03 phase 3 — Skills &
 *              Commands card: mirrored-tree skill/command store with per-surface
 *              target checkboxes and a v1 inline editor; OP #1365 / H-04 — History
 *              card for the opt-in git-backed home config: enable/init, status line,
 *              commit timeline with per-file diff + restore, manual commit, remote +
 *              masked token + explicit Push). Static skeleton only — server
 *              list, drift banner, editor form, instruction editor, skills/commands
 *              lists + editors, apply results and the git history UI are populated by
 *              ManagerHubScripts.php from the hub_get_state / hub_get_instructions /
 *              hub_list_skills / hub_list_commands / hub_project / hub_git_* AJAX actions.
 * Dependencies: $csrf_token (via ManagerGlobalState), ManagerHubScripts.php.
 * Constraints: Atomic UI fragment. UI decision per docs/specs/AGENT_CONFIG_HUB.md:
 *              PHP + fetch() tab (ManagerConfigTab precedent), NOT a second Vite entry.
 * </module_context>
 */
?>
<?php
require_once __DIR__ . '/SettingsCard.php'; // aicli_card_open and aicli_help_btn: the cards use the shared header (Phase 7).
?>
<?php
// The drift compare dialog is a React part of the shared UI bundle. It loads on the first Compare click
// (docs/specs/HUB_DRIFT_VIEW.md). The page only names the files and their version here.
$hubCmpDir = '/usr/local/emhttp/plugins/unraid-aicliagents/assets/ui';
$hubCmpCss = glob("$hubCmpDir/index*.css") ?: [];
sort($hubCmpCss);
$hubCmpVer = (string) max(array_merge([@filemtime("$hubCmpDir/index.js") ?: 0], array_map(static fn($f) => @filemtime($f) ?: 0, $hubCmpCss)));
if ($hubCmpVer === '0') { $hubCmpVer = (string) \AICliAgents\Services\ConfigService::getVersion(); }
?>
<!-- TAB: CONFIG HUB -->
<div id="tab-hub" class="aicli-tab-content aicli-layout">
    <div id="aicli-hub-compare-root" data-ver="<?= htmlspecialchars($hubCmpVer) ?>" data-css="<?= htmlspecialchars(implode(',', array_map('basename', $hubCmpCss))) ?>"></div>
    <?php // Same import map as AICliAgents.page: the lazy chunks import "./index.js" without ?v=, and must get the one module. It does nothing until the bundle loads. ?>
    <script type="importmap">{"imports":{"/plugins/unraid-aicliagents/assets/ui/index.js":"/plugins/unraid-aicliagents/assets/ui/index.js?v=<?= htmlspecialchars($hubCmpVer) ?>"}}</script>
    <div style="width:100%;">

        <?php /* Phase 7: the cards can be collapsed and moved. Reset layout forgets the saved order and the open state. */ ?>
        <div class="aicli-settings-toolbar">
            <button type="button" class="aicli-btn-slim" data-aicli-card-action="reset-layout" data-testid="reset-layout-hub">Reset layout</button>
        </div>

        <!-- Home-unavailable warning (shown when the agent home overlay is not mounted) -->
        <div id="hub-home-banner" style="display:none; margin-bottom:12px; padding:10px 14px; border:1px solid rgba(255,140,0,0.5); border-radius:4px; background:rgba(255,140,0,0.08); font-size:12px;">
            <i class="fa fa-exclamation-triangle" style="color:var(--orange, #ff8c00); margin-right:6px;"></i>
            The agent home is not mounted — server definitions can still be edited, but
            <strong>Apply to agents</strong> is unavailable until a session (or the array) brings the home online.
            Nothing is ever written to an unmounted home.
        </div>

        <div data-aicli-cards="hub">

        <!-- Drift card (populated when hub_get_drift / hub_project report conflicts). It is hidden until a drift shows, and then it opens itself. -->
        <?=aicli_card_open('hub-drift', 'fa-code-fork', 'Config drift detected', 'Loading…', 'hub-drift', '',
            ['domId' => 'hub-drift-banner', 'style' => 'display:none; border-color:rgba(255,140,0,0.6);', 'class' => 'aicli-card--warn'])?>
                <div id="hub-drift-list" role="list" aria-label="Files changed outside the hub"></div>
                <div id="hub-drift-live" class="hub-drift-live" role="status" aria-live="polite"></div>
        <?=aicli_card_close()?>

        <!-- Canonical MCP server list -->
        <?=aicli_card_open('hub-mcp', 'fa-plug', 'MCP Servers', 'Loading…', 'hub-mcp',
            '<span class="aicli-card-actions">'
            . '<button type="button" class="aicli-btn-slim" onclick="hubOpenEditor(null)"><i class="fa fa-plus"></i> Add Server</button>'
            . '<button type="button" class="aicli-btn-slim" id="hub-apply-btn" onclick="hubApply(\'mcp\')"><i class="fa fa-paper-plane"></i> Apply to agents</button>'
            . '<button type="button" class="aicli-btn-slim" onclick="hubLoadState()" title="Refresh" aria-label="Refresh"><i class="fa fa-refresh"></i></button>'
            . '</span>')?>
                <div id="hub-server-list" style="display:flex; flex-direction:column; gap:6px;">
                    <div style="font-size:11px; opacity:0.5;">Loading…</div>
                </div>
                <div id="hub-apply-results" style="display:none; margin-top:12px; padding:10px; border-radius:4px; background:rgba(255,255,255,0.03); font-size:11px;"></div>
        <?=aicli_card_close()?>

        <!-- Global instructions (OP #1363 / H-02 phase 2). The editor is closed until the person presses Edit. -->
        <?=aicli_card_open('hub-instr', 'fa-file-text-o', 'Global Instructions', 'Loading…', 'hub-instr',
            '<span class="aicli-card-actions">'
            . '<button type="button" class="aicli-btn-slim" id="hub-instr-edit-btn" data-testid="hub-instr-edit" aria-expanded="false" aria-controls="hub-instr-editor" onclick="hubToggleInstrEditor()"><i class="fa fa-pencil"></i> <span>Edit</span></button>'
            . '<button type="button" class="aicli-btn-slim" id="hub-instr-apply-btn" onclick="hubApply(\'instr\')"><i class="fa fa-paper-plane"></i> Apply to agents</button>'
            . '</span>')?>
                <div id="hub-instr-editor" data-testid="hub-instr-editor" hidden>
                    <textarea id="hub-instr-content" rows="20" spellcheck="false" aria-label="Global instructions"
                              style="width:100%; font-family:monospace; font-size:11px; resize:vertical;"
                              placeholder="# Global agent instructions&#10;&#10;Shared guidance every targeted agent loads at session start…"></textarea>
                    <div id="hub-instr-meta" style="font-size:10px; opacity:0.5; margin-top:2px;"></div>
                </div>
                <div style="margin-top:10px;">
                    <span style="font-size:11px;">Project to agents <span style="opacity:0.5;">(opt-in — no agents are targeted by default)</span></span>
                    <div id="hub-instr-agent-checks" class="hub-agent-grid"></div>
                    <div id="hub-instr-agent-checks-empty" style="display:none; font-size:11px; opacity:0.6;">
                        No supported agents are installed yet — the document is stored and can be targeted later.
                    </div>
                </div>
                <div id="hub-instr-apply-results" style="display:none; margin-top:12px; padding:10px; border-radius:4px; background:rgba(255,255,255,0.03); font-size:11px;"></div>
        <?=aicli_card_close()?>

        <!-- Skills & Commands (OP #1364 / H-03 phase 3 — mirrored-tree projection) -->
        <?=aicli_card_open('hub-skills', 'fa-magic', 'Skills & Commands', 'Loading…', 'hub-skills',
            '<span class="aicli-card-actions">'
            . '<button type="button" class="aicli-btn-slim" onclick="hubOpenSkillEditor(null)"><i class="fa fa-plus"></i> Add Skill</button>'
            . '<button type="button" class="aicli-btn-slim" onclick="hubOpenCommandEditor(null)"><i class="fa fa-plus"></i> Add Command</button>'
            . '<button type="button" class="aicli-btn-slim" id="hub-tree-apply-btn" onclick="hubApply(\'tree\')"><i class="fa fa-paper-plane"></i> Apply to agents</button>'
            . '</span>')?>
                <?php /* The agents of each surface are listed in the user guide page (ug-config-hub.md), no longer in this card. */ ?>
                <div style="display:flex; flex-wrap:wrap; gap:18px;">
                    <div style="flex:1; min-width:280px;">
                        <div style="font-size:11px; font-weight:700; margin-bottom:4px;">Skills</div>
                        <div id="hub-skill-list" style="display:flex; flex-direction:column; gap:6px;">
                            <div style="font-size:11px; opacity:0.5;">Loading…</div>
                        </div>
                        <div style="margin-top:8px;">
                            <span style="font-size:11px;">Project skills to <span style="opacity:0.5;">(opt-in)</span></span>
                            <div id="hub-skills-agent-checks" class="hub-agent-grid"></div>
                            <div id="hub-skills-agent-checks-empty" style="display:none; font-size:11px; opacity:0.6;">
                                No agent with a skills surface is installed yet.
                            </div>
                        </div>
                    </div>
                    <div style="flex:1; min-width:280px;">
                        <div style="font-size:11px; font-weight:700; margin-bottom:4px;">Commands</div>
                        <div id="hub-command-list" style="display:flex; flex-direction:column; gap:6px;">
                            <div style="font-size:11px; opacity:0.5;">Loading…</div>
                        </div>
                        <div style="margin-top:8px;">
                            <span style="font-size:11px;">Project commands to <span style="opacity:0.5;">(opt-in)</span></span>
                            <div id="hub-commands-agent-checks" class="hub-agent-grid"></div>
                            <div id="hub-commands-agent-checks-empty" style="display:none; font-size:11px; opacity:0.6;">
                                No agent with a commands surface is installed yet.
                            </div>
                        </div>
                    </div>
                </div>
                <div id="hub-tree-meta" style="font-size:10px; opacity:0.5; margin-top:8px;"></div>
                <div id="hub-tree-apply-results" style="display:none; margin-top:12px; padding:10px; border-radius:4px; background:rgba(255,255,255,0.03); font-size:11px;"></div>

                <!-- Skill editor (v1: filename + textarea per file; SKILL.md is mandatory) -->
                <div id="hub-skill-editor" style="display:none; margin-top:12px; border-top:1px solid rgba(128,128,128,0.25); padding-top:10px;">
                    <div style="font-size:11px; font-weight:700; margin-bottom:6px;" id="hub-skill-editor-title">Add Skill</div>
                    <label style="font-size:11px;">Skill name<br>
                        <input type="text" id="hub-skill-name" maxlength="64" pattern="[a-zA-Z0-9_-]+" placeholder="my-skill" style="width:220px;">
                    </label>
                    <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">A short identifier for this skill; it becomes the folder name inside each agent's skills directory, so use only letters, numbers, hyphens and underscores.</p>
                    <div id="hub-skill-files" style="display:flex; flex-direction:column; gap:8px; margin-top:8px;"></div>
                    <button type="button" class="aicli-btn-slim" style="margin-top:6px;" onclick="hubAddSkillFileRow('', '', false)"><i class="fa fa-plus"></i> Add file</button>
                    <div style="margin-top:10px; display:flex; gap:8px;">
                        <button type="button" class="aicli-btn-slim" onclick="hubSaveSkill()"><i class="fa fa-check"></i> Save Skill</button>
                        <button type="button" class="aicli-btn-slim" onclick="hubCloseSkillEditor()">Cancel</button>
                    </div>
                </div>

                <!-- Command editor (one markdown file) -->
                <div id="hub-command-editor" style="display:none; margin-top:12px; border-top:1px solid rgba(128,128,128,0.25); padding-top:10px;">
                    <div style="font-size:11px; font-weight:700; margin-bottom:6px;" id="hub-command-editor-title">Add Command</div>
                    <label style="font-size:11px;">Command name <span style="opacity:0.5;">(stored as <code>&lt;name&gt;.md</code>)</span><br>
                        <input type="text" id="hub-command-name" maxlength="64" pattern="[a-zA-Z0-9_-]+" placeholder="my-command" style="width:220px;">
                    </label>
                    <textarea id="hub-command-content" rows="10" spellcheck="false"
                              style="width:100%; font-family:monospace; font-size:11px; resize:vertical; margin-top:6px;"
                              placeholder="# /my-command&#10;&#10;What the command should do…"></textarea>
                    <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">The full text of the slash command, written in markdown; this is what the agent reads when someone runs the command.</p>
                    <div style="margin-top:10px; display:flex; gap:8px;">
                        <button type="button" class="aicli-btn-slim" onclick="hubSaveCommand()"><i class="fa fa-check"></i> Save Command</button>
                        <button type="button" class="aicli-btn-slim" onclick="hubCloseCommandEditor()">Cancel</button>
                    </div>
                </div>
        <?=aicli_card_close()?>

        <!-- History — git-backed home config (OP #1365 / H-04, opt-in) -->
        <?=aicli_card_open('hub-history', 'fa-history', 'History', 'Loading…', 'hub-history',
            '<span class="aicli-card-actions">'
            . '<button type="button" class="aicli-btn-slim" id="hub-git-commit-btn" style="display:none;" onclick="hubGitCommit()"><i class="fa fa-save"></i> Commit now</button>'
            . '<button type="button" class="aicli-btn-slim" id="hub-git-restore-settings-btn" style="display:none;" onclick="hubGitRestoreSettings()" title="Overwrite the live plugin settings on flash with the last committed backup"><i class="fa fa-download"></i> Restore settings</button>'
            . '<button type="button" class="aicli-btn-slim" onclick="hubGitRefresh()" title="Refresh" aria-label="Refresh"><i class="fa fa-refresh"></i></button>'
            . '</span>', ['domId' => 'hub-git-card'])?>
                <!-- git missing on the host -->
                <div id="hub-git-unavailable" style="display:none; padding:10px 14px; border:1px solid rgba(255,140,0,0.5); border-radius:4px; background:rgba(255,140,0,0.08); font-size:12px;">
                    <i class="fa fa-exclamation-triangle" style="color:var(--orange, #ff8c00); margin-right:6px;"></i>
                    <strong>git is not installed on this server.</strong> Install git via
                    <em>NerdTools</em> (Community Applications) to enable config backup — the plugin
                    does not bundle git.
                </div>
                <!-- opt-in / enable -->
                <div id="hub-git-disabled" style="display:none;">
                    <p style="font-size:11px; opacity:0.7; margin-top:0;">
                        Version your agent configuration with git: every hub save and projection is
                        auto-committed (debounced), giving you history, per-file diff and one-click
                        restore. A deny-by-default <code>.gitignore</code> is generated — only
                        whitelisted config files are ever tracked; OAuth/credential files
                        (<code>~/.claude.json</code>, <code>~/.gemini/mcp-oauth-tokens.json</code>,
                        <code>~/.codex/auth.json</code>) are explicitly excluded and can never enter
                        the repo. Nothing is pushed anywhere unless you configure a remote and press
                        Push yourself.
                    </p>
                    <button type="button" class="aicli-btn-slim" id="hub-git-enable-btn" onclick="hubGitEnable()"><i class="fa fa-toggle-on"></i> Enable config backup</button>
                </div>
                <!-- enabled: status + timeline -->
                <div id="hub-git-enabled-ui" style="display:none;">
                    <div id="hub-git-statusline" style="font-size:11px; opacity:0.75; margin-bottom:8px;"></div>
                    <div id="hub-git-timeline" style="display:flex; flex-direction:column; gap:4px;"></div>
                    <div id="hub-git-diffwrap" style="display:none; margin-top:10px;">
                        <div id="hub-git-difftitle" style="font-size:11px; font-weight:700; margin-bottom:4px;"></div>
                        <pre id="hub-git-diff" style="font-size:10px; max-height:300px; overflow:auto; background:rgba(0,0,0,0.25); padding:8px; border-radius:3px; white-space:pre-wrap;"></pre>
                    </div>
                    <!-- remote backup (explicit push ONLY — never automatic) -->
                    <div style="margin-top:14px; border-top:1px solid rgba(128,128,128,0.25); padding-top:10px;">
                        <div style="font-size:11px; font-weight:700; margin-bottom:4px;"><i class="fa fa-cloud-upload"></i> Remote backup <span style="font-weight:400; opacity:0.5;">(optional — push is always manual)</span></div>
                        <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;">
                            <label style="font-size:11px;">Remote URL (https)<br>
                                <input type="text" id="hub-git-remote-url" placeholder="https://github.com/you/agent-config.git" style="width:320px; font-family:monospace; font-size:11px;">
                            </label>
                            <label style="font-size:11px;">Access token<br>
                                <input type="password" data-aicli-eye id="hub-git-token" autocomplete="new-password" placeholder="•••••••• (stored as HUB_GIT_TOKEN in the secrets vault)" style="width:280px; font-family:monospace; font-size:11px;">
                            </label>
                            <button type="button" class="aicli-btn-slim" onclick="hubGitSetRemote()"><i class="fa fa-check"></i> Save remote</button>
                            <button type="button" class="aicli-btn-slim" id="hub-git-push-btn" onclick="hubGitPush()"><i class="fa fa-cloud-upload"></i> Push</button>
                        </div>
                        <div style="font-size:10px; opacity:0.5; margin-top:4px;">
                            The token is kept in the plugin's secrets vault (key <code>HUB_GIT_TOKEN</code>,
                            editable in the Secrets section) and is passed to git via the environment only —
                            never on a command line, never inside the remote URL.
                        </div>
                    </div>
                </div>
        <?=aicli_card_close()?>

        </div><!-- /data-aicli-cards="hub" -->


        <!-- Add / edit form (hidden until opened) -->
        <div id="hub-editor" class="aicli-card" style="display:none;">
            <div class="aicli-card-header"><i class="fa fa-pencil"></i> <span id="hub-editor-title">Add MCP Server</span></div>
            <div class="aicli-card-body">
                <div style="display:flex; flex-wrap:wrap; gap:14px;">
                    <label style="font-size:11px;">Name<br>
                        <input type="text" id="hub-f-name" maxlength="64" pattern="[a-zA-Z0-9_-]+" placeholder="my-server" style="width:220px;">
                    </label>
                    <label style="font-size:11px;">Transport<br>
                        <select id="hub-f-transport" onchange="hubTransportChanged()">
                            <option value="stdio">stdio (local command)</option>
                            <option value="http">http (streamable HTTP)</option>
                            <option value="sse">sse (server-sent events)</option>
                        </select>
                    </label>
                </div>
                <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">Name is a label you choose to identify this server. Transport is how it is reached — a program run on this box (stdio), or a remote web address (http or sse).</p>
                <div id="hub-f-stdio" style="margin-top:10px;">
                    <label style="font-size:11px;">Command<br>
                        <input type="text" id="hub-f-command" placeholder="/usr/local/bin/my-mcp" style="width:100%; max-width:520px;">
                    </label>
                    <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">The full path to the program that starts this MCP server on the Unraid box.</p>
                    <label style="font-size:11px; display:block; margin-top:8px;">Arguments (one per line)<br>
                        <textarea id="hub-f-args" rows="3" style="width:100%; max-width:520px; font-family:monospace; font-size:11px;" placeholder="--port&#10;3000"></textarea>
                    </label>
                    <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">Extra options passed to the command above, one per line, in the order the program expects them.</p>
                    <div style="margin-top:8px;">
                        <span style="font-size:11px;">Environment variables <span style="opacity:0.5;">(values are masked after you save; <code>{SECRET_KEY}</code> placeholders resolve from the secrets vault when applied to agents)</span></span>
                        <div id="hub-env-rows" style="display:flex; flex-direction:column; gap:4px; margin-top:4px;"></div>
                        <button type="button" class="aicli-btn-slim" style="margin-top:4px;" onclick="hubAddEnvRow('', false)"><i class="fa fa-plus"></i> Add variable</button>
                    </div>
                </div>
                <div id="hub-f-remote" style="margin-top:10px; display:none;">
                    <label style="font-size:11px;">URL<br>
                        <input type="text" id="hub-f-url" placeholder="https://example.com/mcp" style="width:100%; max-width:520px;">
                    </label>
                    <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">The full web address of the remote MCP server, including https://.</p>
                    <div style="margin-top:8px;">
                        <span style="font-size:11px;">Authentication</span>
                        <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">How the server checks who you are. Most servers (Home Assistant, GitHub, ...) use a bearer token. Choose None if the server needs no login. A token is never stored as typed text: store it under Secrets and use its key in braces.</p>
                        <style>
                            /* Segmented control (av2-seg base) sized to its content, with an even
                               1px frame and a neutral fill for the chosen option instead of the
                               orange frame + bottom bar used on the agent cards. */
                            #hub-editor .hub-auth-seg { width: auto; max-width: none; grid-auto-columns: auto; }
                            #hub-editor .hub-auth-seg label { padding: 5px 14px; box-shadow: inset 0 0 0 1px transparent; }
                            #hub-editor .hub-auth-seg input:checked + label {
                                background: rgba(127,127,127,0.22);
                                box-shadow: inset 0 0 0 1px rgba(127,127,127,0.55);
                            }
                        </style>
                        <div class="av2-seg hub-auth-seg" role="radiogroup" aria-label="Authentication">
                            <input type="radio" id="hub-auth-none" name="hub-auth-mode" value="none" onchange="hubAuthModeChanged()">
                            <label for="hub-auth-none">None</label>
                            <input type="radio" id="hub-auth-bearer-opt" name="hub-auth-mode" value="bearer" onchange="hubAuthModeChanged()">
                            <label for="hub-auth-bearer-opt">Bearer token</label>
                            <input type="radio" id="hub-auth-header-opt" name="hub-auth-mode" value="header" onchange="hubAuthModeChanged()">
                            <label for="hub-auth-header-opt">Custom header</label>
                        </div>
                        <div id="hub-auth-bearer" style="display:none; margin-top:6px;">
                            <div style="display:flex; gap:6px; align-items:center;">
                                <code style="font-size:11px; opacity:0.7; white-space:nowrap;">Authorization: Bearer</code>
                                <input type="password" data-aicli-eye id="hub-f-bearer" autocomplete="new-password" style="flex:1; max-width:360px; font-family:monospace; font-size:11px;">
                            </div>
                            <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">Type only the secret key in braces, for example <code>{MY_TOKEN}</code>. "Bearer " is added for you. The token itself lives under Secrets, and the hub resolves it when it applies the server to agents.</p>
                        </div>
                        <div id="hub-auth-header" style="display:none; margin-top:6px;">
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="text" id="hub-f-auth-name" placeholder="X-API-Key" style="width:180px; font-family:monospace; font-size:11px;">
                                <input type="password" data-aicli-eye id="hub-f-auth-val" autocomplete="new-password" style="flex:1; max-width:320px; font-family:monospace; font-size:11px;">
                            </div>
                            <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">For servers whose docs name another header, such as <code>X-API-Key</code>, or <code>Authorization</code> with Basic credentials. A header that carries a credential must use a <code>{SECRET_KEY}</code> value; the hub refuses typed secrets.</p>
                        </div>
                    </div>
                    <div style="margin-top:8px;">
                        <span style="font-size:11px;">Additional headers <span style="opacity:0.5;">(optional)</span></span>
                        <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">Only if the server's docs ask for extra headers, such as a tenant or workspace id. The login token belongs under Authentication above.</p>
                        <div id="hub-header-rows" style="display:flex; flex-direction:column; gap:4px; margin-top:4px;"></div>
                        <button type="button" class="aicli-btn-slim" style="margin-top:4px;" onclick="hubAddHeaderRow('', false)"><i class="fa fa-plus"></i> Add header</button>
                    </div>
                    <div style="margin-top:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <button type="button" class="aicli-btn-slim" id="hub-test-btn" onclick="hubTestServer()"><i class="fa fa-plug"></i> Test connection</button>
                        <span id="hub-test-result" style="font-size:11px;"></span>
                    </div>
                    <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">Connects from this Unraid server to the address above, with the values above, and does not save them. Redirects are not followed. The result shows the HTTP status and a short reason, never the reply of the server.</p>
                </div>
                <div style="margin-top:12px;">
                    <span style="font-size:11px;">Enable for agents</span>
                    <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">Tick each agent that should receive this server the next time you click Apply to agents.</p>
                    <div id="hub-agent-checks" class="hub-agent-grid"></div>
                    <div id="hub-agent-checks-empty" style="display:none; font-size:11px; opacity:0.75;">
                        No supported agents are installed yet — the server definition is stored and can be targeted later.
                    </div>
                </div>
                <div style="margin-top:14px; display:flex; gap:8px;">
                    <button type="button" class="aicli-btn-slim" onclick="hubSaveServer()"><i class="fa fa-check"></i> Save Server</button>
                    <button type="button" class="aicli-btn-slim" onclick="hubCloseEditor()">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>
