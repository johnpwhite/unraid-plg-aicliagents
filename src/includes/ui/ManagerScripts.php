<?php
/**
 * <module_context>
 * Description: Main entry point and initialization for AICliAgents Manager UI.
 * Dependencies: ManagerGlobalState, ManagerLogScripts, ManagerStorageScripts, ManagerStoreScripts.
 * Constraints: Atomic UI fragment (< 100 lines).
 * </module_context>
 */
?>
<script>
// Show-value eye button for every masked input (docs/specs/MASKED_VALUE_SHOW_ICON.md).
// One helper for all Manager tabs. Put data-aicli-eye on a password input, or call
// aicliMaskEye(input) for an input that the page builds later.
// The button only changes the input type. It makes no server call and saves nothing.
// A value that is only stored on the server shows as dots, and no existing read path
// returns it, so the button stays hidden until the person types a value.
(function () {
    var MASK = '••••••••';
    var NS = 'http://www.w3.org/2000/svg';
    // Same two icons as the Notebook connection card (lucide Eye and EyeOff).
    var PATHS = {
        show: ['M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0'],
        hide: ['M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49',
               'M14.084 14.158a3 3 0 0 1-4.242-4.242',
               'M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143',
               'm2 2 20 20']
    };
    function icon(kind) {
        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '16'); svg.setAttribute('height', '16');
        svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.75'); svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round'); svg.setAttribute('aria-hidden', 'true');
        PATHS[kind].forEach(function (d) {
            var p = document.createElementNS(NS, 'path');
            p.setAttribute('d', d);
            svg.appendChild(p);
        });
        if (kind === 'show') {
            var c = document.createElementNS(NS, 'circle');
            c.setAttribute('cx', '12'); c.setAttribute('cy', '12'); c.setAttribute('r', '3');
            svg.appendChild(c);
        }
        return svg;
    }
    window.aicliMaskEye = function (input) {
        if (!input || !input.parentNode || input.dataset.aicliEyeReady === '1') return;
        input.dataset.aicliEyeReady = '1';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'aicli-eye-btn';
        btn.hidden = true;
        var shown = false;
        function paint() {
            var label = shown ? 'Hide value' : 'Show value';
            btn.setAttribute('aria-label', label);
            btn.title = label;
            btn.setAttribute('aria-pressed', shown ? 'true' : 'false');
            while (btn.firstChild) btn.removeChild(btn.firstChild);
            btn.appendChild(icon(shown ? 'hide' : 'show'));
            input.type = shown ? 'text' : 'password';
        }
        // Show the button only when the field holds a value the person typed.
        function sync() {
            var v = input.value;
            var typed = v !== '' && v !== MASK;
            if (!typed && shown) { shown = false; paint(); }
            btn.hidden = !typed;
        }
        btn.addEventListener('click', function () {
            shown = !shown;
            paint();
            input.focus();
        });
        input.addEventListener('input', sync);
        input.addEventListener('change', sync);
        input.addEventListener('focus', sync);
        // A script can set the value with no event; look again when the pointer or focus enters the row.
        input.parentNode.addEventListener('pointerover', sync);
        input.parentNode.addEventListener('focusin', sync);
        input.parentNode.insertBefore(btn, input.nextSibling);
        paint();
        sync();
    };
    function initAll() {
        document.querySelectorAll('input[data-aicli-eye]').forEach(window.aicliMaskEye);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);
    else initAll();
})();

function switchMainTab(tab, el) {
    localStorage.setItem('aicli_manager_tab', tab);
    $('.aicli-tab-btn').removeClass('active');
    $(el).addClass('active');
    $('.aicli-tab-content').removeClass('active');
    $('#tab-' + tab).addClass('active');
    
    // Auto-scroll log if switching to debug tab
    if (tab === 'debug') {
        const lb = $('#log-content');
        if (lb.length && lb[0].scrollHeight) {
            lb.scrollTop(lb[0].scrollHeight);
        }
    }
}

// D-403: No-op safety net. The addEventListener/jQuery.on interceptors in ManagerGlobalState
// permanently block beforeunload registration, so this is only needed for legacy call sites.
function clearChanged() {}
window.clearChanged = clearChanged;

// D-403: Safe page reload that won't trigger the unsaved changes dialog.
function safeReload() {
    clearChanged();
    location.replace(location.href);
}

function resetStatsTimer() {
    if (statsTimer) clearInterval(statsTimer);
    statsTimer = setInterval(refreshStats, statsInterval);
}

// D-405: Auto-save with debounce — called by onchange on all non-secret, non-path inputs
var _autoSaveTimer = null;
function autoSaveConfig() {
    clearTimeout(_autoSaveTimer);
    _autoSaveTimer = setTimeout(function() {
        saveAICliAgentsManager(document.getElementById('aicli-settings-form'), true);
    }, 400);
}

