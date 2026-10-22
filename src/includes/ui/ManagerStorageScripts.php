<?php
/**
 * <module_context>
 * Description: JavaScript logic for storage management in AICliAgents Manager.
 * Dependencies: jQuery, SweetAlert, $csrf_token.
 * Constraints: Atomic UI fragment (< 100 lines).
 *   Phase 6 (docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md): every home is a card of the Storage
 *   tab list (id home-<user>). The head of the card comes from aicli_card_open() in SettingsCard.php;
 *   renderHomeStats() fills the body and sets the status line.
 * </module_context>
 */
require_once __DIR__ . '/SettingsCard.php'; // aicli_card_open(), aicli_help_texts(): the head and the (i) texts of a home card.
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

// R-177: a name (a home, a layer, an agent) that goes into an onclick="..." attribute. It becomes a
// JavaScript string literal first, then HTML-escaped, so no name can close the attribute or the string.
function aicliJsArg(s) {
    return escapeHtml(JSON.stringify(String(s == null ? '' : s)));
}

// Phase 6: an (i) button and its help panel, made in the browser (a home card, a line of the card).
// The same markup as aicli_help_btn() and aicli_help_panel() in SettingsCard.php. The id may hold
// only letters, digits and dashes. Put the button in the label and the panel under the row, then
// run aicliHelp.attach(root) on the root.
function aicliHelpHtml(id, label, html) {
    var cid = String(id).toLowerCase().replace(/[^a-z0-9-]/g, '-');
    return {
        btn: '<button type="button" class="aicli-help-btn" data-aicli-help="' + cid + '" data-testid="help-btn-' + cid + '"' +
            ' aria-label="About ' + escapeHtml(label) + '" aria-expanded="false" aria-controls="aicli-help-' + cid + '">' +
            '<i class="fa fa-info-circle" aria-hidden="true"></i></button>',
        panel: '<div class="aicli-help-panel" id="aicli-help-' + cid + '" data-testid="help-panel-' + cid + '" role="note" hidden>' + html + '</div>'
    };
}

// Phase 6: the System memory card. The status line shows "41 % of 7.8 GB used". The card is closed
// while the memory is under 80 % full and opens by itself above that, unless the person chose.
function aicliRootfsStatus(r) {
    var total = Number(r.total_mb) || 0;
    var size = total >= 1024 ? (total / 1024).toFixed(1) + ' GB' : total + ' MB';
    return Number(r.percent) + ' % of ' + size + ' used';
}
function aicliRenderRootfs(r) {
    if (!r) return;
    $('#rootfs-bar').css('width', r.percent + '%');
    $('#rootfs-percent').text(r.percent + '%');
    $('#rootfs-text').text(r.used_mb + 'MB / ' + r.total_mb + 'MB');
    if (window.aicliCards) {
        window.aicliCards.setStatus('system-memory', aicliRootfsStatus(r));
        window.aicliCards.setDefaultCollapsed('system-memory', Number(r.percent) < 80);
    }
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
            // EVENT_FIRST_RECONCILIATION.md fact table: "Storage figures after any
            // job … 30s". The aicli_storage_status push (NchanSubscribers.php)
            // patches the figures between reads; this poll is the reconcile.
            if (statsInterval !== 30000) { statsInterval = 30000; resetStatsTimer(); }
        }
        if (data.rootfs) aicliRenderRootfs(data.rootfs);
        // WP #748 J / Phase B: per-agent cards removed from the Storage tab.
        // Agent storage state is now surfaced in the Store card foot (size only;
        // health remains in the boot-integrity banner). data.agents is still
        // consumed by av2RefreshStoreCardSizes() driven by the Store-tab poll.
        renderHomeStats(data.homes);
        renderCleanupCard(data.artifacts);
    });
}

// REVIEW_2026-09-13_EVENTS_AND_SECURITY.md E2: a dedicated 60s get_storage_status
// reconcile while the Storage tab is actually visible. The live 'storage_status'
// Nchan push (NchanSubscribers.php) already patches renderHomeStats/
// renderCleanupCard between reads, but a push can be missed (a dropped
// websocket, a publish that failed) — this reconcile is the backstop that
// self-corrects within one minute, the same "poll behind the push" shape
// refreshStats()'s own 30s global poll already gives every OTHER tab. Armed
// only while the Storage tab is the active one — never a timer ticking into a
// backgrounded tab — and reads the SAME renderHomeStats/renderCleanupCard
// functions refreshStats() drives, so there is exactly one rendering path.
(function() {
    var _reconcileTimer = null;

    function storageReconcileTick() {
        aicliAjax('get_storage_status', {}, function(data) {
            if (!data) return;
            if (data.homes) renderHomeStats(data.homes);
            if (data.artifacts) renderCleanupCard(data.artifacts);
        });
    }

    function startStorageReconcile() {
        if (_reconcileTimer) return;
        _reconcileTimer = setInterval(storageReconcileTick, 60000);
    }

    function stopStorageReconcile() {
        if (_reconcileTimer) { clearInterval(_reconcileTimer); _reconcileTimer = null; }
    }

    $(document).on('click', '.aicli-tab-btn', function() {
        var onclick = $(this).attr('onclick') || '';
        if (onclick.indexOf("'storage'") !== -1) {
            startStorageReconcile();
        } else {
            stopStorageReconcile();
        }
    });

    // Storage tab already active on page load (e.g. ManagerScripts.php's own
    // localStorage restore of the last-viewed tab).
    if ($('#tab-storage').hasClass('active') || $('#tab-storage').is(':visible')) {
        startStorageReconcile();
    }
})();

function formatSize(bytes) {
    if (typeof bytes !== 'number' || bytes === 0) return '0 KB';
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes < 1048576) return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    return (bytes / 1048576).toFixed(2) + ' MB';
}

