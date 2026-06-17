<?php
/**
 * <module_context>
 * Description: JS logic for the Config Hub tab (OP #1362 / H-01 phase 1; OP #1363 /
 *              H-02 phase 2 — instructions; OP #1364 / H-03 phase 3 — skills/commands
 *              trees: hub_list_skills / hub_save_skill_file / hub_delete_skill /
 *              hub_list_commands / hub_save_command / hub_delete_command /
 *              hub_set_skills_targets / hub_set_commands_targets, v1 inline editors,
 *              per-surface target checkboxes; Apply rides the shared hubApply). Vanilla
 *              DOM + fetch() per the ManagerConfigTab.php precedent (NOT $.ajax) —
 *              see docs/specs/AGENT_CONFIG_HUB.md for the React-vs-PHP decision.
 *              Drives hub_get_state / hub_save_mcp_server / hub_delete_mcp_server /
 *              hub_get_instructions / hub_save_instructions / hub_set_instruction_targets /
 *              hub_project / hub_get_drift / hub_resolve_drift, renders the drift
 *              banner, and offers per-session signal_reload after a projection
 *              (never auto-restarts sessions). env values arrive MASKED (key + set
 *              flag) and are only re-sent when the user types a new value. One
 *              Apply button projects BOTH MCP servers and instructions.
 *              OP #1365 / H-04: History card — hub_git_status/init/commit/log/diff/
 *              restore/set_remote/push. Push is EXPLICIT only (button), never
 *              automatic; the PAT field is masked, cleared from the DOM after save,
 *              and the value is never echoed back by the backend.
 * Dependencies: window.csrf_token (ManagerGlobalState), SweetAlert (swal), ManagerHubTab.php.
 * Constraints: All user strings rendered via textContent (no innerHTML injection).
 * </module_context>
 */