function saveAICliAgentsManager(form, silent = false) {
    clearChanged();
    // D-405: Check if storage paths changed — if so, run preflight migration instead of normal save
    var newAgentPath = $(form).find('[name="agent_storage_path"]').val() || '';
    var newHomePath = $(form).find('[name="home_storage_path"]').val() || '';
    var curAgentPath = '<?= addslashes($config['agent_storage_path'] ?? \AICliAgents\Services\SecretPaths::flashRoot() . '/persistence') ?>';
    var curHomePath = '<?= addslashes($config['home_storage_path'] ?? \AICliAgents\Services\SecretPaths::flashRoot() . '/persistence') ?>';
    var newUpperMode = $(form).find('[name="storage_upper_mode"]').val() || 'zram';
    var curUpperMode = '<?= addslashes($config['storage_upper_mode'] ?? 'zram') ?>';

    // #456: the writable-layer placement of homes. A change is a MOVE, never a bare save:
    // read-only preflight, a warning overlay with the facts, then the move behind a progress overlay.
    if (newUpperMode !== curUpperMode) {
        aicliChangeUpperMode(newUpperMode, curUpperMode, form);
        return;
    }

    // Home storage and Agent storage are two settings. A change of one is a conversion of
    // that kind only, never a bare save: probe the backing devices, show what will happen,
    // then let the server convert the items before it saves the setting.
    // A removable/USB result is an explicit warning, not a path-prefix guess.
    var storageKinds = ['homes', 'agents'];
    for (var ki = 0; ki < storageKinds.length; ki++) {
        var kindName = storageKinds[ki];
        var kindNew = $(form).find('[name="storage_backend_' + kindName + '"]').val() || 'layering';
        var kindCur = aicliCurrentStorageMode(kindName);
        if (kindNew !== kindCur) {
            aicliChangeStorageKind(kindName, kindNew, kindCur, form);
            return;
        }
    }

    if ((newAgentPath && newAgentPath !== curAgentPath) || (newHomePath && newHomePath !== curHomePath)) {
        // Storage path changed — preflight (fast, no consolidation)
        if (window._aicliPathBusy) return;
        window._aicliPathBusy = true;
        var restorePaths = function () {
            window._aicliPathBusy = false;
            $(form).find('[name="agent_storage_path"]').val(curAgentPath);
            $(form).find('[name="home_storage_path"]').val(curHomePath);
        };
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=preflight_migrate&agent_storage_path=' + encodeURIComponent(newAgentPath) + '&home_storage_path=' + encodeURIComponent(newHomePath) + '&csrf_token=' + csrf, function(pf) {
            if (pf.status !== 'ok') { restorePaths(); swal("Error", pf.message || "Preflight failed", "error"); return; }
            // Bug #297: escapeHtml (ManagerStorageScripts.php, loaded earlier on
            // this page) — the message below is rendered as HTML so the /mnt/user
            // advice box can use <code>/<div> markup, so every path/name value
            // must be escaped before it goes in.
            var esc = window.escapeHtml || function (s) { return String(s == null ? '' : s); };
            var fileList = '';
            $.each(pf.files, function(i, f) { fileList += esc(f.name) + ' (' + f.size_mb + ' MB)<br>'; });
            var msg = '';
            if (pf.agent_changed) msg += 'Agents: <code>' + esc(pf.old_agent_path) + '</code> → <code>' + esc(pf.new_agent_path) + '</code><br>';
            if (pf.home_changed) msg += 'Homes: <code>' + esc(pf.old_home_path) + '</code> → <code>' + esc(pf.new_home_path) + '</code><br>';
            msg += '<br>Files will be persisted and consolidated before moving.<br>';
            msg += pf.files.length + ' items (' + pf.total_mb + ' MB total):<br>' + fileList;

            // Bug #297: plain-English advice when either target still resolves
            // onto /mnt/user (Unraid's shared-folder layer, "shfs") — a resolved
            // single-pool/exclusive-share target carries no via_user_share
            // warning (preflightMigrate only reports it for a target that
            // genuinely stays on FUSE), so this never shows for those.
            var wHome = (pf.warnings && pf.warnings.home) || [];
            var wAgent = (pf.warnings && pf.warnings.agent) || [];
            if (wHome.indexOf('via_user_share') !== -1 || wAgent.indexOf('via_user_share') !== -1) {
                msg += '<div style="margin-top:10px; padding:8px 10px; border-radius:4px; background:rgba(234,179,8,0.12); border:1px solid rgba(234,179,8,0.4); text-align:left; font-size:12px;">'
                    + '<i class="fa fa-info-circle"></i> This move keeps data on <code>/mnt/user</code>, Unraid\'s shared-folder layer ("shfs"). '
                    + 'Heavy save or merge activity through shfs can slow down or freeze the whole server. '
                    + 'A pool path (<code>/mnt/cache/…</code>), an Unassigned Device path (<code>/mnt/disks/…</code>), or a single-disk path '
                    + '(<code>/mnt/diskN/…</code>) avoids this. <code>/mnt/user</code> is fine to use when none of those is available.'
                    + '</div>';
            }

            aicliGuardedSwal({ title: "Migrate Storage?", text: msg, html: true, type: "warning", showCancelButton: true, confirmButtonText: "Yes, Migrate", cancelButtonText: "Cancel", closeOnConfirm: false, showLoaderOnConfirm: true }, restorePaths, function() {
                // DO NOT save config here — execute_migrate saves it AFTER copying files.
                // Saving first would update paths before migration, causing persist/consolidate
                // to target the empty new path instead of the old path with actual data.

                // Show migration overlay. Progress updates arrive on the one
                // page-lifetime `aicli_migrate_progress` subscriber that
                // NchanSubscribers.php already opened on page load — it
                // targets this overlay whenever it is present (D9: this used
                // to open its own second subscription on a second channel).
                showMigrateOverlay();

                // Execute migration (pass both old and new paths — execute_migrate saves config AFTER copying)
                aicliAjax('execute_migrate', { agent_storage_path: newAgentPath, home_storage_path: newHomePath, old_agent_path: curAgentPath, old_home_path: curHomePath }, function(r) {
                    if (r.status === 'queued') {
                        // F18 (#652): the copy runs in a supervisor job and goes on when this page closes.
                        // Save the settings of the form EXCEPT the two paths (the job saves those after the copy).
                        var rest = $(form).serializeArray().filter(function (f) { return f.name !== 'agent_storage_path' && f.name !== 'home_storage_path'; });
                        $.post('/plugins/unraid-aicliagents/AICliAjax.php?action=save&csrf_token=' + csrf, $.param(rest));
                        hideMigrateOverlay();
                        swal({ title: "Storage move started", text: r.message || "The files are copied in the background. Watch the activity tray.", type: "info", confirmButtonText: "OK" }, function() { safeReload(); });
                        return;
                    }
                    if (r.status === 'ok') {
                        // Now save all non-path form settings (migration already saved the paths)
                        var params = $(form).serialize();
                        $.post('/plugins/unraid-aicliagents/AICliAjax.php?action=save&csrf_token=' + csrf, params);
                        hideMigrateOverlay();
                        swal({ title: "Migration Complete", text: r.message || "Storage moved successfully.", type: "success", confirmButtonText: "OK" }, function() { safeReload(); });
                    } else {
                        hideMigrateOverlay();
                        swal("Migration Failed", r.message || "Check debug.log", "error");
                    }
                }).fail(function() {
                    hideMigrateOverlay();
                    swal("Migration Failed", "Server communication error", "error");
                });
            });
        });
        return;
    }

    // Normal save (no path changes)
    let params = $(form).serialize();
    // Bug #1054 follow-up: capture user-switch intent BEFORE the POST so we can
    // force a full reload after a successful save. Args panels, workspaces, and
    // other per-user UI state are PHP-pre-rendered from the user-home that was
    // active at the previous page load -- without a reload they keep showing
    // stale data from the old user (e.g. CLI args panel empty after switching
    // back to root, even though root's args file is on disk).
    var originalUser = ($('#aicli-original-user').val() || 'root');
    var newUser = ($(form).find('[name="user"]').val() || 'root');
    var userChanged = (originalUser !== newUser);
    $.post('/plugins/unraid-aicliagents/AICliAjax.php?action=save&csrf_token=' + csrf, params, function(r) {
        // AGENT_TEMP_FOLDER.md R2: the server refused one setting (for example a folder under /mnt/user, or the boot disk on a
        // USB stick). The rest was saved. Show the reason, then reload the form so it shows the stored value.
        if (r && r.status === 'error' && r.message) {
            swal({ title: "Not saved", text: r.message, type: "error" }, function() { safeReload(); });
            return;
        }
        if (userChanged) {
            // Always reload on a Terminal User switch -- silent or not. The new
            // user's workspaces.json + args files live in a different home
            // overlay, so the only way to surface them is a full page render.
            safeReload();
        } else if (!silent) {
            safeReload();
        } else {
            swal({ title: "Saved", text: "Configuration updated.", type: "success", timer: 1000, showConfirmButton: false });
        }
    });
}

