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
<!-- TAB: CONFIG HUB -->
<div id="tab-hub" class="aicli-tab-content aicli-layout">
    <div style="width:100%;">

        <!-- Home-unavailable warning (shown when the agent home overlay is not mounted) -->
        <div id="hub-home-banner" style="display:none; margin-bottom:12px; padding:10px 14px; border:1px solid rgba(255,140,0,0.5); border-radius:4px; background:rgba(255,140,0,0.08); font-size:12px;">
            <i class="fa fa-exclamation-triangle" style="color:var(--orange, #ff8c00); margin-right:6px;"></i>
            The agent home is not mounted — server definitions can still be edited, but
            <strong>Apply to agents</strong> is unavailable until a session (or the array) brings the home online.
            Nothing is ever written to an unmounted home.
        </div>

        <!-- Drift banner (populated when hub_get_drift / hub_project report conflicts) -->
        <div id="hub-drift-banner" class="aicli-card" style="display:none; border-color:rgba(255,140,0,0.6);">
            <div class="aicli-card-header"><i class="fa fa-code-fork" style="color:var(--orange, #ff8c00);"></i> Config drift detected</div>
            <div class="aicli-card-body">
                <p style="font-size:11px; opacity:0.7; margin-top:0;">
                    A vendor config file was edited outside the hub. Drifted keys are never overwritten automatically —
                    choose per key: <strong>Adopt</strong> (pull the edit into the hub), <strong>Overwrite</strong>
                    (re-assert the hub value), or <strong>Release</strong> (stop managing the key).
                </p>
                <div id="hub-drift-list"></div>
            </div>
        </div>

        <!-- Canonical MCP server list -->
        <div class="aicli-card">
            <div class="aicli-card-header" style="display:flex; align-items:center; justify-content:space-between;">
                <span><i class="fa fa-plug"></i> MCP Servers</span>
                <span style="display:flex; gap:8px;">
                    <button type="button" class="aicli-btn-slim" onclick="hubOpenEditor(null)"><i class="fa fa-plus"></i> Add Server</button>
                    <button type="button" class="aicli-btn-slim" id="hub-apply-btn" onclick="hubApply('mcp')"><i class="fa fa-paper-plane"></i> Apply to agents</button>
                    <button type="button" class="aicli-btn-slim" onclick="hubLoadState()" title="Refresh"><i class="fa fa-refresh"></i></button>
                </span>
            </div>
            <div class="aicli-card-body">
                <p style="font-size:11px; opacity:0.6; margin-top:0;">
                    One canonical definition per MCP server, projected into each targeted agent's own config format
                    (Claude / Gemini / Qwen / OpenCode / Copilot JSON, Codex TOML, Goose YAML). Keys you created by
                    hand in vendor files are never touched.
                </p>
                <div id="hub-server-list" style="display:flex; flex-direction:column; gap:6px;">
                    <div style="font-size:11px; opacity:0.5;">Loading…</div>
                </div>
                <div id="hub-apply-results" style="display:none; margin-top:12px; padding:10px; border-radius:4px; background:rgba(255,255,255,0.03); font-size:11px;"></div>
            </div>
        </div>

        <!-- Global instructions (OP #1363 / H-02 phase 2) -->
        <div class="aicli-card">
            <div class="aicli-card-header" style="display:flex; align-items:center; justify-content:space-between;">
                <span><i class="fa fa-file-text-o"></i> Global Instructions</span>
                <span style="display:flex; gap:8px;">
                    <button type="button" class="aicli-btn-slim" id="hub-instr-apply-btn" onclick="hubApply('instr')"><i class="fa fa-paper-plane"></i> Apply to agents</button>
                </span>
            </div>
            <div class="aicli-card-body">
                <p style="font-size:11px; opacity:0.6; margin-top:0;">
                    One canonical instruction document (<code>hub/instructions/global.md</code>) projected into each
                    targeted agent's instruction file inside a managed fence. Claude Code receives a native
                    <code>@import</code> line (content stays single-sourced); Gemini and Codex&nbsp;/&nbsp;Copilot
                    (shared <code>AGENTS.md</code>) receive a copy of the content. Text outside the fence is never touched.
                </p>
                <textarea id="hub-instr-content" rows="20" spellcheck="false"
                          style="width:100%; font-family:monospace; font-size:11px; resize:vertical;"
                          placeholder="# Global agent instructions&#10;&#10;Shared guidance every targeted agent loads at session start…"></textarea>
                <div id="hub-instr-meta" style="font-size:10px; opacity:0.5; margin-top:2px;"></div>
                <div style="margin-top:10px;">
                    <span style="font-size:11px;">Project to agents <span style="opacity:0.5;">(opt-in — no agents are targeted by default)</span></span>
                    <div id="hub-instr-agent-checks" class="hub-agent-grid"></div>
                    <div id="hub-instr-agent-checks-empty" style="display:none; font-size:11px; opacity:0.6;">
                        No supported agents are installed yet — the document is stored and can be targeted later.
                    </div>
                </div>
                <div id="hub-instr-apply-results" style="display:none; margin-top:12px; padding:10px; border-radius:4px; background:rgba(255,255,255,0.03); font-size:11px;"></div>
            </div>
        </div>

        <!-- Skills & Commands (OP #1364 / H-03 phase 3 — mirrored-tree projection) -->
        <div class="aicli-card">
            <div class="aicli-card-header" style="display:flex; align-items:center; justify-content:space-between;">
                <span><i class="fa fa-magic"></i> Skills &amp; Commands</span>
                <span style="display:flex; gap:8px;">
                    <button type="button" class="aicli-btn-slim" onclick="hubOpenSkillEditor(null)"><i class="fa fa-plus"></i> Add Skill</button>
                    <button type="button" class="aicli-btn-slim" onclick="hubOpenCommandEditor(null)"><i class="fa fa-plus"></i> Add Command</button>
                    <button type="button" class="aicli-btn-slim" id="hub-tree-apply-btn" onclick="hubApply('tree')"><i class="fa fa-paper-plane"></i> Apply to agents</button>
                </span>
            </div>
            <div class="aicli-card-body">
                <p style="font-size:11px; opacity:0.6; margin-top:0;">
                    Hub-stored skills (a folder with <code>SKILL.md</code> + optional extra files) and slash
                    commands (one markdown file each), mirrored into the agents that have such a surface:
                    Claude&nbsp;Code (<code>~/.claude/skills/</code> + <code>~/.claude/commands/</code>) and
                    OpenCode (<code>~/.opencode/commands/</code>, commands only). The hub only ever touches
                    the copies it projected — skills/commands you placed in those directories yourself are
                    never modified or removed.
                </p>
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
                    <div style="margin-top:10px; display:flex; gap:8px;">
                        <button type="button" class="aicli-btn-slim" onclick="hubSaveCommand()"><i class="fa fa-check"></i> Save Command</button>
                        <button type="button" class="aicli-btn-slim" onclick="hubCloseCommandEditor()">Cancel</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- History — git-backed home config (OP #1365 / H-04, opt-in) -->
        <div class="aicli-card" id="hub-git-card">
            <div class="aicli-card-header" style="display:flex; align-items:center; justify-content:space-between;">
                <span><i class="fa fa-history"></i> History <span style="font-size:10px; opacity:0.5; font-weight:400;">(git config backup)</span></span>
                <span style="display:flex; gap:8px;">
                    <button type="button" class="aicli-btn-slim" id="hub-git-commit-btn" style="display:none;" onclick="hubGitCommit()"><i class="fa fa-save"></i> Commit now</button>
                    <button type="button" class="aicli-btn-slim" id="hub-git-restore-settings-btn" style="display:none;" onclick="hubGitRestoreSettings()" title="Overwrite the live plugin settings on flash with the last committed backup"><i class="fa fa-download"></i> Restore settings</button>
                    <button type="button" class="aicli-btn-slim" onclick="hubGitRefresh()" title="Refresh"><i class="fa fa-refresh"></i></button>
                </span>
            </div>
            <div class="aicli-card-body">
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
                                <input type="password" id="hub-git-token" autocomplete="new-password" placeholder="•••••••• (stored as HUB_GIT_TOKEN in the secrets vault)" style="width:280px; font-family:monospace; font-size:11px;">
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
            </div>
        </div>

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
                <div id="hub-f-stdio" style="margin-top:10px;">
                    <label style="font-size:11px;">Command<br>
                        <input type="text" id="hub-f-command" placeholder="/usr/local/bin/my-mcp" style="width:100%; max-width:520px;">
                    </label>
                    <label style="font-size:11px; display:block; margin-top:8px;">Arguments (one per line)<br>
                        <textarea id="hub-f-args" rows="3" style="width:100%; max-width:520px; font-family:monospace; font-size:11px;" placeholder="--port&#10;3000"></textarea>
                    </label>
                    <div style="margin-top:8px;">
                        <span style="font-size:11px;">Environment variables <span style="opacity:0.5;">(values are masked after save; <code>{SECRET_KEY}</code> placeholders resolve from the secrets vault at projection time)</span></span>
                        <div id="hub-env-rows" style="display:flex; flex-direction:column; gap:4px; margin-top:4px;"></div>
                        <button type="button" class="aicli-btn-slim" style="margin-top:4px;" onclick="hubAddEnvRow('', false)"><i class="fa fa-plus"></i> Add variable</button>
                    </div>
                </div>
                <div id="hub-f-remote" style="margin-top:10px; display:none;">
                    <label style="font-size:11px;">URL<br>
                        <input type="text" id="hub-f-url" placeholder="https://example.com/mcp" style="width:100%; max-width:520px;">
                    </label>
                </div>
                <div style="margin-top:12px;">
                    <span style="font-size:11px;">Enable for agents</span>
                    <div id="hub-agent-checks" class="hub-agent-grid"></div>
                    <div id="hub-agent-checks-empty" style="display:none; font-size:11px; opacity:0.6;">
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