function renderLayerList(layers, dirtyMb, upperMode) {
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
        html += '<div class="se-mount-label" style="opacity:0.65;" title="Common parent directory for the compressed layers below">' +
                    '<i class="fa fa-folder-open-o"></i> ' + escapeHtml(flashRoot) +
                '</div>';
    }

    // #231: the live/unsaved upper is its OWN tile ABOVE the durable layer list,
    // not another row inside it — it is a different KIND of thing (unbaked, still
    // changing) from the immutable SquashFS layers, and drawing it as just
    // another layer row conflated "in RAM / not yet saved as a layer" with the
    // baked history below. upper_mode is independent from backend: an entity can
    // keep layered storage while its writable upper lives durably on disk (#251).
    if (typeof dirtyMb !== 'undefined' && dirtyMb !== null && dirtyMb > 0) {
        var isZram = (upperMode === 'zram');
        var dirtyLabel = isZram ? 'ZRAM changes (volatile, not yet flushed)' : 'Writable changes (durable, not yet compacted)';
        var dirtyTitle = isZram ? 'Volatile changes in the ZRAM upper layer; Persist writes them to a durable layer'
                                : 'Changes already saved in the durable disk upper; Persist or Consolidate folds them into an immutable layer';
        html += '<div class="se-live-tile" style="margin-left:20px; display:flex; align-items:center; gap:8px; ' +
                  'padding:7px 10px; margin-bottom:6px; border:1px solid var(--orange, #ff8c00); ' +
                  'border-left-width:3px; border-radius:5px; background:rgba(255,140,0,0.07);" title="' + dirtyTitle + '">' +
                  '<i class="fa fa-bolt" style="color:var(--orange, #ff8c00);"></i>' +
                  '<span class="se-layer-path" style="flex:1; font-weight:600;">' + dirtyLabel + '</span>' +
                  '<span class="se-layer-size">' + dirtyMb + ' MB</span>' +
                '</div>';
    }

    // Indent the list so it visually tucks under the persistence path header
    // above (~20px is roughly the width of the folder icon + its trailing space).
    html += '<div class="se-layer-list" style="margin-left:20px;">';

    // Flash rows — the durable, immutable SquashFS layers. Basename only, blue
    // right bar.
    $.each(layers, function(i, l) {
        var icon = l.name.indexOf('delta') >= 0 ? 'fa-plus-square' : 'fa-database';
        html += '<div class="se-layer-item" style="' + rowPadL + flashBar + '" title="' + escapeHtml(l.path) + '">' +
                  '<i class="fa ' + icon + '"></i>' +
                  '<span class="se-layer-path">' + escapeHtml(l.name) + '</span>' +
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

// HOME_STORAGE_CARD_JOB_STATE.md (Forgejo #247): what the card says about the
// supervisor job that is queued, running or deferred for a home. `job` is the
// server's own field on the snapshot (StorageMetricsService), so every device
// shows the same state and the same lock. Returns null when there is no job.
function homeJobLabel(job) {
    if (!job || !job.state) return null;
    var verb = { bake: 'Saving', consolidate: 'Merging layers', mount: 'Mounting', unmount: 'Unmounting',
                 repair: 'Repairing', delete: 'Deleting', backup: 'Backing up', restore: 'Restoring',
                 graduate: 'Converting to plain folders' }[job.op] || (job.op || 'Working');
    if (job.state === 'queued') return { text: verb + ' — queued', title: verb + ' is queued behind another storage job.', deferred: false };
    if (job.state === 'deferred') {
        var why = { busy_cooldown: 'this home was saved less than 30 minutes ago and is still in use; the plugin retries when it is idle',
                    upper_on_disk: 'the changes are kept on disk and go into a layer when the home is idle',
                    mount_busy: 'this home is in use; the plugin retries when it is idle' }[job.defer_reason] || 'the plugin will retry';
        return { text: verb + ' — waiting', title: verb + ' is waiting: ' + why + '.', deferred: true };
    }
    return { text: verb + '…', title: verb + ' is running now. The figures refresh when it finishes.', deferred: false };
}

// Forgejo #255: give the operator a stable byte-sized answer while the generic
// activity stripe is moving slowly. dirty_mb is the same server snapshot field
// used by the card's upper-layer figure, so this is an estimate of the work
// remaining rather than a made-up percentage.
function homeJobRemaining(job, dirtyMb) {
    if (!job || Number(dirtyMb || 0) <= 0) return '';
    var action = job.op === 'consolidate' ? 'merge' : (job.op === 'bake' ? 'save' : 'process');
    return '~' + formatSize(Number(dirtyMb) * 1048576) + ' to ' + action;
}

// CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R22: after "Convert to plain folders" the old layers wait
// for a rollback. While they exist, the home card (h.graduated = {key, bytes, expires_at}, from the
// server) says how big they are and when the plugin removes them, and offers "Remove old layers".
// Pure: returns the strip HTML, or '' when the home has no such copy.
function aicliGraduatedDate(epochSeconds) {
    var d = new Date(Number(epochSeconds) * 1000);
    if (!isFinite(d.getTime())) return '';
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
function aicliGraduatedStripHtml(user, h, job) {
    var g = h && h.graduated;
    if (!g || !g.key) return '';
    var size = Number(g.bytes) > 0 ? formatSize(Number(g.bytes)) : 'size not known';
    var when = aicliGraduatedDate(g.expires_at);
    return '<div class="se-row" data-testid="home-graduated-strip">' +
        '<span class="se-row-text" data-testid="home-graduated-line"><i class="fa fa-history" style="color:var(--orange, #e68a00); margin-right:5px;"></i>Old layers kept for rollback: ' + escapeHtml(size) + (when ? ', removed automatically on ' + escapeHtml(when) : '') + '</span>' +
        '<button type="button" class="aicli-btn-slim" data-testid="home-graduated-btn" style="white-space:nowrap;"' + (job ? ' disabled title="' + job.title + '"' : '') + ' onclick="removeOldLayers(' + aicliJsArg(user) + '); return false;">Remove old layers</button>' +
        '</div>';
}

// The button posts the same action as the Remove button of the Boot disk space card
// (remove_leftover) with the key of the folder. The server checks the folder again.
function removeOldLayers(user) {
    var hs = (window._aicliHomesLast || {})[user] || {};
    var g = hs.graduated;
    if (!g || !g.key) return false;
    function run() {
        var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
        $.ajax({
            url: '/plugins/unraid-aicliagents/AICliAjax.php?action=remove_leftover',
            method: 'POST', dataType: 'json',
            data: { csrf_token: token, key: g.key }
        }).done(function (r) {
            if (r && r.status === 'ok') {
                if (typeof window.swal === 'function') swal({ title: 'Removed', text: 'Freed ' + formatSize(Number(g.bytes) || 0) + '.', type: 'success', timer: 2500, showConfirmButton: false });
            } else if (typeof window.swal === 'function') {
                swal('Not removed', (r && r.message) || 'The old layers could not be removed.', 'error');
            }
            refreshStats();
        }).fail(function () {
            if (typeof window.swal === 'function') swal('Not removed', 'Could not reach the server.', 'error');
        });
    }
    if (typeof window.swal !== 'function') { if (window.confirm('Remove the old layers of ' + user + '?')) run(); return false; }
    swal({
        title: 'Remove old layers?',
        text: '<div style="text-align:left; font-size:12px; line-height:1.6;">' +
            '<div style="margin-bottom:8px;">The old layers of the home of <b>' + escapeHtml(user) + '</b> are removed for good. You can no longer roll the conversion back.</div>' +
            '<div>This frees <b>' + escapeHtml(formatSize(Number(g.bytes) || 0)) + '</b> on the boot disk.</div></div>',
        html: true,
        type: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Remove',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#b35900'
    }, function (confirmed) { if (confirmed) run(); });
    return false;
}

// Phase 6 (SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md): the id of the card of one home, the id that
// settings-cards.js saves the order and the open state under. Only a-z, 0-9 and dashes stay.
function aicliHomeCardId(user) {
    return 'home-' + String(user).toLowerCase().replace(/[^a-z0-9-]/g, '-');
}

// Pure: the one-line status of a home card. Layered: "12 layers · 240 MB unsaved".
// Plain folders: "Plain folders on the boot disk · no saves needed".
function aicliHomeStatusText(h, job) {
    var isPlain = h.backend === 'passthrough';
    var parts = [];
    if (!h.mounted) parts.push('Not mounted');
    if (isPlain) {
        var p = String(h.persist_path || '');
        parts.push('Plain folders on ' + (p.indexOf('/boot') === 0 ? 'the boot disk' : (p || 'disk')));
        parts.push('no saves needed');
    } else {
        var n = Number(h.layers) || 0;
        parts.push(n + (n === 1 ? ' layer' : ' layers'));
        parts.push(Number(h.dirty_mb || 0) > 0 ? h.dirty_mb + ' MB unsaved' : 'no unsaved changes');
    }
    if (job && job.text) parts.push(job.text);
    return parts.join(' · ');
}

function renderHomeStats(homes) {
    // HOME_BACKUP.md #287: the backup line on each card reads this snapshot.
    if (typeof aicliNoteHomesForBackup === 'function') aicliNoteHomesForBackup(homes);
    let totalPhysical = 0;
    let changed = false;
    let index = 0;
    const seen = {};
    const list = $('#home-stats-container');
    const users = Object.keys(homes || {});
    $('#home-stats-empty').html(users.length === 0
        ? '<div class="storage-empty-state"><i class="fa fa-home" style="font-size:24px; display:block; margin-bottom:8px; opacity:0.3;"></i>No active home persistence</div>' : '');
    $.each(homes || {}, function(u, h) {
            totalPhysical += h.physical_mb;
            // HOME_CARD_BUTTONS.md: Consolidate also saves the unsaved changes, so it has work
            // to do with one layer plus unsaved changes. Repair shows only for a home that is not mounted.
            // #496 (CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R10): a home in plain folders has no
            // layers, so it has no save, no merge and no tidy-up controls.
            const isPlain = h.backend === 'passthrough';
            const canConsolidate = !isPlain && (h.layers >= 2 || (h.layers >= 1 && Number(h.dirty_mb || 0) > 0));
            const showRepair = !h.mounted;
            const job = homeJobLabel(h.job);
            const jobRemaining = isPlain ? '' : (job ? homeJobRemaining(h.job, h.dirty_mb) : '');
            const hasUpperChanges = !isPlain && Number(h.dirty_mb || 0) > 0;
            const isZramUpper = h.upper_mode === 'zram';
            const upperFigure = isPlain ? 'Plain folders' : (!hasUpperChanges ? 'Layered'
                : (isZramUpper ? (h.dirty_mb + ' MB volatile') : (h.dirty_mb + ' MB writable')));
            // A disk upper has no fixed capacity represented by this card, so
            // never paint it as a fake 100%-full bar. Its byte count is the fact.
            const upperBarWidth = isZramUpper ? Number(h.percent || 0) : 0;
            const upperBarText = !hasUpperChanges ? 'Layered'
                : (isZramUpper ? (Number(h.percent || 0) + '% ZRAM used') : (h.dirty_mb + ' MB durable writable layer'));
            const plainPath = String(h.persist_path || '');
            const metaText = isPlain
                ? (plainPath.indexOf('/boot') === 0 ? 'Plain folders on the boot disk'
                    : 'Plain folders' + (plainPath ? ' on ' + escapeHtml(plainPath) : ''))
                : h.physical_mb + ' MB persisted &middot; ' + h.layers + ' Layer' + (h.layers !== 1 ? 's' : '');
            // A plain-folder home has nothing to measure: no bar and no note. The status line of the card says it.
            const statBlock = isPlain
                ? ''
                : '<div class="stat-bar-wrap' + (job ? ' stat-bar-busy' + (job.deferred ? ' stat-bar-deferred' : '') : '') + '" style="height:12px; opacity:' + (h.mounted ? 1 : 0.3) + ';"' + (job ? ' title="' + job.title + '"' : '') + '><div class="stat-bar-base" style="width:' + (100 - upperBarWidth) + '%;"></div><div class="stat-bar-dirty" style="width:' + upperBarWidth + '%;"></div><div class="stat-bar-text">' + (job ? '' : (h.mounted ? upperBarText : 'OFFLINE')) + '</div></div>';
            const lockTitle = job ? ' (locked: ' + job.text + ')' : '';
            const lockAttr = job ? ' disabled aria-disabled="true"' : '';
            const cardId = aicliHomeCardId(u);
            const suffix = cardId.replace(/^home-/, '');
            seen[cardId] = true;
            const convertHelp = aicliHelpHtml('storage-home-convert-' + suffix, 'Plain folders', AICLI_HOME_HELP.convert);
            const body = '<div class="se-body">' +
                    // The figures that sat in the card header: the size line, the state and the job badges.
                    '<div class="se-summary">' +
                        '<span class="se-meta" data-testid="home-storage-meta">' + metaText + '</span>' +
                        '<span style="display:flex; flex-direction:column; align-items:flex-end; gap:2px;">' +
                            '<span style="font-size:11px; font-weight:700; color:' + (!h.mounted ? '#888' : (hasUpperChanges ? 'var(--orange, #ff8c00)' : '#4caf50')) + ';">' + (!h.mounted ? 'OFFLINE' : upperFigure) + '</span>' +
                            // #247: the job badge sits under the figure so the figure stays readable
                            (job ? '<span data-testid="home-job-badge" style="font-size:9px; font-weight:700; color:var(--orange, #ff8c00); letter-spacing:0.5px; text-transform:uppercase;" title="' + job.title + '">' + (job.deferred ? '⧗ ' : '⟳ ') + job.text + (jobRemaining ? ' <span data-testid="home-job-remaining" style="font-weight:600; opacity:0.9; text-transform:none;">· ' + jobRemaining + '</span>' : '') + '</span>' : '') +
                            // WP #271 follow-up: pending-consolidation badge
                            (!isPlain && h.consolidate_pending ? '<span style="font-size:9px; font-weight:700; color:var(--orange, #ff8c00); letter-spacing:0.5px; text-transform:uppercase;" title="Auto-consolidation deferred — waiting for the home mount to go idle (no active terminals).">⧗ Awaiting idle</span>' : '') +
                        '</span>' +
                    '</div>' +
                    statBlock +
                    '<div class="se-mount-label"><i class="fa fa-hdd-o"></i> ' + escapeHtml(h.mount_point) + '</div>' +
                    (isPlain ? '' : renderLayerList(h.layer_files, h.dirty_mb, h.upper_mode)) +
                    // #496 R9: a layered home on a disk that is not a USB stick can become
                    // plain folders in place. The USB relocation strip below stays for sticks.
                    (h.can_convert ?
                        '<div class="se-row" data-testid="home-convert-strip">' +
                            '<span class="se-row-text"><i class="fa fa-folder-open-o" style="color:#4caf50; margin-right:5px;"></i>This home can change to plain folders. Then it needs no more saves or tidy-ups.' + convertHelp.btn + '</span>' +
                            '<button type="button" class="aicli-btn-slim" data-testid="home-convert-btn" style="white-space:nowrap;"' + (job ? ' disabled title="' + job.title + '"' : '') + ' onclick="convertHomeStorage(' + aicliJsArg(u) + '); return false;">Convert to plain folders</button>' +
                        '</div>' + convertHelp.panel : '') +
                    // R22: the rollback copy of the old layers, while it exists.
                    aicliGraduatedStripHtml(u, h, job) +
                    // Bug #1380: non-modal relocation offer — shown ONLY when this
                    // entity's data sits on a GENUINE USB flash drive AND a durable
                    // non-array non-flash target exists to move it to.
                    (h.can_graduate ?
                        '<div class="se-row">' +
                            '<span class="se-row-text"><i class="fa fa-hdd-o" style="color:#4caf50; margin-right:5px;"></i>This data is on a USB flash drive — move it to a durable disk (faster, no USB wear)</span>' +
                            '<button type="button" class="aicli-btn-slim" style="white-space:nowrap;" onclick="graduateStorage(\'home\', ' + aicliJsArg(u) + ', ' + (h.physical_mb || 0) + '); return false;">Move off USB flash drive</button>' +
                        '</div>' : '') +
                    // HOME_BACKUP.md #287: this home's own backup — status line + Backup… dialog.
                    aicliHomeBackupStripHtml(u) +
                '</div>' +
                // The actions have words (Phase 6). Delete stays red and keeps its confirm.
                '<div class="se-actions">' +
                    (isPlain ? '' :
                    '<button type="button" class="aicli-btn-slim' + (job ? ' aicli-locked' : '') + '" data-testid="home-save-btn"' + lockAttr + ' onclick="' + (job ? 'return false;' : 'persistEntity(\'home\', ' + aicliJsArg(u) + '); return false;') + '" title="Persist to storage' + lockTitle + '">Save now</button>' +
                    '<button type="button" class="aicli-btn-slim' + (job ? ' aicli-locked' : '') + '" data-testid="home-merge-btn"' + (canConsolidate && !job ? '' : ' disabled aria-disabled="true"') + ' onclick="' + (canConsolidate && !job ? 'consolidateStorage(\'home\', ' + aicliJsArg(u) + ')' : 'return false;') + '; return false;" title="' + (canConsolidate ? 'Consolidate: save unsaved changes and merge all layers into one' + lockTitle : 'Nothing to merge: one layer and no unsaved changes') + '">Merge layers</button>') +
                    (showRepair ? '<button type="button" class="aicli-btn-slim' + (job ? ' aicli-locked' : '') + '" data-testid="home-repair-btn"' + lockAttr + ' onclick="' + (job ? 'return false;' : 'repairStorage(\'home\', ' + aicliJsArg(u) + '); return false;') + '" title="Repair mount: this home is not mounted' + lockTitle + '">Repair</button>' : '') +
                    '<button type="button" class="aicli-btn-slim danger' + (job ? ' aicli-locked' : '') + '" data-testid="home-delete-btn"' + lockAttr + ' onclick="' + (job ? 'return false;' : 'deleteHomeStorage(' + aicliJsArg(u) + '); return false;') + '" title="Delete home data (permanent)' + lockTitle + '">Delete home…</button>' +
                '</div>';
            const cardClass = 'storage-entity-card' + (hasUpperChanges ? ' has-dirty' : '') + (!h.mounted ? ' offline' : '');
            const html = AICLI_HOME_CARD_HEAD.split('zzidzz').join(suffix)
                .split('zztitlezz').join(escapeHtml('Home: ' + u))
                .split('zzstatuszz').join(escapeHtml(aicliHomeStatusText(h, job))) + body + '</div></div>';
            const old = list.children('.aicli-card').filter(function() { return this.getAttribute('data-aicli-card') === cardId; });
            const pos = 100 + index++;
            if (old.length && old[0].__aicliSig === cardClass + html) { old.attr('data-aicli-pos', pos); return; }
            const card = $(html).addClass(cardClass).attr({ 'data-aicli-home': u, 'data-aicli-pos': pos });
            card.children('.aicli-help-panel').first().html(isPlain ? AICLI_HOME_HELP.plain : AICLI_HOME_HELP.layers);
            card[0].__aicliSig = cardClass + html;
            if (old.length) old.replaceWith(card);
            else {
                const firstFixed = list.children('.aicli-card').not('[data-aicli-home]').first();
                if (firstFixed.length) firstFixed.before(card); else list.append(card);
            }
            changed = true;
    });
    list.children('[data-aicli-home]').each(function() {
        if (!seen[this.getAttribute('data-aicli-card')]) { $(this).remove(); changed = true; }
    });
    $('#homes-text-summary').text(totalPhysical.toFixed(2) + ' MB used');
    window._aicliHomesLast = homes || {};   // R14: the convert dialog reads the sizes
    if (changed && list[0]) {
        // New cards get their handlers and their saved open state; the (i) buttons are wired.
        if (window.aicliHelp) window.aicliHelp.attach(list[0]);
        if (window.aicliCards) window.aicliCards.refresh(list[0]);
    }
    aicliConsumeConvertDeepLink(homes);
}

// The head of a home card (the shared header of SettingsCard.php) and the (i) texts of a home. The
// head holds the words zzidzz, zztitlezz and zzstatuszz, which renderHomeStats() replaces.
var AICLI_HOME_CARD_HEAD = <?= json_encode(aicli_card_open('home-zzidzz', 'fa-home', 'zztitlezz', 'zzstatuszz', 'storage-card-zzidzz', '', ['pos' => 100, 'bodyClass' => 'se-home-body']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var AICLI_HOME_HELP = <?= json_encode([
    'layers' => aicli_help_texts()['storage-home-layers'] ?? '',
    'plain' => aicli_help_texts()['storage-home-plain'] ?? '',
    'backup' => aicli_help_texts()['storage-home-backup'] ?? '',
    'convert' => aicli_help_texts()['storage-home-convert'] ?? '',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

// #496 (CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R13): the install notification links to
// this page with #aicli-convert=home:<user>. The first home snapshot after the page
// loads opens the conversion dialog for that home. The hash is used once.
function aicliConvertDeepLinkUser(hash) {
    var m = /^#aicli-convert=home:([A-Za-z0-9._-]+)$/.exec(String(hash || ''));
    return m ? m[1] : null;
}
var _aicliConvertDeepLink = aicliConvertDeepLinkUser(window.location.hash);
function aicliConsumeConvertDeepLink(homes) {
    if (!_aicliConvertDeepLink) return;
    var user = _aicliConvertDeepLink;
    _aicliConvertDeepLink = null;
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) { /* keep the hash */ }
    var tabBtn = $('.aicli-tab-btn[onclick*="\'storage\'"]');
    if (tabBtn.length && typeof switchMainTab === 'function') switchMainTab('storage', tabBtn[0]);
    var h = (homes || {})[user];
    if (h && h.can_convert) { convertHomeStorage(user); return; }
    var why = (h && h.backend === 'passthrough')
        ? 'The home of ' + user + ' already uses plain folders.'
        : 'The home of ' + user + ' cannot change to plain folders now.';
    if (typeof window.swal === 'function') swal('Nothing to convert', why, 'info');
}

// #496: "Convert to plain folders" — a layered home on a disk that is not a USB stick
// becomes an ordinary folder in the same place. The supervisor closes the home's
// workspaces, copies the home, switches, and opens the workspaces again.
// R14 (John, 2026-10-14): the conversion must never look hung. Before it starts,
// say how big the home is and how long the copy can take. The layers are
// compressed; their contents are often 2 to 3 times larger. A copy out of xz
// layers runs at about 10 to 20 MB/s (13 MB/s on prod02), and a full boot disk
// copies twice (to the work disk and back). Pure: returns the sentence.
function aicliConvertEstimate(physMb, dirtyMb) {
    var phys = Math.max(0, parseFloat(physMb) || 0), dirty = Math.max(0, parseFloat(dirtyMb) || 0);
    if (phys + dirty <= 0) return '';
    var gb = function (mb) { return mb < 1024 ? Math.round(mb) + ' MB' : (mb / 1024).toFixed(1) + ' GB'; };
    var lo = phys * 2 + dirty, hi = phys * 3 + dirty;
    var minLo = Math.max(1, Math.round(lo / 20 / 60)), minHi = Math.max(minLo + 1, Math.round(hi * 2 / 10 / 60));
    return 'This home takes ' + gb(phys + dirty) + ' on disk. Its contents are larger, often 2 to 3 times '
        + '(about ' + gb(lo) + ' to ' + gb(hi) + '), and the copy runs at about 10 to 20 MB/s, so this can take '
        + minLo + ' to ' + minHi + ' minutes. The workspaces stay closed until it ends; the Activity tray shows the progress.';
}

function convertHomeStorage(id) {
    if (aicliBlockedByConsolidate('converting this home')) return false;
    var hs = (window._aicliHomesLast || {})[id] || {};
    var estimate = aicliConvertEstimate(hs.physical_mb, hs.dirty_mb);
    var plain = 'Convert the home of ' + id + ' to plain folders?\n\n'
        + 'Every workspace of this home closes and opens again. The home stays on the boot disk. '
        + 'It needs no more tidy-ups. Your current storage files are kept as a backup for 14 days, then removed; when the boot disk is short of space, that backup is kept on another disk.'
        + (estimate ? '\n\n' + estimate : '');
    function run(done) {
        aicliAjax('convert_storage', { type: 'home', id: id }, function(r) {
            if (r && r.status === 'queued') {
                if (typeof window.swal === 'function') {
                    swal({ title: 'Conversion queued', text: r.message || 'Watch the activity tray for progress.', type: 'info', timer: 3000, showConfirmButton: false });
                }
                clearChanged();
            } else if (typeof window.swal === 'function') {
                swal('Not started', (r && r.message) || 'Unknown error. Check the Debug Console.', 'error');
            } else {
                window.alert((r && r.message) || 'The conversion did not start.');
            }
            if (done) done();
            refreshStats();
        }).fail(function(x, s, e) {
            // R-175: the dialog must not spin for ever when the request fails.
            var why = 'Could not reach the server. ' + aicliAjaxReason(x, s, e);
            if (typeof window.swal === 'function') swal('Not started', why, 'error'); else window.alert(why);
            if (done) done();
        });
    }
    if (typeof window.swal !== 'function') {
        if (window.confirm(plain)) run();
        return false;
    }
    // Lift the dialog above the open Activity tray (the same body class the tray uses, #493).
    if (!document.getElementById('aicli-swal-above-tray-css')) {
        var st = document.createElement('style');
        st.id = 'aicli-swal-above-tray-css';
        st.textContent = 'body.aicli-swal-above-tray .sweet-overlay { z-index: 1000010 !important; }'
            + 'body.aicli-swal-above-tray .sweet-alert { z-index: 1000011 !important; }';
        document.head.appendChild(st);
    }
    document.body.classList.add('aicli-swal-above-tray');
    var html =
        '<div style="text-align:left; font-size:12px; line-height:1.6;">' +
            '<div style="margin-bottom:8px;">The home of <b>' + escapeHtml(id) + '</b> changes from layers to plain folders.</div>' +
            '<ul style="margin:0 0 0 18px; padding:0;">' +
                '<li>Every workspace of this home closes and opens again.</li>' +
                '<li>The home stays on the boot disk.</li>' +
                '<li>It needs no more tidy-ups.</li>' +
                '<li>Your current storage files are kept as a backup for 14 days, then removed; when the boot disk is short of space, that backup is kept on another disk.</li>' +
            '</ul>' +
            (estimate ? '<div style="margin-top:8px;"><b>How long:</b> ' + escapeHtml(estimate) + '</div>' : '') +
        '</div>';
    swal({
        title: 'Convert to plain folders?',
        text: html,
        html: true,
        type: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Convert',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#b35900',
        showLoaderOnConfirm: true,
        closeOnConfirm: false
    }, function (confirmed) {
        if (!confirmed) {
            document.body.classList.remove('aicli-swal-above-tray');
            return;
        }
        aicli_log_to_server('User requested convert to plain folders for home/' + id, 2);
        run(function () { document.body.classList.remove('aicli-swal-above-tray'); });
    });
    return false;
}

function renderCleanupCard(artifacts) {
    $('#cleanup-card-container').remove();
    if (!artifacts || artifacts.length === 0) return;

    let totalMb = 0;
    let fileListHtml = '';
    $.each(artifacts, function(i, art) {
        totalMb += parseFloat(art.size_mb) || 0;
        fileListHtml += '<div style="display:flex; justify-content:space-between; padding:4px 8px; border-bottom:1px solid var(--border-color, rgba(0,0,0,0.06)); font-family:monospace; font-size:10px;">' +
            '<span><i class="fa ' + (art.type === 'image' ? 'fa-file-archive-o' : 'fa-folder-o') + '" style="width:16px; color:var(--orange, #e68a00); opacity:0.6;"></i> ' + escapeHtml(art.name) + '</span>' +
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
                    '<button type="button" class="aicli-btn-slim" onclick="purgeArtifacts()"><i class="fa fa-trash"></i> Purge All Artifacts</button>' +
                '</div>' +
            '</div>' +
        '</div>' +
    '</div>';

    $('#home-stats-container').after(card);
}

function persistEntity(type, id) {
    const title = type === 'agent' ? "Persist Agent Updates?" : "Persist Home Changes?";
    const text = type === 'agent' ? "Commit changes in RAM to storage for " + id + "." : "Commit current RAM session data to compressed layers for " + id + ".";
    
    swal({ title: title, text: text, type: "info", showCancelButton: true, confirmButtonText: "Persist Now", showLoaderOnConfirm: true, closeOnConfirm: false }, function(confirmed) {
        if (!confirmed) return;
        const token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
        aicli_log_to_server("User requested manual " + type + " persistence for " + id, 2);
        
        // R-06: aicliAjax stamps the X-Aicli-Trace header — this is the canonical
        // AJAX→PHP→shell mutation path the trace id is designed to join.
        const req = (type === 'agent')
            ? aicliAjax('persist_agent', { id: id })
            : aicliAjax('persist_home', { user: id });
        // R2 (HOME_PERSIST_PILL_AND_FEEDBACK): never leave the spinner frozen.
        req.fail(function() {
            swal("Persistence Failed", "The request did not complete. Check debug.log / network.", "error");
        });
        req.done(function(r) {
            if (r && r.status === 'ok' && r.baking && r.job_id) {
                // Queued path (home persist + agent persist): hand off to the activity tray.
                swal({ title: "Queued", text: "Queued — watch the activity tray for progress.", type: "info", timer: 2500, showConfirmButton: false });
                clearChanged();
                refreshStats();
            } else if (r && r.status === 'nothing_to_save') {
                // #567: the save did nothing. Neutral, never the green "Persisted".
                swal({ title: "Nothing to save", text: r.message || "There were no changes to write.", type: "info", timer: 2500, showConfirmButton: false });
            } else if (r && r.status === 'emergency') {
                // #567: the emergency session runs on a temporary home. Warn, do not call it saved.
                swal({ title: "Not saved", text: r.message || "This session runs on the emergency temporary home. Its work is not kept.", type: "warning", showConfirmButton: true });
            } else if (r && r.status === 'ok') {
                // Legacy synchronous path (no baking flag) — still used for edge cases.
                swal({ title: "Persisted", text: "Data persisted.", type: "success", timer: 2000, showConfirmButton: false });
                clearChanged();
                refreshStats();
            } else if (r && r.status === 'busy') {
                swal({ title: "Busy", text: r.message || "Another operation is in progress. Please wait and try again.", type: "warning", showConfirmButton: true });
            } else {
                const err = (r && r.message) || "Unknown Error. Check debug.log";
                aicli_log_to_server("Manual persistence FAILED: " + err, 0);
                swal("Persistence Failed", err, "error");
            }
        });
    });
}


function repairStorage(type, id) {
    if (aicliBlockedByConsolidate('repairing storage')) return false;
    swal({ title: "Repair " + type + " storage?", text: "Unmount and remount the OverlayFS stack for " + id + ". This may briefly interrupt active sessions.", type: "warning", showCancelButton: true, confirmButtonText: "Repair", showLoaderOnConfirm: true, closeOnConfirm: false }, function(confirmed) {
        if (!confirmed) return;
        const action = (type === 'agent') ? 'repair_agent_storage' : 'repair_home_storage';
        aicliAjax(action, (type === 'agent') ? { id: id } : { id: id, user: id }, function(r) {
            if (r.status === 'ok') swal({ title: "Repaired", text: "Storage stack remounted.", type: "success", timer: 1500, showConfirmButton: false });
            else swal("Repair Failed", r.message, "error");
            refreshStats();
        }).fail(function(x, s, e) {
            swal("Repair Failed", "Could not reach the server. " + aicliAjaxReason(x, s, e), "error");
        });
    });
}

function consolidateStorage(type, id) {
    if (aicliBlockedByConsolidate('starting another consolidation')) return false;
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
    // CONSOLIDATE_CONTINUE_OPTION.md: the dialog for a home with open workspaces has a box
    // "Send Continue to each workspace after it reopens" (ticked). Unticked: the workspaces
    // reopen with their conversation resumed but get no Continue, so they use no tokens.
    var continueAfter = true;
    function runConsolidate() {
        var args = { type: type, id: id };
        if (!continueAfter) args.continue_after = '0';
        aicliAjax('consolidate_storage', args, function(r) {
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
        }).fail(function(x, s, e) {
            swal('Failed', 'Could not reach the server. ' + aicliAjaxReason(x, s, e), 'error');
        });
    }

    function doConsolidate() {
        swal({ title: 'Consolidate ' + type + ' layers?', text: (type === 'home' ? 'Save your unsaved changes and merge all saved layers into one layer. This saves memory.' : 'Merge SquashFS deltas into a single base volume. This saves memory.'), type: 'warning', showCancelButton: true, showLoaderOnConfirm: true, closeOnConfirm: false }, function(confirmed) {
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
                '<label style="display:flex; align-items:flex-start; gap:8px; margin-top:12px; padding:6px 8px; text-align:left; cursor:pointer; border-radius:4px; background:var(--mild-background-color,rgba(0,0,0,0.04));">' +
                    '<input type="checkbox" id="aicli-consol-continue" data-testid="consolidate-continue-box" checked style="margin-top:3px;">' +
                    '<span style="font-size:11px; line-height:1.4; color:var(--text-color,#ccc);"><strong>Send Continue to each workspace after it reopens</strong>' +
                    '<br><span style="opacity:0.7;">Untick to reopen them without a message. They use no tokens until you tell them to go on.</span></span>' +
                '</label>' +
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
            var box = document.getElementById('aicli-consol-continue');
            continueAfter = !box || box.checked;
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
    if (aicliBlockedByConsolidate('moving storage off the USB flash drive')) return false;
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
                if (r && (r.status === 'ok' || r.status === 'queued')) {
                    swal({ title: "Move started", text: "The data is being copied and verified, then the path is switched. This goes on if you close the page. Watch progress in the activity tray.", type: "success", timer: 4000, showConfirmButton: false });
                } else {
                    swal("Move failed", (r && r.message) || "Unknown error. Check debug.log.", "error");
                }
                refreshStats();
            }).fail(function(x, s, e) {
                swal("Move failed", "Could not reach the server. " + aicliAjaxReason(x, s, e), "error");
            });
        });
    });
}

function wipeStorage(type, id) {
    swal({ title: "Wipe Storage: " + id + "?", text: "PERMANENTLY WIPE all storage for this " + type + ". This cannot be undone.", type: "error", showCancelButton: true, confirmButtonColor: "#f44336", confirmButtonText: "YES, WIPE IT", showLoaderOnConfirm: true, closeOnConfirm: false }, function(confirmed) {
        if (!confirmed) return;
        aicliAjax('wipe_storage', { type: type, id: id }, function(r) {
            if (r.status === 'ok') {
                swal({ title: "Wiped", type: "success", timer: 1500 });
                clearChanged();
            }
            else swal("Failed", r.message, "error");
            refreshStats();
        }).fail(function(x, s, e) {
            swal("Failed", "Could not reach the server. " + aicliAjaxReason(x, s, e), "error");
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
            // #654: the server makes a short single-use token once DELETE is typed.
            aicliAjax('delete_home_confirm_token', { id: id }, function(t) {
                if (!t || t.status !== 'ok' || !t.token) {
                    swal('Delete Failed', (t && t.message) || 'Could not get a confirmation from the server.', 'error');
                    return;
                }
                aicliAjax('delete_home_storage', { id: id, confirm_token: t.token }, function(r) {
                    if (r && r.status === 'ok') {
                        swal({ title: 'Deleted', text: r.message || 'Home storage deleted.', type: 'success', timer: 2500, showConfirmButton: false });
                        refreshStats();
                    } else {
                        swal('Delete Failed', (r && r.message) || 'Unknown error. Check debug.log.', 'error');
                    }
                }).fail(function(x, s, e) {
                    swal('Delete Failed', 'Could not reach the server. ' + aicliAjaxReason(x, s, e), 'error');
                });
            }).fail(function(x, s, e) {
                swal('Delete Failed', 'Could not reach the server. ' + aicliAjaxReason(x, s, e), 'error');
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
        }, function(confirmed) {
            if (!confirmed) return;
            aicliAjax('delete_home_storage', { id: id }, function(r) {
                if (r && r.status === 'ok') {
                    swal({ title: 'Deleted', text: r.message || 'Home storage deleted.', type: 'success', timer: 2500, showConfirmButton: false });
                    refreshStats();
                } else {
                    swal('Delete Failed', (r && r.message) || 'Unknown error. Check debug.log.', 'error');
                }
            }).fail(function(x, s, e) {
                swal('Delete Failed', 'Could not reach the server. ' + aicliAjaxReason(x, s, e), 'error');
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
                                'style="white-space:nowrap; margin-left:16px;" ' +
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
                                'style="white-space:nowrap; margin-left:16px;">' +
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
        }, function(confirmed) {
            if (!confirmed) return;
            $.ajax({
                url: '/plugins/unraid-aicliagents/AICliAjax.php',
                type: 'POST',
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

    // Trigger fetch when the storage tab becomes visible. Epic #307: the
    // Manager's real tab button is .aicli-tab-btn with switchMainTab('storage')
    // — the two older selectors match nothing on this page, so the banner
    // never loaded unless Storage was already the active tab at load time.
    $(document).on('click', '.aicli-tab-btn[onclick*="\'storage\'"], [data-tab="storage"], .aicli-nav-item[href*="storage"]', function() {
        setTimeout(fetchBootIntegrity, 300);
    });
    // Also fetch if storage tab is already active on page load. Checked after
    // DOM-ready, because ManagerScripts.php restores the last-used tab then.
    $(function() {
        setTimeout(function() {
            if ($('#tab-storage').hasClass('active') || $('#tab-storage').is(':visible')) fetchBootIntegrity();
        }, 800);
    });
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
                ' on Flash at <code><?=htmlspecialchars(\AICliAgents\Services\SecretPaths::flashRoot(), ENT_QUOTES, 'UTF-8')?>/failures/</code>' +
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
// fixed-position overlay (z-index:10004, above the Activity tray pill at
// 10003, so the pill never covers a card's buttons) blocking the page until each halt
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
        legacy_unmanaged : 'Saved layers were found in another folder that the plugin does not manage',
        path_drift       : 'The plugin saved these layers in one folder, but the storage setting now points to another folder, so it cannot find them',
        partial_loss     : 'Some saved layers are missing from the storage folder',
        total_loss       : 'All saved layers are missing. The drive may be disconnected',
        corrupt_layers   : 'A saved layer failed its integrity check (the checksum does not match)',
        host_mismatch    : 'The saved layers were written on a different server (the USB flash drive may have moved)',
    };

    // What the card is about, in words a person knows. The id stays in quotes.
    var TYPE_LABELS = { home: 'Home storage', agent: 'Agent storage' };
    function entityLabel(halt) {
        return (TYPE_LABELS[halt.type] || halt.type) + ' "' + halt.id + '"';
    }

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
            '#' + OVERLAY_ID + '{position:fixed;inset:0;z-index:10004;background:rgba(0,0,0,.55);backdrop-filter:blur(3px);display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding:32px 24px;box-sizing:border-box;overflow-y:auto;}' +
            '#' + OVERLAY_ID + ' .aicli-halt-header{max-width:720px;width:100%;text-align:center;margin-bottom:20px;color:#fff;text-shadow:0 1px 3px #000;}' +
            /* Solid black shadow: a translucent one blended to grey over a light page and left the orange title at 2.77:1 (axe color-contrast, 2026-09-29). */
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
            // NATIVE_BUTTON_STYLE.md (2026-09-29): every action has the same plain
            // look (the theme's text on its mild background, a thin border), like
            // Unraid's own buttons. No orange, green or blue fill. The destructive
            // action keeps red text; its confirmation step is unchanged.
            '#' + OVERLAY_ID + ' .aicli-btn-slim.aicli-halt-btn-danger{color:color-mix(in srgb,#dc2626 70%,var(--text-color,#1c1b1b)) !important;}' +
            '#' + OVERLAY_ID + ' .aicli-btn-slim.aicli-halt-btn-neutral{background:transparent !important;color:var(--text-color,#222) !important;}' +
            '#' + OVERLAY_ID + ' .aicli-heal-spinner{display:inline-block;width:14px;height:14px;border:2px solid var(--alt-text-color,#888);border-top-color:transparent;border-radius:50%;animation:aicli-halt-spin 0.9s linear infinite;margin-right:8px;vertical-align:middle;}' +
            '@keyframes aicli-halt-spin{to{transform:rotate(360deg)}}' +
            // Epic #307: at phone size each action is a 44 px touch target that
            // wraps its text, and the overlay uses the full width. Desktop unchanged.
            '@media (max-width:600px){' +
                '#' + OVERLAY_ID + '{padding:16px 12px;}' +
                '#' + OVERLAY_ID + ' .aicli-halt-card{padding:14px 14px;}' +
                '#' + OVERLAY_ID + ' .aicli-halt-entity{overflow-wrap:anywhere;}' +
                '#' + OVERLAY_ID + ' .aicli-btn-slim{min-height:44px !important;box-sizing:border-box !important;white-space:normal !important;overflow-wrap:anywhere !important;flex:1 1 100% !important;justify-content:center !important;}' +
                // Readable text (4.5:1): orange-on-grey and white-on-orange /
                // white-on-green were 2.1-2.6:1.
                '#' + OVERLAY_ID + ' .aicli-halt-entity{color:var(--text-color,#222);}' +
                '#' + OVERLAY_ID + ' .aicli-halt-when{opacity:1;}' +
                '#' + OVERLAY_ID + ' .aicli-halt-state{color:var(--text-color,#222);}' +
                '#' + OVERLAY_ID + ' .aicli-halt-badge{color:#111;}' +
            '}';
        document.head.appendChild(st);
    }

    function buildOverlayHtml(halts) {
        var cards = '';
        $.each(halts, function(i, halt) {
            cards +=
                '<div class="aicli-halt-card" data-entity="' + halt.type + '/' + halt.id + '">' +
                    '<div class="aicli-halt-card-head">' +
                        '<div>' +
                            '<div class="aicli-halt-entity">' + entityLabel(halt) + '</div>' +
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
                '<div class="aicli-halt-title"><i class="fa fa-exclamation-circle" style="margin-right:8px;"></i>AI CLI Agents: Storage Attention Needed</div>' +
                '<div class="aicli-halt-subtitle">The AI CLI Agents plugin checked its saved storage at start-up and found a problem. Your data has not been changed. The plugin will not overwrite it without your consent.</div>' +
                '<div class="aicli-halt-note">Tip: you can Dismiss to deal with this later, or click Retry after fixing the underlying issue.</div>' +
            '</div>' +
            // id: every card lookup below uses #aicli-halt-cards. Without it,
            // dismissing ONE halt closed the whole overlay and hid the others.
            '<div class="aicli-halt-cards" id="aicli-halt-cards">' + cards + '</div>' +
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
            type: 'POST',
            data: { action: 'restore_from_sibling', type: type, id: id, csrf_token: token },
            dataType: 'json'
        }).done(function(r) {
            if (r && r.status === 'ok') {
                // Clear the halt
                $.ajax({
                    url: AJAX_BASE,
                    type: 'POST',
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
                type: 'POST',
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
            type: 'POST',
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
            type: 'POST',
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
                type: 'POST',
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
    }, function(confirmed) {
        if (!confirmed) return;
        aicliAjax('purge_artifacts', {}, function(r) {
            if (r.status === 'ok') {
                swal({ title: "Purged", text: "All legacy migration artifacts have been removed.", type: "success", timer: 2000, showConfirmButton: false });
                clearChanged();
            }
            else swal("Purge Failed", r.message, "error");
            refreshStats();
        }).fail(function(x, s, e) {
            swal("Purge Failed", "Could not reach the server. " + aicliAjaxReason(x, s, e), "error");
        });
    });
}

// #131: poll the home consolidation state so the Storage tab shows a live
// "tidy-up in progress" banner and pauses storage actions while it runs (the
// drawer shows the same banner; the interactive start is already gated). Uses
// the same get_force_reclaim_state endpoint, extended with `consolidating`.
window.aicli_home_consolidating = false;
function aicliBlockedByConsolidate(actionLabel) {
    if (!window.aicli_home_consolidating) return false;
    swal('Storage tidy-up in progress',
        'A home consolidation is running — ' + (actionLabel || 'this action') +
        ' is paused until it finishes to avoid interrupting the reclaim. It usually takes a few minutes.',
        'info');
    return true;
}
(function () {
    // EVENT_FIRST_RECONCILIATION.md fact table: "Maintenance / force-reclaim
    // state … 30s". The aicli_storage_status push's `maintenance` field
    // (NchanSubscribers.php) patches window.aicli_home_consolidating and the
    // banner between reads via applyConsolidateState() below.
    window.applyConsolidateState = function (d) {
        var on = !!(d && d.consolidating);
        window.aicli_home_consolidating = on;
        $('#aicli-consolidate-banner').css('display', on ? 'flex' : 'none');
    };
    function pollConsolidate() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_force_reclaim_state&csrf_token=' + csrf, window.applyConsolidateState);
    }
    pollConsolidate();
    setInterval(pollConsolidate, 30000);
})();

/* ---------------------------------------------------------------------------
 * HOME_BACKUP.md "2026-09-24 redesign (#287)" — each home has its OWN backup
 * settings, reached from a Backup… button on that home's card. The dialog
 * saves every change at once through set_home_backup_setting (one key per
 * request) and shows the value the server read back from disk, so what the
 * dialog shows is what is saved. It never posts the shared settings form:
 * the Storage tab sits outside #aicli-settings-form (Bug #710), which is why
 * the old plugin-wide card never saved anything.
 * ------------------------------------------------------------------------- */

var _aicliHomes = {};          // last homes snapshot from get_storage_status, by user
var _aicliBackupRunning = {};  // user -> step text while that home's backup job runs
var _aicliHb = null;           // the open dialog: { user, settings }
var AICLI_HB_DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function aicliBackupRowId(user) { return String(user).replace(/[^A-Za-z0-9_-]/g, '_'); }

function aicliFmtSchedule(s) {
    var m = /^daily:(\d\d:\d\d)$/.exec(s || '');
    if (m) return 'daily at ' + m[1];
    m = /^weekly:(\d):(\d\d:\d\d)$/.exec(s || '');
    if (m) return 'weekly on ' + AICLI_HB_DAYS[+m[1]] + ' at ' + m[2];
    return '';
}

function aicliFmtBackupWhen(at) { return at ? new Date(at).toLocaleString() : 'unknown time'; }

// HOME_BACKUP.md 2026-09-29: a warm backup is complete even when the running
// sessions changed files during it; say how many.
function aicliFmtChangedDuring(lb) {
    var n = (lb && typeof lb.changed_during === 'number') ? lb.changed_during : 0;
    if (!lb || !lb.warm || n <= 0) return '';
    return ' · ' + n + (n === 1 ? ' file' : ' files') + ' changed during the backup';
}

// The Home card's backup line: one of unset / never / running / ok / failed.
function aicliHomeBackupState(user) {
    var h = _aicliHomes[user] || {};
    var bk = h.backup || null;
    var job = (h.job && h.job.op === 'backup') ? h.job : null;
    if (_aicliBackupRunning[user] || job) {
        var step = _aicliBackupRunning[user] || (job && job.state === 'queued' ? 'queued' : (job && job.state === 'deferred' ? 'waiting' : 'running'));
        return { state: 'running', icon: 'fa-spinner fa-spin', text: 'Backup: running — ' + step };
    }
    var lb = h.last_backup || null;
    if (!bk || !bk.configured) {
        // HOME_BACKUP.md 2026-09-24 follow-up: a home can have older snapshots
        // (made before it had a folder of its own). Say where they are; the
        // dialog offers that folder, it is never chosen silently.
        var earlier = (bk && bk.earlier_target) || '';
        if (earlier) {
            return { state: 'unset', icon: 'fa-life-ring', text: 'Backup: no folder chosen — earlier backups found in ' + earlier + (lb ? ' (last ' + aicliFmtBackupWhen(lb.at) + ').' : '.') };
        }
        return { state: 'unset', icon: 'fa-life-ring', text: 'Backup: not set up.' };
    }
    var sched = aicliFmtSchedule(bk.schedule);
    var run = h.last_backup_run || null;
    var runT = run && run.at ? Date.parse(run.at) : NaN;
    var lbT = lb && lb.at ? Date.parse(lb.at) : NaN;
    if (run && run.ok === false && (!lb || isNaN(lbT) || (!isNaN(runT) && runT >= lbT))) {
        return { state: 'failed', icon: 'fa-exclamation-triangle', text: 'Backup: last run failed' + (run.summary ? ': ' + run.summary : '.') };
    }
    if (lb) {
        var size = (typeof lb.bytes === 'number') ? ' · ' + (lb.bytes / 1048576).toFixed(1) + ' MB' : '';
        return { state: 'ok', icon: 'fa-check-circle', text: 'Backup: last ' + aicliFmtBackupWhen(lb.at) + size + ' (' + (lb.warm ? 'warm' : 'cold') + ')' + aicliFmtChangedDuring(lb) + (sched ? ' · ' + sched : '') };
    }
    return { state: 'never', icon: 'fa-circle-o', text: 'Backup: not yet · to ' + bk.target + (sched ? ' · ' + sched : '') };
}

function aicliHomeBackupStripHtml(user) {
    var st = aicliHomeBackupState(user);
    var rowId = aicliBackupRowId(user);
    // Phase 6: the note that sat at the foot of the page is the (i) of this row.
    var help = aicliHelpHtml('storage-home-backup-' + rowId, 'Backup', AICLI_HOME_HELP.backup);
    return '<div class="se-backup se-backup-' + st.state + '" data-testid="home-backup-strip" data-user="' + escapeHtml(user) + '" data-state="' + st.state + '">' +
        '<span class="se-backup-status" id="home-backup-status-' + rowId + '"><i class="fa ' + st.icon + '" aria-hidden="true"></i> <span class="se-backup-text">' + escapeHtml(st.text) + '</span></span>' +
        help.btn +
        '<button type="button" class="aicli-btn-slim se-backup-btn" id="home-backup-btn-' + rowId + '" data-user="' + escapeHtml(user) + '" aria-haspopup="dialog" aria-describedby="home-backup-status-' + rowId + '">Backup…</button>' +
    '</div>' + help.panel;
}

// Repaint one card's backup line in place (no card re-render, focus kept),
// and the same line at the top of the dialog when it is open for that user.
function aicliPaintBackupStrip(user) {
    var st = aicliHomeBackupState(user);
    var strip = $('.se-backup[data-user]').filter(function() { return $(this).attr('data-user') === user; });
    strip.attr('class', 'se-backup se-backup-' + st.state).attr('data-state', st.state);
    strip.find('.se-backup-status').html('<i class="fa ' + st.icon + '" aria-hidden="true"></i> <span class="se-backup-text">' + escapeHtml(st.text) + '</span>');
    strip.find('.se-backup-btn').text('Backup…');
    if (_aicliHb && _aicliHb.user === user) {
        $('#hb-state').attr('class', 'hb-state hb-state-' + st.state).attr('data-state', st.state)
            .html('<i class="fa ' + st.icon + '" aria-hidden="true"></i> ' + escapeHtml(st.text));
    }
}

// Called by renderHomeStats() with every fresh snapshot.
function aicliNoteHomesForBackup(homes) {
    _aicliHomes = homes || {};
    $.each(_aicliHomes, function(u, h) {
        if (h && h.job && h.job.op === 'backup' && h.job.state === 'running') aicliPollBackupStatus(u);
    });
    if (_aicliHb && _aicliHb.user) aicliPaintBackupStrip(_aicliHb.user);
}

$(document).on('click', '.se-backup-btn', function(e) {
    e.preventDefault();
    aicliOpenHomeBackup($(this).attr('data-user'));
});

var _aicliBackupPollers = {};

// HOME_RESTORE.md R1: "Back up now" and every "Restore…" button stay disabled
// while EITHER a backup or a restore job runs for that user; "Back up now"
// also needs a backup folder (#287).
var _aicliRowBusy = {};

function aicliUpdateRowBusyUI(user) {
    var rowId = aicliBackupRowId(user);
    var state = _aicliRowBusy[user] || {};
    var busy = !!(state.backup || state.restore);
    var noTarget = !(_aicliHb && _aicliHb.user === user && _aicliHb.settings && _aicliHb.settings.target);
    var btn = $('#backup-now-' + rowId);
    btn.prop('disabled', busy || noTarget)
        .attr('title', busy ? 'A backup or restore of this home is running.' : (noTarget ? 'Choose a destination folder first.' : ''));
    $('#backup-row-' + rowId).find('.aicli-restore-btn, .aicli-snap-delete-btn').prop('disabled', busy);
}

// Dialog: last backup line, and the running step while a job runs.
function aicliRefreshBackupStatus(user) {
    var rowId = aicliBackupRowId(user);
    aicliAjax('backup_status', { user: user }, function(r) {
        var lastEl = $('#backup-last-' + rowId);
        if (!r || r.status !== 'ok') { lastEl.text('Backup status unavailable.'); return; }
        if (r.last_backup) {
            // StorageHandler::backupStatusFor(): {at (ISO 8601 UTC string), path, bytes, files, warm, ok}.
            var lb = r.last_backup;
            var size = (typeof lb.bytes === 'number') ? (lb.bytes / 1048576).toFixed(1) + ' MB' : '';
            var files = (typeof lb.files === 'number') ? (lb.files + ' files') : '';
            lastEl.text('Last backup: ' + aicliFmtBackupWhen(lb.at) + (size ? ' — ' + size : '') + (files ? ', ' + files : '') + ' (' + (lb.warm ? 'warm' : 'cold') + ')' + aicliFmtChangedDuring(lb));
        } else {
            lastEl.text('No backup yet.');
        }
        var prog = $('#backup-progress-' + rowId);
        // r.running is {jobId, step} while a job is active, else null.
        _aicliRowBusy[user] = _aicliRowBusy[user] || {};
        _aicliRowBusy[user].backup = !!r.running;
        aicliUpdateRowBusyUI(user);
        if (r.running) {
            prog.show().find('span').text(r.running.step || 'Running…');
            aicliPollBackupStatus(user);
        } else {
            prog.hide();
        }
    }).fail(function() { $('#backup-last-' + rowId).text('Backup status unavailable.'); });
}

// 5s poll while a backup runs — same cadence startInstallPolling() uses for an
// install/upgrade progress bar. Feeds the card line AND the dialog.
function aicliPollBackupStatus(user) {
    if (_aicliBackupPollers[user]) return;
    _aicliBackupPollers[user] = setInterval(function() {
        var rowId = aicliBackupRowId(user);
        aicliAjax('backup_status', { user: user }, function(r) {
            if (!r || r.status !== 'ok' || !r.running) {
                var wasRunning = !!_aicliBackupRunning[user];
                clearInterval(_aicliBackupPollers[user]);
                delete _aicliBackupPollers[user];
                delete _aicliBackupRunning[user];
                if (_aicliHomes[user] && _aicliHomes[user].job && _aicliHomes[user].job.op === 'backup') _aicliHomes[user].job = null;
                aicliPaintBackupStrip(user);
                if (_aicliHb && _aicliHb.user === user) {
                    aicliRefreshBackupStatus(user);
                    // A new snapshot just landed: show its Restore button now.
                    aicliRefreshSnapshots(user);
                }
                // Pull the new last_backup into the card once (bounded: only on a real finish).
                if (wasRunning && typeof refreshStats === 'function') refreshStats();
                return;
            }
            _aicliBackupRunning[user] = r.running.step || 'running';
            aicliPaintBackupStrip(user);
            $('#backup-progress-' + rowId).show().find('span').text(r.running.step || 'Running…');
        });
    }, 5000);
}

function aicliBackupNow(user) {
    if (aicliBlockedByConsolidate('starting a home backup')) return false;
    var s = (_aicliHb && _aicliHb.user === user && _aicliHb.settings) ? _aicliHb.settings : ((_aicliHomes[user] || {}).backup || {});
    var warm = s.quiesce === 'warm';
    swal({
        title: 'Back up ' + user + '’s home now?',
        text: warm
            ? 'Warm mode: sessions stay running while the copy is made, best effort.'
            : 'Cold mode: ' + user + '’s running sessions close first, then relaunch once the copy finishes.',
        type: 'info', showCancelButton: true, confirmButtonText: 'Back up now', showLoaderOnConfirm: true, closeOnConfirm: false
    }, function(confirmed) {
        if (!confirmed) return;
        aicliAjax('backup_home', { user: user }, function(r) {
            if (r && r.status === 'ok') {
                swal({ title: 'Queued', text: 'Watch the activity tray for progress.', type: 'info', timer: 2500, showConfirmButton: false });
                _aicliBackupRunning[user] = 'queued';
                aicliPaintBackupStrip(user);
                aicliPollBackupStatus(user);
            } else {
                swal('Could not start', (r && r.message) || 'Unknown error. Check debug.log.', 'error');
            }
            aicliRefreshBackupStatus(user);
        }).fail(function(x, s, e) {
            swal('Could not start', 'Could not reach the server. ' + aicliAjaxReason(x, s, e), 'error');
        });
    });
}

/* ---- The per-home Backup dialog ---------------------------------------- */

function aicliHbIndicator(key) {
    return '<span class="hb-save" id="hb-save-' + key + '" aria-live="polite"></span>';
}

function aicliOpenHomeBackup(user) {
    if (!user) return;
    aicliCloseHomeBackup(false);
    var rowId = aicliBackupRowId(user);
    var e = escapeHtml;
    var dayOpts = '';
    $.each(AICLI_HB_DAYS, function(i, d) { dayOpts += '<option value="' + i + '">' + d + '</option>'; });
    var html =
    '<div id="aicli-home-backup-backdrop" class="hb-backdrop">' +
      '<div id="aicli-home-backup-dialog" class="hb-dialog" role="dialog" aria-modal="true" aria-labelledby="hb-title" data-user="' + e(user) + '">' +
        '<div class="hb-header">' +
          '<h2 id="hb-title" class="hb-title" tabindex="-1"><i class="fa fa-life-ring" aria-hidden="true"></i> Config backup — ' + e(user) + '</h2>' +
          '<button type="button" class="hb-close" id="hb-close" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="hb-body" id="backup-row-' + rowId + '">' +
          '<div class="hb-status" aria-live="polite">' +
            '<div id="hb-state" class="hb-state"></div>' +
            '<div id="backup-last-' + rowId + '" class="hb-muted"></div>' +
            '<div id="backup-progress-' + rowId + '" class="hb-progress" style="display:none;"><i class="fa fa-spinner fa-spin" aria-hidden="true"></i> <span></span></div>' +
          '</div>' +
          '<div id="hb-load" class="hb-muted">Loading backup settings…</div>' +
          '<fieldset class="hb-fields" id="hb-fields" disabled>' +
            '<div class="hb-field">' +
              '<div class="hb-label-row"><span class="hb-label" id="hb-target-label">Destination folder</span>' + aicliHbIndicator('target') + '</div>' +
              '<div class="hb-target-row">' +
                '<code id="hb-target-show" class="hb-target hb-empty" aria-labelledby="hb-target-label">No folder chosen</code>' +
                '<button type="button" class="aicli-btn-slim" id="hb-choose"><i class="fa fa-folder-open" aria-hidden="true"></i> Choose folder…</button>' +
              '</div>' +
              '<input type="hidden" id="hb-target-input" value="">' +
              '<div id="hb-target-check" class="hb-check"></div>' +
              '<div id="hb-suggest" class="hb-suggest" hidden data-testid="hb-suggest">' +
                '<span>Your earlier backups are in <code id="hb-suggest-path"></code></span>' +
                '<button type="button" class="aicli-btn-slim" id="hb-suggest-use">Use this folder</button>' +
              '</div>' +
              '<div class="hb-help">A folder on a /mnt/user share is saved as its pool or disk path: copying a home’s many small files through the share can freeze the server.</div>' +
            '</div>' +
            '<fieldset class="hb-field hb-radios">' +
              '<legend class="hb-label">Mode ' + aicliHbIndicator('quiesce') + '</legend>' +
              '<label><input type="radio" name="hb-quiesce" value="cold"> Cold — closes the sessions first, most consistent</label>' +
              '<label><input type="radio" name="hb-quiesce" value="warm"> Warm — sessions keep running, best effort</label>' +
            '</fieldset>' +
            '<div class="hb-field">' +
              '<div class="hb-label-row"><label class="hb-label" for="hb-keep">Keep</label>' + aicliHbIndicator('keep') + '</div>' +
              '<div class="hb-inline"><input type="number" id="hb-keep" min="1" max="50" step="1" inputmode="numeric"> <span>snapshots</span></div>' +
            '</div>' +
            '<div class="hb-field">' +
              '<div class="hb-label-row"><label class="hb-label" for="hb-sched-mode">Schedule</label>' + aicliHbIndicator('schedule') + '</div>' +
              '<div class="hb-inline hb-wrap">' +
                '<select id="hb-sched-mode"><option value="off">Off</option><option value="daily">Daily</option><option value="weekly">Weekly</option></select>' +
                '<select id="hb-sched-day" aria-label="Day of the week">' + dayOpts + '</select>' +
                '<label class="hb-at" id="hb-sched-at" for="hb-sched-time">at</label>' +
                '<input type="time" id="hb-sched-time" value="02:00">' +
              '</div>' +
            '</div>' +
            '<div class="hb-field">' +
              '<div class="hb-label-row"><span class="hb-label">Continue on relaunch</span>' + aicliHbIndicator('nudge_working') + '</div>' +
              '<label class="hb-check-label"><input type="checkbox" id="hb-nudge"> Tell an agent to continue if it was working when the backup started</label>' +
            '</div>' +
            '<details class="hb-field hb-details">' +
              '<summary>Advanced: excluded files</summary>' +
              '<div class="hb-label-row"><label class="hb-label" for="hb-excludes">Excluded files, one pattern per line</label>' + aicliHbIndicator('excludes') + '</div>' +
              '<textarea id="hb-excludes" rows="5" spellcheck="false"></textarea>' +
              '<div class="hb-help">Saved when you leave the field.</div>' +
            '</details>' +
          '</fieldset>' +
          '<div class="hb-field">' +
            '<div class="hb-label">Snapshots</div>' +
            '<div id="backup-snapshots-' + rowId + '" class="hb-snapshots">Loading snapshots…</div>' +
            '<div id="restore-last-' + rowId + '" class="hb-muted"></div>' +
            '<div id="restore-progress-' + rowId + '" class="hb-progress" style="display:none;"><i class="fa fa-spinner fa-spin" aria-hidden="true"></i> <span></span></div>' +
            '<div class="hb-help">Restore closes every session of ' + e(user) + ', then reopens them when it finishes; a session that was working is told to continue.</div>' +
          '</div>' +
        '</div>' +
        '<div class="hb-footer">' +
          '<button type="button" class="aicli-btn-slim" id="backup-now-' + rowId + '" disabled title="Choose a destination folder first.">Back up now</button>' +
          '<button type="button" class="aicli-btn-slim hb-secondary" id="hb-close-footer">Close</button>' +
        '</div>' +
      '</div>' +
    '</div>';
    $('body').append(html);
    _aicliHb = { user: user, settings: null, earlier: (((_aicliHomes[user] || {}).backup) || {}).earlier_target || '' };

    var dlg = $('#aicli-home-backup-dialog');
    $('#aicli-home-backup-backdrop').on('mousedown', function(ev) { if (ev.target === this) aicliCloseHomeBackup(true); });
    $('#hb-close, #hb-close-footer').on('click', function() { aicliCloseHomeBackup(true); });
    $('#hb-choose').on('click', function() { openPathPicker('hb-target-input'); });
    $('#hb-suggest-use').on('click', function() {
        if (_aicliHb && _aicliHb.earlier) aicliHbSave('target', _aicliHb.earlier);
    });
    $('#hb-target-input').on('change', function() {
        aicliHbSave('target', $(this).val() || '');
        $('#hb-choose').trigger('focus');
    });
    dlg.find('input[name="hb-quiesce"]').on('change', function() { aicliHbSave('quiesce', $(this).val()); });
    $('#hb-keep').on('change', function() { aicliHbSave('keep', $(this).val()); });
    $('#hb-sched-mode, #hb-sched-day, #hb-sched-time').on('change', function() {
        aicliHbToggleSchedule();
        aicliHbSave('schedule', aicliHbScheduleValue());
    });
    $('#hb-nudge').on('change', function() { aicliHbSave('nudge_working', $(this).is(':checked') ? '1' : '0'); });
    $('#hb-excludes').on('change', function() { aicliHbSave('excludes', $(this).val()); });
    $('#backup-now-' + rowId).on('click', function() { aicliBackupNow(user); });

    aicliPaintBackupStrip(user);
    aicliHbLoad(user);
    aicliRefreshBackupStatus(user);
    aicliRefreshSnapshots(user);
    aicliRefreshRestoreStatus(user);
    // Focus the dialog's heading, not a control: a mouse user sees no focus
    // ring, and a keyboard user's first Tab reaches Close (HOME_BACKUP.md
    // 2026-09-24 follow-up — the ring on "Choose folder…" was this focus).
    document.getElementById('hb-title').focus();
}

function aicliCloseHomeBackup(returnFocus) {
    var user = _aicliHb ? _aicliHb.user : null;
    _aicliHb = null;
    $('#aicli-home-backup-backdrop').remove();
    if (returnFocus && user) {
        var b = document.getElementById('home-backup-btn-' + aicliBackupRowId(user));
        if (b) b.focus();
    }
}

function aicliHbLoad(user) {
    $('#hb-load').removeClass('hb-error').text('Loading backup settings…').show();
    aicliAjax('get_home_backup_settings', { user: user }, function(r) {
        if (!_aicliHb || _aicliHb.user !== user) return;
        if (!r || r.status !== 'ok' || !r.settings) { aicliHbLoadFailed(user); return; }
        _aicliHb.settings = r.settings;
        $.each(['target', 'quiesce', 'keep', 'schedule', 'nudge_working', 'excludes'], function(i, k) { aicliHbApply(k); });
        $('#hb-fields').prop('disabled', false);
        $('#hb-load').hide();
        aicliUpdateRowBusyUI(user);
        if (r.settings.target) aicliHbCheckTarget(r.settings.target);
        aicliHbPaintSuggest();
        // Focus stays where it is (the heading): moving it to a control here
        // drew a focus ring on "Choose folder…" for a mouse user.
    }).fail(function() { if (_aicliHb && _aicliHb.user === user) aicliHbLoadFailed(user); });
}

function aicliHbLoadFailed(user) {
    $('#hb-load').addClass('hb-error').html('<span role="alert">Could not load the backup settings.</span> ' +
        '<button type="button" class="aicli-btn-slim" id="hb-retry">Try again</button>').show();
    $('#hb-retry').on('click', function() { aicliHbLoad(user); });
}

// "Your earlier backups are in <path> — Use this folder": only while the home
// has no folder of its own and earlier snapshots were found.
function aicliHbPaintSuggest() {
    var box = document.getElementById('hb-suggest');
    if (!box || !_aicliHb) return;
    var show = !!(_aicliHb.settings && !_aicliHb.settings.target && _aicliHb.earlier);
    $('#hb-suggest-path').text(show ? _aicliHb.earlier : '');
    box.hidden = !show;
}

// Put the SAVED value of one setting into its control.
function aicliHbApply(key) {
    if (!_aicliHb || !_aicliHb.settings) return;
    var s = _aicliHb.settings;
    switch (key) {
        case 'target':
            $('#hb-target-input').val(s.target || '');
            $('#hb-target-show').text(s.target || 'No folder chosen').toggleClass('hb-empty', !s.target);
            break;
        case 'quiesce':
            $('input[name="hb-quiesce"][value="' + (s.quiesce === 'warm' ? 'warm' : 'cold') + '"]').prop('checked', true);
            break;
        case 'keep':
            $('#hb-keep').val(s.keep);
            break;
        case 'schedule':
            var mode = 'off', day = '0', time = '02:00', m;
            if ((m = /^daily:(\d\d:\d\d)$/.exec(s.schedule || ''))) { mode = 'daily'; time = m[1]; }
            else if ((m = /^weekly:(\d):(\d\d:\d\d)$/.exec(s.schedule || ''))) { mode = 'weekly'; day = m[1]; time = m[2]; }
            $('#hb-sched-mode').val(mode);
            $('#hb-sched-day').val(day);
            $('#hb-sched-time').val(time);
            aicliHbToggleSchedule();
            break;
        case 'nudge_working':
            $('#hb-nudge').prop('checked', !!s.nudge_working);
            break;
        case 'excludes':
            $('#hb-excludes').val(s.excludes || '');
            break;
    }
}

function aicliHbToggleSchedule() {
    var mode = $('#hb-sched-mode').val();
    $('#hb-sched-day').toggle(mode === 'weekly');
    $('#hb-sched-at, #hb-sched-time').toggle(mode !== 'off');
}

function aicliHbScheduleValue() {
    var mode = $('#hb-sched-mode').val();
    var time = $('#hb-sched-time').val() || '02:00';
    if (mode === 'daily') return 'daily:' + time;
    if (mode === 'weekly') return 'weekly:' + ($('#hb-sched-day').val() || '0') + ':' + time;
    return 'off';
}

function aicliHbShowCheck(check, path) {
    var box = $('#hb-target-check');
    if (!path) { box.attr('class', 'hb-check').removeAttr('role').empty(); return; }
    if (!check || check.ok === false) {
        box.attr('class', 'hb-check hb-err').attr('role', 'alert')
            .html('<i class="fa fa-times-circle" aria-hidden="true"></i> ' + escapeHtml((check && check.message) || 'This folder cannot be used for backups.'));
        return;
    }
    var free = (typeof check.free_bytes === 'number' && check.free_bytes > 0) ? ' — ' + (check.free_bytes / 1073741824).toFixed(1) + ' GB free' : '';
    box.attr('class', 'hb-check hb-ok').removeAttr('role')
        .html('<i class="fa fa-check-circle" aria-hidden="true"></i> ' + escapeHtml(check.resolved || path) + (check.fs ? ' (' + escapeHtml(check.fs) + ')' : '') + free);
}

// The saved target can go missing (a disk unplugged): check it on open.
function aicliHbCheckTarget(path) {
    aicliAjax('backup_validate_target', { target: path }, function(r) {
        if (!_aicliHb || !_aicliHb.settings || _aicliHb.settings.target !== path) return;
        aicliHbShowCheck(r || null, path);
    }).fail(function() { aicliHbShowCheck({ ok: false, message: 'Could not check this folder.' }, path); });
}

// Save ONE setting now. The control ends up showing what the server read back.
function aicliHbSave(key, value) {
    if (!_aicliHb || !_aicliHb.settings) return;
    var user = _aicliHb.user;
    var ind = $('#hb-save-' + key);
    ind.removeAttr('role').attr('class', 'hb-save hb-saving').text('Saving…');
    var failed = function(msg) {
        if (!_aicliHb || _aicliHb.user !== user) return;
        aicliHbApply(key); // put the last saved value back
        ind.attr('class', 'hb-save hb-err').attr('role', 'alert')
            .html('<i class="fa fa-times-circle" aria-hidden="true"></i> Not saved: ' + escapeHtml(msg));
        if (key === 'target') aicliHbShowCheck({ ok: false, message: msg }, value || '-');
    };
    aicliAjax('set_home_backup_setting', { user: user, key: key, value: value }, function(r) {
        if (!_aicliHb || _aicliHb.user !== user) return;
        if (!r || r.status !== 'ok' || !r.settings) { failed((r && r.message) || 'unknown error'); return; }
        _aicliHb.settings = r.settings;
        aicliHbApply(key);
        ind.attr('class', 'hb-save hb-ok').html('<i class="fa fa-check" aria-hidden="true"></i> Saved');
        if (key === 'target') aicliHbShowCheck(r.check || { ok: true, resolved: r.value }, r.value);
        var h = _aicliHomes[user] = _aicliHomes[user] || {};
        var earlier = r.settings.target ? '' : (_aicliHb.earlier || '');
        h.backup = { configured: !!r.settings.target, target: r.settings.target, schedule: r.settings.schedule, quiesce: r.settings.quiesce, earlier_target: earlier };
        if (key === 'target') {
            aicliHbPaintSuggest();
            // The earlier snapshots may be in the folder just chosen.
            aicliRefreshSnapshots(user);
            aicliRefreshBackupStatus(user);
        }
        aicliPaintBackupStrip(user);
        aicliUpdateRowBusyUI(user);
    }).fail(function() { failed('the server did not answer.'); });
}

// Escape closes the dialog (or only the folder browser on top of it); Tab stays inside.
document.addEventListener('keydown', function(ev) {
    if (!_aicliHb) return;
    if ($('.sweet-alert:visible').length) return; // a confirm is on top: it owns the keys
    var picker = document.getElementById('aicli-path-picker-backdrop');
    if (ev.key === 'Escape') {
        ev.preventDefault();
        ev.stopPropagation();
        if (picker) {
            // One Escape closes one layer: the New folder row, then the browser.
            if (aicliPathPickerEscape() === 'picker') $('#hb-choose').trigger('focus');
            return;
        }
        aicliCloseHomeBackup(true);
        return;
    }
    if (ev.key !== 'Tab' || picker) return;
    var dlg = document.getElementById('aicli-home-backup-dialog');
    if (!dlg) return;
    var items = $(dlg).find('button, [href], input:not([type="hidden"]), select, textarea, summary, [tabindex]:not([tabindex="-1"])')
        .filter(function() { return !this.disabled && $(this).is(':visible'); }).toArray();
    if (!items.length) return;
    var first = items[0], last = items[items.length - 1];
    // Focus outside the dialog, or on its heading (not in the list): the
    // first Tab goes to the first control, Shift+Tab to the last one.
    if (!dlg.contains(document.activeElement) || items.indexOf(document.activeElement) === -1) {
        ev.preventDefault();
        (ev.shiftKey ? last : first).focus();
        return;
    }
    if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
    else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
}, true);

// The folder browser closes by Select or Cancel: give focus back to "Choose folder…".
$(document).on('click', '#pp-cancel, #pp-confirm', function() {
    if (_aicliHb) setTimeout(function() { $('#hb-choose').trigger('focus'); }, 0);
});

/* ---------------------------------------------------------------------------
 * HOME_RESTORE.md R1 — Home restore. Each snapshot row (from `list_backups`)
 * gets a Restore… button; the confirm states the snapshot facts, the mode
 * (replace/merge), and the safety-snapshot choice, then queues `restore_home`
 * and polls `restore_status` every 5s while running — the same cadence and
 * shape aicliPollBackupStatus() already uses above.
 * ------------------------------------------------------------------------- */

// One user's backup snapshots, each with a Restore… button. Called on row
// creation and again after any backup or restore finishes, so a brand-new
// snapshot (or a fresh 'pre-restore' safety snapshot) shows up without a
// manual page reload.
function aicliRefreshSnapshots(user) {
    var rowId = aicliBackupRowId(user);
    var box = $('#backup-snapshots-' + rowId);
    aicliAjax('list_backups', { user: user }, function(r) {
        if (!r || r.status !== 'ok') { box.html('<div style="font-size:10px; opacity:.6;">Snapshot list unavailable.</div>'); return; }
        if (_aicliHb && _aicliHb.user === user) {
            _aicliHb.earlier = r.earlier_target || '';
            aicliHbPaintSuggest();
        }
        aicliRenderSnapshots(user, r.snapshots || []);
    }).fail(function() { box.html('<div style="font-size:10px; opacity:.6;">Snapshot list unavailable.</div>'); });
}

// Draw one user's snapshot rows (Restore… and Delete… on each).
function aicliRenderSnapshots(user, snaps) {
    var rowId = aicliBackupRowId(user);
    var box = $('#backup-snapshots-' + rowId);
    if (snaps.length === 0) { box.html('<div style="font-size:10px; opacity:.6;">No snapshots yet.</div>'); return; }
    var html = '';
    $.each(snaps, function(i, s) {
        var when = s.at ? new Date(s.at).toLocaleString() : 'unknown time';
        var size = (typeof s.bytes === 'number') ? (s.bytes / 1048576).toFixed(1) + ' MB' : '';
        var files = (typeof s.files === 'number') ? (s.files + ' files') : '';
        var mode = s.warm ? 'warm' : 'cold';
        var label = s.label === 'pre-restore' ? ' <span style="color:#f59e0b;">(pre-restore safety snapshot)</span>' : '';
        var bad = (s.ok === false) ? ' <span style="color:#f87171;">(incomplete)</span>' : '';
        var data = 'data-path="' + escapeHtml(s.path) + '" data-at="' + escapeHtml(when) + '" data-warm="' + (s.warm ? '1' : '0') +
                '" data-files="' + escapeHtml(String(files || '')) + '" data-size="' + escapeHtml(String(size || '')) + '"';
        var what = escapeHtml(when) + (size ? ', ' + size : '');
        html += '<div class="aicli-snapshot-row" style="display:flex; justify-content:space-between; align-items:center; gap:8px; font-size:10px; padding:3px 6px; border:1px solid var(--border-color, rgba(128,128,128,0.15)); border-radius:4px;">' +
            '<span>' + escapeHtml(when) + (size ? ' — ' + size : '') + (files ? ', ' + files : '') + ' (' + mode + ')' + label + bad + '</span>' +
            '<span class="aicli-snapshot-actions">' +
              '<button type="button" class="aicli-btn-slim aicli-restore-btn" style="font-size:10px; padding:1px 6px;" ' + data +
                ' aria-label="Restore the snapshot from ' + what + '">Restore…</button>' +
              '<button type="button" class="aicli-btn-slim danger aicli-snap-delete-btn" style="font-size:10px; padding:1px 6px;" ' + data +
                ' aria-label="Delete the snapshot from ' + what + '">Delete…</button>' +
            '</span>' +
        '</div>';
    });
    box.html(html);
    box.find('.aicli-restore-btn').on('click', function() {
        var btn = $(this);
        aicliRestoreHome(user, {
            path: btn.attr('data-path'),
            at: btn.attr('data-at'),
            warm: btn.attr('data-warm') === '1',
            files: btn.attr('data-files'),
            size: btn.attr('data-size')
        });
    });
    box.find('.aicli-snap-delete-btn').on('click', function() {
        var btn = $(this);
        aicliDeleteSnapshot(user, {
            path: btn.attr('data-path'),
            at: btn.attr('data-at'),
            files: btn.attr('data-files'),
            size: btn.attr('data-size')
        }, this);
    });
    aicliUpdateRowBusyUI(user); // a just-added button must respect an already-running job
}

// HOME_BACKUP.md 2026-09-24 follow-up: confirm, then delete ONE snapshot.
// The server removes only that snapshot folder inside the home's backup
// folder, and refuses while a backup or restore of the home runs. The answer
// carries the new list and last backup, so the dialog and the card update at once.
function aicliDeleteSnapshot(user, snap, opener) {
    var facts = escapeHtml(snap.at || 'an unknown time') + (snap.size ? ', ' + escapeHtml(snap.size) : '') + (snap.files ? ', ' + escapeHtml(snap.files) : '');
    swal({
        title: 'Delete this snapshot?',
        text: '<div style="text-align:left; font-size:12px; line-height:1.6;">' +
                '<p>The snapshot of ' + escapeHtml(user) + '’s home from <strong>' + facts + '</strong> is deleted from the backup folder. You cannot restore it after this.</p>' +
                '<p style="font-size:11px; opacity:.8; overflow-wrap:anywhere;">' + escapeHtml(snap.path || '') + '</p>' +
              '</div>',
        html: true,
        type: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete snapshot',
        showLoaderOnConfirm: true,
        closeOnConfirm: false
    }, function(confirmed) {
        if (!confirmed) { if (opener && document.body.contains(opener)) opener.focus(); return; }
        aicliAjax('delete_backup_snapshot', { user: user, snapshot: snap.path }, function(r) {
            if (!r || r.status !== 'ok') {
                swal('Not deleted', (r && r.message) || 'Unknown error. Check debug.log.', 'error');
                return;
            }
            swal({ title: 'Snapshot deleted', text: r.warning || '', type: r.warning ? 'warning' : 'success', timer: r.warning ? null : 1500, showConfirmButton: !!r.warning });
            aicliRenderSnapshots(user, r.snapshots || []);
            var h = _aicliHomes[user] = _aicliHomes[user] || {};
            h.last_backup = r.last_backup || null;
            if (h.backup && !h.backup.configured) h.backup.earlier_target = r.earlier_target || '';
            if (_aicliHb && _aicliHb.user === user) { _aicliHb.earlier = r.earlier_target || ''; aicliHbPaintSuggest(); }
            aicliPaintBackupStrip(user);
            aicliRefreshBackupStatus(user);
            var next = $('#backup-snapshots-' + aicliBackupRowId(user)).find('button:enabled').get(0) || document.getElementById('hb-title');
            if (next) setTimeout(function() { next.focus(); }, 0);
        }).fail(function() { swal('Not deleted', 'The server did not answer. Nothing was deleted.', 'error'); });
    });
}

// One user's last restore record and, while a restore job runs, its progress.
function aicliRefreshRestoreStatus(user) {
    var rowId = aicliBackupRowId(user);
    aicliAjax('restore_status', { user: user }, function(r) {
        var lastEl = $('#restore-last-' + rowId);
        if (!r || r.status !== 'ok') { lastEl.text('Restore status unavailable.'); return; }
        if (r.last_restore) {
            // StorageHandler::restoreStatusFor(): {ok, at, snapshot, mode,
            // safety_snapshot_path, cause}.
            var lr = r.last_restore;
            var when = lr.at ? new Date(lr.at).toLocaleString() : 'unknown time';
            var from = lr.snapshot ? (' from ' + escapeHtml(lr.snapshot)) : '';
            var mode = lr.mode ? (' (' + escapeHtml(lr.mode) + ')') : '';
            if (lr.ok) {
                lastEl.text('Last restore: ' + when + from + mode + (lr.safety_snapshot_path ? ' — safety snapshot kept' : ''));
            } else {
                lastEl.html('<span style="color:#f87171;">Last restore: ' + when + from + mode + ' — failed' +
                    (lr.cause ? ' (' + escapeHtml(lr.cause) + ')' : '') +
                    (lr.safety_snapshot_path ? '. Safety snapshot kept at ' + escapeHtml(lr.safety_snapshot_path) : '') +
                    '</span>');
            }
        } else {
            lastEl.text('No restore yet.');
        }
        var prog = $('#restore-progress-' + rowId);
        _aicliRowBusy[user] = _aicliRowBusy[user] || {};
        _aicliRowBusy[user].restore = !!r.running;
        aicliUpdateRowBusyUI(user);
        if (r.running) {
            prog.show().find('span').text(r.running.step || 'Restoring…');
            aicliPollRestoreStatus(user);
        } else {
            prog.hide();
        }
    }).fail(function() { $('#restore-last-' + rowId).text('Restore status unavailable.'); });
}

var _aicliRestorePollers = {};

// 5s poll while running — same cadence aicliPollBackupStatus() uses.
function aicliPollRestoreStatus(user) {
    if (_aicliRestorePollers[user]) return;
    _aicliRestorePollers[user] = setInterval(function() {
        var rowId = aicliBackupRowId(user);
        aicliAjax('restore_status', { user: user }, function(r) {
            if (!r || r.status !== 'ok' || !r.running) {
                clearInterval(_aicliRestorePollers[user]);
                delete _aicliRestorePollers[user];
                aicliRefreshRestoreStatus(user);
                // R1: refresh the snapshot list after a run — a safety
                // snapshot may have just been added, and the restored
                // snapshot's own row facts (e.g. 'ok') may have changed.
                aicliRefreshSnapshots(user);
                return;
            }
            $('#restore-progress-' + rowId).show().find('span').text(r.running.step || 'Restoring…');
        });
    }, 5000);
}

// Confirm and queue a restore of one snapshot. `snap` is
// {path, at, warm, files, size} — `at`/`files`/`size` are already the
// human-readable strings aicliRefreshSnapshots() built for display.
function aicliRestoreHome(user, snap) {
    if (aicliBlockedByConsolidate('restoring a home')) return false;
    var html =
        '<div style="text-align:left; font-size:12px; line-height:1.6;">' +
            '<p>Snapshot from ' + escapeHtml(snap.at || 'an unknown time') + (snap.warm ? ' (warm — taken while sessions were running)' : ' (cold)') +
                (snap.files ? ', ' + escapeHtml(snap.files) : '') + (snap.size ? ', ' + escapeHtml(snap.size) : '') + '.</p>' +
            (snap.warm ? '<p style="color:#f59e0b;">This snapshot was taken while sessions were running — it may not be perfectly consistent.</p>' : '') +
            '<label style="display:block; margin:6px 0 2px; cursor:pointer;"><input type="radio" name="aicli-restore-mode" value="replace" checked> Replace — the home ends up identical to the snapshot; anything else in the home is removed.</label>' +
            '<label style="display:block; margin:2px 0 8px; cursor:pointer;"><input type="radio" name="aicli-restore-mode" value="merge"> Merge — the snapshot\'s files are copied over the home; nothing else is removed.</label>' +
            '<label style="display:block; margin-bottom:8px; cursor:pointer;"><input type="checkbox" id="aicli-restore-safety" checked> Take a safety snapshot of the current home first (labelled "pre-restore")</label>' +
            '<p style="font-size:11px; opacity:.75;">Every session of ' + escapeHtml(user) + ' closes first and relaunches once the restore finishes; a session that was working is told to continue.</p>' +
        '</div>';
    swal({
        title: 'Restore ' + user + '’s home?',
        text: html,
        html: true,
        type: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Restore',
        showLoaderOnConfirm: true,
        closeOnConfirm: false
    }, function(confirmed) {
        if (!confirmed) return;
        var mode = $('input[name="aicli-restore-mode"]:checked').val() || 'replace';
        var safety = $('#aicli-restore-safety').is(':checked');
        aicliAjax('restore_home', { user: user, snapshot: snap.path, mode: mode, safety_snapshot: safety ? 1 : 0 }, function(r) {
            if (r && r.status === 'ok') {
                swal({ title: 'Queued', text: 'Watch the activity tray for progress.', type: 'info', timer: 2500, showConfirmButton: false });
                clearChanged();
            } else {
                swal('Could not start', (r && r.message) || 'Unknown error. Check debug.log.', 'error');
            }
            aicliRefreshRestoreStatus(user);
        });
    });
}
</script>