// Epic #307: the card uses min() widths so it never passes the screen edge on a
// phone; on a desktop the 400-600 px card is unchanged.
// A setting change that needs a confirm dialog. Any way of closing the dialog other than
// "confirm" calls restore(): Cancel, Escape, and a click on the backdrop (which sweetalert
// reports with no callback). The watcher starts only once the dialog has been SEEN open,
// so the wait before it appears can never count as a dismissal (#457).
function aicliGuardedSwal(opts, restore, onConfirm) {
    var settled = false, seen = false;
    var watch = setInterval(function () {
        if (settled) { clearInterval(watch); return; }
        if ($('.sweet-alert.visible').length) { seen = true; return; }
        if (seen) { settled = true; clearInterval(watch); restore(); }
    }, 250);
    opts.allowOutsideClick = false;
    opts.allowEscapeKey = true;
    swal(opts, function (ok) {
        settled = true; clearInterval(watch);
        if (!ok) { restore(); return; }
        onConfirm();
    });
}

function aicliChangeUpperMode(target, previous, form) {
    var esc = window.escapeHtml || function (s) { return String(s == null ? '' : s); };
    // One check at a time: a second autosave while the first dialog is open must not
    // open a second dialog over it (the first one would then count as dismissed).
    if (window._aicliUpperBusy) return;
    window._aicliUpperBusy = true;
    var restore = function () { window._aicliUpperBusy = false; $(form).find('[name="storage_upper_mode"]').val(previous); };
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=preflight_upper_mode&mode=' + encodeURIComponent(target) + '&csrf_token=' + csrf, function(pf) {
        if (pf.status !== 'ok') { restore(); swal("Cannot change the writable layer", pf.message || "Check failed", "error"); return; }
        var rows = '';
        $.each(pf.homes || [], function(i, h) {
            rows += '<div style="padding:2px 0;"><b>' + esc(h.id) + '</b>: ' +
                (h.mounted ? 'now on ' + (h.live === 'zram' ? 'RAM' : 'disk') : 'not mounted') + ', ' +
                (h.needs_move ? ('will move (' + esc(h.mb) + ' MB)') : 'stays where it is') + '</div>';
        });
        var html = '<div style="text-align:left; line-height:1.5;">' + esc(pf.message || '') +
            (pf.moves > 0 ? '<div style="margin-top:10px; padding:8px 10px; border-radius:4px; background:rgba(234,179,8,0.12); border:1px solid rgba(234,179,8,0.4); font-size:12px;">' +
                '<i class="fa fa-exclamation-triangle"></i> <b>Your open workspaces will be closed</b> while the data moves. This can take several minutes for a large home. ' +
                'Do not reload this page or restart the server meanwhile.</div>' : '') +
            (rows ? '<div style="max-height:160px; overflow:auto; margin-top:10px; font-size:13px;" data-testid="upper-mode-homes">' + rows + '</div>' : '') + '</div>';
        aicliGuardedSwal({ title: "Move the writable layer to " + (target === 'zram' ? 'RAM' : 'disk') + "?", text: html, html: true, type: "warning",
               showCancelButton: true, confirmButtonText: pf.moves > 0 ? "Close workspaces and move" : "Save setting", cancelButtonText: "Cancel", closeOnConfirm: false }, restore, function() {
            swal.close();
            showMigrateOverlay();
            updateMigrateOverlay('Moving writable layers to ' + (target === 'zram' ? 'RAM' : 'disk') + '...', 5, 'Workspaces are being closed, then each home is saved and mounted again.');
            aicliAjax('execute_upper_mode', { mode: target }, function(r) {
                hideMigrateOverlay();
                if (r.status === 'ok') {
                    swal({ title: "Writable layer changed", text: r.message || "Done.", type: "success", confirmButtonText: "OK" }, function() { safeReload(); });
                } else {
                    restore();
                    swal("Not moved", r.message || "Check debug.log", "error");
                }
            }).fail(function() {
                hideMigrateOverlay();
                swal("Move failed", "The server did not answer. Reload the page to see where the data is before you try again.", "error");
            });
        });
    }).fail(function() { restore(); swal("Error", "Could not check the change. Try again.", "error"); });
}

