/* The Script runs card. Dynamic values are always written as text. */
(function () {
    'use strict';

    var list = document.getElementById('aicli-runs-list');
    if (!list) return;

    var state = { scripts: [], agentChoices: [], userScripts: null, runUser: null, shareWarnings: [], temporaryRoot: '', enabled: false, loadError: false, open: null };
    var addButton = document.getElementById('aicli-runs-add');
    var message = document.getElementById('aicli-runs-msg');
    var template = document.getElementById('aicli-runs-dialog-tpl');
    var switchControl = document.querySelector('select[name="run_enabled"]');
    var runPassword = document.getElementById('aicli-run-password');
    var signInAgent = document.getElementById('aicli-run-sign-in-agent');
    var signInButton = document.getElementById('aicli-run-sign-in');

    function q(selector, root) { return (root || (state.open && state.open.dialog) || document).querySelector(selector); }
    function qa(selector, root) { return Array.prototype.slice.call((root || (state.open && state.open.dialog) || document).querySelectorAll(selector)); }
    function make(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }
    function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }
    function setText(selector, value) {
        var node = q(selector);
        if (node) node.textContent = value == null ? '' : String(value);
        return node;
    }
    function say(text, bad) {
        if (!message) return;
        message.textContent = text || '';
        message.classList.toggle('is-error', !!bad);
    }
    function post(action, body) {
        var csrf = window.csrf_token || '';
        var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=' + action + '&csrf_token=' + encodeURIComponent(csrf);
        var payload = Object.assign({}, body || {}, { csrf_token: csrf });
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(payload).toString()
        }).then(function (response) { return response.json(); });
    }
    function displayName(script) { return String(script.displayName || script.label || (script.path || '').split('/').pop() || 'Script'); }
    function nameForAgent(id) {
        var choice = state.agentChoices.find(function (item) { return item.id === id; });
        return choice ? choice.name : id;
    }
    function switchIsOn() { return !!switchControl && switchControl.value === '1'; }
    function setStatus() {
        state.enabled = switchIsOn();
        var count = state.scripts.length;
        var attention = state.scripts.filter(function (item) { return !!item.attention; }).length;
        var status = (state.enabled ? 'On' : 'Off') + ' · ' + count + (count === 1 ? ' script' : ' scripts');
        if (attention) status += ' · ' + attention + ' needs attention';
        if (window.aicliCards && typeof window.aicliCards.setStatus === 'function') window.aicliCards.setStatus('script-runs', status);
        var off = document.getElementById('aicli-runs-off');
        if (off) off.hidden = state.enabled || count === 0;
    }
    function renderRunUser() {
        var box = document.getElementById('aicli-run-user-state');
        if (box) box.textContent = state.runUser && state.runUser.ready
            ? 'Ready. Script runs use aicli-run unless an authorised entry selects root.'
            : ((state.runUser && state.runUser.fault) || 'The run user status is not available. Script runs as aicli-run are refused.');
        var warningList = document.getElementById('aicli-run-share-warnings');
        if (warningList) {
            clear(warningList);
            state.shareWarnings.forEach(function (warning) { warningList.appendChild(make('li', '', warning.message || 'Check SMB access for this share.')); });
            warningList.hidden = !state.shareWarnings.length;
        }
        var tempRoot = document.getElementById('aicli-run-temp-root');
        if (tempRoot) tempRoot.textContent = state.temporaryRoot ? 'Temporary run folders are made under ' + state.temporaryRoot + '.' : 'Temporary folders are unavailable until the appdata pool path can be resolved.';
        if (signInAgent) {
            var current = signInAgent.value;
            clear(signInAgent);
            state.agentChoices.filter(function (choice) { return choice.installed; }).forEach(function (choice) {
                var option = make('option', '', choice.name);
                option.value = choice.id;
                signInAgent.appendChild(option);
            });
            if (Array.prototype.some.call(signInAgent.options, function (option) { return option.value === current; })) signInAgent.value = current;
        }
        if (signInButton) signInButton.disabled = !(state.runUser && state.runUser.ready && signInAgent && signInAgent.value);
    }
    function generateRunPassword() {
        if (!runPassword || !window.crypto || typeof window.crypto.getRandomValues !== 'function') {
            say('This browser cannot make a secure password. Use a password manager.', true);
            return;
        }
        var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%*+-_';
        var bytes = new Uint8Array(32);
        window.crypto.getRandomValues(bytes);
        var password = '';
        for (var i = 0; i < 24; i++) password += alphabet.charAt(bytes[i] % alphabet.length);
        runPassword.value = password;
        runPassword.hidden = false;
        var copy = document.getElementById('aicli-run-password-copy');
        if (copy) copy.hidden = false;
        say('Password made in this page. Paste it into the Users page. It is not sent or saved here.');
    }
    function copyRunPassword() {
        if (!runPassword || !runPassword.value) return;
        runPassword.select();
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(runPassword.value);
            else document.execCommand('copy');
            say('Password copied. Paste it into the Users page.');
        } catch (e) { say('Select and copy the password into the Users page.', true); }
    }
    function openSignInWorkspace() {
        if (!signInAgent || !signInAgent.value || signInButton.disabled) return;
        signInButton.disabled = true;
        var status = document.getElementById('aicli-run-sign-in-message');
        if (status) status.textContent = 'Opening the sign-in workspace…';
        post('run_scripts_sign_in', { agentId: signInAgent.value }).then(function (answer) {
            if (!answer || answer.status !== 'ok') throw new Error((answer && answer.message) || 'The sign-in workspace could not be opened.');
            var workspace = answer.workspace || {};
            var workspaceId = String(workspace.workspaceId || workspace.id || '');
            if (!/^[A-Za-z0-9_-]{1,128}$/.test(workspaceId)) throw new Error('The sign-in workspace opened, but its terminal link is missing.');
            if (status) status.textContent = 'Sign-in workspace opened. Complete sign-in there, then close it.';
            window.open('/AICliAgents#aicli-workspace=' + encodeURIComponent(workspaceId), '_self');
        }).catch(function (error) {
            if (status) status.textContent = error.message || 'The sign-in workspace could not be opened.';
        }).then(renderRunUser);
    }
    function statusTag(script) {
        var value = script.attention || '';
        if (!value) return null;
        var map = {
            staleCopy: ['fa-exclamation-triangle', 'Needs attention', 'runs-tag-attention'],
            missing: ['fa-times-circle', 'File missing', 'runs-tag-danger'],
            changed: ['fa-exclamation-circle', 'File changed', 'runs-tag-attention']
        };
        return map[value] || null;
    }
    function makeRow(script) {
        var li = make('li', 'aicli-runs-item');
        var button = make('button', 'aicli-runs-row');
        button.type = 'button';
        button.setAttribute('aria-haspopup', 'dialog');
        button.setAttribute('data-testid', 'runs-row');
        button.setAttribute('data-entry-id', String(script.id || ''));
        var info = make('span', 'aicli-runs-info');
        info.appendChild(make('span', 'aicli-runs-name', displayName(script)));
        info.appendChild(make('span', 'aicli-runs-description', script.descriptionText || script.summary || ''));
        var tag = statusTag(script);
        if (tag) {
            var status = make('span', 'aicli-runs-tag ' + tag[2]);
            status.appendChild(make('span', 'aicli-runs-sr', 'Status: '));
            var icon = make('i', 'fa ' + tag[0]);
            icon.setAttribute('aria-hidden', 'true');
            status.appendChild(icon);
            status.appendChild(document.createTextNode(' ' + tag[1]));
            info.appendChild(status);
        }
        button.appendChild(info);
        var arrow = make('i', 'fa fa-chevron-right aicli-runs-chevron');
        arrow.setAttribute('aria-hidden', 'true');
        button.appendChild(arrow);
        button.addEventListener('click', function () { openEntry(script.id, button); });
        li.appendChild(button);
        return li;
    }
    function renderList() {
        clear(list);
        if (state.loadError) {
            var error = make('li', 'aicli-runs-empty', 'The list could not be loaded.');
            var retry = make('button', 'aicli-btn-slim aicli-runs-btn', 'Try again');
            retry.type = 'button';
            retry.addEventListener('click', loadList);
            error.appendChild(retry);
            list.appendChild(error);
            setStatus();
            return;
        }
        if (!state.scripts.length) {
            list.appendChild(make('li', 'aicli-runs-empty', 'No script may start agent runs yet. Add a script, or wait for a script to ask: it then shows in the Activity tray, and Approve puts it here.'));
        } else {
            state.scripts.forEach(function (script) { list.appendChild(makeRow(script)); });
        }
        setStatus();
    }
    function loadList() {
        state.loadError = false;
        return post('run_scripts_list', {}).then(function (answer) {
            if (!answer || answer.status !== 'ok') throw new Error((answer && answer.message) || 'The list could not be loaded.');
            state.scripts = Array.isArray(answer.scripts) ? answer.scripts : [];
            state.agentChoices = Array.isArray(answer.agentChoices) ? answer.agentChoices : [];
            state.runUser = answer.runUser || null;
            state.shareWarnings = Array.isArray(answer.shareWarnings) ? answer.shareWarnings : [];
            state.temporaryRoot = typeof answer.temporaryRoot === 'string' ? answer.temporaryRoot : '';
            state.enabled = !!answer.enabled;
            state.loadError = false;
            renderRunUser();
            renderList();
            if (state.open && state.open.id) {
                var latest = state.scripts.find(function (item) { return item.id === state.open.id; });
                if (latest) { state.open.entry = latest; renderDialog(); }
                else renderMissingEntry();
            }
            return true;
        }).catch(function () {
            state.loadError = true;
            renderList();
            return false;
        });
    }

    function dateText(timestamp) {
        var value = Number(timestamp || 0);
        if (!value) return 'an earlier date';
        try {
            var parts = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).formatToParts(new Date(value * 1000));
            var out = {};
            parts.forEach(function (part) { out[part.type] = part.value; });
            return (out.day || '') + ' ' + (out.month || '') + ' ' + (out.year || '');
        } catch (e) {
            return new Date(value * 1000).toLocaleDateString();
        }
    }
    function trustedText(script) {
        var by = script.trustedBy || 'a person';
        var trustedAt = dateText(script.trustedAt || script.addedAt);
        if (script.fileState === 'missing') return 'The file is missing. Runs are refused.';
        if (script.fileState === 'changed') return 'The file changed on ' + dateText(script.fileModifiedAt) + ', after you trusted it on ' + trustedAt + '. Runs are refused until you trust it again.';
        return 'Trusted on ' + trustedAt + ' by ' + by + '. The file has not changed since.';
    }
    function writeView(script) {
        var derivedName = displayName(script);
        setText('[data-view="name"]', derivedName);
        setText('[data-view="name-source"]', script.nameSource === 'userScripts' ? ' (from User Scripts)' : '');
        setText('[data-view="description"]', script.descriptionText || 'No description.');
        var kind = '';
        if (script.pathKind === 'userScript') kind = 'User Script ' + (script.userScriptName || '');
        if (script.pathKind === 'userScriptCopy') kind = 'User Script ' + (script.userScriptName || '') + ' (temporary copy)';
        setText('[data-view="script-kind"]', kind);
        setText('[data-view="path"]', script.path || '');
        setText('[data-view="fingerprint"]', trustedText(script));
        setText('[data-view="hash"]', script.sha256 || '');
        var trust = q('[data-action="trust"]');
        if (trust) trust.hidden = script.fileState !== 'changed' || script.pathKind === 'userScriptCopy';
        setText('[data-view="agents"]', (script.agentNames && script.agentNames.length ? script.agentNames : (script.agents || []).map(nameForAgent)).join(', ') || 'No agent');
        setText('[data-view="folders"]', (script.folders || []).join('\n') + (script.folders && script.folders.length && script.allowTemp ? '\nand a temporary folder' : '') || 'A temporary folder only. The plugin makes a new empty folder for each run.');
        setText('[data-view="mode"]', script.maxMode === 'may-change' ? 'May change files: waits for your approval each time.' : 'Read-only: starts at once and only reads files.');
        var users = script.users || ['root'];
        // #944: an entry that allows root and not the run user was approved before restricted runs. A call that names no user runs as root.
        var legacyRoot = users.indexOf('root') !== -1 && users.indexOf('aicli-run') === -1;
        setText('[data-view="users"]', users.map(function (user) { return user === 'aicli-run' ? 'aicli-run (restricted)' : user; }).join(', ')
            + (legacyRoot ? '. A call that names no user runs as root. To run it restricted, tick aicli-run (restricted).' : ''));
        setText('[data-view="perday"]', 'Up to ' + Number(script.maxPerDay || 6) + ' runs in 24 hours.');

        var callout = q('[data-role="stale-callout"]');
        var staleCopy = script.pathKind === 'userScriptCopy';
        callout.hidden = !staleCopy;
        if (staleCopy) {
            var intro = q('.runs-callout > p');
            var originalText = q('[data-role="stale-original"]');
            var action = q('[data-action="repoint"]');
            if (!script.originalExists) {
                intro.textContent = 'The original User Script is not on this server any more. Remove this entry.';
                originalText.textContent = '';
                action.hidden = true;
            } else {
                intro.textContent = 'This entry points at a temporary copy. User Scripts makes a new copy for each run. The plugin checks the original file, so this entry can never match a call.';
                originalText.textContent = 'The original is User Script ' + (script.userScriptName || '') + ': ' + script.originalPath;
                var matching = state.scripts.find(function (item) { return item.path === script.originalPath && item.id !== script.id; });
                action.textContent = matching ? 'Remove this copy entry (the original is already on the list)' : 'Switch to the original and trust it as it is now';
                action.hidden = false;
            }
        }
    }

    function appendAgentChoices(script) {
        var box = q('[data-role="agents"]');
        clear(box);
        var selected = new Set((script && script.agents) || []);
        var choices = state.agentChoices.slice();
        var known = new Set(choices.map(function (item) { return item.id; }));
        selected.forEach(function (id) {
            if (!known.has(id)) choices.push({ id: id, name: id + ' (unknown agent)', installed: false, unknown: true });
        });
        choices.forEach(function (choice) {
            var label = make('label', 'runs-agent-choice');
            var check = make('input');
            check.type = 'checkbox';
            check.value = choice.id;
            check.checked = selected.has(choice.id);
            check.setAttribute('data-agent-id', choice.id);
            label.appendChild(check);
            var name = choice.name + (choice.unknown ? '' : (choice.installed ? '' : ' (not installed)'));
            label.appendChild(make('span', '', name));
            box.appendChild(label);
        });
    }
    function appendRunUserChoices(script) {
        var users = script && Array.isArray(script.users) && script.users.length ? script.users : ['aicli-run'];
        qa('[data-user-id]').forEach(function (check) { check.checked = users.indexOf(check.getAttribute('data-user-id')) !== -1; });
    }
    function updateDescriptionCount() {
        var input = q('#runs-edit-description');
        var count = q('[data-role="description-count"]');
        if (input && count) count.textContent = input.value.length + ' / 120';
    }
    function userScriptsForDisplay() {
        return state.userScripts && Array.isArray(state.userScripts.scripts) ? state.userScripts.scripts : [];
    }
    function drawUserScriptChoices() {
        if (!state.open || !state.open.add) return;
        var box = q('[data-role="user-script-list"]');
        var empty = q('[data-role="user-script-empty"]');
        var filter = q('[data-role="user-script-filter"]');
        if (!box || !empty || !filter) return;
        clear(box);
        var scripts = userScriptsForDisplay();
        var term = filter.value.trim().toLowerCase();
        var visible = scripts.filter(function (script) { return !term || (script.name + ' ' + script.description).toLowerCase().indexOf(term) !== -1; });
        if (state.userScripts && state.userScripts.truncated) empty.textContent = 'Showing 200 scripts. Type the path for another one.';
        else if (!scripts.length) empty.textContent = 'The User Scripts plugin has no script on this server.';
        else empty.textContent = '';
        empty.hidden = !!scripts.length && !state.userScripts.truncated;
        filter.hidden = scripts.length <= 8;
        visible.forEach(function (script) {
            var row = make('div', 'runs-user-script-choice');
            var label = make('label', 'runs-choice');
            var radio = make('input');
            radio.type = 'radio';
            radio.checked = !!(state.open.selectedScript && state.open.selectedScript.path === script.path);
            radio.disabled = !!script.onListId;
            radio.setAttribute('data-user-script-path', script.path);
            label.appendChild(radio);
            var content = make('span', 'runs-choice-copy');
            content.appendChild(make('strong', '', script.name));
            if (script.description) content.appendChild(make('span', 'hb-muted', script.description));
            label.appendChild(content);
            row.appendChild(label);
            if (script.onListId) {
                row.appendChild(make('span', 'runs-on-list', 'On the list'));
                var open = make('button', 'aicli-btn-slim runs-open-entry', 'Open it');
                open.type = 'button';
                open.setAttribute('data-open-id', script.onListId);
                row.appendChild(open);
            }
            box.appendChild(row);
        });
        if (scripts.length && !box.firstChild && term) { empty.textContent = 'No User Script matches that name.'; empty.hidden = false; }
        var selectedPath = state.open.selectedScript ? state.open.selectedScript.path : '';
        var path = q('[data-role="selected-script-path"]');
        if (path) { path.textContent = selectedPath; path.hidden = !selectedPath; }
        updateAddPlaceholders();
    }
    function updateAddPlaceholders() {
        if (!state.open || !state.open.add) return;
        var selected = state.open.selectedScript;
        var name = q('#runs-edit-name');
        var description = q('#runs-edit-description');
        if (name) name.placeholder = selected ? selected.name : 'The file name';
        if (description) description.placeholder = selected && selected.description ? selected.description : 'What is this script for?';
        setText('[data-role="edit-fingerprint"]', selected ? 'The fingerprint of the file as it is now is stored when you save.' : 'The fingerprint is stored when you save.');
    }
    function addDefaultAgent() {
        var installed = state.agentChoices.filter(function (choice) { return choice.installed; });
        if (installed.length === 1) {
            var check = q('[data-agent-id="' + installed[0].id.replace(/(["\\])/g, '\\$1') + '"]');
            if (check) check.checked = true;
        }
    }
    function renderEdit(script) {
        var editing = state.open.mode === 'edit' || state.open.mode === 'add';
        qa('.runs-view').forEach(function (node) { node.hidden = editing; });
        qa('.runs-edit').forEach(function (node) { node.hidden = !editing; });
        if (!editing) return;
        var adding = state.open.add;
        var entry = script || {};
        var name = q('#runs-edit-name');
        var description = q('#runs-edit-description');
        var folderBox = q('#runs-edit-folders');
        var perDay = q('#runs-edit-perday');
        var selectedLabel = entry.label && entry.label !== (entry.path || '').split('/').pop() ? entry.label : '';
        name.value = adding ? (state.open.formName || '') : selectedLabel;
        name.placeholder = adding ? name.placeholder : displayName(entry);
        description.value = adding ? (state.open.formDescription || '') : (entry.description || '');
        description.placeholder = adding ? description.placeholder : 'What is this script for?';
        folderBox.value = adding ? (state.open.formFolders || '') : (entry.folders || []).join('\n');
        perDay.value = adding ? (state.open.formPerDay || 6) : Number(entry.maxPerDay || 6);
        var existingPath = q('[data-role="existing-path"]');
        existingPath.hidden = adding;
        setText('[data-role="existing-path-text"]', entry.path || '');
        q('[data-role="add-fields"]').hidden = !adding;
        q('[data-role="trust-wrap"]').hidden = adding;
        var trust = q('[data-control="trust"]');
        if (trust) trust.checked = !!state.open.trustChecked;
        appendAgentChoices(adding ? { agents: state.open.formAgents || [] } : entry);
        appendRunUserChoices(adding ? { users: state.open.formUsers || ['aicli-run'] } : entry);
        var mode = adding ? (state.open.formMode || 'read-only') : (entry.maxMode || 'read-only');
        qa('[data-mode]').forEach(function (radio) { radio.checked = radio.getAttribute('data-mode') === mode; });
        updateDescriptionCount();
        if (adding) {
            var scripts = userScriptsForDisplay();
            var defaultSource = state.open.source || (scripts.length ? 'userScript' : 'file');
            qa('[data-source]').forEach(function (radio) { radio.checked = radio.getAttribute('data-source') === defaultSource; });
            q('[data-role="user-script-picker"]').hidden = defaultSource !== 'userScript';
            q('[data-role="file-picker"]').hidden = defaultSource !== 'file';
            q('[data-role="path-check"]').textContent = state.open.pathCheck || '';
            q('[data-action="use-original"]').hidden = !state.open.originalPath;
            drawUserScriptChoices();
            if (!state.open.addAgentDefaulted && state.agentChoices.length) {
                if (!state.open.formAgents || !state.open.formAgents.length) addDefaultAgent();
                state.open.addAgentDefaulted = true;
            }
            updateAddPlaceholders();
        }
    }
    function renderDialog() {
        if (!state.open || !state.open.dialog) return;
        var item = state.open.entry;
        q('#runs-title').textContent = state.open.add ? 'Add a script' : displayName(item || {});
        q('[data-role="error"]').hidden = true;
        if (item && !state.open.add) writeView(item);
        renderEdit(item);
        renderFooter();
    }
    function renderMissingEntry() {
        if (!state.open) return;
        state.open.mode = 'missing';
        qa('.runs-view').forEach(function (node) { node.hidden = true; });
        qa('.runs-edit').forEach(function (node) { node.hidden = true; });
        q('[data-role="stale-callout"]').hidden = true;
        var note = make('p', 'runs-missing', 'This entry no longer exists.');
        var body = q('.runs-body');
        var previous = q('.runs-missing', body);
        if (previous) previous.remove();
        body.insertBefore(note, q('[data-role="discard"]'));
        renderFooter();
    }

    function openDialog(entry, returnFocus, adding) {
        if (!template || !template.content || !template.content.firstElementChild) return;
        var back = template.content.firstElementChild.cloneNode(true);
        var dialog = back.querySelector('.runs-dialog');
        document.body.appendChild(back);
        var previousOverflow = document.documentElement.style.overflow;
        state.open = {
            id: adding ? '' : String(entry.id), entry: entry || null, add: !!adding,
            mode: adding ? 'add' : 'view', returnFocus: returnFocus || addButton,
            back: back, dialog: dialog, previousOverflow: previousOverflow, dirty: false,
            snapshot: null, source: '', sourceChosen: false, selectedScript: null, originalPath: '', pathCheck: '', closePrompt: false,
            closeAfterDiscard: false, saving: false
        };
        document.documentElement.style.overflow = 'hidden';
        if (window.aicliHelp && typeof window.aicliHelp.attach === 'function') window.aicliHelp.attach(dialog);
        bindDialog(back, dialog);
        renderDialog();
        state.open.snapshot = JSON.stringify(formState());
        q('#runs-title').focus();
        if (window.aicliDialogTrapKeys) window.aicliDialogTrapKeys(dialog, function () { requestClose(); });
        if (adding) loadUserScripts();
    }
    function openEntry(id, button) {
        var entry = state.scripts.find(function (item) { return item.id === id; });
        if (entry) openDialog(entry, button, false);
    }
    function openAdd() {
        say('');
        openDialog(null, addButton, true);
    }
    function loadUserScripts() {
        if (state.userScripts) { drawUserScriptChoices(); return; }
        post('run_scripts_user_scripts', {}).then(function (answer) {
            if (!answer || answer.status !== 'ok') throw new Error('Could not list User Scripts.');
            state.userScripts = answer;
            if (!state.open || !state.open.add) return;
            var before = formState();
            state.open.formName = before.label;
            state.open.formDescription = before.description;
            state.open.formFolders = before.folders;
            state.open.formAgents = before.agents;
            state.open.formMode = before.maxMode;
            state.open.formPerDay = before.maxPerDay;
            if (!state.open.sourceChosen) state.open.source = answer.scripts && answer.scripts.length ? 'userScript' : 'file';
            renderEdit(null);
            if (!state.open.dirty) state.open.snapshot = JSON.stringify(formState());
            updateDirty();
        }).catch(function () {
            state.userScripts = { installed: false, scripts: [], truncated: false };
            if (state.open && state.open.add) {
                if (!state.open.sourceChosen) state.open.source = 'file';
                renderEdit(null);
                if (!state.open.dirty) state.open.snapshot = JSON.stringify(formState());
            }
        });
    }
    function renderFooter() {
        if (!state.open) return;
        var footer = q('.runs-footer');
        clear(footer);
        if (state.open.mode === 'missing') {
            footer.appendChild(button('Close', 'hb-secondary', function () { closeDialog(); }));
            return;
        }
        if (state.open.mode === 'view') {
            var remove = button('Remove', 'runs-remove danger', function () { showRemoveConfirm(); });
            remove.setAttribute('data-action', 'remove');
            footer.appendChild(remove);
            footer.appendChild(make('span', 'runs-footer-spacer'));
            footer.appendChild(button('Close', 'hb-secondary', function () { closeDialog(); }));
            var edit = button('Edit', 'runs-primary', function () { state.open.mode = 'edit'; state.open.dirty = false; state.open.snapshot = null; renderDialog(); state.open.snapshot = JSON.stringify(formState()); q('#runs-edit-name').focus(); });
            edit.setAttribute('data-action', 'edit');
            footer.appendChild(edit);
            return;
        }
        footer.appendChild(button('Cancel', 'hb-secondary', cancelEdit));
        var save = button('Save', 'runs-primary', saveEntry);
        save.setAttribute('data-action', 'save');
        footer.appendChild(save);
    }
    function button(text, className, action) {
        var btn = make('button', 'aicli-btn-slim ' + (className || ''), text);
        btn.type = 'button';
        if (action) btn.addEventListener('click', action);
        return btn;
    }
    function showRemoveConfirm() {
        var footer = q('.runs-footer');
        clear(footer);
        var confirm = make('div', 'acp-confirm runs-remove-confirm');
        confirm.setAttribute('role', 'group');
        confirm.appendChild(make('span', '', 'Remove ' + displayName(state.open.entry) + '? It can no longer start agent runs.'));
        var yes = button('Yes, remove', 'runs-danger', removeEntry);
        var no = button('No', 'hb-secondary', function () { renderFooter(); var next = q('[data-action="remove"]'); if (next) next.focus(); });
        no.setAttribute('data-action', 'remove-no');
        confirm.appendChild(yes);
        confirm.appendChild(no);
        footer.appendChild(confirm);
        no.focus();
    }
    function renderDiscard() {
        var box = q('[data-role="discard"]');
        box.hidden = !state.open.closePrompt;
        if (state.open.closePrompt) {
            var target = q('[data-action="discard"]');
            if (target) target.focus();
        }
    }
    function closeDialog(target, restoreFocus) {
        if (!state.open) return;
        var current = state.open;
        document.documentElement.style.overflow = current.previousOverflow;
        state.open = null;
        if (restoreFocus === false) {
            current.back.remove();
            return;
        }
        current.back.classList.add('is-closing');
        window.setTimeout(function () {
            current.back.remove();
            var focus = target || current.returnFocus || addButton;
            if (focus && focus.isConnected && typeof focus.focus === 'function') focus.focus();
        }, 120);
    }
    function requestClose() {
        if (!state.open) return;
        if (state.open.closePrompt) {
            state.open.closePrompt = false;
            renderDiscard();
            q('#runs-edit-name') && q('#runs-edit-name').focus();
            return;
        }
        if (state.open.dirty) {
            state.open.closePrompt = true;
            state.open.closeAfterDiscard = true;
            renderDiscard();
            return;
        }
        closeDialog();
    }
    function cancelEdit() {
        if (state.open.dirty) {
            state.open.closePrompt = true;
            state.open.closeAfterDiscard = false;
            renderDiscard();
            return;
        }
        if (state.open.add) { closeDialog(); return; }
        state.open.mode = 'view';
        renderDialog();
    }
    function discardChanges() {
        if (state.open.closeAfterDiscard || state.open.add) { closeDialog(); return; }
        state.open.closePrompt = false;
        state.open.mode = 'view';
        state.open.dirty = false;
        renderDialog();
    }
    function formState() {
        if (!state.open || !state.open.dialog) return {};
        var adding = state.open.add;
        var entry = state.open.entry || {};
        var source = state.open.source || (userScriptsForDisplay().length ? 'userScript' : 'file');
        return {
            source: adding ? source : '',
            path: adding ? (source === 'userScript' ? ((state.open.selectedScript && state.open.selectedScript.path) || '') : (q('#runs-add-path').value || '').trim()) : entry.path,
            label: (q('#runs-edit-name').value || '').trim(), description: (q('#runs-edit-description').value || '').trim(),
            agents: qa('[data-agent-id]:checked').map(function (node) { return node.value; }).sort(),
            folders: (q('#runs-edit-folders').value || '').trim(),
            maxMode: (q('[data-mode]:checked') || {}).getAttribute ? q('[data-mode]:checked').getAttribute('data-mode') : 'read-only',
            users: qa('[data-user-id]:checked').map(function (node) { return node.getAttribute('data-user-id'); }).sort(),
            maxPerDay: q('#runs-edit-perday').value || '6',
            trust: !!q('[data-control="trust"]') && q('[data-control="trust"]').checked
        };
    }
    function updateDirty() {
        if (!state.open || !state.open.snapshot || state.open.mode === 'view') return;
        state.open.dirty = JSON.stringify(formState()) !== state.open.snapshot;
        if (!state.open.dirty && state.open.closePrompt) { state.open.closePrompt = false; renderDiscard(); }
    }
    function renderError(messageText) {
        var error = q('[data-role="error"]');
        error.textContent = messageText || 'The changes could not be saved.';
        error.hidden = false;
    }
    function focusErrorField(messageText) {
        if (/folder/i.test(messageText)) q('#runs-edit-folders').focus();
        else {
            var save = q('[data-action="save"]');
            if (save) save.focus();
        }
    }
    function saveEntry() {
        if (!state.open || state.open.saving) return;
        var values = formState();
        var save = q('[data-action="save"]');
        state.open.saving = true;
        save.disabled = true;
        save.textContent = 'Saving…';
        q('[data-role="error"]').hidden = true;
        var body = {
            id: state.open.add ? '' : state.open.id,
            path: values.path,
            label: values.label,
            description: values.description,
            agents: values.agents.join(', '),
            folders: values.folders,
            maxMode: values.maxMode,
            users: values.users.length ? values.users.join(', ') : 'aicli-run',
            maxPerDay: values.maxPerDay,
            authoriseCurrent: values.trust ? '1' : ''
        };
        post('run_scripts_save', body).then(function (answer) {
            state.open.saving = false;
            if (!answer || answer.status !== 'ok' || !answer.entry) {
                var errorText = (answer && answer.message) || 'The script was not saved.';
                renderError(errorText);
                save.disabled = false;
                save.textContent = 'Save';
                focusErrorField(errorText);
                return;
            }
            state.open.entry = answer.entry;
            state.open.id = answer.entry.id;
            state.open.add = false;
            state.open.mode = 'view';
            state.open.dirty = false;
            state.open.closePrompt = false;
            loadList().then(function () {
                if (state.open) {
                    state.open.mode = 'view';
                    state.open.snapshot = JSON.stringify(formState());
                    renderDialog();
                    say('Saved.');
                }
            });
        }).catch(function () {
            state.open.saving = false;
            renderError('Network error. Nothing was saved.');
            save.disabled = false;
            save.textContent = 'Save';
        });
    }
    function trustCurrent() {
        if (!state.open || !state.open.entry) return;
        var item = state.open.entry;
        post('run_scripts_save', {
            id: item.id, label: item.label || '', description: item.description || '', agents: (item.agents || []).join(', '),
            folders: (item.folders || []).join('\n'), maxMode: item.maxMode || 'read-only', users: (item.users || ['root']).join(', '),
            maxPerDay: item.maxPerDay || 6, authoriseCurrent: '1'
        }).then(function (answer) {
            if (!answer || answer.status !== 'ok') { renderError((answer && answer.message) || 'The file was not trusted.'); return; }
            state.open.mode = 'view';
            state.open.entry = answer.entry;
            state.open.dirty = false;
            return loadList().then(function () { if (state.open) { renderDialog(); say('Saved.'); } });
        }).catch(function () { renderError('Network error. The file was not trusted.'); });
    }
    function removeEntry() {
        if (!state.open || !state.open.entry) return;
        var index = state.scripts.findIndex(function (item) { return item.id === state.open.id; });
        post('run_scripts_remove', { id: state.open.id }).then(function (answer) {
            if (!answer || answer.status !== 'ok') { renderError((answer && answer.message) || 'The entry was not removed.'); return; }
            closeDialog(null, false);
            loadList().then(function () {
                say('Removed.');
                var next = list.querySelectorAll('.aicli-runs-row')[Math.min(index, Math.max(0, state.scripts.length - 1))];
                if (next) next.focus(); else addButton.focus();
            });
        }).catch(function () { renderError('Network error. Nothing was removed.'); });
    }
    function repoint() {
        if (!state.open || !state.open.entry) return;
        var button = q('[data-action="repoint"]');
        button.disabled = true;
        post('run_scripts_repoint', { id: state.open.id, sha256: state.open.entry.sha256 || '' }).then(function (answer) {
            if (!answer || answer.status !== 'ok') {
                button.disabled = false;
                renderError((answer && answer.message) || 'The entry was not moved.');
                return;
            }
            if (answer.merged) {
                var original = state.open.entry.originalPath;
                closeDialog(null, false);
                return loadList().then(function () {
                    say('The original entry stays on the list.');
                    var row = list.querySelector('[data-entry-id]');
                    state.scripts.some(function (item, index) {
                        if (item.path === original) { row = list.querySelectorAll('.aicli-runs-row')[index]; return true; }
                        return false;
                    });
                    if (row) row.focus();
                });
            }
            state.open.mode = 'view';
            state.open.entry = answer.entry;
            state.open.id = answer.entry.id;
            state.open.dirty = false;
            return loadList().then(function () { if (state.open) { renderDialog(); say('Moved to the original script and trusted it.'); } });
        }).catch(function () {
            if (button) button.disabled = false;
            renderError('Network error. The entry was not moved.');
        });
    }
    function copyHash() {
        var hash = state.open && state.open.entry ? state.open.entry.sha256 : '';
        if (!hash) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(hash).then(function () { say('Fingerprint copied.'); }).catch(function () { say('The fingerprint could not be copied.', true); });
            return;
        }
        say('Copy is not available in this browser.', true);
    }
    function checkPath() {
        if (!state.open || !state.open.add || state.open.source !== 'file') return;
        var path = (q('#runs-add-path').value || '').trim();
        state.open.pathCheck = '';
        state.open.originalPath = '';
        q('[data-action="use-original"]').hidden = true;
        var check = q('[data-role="path-check"]');
        check.classList.remove('is-error');
        if (!path) { check.textContent = ''; updateDirty(); return; }
        check.textContent = 'Checking the file…';
        post('run_scripts_hash', { path: path }).then(function (answer) {
            if (!state.open || !state.open.add || state.open.source !== 'file' || q('#runs-add-path').value.trim() !== path) return;
            if (answer && answer.temporaryCopy) {
                state.open.originalPath = answer.originalPath || '';
                check.textContent = answer.message || 'This file is temporary and is gone after a reboot. Pick the original script.';
                check.classList.add('is-error');
                q('[data-action="use-original"]').hidden = !state.open.originalPath;
            } else if (answer && answer.status === 'ok') {
                check.textContent = 'File found. Its fingerprint is stored when you save.';
            } else {
                check.textContent = (answer && answer.message) || 'That file does not exist or cannot be read.';
                check.classList.add('is-error');
            }
            state.open.pathCheck = check.textContent;
            updateDirty();
        }).catch(function () {
            check.textContent = 'The file could not be checked.';
            check.classList.add('is-error');
            state.open.pathCheck = check.textContent;
        });
    }
    function useOriginal() {
        if (!state.open || !state.open.originalPath) return;
        q('#runs-add-path').value = state.open.originalPath;
        state.open.originalPath = '';
        checkPath();
    }
    function browseFile() {
        var input = q('#runs-add-path-picker');
        var current = (q('#runs-add-path').value || '').trim();
        if (current.indexOf('/') !== -1) input.value = current.slice(0, current.lastIndexOf('/')) || '/';
        else input.value = '/boot/config';
        if (typeof window.openPathPicker === 'function') window.openPathPicker(input.id, { allowCreate: false });
    }
    function browseFolder() {
        var input = q('#runs-folder-path-picker');
        var value = q('#runs-edit-folders').value.split(/\r?\n/).filter(Boolean).pop();
        input.value = value || '/mnt';
        if (typeof window.openPathPicker === 'function') window.openPathPicker(input.id, { allowCreate: false });
    }
    function appendFolder(path) {
        var box = q('#runs-edit-folders');
        var lines = box.value.split(/\r?\n/).map(function (line) { return line.trim(); }).filter(Boolean);
        if (path && lines.indexOf(path) === -1) lines.push(path);
        box.value = lines.join('\n');
        box.focus();
        updateDirty();
    }
    function selectSource(source) {
        state.open.source = source;
        state.open.sourceChosen = true;
        state.open.selectedScript = null;
        state.open.pathCheck = '';
        state.open.originalPath = '';
        q('[data-role="user-script-picker"]').hidden = source !== 'userScript';
        q('[data-role="file-picker"]').hidden = source !== 'file';
        q('[data-role="path-check"]').textContent = '';
        drawUserScriptChoices();
        updateDirty();
    }
    function selectUserScript(script) {
        state.open.selectedScript = script;
        state.open.formName = q('#runs-edit-name').value;
        state.open.formDescription = q('#runs-edit-description').value;
        drawUserScriptChoices();
        updateDirty();
    }
    function bindDialog(back, dialog) {
        back.addEventListener('click', function (event) { if (event.target === back) requestClose(); });
        back.querySelector('.runs-close').addEventListener('click', requestClose);
        dialog.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || event.target.tagName === 'TEXTAREA' || event.target.tagName === 'BUTTON') return;
            if (event.target.tagName === 'INPUT' && ['checkbox', 'radio'].indexOf(event.target.type) !== -1) return;
            event.preventDefault();
            saveEntry();
        });
        dialog.addEventListener('input', function (event) {
            if (event.target.id === 'runs-edit-description') updateDescriptionCount();
            if (event.target.id === 'runs-add-path') {
                q('[data-role="path-check"]').textContent = '';
                state.open.pathCheck = '';
                state.open.originalPath = '';
                q('[data-action="use-original"]').hidden = true;
            }
            if (event.target.matches('[data-role="user-script-filter"]')) drawUserScriptChoices();
            updateDirty();
        });
        dialog.addEventListener('change', function (event) {
            var source = event.target.getAttribute('data-source');
            if (source) { selectSource(source); return; }
            var mode = event.target.getAttribute('data-mode');
            if (mode) {
                qa('[data-mode]').forEach(function (radio) { radio.checked = radio === event.target; });
            }
            var path = event.target.getAttribute('data-user-script-path');
            if (path) {
                var selected = userScriptsForDisplay().find(function (item) { return item.path === path; });
                if (selected) selectUserScript(selected);
            }
            if (event.target.id === 'runs-add-path') checkPath();
            if (event.target.id === 'runs-folder-path-picker') appendFolder(event.target.value);
            updateDirty();
        });
        dialog.addEventListener('focusout', function (event) { if (event.target.id === 'runs-add-path') checkPath(); });
        dialog.addEventListener('click', function (event) {
            var action = event.target.closest('[data-action]');
            if (action) {
                var name = action.getAttribute('data-action');
                if (name === 'trust') trustCurrent();
                else if (name === 'copy-hash') copyHash();
                else if (name === 'repoint') repoint();
                else if (name === 'use-original') useOriginal();
                else if (name === 'browse-file') browseFile();
                else if (name === 'browse-folder') browseFolder();
                else if (name === 'discard') discardChanges();
                else if (name === 'keep-editing') { state.open.closePrompt = false; renderDiscard(); q('[data-action="save"]').focus(); }
                return;
            }
            var openId = event.target.closest('[data-open-id]');
            if (openId) {
                var target = state.scripts.find(function (item) { return item.id === openId.getAttribute('data-open-id'); });
                if (target) { closeDialog(null, false); openEntry(target.id, list.querySelector('[data-entry-id="' + target.id + '"]')); }
            }
        });
        var filePicker = dialog.querySelector('#runs-add-path-picker');
        filePicker.addEventListener('change', function () {
            var path = filePicker.value.replace(/\/+$/, '');
            if (path) {
                q('#runs-add-path').value = path + '/';
                var check = q('[data-role="path-check"]');
                check.textContent = 'The browser selects folders. Type the script file name at the end.';
                state.open.pathCheck = check.textContent;
                q('#runs-add-path').focus();
                updateDirty();
            }
        });
    }

    if (addButton) addButton.addEventListener('click', openAdd);
    if (switchControl) switchControl.addEventListener('change', setStatus);
    var generatePasswordButton = document.getElementById('aicli-run-password-generate');
    if (generatePasswordButton) generatePasswordButton.addEventListener('click', generateRunPassword);
    var copyPasswordButton = document.getElementById('aicli-run-password-copy');
    if (copyPasswordButton) copyPasswordButton.addEventListener('click', copyRunPassword);
    if (signInButton) signInButton.addEventListener('click', openSignInWorkspace);
    if (signInAgent) signInAgent.addEventListener('change', renderRunUser);
    renderList();
    loadList();
}());
