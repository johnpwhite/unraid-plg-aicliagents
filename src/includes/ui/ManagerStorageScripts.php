<?php
/**
 * <module_context>
 * Description: JavaScript logic for storage management in AICliAgents Manager.
 * Dependencies: jQuery, SweetAlert, $csrf_token.
 * Constraints: Atomic UI fragment (< 100 lines).
 * </module_context>
 */
?>
<script>
// Top-level HTML escaper — shared by all top-level functions in this script
// (notably consolidateStorage's calm-card dialog). NOTE: a second escapeHtml is
// defined inside the consolidate-fail-banner IIFE below; that one is function-
// local and shadows this only within that IIFE. This top-level copy is what
// consolidateStorage resolves (the IIFE's is out of scope there).
function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function refreshStats() {
    // Show storage unavailable banner if needed
    if (window.aicli_storage_available === false) {
        if (!$('#aicli-storage-warning').length) {
            var cls = window.aicli_storage_classification || 'unknown';
            var msg = 'Storage path (' + (window.aicli_storage_path || '') + ') is currently unavailable.';
            if (cls === 'array') msg += ' The Unraid array is not started.';
            else if (cls.indexOf('pool:') === 0) msg += ' Pool "' + cls.substring(5) + '" is not available.';
            $('#tab-storage .aicli-cards').prepend('<div id="aicli-storage-warning" style="background:rgba(234,179,8,0.12); border:1px solid #eab308; border-radius:6px; padding:10px 16px; margin-bottom:12px; display:flex; align-items:center; gap:8px; font-size:12px; color:#eab308;"><i class="fa fa-exclamation-triangle"></i> ' + msg + ' Storage operations may be limited.</div>');
        }
    }
    // R-06: routed through aicliAjax (CommonLogging.php) so this high-traffic
    // poll carries an X-Aicli-Trace id — its server/shell log lines join up.
    aicliAjax('get_storage_status', {}, function(data) {
        if (data.migration_in_progress) {
            $('#migration-overlay').css('display', 'flex');
            if (data.migration_progress) {
                $('#migration-bar').css('width', data.migration_progress.percent + '%');
                $('#migration-status-text').text('Migrating legacy data: ' + data.migration_progress.done + ' / ' + data.migration_progress.total + ' images converted (' + data.migration_progress.percent + '%)');
            }
            if (statsInterval !== 2000) { statsInterval = 2000; resetStatsTimer(); }
            $('#agent-store-grid').html('<div style="grid-column: 1 / -1; padding: 40px; text-align: center; opacity: 0.7;"><i class="fa fa-database fa-spin" style="font-size: 30px; margin-bottom: 15px; color: #ff8c00;"></i><br>Agent Store is locked while storage migration is in progress...</div>');
            return;
        } else {
            $('#migration-overlay').hide();
            if (statsInterval !== 5000) { statsInterval = 5000; resetStatsTimer(); }
        }
        if (data.rootfs) {
            $('#rootfs-bar').css('width', data.rootfs.percent + '%');
            $('#rootfs-percent').text(data.rootfs.percent + '%');
            $('#rootfs-text').text(data.rootfs.used_mb + 'MB / ' + data.rootfs.total_mb + 'MB');
        }
        // WP #748 J / Phase B: per-agent cards removed from the Storage tab.
        // Agent storage state is now surfaced in the Store card foot (size only;
        // health remains in the boot-integrity banner). data.agents is still
        // consumed by av2RefreshStoreCardSizes() driven by the Store-tab poll.
        renderHomeStats(data.homes);
        renderCleanupCard(data.artifacts);
    });
}

function formatSize(bytes) {
    if (typeof bytes !== 'number' || bytes === 0) return '0 KB';
    if (bytes < 1048576) return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    return (bytes / 1048576).toFixed(2) + ' MB';
}

function renderLayerList(layers, dirtyMb) {
    if ((!layers || layers.length === 0) && (!dirtyMb || dirtyMb === 0)) return '';

    // WP #276: collapse the repeated persistence path into a single header line
    // and show only the filename in each Flash row. Flash layers share their
    // parent dir (the persistence path), so repeating it on every row wastes
    // horizontal space and forces the filenames to truncate. Take the dirname
    // of the first layer's full path as the common root.
    var flashRoot = '';
    if (layers && layers.length > 0 && layers[0].path) {
        var firstPath = layers[0].path;
        var lastSlash = firstPath.lastIndexOf('/');
        if (lastSlash > 0) flashRoot = firstPath.substring(0, lastSlash);
    }

    // Visual conventions for this list — colour-coded LEFT bar per layer tier:
    //   - 3px ORANGE bar  → in-memory (ZRAM, RAM-side)
    //   - 3px BLUE bar    → on-Flash (persisted SquashFS)
    //   - both bars on the left edge so the icon column aligns vertically.
    var ramBar    = 'border-left:3px solid var(--orange, #ff8c00);';
    var flashBar  = 'border-left:3px solid #1e4976;';
    var rowPadL   = 'padding-left:8px;';

    // Persistence-root header — rendered OUTSIDE the layer-list bordered box so
    // it sits next to the existing mount-point line and the bordered list below
    // contains only the actual rows. Uses the same .se-mount-label style as the
    // mount-point line above for visual continuity.
    var html = '';
    if (flashRoot) {
        html += '<div class="se-mount-label" style="opacity:0.65;" title="Common parent directory for the SquashFS layers below">' +
                    '<i class="fa fa-folder-open-o"></i> ' + flashRoot +
                '</div>';
    }

    // Indent the list so it visually tucks under the persistence path header
    // above (~20px is roughly the width of the folder icon + its trailing space).
    html += '<div class="se-layer-list" style="margin-left:20px;">';

    // ZRAM row (WP #267) — in-memory upper layer, orange left bar.
    if (typeof dirtyMb !== 'undefined' && dirtyMb !== null && dirtyMb > 0) {
        html += '<div class="se-layer-item se-layer-zram" style="' + ramBar + rowPadL + '">' +
                  '<i class="fa fa-bolt" style="color:var(--orange, #ff8c00);" title="In-memory ZRAM upper layer (unflushed)"></i>' +
                  '<span class="se-layer-path">ZRAM (in-memory upper layer)</span>' +
                  '<span class="se-layer-size">' + dirtyMb + ' MB</span>' +
                '</div>';
    }

    // Flash rows — basename only, blue right bar, matching left-padding so the
    // icon column lines up with the ZRAM row above.
    $.each(layers, function(i, l) {
        var icon = l.name.indexOf('delta') >= 0 ? 'fa-plus-square' : 'fa-database';
        html += '<div class="se-layer-item" style="' + rowPadL + flashBar + '" title="' + l.path + '">' +
                  '<i class="fa ' + icon + '"></i>' +
                  '<span class="se-layer-path">' + l.name + '</span>' +
                  '<span class="se-layer-size">' + formatSize(l.size_bytes) + '</span>' +
                '</div>';
    });
    html += '</div>';
    return html;
}

// renderAgentStats() removed in v2026.05.13.05 (WP #748 J / Phase B). Under
// single-layer-per-agent the per-agent storage cards were vestigial; agent
// storage size is now surfaced in the Store card foot (av2RefreshStoreCardSizes
// in ManagerStoreScripts.php), and repair/restore actions remain on the
// boot-integrity banner. The persist_agent / consolidate_storage / wipe_storage
// AJAX handlers stay registered (home flows still use them; advanced/admin
// paths can hit them directly) but lose their UI entry point on this tab.