// ---- Home storage and Agent storage (GLOBAL_STORAGE_BACKEND_MIGRATION.md) ----
// The saved value of each kind. The page value is the start; a finished migration moves it.
window._aicliCurrentStorage = window._aicliCurrentStorage || {
    homes: '<?= addslashes($config['storage_backend_homes'] ?? 'layering') ?>',
    agents: '<?= addslashes($config['storage_backend_agents'] ?? 'layering') ?>'
};
function aicliCurrentStorageMode(kind) { return window._aicliCurrentStorage[kind] || 'layering'; }
function aicliStorageSelects() { return $('select[name="storage_backend_homes"], select[name="storage_backend_agents"]'); }
function aicliKindSelect(kind) { return $('select[name="storage_backend_' + kind + '"]'); }
function aicliKindWord(kind) { return kind === 'agents' ? 'agent storage' : 'home storage'; }
function aicliKindModeLabel(mode) { return mode === 'passthrough' ? 'Plain folders' : 'SquashFS layers'; }

// Show the REAL state of each kind under its select. It comes from the storage status, not
// from the saved value, so a home that was converted by hand shows at once ("Mixed: ...").
// When the kind is mixed, or the saved value and the real state differ, a button finishes the job.
function aicliRenderKindState(r) {
    ['homes', 'agents'].forEach(function (kind) {
        var box = $('#aicli-kind-state-' + kind);
        var s = r && r[kind];
        if (!box.length || !s) return;
        var saved = s.saved || aicliCurrentStorageMode(kind);
        box.empty();
        $('<span>').text('Now: ' + s.label + '.').appendTo(box);
        var missing = Number(s.total || 0) - Number(saved === 'passthrough' ? s.plain : s.layers);
        if (s.state !== 'none' && missing > 0) {
            var left = missing + (kind === 'agents' ? ' agent' : ' home') + (missing === 1 ? '' : 's');
            $('<button type="button">').attr('data-testid', 'storage-kind-finish-' + kind)
                .text('Convert the other ' + left + ' to ' + aicliKindModeLabel(saved).toLowerCase())
                .css({ marginLeft: '8px' })
                .on('click', function () { aicliChangeStorageKind(kind, saved, saved, document.getElementById('aicli-settings-form')); })
                .appendTo(box);
        }
    });
}
function aicliLoadKindState() {
    if (!$('#aicli-kind-state-homes').length) return;
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_storage_kind_state&csrf_token=' + encodeURIComponent(csrf))
        .done(aicliRenderKindState);
}

