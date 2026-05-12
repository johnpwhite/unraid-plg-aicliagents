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

    if ((newAgentPath && newAgentPath !== curAgentPath) || (newHomePath && newHomePath !== curHomePath)) {
        // Storage path changed — preflight (fast, no consolidation)
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=preflight_migrate&agent_storage_path=' + encodeURIComponent(newAgentPath) + '&home_storage_path=' + encodeURIComponent(newHomePath) + '&csrf_token=' + csrf, function(pf) {
            if (pf.status !== 'ok') { swal("Error", pf.message || "Preflight failed", "error"); return; }
            var fileList = '';
            $.each(pf.files, function(i, f) { fileList += f.name + ' (' + f.size_mb + ' MB)\n'; });
            var msg = '';
            if (pf.agent_changed) msg += 'Agents: ' + pf.old_agent_path + ' → ' + pf.new_agent_path + '\n';
            if (pf.home_changed) msg += 'Homes: ' + pf.old_home_path + ' → ' + pf.new_home_path + '\n';
            msg += '\nFiles will be persisted and consolidated before moving.\n';
            msg += pf.files.length + ' items (' + pf.total_mb + ' MB total):\n' + fileList;

            swal({ title: "Migrate Storage?", text: msg, type: "warning", showCancelButton: true, confirmButtonText: "Yes, Migrate", cancelButtonText: "Cancel", closeOnConfirm: false, showLoaderOnConfirm: true }, function() {
                // DO NOT save config here — execute_migrate saves it AFTER copying files.
                // Saving first would update paths before migration, causing persist/consolidate
                // to target the empty new path instead of the old path with actual data.

                // Show migration overlay
                showMigrateOverlay();

                // Subscribe to Nchan for progress
                var migrateSub = null;
                if (typeof NchanSubscriber !== 'undefined') {
                    try {
                        migrateSub = new NchanSubscriber('/sub/aicli_migrate_progress', {subscriber: 'websocket', reconnectTimeout: 2000});
                        migrateSub.on('message', function(m) {
                            try {
                                var d = JSON.parse(m);
                                updateMigrateOverlay(d.step, d.progress, d.file);
                            } catch(e) {}
                        });
                        migrateSub.start();
                    } catch(e) {}
                }

                // Execute migration (pass both old and new paths — execute_migrate saves config AFTER copying)
                $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=execute_migrate&agent_storage_path=' + encodeURIComponent(newAgentPath) + '&home_storage_path=' + encodeURIComponent(newHomePath) + '&old_agent_path=' + encodeURIComponent(curAgentPath) + '&old_home_path=' + encodeURIComponent(curHomePath) + '&csrf_token=' + csrf, function(r) {
                    if (migrateSub) migrateSub.stop();
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
                    if (migrateSub) migrateSub.stop();
                    hideMigrateOverlay();
                    swal("Migration Failed", "Server communication error", "error");
                });
            });
        });
        return;
    }

    // Normal save (no path changes)
    let params = $(form).serialize();
    $.post('/plugins/unraid-aicliagents/AICliAjax.php?action=save&csrf_token=' + csrf, params, function() {
        if (!silent) safeReload();
        else swal({ title: "Saved", text: "Configuration updated.", type: "success", timer: 1000, showConfirmButton: false });
    });
}

function showMigrateOverlay() {
    $('#aicli-migrate-overlay').remove();
    $('body').append(
        '<div id="aicli-migrate-overlay" style="position:fixed; inset:0; z-index:3000000; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center;">' +
            '<div style="background:var(--background-color, #fff); border:1px solid var(--border-color, #ccc); border-radius:8px; padding:30px 40px; min-width:400px; max-width:600px; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.3);">' +
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

function openPathPicker(id) {
    const input = $('#' + id);
    let startPath = input.val() || '/mnt';
    if (startPath.length > 1 && startPath.endsWith('/')) startPath = startPath.slice(0, -1);

    // Remove any existing picker
    $('#aicli-path-picker-backdrop').remove();

    let selectedPath = null;
    let currentDir = startPath;
    let lastClick = { time: 0, path: '' };

    function browse(path) {
        currentDir = path;
        $('#pp-current-path').val(path);
        $('#pp-dir-list').html('<div style="padding:20px; text-align:center; opacity:0.5;"><i class="fa fa-spinner fa-spin"></i></div>');
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=list_dir&path=' + encodeURIComponent(path) + '&csrf_token=' + csrf, function(data) {
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

    // Build modal HTML
    const modal = $('<div id="aicli-path-picker-backdrop" class="pp-backdrop">' +
        '<div class="pp-modal">' +
            '<div class="pp-header"><span class="pp-title"><i class="fa fa-folder-open" style="color:var(--orange, #e68a00);"></i> Select Directory</span></div>' +
            '<div class="pp-body">' +
                '<div class="pp-path-bar"><i class="fa fa-hdd-o"></i>' +
                    '<input type="text" id="pp-current-path" value="' + $('<div>').text(startPath).html() + '" style="flex:1; background:transparent; border:none; color:inherit; font:inherit; font-family:monospace; font-size:12px; padding:2px 4px; outline:none; min-width:0;" spellcheck="false">' +
                '</div>' +
                '<div id="pp-dir-list" class="pp-dir-list"></div>' +
            '</div>' +
            '<div class="pp-footer">' +
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
    if (lastTab && ['config', 'store', 'storage', 'debug'].includes(lastTab)) {
        const btn = $(`.aicli-tab-btn[onclick*="'${lastTab}'"]`);
        if (btn.length) switchMainTab(lastTab, btn[0]);
    }
    const logBox = $('#log-content');
    if (logBox.length) {
        logBox.on('mouseenter', function() { pauseAutoscroll(true); }).on('mouseleave', function() { pauseAutoscroll(false); });
        logBox[0].addEventListener('wheel', function(e) { e.stopPropagation(); }, { passive: false });
    }
    refreshLog();
    refreshStats();
    resetStatsTimer();
    setInterval(refreshLog, 5000);
});
</script>