function renderHomeStats(homes) {
    let html = '';
    let totalPhysical = 0;
    const users = Object.keys(homes || {});
    if (users.length === 0) {
        html = '<div class="storage-empty-state"><i class="fa fa-home" style="font-size:24px; display:block; margin-bottom:8px; opacity:0.3;"></i>No active home persistence</div>';
    } else {
        $.each(homes, function(u, h) {
            totalPhysical += h.physical_mb;
            const canConsolidate = h.layers >= 2;
            const cardClass = 'storage-entity-card' + (h.percent > 0 ? ' has-dirty' : '') + (!h.mounted ? ' offline' : '');
            html += '<div class="' + cardClass + '">' +
                '<div class="se-header">' +
                    '<div><div class="se-title"><i class="fa fa-home" style="color:var(--orange, #e68a00); margin-right:6px;"></i>' + u + '</div>' +
                    '<div class="se-meta">' + h.physical_mb + ' MB persisted &middot; ' + h.layers + ' Layer' + (h.layers !== 1 ? 's' : '') + '</div></div>' +
                    '<div style="display:flex; flex-direction:column; align-items:flex-end; gap:2px;">' +
                        '<div style="font-size:11px; font-weight:700; color:' + (!h.mounted ? '#888' : (h.percent > 0 ? 'var(--orange, #ff8c00)' : '#4caf50')) + ';">' + (!h.mounted ? 'OFFLINE' : (h.percent > 0 ? h.dirty_mb + ' MB Dirty' : 'Synced')) + '</div>' +
                        // WP #271 follow-up: pending-consolidation badge
                        (h.consolidate_pending ? '<div style="font-size:9px; font-weight:700; color:var(--orange, #ff8c00); letter-spacing:0.5px; text-transform:uppercase;" title="Auto-consolidation deferred — waiting for the home mount to go idle (no active terminals).">⧗ Awaiting idle</div>' : '') +
                    '</div>' +
                '</div>' +
                '<div class="se-body">' +
                    '<div class="stat-bar-wrap" style="height:12px; opacity:' + (h.mounted ? 1 : 0.3) + ';"><div class="stat-bar-base" style="width:' + (100 - h.percent) + '%;"></div><div class="stat-bar-dirty" style="width:' + h.percent + '%;"></div><div class="stat-bar-text">' + (h.mounted ? (h.percent > 0 ? h.percent + '% Uncommitted' : 'Synced') : 'OFFLINE') + '</div></div>' +
                    '<div class="se-mount-label"><i class="fa fa-hdd-o"></i> ' + h.mount_point + '</div>' +
                    renderLayerList(h.layer_files, h.dirty_mb) +
                    // Bug #1380: non-modal relocation offer — shown ONLY when this
                    // entity's data sits on a GENUINE USB flash drive AND a durable
                    // non-array non-flash target exists to move it to.
                    (h.can_graduate ?
                        '<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:8px; padding:6px 8px; background:rgba(76,175,80,0.08); border:1px solid rgba(76,175,80,0.35); border-radius:4px;">' +
                            '<span style="font-size:10px; line-height:1.4;"><i class="fa fa-hdd-o" style="color:#4caf50; margin-right:5px;"></i>This data is on a USB flash drive — move it to a durable disk (faster, no USB wear)</span>' +
                            '<button type="button" class="aicli-btn-slim" style="background:#4caf50; white-space:nowrap;" onclick="graduateStorage(\'home\', \'' + u + '\', ' + (h.physical_mb || 0) + '); return false;">Move off USB flash drive</button>' +
                        '</div>' : '') +
                '</div>' +
                '<div class="se-actions">' +
                    '<a href="#" class="stat-icon-btn" onclick="persistEntity(\'home\', \'' + u + '\'); return false;" title="Persist to storage"><i class="fa fa-save"></i></a>' +
                    '<a href="#" class="stat-icon-btn" ' + (canConsolidate ? '' : 'style="opacity:0.3; cursor:default;"') + ' onclick="' + (canConsolidate ? 'consolidateStorage(\'home\', \'' + u + '\')' : 'return false;') + '; return false;" title="' + (canConsolidate ? 'Consolidate Layers' : 'Requires 2+ layers') + '"><i class="fa fa-compress"></i></a>' +
                    '<a href="#" class="stat-icon-btn" onclick="repairStorage(\'home\', \'' + u + '\'); return false;" title="Repair Mount"><i class="fa fa-wrench"></i></a>' +
                    '<a href="#" class="stat-icon-btn" onclick="deleteHomeStorage(\'' + u + '\'); return false;" title="Delete home data (permanent)" style="color:#c0392b;"><i class="fa fa-trash-o"></i></a>' +
                '</div>' +
                '</div>';
        });
    }
    $('#home-stats-container').html(html);
    $('#homes-text-summary').text(totalPhysical.toFixed(2) + ' MB Total');
}

function renderCleanupCard(artifacts) {
    $('#cleanup-card-container').remove();
    if (!artifacts || artifacts.length === 0) return;

    let totalMb = 0;
    let fileListHtml = '';
    $.each(artifacts, function(i, art) {
        totalMb += parseFloat(art.size_mb) || 0;
        fileListHtml += '<div style="display:flex; justify-content:space-between; padding:4px 8px; border-bottom:1px solid var(--border-color, rgba(0,0,0,0.06)); font-family:monospace; font-size:10px;">' +
            '<span><i class="fa ' + (art.type === 'image' ? 'fa-file-archive-o' : 'fa-folder-o') + '" style="width:16px; color:var(--orange, #e68a00); opacity:0.6;"></i> ' + art.name + '</span>' +
            '<span style="opacity:0.6;">' + art.size_mb + ' MB</span></div>';
    });

    const card = '<div id="cleanup-card-container" style="margin-top:24px;">' +
        '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">' +
            '<span style="font-size:13px; font-weight:700;"><i class="fa fa-recycle" style="color:var(--orange, #e68a00); margin-right:6px;"></i>Legacy Migration Artifacts</span>' +
            '<span style="font-size:11px; opacity:0.6;">' + artifacts.length + ' item' + (artifacts.length !== 1 ? 's' : '') + ' &middot; ' + totalMb.toFixed(1) + ' MB</span>' +
        '</div>' +
        '<div class="storage-entity-grid">' +
            '<div class="storage-entity-card" style="border-bottom-color:var(--orange, #ff8c00); grid-column: 1 / -1;">' +
                '<div class="se-header">' +
                    '<div><div class="se-title"><i class="fa fa-archive" style="color:var(--orange, #e68a00); margin-right:6px;"></i>Migrated Files</div>' +
                    '<div class="se-meta">Legacy .img and folder backups from Btrfs-to-SquashFS migration</div></div>' +
                    '<div style="font-size:11px; font-weight:700; color:var(--orange, #ff8c00);">' + totalMb.toFixed(1) + ' MB</div>' +
                '</div>' +
                '<div class="se-body">' +
                    '<div style="max-height:120px; overflow-y:auto; border:1px solid var(--border-color, #333); border-radius:4px;">' + fileListHtml + '</div>' +
                    '<div style="font-size:10px; opacity:0.5; margin-top:4px;">These files are safe to remove once you have verified your agents and workspaces are functioning correctly.</div>' +
                '</div>' +
                '<div class="se-actions">' +
                    '<button type="button" class="aicli-btn-slim" onclick="purgeArtifacts()" style="background:var(--orange, #ff8c00);"><i class="fa fa-trash"></i> Purge All Artifacts</button>' +
                '</div>' +
            '</div>' +
        '</div>' +
    '</div>';

    $('#home-stats-container').after(card);
}

function persistEntity(type, id) {
    const title = type === 'agent' ? "Persist Agent Updates?" : "Persist Home Changes?";
    const text = type === 'agent' ? "Commit changes in RAM to storage for " + id + "." : "Commit current RAM session data to SquashFS layers for " + id + ".";
    
    swal({ title: title, text: text, type: "info", showCancelButton: true, confirmButtonText: "Persist Now", showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        const token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
        aicli_log_to_server("User requested manual " + type + " persistence for " + id, 2);
        
        // R-06: aicliAjax stamps the X-Aicli-Trace header — this is the canonical
        // AJAX→PHP→shell mutation path the trace id is designed to join.
        const req = (type === 'agent')
            ? aicliAjax('persist_agent', { id: id })
            : aicliAjax('persist_home', {});
        req.done(function(r) {
            if (r && r.status === 'ok') { 
                swal({ title: "Persisted", text: "Data persisted.", type: "success", timer: 2000, showConfirmButton: false });
                clearChanged();
                refreshStats(); 
            }
            else {
                const err = r.message || "Unknown Error. Check debug.log";
                aicli_log_to_server("Manual persistence FAILED: " + err, 0);
                swal("Persistence Failed", err, "error");
            }
        });
    });
}


function repairStorage(type, id) {
    swal({ title: "Repair " + type + " storage?", text: "Unmount and remount the OverlayFS stack for " + id + ". This may briefly interrupt active sessions.", type: "warning", showCancelButton: true, confirmButtonText: "Repair", showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        const action = (type === 'agent') ? 'repair_agent_storage' : 'repair_home_storage';
        aicliAjax(action, { id: id }, function(r) {
            if (r.status === 'ok') swal({ title: "Repaired", text: "Storage stack remounted.", type: "success", timer: 1500, showConfirmButton: false });
            else swal("Repair Failed", r.message, "error");
            refreshStats();
        });
    });
}

