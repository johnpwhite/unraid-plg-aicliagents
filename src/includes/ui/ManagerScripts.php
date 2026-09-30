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
    var curAgentPath = '<?= addslashes($config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence') ?>';
    var curHomePath = '<?= addslashes($config['home_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence') ?>';
    var newStorageMode = $(form).find('[name="storage_backend_mode"]').val() || 'layering';
    var curStorageMode = '<?= addslashes($config['storage_backend_mode'] ?? 'layering') ?>';

    // The engine is global.  Probe the actual backing devices before accepting
    // a change, then let the server migrate every entity before saving the mode.
    // A removable/USB result is an explicit warning, not a path-prefix guess.
    if (newStorageMode !== curStorageMode) {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=preflight_storage_backend&mode=' + encodeURIComponent(newStorageMode) + '&csrf_token=' + csrf, function(pf) {
            if (pf.status !== 'ok') { swal("Error", pf.message || "Storage-engine check failed", "error"); return; }
            var msg = pf.message || ('This will migrate all agents and homes to ' + newStorageMode + '.');
            swal({ title: "Change persistence engine?", text: msg, type: "warning", showCancelButton: true, confirmButtonText: "Migrate all storage", cancelButtonText: "Cancel", closeOnConfirm: false, showLoaderOnConfirm: true }, function() {
                showMigrateOverlay();
                $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=execute_storage_backend&mode=' + encodeURIComponent(newStorageMode) + '&ack_wear=1&csrf_token=' + csrf, function(r) {
                    hideMigrateOverlay();
                    if (r.status === 'ok') {
                        swal({ title: "Storage engine changed", text: r.message || "All storage was migrated.", type: "success", confirmButtonText: "OK" }, function() { safeReload(); });
                    } else {
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

    if ((newAgentPath && newAgentPath !== curAgentPath) || (newHomePath && newHomePath !== curHomePath)) {
        // Storage path changed — preflight (fast, no consolidation)
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=preflight_migrate&agent_storage_path=' + encodeURIComponent(newAgentPath) + '&home_storage_path=' + encodeURIComponent(newHomePath) + '&csrf_token=' + csrf, function(pf) {
            if (pf.status !== 'ok') { swal("Error", pf.message || "Preflight failed", "error"); return; }
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

            swal({ title: "Migrate Storage?", text: msg, html: true, type: "warning", showCancelButton: true, confirmButtonText: "Yes, Migrate", cancelButtonText: "Cancel", closeOnConfirm: false, showLoaderOnConfirm: true }, function() {
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
                $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=execute_migrate&agent_storage_path=' + encodeURIComponent(newAgentPath) + '&home_storage_path=' + encodeURIComponent(newHomePath) + '&old_agent_path=' + encodeURIComponent(curAgentPath) + '&old_home_path=' + encodeURIComponent(curHomePath) + '&csrf_token=' + csrf, function(r) {
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
    $.post('/plugins/unraid-aicliagents/AICliAjax.php?action=save&csrf_token=' + csrf, params, function() {
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
    // EVENT_FIRST_RECONCILIATION.md fact table: the debug log tail has no push
    // behind it (low value to push) — poll only while the tab is actually
    // visible AND the Debug Console tab is the one showing, never into a
    // hidden/background tab.
    setInterval(function() {
        if (document.visibilityState === 'visible' && $('#tab-debug').hasClass('active')) refreshLog();
    }, 5000);
});
</script>