// Convert one kind (homes or agents) to newMode. newMode may equal the saved value: then the
// kind is mixed and only the items that are not on newMode yet convert.
function aicliChangeStorageKind(kind, newMode, curMode, form) {
    if (window._aicliEngineBusy) return;
    window._aicliEngineBusy = true;
    var restoreEngine = function () { window._aicliEngineBusy = false; aicliKindSelect(kind).val(aicliCurrentStorageMode(kind)); };
    var word = aicliKindWord(kind);
    var q = '&mode=' + encodeURIComponent(newMode) + '&kind=' + encodeURIComponent(kind);
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=preflight_storage_backend' + q + '&csrf_token=' + csrf, function(pf) {
        if (pf.status !== 'ok') { restoreEngine(); swal("Error", pf.message || "Storage check failed", "error"); return; }
        var msg = pf.message || ('This will convert ' + word + ' to ' + aicliKindModeLabel(newMode).toLowerCase() + '.');
        aicliGuardedSwal({ title: "Change " + word + "?", text: msg, type: "warning", showCancelButton: true, confirmButtonText: kind === 'agents' ? "Convert agents" : "Convert homes", cancelButtonText: "Cancel", closeOnConfirm: false, showLoaderOnConfirm: true }, restoreEngine, function() {
            sessionStorage.removeItem('aicli_backend_migration_dismissed');
            renderBackendMigrationStatus({ status: 'queued', kind: kind, target: newMode, message: 'Starting the migration…' });
            aicliAjax('execute_storage_backend', { mode: newMode, kind: kind, ack_wear: 1 }, function(r) {
                if (r.status === 'accepted') {
                    window._aicliBackendMigrationStartedHere = true;
                    if (typeof swal.close === 'function') swal.close();
                    updateBackendMigrationStatus();
                } else if (r.status === 'saved') {
                    // Every item was on the new kind already: only the setting changed.
                    window._aicliCurrentStorage[kind] = newMode;
                    window._aicliEngineBusy = false;
                    renderBackendMigrationStatus({ status: 'idle' });
                    if (typeof swal.close === 'function') swal.close();
                    aicliLoadKindState();
                } else {
                    restoreEngine();
                    renderBackendMigrationStatus({ status: 'error', kind: kind, from: curMode, target: newMode, message: r.message || 'Storage migration failed.' });
                    swal("Migration Failed", r.message || "Check debug.log", "error");
                }
            }).fail(function() {
                // A response can be lost after the detached worker starts.
                // Close the prompt and use the saved state as the source of truth.
                window._aicliBackendMigrationStartedHere = true;
                if (typeof swal.close === 'function') swal.close();
                updateBackendMigrationStatus();
            });
        });
    }).fail(function() { restoreEngine(); swal("Error", "Could not check the change. Try again.", "error"); });
}

function showMigrateOverlay() {
    $('#aicli-migrate-overlay').remove();
    $('body').append(
        '<div id="aicli-migrate-overlay" style="position:fixed; inset:0; z-index:3000000; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center;">' +
            '<div style="background:var(--background-color, #fff); border:1px solid var(--border-color, #ccc); border-radius:8px; padding:30px min(40px, 5vw); min-width:min(400px, calc(100vw - 32px)); max-width:min(600px, calc(100vw - 32px)); box-sizing:border-box; overflow-wrap:anywhere; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.3);">' +
                '<i class="fa fa-truck fa-2x" style="color:var(--orange, #ff8c00); margin-bottom:16px;"></i>' +
                '<h3 id="migrate-step" style="margin:0 0 12px 0;">Preparing migration...</h3>' +
                '<div style="width:100%; height:8px; background:var(--mild-background-color, #eee); border-radius:4px; overflow:hidden; margin-bottom:10px;">' +
                    '<div id="migrate-bar" style="height:100%; width:0%; background:var(--orange, #ff8c00); transition:width 0.3s;"></div>' +
                '</div>' +
                '<div id="migrate-file" style="font-family:monospace; font-size:11px; opacity:0.6; min-height:16px;"></div>' +
            '</div>' +
        '</div>'
    );
}

function updateMigrateOverlay(step, progress, file) {
    $('#migrate-step').text(step || 'Working...');
    $('#migrate-bar').css('width', (progress || 0) + '%');
    if (file) $('#migrate-file').text(file);
}

function hideMigrateOverlay() {
    $('#aicli-migrate-overlay').fadeOut(200, function() { $(this).remove(); });
}