function consolidateStorage(type, id) {
    // Runs the consolidate AJAX + reports the result. No confirm of its own — the
    // caller is responsible for confirming first (a plain confirm for agents/empty
    // homes via doConsolidate, or the calm action-card for homes with open sessions).
    // Shared so neither path chains two swals (sweet-alert v1 swallows a new swal
    // opened from a closeOnConfirm:true callback — that was the "overlay disappears,
    // nothing happens" bug).

    // R2.3 — backend now returns {status:'queued', job_id, message} almost immediately.
    // On queued: close the dialog at once; the activity-tray pill is the progress source
    // of truth. A brief non-blocking toast confirms the hand-off. On any non-queued
    // response keep the error path.
    function runConsolidate() {
        aicliAjax('consolidate_storage', { type: type, id: id }, function(r) {
            if (r && r.status === 'queued') {
                // Hand off to the activity tray immediately — no long spin.
                swal({
                    title: 'Consolidating ' + id + '’s home',
                    text: r.message || 'Queued — watch the activity tray for progress.',
                    type: 'info',
                    timer: 2500,
                    showConfirmButton: false
                });
                clearChanged();
            } else if (r && r.status === 'ok') {
                // Non-home consolidate (agent) or legacy synchronous path — truthful.
                swal({ title: "Queued", text: r.message || 'Consolidation queued.', type: 'info', timer: 4000, showConfirmButton: false });
                clearChanged();
            } else {
                swal('Failed', (r && r.message) || 'Unknown error. Check debug.log.', 'error');
            }
            refreshStats();
        });
    }

    function doConsolidate() {
        swal({ title: 'Consolidate ' + type + ' layers?', text: 'Merge SquashFS deltas into a single base volume. This saves memory.', type: 'warning', showCancelButton: true, showLoaderOnConfirm: true, closeOnConfirm: false }, function(confirmed) {
            if (!confirmed) return;
            runConsolidate();
        });
    }

    if (type !== 'home') {
        doConsolidate();
        return;
    }

    // R1.2/R1.3 — For home consolidates: fetch open sessions first.
    // If sessions exist, show a calm action-card (NOT a destructive warning) because
    // the operation auto-resumes every session — it is safe and reversible.
    // If sessions is empty, fall back to the standard doConsolidate confirm.
    aicliAjax('get_home_sessions', { id: id }, function(r) {
        var sessions = (r && r.status === 'ok' && r.sessions) ? r.sessions : [];
        if (sessions.length === 0) {
            doConsolidate();
            return;
        }

        // Build agent-row grid. Each session: {id, agentId, name, icon, path, workspace}.
        // Fall back defensively: name → agentId → 'unknown'; icon → generic SVG data-uri.
        var FALLBACK_ICON = 'data:image/svg+xml,%3Csvg xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22 viewBox%3D%220 0 24 24%22 fill%3D%22none%22 stroke%3D%22%23888%22 stroke-width%3D%221.5%22%3E%3Crect x%3D%223%22 y%3D%223%22 width%3D%2218%22 height%3D%2218%22 rx%3D%223%22%2F%3E%3Ccircle cx%3D%2212%22 cy%3D%229%22 r%3D%222.5%22%2F%3E%3Cpath d%3D%22M7 19c0-2.8 2.2-5 5-5s5 2.2 5 5%22%2F%3E%3C%2Fsvg%3E';

        // Determine shared workspace path (shown once if all sessions share the same path).
        var paths = sessions.map(function(s) { return s.workspace || s.path || ''; });
        var firstPath = paths[0] || '';
        var sharedPath = firstPath && paths.every(function(p) { return p === firstPath; }) ? firstPath : '';

        var rowsHtml = '';
        for (var i = 0; i < sessions.length; i++) {
            var s = sessions[i];
            var displayName = s.name || s.agentId || 'unknown';
            var iconSrc = s.icon || FALLBACK_ICON;
            // XSS-safe icon src: only image data URIs, https, or root-relative
            // same-origin paths (registry icons). Anything else -> fallback.
            if (!/^(data:image\/|https:\/\/|\/[^\/])/.test(iconSrc)) { iconSrc = FALLBACK_ICON; }
            var rowPath = s.workspace || s.path;
            var pathHtml = (!sharedPath && rowPath)
                ? '<span style="display:block; font-size:10px; font-family:monospace; opacity:0.55; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px;">' + escapeHtml(rowPath) + '</span>'
                : '';
            rowsHtml +=
                '<div style="display:flex; align-items:center; gap:8px; padding:5px 6px; border-radius:4px; background:var(--mild-background-color,rgba(0,0,0,0.04));">' +
                    '<img src="' + escapeHtml(iconSrc) + '" alt="" style="width:22px; height:22px; border-radius:4px; flex-shrink:0; object-fit:contain; background:var(--title-header-background-color,#333);" onerror="this.src=\'' + FALLBACK_ICON + '\'">' +
                    '<div style="min-width:0; flex:1;">' +
                        '<span style="font-size:12px; font-weight:600; color:var(--text-color,#eee);">' + escapeHtml(displayName) + '</span>' +
                        pathHtml +
                    '</div>' +
                '</div>';
        }

        var sharedPathHtml = sharedPath
            ? '<div style="margin-top:8px; padding:5px 8px; border-radius:4px; background:var(--mild-background-color,rgba(0,0,0,0.04)); font-family:monospace; font-size:10px; color:var(--text-color,#ccc); opacity:0.75; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' +
                  '<svg style="width:12px;height:12px;vertical-align:-2px;margin-right:4px;" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 10l4-4 3 3 5-5"/><path d="M1 13h14"/></svg>' +
                  escapeHtml(sharedPath) +
              '</div>'
            : '';

        // Merge/consolidate SVG icon — two overlapping layers flowing into one. No emoji.
        var mergeIconSvg =
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none" ' +
                'style="width:52px;height:52px;display:block;margin:0 auto 8px;" ' +
                'aria-hidden="true">' +
                // back layer (lighter, offset up-right)
                '<rect x="14" y="6" width="26" height="18" rx="3" ' +
                    'stroke="var(--orange,#e68a00)" stroke-width="1.8" stroke-dasharray="3 2" opacity="0.45"/>' +
                // front layer (solid, offset down-left)
                '<rect x="8" y="14" width="26" height="18" rx="3" ' +
                    'stroke="var(--orange,#e68a00)" stroke-width="1.8" opacity="0.7"/>' +
                // merge arrow pointing down to unified layer
                '<path d="M24 32 L24 38" stroke="var(--orange,#e68a00)" stroke-width="2" stroke-linecap="round"/>' +
                '<path d="M20 35 L24 39 L28 35" stroke="var(--orange,#e68a00)" stroke-width="2" ' +
                    'stroke-linecap="round" stroke-linejoin="round"/>' +
                // unified bottom layer
                '<rect x="11" y="39" width="26" height="4" rx="2" ' +
                    'fill="var(--orange,#e68a00)" opacity="0.85"/>' +
            '</svg>';

        var cardHtml =
            '<div style="text-align:center; padding:4px 0 8px;">' +
                mergeIconSvg +
                '<div style="font-size:11px; line-height:1.5; color:var(--text-color,#ccc); opacity:0.85; margin-bottom:12px; padding:0 4px;">' +
                    'Frees memory by merging storage layers. Your sessions close briefly and reopen exactly where you left off.' +
                '</div>' +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:5px; text-align:left; margin-bottom:0;">' +
                    rowsHtml +
                '</div>' +
                sharedPathHtml +
            '</div>';

        // R1.2: calm swal — no "warning" type icon. html:true, closeOnConfirm:false keeps
        // the modal up while the brief AJAX round-trip completes (showLoaderOnConfirm).
        // Sweet-alert v1 note: do NOT open a new swal from a closeOnConfirm:true callback
        // — it gets swallowed. closeOnConfirm:false + runConsolidate's own swal replaces it.
        swal({
            title: 'Consolidate ' + id + '’s home',
            text: cardHtml,
            html: true,
            type: 'info',
            showCancelButton: true,
            confirmButtonText: 'Consolidate',
            cancelButtonText: 'Cancel',
            showLoaderOnConfirm: true,
            closeOnConfirm: false
        }, function(confirmed) {
            if (!confirmed) return;
            runConsolidate();
        });
    });
}