?>
<script>
(function () {
    'use strict';

    var hubState = { servers: {}, agents: [], homeAvailable: false };
    var hubEditingName = null;

    function hubAjax(action, body) {
        var csrf = (window.csrf_token || '');
        var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=' + action + '&csrf_token=' + encodeURIComponent(csrf);
        var postBody = Object.assign({}, body || {}, { csrf_token: csrf });
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(postBody).toString()
        }).then(function (r) { return r.json(); });
    }

    function el(tag, css, text) {
        var e = document.createElement(tag);
        if (css) e.style.cssText = css;
        if (text != null) e.textContent = text;
        return e;
    }
    function clearNode(n) { while (n.firstChild) n.removeChild(n.firstChild); }

    // ---------- state ----------

    window.hubLoadState = function () {
        hubAjax('hub_get_state').then(function (res) {
            if (res.status !== 'ok') return;
            hubState = res;
            document.getElementById('hub-home-banner').style.display = res.homeAvailable ? 'none' : 'block';
            document.getElementById('hub-apply-btn').disabled = !res.homeAvailable;
            document.getElementById('hub-instr-apply-btn').disabled = !res.homeAvailable;
            document.getElementById('hub-tree-apply-btn').disabled = !res.homeAvailable;
            hubRenderServers();
            hubRenderInstructionTargets();
            hubLoadInstructions();
            hubRenderTreeTargets('skills');
            hubRenderTreeTargets('commands');
            hubLoadSkills();
            hubLoadCommands();
            if (res.homeAvailable) {
                hubAjax('hub_get_drift').then(function (d) {
                    hubRenderDrift((d && d.status === 'ok') ? d.drift : []);
                });
            } else {
                hubRenderDrift([]);
            }
        }).catch(function () { /* page may be mid-reload */ });
    };

    function agentLabel(id) {
        for (var i = 0; i < hubState.agents.length; i++) {
            if (hubState.agents[i].id === id) return hubState.agents[i].name || hubState.agents[i].label;
        }
        return id;
    }

    // ---------- server list ----------

    function hubRenderServers() {
        var list = document.getElementById('hub-server-list');
        clearNode(list);
        var names = Object.keys(hubState.servers).sort();
        if (!names.length) {
            list.appendChild(el('div', 'font-size:11px; opacity:0.5;', 'No MCP servers defined yet — click "Add Server".'));
            return;
        }
        names.forEach(function (name) {
            var s = hubState.servers[name];
            var row = el('div', 'display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid rgba(128,128,128,0.25); border-radius:4px;');
            var info = el('div', 'flex:1; min-width:0;');
            var head = el('div', 'display:flex; align-items:center; gap:8px;');
            head.appendChild(el('span', 'font-size:12px; font-weight:700;', name));
            head.appendChild(el('span', 'font-size:10px; opacity:0.55; border:1px solid rgba(128,128,128,0.4); border-radius:3px; padding:0 5px;', s.transport));
            info.appendChild(head);
            var summary = (s.transport === 'stdio')
                ? (s.command + (s.args && s.args.length ? ' ' + s.args.join(' ') : ''))
                : (s.url || '');
            info.appendChild(el('div', 'font-size:10px; font-family:monospace; opacity:0.6; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;', summary));
            var chips = el('div', 'display:flex; flex-wrap:wrap; gap:4px; margin-top:3px;');
            (s.enabledFor || []).forEach(function (id) {
                chips.appendChild(el('span', 'font-size:9px; padding:1px 6px; border-radius:8px; background:rgba(255,140,0,0.15); border:1px solid rgba(255,140,0,0.4);', agentLabel(id)));
            });
            if (!(s.enabledFor || []).length) {
                chips.appendChild(el('span', 'font-size:9px; opacity:0.45;', 'no agents targeted'));
            }
            info.appendChild(chips);
            row.appendChild(info);

            var editBtn = el('button', 'flex-shrink:0;');
            editBtn.type = 'button'; editBtn.className = 'aicli-btn-slim'; editBtn.title = 'Edit';
            editBtn.appendChild(Object.assign(document.createElement('i'), { className: 'fa fa-pencil' }));
            editBtn.addEventListener('click', function () { window.hubOpenEditor(name); });
            row.appendChild(editBtn);

            var delBtn = el('button', 'flex-shrink:0;');
            delBtn.type = 'button'; delBtn.className = 'aicli-btn-slim'; delBtn.title = 'Delete';
            delBtn.appendChild(Object.assign(document.createElement('i'), { className: 'fa fa-trash-o' }));
            delBtn.addEventListener('click', function () { hubDeleteServer(name); });
            row.appendChild(delBtn);

            list.appendChild(row);
        });
    }

    // ---------- agent target rows (shared by MCP / instructions / skills / commands) ----------

    // Render ONE agent target TILE (styles in ManagerStyles .hub-agent-tile). Agent name
    // is primary; the config path is a muted monospace second line. An installed agent is
    // a live tickable target (checked tiles accent in orange via :has(:checked)); an agent
    // wired for the surface but NOT installed is dimmed + disabled with a "not installed"
    // pill — the surface exists, the binary doesn't, and projection is install-gated.
    function hubAgentRow(a, cssClass, checked, onChange) {
        var off = !a.installed;
        var lab = document.createElement('label');
        lab.className = 'hub-agent-tile';
        if (off) lab.setAttribute('data-off', '');
        var cb = document.createElement('input');
        cb.type = 'checkbox'; cb.value = a.id; cb.className = cssClass;
        cb.checked = !!checked;
        if (off) { cb.disabled = true; cb.title = a.name + ' is not installed'; }
        else if (onChange) { cb.addEventListener('change', onChange); }
        lab.appendChild(cb);
        var body = document.createElement('span'); body.className = 'hub-agent-tile-body';
        var nm = document.createElement('span'); nm.className = 'hub-agent-tile-name'; nm.textContent = a.name;
        var pt = document.createElement('span'); pt.className = 'hub-agent-tile-path'; pt.textContent = a.file; pt.title = a.file;
        body.appendChild(nm); body.appendChild(pt);
        lab.appendChild(body);
        if (off) {
            var badge = document.createElement('span');
            badge.className = 'hub-agent-tile-badge'; badge.textContent = 'not installed';
            lab.appendChild(badge);
        }
        return lab;
    }

    // Installed agents first (live targets before greyed ones); stable within each group.
    function hubSortAgents(agents) {
        return (agents || []).slice().sort(function (x, y) { return (y.installed ? 1 : 0) - (x.installed ? 1 : 0); });
    }

    // ---------- instructions (H-02) ----------

    function hubRenderInstructionTargets() {
        var instr = hubState.instructions || { targets: [], agents: [] };
        var checks = document.getElementById('hub-instr-agent-checks');
        clearNode(checks);
        var agents = hubSortAgents(instr.agents);
        agents.forEach(function (a) {
            checks.appendChild(hubAgentRow(a, 'hub-instr-cb', (instr.targets || []).indexOf(a.id) >= 0, hubSaveInstructionTargets));
        });
        document.getElementById('hub-instr-agent-checks-empty').style.display = agents.length ? 'none' : 'block';
    }

    // Auto-save on tick — matches the skills/commands surfaces (tick = persisted), so a
    // subsequent "Apply to agents" projects what's on screen. Persists BOTH the current
    // document AND the ticked targets (previously instruction ticks only persisted via the
    // explicit "Save Instructions" button, so tick→Apply silently projected zero targets).
    function hubSaveInstructionTargets() {
        var content = document.getElementById('hub-instr-content').value;
        var ids = [];
        document.querySelectorAll('.hub-instr-cb:checked').forEach(function (cb) { ids.push(cb.value); });
        hubAjax('hub_save_instructions', { content: content }).then(function (res) {
            if (res.status !== 'ok') { swal('Save failed', res.message || 'unknown error', 'error'); return; }
            hubAjax('hub_set_instruction_targets', { agentIds: JSON.stringify(ids) }).then(function (res2) {
                if (res2.status !== 'ok') swal('Targets not saved', res2.message || 'unknown error', 'error');
            });
        });
    }

    function hubLoadInstructions() {
        hubAjax('hub_get_instructions').then(function (res) {
            if (res.status !== 'ok') return;
            var ta = document.getElementById('hub-instr-content');
            // Never stomp an edit in progress (state reloads after drift actions etc.)
            if (document.activeElement !== ta) ta.value = res.content || '';
            var bytes = new Blob([ta.value]).size;
            document.getElementById('hub-instr-meta').textContent =
                bytes + ' / ' + (res.maxBytes || 262144) + ' bytes';
        });
    }

    // There is no separate "Save" for instructions: ticking auto-persists (below) and
    // "Apply to agents" persists the latest document + targets, then projects.

    // ---------- skills + commands trees (H-03) ----------

    var hubSkills = {};
    var hubCommands = {};
    var hubEditingSkill = null;
    var hubEditingCommand = null;

    // Per-surface target checkboxes (skills: Claude Code; commands: Claude Code + OpenCode).
    // A change is persisted immediately; projection still waits for Apply.
    function hubRenderTreeTargets(surface) {
        var block = hubState[surface] || { targets: [], agents: [] };
        var checks = document.getElementById('hub-' + surface + '-agent-checks');
        clearNode(checks);
        var agents = hubSortAgents(block.agents);
        agents.forEach(function (a) {
            checks.appendChild(hubAgentRow(a, 'hub-' + surface + '-cb', (block.targets || []).indexOf(a.id) >= 0,
                function () { hubSaveTreeTargets(surface); }));
        });
        document.getElementById('hub-' + surface + '-agent-checks-empty').style.display = agents.length ? 'none' : 'block';
    }

    function hubSaveTreeTargets(surface) {
        var ids = [];
        document.querySelectorAll('.hub-' + surface + '-cb:checked').forEach(function (cb) { ids.push(cb.value); });
        hubAjax('hub_set_' + surface + '_targets', { agentIds: JSON.stringify(ids) }).then(function (res) {
            if (res.status !== 'ok') { swal('Targets not saved', res.message || 'unknown error', 'error'); return; }
            if (hubState[surface]) hubState[surface].targets = ids;
        });
    }

    function hubTreeMeta(totalBytes, maxTotalBytes) {
        document.getElementById('hub-tree-meta').textContent =
            'Store usage: ' + totalBytes + ' / ' + maxTotalBytes + ' bytes (256 KB per file).';
    }

    function hubLoadSkills() {
        hubAjax('hub_list_skills').then(function (res) {
            if (res.status !== 'ok') return;
            hubSkills = res.skills || {};
            hubTreeMeta(res.totalBytes || 0, res.maxTotalBytes || 2097152);
            hubRenderSkills();
        });
    }

    function hubRenderSkills() {
        var list = document.getElementById('hub-skill-list');
        clearNode(list);
        var names = Object.keys(hubSkills).sort();
        if (!names.length) {
            list.appendChild(el('div', 'font-size:11px; opacity:0.5;', 'No skills yet — click "Add Skill".'));
            return;
        }
        names.forEach(function (name) {
            var s = hubSkills[name];
            var row = el('div', 'display:flex; align-items:center; gap:10px; padding:6px 10px; border:1px solid rgba(128,128,128,0.25); border-radius:4px;');
            var info = el('div', 'flex:1; min-width:0;');
            info.appendChild(el('div', 'font-size:12px; font-weight:700;', name));
            info.appendChild(el('div', 'font-size:10px; opacity:0.55;',
                (s.files || []).length + ' file' + ((s.files || []).length === 1 ? '' : 's') + ' · ' + (s.bytes || 0) + ' bytes'));
            row.appendChild(info);
            var editBtn = el('button', 'flex-shrink:0;');
            editBtn.type = 'button'; editBtn.className = 'aicli-btn-slim'; editBtn.title = 'Edit';
            editBtn.appendChild(Object.assign(document.createElement('i'), { className: 'fa fa-pencil' }));
            editBtn.addEventListener('click', function () { window.hubOpenSkillEditor(name); });
            row.appendChild(editBtn);
            var delBtn = el('button', 'flex-shrink:0;');
            delBtn.type = 'button'; delBtn.className = 'aicli-btn-slim'; delBtn.title = 'Delete';
            delBtn.appendChild(Object.assign(document.createElement('i'), { className: 'fa fa-trash-o' }));
            delBtn.addEventListener('click', function () { hubDeleteSkill(name); });
            row.appendChild(delBtn);
            list.appendChild(row);
        });
    }

    window.hubOpenSkillEditor = function (name) {
        hubEditingSkill = name;
        document.getElementById('hub-skill-editor-title').textContent = name ? ('Edit Skill: ' + name) : 'Add Skill';
        var nameInput = document.getElementById('hub-skill-name');
        nameInput.value = name || '';
        nameInput.disabled = !!name;
        clearNode(document.getElementById('hub-skill-files'));
        if (name && hubSkills[name]) {
            hubSkills[name].files.forEach(function (f) { window.hubAddSkillFileRow(f.path, f.content, true); });
        } else {
            window.hubAddSkillFileRow('SKILL.md', '', false);
        }
        document.getElementById('hub-skill-editor').style.display = 'block';
        document.getElementById('hub-command-editor').style.display = 'none';
        document.getElementById('hub-skill-editor').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    window.hubCloseSkillEditor = function () {
        document.getElementById('hub-skill-editor').style.display = 'none';
        hubEditingSkill = null;
    };

    // One file row: path input + content textarea. SKILL.md (and already-saved
    // paths) keep a fixed path; removing a saved file deletes it server-side.
    window.hubAddSkillFileRow = function (path, content, existing) {
        var rows = document.getElementById('hub-skill-files');
        var row = el('div', 'display:flex; flex-direction:column; gap:2px;');
        row.className = 'hub-skill-file-row';
        var head = el('div', 'display:flex; gap:6px; align-items:center;');
        var p = document.createElement('input');
        p.type = 'text'; p.placeholder = 'path (e.g. SKILL.md or scripts/run.sh)'; p.className = 'hub-skill-file-path';
        p.style.cssText = 'width:280px; font-family:monospace; font-size:11px;';
        p.value = path || '';
        if (existing || path === 'SKILL.md') p.readOnly = true;
        head.appendChild(p);
        if (path !== 'SKILL.md') {
            var del = el('button', 'flex-shrink:0;');
            del.type = 'button'; del.className = 'aicli-btn-slim'; del.title = 'Remove file';
            del.appendChild(Object.assign(document.createElement('i'), { className: 'fa fa-trash-o' }));
            del.addEventListener('click', function () {
                if (!existing) { row.remove(); return; }
                swal({
                    title: 'Remove "' + path + '"?',
                    text: 'The file is removed from the hub skill now; projected copies are removed at the next "Apply to agents".',
                    type: 'warning', showCancelButton: true, confirmButtonText: 'Remove', cancelButtonText: 'Cancel', closeOnConfirm: true
                }, function (confirmed) {
                    if (!confirmed) return;
                    hubAjax('hub_delete_skill', { name: hubEditingSkill, path: path }).then(function (res) {
                        if (res.status !== 'ok') { swal('Remove failed', res.message || 'unknown error', 'error'); return; }
                        row.remove();
                        hubLoadSkills();
                    });
                });
            });
            head.appendChild(del);
        }
        row.appendChild(head);
        var ta = document.createElement('textarea');
        ta.rows = path === 'SKILL.md' ? 8 : 5;
        ta.spellcheck = false;
        ta.className = 'hub-skill-file-content';
        ta.style.cssText = 'width:100%; font-family:monospace; font-size:11px; resize:vertical;';
        ta.placeholder = (path === 'SKILL.md') ? '# Skill description (SKILL.md is required)…' : 'file content…';
        ta.value = content || '';
        row.appendChild(ta);
        rows.appendChild(row);
    };

    window.hubSaveSkill = function () {
        var name = document.getElementById('hub-skill-name').value.trim();
        if (!/^[a-zA-Z0-9_-]{1,64}$/.test(name)) {
            swal('Invalid name', 'Skill names may use letters, digits, _ and - (max 64 chars).', 'error');
            return;
        }
        var files = [];
        document.querySelectorAll('#hub-skill-files .hub-skill-file-row').forEach(function (row) {
            var p = row.querySelector('.hub-skill-file-path').value.trim();
            if (!p) return;
            files.push({ path: p, content: row.querySelector('.hub-skill-file-content').value });
        });
        // SKILL.md must save first — a new skill is anchored on it server-side.
        files.sort(function (a, b) { return (a.path === 'SKILL.md' ? -1 : 0) - (b.path === 'SKILL.md' ? -1 : 0); });
        if (!files.length || files[0].path !== 'SKILL.md') {
            swal('SKILL.md required', 'A skill needs a SKILL.md file as its anchor.', 'error');
            return;
        }
        var chain = Promise.resolve({ status: 'ok' });
        files.forEach(function (f) {
            chain = chain.then(function (prev) {
                if (prev.status !== 'ok') return prev;
                return hubAjax('hub_save_skill_file', { skill: name, path: f.path, content: f.content });
            });
        });
        chain.then(function (res) {
            if (res.status !== 'ok') { swal('Save failed', res.message || 'unknown error', 'error'); return; }
            window.hubCloseSkillEditor();
            hubLoadSkills();
        });
    };

    function hubDeleteSkill(name) {
        swal({
            title: 'Delete skill "' + name + '"?',
            text: 'The hub copy is removed now; projected copies are removed from agent homes at the next "Apply to agents". Skills you created in the agent dirs yourself are never touched.',
            type: 'warning', showCancelButton: true, confirmButtonText: 'Delete', cancelButtonText: 'Cancel', closeOnConfirm: true
        }, function (confirmed) {
            if (!confirmed) return;
            hubAjax('hub_delete_skill', { name: name }).then(function (res) {
                if (res.status !== 'ok') { swal('Delete failed', res.message || 'unknown error', 'error'); return; }
                if (hubEditingSkill === name) window.hubCloseSkillEditor();
                hubLoadSkills();
            });
        });
    }

    function hubLoadCommands() {
        hubAjax('hub_list_commands').then(function (res) {
            if (res.status !== 'ok') return;
            hubCommands = res.commands || {};
            hubRenderCommands();
        });
    }

    function hubRenderCommands() {
        var list = document.getElementById('hub-command-list');
        clearNode(list);
        var names = Object.keys(hubCommands).sort();
        if (!names.length) {
            list.appendChild(el('div', 'font-size:11px; opacity:0.5;', 'No commands yet — click "Add Command".'));
            return;
        }
        names.forEach(function (name) {
            var c = hubCommands[name];
            var row = el('div', 'display:flex; align-items:center; gap:10px; padding:6px 10px; border:1px solid rgba(128,128,128,0.25); border-radius:4px;');
            var info = el('div', 'flex:1; min-width:0;');
            info.appendChild(el('div', 'font-size:12px; font-weight:700;', '/' + name));
            info.appendChild(el('div', 'font-size:10px; opacity:0.55; font-family:monospace;', name + '.md · ' + (c.bytes || 0) + ' bytes'));
            row.appendChild(info);
            var editBtn = el('button', 'flex-shrink:0;');
            editBtn.type = 'button'; editBtn.className = 'aicli-btn-slim'; editBtn.title = 'Edit';
            editBtn.appendChild(Object.assign(document.createElement('i'), { className: 'fa fa-pencil' }));
            editBtn.addEventListener('click', function () { window.hubOpenCommandEditor(name); });
            row.appendChild(editBtn);
            var delBtn = el('button', 'flex-shrink:0;');
            delBtn.type = 'button'; delBtn.className = 'aicli-btn-slim'; delBtn.title = 'Delete';
            delBtn.appendChild(Object.assign(document.createElement('i'), { className: 'fa fa-trash-o' }));
            delBtn.addEventListener('click', function () { hubDeleteCommand(name); });
            row.appendChild(delBtn);
            list.appendChild(row);
        });
    }

    window.hubOpenCommandEditor = function (name) {
        hubEditingCommand = name;
        document.getElementById('hub-command-editor-title').textContent = name ? ('Edit Command: /' + name) : 'Add Command';
        var nameInput = document.getElementById('hub-command-name');
        nameInput.value = name || '';
        nameInput.disabled = !!name;
        document.getElementById('hub-command-content').value = (name && hubCommands[name]) ? (hubCommands[name].content || '') : '';
        document.getElementById('hub-command-editor').style.display = 'block';
        document.getElementById('hub-skill-editor').style.display = 'none';
        document.getElementById('hub-command-editor').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    window.hubCloseCommandEditor = function () {
        document.getElementById('hub-command-editor').style.display = 'none';
        hubEditingCommand = null;
    };

    window.hubSaveCommand = function () {
        var name = document.getElementById('hub-command-name').value.trim();
        if (!/^[a-zA-Z0-9_-]{1,64}$/.test(name)) {
            swal('Invalid name', 'Command names may use letters, digits, _ and - (max 64 chars).', 'error');
            return;
        }
        hubAjax('hub_save_command', { name: name, content: document.getElementById('hub-command-content').value }).then(function (res) {
            if (res.status !== 'ok') { swal('Save failed', res.message || 'unknown error', 'error'); return; }
            window.hubCloseCommandEditor();
            hubLoadCommands();
        });
    };

    function hubDeleteCommand(name) {
        swal({
            title: 'Delete command "/' + name + '"?',
            text: 'The hub copy is removed now; projected copies are removed from agent homes at the next "Apply to agents".',
            type: 'warning', showCancelButton: true, confirmButtonText: 'Delete', cancelButtonText: 'Cancel', closeOnConfirm: true
        }, function (confirmed) {
            if (!confirmed) return;
            hubAjax('hub_delete_command', { name: name }).then(function (res) {
                if (res.status !== 'ok') { swal('Delete failed', res.message || 'unknown error', 'error'); return; }
                if (hubEditingCommand === name) window.hubCloseCommandEditor();
                hubLoadCommands();
            });
        });
    }

    // ---------- editor ----------

    window.hubOpenEditor = function (name) {
        hubEditingName = name;
        var s = name ? hubState.servers[name] : null;
        document.getElementById('hub-editor-title').textContent = name ? ('Edit MCP Server: ' + name) : 'Add MCP Server';
        var nameInput = document.getElementById('hub-f-name');
        nameInput.value = name || '';
        nameInput.disabled = !!name;
        document.getElementById('hub-f-transport').value = s ? s.transport : 'stdio';
        document.getElementById('hub-f-command').value = s ? (s.command || '') : '';
        document.getElementById('hub-f-args').value = s && s.args ? s.args.join('\n') : '';
        document.getElementById('hub-f-url').value = s ? (s.url || '') : '';
        clearNode(document.getElementById('hub-env-rows'));
        if (s && s.env) s.env.forEach(function (e) { window.hubAddEnvRow(e.key, e.set); });

        var checks = document.getElementById('hub-agent-checks');
        clearNode(checks);
        hubSortAgents(hubState.agents).forEach(function (a) {
            checks.appendChild(hubAgentRow(a, 'hub-agent-cb', !!(s && (s.enabledFor || []).indexOf(a.id) >= 0), null));
        });
        document.getElementById('hub-agent-checks-empty').style.display = (hubState.agents || []).length ? 'none' : 'block';
        document.getElementById('hub-editor').style.display = 'block';
        hubTransportChanged();
        document.getElementById('hub-editor').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    window.hubCloseEditor = function () {
        document.getElementById('hub-editor').style.display = 'none';
        hubEditingName = null;
    };

    window.hubTransportChanged = function () {
        var t = document.getElementById('hub-f-transport').value;
        document.getElementById('hub-f-stdio').style.display = (t === 'stdio') ? 'block' : 'none';
        document.getElementById('hub-f-remote').style.display = (t === 'stdio') ? 'none' : 'block';
    };

    // Env row: existing (masked) values keep their stored value unless re-typed.
    window.hubAddEnvRow = function (key, isSet) {
        var rows = document.getElementById('hub-env-rows');
        var row = el('div', 'display:flex; gap:6px; align-items:center;');
        row.className = 'hub-env-row';
        var k = document.createElement('input');
        k.type = 'text'; k.placeholder = 'KEY'; k.className = 'hub-env-key';
        k.style.cssText = 'width:180px; font-family:monospace; font-size:11px;';
        k.value = key || '';
        if (key) k.readOnly = true;
        var v = document.createElement('input');
        v.type = 'password'; v.className = 'hub-env-val'; v.autocomplete = 'new-password';
        v.style.cssText = 'flex:1; max-width:320px; font-family:monospace; font-size:11px;';
        v.placeholder = isSet ? '•••••••• (unchanged)' : 'value or {SECRET_KEY}';
        v.dataset.keep = (key && isSet) ? '1' : '0';
        v.addEventListener('input', function () { v.dataset.keep = '0'; });
        var del = el('button', 'flex-shrink:0;');
        del.type = 'button'; del.className = 'aicli-btn-slim'; del.title = 'Remove variable';
        del.appendChild(Object.assign(document.createElement('i'), { className: 'fa fa-trash-o' }));
        del.addEventListener('click', function () { row.remove(); });
        row.appendChild(k); row.appendChild(v); row.appendChild(del);
        rows.appendChild(row);
    };

    window.hubSaveServer = function () {
        var name = document.getElementById('hub-f-name').value.trim();
        if (!/^[a-zA-Z0-9_-]{1,64}$/.test(name)) {
            swal('Invalid name', 'Server names may use letters, digits, _ and - (max 64 chars).', 'error');
            return;
        }
        var transport = document.getElementById('hub-f-transport').value;
        var env = [];
        document.querySelectorAll('#hub-env-rows .hub-env-row').forEach(function (row) {
            var k = row.querySelector('.hub-env-key').value.trim();
            if (!k) return;
            var v = row.querySelector('.hub-env-val');
            env.push({ key: k, value: v.value, keep: v.dataset.keep === '1' && v.value === '' });
        });
        var enabledFor = [];
        document.querySelectorAll('.hub-agent-cb:checked').forEach(function (cb) { enabledFor.push(cb.value); });
        var server = {
            transport: transport,
            command: document.getElementById('hub-f-command').value.trim(),
            args: document.getElementById('hub-f-args').value.split('\n').map(function (a) { return a.trim(); }).filter(Boolean),
            url: document.getElementById('hub-f-url').value.trim(),
            env: env,
            enabledFor: enabledFor
        };
        hubAjax('hub_save_mcp_server', { name: name, server: JSON.stringify(server) }).then(function (res) {
            if (res.status !== 'ok') { swal('Save failed', res.message || 'unknown error', 'error'); return; }
            window.hubCloseEditor();
            window.hubLoadState();
        });
    };

    function hubDeleteServer(name) {
        swal({
            title: 'Delete "' + name + '"?',
            text: 'The canonical definition is removed now; the entry is removed from agent configs at the next "Apply to agents".',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel',
            closeOnConfirm: true
        }, function (confirmed) {
            if (!confirmed) return;
            hubAjax('hub_delete_mcp_server', { name: name }).then(function (res) {
                if (res.status !== 'ok') { swal('Delete failed', res.message || 'unknown error', 'error'); return; }
                window.hubLoadState();
            });
        });
    }

    // ---------- projection ----------

    // Apply is a GLOBAL projection (MCP + instructions + skills/commands in one pass),
    // so any of the three "Apply to agents" buttons triggers it — status renders in the
    // card whose button was clicked. The instructions card has NO separate Save button:
    // its Apply persists the document + ticked targets FIRST, then projects (the server
    // then emits an Unraid notification of the per-agent instruction changes).
    var HUB_APPLY_BTNS = ['hub-apply-btn', 'hub-instr-apply-btn', 'hub-tree-apply-btn'];
    function hubApplyBtns(disabled) {
        HUB_APPLY_BTNS.forEach(function (id) { var b = document.getElementById(id); if (b) b.disabled = disabled; });
    }
    window.hubApply = function (ctx) {
        var boxId = (ctx === 'instr') ? 'hub-instr-apply-results'
                  : (ctx === 'tree')  ? 'hub-tree-apply-results'
                  : 'hub-apply-results';
        hubApplyBtns(true);
        var project = function () {
            hubAjax('hub_project').then(function (res) {
                hubApplyBtns(false);
                hubRenderApplyResult(res, boxId);
            });
        };
        if (ctx === 'instr') {
            // Single action: persist the document + ticked targets, THEN project.
            var content = document.getElementById('hub-instr-content').value;
            var ids = [];
            document.querySelectorAll('.hub-instr-cb:checked').forEach(function (cb) { ids.push(cb.value); });
            hubAjax('hub_save_instructions', { content: content }).then(function (r1) {
                if (r1.status !== 'ok') { hubApplyBtns(false); swal('Save failed', r1.message || 'unknown error', 'error'); return; }
                hubAjax('hub_set_instruction_targets', { agentIds: JSON.stringify(ids) }).then(function (r2) {
                    if (r2.status !== 'ok') { hubApplyBtns(false); swal('Targets not saved', r2.message || 'unknown error', 'error'); return; }
                    project();
                });
            });
        } else {
            project();
        }
    };

    function hubRenderApplyResult(res, boxId) {
        var box = document.getElementById(boxId);
        clearNode(box);
        box.style.display = 'block';
        if (res.status !== 'ok') {
            box.appendChild(el('div', 'color:var(--orange, #ff8c00);',
                (res.reason === 'home_unavailable')
                    ? 'Agent home is not mounted — nothing was written.'
                    : ('Projection failed: ' + (res.message || 'unknown error'))));
            return;
        }
        var results = res.results || {};
        var touched = 0;
        Object.keys(results).forEach(function (f) {
            var r = results[f];
            var n = (r.written || []).length + (r.removed || []).length;
            if (!n) return;
            touched++;
            box.appendChild(el('div', '', f + ' — ' + (r.written || []).length + ' written, ' + (r.removed || []).length + ' removed'));
        });
        if (!touched) box.appendChild(el('div', 'opacity:0.6;', 'All agent configs already up to date.'));
        if ((res.drift || []).length) {
            box.appendChild(el('div', 'color:var(--orange, #ff8c00); margin-top:4px;',
                (res.drift.length) + ' drifted key(s) were left untouched — resolve them in the drift panel above.'));
        }
        (res.affectedSessions || []).forEach(function (a) {
            var wrap = el('div', 'margin-top:6px;');
            wrap.appendChild(el('div', 'font-weight:700;', agentLabel(a.agentId) + ' has running session(s) — reload to pick up the new config:'));
            a.sessions.forEach(function (sess) {
                var line = el('div', 'display:flex; align-items:center; gap:8px; margin-top:3px;');
                line.appendChild(el('span', 'font-family:monospace; font-size:10px; opacity:0.7;', sess.id + (sess.path ? ' @ ' + sess.path : '')));
                var rb = el('button', '');
                rb.type = 'button'; rb.className = 'aicli-btn-slim'; rb.textContent = 'Reload agent';
                rb.addEventListener('click', function () {
                    rb.disabled = true;
                    hubAjax('agent_signal_reload&id=' + encodeURIComponent(sess.id)).then(function () { rb.textContent = 'Reload sent'; });
                });
                line.appendChild(rb);
                wrap.appendChild(line);
            });
            box.appendChild(wrap);
        });
        hubRenderDrift(res.drift || []);
    }

    // ---------- drift ----------

    function hubRenderDrift(drift) {
        var banner = document.getElementById('hub-drift-banner');
        var list = document.getElementById('hub-drift-list');
        clearNode(list);
        if (!drift || !drift.length) { banner.style.display = 'none'; return; }
        banner.style.display = 'block';
        drift.forEach(function (d) {
            var row = el('div', 'padding:8px 0; border-top:1px solid rgba(128,128,128,0.2);');
            var head = el('div', 'display:flex; align-items:center; gap:8px; flex-wrap:wrap;');
            head.appendChild(el('span', 'font-family:monospace; font-size:11px; font-weight:700;', d.file));
            head.appendChild(el('span', 'font-family:monospace; font-size:11px;', d.key));
            head.appendChild(el('span', 'font-size:10px; opacity:0.6;', '(' + d.kind + ')'));
            ['adopt', 'overwrite', 'release'].forEach(function (mode) {
                var b = el('button', '');
                b.type = 'button'; b.className = 'aicli-btn-slim';
                b.textContent = mode.charAt(0).toUpperCase() + mode.slice(1);
                b.addEventListener('click', function () { hubResolve(d, mode); });
                head.appendChild(b);
            });
            row.appendChild(head);
            var det = document.createElement('details');
            var sum = document.createElement('summary');
            sum.style.cssText = 'font-size:10px; opacity:0.6; cursor:pointer;';
            sum.textContent = 'show values';
            det.appendChild(sum);
            var pre = el('pre', 'font-size:10px; max-height:160px; overflow:auto; background:rgba(0,0,0,0.2); padding:6px; border-radius:3px;');
            pre.textContent = 'on disk (theirs):\n' + JSON.stringify(d.theirs, null, 2) + '\n\nhub value (ours):\n' + JSON.stringify(d.ours, null, 2);
            det.appendChild(pre);
            row.appendChild(det);
            list.appendChild(row);
        });
    }

    function hubResolve(d, mode) {
        var go = function () {
            hubAjax('hub_resolve_drift', { file: d.file, key: d.key, mode: mode }).then(function (res) {
                if (res.status !== 'ok') { swal('Could not ' + mode, res.message || 'unknown error', 'error'); return; }
                window.hubLoadState();
            });
        };
        if (mode === 'overwrite') {
            swal({
                title: 'Overwrite the on-disk edit?',
                text: 'The hub value replaces the manual edit in ' + d.file + ' (' + d.key + ').',
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Overwrite',
                cancelButtonText: 'Cancel',
                closeOnConfirm: true
            }, function (c) { if (c) go(); });
        } else {
            go();
        }
    }

    // ---------- git history (OP #1365 / H-04) ----------

    var hubGitStatus = null;

    window.hubGitRefresh = function () {
        hubAjax('hub_git_status').then(function (res) {
            if (res.status !== 'ok') return;
            hubGitStatus = res;
            hubGitRender();
            if (res.initialized) {
                hubAjax('hub_git_log', { limit: 20 }).then(function (lr) {
                    hubGitRenderTimeline((lr && lr.status === 'ok') ? lr.commits : []);
                });
            }
        }).catch(function () { /* page may be mid-reload */ });
    };

    function hubGitRender() {
        var s = hubGitStatus || {};
        document.getElementById('hub-git-unavailable').style.display = s.gitAvailable ? 'none' : 'block';
        var ready = s.gitAvailable && s.initialized && s.enabled;
        document.getElementById('hub-git-disabled').style.display = (s.gitAvailable && !ready) ? 'block' : 'none';
        document.getElementById('hub-git-enabled-ui').style.display = ready ? 'block' : 'none';
        document.getElementById('hub-git-commit-btn').style.display = ready ? '' : 'none';
        document.getElementById('hub-git-restore-settings-btn').style.display = ready ? '' : 'none';
        document.getElementById('hub-git-enable-btn').disabled = !s.homeAvailable;
        if (!ready) return;
        var line = 'Backup enabled — ' + (s.commits || 0) + ' commit' + (s.commits === 1 ? '' : 's')
            + ' · ' + (s.dirty ? (s.dirty + ' uncommitted change' + (s.dirty === 1 ? '' : 's')) : 'working tree clean')
            + ' · auto-commit ' + (s.autocommit ? 'on (30 s debounce)' : 'off')
            + (s.pending ? ' · changes pending commit' : '');
        if (s.lastCommit) line += ' · last: ' + new Date(s.lastCommit.ts * 1000).toLocaleString();
        document.getElementById('hub-git-statusline').textContent = line;
        var urlInput = document.getElementById('hub-git-remote-url');
        if (document.activeElement !== urlInput) urlInput.value = s.remote || '';
        document.getElementById('hub-git-token').placeholder =
            s.tokenSet ? '•••••••• (unchanged — stored as HUB_GIT_TOKEN)' : 'token (stored as HUB_GIT_TOKEN in the secrets vault)';
        document.getElementById('hub-git-push-btn').disabled = !(s.remote && s.tokenSet);
    }

    function hubGitRenderTimeline(commits) {
        var list = document.getElementById('hub-git-timeline');
        clearNode(list);
        if (!commits.length) {
            list.appendChild(el('div', 'font-size:11px; opacity:0.5;', 'No commits yet.'));
            return;
        }
        commits.forEach(function (c) {
            var det = document.createElement('details');
            det.style.cssText = 'border:1px solid rgba(128,128,128,0.25); border-radius:4px; padding:4px 8px;';
            var sum = document.createElement('summary');
            sum.style.cssText = 'cursor:pointer; display:flex; align-items:center; gap:8px; font-size:11px;';
            sum.appendChild(el('span', 'font-family:monospace; opacity:0.6;', c.short));
            sum.appendChild(el('span', 'opacity:0.55; font-size:10px;', new Date(c.ts * 1000).toLocaleString()));
            sum.appendChild(el('span', 'flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;', c.subject));
            det.appendChild(sum);
            var files = el('div', 'margin-top:4px; display:flex; flex-direction:column; gap:2px;');
            (c.files || []).forEach(function (f) {
                var row = el('div', 'display:flex; align-items:center; gap:8px; font-size:10px;');
                row.appendChild(el('span', 'font-family:monospace; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;', f));
                var db = el('button', '');
                db.type = 'button'; db.className = 'aicli-btn-slim'; db.textContent = 'Diff';
                db.addEventListener('click', function () { hubGitDiff(f, c); });
                row.appendChild(db);
                var rb = el('button', '');
                rb.type = 'button'; rb.className = 'aicli-btn-slim'; rb.textContent = 'Restore';
                rb.addEventListener('click', function () { hubGitRestore(f, c); });
                row.appendChild(rb);
                files.appendChild(row);
            });
            if (!(c.files || []).length) files.appendChild(el('div', 'font-size:10px; opacity:0.5;', 'no files recorded'));
            det.appendChild(files);
            list.appendChild(det);
        });
    }

    window.hubGitEnable = function () {
        swal({
            title: 'Enable config backup?',
            text: 'A git repository is created in the agent home with a deny-by-default .gitignore — only whitelisted config files are tracked; OAuth/credential files can never enter it. Hub saves then auto-commit (debounced 30 s).',
            type: 'info',
            showCancelButton: true,
            confirmButtonText: 'Enable',
            cancelButtonText: 'Cancel',
            closeOnConfirm: true
        }, function (confirmed) {
            if (!confirmed) return;
            var btn = document.getElementById('hub-git-enable-btn');
            btn.disabled = true;
            hubAjax('hub_git_init').then(function (res) {
                btn.disabled = false;
                if (res.status !== 'ok') { swal('Could not enable', res.message || 'unknown error', 'error'); return; }
                window.hubGitRefresh();
            });
        });
    };

    window.hubGitCommit = function () {
        swal({
            title: 'Commit now',
            text: 'Optional commit message (file names only — never paste secrets):',
            type: 'input',
            inputPlaceholder: 'hub: manual commit',
            showCancelButton: true,
            confirmButtonText: 'Commit',
            closeOnConfirm: true
        }, function (msg) {
            if (msg === false) return;
            hubAjax('hub_git_commit', { message: (msg || '').trim() }).then(function (res) {
                if (res.status === 'busy') { swal('Busy', res.message || 'a bake is in flight — retry shortly', 'warning'); return; }
                if (res.status !== 'ok') { swal('Commit failed', res.message || 'unknown error', 'error'); return; }
                window.hubGitRefresh();
            });
        });
    };

    function hubGitDiff(path, commit) {
        hubAjax('hub_git_diff', { path: path, ref: commit.hash }).then(function (res) {
            var wrap = document.getElementById('hub-git-diffwrap');
            var pre = document.getElementById('hub-git-diff');
            if (res.status !== 'ok') { swal('Diff failed', res.message || 'unknown error', 'error'); return; }
            document.getElementById('hub-git-difftitle').textContent =
                path + ' — current vs ' + commit.short + ' (what Restore would change back)';
            pre.textContent = res.identical ? '(no differences — current file matches this commit)' : res.diff;
            wrap.style.display = 'block';
            wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    }

    function hubGitRestore(path, commit) {
        swal({
            title: 'Restore "' + path + '"?',
            text: 'The file is reverted to its content at commit ' + commit.short + ' and the restore is itself committed (so it can be undone).',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Restore',
            cancelButtonText: 'Cancel',
            closeOnConfirm: true
        }, function (confirmed) {
            if (!confirmed) return;
            hubAjax('hub_git_restore', { path: path, ref: commit.hash }).then(function (res) {
                if (res.status === 'busy') { swal('Busy', res.message || 'a bake is in flight — retry shortly', 'warning'); return; }
                if (res.status !== 'ok') { swal('Restore failed', res.message || 'unknown error', 'error'); return; }
                window.hubGitRefresh();
                window.hubLoadState(); // restored vendor files may shift drift state
            });
        });
    }

    window.hubGitSetRemote = function () {
        var url = document.getElementById('hub-git-remote-url').value.trim();
        var tokenInput = document.getElementById('hub-git-token');
        if (!url) { swal('Missing URL', 'Enter an http:// or https:// remote URL first.', 'error'); return; }
        hubAjax('hub_git_set_remote', { url: url, token: tokenInput.value }).then(function (res) {
            if (res.status !== 'ok') { swal('Save failed', res.message || 'unknown error', 'error'); return; }
            tokenInput.value = ''; // never keep the token in the DOM after save
            window.hubGitRefresh();
            if (res.warning) swal('Saved (insecure remote)', res.warning, 'warning');
        });
    };

    window.hubGitPush = function () {
        var btn = document.getElementById('hub-git-push-btn');
        btn.disabled = true;
        hubAjax('hub_git_push').then(function (res) {
            btn.disabled = false;
            if (res.status !== 'ok') { swal('Push failed', res.message || 'unknown error', 'error'); return; }
            swal('Pushed', 'Config backup pushed to the remote.', 'success');
        });
    };

    // Restore the plugin settings (unraid-aicliagents.cfg, version pins, freeform keys) from
    // the last committed backup — OVERWRITES the live settings on flash, so confirm first.
    window.hubGitRestoreSettings = function () {
        swal({
            title: 'Restore plugin settings?',
            text: 'This overwrites the live plugin settings on flash (unraid-aicliagents.cfg, agent version pins, freeform key list) with the last committed backup. Workspaces and the agent home are unaffected. Continue?',
            type: 'warning', showCancelButton: true, confirmButtonText: 'Restore', cancelButtonText: 'Cancel', closeOnConfirm: true
        }, function (confirmed) {
            if (!confirmed) return;
            hubAjax('hub_git_restore_settings').then(function (res) {
                if (res.status !== 'ok') { swal('Restore failed', res.message || 'unknown error', 'error'); return; }
                var n = (res.restored || []).length;
                swal('Restored', n ? ('Restored ' + n + ' settings file(s): ' + res.restored.join(', ') + '. Reload the page to see them.') : 'Nothing to restore.', 'success');
            });
        });
    };

    // ---------- init ----------

    document.addEventListener('DOMContentLoaded', function () {
        window.hubLoadState();
        window.hubGitRefresh();
        // Refresh whenever the Config Hub tab is opened.
        document.querySelectorAll('.aicli-tab-btn').forEach(function (btn) {
            if ((btn.getAttribute('onclick') || '').indexOf("'hub'") >= 0) {
                btn.addEventListener('click', function () { window.hubLoadState(); window.hubGitRefresh(); });
            }
        });
    });
})();
</script>