function renderBackendMigrationStatus(s) {
    var box = $('#aicli-backend-migration-status');
    if (!box.length) return;
    if (!s || !s.status || s.status === 'idle') {
        box.prop('hidden', true).empty();
        return;
    }
    var dismissedStartedAt = Number(sessionStorage.getItem('aicli_backend_migration_dismissed') || 0);
    var statusStartedAt = Number(s.started_at || 0);
    if (dismissedStartedAt > 0 && statusStartedAt > 0 && statusStartedAt <= dismissedStartedAt) {
        box.prop('hidden', true).empty();
        return;
    }
    if (dismissedStartedAt > 0 && statusStartedAt > dismissedStartedAt) sessionStorage.removeItem('aicli_backend_migration_dismissed');
    box.prop('hidden', false).empty();
    var interrupted = s.status === 'interrupted';
    var running = s.status === 'running' || s.status === 'queued' || interrupted;
    // Say which kind converts: homes, agents, or (an older request) both.
    var kindWord = s.kind === 'homes' ? 'Home storage' : (s.kind === 'agents' ? 'Agent storage' : 'Storage');
    var kindNames = s.kind === 'homes' ? ['homes'] : (s.kind === 'agents' ? ['agents'] : ['homes', 'agents']);
    var title = s.status === 'queued' ? kindWord + ' migration starting'
        : (s.phase === 'rolling_back' ? 'Restoring ' + kindWord.toLowerCase() + ' after a conversion problem'
        : (interrupted ? kindWord + ' migration stopped' : (running ? kindWord + ' migration in progress'
        : (s.status === 'complete' ? kindWord + ' migration complete' : kindWord + ' migration needs attention'))));
    var target = s.target === 'passthrough' ? 'Plain folders' : (s.target === 'layering' ? 'SquashFS layers' : '');
    $('<strong>').text(title).appendTo(box);
    $('<div>').text((target ? 'Target: ' + target + '. ' : '') + (s.message || '')).appendTo(box);
    if (running && Number(s.total) > 0) {
        var current = s.current ? 'Current item: ' + s.current + '. ' : '';
        $('<div>').text(current + Number(s.completed || 0) + ' of ' + Number(s.total) + ' complete.').appendTo(box);
        $('<progress>').attr({ max: Number(s.total), value: Number(s.completed || 0), 'aria-label': 'Storage migration progress' }).css({ width: '100%', marginTop: '6px' }).appendTo(box);
        aicliStorageSelects().prop('disabled', true);
    } else if (running) {
        aicliStorageSelects().prop('disabled', true);
        $('<div>').text(s.message || 'Workspaces cannot open until the migration finishes.').appendTo(box);
        if (interrupted) {
            $('<button type="button">').text('Continue').css({ marginTop: '6px', marginRight: '6px' }).on('click', function() { aicliRecoverBackendMigration('continue'); }).appendTo(box);
            $('<button type="button">').text('Put back').css({ marginTop: '6px' }).on('click', function() { aicliRecoverBackendMigration('rollback'); }).appendTo(box);
        }
    } else {
        aicliStorageSelects().prop('disabled', false);
        // Set the select(s) of the migrated kind from a finished record ONCE per record, not on
        // every poll: a later poll must not move a select back while the person picks a new
        // value and its confirmation is open.
        // A person who already picked another value keeps the choice, also before the
        // first reply arrives (the 400 ms save delay, the check, the open warning): the
        // record then moves only the baseline that Cancel restores (#702).
        var recordKey = String(s.started_at || '') + ':' + String(s.status || '');
        var choosing = !!window._aicliEngineBusy;
        kindNames.forEach(function (k) {
            if (aicliKindSelect(k).val() !== aicliCurrentStorageMode(k)) choosing = true;
        });
        var firstSeen = !choosing && window._aicliBackendStatusApplied !== recordKey;
        if (!choosing) window._aicliBackendStatusApplied = recordKey;
        kindNames.forEach(function (k) {
            if (firstSeen && s.status === 'complete' && target) aicliKindSelect(k).val(s.target);
            if (s.status === 'complete' && s.target) window._aicliCurrentStorage[k] = s.target;
            if ((s.status === 'error' || s.status === 'interrupted') && s.from) window._aicliCurrentStorage[k] = s.from;
            if (firstSeen && (s.status === 'error' || s.status === 'interrupted') && s.from) aicliKindSelect(k).val(s.from);
        });
        if (firstSeen && (s.status === 'error' || s.status === 'interrupted') && s.from) window._aicliEngineBusy = false;
        if (s.status === 'complete') window._aicliEngineBusy = false;
        if (firstSeen && s.status !== 'queued') aicliLoadKindState();
        if (s.status === 'complete' && window._aicliBackendMigrationStartedHere) {
            window._aicliBackendMigrationStartedHere = false;
            setTimeout(safeReload, 800);
        }
        $('<button type="button">').text('Dismiss').css({ marginTop: '6px' }).on('click', function() {
            sessionStorage.setItem('aicli_backend_migration_dismissed', String(Number(s.started_at || Date.now())));
            box.prop('hidden', true).empty();
        }).appendTo(box);
    }
}

function aicliRecoverBackendMigration(operation) {
    aicliAjax('storage_backend_migration_recovery', { operation: operation }, function(r) {
        if (!r || r.status !== 'accepted') {
            if (typeof window.swal === 'function') window.swal('Storage recovery did not start', (r && r.message) || 'Try again after you check the storage status.', 'error');
            return;
        }
        sessionStorage.removeItem('aicli_backend_migration_dismissed');
        renderBackendMigrationStatus({ status: 'queued', message: r.message || 'Storage recovery is starting.' });
        updateBackendMigrationStatus();
        if (window.aicliBootSpace && typeof window.aicliBootSpace.refresh === 'function') window.aicliBootSpace.refresh();
    });
}
window.aicliRecoverBackendMigration = aicliRecoverBackendMigration;

function updateBackendMigrationStatus() {
    if (!$('#aicli-backend-migration-status').length) return;
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=storage_backend_migration_status&csrf_token=' + encodeURIComponent(csrf))
        .done(renderBackendMigrationStatus);
}

// The shared folder browser. HOME_BACKUP.md "2026-09-24 follow-up": it has a
// "New folder" action for every caller; a caller opts out with
// openPathPicker(id, { allowCreate: false }). The folder is made on the server
// (picker_create_folder: one name, inside the browser's roots, never through a
// /mnt/user FUSE share) and the browser then opens it.
function aicliPickerFolderNameError(name, allowHidden) {
    if (!name) return 'Type a name for the new folder.';
    if (name.length > 64) return 'Use a name of 64 characters or fewer.';
    if (name === '.' || name === '..' || name.indexOf('/') !== -1 || name.indexOf('\\') !== -1) return 'Type one folder name, not a path.';
    if (name.charAt(0) === '.' && !allowHidden) return 'A name that starts with a dot makes a hidden folder. Use another name.';
    if (!/^[A-Za-z0-9 ._+@,()-]+$/.test(name)) return 'Use only letters, digits, spaces and . _ - + @ , ( ) in the name.';
    if (/^[- ]/.test(name)) return 'The name cannot start with a dash or a space.';
    if (/[. ]$/.test(name)) return 'The name cannot end with a dot or a space.';
    return '';
}

// Escape inside the folder browser: close the New folder row first, then the
// browser. Returns 'form', 'picker' or '' (nothing was open). The Backup
// dialog's own key handler calls this so one Escape closes one layer.
function aicliPathPickerEscape() {
    var picker = document.getElementById('aicli-path-picker-backdrop');
    if (!picker) return '';
    var row = document.getElementById('pp-new-row');
    if (row && !row.hidden) {
        row.hidden = true;
        $('#pp-new-error').removeAttr('role').text('');
        $('#pp-new-folder').trigger('focus');
        return 'form';
    }
    $(picker).remove();
    return 'picker';
}