// Bug #1380: "Move off USB flash drive" — relocate an entity's data from a
// genuine USB-flash persist device to a durable non-array non-flash target the
// user picks. Lists the QUALIFYING targets (the same filtered list the offer
// gate uses), then drives the proven relocation (execute_migrate: verified
// per-file copy + config + manifest re-point under a crash-safe marker).
function graduateStorage(type, id, physicalMb) {
    const mb = parseFloat(physicalMb) || 0;
    function fmtBytes(b) {
        b = parseFloat(b) || 0;
        if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB free';
        if (b >= 1048576)    return (b / 1048576).toFixed(0) + ' MB free';
        return 'free space unknown';
    }
    // Step 1: fetch the qualifying durable targets for this kind.
    aicliAjax('graduate_targets', { type: type }, function(tr) {
        if (!tr || tr.status !== 'ok') {
            swal("Couldn't list targets", (tr && tr.message) || "Unknown error. Check debug.log.", "error");
            return;
        }
        var targets = tr.targets || [];
        if (targets.length === 0) {
            swal("No durable target available",
                 "There is no durable, non-array, non-flash location to move this data to. Add a pool or an Unassigned Device, then try again.",
                 "info");
            return;
        }
        // Step 2: build a radio picker of the qualifying targets.
        var opts = '';
        $.each(targets, function(i, t) {
            var checked = (i === 0) ? ' checked' : '';
            var sub = (t.label ? t.label : t.path) + ' — ' + fmtBytes(t.free_bytes);
            opts += '<label style="display:flex; align-items:flex-start; gap:8px; padding:6px 4px; cursor:pointer; text-align:left;">' +
                        '<input type="radio" name="aicli-grad-target" value="' + String(t.path).replace(/"/g, '&quot;') + '"' + checked + ' style="margin-top:3px;">' +
                        '<span style="font-size:12px; line-height:1.4;"><strong>' + sub + '</strong>' +
                        '<br><span style="font-family:monospace; font-size:10px; opacity:0.65;">' + t.path + '</span></span>' +
                    '</label>';
        });
        // Rough wall-clock estimate: decompress + verified copy ≈ 2 min/GB, min 2 min.
        var estMin = Math.max(2, Math.round((mb / 1024) * 2));
        var html =
            '<div style="text-align:left; font-size:12px; line-height:1.5;">' +
                '<p>This home\'s data is on a USB flash drive. Pick a durable disk to move it to — the layers are copied and verified before anything on the stick is touched, then the persistence path is switched.</p>' +
                '<div style="border:1px solid var(--border-color,#ddd); border-radius:4px; padding:4px 8px; margin:8px 0; max-height:180px; overflow-y:auto;">' + opts + '</div>' +
                '<p style="font-size:11px; opacity:0.7;">Estimated time: ~' + estMin + ' min. Close any terminals for this user first or the copy will wait for the mount to go idle.</p>' +
            '</div>';
        swal({
            title: "Move " + id + " off the USB flash drive",
            text: html,
            html: true,
            type: "info",
            showCancelButton: true,
            confirmButtonText: "Move data",
            showLoaderOnConfirm: true,
            closeOnConfirm: false
        }, function(confirmed) {
            if (confirmed === false) return;
            var chosen = $('input[name="aicli-grad-target"]:checked').val();
            if (!chosen) {
                swal.showInputError && swal.showInputError("Pick a target disk.");
                return false;
            }
            aicli_log_to_server("User requested move-off-USB for " + type + "/" + id + " → " + chosen, 2);
            aicliAjax('graduate_storage', { type: type, target: chosen }, function(r) {
                if (r && r.status === 'ok') {
                    swal({ title: "Move started", text: "The data is being copied and verified, then the path is switched. Watch progress on this tab.", type: "success", timer: 4000, showConfirmButton: false });
                } else {
                    swal("Move failed", (r && r.message) || "Unknown error. Check debug.log.", "error");
                }
                refreshStats();
            });
        });
    });
}

function wipeStorage(type, id) {
    swal({ title: "Wipe Storage: " + id + "?", text: "PERMANENTLY WIPE all storage for this " + type + ". This cannot be undone.", type: "error", showCancelButton: true, confirmButtonColor: "#f44336", confirmButtonText: "YES, WIPE IT", showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        aicliAjax('wipe_storage', { type: type, id: id }, function(r) {
            if (r.status === 'ok') {
                swal({ title: "Wiped", type: "success", timer: 1500 });
                clearChanged();
            }
            else swal("Failed", r.message, "error");
            refreshStats();
        });
    });
}
// Legacy alias for backwards compatibility
function nuclearRebuild(type, id) { wipeStorage(type, id); }

// Bug #1379: permanently delete a home entity and ALL its layers.
// "root" and "aicliagent" require typed confirmation (they are the primary homes).
// All other homes get a single-confirm swal.
function deleteHomeStorage(id) {
    var isRoot = (id === 'root' || id === 'aicliagent');

    if (isRoot) {
        // Extra-stern typed confirmation for the primary home.
        swal({
            title: 'Delete home data for \'' + id + '\'?',
            text: 'This is the primary user home — all stored layers, settings and session data for \'' + id + '\' will be permanently destroyed.\n\nType DELETE in the box below to confirm.',
            type: 'input',
            inputPlaceholder: 'Type DELETE to confirm',
            showCancelButton: true,
            closeOnConfirm: false,
            animation: 'slide-from-top',
            confirmButtonColor: '#c0392b',
            confirmButtonText: 'Delete permanently'
        }, function(inputValue) {
            if (inputValue === false) return;
            if (inputValue !== 'DELETE') {
                swal.showInputError('Type DELETE exactly (uppercase) to confirm — or Cancel to back out.');
                return false;
            }
            aicliAjax('delete_home_storage', { id: id, root_confirmed: '1' }, function(r) {
                if (r && r.status === 'ok') {
                    swal({ title: 'Deleted', text: r.message || 'Home storage deleted.', type: 'success', timer: 2500, showConfirmButton: false });
                    refreshStats();
                } else {
                    swal('Delete Failed', (r && r.message) || 'Unknown error. Check debug.log.', 'error');
                }
            });
        });
    } else {
        // Single-confirm for non-root homes.
        swal({
            title: 'Delete home data for \'' + id + '\'?',
            text: 'This permanently removes all stored layers and session data for \'' + id + '\'. This cannot be undone.',
            type: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#c0392b',
            confirmButtonText: 'Delete permanently',
            showLoaderOnConfirm: true,
            closeOnConfirm: false
        }, function() {
            aicliAjax('delete_home_storage', { id: id }, function(r) {
                if (r && r.status === 'ok') {
                    swal({ title: 'Deleted', text: r.message || 'Home storage deleted.', type: 'success', timer: 2500, showConfirmButton: false });
                    refreshStats();
                } else {
                    swal('Delete Failed', (r && r.message) || 'Unknown error. Check debug.log.', 'error');
                }
            });
        });
    }
}

// ---- Phase 0 + 4a: Boot Integrity Banner with Sibling-Restore ----
// Fetches the boot integrity status once when the storage tab is opened.
// For legacy_unmanaged / path_drift states, renders a recovery card with a
// Restore button. For other non-healthy states, renders the Phase 4a warn banner.
// After a successful restore the cache is invalidated and the banner re-fetches.
(function() {
    var _biLoaded = false;

    function fetchBootIntegrity() {
        if (_biLoaded) return;
        _biLoaded = true;
        var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
        $.getJSON(
            '/plugins/unraid-aicliagents/AICliAjax.php?action=get_boot_integrity_status&csrf_token=' + token,
            function(data) { renderBootIntegrityBanner(data); }
        ).fail(function() {
            // Non-fatal -- banner stays hidden
        });
    }

    function refetchBootIntegrity() {
        var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
        $.getJSON(
            '/plugins/unraid-aicliagents/AICliAjax.php?action=get_boot_integrity_status&csrf_token=' + token,
            function(data) { renderBootIntegrityBanner(data); }
        ).fail(function() {
            $('#aicli-boot-integrity-banner').hide();
        });
    }

    function renderBootIntegrityBanner(data) {
        var banner = $('#aicli-boot-integrity-banner');
        if (!data || data.status !== 'ok') { banner.hide(); return; }

        var anyCritical = data.any_critical;
        var anyWarning  = data.any_warning;
        if (!anyCritical && !anyWarning) { banner.hide(); return; }

        var attnCount = (data.summary && data.summary.needs_attention) || 0;
        var color = anyCritical ? '#c0392b' : '#e67e22';
        var icon  = anyCritical ? 'fa-exclamation-circle' : 'fa-exclamation-triangle';
        var label = anyCritical ? 'Critical' : 'Warning';

        // Recovery states get dedicated interactive cards; all others get a summary row.
        var recoveryStates = ['legacy_unmanaged', 'path_drift'];
        var recoveryCards  = '';
        var detailRows     = '';

        $.each(data.sweep || [], function(i, entry) {
            if (entry.state === 'healthy' || entry.state === 'genuine_fresh') return;
            var ev = entry.evidence || {};

            var entitySafe = entry.entity.replace(/[^a-zA-Z0-9/_-]/g, '');
            var typeSafe   = entitySafe.split('/')[0] || '';
            var idSafe     = entitySafe.split('/')[1] || '';

            // WP #748 J / Phase B follow-up (c): for agent entities, the banner
            // becomes navigational — surface a "Show on Agent Store" deep-link
            // that switches to the Store tab and scrolls to the affected card,
            // where the pill + Repair / Clear-halt buttons live. The inline
            // Restore button stays for home entities (no Store card for homes).
            if (typeSafe === 'agent') {
                var agentStateLabel = (entry.state || '').replace(/_/g, ' ');
                var sibLine = '';
                if (ev.siblings_count) {
                    sibLine = ' &nbsp;|&nbsp; <span style="opacity:0.85;">' + ev.siblings_count + ' sibling layer(s) available to restore</span>';
                }
                recoveryCards +=
                    '<div style="border:1px solid ' + color + '; border-radius:6px; padding:12px 16px; margin-top:8px;">' +
                        '<div style="display:flex; justify-content:space-between; align-items:center;">' +
                            '<div>' +
                                '<div style="font-size:12px; font-weight:700; font-family:monospace; margin-bottom:4px;">' + entry.entity + '</div>' +
                                '<div style="font-size:10px; text-transform:uppercase; letter-spacing:0.5px; color:' + color + ';">' + agentStateLabel + sibLine + '</div>' +
                            '</div>' +
                            '<button type="button" ' +
                                'class="aicli-btn-slim aicli-show-on-store-btn" ' +
                                'data-agent="' + idSafe + '" ' +
                                'style="background:' + color + '; white-space:nowrap; margin-left:16px;" ' +
                                'title="Switch to the Agent Store tab and scroll to this agent — Repair / Clear-halt actions live on the card.">' +
                                '<i class="fa fa-external-link"></i> Show on Agent Store →' +
                            '</button>' +
                        '</div>' +
                    '</div>';
                return;
            }

            // Home entities: existing two-mode rendering — recovery card with
            // inline Restore-from-sibling for legacy_unmanaged / path_drift,
            // text-only detail row for everything else.
            if (recoveryStates.indexOf(entry.state) !== -1) {
                // Build a recovery card for this entity.
                var sibCount = ev.siblings_count || 0;
                var sibPaths = ev.siblings_paths || [];

                // Derive a representative sibling directory from the first known path.
                var sibDir = '';
                if (sibPaths.length > 0) {
                    var sp = sibPaths[0];
                    var lastSlash = sp.lastIndexOf('/');
                    sibDir = (lastSlash > 0) ? sp.substring(0, lastSlash) : sp;
                }

                var stateLabel = entry.state === 'legacy_unmanaged'
                    ? 'Unmanaged layers found in sibling directory'
                    : 'Layer path drift detected';

                var detailText = sibCount + ' layer file' + (sibCount !== 1 ? 's' : '') +
                    (sibDir ? ' in <code style="font-size:10px;">' + sibDir + '</code>' : '');

                recoveryCards +=
                    '<div style="border:1px solid ' + color + '; border-radius:6px; padding:12px 16px; margin-top:8px;">' +
                        '<div style="display:flex; justify-content:space-between; align-items:flex-start;">' +
                            '<div>' +
                                '<div style="font-size:12px; font-weight:700; font-family:monospace; margin-bottom:4px;">' + entry.entity + '</div>' +
                                '<div style="font-size:10px; text-transform:uppercase; letter-spacing:0.5px; color:' + color + '; margin-bottom:6px;">' + stateLabel + '</div>' +
                                '<div style="font-size:11px; opacity:0.8;">' + detailText + '</div>' +
                            '</div>' +
                            '<button type="button" ' +
                                'class="aicli-btn-slim aicli-restore-btn" ' +
                                'data-type="' + typeSafe + '" ' +
                                'data-id="' + idSafe + '" ' +
                                'data-sibling-dir="' + (sibDir || '') + '" ' +
                                'style="background:' + color + '; white-space:nowrap; margin-left:16px;">' +
                                '<i class="fa fa-reply"></i> Restore from sibling' +
                            '</button>' +
                        '</div>' +
                    '</div>';
            } else {
                // Standard detail row (non-recoverable / other states)
                detailRows +=
                    '<div style="padding:6px 0; border-bottom:1px solid rgba(255,255,255,0.08);">' +
                        '<span style="font-weight:700; font-family:monospace;">' + entry.entity + '</span>' +
                        ' &mdash; <span style="text-transform:uppercase; font-size:10px; letter-spacing:0.5px;">' + entry.state + '</span>' +
                        '<div style="font-size:10px; opacity:0.75; margin-top:2px;">' +
                            'Expected: ' + (ev.expected_count || 0) + ' layer(s) &nbsp;|&nbsp; ' +
                            'Active: ' + (ev.active_count || 0) + ' layer(s)' +
                            (ev.siblings_count ? ' &nbsp;|&nbsp; Siblings: ' + ev.siblings_count : '') +
                            (ev.entity_persist_path ? '<br><span style="font-family:monospace;opacity:0.6;">' + ev.entity_persist_path + '</span>' : '') +
                        '</div>' +
                    '</div>';
            }
        });

        var summarySection = '';
        if (detailRows) {
            summarySection =
                '<details style="margin-top:8px;">' +
                    '<summary style="cursor:pointer; font-size:11px; opacity:0.8; list-style:none;">Other states (' + label + ')</summary>' +
                    '<div style="margin-top:8px;">' + detailRows + '</div>' +
                '</details>';
        }

        var html =
            '<div style="background:rgba(' + (anyCritical ? '192,57,43' : '230,126,34') + ',0.12);' +
                    'border:1px solid ' + color + '; border-radius:6px; padding:10px 16px;">' +
                '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">' +
                    '<span style="font-size:13px; font-weight:700; color:' + color + ';">' +
                        '<i class="fa ' + icon + '" style="margin-right:6px;"></i>' +
                        label + ': Boot integrity &mdash; ' + attnCount + ' entit' + (attnCount === 1 ? 'y needs' : 'ies need') + ' attention' +
                    '</span>' +
                    '<span style="font-size:10px; opacity:0.6;" id="aicli-bi-mode-label">warn mode &mdash; mounts not blocked</span>' +
                '</div>' +
                recoveryCards +
                summarySection +
            '</div>';

        banner.html(html).show();
    }

    // WP #748 J / Phase B follow-up (c): "Show on Agent Store" deep-link.
    // Switches to the Store tab and scrolls/flashes the affected card. The
    // pill + Repair / Clear-halt buttons live there; this is purely navigational.
    $('#aicli-boot-integrity-banner').on('click', '.aicli-show-on-store-btn', function() {
        var agentId  = $(this).data('agent');
        var storeBtn = document.querySelector('.aicli-tab-btn[onclick*="\'store\'"]');
        if (storeBtn) storeBtn.click();
        setTimeout(function() {
            var card = document.querySelector('.av2-card[data-agent="' + agentId + '"]');
            if (!card) return;
            card.scrollIntoView({behavior: 'smooth', block: 'center'});
            var prevTransition = card.style.transition;
            var prevShadow     = card.style.boxShadow;
            card.style.transition = 'box-shadow 0.4s';
            card.style.boxShadow  = '0 0 0 3px #e67e22, 0 0 24px rgba(230,126,34,0.55)';
            setTimeout(function() {
                card.style.boxShadow  = prevShadow;
                setTimeout(function() { card.style.transition = prevTransition; }, 450);
            }, 2200);
        }, 220);
    });

    // Single delegated click handler for all Restore buttons in the banner.
    // Never opens a modal from within another modal callback (no nested swal).
    $('#aicli-boot-integrity-banner').on('click', '.aicli-restore-btn', function() {
        var btn       = $(this);
        var type      = btn.data('type');
        var id        = btn.data('id');
        var sibDir    = btn.data('sibling-dir') || 'sibling directory';
        var entity    = type + '/' + id;
        var token     = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');

        swal({
            title: 'Restore ' + entity + '?',
            text: 'Move layer files from ' + sibDir + ' into the active persist path and register them in the manifest. The entity will classify as healthy on next boot.',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Restore',
            showLoaderOnConfirm: true,
            closeOnConfirm: false
        }, function() {
            $.ajax({
                url: '/plugins/unraid-aicliagents/AICliAjax.php',
                type: 'GET',
                data: { action: 'restore_from_sibling', type: type, id: id, csrf_token: token },
                dataType: 'json'
            }).done(function(r) {
                if (r && r.status === 'ok') {
                    swal({
                        title: 'Restored',
                        text: r.message || 'Layers restored successfully. Mount normally on next boot.',
                        type: 'success',
                        timer: 3000,
                        showConfirmButton: false
                    });
                    // Broadcast so any other open tab can refresh
                    if (window.localStorage) {
                        localStorage.setItem('aicli_restore_complete', entity + ':' + Date.now());
                    }
                    // Refresh the banner after a short delay
                    setTimeout(function() { refetchBootIntegrity(); }, 800);
                } else {
                    var errMsg = (r && r.message) ? r.message : 'Restore failed. Check lifecycle log for details.';
                    swal('Restore Failed', errMsg, 'error');
                }
            }).fail(function() {
                swal('Restore Failed', 'AJAX request failed. Check debug.log.', 'error');
            });
        });
    });

    // Listen for restore-complete events from other tabs
    if (window.localStorage) {
        $(window).on('storage', function(e) {
            if (e.originalEvent && e.originalEvent.key === 'aicli_restore_complete') {
                refetchBootIntegrity();
            }
        });
    }

    // Trigger fetch when the storage tab becomes visible
    $(document).on('click', '[data-tab="storage"], .aicli-nav-item[href*="storage"]', function() {
        setTimeout(fetchBootIntegrity, 300);
    });
    // Also fetch if storage tab is already active on page load
    if ($('#tab-storage').hasClass('active') || $('#tab-storage').is(':visible')) {
        setTimeout(fetchBootIntegrity, 800);
    }
})();

// ---- WP #922: Recent-consolidate-failure indicator ----
// Non-blocking, theme-friendly banner on the Storage tab. Shows when the
// supervisor has a non-zero consolidate-failure counter for any entity, OR
// when there are recent snapshot files on Flash. Clears automatically the
// moment the supervisor's next successful consolidate resets the counter
// (or when a busy-mount defer resets it).
//
// Sits ABOVE the boot-integrity banner — different signal (warning of an
// in-flight problem the supervisor is still retrying) vs the existing banner
// (manifest-state needs user attention).
(function() {
    var BANNER_ID = 'aicli-consolidate-fail-banner';
    var _loaded = false;

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function fetchConsolidateFails() {
        if (_loaded) return;
        _loaded = true;
        var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
        $.getJSON(
            '/plugins/unraid-aicliagents/AICliAjax.php?action=get_supervisor_status&csrf_token=' + token,
            function(data) { renderConsolidateFailsBanner(data); }
        ).fail(function() {
            // Non-fatal — banner stays hidden
        });
    }

    function renderConsolidateFailsBanner(data) {
        var existing = $('#' + BANNER_ID);
        if (!data || !data.consolidate_fails) { existing.remove(); return; }
        var f = data.consolidate_fails;
        var counts = f.counts || {};
        var entityKeys = Object.keys(counts).filter(function(k) { return (counts[k] | 0) > 0; });
        var snapTotal = (f.total_snapshots | 0);

        // Nothing to surface
        if (entityKeys.length === 0 && snapTotal === 0) { existing.remove(); return; }

        var rows = '';
        $.each(entityKeys, function(_, k) {
            rows += '<li><code>' + escapeHtml(k) + '</code> &mdash; ' + counts[k] +
                ' consecutive failure' + (counts[k] === 1 ? '' : 's') +
                ' (auto-halt triggers at 2).</li>';
        });

        var snapLines = '';
        if (snapTotal > 0) {
            var sample = (f.recent_snapshots || []).map(function(s) {
                return '<code>' + escapeHtml(s) + '</code>';
            }).join(', ');
            snapLines = '<div style="font-size:11px;opacity:.75;margin-top:6px;">' +
                snapTotal + ' failure snapshot' + (snapTotal === 1 ? '' : 's') +
                ' on Flash at <code>/boot/config/plugins/unraid-aicliagents/failures/</code>' +
                (sample ? '. Most recent: ' + sample : '') + '.</div>';
        }

        var html = '<div id="' + BANNER_ID + '" class="unapi" style="' +
            'margin:12px 0;padding:12px 16px;border-radius:6px;' +
            'background:var(--mild-background-color,#fff7e0);' +
            'border:1px solid var(--orange,#e68a00);' +
            'color:var(--text-color,#222);font-size:12px;">' +
            '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">' +
                '<i class="fa fa-info-circle" style="color:var(--orange,#e68a00);"></i>' +
                '<strong style="color:var(--orange,#e68a00);">Recent consolidate failure</strong>' +
            '</div>' +
            (rows ? '<ul style="margin:4px 0 0 18px;padding:0;">' + rows + '</ul>' : '') +
            snapLines +
            '<div style="font-size:10px;opacity:.6;margin-top:6px;">' +
                'The supervisor will retry automatically. This indicator clears as soon as ' +
                'the next successful consolidate runs (or a defer clears the counter).' +
            '</div>' +
        '</div>';

        if (existing.length) {
            existing.replaceWith(html);
        } else {
            var $tab = $('#tab-storage');
            if ($tab.length) $tab.prepend(html);
        }
    }

    // Trigger fetch on storage-tab activation + on initial load if active.
    $(document).on('click', '[data-tab="storage"], .aicli-nav-item[href*="storage"]', function() {
        _loaded = false;
        setTimeout(fetchConsolidateFails, 400);
    });
    if ($('#tab-storage').hasClass('active') || $('#tab-storage').is(':visible')) {
        setTimeout(fetchConsolidateFails, 900);
    }
})();

// ---- Phase 4b: Storage Unavailable halt overlay ----
// Fetches list_halts once on page load. If any halts exist, renders a
// fixed-position overlay (z-index:10002) blocking the page until each halt
// is resolved. Cross-tab: localStorage event 'aicli_halt_cleared' dismisses
// the overlay in other tabs without polling.
(function() {
    var OVERLAY_ID    = 'aicli-halt-overlay';
    var AJAX_BASE     = '/plugins/unraid-aicliagents/AICliAjax.php';

    function getToken() {
        return typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    }

    // Plain-language descriptions per state
    var STATE_LABELS = {
        legacy_unmanaged : 'Unmanaged layers found in a sibling directory',
        path_drift       : 'Layer path has drifted from the recorded manifest path',
        partial_loss     : 'Some expected layers are missing from the active path',
        total_loss       : 'All expected layers are missing — drive may be disconnected',
        corrupt_layers   : 'Layer integrity check failed — sha256 mismatch detected',
        host_mismatch    : 'Manifest was written on a different host (USB may have moved)',
    };

    function stateLabel(state) {
        return STATE_LABELS[state] || state;
    }

    // localStorage-backed "dismiss until" so the overlay doesn't re-block on
    // every refresh for a halt the user has decided to deal with later.
    var DISMISSED_KEY  = 'aicli_halt_dismissed_until';
    var DISMISS_HOURS  = 24;
    function _readDismissed() {
        if (!window.localStorage) return {};
        try { return JSON.parse(localStorage.getItem(DISMISSED_KEY) || '{}') || {}; }
        catch (e) { return {}; }
    }
    function isDismissed(entity) {
        var d = _readDismissed();
        var until = d[entity];
        return typeof until === 'number' && until > Date.now();
    }
    function dismissFor(entity, hours) {
        if (!window.localStorage) return;
        var d = _readDismissed();
        d[entity] = Date.now() + (hours || DISMISS_HOURS) * 3600 * 1000;
        try { localStorage.setItem(DISMISSED_KEY, JSON.stringify(d)); } catch (e) {}
    }

    // Build the action buttons for a single halt record (WP #916: theme-friendly,
    // Dismiss + Retry always available, no agent/total_loss destructive prompt —
    // that case is auto-healed before the overlay even renders).
    function buildActionButtons(halt) {
        var type   = halt.type;
        var id     = halt.id;
        var state  = halt.state;
        var action = halt.recommended_action || '';
        var btns   = '';
        var BTN    = 'aicli-btn-slim';                // base class — Unraid-themed via CSS below

        if (action === 'restore_from_sibling' || state === 'legacy_unmanaged' || state === 'path_drift') {
            var sibDir = '';
            var paths  = (halt.details && halt.details.sibling_dirs) ? halt.details.sibling_dirs : [];
            if (!paths.length && halt.details && halt.details.siblings_paths) paths = halt.details.siblings_paths;
            if (paths.length) {
                var p = paths[0];
                var sl = p.lastIndexOf('/');
                sibDir = (sl > 0) ? p.substring(0, sl) : p;
            }
            btns += '<button type="button" class="' + BTN + ' aicli-halt-btn-primary aicli-halt-restore" ' +
                'data-type="' + type + '" data-id="' + id + '" data-sibling-dir="' + (sibDir || 'sibling directory') + '">' +
                '<i class="fa fa-reply"></i> Restore from ' + (sibDir ? '<code>' + sibDir + '</code>' : 'sibling') +
                '</button> ';
        }
        // WP #916: agent/total_loss never reaches buildActionButtons — it's
        // auto-healed in checkAndRenderOverlay. Only home/total_loss (real
        // user data) shows the destructive button.
        if ((action === 'use_emergency_mode' || state === 'total_loss' || state === 'partial_loss') && type === 'home') {
            btns += '<button type="button" class="' + BTN + ' aicli-halt-btn-danger aicli-halt-abandon" ' +
                'data-type="' + type + '" data-id="' + id + '">' +
                '<i class="fa fa-exclamation-triangle"></i> Start fresh and abandon data' +
                '</button> ';
        }
        if (state === 'host_mismatch') {
            btns += '<button type="button" class="' + BTN + ' aicli-halt-btn-confirm aicli-halt-confirm-host" ' +
                'data-type="' + type + '" data-id="' + id + '">' +
                '<i class="fa fa-check"></i> Confirm: this is the correct machine' +
                '</button> ';
        }
        if (action === 'configure_path' || (state === 'path_drift' && action === 'configure_path')) {
            btns += '<a href="#tab-config" class="' + BTN + ' aicli-halt-btn-info">' +
                '<i class="fa fa-cog"></i> Open Settings</a> ';
        }
        if (action === 'review_manifest' && state !== 'host_mismatch') {
            btns += '<button type="button" class="' + BTN + ' aicli-halt-btn-neutral aicli-halt-override" ' +
                'data-type="' + type + '" data-id="' + id + '">' +
                '<i class="fa fa-unlock"></i> Override and start fresh' +
                '</button> ';
        }
        // WP #916: always-available non-destructive options. Retry re-runs
        // the boot sweep (good if user fixed the underlying issue outside
        // the UI, e.g. plugged a disk back in). Dismiss hides the overlay
        // for 24h on this device.
        btns += '<button type="button" class="' + BTN + ' aicli-halt-btn-neutral aicli-halt-retry" ' +
            'data-type="' + type + '" data-id="' + id + '">' +
            '<i class="fa fa-refresh"></i> Retry integrity check' +
            '</button> ';
        btns += '<button type="button" class="' + BTN + ' aicli-halt-btn-neutral aicli-halt-dismiss" ' +
            'data-type="' + type + '" data-id="' + id + '">' +
            '<i class="fa fa-clock-o"></i> Dismiss for ' + DISMISS_HOURS + 'h' +
            '</button>';
        return btns;
    }

    // WP #916: theme-friendly stylesheet for the overlay. Loads once on first
    // overlay render. Uses Unraid theme CSS variables so the overlay matches
    // light/dark/auto themes instead of forcing a dark+orange palette. All
    // selectors are .aicli-halt-* so they don't leak; the inner .unapi class
    // on the root prevents Unraid's global button styling from bleeding into
    // our buttons on Unraid 7.3+ (7.2 falls back to the !important rules).
    var STYLE_ID = 'aicli-halt-overlay-styles';
    function injectStylesOnce() {
        if (document.getElementById(STYLE_ID)) return;
        var st = document.createElement('style');
        st.id = STYLE_ID;
        st.textContent =
            '#' + OVERLAY_ID + '{position:fixed;inset:0;z-index:10002;background:rgba(0,0,0,.55);backdrop-filter:blur(3px);display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding:32px 24px;box-sizing:border-box;overflow-y:auto;}' +
            '#' + OVERLAY_ID + ' .aicli-halt-header{max-width:720px;width:100%;text-align:center;margin-bottom:20px;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.6);}' +
            '#' + OVERLAY_ID + ' .aicli-halt-title{font-size:22px;font-weight:700;margin-bottom:8px;color:var(--orange,#e68a00);}' +
            '#' + OVERLAY_ID + ' .aicli-halt-subtitle{font-size:13px;opacity:.85;margin-bottom:4px;}' +
            '#' + OVERLAY_ID + ' .aicli-halt-note{font-size:11px;opacity:.7;}' +
            '#' + OVERLAY_ID + ' .aicli-halt-cards{width:100%;display:flex;flex-direction:column;align-items:center;gap:14px;}' +
            '#' + OVERLAY_ID + ' .aicli-halt-card{background:var(--background-color,#fff);color:var(--text-color,#222);border:1px solid var(--border-color,#ddd);border-radius:8px;padding:16px 20px;max-width:680px;width:100%;box-shadow:0 16px 48px rgba(0,0,0,.25);}' +
            '#' + OVERLAY_ID + ' .aicli-halt-card-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;}' +
            '#' + OVERLAY_ID + ' .aicli-halt-entity{font-size:13px;font-weight:700;font-family:monospace;color:var(--orange,#e68a00);margin-bottom:4px;}' +
            '#' + OVERLAY_ID + ' .aicli-halt-state{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--alt-text-color,#888);margin-bottom:6px;}' +
            '#' + OVERLAY_ID + ' .aicli-halt-when{font-size:10px;opacity:.55;}' +
            '#' + OVERLAY_ID + ' .aicli-halt-badge{font-size:10px;background:var(--orange,#e68a00);color:#fff;padding:3px 8px;border-radius:4px;font-weight:700;white-space:nowrap;margin-left:12px;}' +
            '#' + OVERLAY_ID + ' .aicli-halt-badge.healing{background:var(--alt-text-color,#888);}' +
            '#' + OVERLAY_ID + ' .aicli-halt-buttons{display:flex;flex-wrap:wrap;gap:8px;}' +
            '#' + OVERLAY_ID + ' .aicli-btn-slim{all:unset !important;display:inline-flex !important;align-items:center !important;gap:6px !important;padding:6px 12px !important;font-size:12px !important;font-weight:600 !important;border-radius:4px !important;cursor:pointer !important;border:1px solid var(--border-color,#ddd) !important;background:var(--mild-background-color,#f7f9f9) !important;color:var(--text-color,#222) !important;text-decoration:none !important;}' +
            '#' + OVERLAY_ID + ' .aicli-btn-slim:hover{background:var(--border-color,rgba(0,0,0,.08)) !important;}' +
            '#' + OVERLAY_ID + ' .aicli-btn-slim.aicli-halt-btn-primary{background:var(--orange,#e68a00) !important;color:#fff !important;border-color:var(--orange,#e68a00) !important;}' +
            '#' + OVERLAY_ID + ' .aicli-btn-slim.aicli-halt-btn-confirm{background:#2ecc71 !important;color:#fff !important;border-color:#27ae60 !important;}' +
            '#' + OVERLAY_ID + ' .aicli-btn-slim.aicli-halt-btn-info{background:#3498db !important;color:#fff !important;border-color:#2980b9 !important;}' +
            '#' + OVERLAY_ID + ' .aicli-btn-slim.aicli-halt-btn-danger{background:#c0392b !important;color:#fff !important;border-color:#a93226 !important;}' +
            '#' + OVERLAY_ID + ' .aicli-btn-slim.aicli-halt-btn-neutral{background:transparent !important;color:var(--text-color,#222) !important;}' +
            '#' + OVERLAY_ID + ' .aicli-heal-spinner{display:inline-block;width:14px;height:14px;border:2px solid var(--alt-text-color,#888);border-top-color:transparent;border-radius:50%;animation:aicli-halt-spin 0.9s linear infinite;margin-right:8px;vertical-align:middle;}' +
            '@keyframes aicli-halt-spin{to{transform:rotate(360deg)}}';
        document.head.appendChild(st);
    }

    function buildOverlayHtml(halts) {
        var cards = '';
        $.each(halts, function(i, halt) {
            cards +=
                '<div class="aicli-halt-card" data-entity="' + halt.type + '/' + halt.id + '">' +
                    '<div class="aicli-halt-card-head">' +
                        '<div>' +
                            '<div class="aicli-halt-entity">' + halt.type + '/' + halt.id + '</div>' +
                            '<div class="aicli-halt-state">' + stateLabel(halt.state) + '</div>' +
                            (halt.halted_at ? '<div class="aicli-halt-when">Halted at: ' + halt.halted_at + '</div>' : '') +
                        '</div>' +
                        '<div class="aicli-halt-badge">HALTED</div>' +
                    '</div>' +
                    '<div class="aicli-halt-buttons">' + buildActionButtons(halt) + '</div>' +
                '</div>';
        });

        return '<div id="' + OVERLAY_ID + '" class="unapi">' +
            '<div class="aicli-halt-header">' +
                '<div class="aicli-halt-title"><i class="fa fa-exclamation-circle" style="margin-right:8px;"></i>Storage Attention Needed</div>' +
                '<div class="aicli-halt-subtitle">Boot integrity check found something worth your attention. The plugin won\'t overwrite your data without explicit consent.</div>' +
                '<div class="aicli-halt-note">Tip: you can Dismiss to deal with this later, or click Retry after fixing the underlying issue.</div>' +
            '</div>' +
            '<div class="aicli-halt-cards">' + cards + '</div>' +
        '</div>';
    }

    // WP #916: build an inline "auto-healing" card for an agent halt that's
    // being silently reinstalled. Shown briefly via toast OR inline if the
    // overlay is otherwise visible. Replaced with success/failure state when
    // the auto_heal_agent_install AJAX returns.
    function buildHealingCardHtml(halt) {
        return '<div class="aicli-halt-card" data-entity="' + halt.type + '/' + halt.id + '" data-healing="1">' +
            '<div class="aicli-halt-card-head">' +
                '<div>' +
                    '<div class="aicli-halt-entity">' + halt.type + '/' + halt.id + '</div>' +
                    '<div class="aicli-halt-state"><span class="aicli-heal-spinner"></span>Reinstalling agent…</div>' +
                '</div>' +
                '<div class="aicli-halt-badge healing">HEALING</div>' +
            '</div>' +
            '<div style="font-size:11px;opacity:.75;">Agent storage is npm-managed binaries; reinstalling restores them without any data loss.</div>' +
        '</div>';
    }

    function dismissOverlayIfEmpty() {
        var remaining = $('#aicli-halt-cards > div').length;
        if (remaining === 0) {
            $('#' + OVERLAY_ID).remove();
            // Broadcast to other tabs
            if (window.localStorage) {
                localStorage.setItem('aicli_halt_cleared', Date.now().toString());
            }
        }
    }

    function doRestoreFromSibling(type, id, sibDir) {
        // Render feedback immediately before the roundtrip
        swal({
            title: 'Restoring ' + type + '/' + id + '...',
            text: 'Moving layers from ' + sibDir + ' into the active persist path. This may take a moment.',
            type: 'info',
            showConfirmButton: false
        });
        var token = getToken();
        $.ajax({
            url: AJAX_BASE,
            type: 'GET',
            data: { action: 'restore_from_sibling', type: type, id: id, csrf_token: token },
            dataType: 'json'
        }).done(function(r) {
            if (r && r.status === 'ok') {
                // Clear the halt
                $.ajax({
                    url: AJAX_BASE,
                    type: 'GET',
                    data: { action: 'clear_halt', type: type, id: id, reason: 'restore_from_sibling', csrf_token: token },
                    dataType: 'json'
                }).always(function() {
                    swal({
                        title: 'Restored',
                        text: (r.message || 'Layers restored.') + ' The plugin can now mount normally.',
                        type: 'success',
                        showConfirmButton: true,
                        confirmButtonText: 'Continue'
                    }, function() {
                        // Remove the card for this entity
                        $('#aicli-halt-cards > div[data-entity="' + type + '/' + id + '"]').remove();
                        dismissOverlayIfEmpty();
                    });
                });
            } else {
                swal('Restore Failed', (r && r.message) || 'Restore failed. Check the lifecycle log for details.', 'error');
            }
        }).fail(function() {
            swal('Restore Failed', 'AJAX request failed. Check debug.log.', 'error');
        });
    }

    function doAbandon(type, id) {
        // WP #916: real input field — SweetAlert v1's `type: 'input'` mode.
        // Previously the dialog asked the user to "Type WIPE to confirm" but
        // rendered no input box — clicking CONFIRM proceeded regardless,
        // clicking CANCEL looped back to the same halt overlay with no
        // escape. Now you must literally type WIPE; CANCEL returns to the
        // overlay where Dismiss and Retry are also offered.
        swal({
            title: 'Wipe ' + type + '/' + id + ' and start fresh?',
            text: 'This permanently discards all existing layers for this entity (user data lives here — this IS a destructive action).\n\nType WIPE in the box below to confirm.',
            type: 'input',
            inputPlaceholder: 'Type WIPE to confirm',
            showCancelButton: true,
            closeOnConfirm: false,
            animation: 'slide-from-top',
            confirmButtonColor: '#c0392b',
            confirmButtonText: 'Wipe and start fresh',
            cancelButtonText: 'Cancel'
        }, function(inputValue) {
            if (inputValue === false) return;          // Cancel: just close, halt overlay still visible
            if (inputValue !== 'WIPE') {
                swal.showInputError('Type WIPE exactly (uppercase) to confirm — or Cancel to back out.');
                return false;
            }
            var token = getToken();
            $.ajax({
                url: AJAX_BASE,
                type: 'GET',
                data: { action: 'clear_halt', type: type, id: id, reason: 'user_abandon_start_fresh', csrf_token: token },
                dataType: 'json'
            }).always(function() {
                swal({ title: 'Cleared', text: 'The halt has been cleared. The plugin will mount a fresh empty stack on next boot.', type: 'success', timer: 3000, showConfirmButton: false });
                $('#aicli-halt-cards > div[data-entity="' + type + '/' + id + '"]').remove();
                dismissOverlayIfEmpty();
            });
        });
    }

    // WP #916: silently auto-heal an agent/total_loss halt by re-running the
    // npm install for that agent. Agent storage is pure code from npm — there
    // is nothing to lose. Called from checkAndRenderOverlay before the
    // overlay is shown.
    function doAutoHealAgent(halt, opts) {
        var type = halt.type, id = halt.id;
        var token = getToken();
        var quiet = !!(opts && opts.quiet);
        $.ajax({
            url: AJAX_BASE,
            type: 'GET',
            data: { action: 'auto_heal_agent_install', type: type, id: id, csrf_token: token },
            dataType: 'json',
            timeout: 180000
        }).done(function(r) {
            if (r && r.status === 'ok') {
                if (!quiet) {
                    swal({ title: 'Agent restored', text: r.message || ('Reinstalled ' + id + '.'), type: 'success', timer: 3500, showConfirmButton: false });
                }
                // Remove the healing card if present
                $('#aicli-halt-cards .aicli-halt-card[data-entity="' + type + '/' + id + '"]').remove();
                dismissOverlayIfEmpty();
            } else {
                // Heal failed — surface a non-blocking error and fall back to
                // the destructive option by re-rendering the overlay so the
                // user sees the standard halt card.
                swal({ title: 'Auto-heal failed', text: (r && r.message) || 'Could not reinstall the agent automatically.', type: 'error', confirmButtonText: 'OK' });
            }
        }).fail(function() {
            swal({ title: 'Auto-heal failed', text: 'AJAX request failed — check debug.log.', type: 'error', confirmButtonText: 'OK' });
        });
    }

    // WP #916: re-run the boot-integrity sweep and re-evaluate halts. Useful
    // when the user fixed the underlying issue outside the UI (plugged a
    // disk back in, restored a backup, etc.) and wants the overlay to clear
    // without a page reload.
    function doRetry(type, id) {
        var token = getToken();
        var $card = $('#aicli-halt-cards .aicli-halt-card[data-entity="' + type + '/' + id + '"]');
        $card.css('opacity', '0.5');
        $.ajax({
            url: AJAX_BASE,
            type: 'GET',
            data: { action: 'get_boot_integrity_status', csrf_token: token, _t: Date.now() },
            dataType: 'json',
            timeout: 30000
        }).always(function() {
            // Re-fetch halts and re-render
            $.ajax({
                url: AJAX_BASE,
                type: 'GET',
                data: { action: 'list_halts', csrf_token: token, _t: Date.now() },
                dataType: 'json'
            }).done(function(data) {
                var halts = (data && data.halts) || [];
                var stillHalted = false;
                $.each(halts, function(i, h) {
                    if (h.type === type && h.id === id) { stillHalted = true; return false; }
                });
                if (!stillHalted) {
                    $card.remove();
                    swal({ title: 'Cleared', text: type + '/' + id + ' is no longer halted.', type: 'success', timer: 2500, showConfirmButton: false });
                    dismissOverlayIfEmpty();
                } else {
                    $card.css('opacity', '1');
                    swal({ title: 'Still halted', text: 'The integrity check still flags ' + type + '/' + id + '.', type: 'info', timer: 3000, showConfirmButton: false });
                }
            }).fail(function() {
                $card.css('opacity', '1');
            });
        });
    }

    // WP #916: hide the overlay for this entity for ~24h. Doesn't clear the
    // halt — the underlying state remains; we just stop blocking the page.
    function doDismiss(type, id) {
        var entity = type + '/' + id;
        dismissFor(entity, DISMISS_HOURS);
        $('#aicli-halt-cards .aicli-halt-card[data-entity="' + entity + '"]').remove();
        swal({ title: 'Dismissed', text: 'Hidden for ' + DISMISS_HOURS + 'h on this device. Reload the page after ' + DISMISS_HOURS + 'h or click Retry to re-check.', type: 'info', timer: 3500, showConfirmButton: false });
        dismissOverlayIfEmpty();
    }

    function doConfirmHost(type, id) {
        var token = getToken();
        // Show feedback immediately
        swal({ title: 'Confirming host...', showConfirmButton: false });
        $.ajax({
            url: AJAX_BASE,
            type: 'GET',
            data: { action: 'clear_halt', type: type, id: id, reason: 'host_confirmed_by_user', csrf_token: token },
            dataType: 'json'
        }).always(function() {
            swal({ title: 'Confirmed', text: 'Host identity accepted. Mount will proceed normally.', type: 'success', timer: 2500, showConfirmButton: false });
            $('#aicli-halt-cards > div[data-entity="' + type + '/' + id + '"]').remove();
            dismissOverlayIfEmpty();
        });
    }

    function doOverride(type, id) {
        swal({
            title: 'Override halt for ' + type + '/' + id + '?',
            text: 'This will dismiss the safety gate and allow an empty mount. Use only if you understand the risk.',
            type: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#7f8c8d',
            confirmButtonText: 'Override',
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (!confirmed) return;
            var token = getToken();
            $.ajax({
                url: AJAX_BASE,
                type: 'GET',
                data: { action: 'clear_halt', type: type, id: id, reason: 'user_manual_override', csrf_token: token },
                dataType: 'json'
            }).always(function() {
                swal({ title: 'Override applied', text: 'Halt cleared.', type: 'info', timer: 2000, showConfirmButton: false });
                $('#aicli-halt-cards > div[data-entity="' + type + '/' + id + '"]').remove();
                dismissOverlayIfEmpty();
            });
        });
    }

    function wireOverlayButtons() {
        $(document).on('click', '#' + OVERLAY_ID + ' .aicli-halt-restore', function() {
            var btn  = $(this);
            doRestoreFromSibling(btn.data('type'), btn.data('id'), btn.data('sibling-dir') || 'sibling directory');
        });
        $(document).on('click', '#' + OVERLAY_ID + ' .aicli-halt-abandon', function() {
            var btn  = $(this);
            doAbandon(btn.data('type'), btn.data('id'));
        });
        $(document).on('click', '#' + OVERLAY_ID + ' .aicli-halt-confirm-host', function() {
            var btn  = $(this);
            doConfirmHost(btn.data('type'), btn.data('id'));
        });
        $(document).on('click', '#' + OVERLAY_ID + ' .aicli-halt-override', function() {
            var btn  = $(this);
            doOverride(btn.data('type'), btn.data('id'));
        });
        // WP #916: always-available non-destructive actions.
        $(document).on('click', '#' + OVERLAY_ID + ' .aicli-halt-retry', function() {
            var btn = $(this);
            doRetry(btn.data('type'), btn.data('id'));
        });
        $(document).on('click', '#' + OVERLAY_ID + ' .aicli-halt-dismiss', function() {
            var btn = $(this);
            doDismiss(btn.data('type'), btn.data('id'));
        });
    }

    function checkAndRenderOverlay() {
        var token = getToken();
        $.ajax({
            url: AJAX_BASE,
            type: 'GET',
            data: { action: 'list_halts', csrf_token: token },
            dataType: 'json'
        }).done(function(data) {
            if (!data || data.status !== 'ok' || !data.halts || data.halts.length === 0) {
                return; // No halts — nothing to do
            }
            if ($('#' + OVERLAY_ID).length) return; // Already rendered

            // WP #916: split halts into (a) auto-healable agent halts and
            // (b) ones that need user attention. Filter out anything the
            // user has dismissed in localStorage.
            var autoHeal = [];
            var blocking = [];
            $.each(data.halts, function(i, halt) {
                var entity = halt.type + '/' + halt.id;
                if (isDismissed(entity)) return; // skip
                // Agent storage with no surviving layers → just reinstall
                // it. No data loss, no user prompt. Other states (corrupt,
                // host_mismatch, partial_loss for agent, anything for home)
                // still need user attention.
                if (halt.type === 'agent' && halt.state === 'total_loss') {
                    autoHeal.push(halt);
                } else {
                    blocking.push(halt);
                }
            });

            // Kick off auto-heal in the background. If there are also blocking
            // halts, the overlay will render and include a healing-state card
            // for each in-flight reinstall so the user knows what's happening.
            $.each(autoHeal, function(i, halt) {
                doAutoHealAgent(halt, { quiet: blocking.length > 0 });
            });

            if (blocking.length === 0 && autoHeal.length === 0) {
                return; // every halt was dismissed
            }

            injectStylesOnce();
            if (blocking.length > 0) {
                $('body').append(buildOverlayHtml(blocking));
                // Add inline healing cards for the agents currently being healed.
                if (autoHeal.length > 0) {
                    var $cards = $('#aicli-halt-cards');
                    $.each(autoHeal, function(i, halt) {
                        $cards.prepend(buildHealingCardHtml(halt));
                    });
                }
                wireOverlayButtons();
            }
            // If ONLY auto-heals were pending, no overlay — the toast on
            // success/failure is the user feedback.
        });
        // Non-fatal on failure — banner just stays hidden
    }

    // Run once on page load
    $(function() {
        checkAndRenderOverlay();
    });

    // Cross-tab: another tab cleared a halt — re-check
    if (window.localStorage) {
        $(window).on('storage', function(e) {
            if (e.originalEvent && e.originalEvent.key === 'aicli_halt_cleared') {
                // Re-fetch and dismiss if no more halts remain
                var token = getToken();
                $.ajax({
                    url: AJAX_BASE,
                    type: 'GET',
                    data: { action: 'list_halts', csrf_token: token },
                    dataType: 'json'
                }).done(function(data) {
                    if (!data || data.status !== 'ok' || !data.halts || data.halts.length === 0) {
                        $('#' + OVERLAY_ID).remove();
                    }
                });
            }
        });
    }
})();

function purgeArtifacts() {
    // Build a detailed file list from the cleanup card's rendered data
    var fileList = '';
    $('#cleanup-card-container .se-body div[style*="overflow-y"] > div').each(function() {
        fileList += $(this).text().trim() + '\n';
    });

    swal({
        title: "Permanently Purge All Artifacts?",
        text: "The following legacy migration files will be permanently deleted:\n\n" + (fileList || "(all .img.migrated and .migrated.* files)") + "\nThis action cannot be undone.",
        type: "error",
        showCancelButton: true,
        confirmButtonColor: "#f44336",
        confirmButtonText: "Yes, Purge All",
        cancelButtonText: "Cancel",
        showLoaderOnConfirm: true,
        closeOnConfirm: false
    }, function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=purge_artifacts&csrf_token=' + csrf, function(r) {
            if (r.status === 'ok') {
                swal({ title: "Purged", text: "All legacy migration artifacts have been removed.", type: "success", timer: 2000, showConfirmButton: false });
                clearChanged();
            }
            else swal("Purge Failed", r.message, "error");
            refreshStats();
        });
    });
}
</script>
