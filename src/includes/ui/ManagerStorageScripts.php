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
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_storage_status&csrf_token=' + csrf, function(data) {
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
                '</div>' +
                '<div class="se-actions">' +
                    '<a href="#" class="stat-icon-btn" onclick="persistEntity(\'home\', \'' + u + '\'); return false;" title="Persist to storage"><i class="fa fa-save"></i></a>' +
                    '<a href="#" class="stat-icon-btn" ' + (canConsolidate ? '' : 'style="opacity:0.3; cursor:default;"') + ' onclick="' + (canConsolidate ? 'consolidateStorage(\'home\', \'' + u + '\')' : 'return false;') + '; return false;" title="' + (canConsolidate ? 'Consolidate Layers' : 'Requires 2+ layers') + '"><i class="fa fa-compress"></i></a>' +
                    '<a href="#" class="stat-icon-btn" onclick="repairStorage(\'home\', \'' + u + '\'); return false;" title="Repair Mount"><i class="fa fa-wrench"></i></a>' +
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
        
        let url = '/plugins/unraid-aicliagents/AICliAjax.php?action=persist_home&csrf_token=' + token;
        if (type === 'agent') {
            url = '/plugins/unraid-aicliagents/AICliAjax.php?action=persist_agent&id=' + id + '&csrf_token=' + token;
        }
        
        $.getJSON(url, function(r) {
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
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=' + action + '&id=' + id + '&csrf_token=' + csrf, function(r) {
            if (r.status === 'ok') swal({ title: "Repaired", text: "Storage stack remounted.", type: "success", timer: 1500, showConfirmButton: false });
            else swal("Repair Failed", r.message, "error");
            refreshStats();
        });
    });
}

function consolidateStorage(type, id) {
    swal({ title: "Consolidate " + type + " layers?", text: "Merge SquashFS deltas into a single base volume. This saves memory.", type: "warning", showCancelButton: true, showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=consolidate_storage&type=' + type + '&id=' + id + '&csrf_token=' + csrf, function(r) {
            if (r.status === 'ok') {
                swal({ title: "Consolidated", type: "success", timer: 1500 });
                clearChanged();
            }
            else swal("Failed", r.message, "error");
            refreshStats();
        });
    });
}