function openPathPicker(id, opts) {
    opts = opts || {};
    const allowCreate = opts.allowCreate !== false;
    const allowHidden = !!opts.allowHidden;
    const input = $('#' + id);
    let startPath = input.val() || '/mnt';
    if (startPath.length > 1 && startPath.endsWith('/')) startPath = startPath.slice(0, -1);

    // Remove any existing picker
    $('#aicli-path-picker-backdrop').remove();

    let selectedPath = null;
    let currentDir = startPath;
    let lastClick = { time: 0, path: '' };
    // Only the newest listing may change the picker. A slow earlier reply
    // (the folder the picker opened on) otherwise arrived after the folder
    // the user typed and moved Select back to it (HOME_BACKUP.md, 2026-09-24).
    let browseSeq = 0;

    function browse(path) {
        const seq = ++browseSeq;
        currentDir = path;
        $('#pp-current-path').val(path);
        $('#pp-dir-list').html('<div style="padding:20px; text-align:center; opacity:0.5;"><i class="fa fa-spinner fa-spin"></i></div>');
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=list_dir&path=' + encodeURIComponent(path) + '&csrf_token=' + csrf, function(data) {
            if (seq !== browseSeq) return;
            if (data.status !== 'ok') {
                // Path unreadable or missing — walk up to the nearest readable ancestor
                // so the overlay still opens when the saved setting points at a deleted dir.
                const parent = path.replace(/\/+[^\/]+\/*$/, '') || '/';
                if (parent === path) {
                    $('#pp-dir-list').html('<div style="padding:30px; text-align:center; opacity:0.5;"><i class="fa fa-exclamation-triangle" style="font-size:24px; display:block; margin-bottom:8px;"></i>Cannot read directory</div>');
                    return;
                }
                browse(parent);
                return;
            }
            currentDir = data.path;
            $('#pp-current-path').val(data.path);
            let html = '';
            $.each(data.items || [], function(i, item) {
                const icon = item.name === '..' ? 'fa-level-up' : 'fa-folder';
                const iconColor = item.name === '..' ? 'inherit' : 'var(--orange, #e68a00)';
                html += '<div class="pp-dir-item" data-path="' + $('<div>').text(item.path).html() + '">' +
                    '<i class="fa ' + icon + '" style="color:' + iconColor + '; opacity:0.7;"></i>' +
                    '<span>' + $('<div>').text(item.name).html() + '</span></div>';
            });
            if (!html) html = '<div style="padding:30px; text-align:center; opacity:0.4;"><i class="fa fa-folder-open-o" style="font-size:24px; display:block; margin-bottom:8px;"></i>Empty directory</div>';
            $('#pp-dir-list').html(html);
            selectedPath = null;
            $('.pp-dir-item').removeClass('selected');

            // Bind click handlers
            $('.pp-dir-item').on('click', function() {
                const itemPath = $(this).data('path');
                const now = Date.now();
                if (lastClick.path === itemPath && (now - lastClick.time) < 350) {
                    // Double click: drill in
                    browse(itemPath);
                    lastClick = { time: 0, path: '' };
                } else {
                    // Single click: select
                    lastClick = { time: now, path: itemPath };
                    selectedPath = itemPath;
                    $('.pp-dir-item').removeClass('selected');
                    $(this).addClass('selected');
                }
            });
        }).fail(function() { browse('/'); });
    }

    const newFolderHtml = allowCreate
        ? '<div id="pp-new-row" class="pp-new-row" hidden>' +
              '<div class="pp-new-fields">' +
                  '<input type="text" id="pp-new-name" class="pp-new-name" maxlength="64" autocomplete="off" spellcheck="false" aria-label="New folder name" aria-describedby="pp-new-error" placeholder="New folder name">' +
                  '<button type="button" class="pp-btn-confirm pp-new-create" id="pp-new-create">Create</button>' +
                  '<button type="button" class="pp-btn-cancel pp-new-cancel" id="pp-new-cancel">Cancel</button>' +
              '</div>' +
              '<div id="pp-new-error" class="pp-new-error"></div>' +
          '</div>'
        : '';

    // Build modal HTML
    const modal = $('<div id="aicli-path-picker-backdrop" class="pp-backdrop">' +
        '<div class="pp-modal" role="dialog" aria-modal="true" aria-labelledby="pp-title">' +
            '<div class="pp-header"><span class="pp-title" id="pp-title"><i class="fa fa-folder-open" style="color:var(--orange, #e68a00);" aria-hidden="true"></i> Select Directory</span></div>' +
            '<div class="pp-body">' +
                '<div class="pp-path-bar"><i class="fa fa-hdd-o"></i>' +
                    '<input type="text" id="pp-current-path" aria-label="Folder path" value="' + $('<div>').text(startPath).html() + '" style="flex:1; background:transparent; border:none; color:inherit; font:inherit; font-family:monospace; font-size:12px; padding:2px 4px; outline:none; min-width:0;" spellcheck="false">' +
                '</div>' +
                newFolderHtml +
                '<div id="pp-dir-list" class="pp-dir-list" tabindex="0" aria-label="Folders"></div>' +
            '</div>' +
            '<div class="pp-footer">' +
                (allowCreate ? '<button type="button" class="pp-btn-cancel pp-new-folder" id="pp-new-folder" aria-controls="pp-new-row" aria-expanded="false"><i class="fa fa-plus" aria-hidden="true"></i> New folder</button>' : '') +
                '<button type="button" class="pp-btn-cancel" id="pp-cancel">Cancel</button>' +
                '<button type="button" class="pp-btn-confirm" id="pp-confirm"><i class="fa fa-check"></i> Select</button>' +
            '</div>' +
        '</div>' +
    '</div>');

    $('body').append(modal);

    // Path input: navigate on Enter or paste
    $('#pp-current-path').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var typed = $(this).val().trim();
            if (typed) browse(typed);
        }
    }).on('paste', function() {
        var el = this;
        setTimeout(function() {
            var pasted = $(el).val().trim();
            if (pasted) browse(pasted);
        }, 50);
    });

    // New folder: an inline name field, checked here and again on the server.
    function newFolderError(msg) {
        var box = $('#pp-new-error');
        if (msg) box.attr('role', 'alert').text(msg); else box.removeAttr('role').text('');
        $('#pp-new-name').attr('aria-invalid', msg ? 'true' : 'false');
    }
    function closeNewFolder() {
        $('#pp-new-row').prop('hidden', true);
        $('#pp-new-folder').attr('aria-expanded', 'false');
        newFolderError('');
    }
    function createFolder() {
        var name = String($('#pp-new-name').val() || '').trim();
        var err = aicliPickerFolderNameError(name, allowHidden);
        if (err) { newFolderError(err); $('#pp-new-name').trigger('focus'); return; }
        var parent = currentDir;
        var btn = $('#pp-new-create').prop('disabled', true);
        newFolderError('');
        aicliAjax('picker_create_folder', { parent: parent, name: name, allow_hidden: allowHidden ? 1 : 0 }, function(r) {
            btn.prop('disabled', false);
            if (!r || r.status !== 'ok' || !r.path) {
                newFolderError((r && r.message) || 'The folder could not be made.');
                $('#pp-new-name').trigger('focus');
                return;
            }
            closeNewFolder();
            $('#pp-new-name').val('');
            browse(r.path); // open the new folder
            $('#pp-confirm').trigger('focus');
        }).fail(function() {
            btn.prop('disabled', false);
            newFolderError('The server did not answer. The folder was not made.');
        });
    }
    $('#pp-new-folder').on('click', function() {
        var row = $('#pp-new-row');
        if (!row.prop('hidden')) { closeNewFolder(); $(this).trigger('focus'); return; }
        row.prop('hidden', false);
        $(this).attr('aria-expanded', 'true');
        newFolderError('');
        $('#pp-new-name').val('').trigger('focus');
    });
    $('#pp-new-create').on('click', createFolder);
    $('#pp-new-cancel').on('click', function() { closeNewFolder(); $('#pp-new-folder').trigger('focus'); });
    $('#pp-new-name').on('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); createFolder(); }
    }).on('input', function() { newFolderError(''); });

    // Escape: the New folder row first, then the browser. (A dialog under the
    // browser, such as the home Backup dialog, routes its Escape here too.)
    $('#aicli-path-picker-backdrop').on('keydown', function(e) {
        if (e.key !== 'Escape') return;
        e.preventDefault();
        e.stopPropagation();
        aicliPathPickerEscape();
    });

    $('#pp-cancel').on('click', function() { $('#aicli-path-picker-backdrop').remove(); });
    $('#aicli-path-picker-backdrop').on('click', function(e) { if (e.target === this) $(this).remove(); });
    $('#pp-confirm').on('click', function() {
        const chosen = selectedPath || currentDir;
        input.val(chosen);
        $('#aicli-path-picker-backdrop').remove();
        // Trigger save — for storage path fields this invokes the migration preflight
        var inputId = input.attr('id') || input.attr('name') || '';
        if (inputId === 'home_storage_path' || inputId === 'agent_storage_path') {
            saveAICliAgentsManager(document.getElementById('aicli-settings-form'), false);
        } else {
            input.trigger('change');
        }
    });

    browse(startPath);
}

$(function() {
    const lastTab = localStorage.getItem('aicli_manager_tab');
    if (lastTab && ['config', 'store', 'storage', 'hub', 'relay', 'debug'].includes(lastTab)) {
        const btn = $(`.aicli-tab-btn[onclick*="'${lastTab}'"]`);
        if (btn.length) switchMainTab(lastTab, btn[0]);
    }
    const logBox = $('#log-content');
    if (logBox.length) {
        logBox.on('mouseenter', function() { pauseAutoscroll(true); }).on('mouseleave', function() { pauseAutoscroll(false); });
        logBox[0].addEventListener('wheel', function(e) { e.stopPropagation(); }, { passive: false });
    }
    refreshLog();
    refreshLogContexts(); // R-07 (#1370): seed the Debug Console context filter dropdown
    refreshStats();
    resetStatsTimer();
    updateBackendMigrationStatus();
    aicliLoadKindState();
    setInterval(function() {
        if (document.visibilityState === 'visible') updateBackendMigrationStatus();
    }, 2500);
    // EVENT_FIRST_RECONCILIATION.md fact table: the debug log tail has no push
    // behind it (low value to push) — poll only while the tab is actually
    // visible AND the Debug Console tab is the one showing, never into a
    // hidden/background tab.
    setInterval(function() {
        if (document.visibilityState === 'visible' && $('#tab-debug').hasClass('active')) refreshLog();
    }, 5000);
});
</script>