function wipeStorage(type, id) {
    swal({ title: "Wipe Storage: " + id + "?", text: "PERMANENTLY WIPE all storage for this " + type + ". This cannot be undone.", type: "error", showCancelButton: true, confirmButtonColor: "#f44336", confirmButtonText: "YES, WIPE IT", showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=wipe_storage&type=' + type + '&id=' + id + '&csrf_token=' + csrf, function(r) {
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

    // Build the action buttons for a single halt record
    function buildActionButtons(halt) {
        var type    = halt.type;
        var id      = halt.id;
        var state   = halt.state;
        var action  = halt.recommended_action || '';
        var btns    = '';

        if (action === 'restore_from_sibling' || state === 'legacy_unmanaged' || state === 'path_drift') {
            var sibDir = '';
            var paths  = (halt.details && halt.details.sibling_dirs) ? halt.details.sibling_dirs : [];
            if (!paths.length && halt.details && halt.details.siblings_paths) paths = halt.details.siblings_paths;
            if (paths.length) {
                var p = paths[0];
                var sl = p.lastIndexOf('/');
                sibDir = (sl > 0) ? p.substring(0, sl) : p;
            }
            btns += '<button type="button" class="aicli-btn-slim aicli-halt-restore" ' +
                'data-type="' + type + '" data-id="' + id + '" data-sibling-dir="' + (sibDir || 'sibling directory') + '" ' +
                'style="background:#e67e22;">' +
                '<i class="fa fa-reply"></i> Restore from ' + (sibDir ? '<code>' + sibDir + '</code>' : 'sibling') +
                '</button> ';
        }
        if (action === 'use_emergency_mode' || state === 'total_loss' || state === 'partial_loss') {
            btns += '<button type="button" class="aicli-btn-slim aicli-halt-abandon" ' +
                'data-type="' + type + '" data-id="' + id + '" ' +
                'style="background:#c0392b;">' +
                '<i class="fa fa-exclamation-triangle"></i> Start fresh and abandon data' +
                '</button> ';
        }
        if (state === 'host_mismatch') {
            btns += '<button type="button" class="aicli-btn-slim aicli-halt-confirm-host" ' +
                'data-type="' + type + '" data-id="' + id + '" ' +
                'style="background:#27ae60;">' +
                '<i class="fa fa-check"></i> Confirm: this is the correct machine' +
                '</button> ';
        }
        if (action === 'configure_path' || (state === 'path_drift' && action === 'configure_path')) {
            btns += '<a href="#tab-config" class="aicli-btn-slim" style="background:#2980b9;">' +
                '<i class="fa fa-cog"></i> Open Settings</a> ';
        }
        if (action === 'review_manifest' && state !== 'host_mismatch') {
            btns += '<button type="button" class="aicli-btn-slim aicli-halt-override" ' +
                'data-type="' + type + '" data-id="' + id + '" ' +
                'style="background:#7f8c8d; margin-left:8px;">' +
                '<i class="fa fa-unlock"></i> Override and start fresh' +
                '</button>';
        }
        return btns;
    }

    function buildOverlayHtml(halts) {
        var cards = '';
        $.each(halts, function(i, halt) {
            cards +=
                '<div style="background:rgba(0,0,0,0.6); border:1px solid #c0392b; border-radius:8px; padding:16px 20px; margin-bottom:16px; max-width:680px; width:100%;">' +
                    '<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">' +
                        '<div>' +
                            '<div style="font-size:13px; font-weight:700; font-family:monospace; color:#e74c3c; margin-bottom:4px;">' + halt.type + '/' + halt.id + '</div>' +
                            '<div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#e67e22; margin-bottom:6px;">' + stateLabel(halt.state) + '</div>' +
                            (halt.halted_at ? '<div style="font-size:10px; opacity:0.55;">Halted at: ' + halt.halted_at + '</div>' : '') +
                        '</div>' +
                        '<div style="font-size:10px; background:#c0392b; color:#fff; padding:3px 8px; border-radius:4px; font-weight:700; white-space:nowrap; margin-left:12px;">HALTED</div>' +
                    '</div>' +
                    '<div style="font-size:11px; opacity:0.75; margin-bottom:12px;">' + (stateLabel(halt.state)) + '</div>' +
                    '<div style="display:flex; flex-wrap:wrap; gap:8px;">' + buildActionButtons(halt) + '</div>' +
                '</div>';
        });

        return '<div id="' + OVERLAY_ID + '" style="' +
            'position:fixed; top:0; left:0; width:100%; height:100%; z-index:10002;' +
            'background:rgba(0,0,0,0.88); display:flex; flex-direction:column; align-items:center;' +
            'justify-content:center; padding:24px; box-sizing:border-box; overflow-y:auto;">' +
            '<div style="max-width:720px; width:100%; text-align:center; margin-bottom:24px;">' +
                '<div style="font-size:22px; font-weight:700; color:#e74c3c; margin-bottom:8px;">' +
                    '<i class="fa fa-exclamation-circle" style="margin-right:8px;"></i>Storage Unavailable' +
                '</div>' +
                '<div style="font-size:13px; opacity:0.8; margin-bottom:4px;">' +
                    'Boot integrity check detected problems that must be resolved before the plugin can start.' +
                '</div>' +
                '<div style="font-size:11px; opacity:0.55;">The plugin will not overwrite your data without explicit consent.</div>' +
            '</div>' +
            '<div id="aicli-halt-cards" style="width:100%; display:flex; flex-direction:column; align-items:center;">' + cards + '</div>' +
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
        swal({
            title: 'Start fresh and abandon data for ' + type + '/' + id + '?',
            text: 'Type WIPE to confirm. This will permanently discard all existing layers for this entity.',
            type: 'error',
            showCancelButton: true,
            confirmButtonColor: '#c0392b',
            confirmButtonText: 'Confirm',
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (!confirmed) return;
            var token = getToken();
            // Clear the halt and proceed with empty mount
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

    function attachCardDataAttributes() {
        // Tag each card div with a data-entity attribute for removal
        $('#aicli-halt-cards > div').each(function(i) {
            var restoreBtn = $(this).find('.aicli-halt-restore, .aicli-halt-abandon, .aicli-halt-confirm-host, .aicli-halt-override').first();
            if (restoreBtn.length) {
                var t = restoreBtn.data('type') || '';
                var d = restoreBtn.data('id')   || '';
                if (t && d) $(this).attr('data-entity', t + '/' + d);
            }
        });
    }

    function wireOverlayButtons() {
        $(document).on('click', '#' + OVERLAY_ID + ' .aicli-halt-restore', function() {
            var btn  = $(this);
            var type = btn.data('type');
            var id   = btn.data('id');
            var dir  = btn.data('sibling-dir') || 'sibling directory';
            doRestoreFromSibling(type, id, dir);
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
            $('body').append(buildOverlayHtml(data.halts));
            attachCardDataAttributes();
            wireOverlayButtons();
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
