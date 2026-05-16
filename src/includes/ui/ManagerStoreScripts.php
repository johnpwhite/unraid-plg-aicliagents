<?php
/**
 * <module_context>
 * Description: JavaScript logic for agent marketplace in AICliAgents Manager.
 * Dependencies: jQuery, SweetAlert, $csrf_token.
 * Constraints: Atomic UI fragment. Handles version picker, install, update badges.
 * </module_context>
 */
?>
<script>
// Visibility-based button hide/show preserves flex container height so the
// card footer doesn't collapse while the install progress panel is active.
function _hideButtons(el) { el.css({visibility: 'hidden', 'pointer-events': 'none'}); }
function _showButtons(el) { el.css({visibility: '', 'pointer-events': ''}); }

// --- Version Picker & Install ---

// Install a specific version (or latest if no version picker)
function installVersionAgent(id, btn, explicitVersion) {
    // Resolve version. explicitVersion wins over the picker — used by the
    // Upgrade / Update-to-vX.Y.Z buttons so the picker can't pin to the
    // already-installed version and cause a no-op reinstall. Passing the
    // empty string forces "@latest" on the backend.
    var select = document.getElementById('version-select-' + id);
    var version = typeof explicitVersion === 'string'
        ? explicitVersion
        : (select ? select.value : '');
    // Never forward sentinels — AgentRegistry defaults for never-installed,
    // not real versions — they 404 on npm / github_release / tarball.
    if (version === '0.0.0' || version === 'unknown' || version === 'installed') version = '';

    // v2 card uses .av2-card[data-installed]; legacy card uses .agent-item.installed
    var $card = $(btn).closest('.av2-card, .agent-item');
    var isUpdate = $card.attr('data-installed') === '1' || $card.hasClass('installed');

    // Determine action label by comparing against the installed version
    // (which we always read from the picker's data-installed attribute —
    // authoritative even when explicitVersion was passed).
    var installed = select ? (select.getAttribute('data-installed') || '') : '';
    var label = 'Install';
    if (isUpdate && version && installed && version !== installed) {
        var cmp = versionCompare(version, installed);
        label = cmp > 0 ? 'Upgrade' : (cmp < 0 ? 'Downgrade' : 'Reinstall');
    }

    // Fetch active sessions FIRST (single async jump), then show ONE
    // consolidated confirm modal covering both the downgrade warning and
    // the session-close notice. Nested swal() calls inside sweet-alert v1
    // callbacks race with each other — the second modal frequently fails
    // to open, killing the install flow silently. One modal = zero race.
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=list_sessions_for_agent&agentId='
              + encodeURIComponent(id) + '&csrf_token=' + csrf)
        .always(function(data) {
            // $.always receives the response on success and the xhr on fail;
            // defend against both shapes.
            var sessions = (data && data.sessions) ? data.sessions : [];
            _showInstallConfirm(id, version, btn, label, sessions);
        });
}

// Single consolidated confirm modal. Shows the appropriate title + body for
// install / upgrade / downgrade / reinstall, and lists any active sessions
// that will be gracefully closed. One modal, one user decision, one async
// hop to the install endpoint. Keeps the close-sessions list visible so the
// user always knows what's about to happen.
function _showInstallConfirm(id, version, btn, label, sessions) {
    var isInstall = !label || label === 'Install';
    var vLabel = version ? ('v' + version) : 'latest';
    var title, confirmText;
    if (isInstall) {
        title = 'Install ' + vLabel + '?';
        confirmText = 'Install';
    } else {
        title = label + ' to ' + vLabel + '?';
        confirmText = 'Yes, ' + label;
    }

    var body = '';
    if (label === 'Downgrade') {
        body += 'This will replace the current version with an older one.\n\n';
    }
    if (sessions.length > 0) {
        var lines = sessions.map(function(s) {
            var p = s.path || '<no workspace>';
            return '• ' + p + '  (' + (s.id || '').slice(0, 8) + ')';
        }).join('\n');
        body += sessions.length + ' active session' + (sessions.length === 1 ? '' : 's')
              + ' will be gracefully closed:\n\n' + lines
              + "\n\nEach session's resume id is preserved so you can pick up where you left off.";
        confirmText = 'Close & ' + (isInstall ? 'install' : label.toLowerCase());
    } else if (isInstall) {
        body = 'Proceed with installation.';
    }

    // For an Install with no active sessions and no version-change warning,
    // skip the modal entirely — no decision to make.
    if (isInstall && sessions.length === 0) {
        doInstall(id, version, btn, 0);
        return;
    }

    swal({
        title: title,
        text: body,
        type: sessions.length > 0 ? 'warning' : (label === 'Downgrade' ? 'warning' : 'info'),
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
        closeOnConfirm: true,
    }, function(confirmed) {
        if (confirmed) doInstall(id, version, btn, sessions.length);
    });
}

function doInstall(id, version, btn, sessionsToClose) {
    // The click source can be a <button> OR a <select> (version picker). For
    // a select, mutating innerHTML would nuke the options, so we only swap
    // the waiting-state content on real buttons and just disable the select.
    var isSelect = btn && btn.tagName && btn.tagName.toLowerCase() === 'select';
    var originalContent = isSelect ? null : $(btn).html();
    var progress = $('#progress-' + id);
    var bar = $('#bar-' + id);
    var status = $('#status-text-' + id);
    var buttons = $('#buttons-' + id);

    $(btn).prop('disabled', true);
    if (!isSelect) $(btn).html('<i class="fa fa-spinner fa-spin"></i> WAIT...');

    // Render the progress panel IMMEDIATELY on click + broadcast install-
    // start so every other tab (Terminal) transitions to the holding
    // overlay BEFORE the ~1.8 s server round-trip where _closeSessionsForUpgrade
    // does Ctrl-C + sleeps. Previously the broadcast fired inside
    // startInstallPolling which only runs AFTER install_agent returns —
    // leaving a noticeable dead window where the progress bar showed
    // "Starting installation…" and the terminal tab hadn't yet reacted.
    _hideButtons(buttons);
    bar.css('width', '5%');
    // Status text reflects what the server is actually doing in the first
    // ~1.8 s window: if sessions were listed in the confirm modal, the
    // backend is running _closeSessionsForUpgrade (Ctrl-C + sleep + sentinel);
    // otherwise it's starting install-bg directly. The real install-status
    // JSON takes over once polling kicks in.
    if (sessionsToClose && sessionsToClose > 0) {
        status.text('Closing ' + sessionsToClose + ' active session' + (sessionsToClose === 1 ? '' : 's') + '…');
    } else {
        status.text('Preparing installation…');
    }
    progress.removeAttr('style').addClass('active');
    _broadcastInstall('install-start', id, { at: 'doInstall-enter' });

    var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=install_agent&agentId=' + id + '&csrf_token=' + csrf;
    if (version) url += '&version=' + encodeURIComponent(version);

    $.getJSON(url, function(r) {
        if (r.status === 'error') {
            swal('Error', r.message, 'error');
            // Roll back the panel state + restore the click-source + tell
            // every other tab the upgrade is off so they clear the overlay.
            progress.removeClass('active');
            _showButtons(buttons);
            $(btn).prop('disabled', false);
            if (!isSelect) $(btn).html(originalContent);
            _broadcastInstall('install-complete', id, { success: false, message: r.message || 'install refused' });
            return;
        }
        startInstallPolling(id, progress, bar, status, buttons, btn, originalContent);
    }).fail(function(xhr) {
        swal('Error', 'Install request failed: ' + (xhr.statusText || 'network error'), 'error');
        progress.removeClass('active');
        _showButtons(buttons);
        $(btn).prop('disabled', false);
        if (!isSelect) $(btn).html(originalContent);
        _broadcastInstall('install-complete', id, { success: false, message: xhr.statusText || 'network error' });
    });
}

// Legacy wrapper (called from resume-on-load)
function installAgent(id, isUpdate, btn) {
    installVersionAgent(id, btn);
}

// Cross-context broadcast for install lifecycle. The Terminal tab (TSX)
// subscribes to the same channel and reacts in real-time — adding the
// agent to its upgradingAgentIds set on 'install-start' (so the holding
// overlay appears immediately for any session using that agent), and
// removing + bumping session lastActive on 'install-complete' (so the
// iframe re-mounts with fresh ttyd). BroadcastChannel is same-origin and
// supported in every evergreen browser. Falls back silently to the
// existing 2s poll on list_active_installs if the API is unavailable.
var _aicliInstallBC = null;
try { if (typeof BroadcastChannel === 'function') _aicliInstallBC = new BroadcastChannel('aicli-install-events'); }
catch (_e) { _aicliInstallBC = null; }
function _broadcastInstall(type, agentId, extra) {
    if (!_aicliInstallBC) return;
    try { _aicliInstallBC.postMessage(Object.assign({ type: type, agentId: agentId, at: Date.now() }, extra || {})); }
    catch (_err) { /* best-effort */ }
}

function startInstallPolling(id, progress, bar, status, buttons, btn, originalContent) {
    // install-start already broadcast at doInstall entry so the Terminal tab
    // reacts immediately on confirm, not ~2 s later after the AJAX. No-op
    // here; install-complete still fires below on completion / server-side
    // error status.
    var isSelectBtn = btn && btn.tagName && btn.tagName.toLowerCase() === 'select';
    function handleProgress(d) {
        if (d.status === 'error') {
            if (nchanSub) { nchanSub.stop(); nchanSub = null; }
            if (poller) clearInterval(poller);
            swal("Failed", d.message, "error");
            progress.removeClass('active'); _showButtons(buttons);
            _broadcastInstall('install-complete', id, { success: false, message: d.message || '' });
            if (btn) {
                $(btn).prop('disabled', false);
                if (!isSelectBtn) $(btn).html(originalContent);
            }
            return;
        }
        if (typeof d.progress !== 'undefined' && d.progress >= 0) bar.css('width', d.progress + '%');
        if (d.status_text) status.text(d.status_text);
        else if (d.step) status.text(d.step);
        if (d.progress >= 100 || d.completed) {
            if (nchanSub) { nchanSub.stop(); nchanSub = null; }
            if (poller) clearInterval(poller);
            status.text("Finalizing...");
            _broadcastInstall('install-complete', id, { success: d.status !== 'error' });
            setTimeout(function() { safeReload(); }, 1500);
        }
    }

    var nchanSub = null;
    var poller = null;
    if (typeof window.aicli_subscribeInstall === 'function') {
        nchanSub = window.aicli_subscribeInstall(id, handleProgress);
    }
    poller = setInterval(function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_install_status&agentId=' + id + '&csrf_token=' + csrf, handleProgress);
    }, nchanSub ? 5000 : 1000);
}

// --- Version Picker Population ---

var _versionPollTimer = null;
function loadVersionCache() {
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_version_cache&csrf_token=' + csrf, function(r) {
        if (r.status !== 'ok' || !r.dropdowns) return;

        var allHaveData = true;
        $.each(r.dropdowns, function(id, data) {
            populateVersionPicker(id, data);
            updateAgentBadge(id, data);
            // Check if this agent's select exists (installed) but has no version data
            if (document.getElementById('version-select-' + id) && (!data.versions || data.versions.length === 0)) {
                allHaveData = false;
            }
        });

        // If any installed agent is missing version data, poll until the background check fills it in
        // (getVersionCache triggers a background check when it detects stale entries)
        if (!allHaveData) {
            if (!_versionPollTimer) {
                var _pollCount = 0;
                _versionPollTimer = setInterval(function() {
                    _pollCount++;
                    if (_pollCount > 20) { clearInterval(_versionPollTimer); _versionPollTimer = null; return; } // Max 60s
                    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_version_cache&csrf_token=' + csrf, function(r2) {
                        if (r2.status !== 'ok' || !r2.dropdowns) return;
                        var nowAllHaveData = true;
                        $.each(r2.dropdowns, function(id, data) {
                            populateVersionPicker(id, data);
                            updateAgentBadge(id, data);
                            if (document.getElementById('version-select-' + id) && (!data.versions || data.versions.length === 0)) {
                                nowAllHaveData = false;
                            }
                        });
                        if (nowAllHaveData) {
                            clearInterval(_versionPollTimer);
                            _versionPollTimer = null;
                        }
                    });
                }, 3000);
            }
        }
    });
}

function populateVersionPicker(id, data) {
    var select = document.getElementById('version-select-' + id);
    if (!select) return;

    var installed = data.installed || '0.0.0';
    var channel = data.channel || 'latest';
    select.setAttribute('data-installed', installed);
    // Safe clear — avoids innerHTML which the security hook will block.
    while (select.firstChild) select.removeChild(select.firstChild);

    // Empty state (1): server returned no version data yet (cache miss or check
    // error). Keep polling — the background VersionCheckService will fill this in.
    if (!data.versions || data.versions.length === 0) {
        var emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.disabled = true;
        emptyOpt.selected = true;
        var channelLabel = (data.channel === 'beta') ? 'beta' : 'stable';
        emptyOpt.textContent = 'No versions available on this channel (' + channelLabel + ')';
        select.appendChild(emptyOpt);
        select.disabled = true;
        return;
    }
    // Versions are available — ensure the select is usable.
    select.disabled = false;

    // Platform filter: Unraid runs x86_64 Linux, so any version tagged with
    // a non-Linux OS (win32, darwin, freebsd) or non-x64 arch (arm64, aarch64,
    // arm32, armv7, ppc64, s390x, riscv) can't execute here. Observed on agents
    // like Pi Coder whose npm dist-tags expose per-platform binaries.
    // Untagged versions (generic releases) are always kept.
    var compatibleVersions = data.versions.filter(function(v) {
        var tags = v.tags || [];
        if (tags.length === 0) return true;
        return tags.every(function(t) {
            t = String(t).toLowerCase();
            if (/(^|[-_.])(win32|windows|darwin|mac|macos|freebsd|netbsd|openbsd|sunos)($|[-_.])/.test(t)) return false;
            if (/(^|[-_.])(arm64|aarch64|arm32|armv7l?|armv6l?|armhf|ppc64|ppc64le|s390x|riscv|mips)($|[-_.])/.test(t)) return false;
            return true;
        });
    });

    // Pre-release filter: on the 'latest' channel hide versions whose dist-tags
    // are exclusively pre-release markers. Codex-CLI exposes alpha-linux-x64,
    // alpha, etc. in its npm dist-tags — they should not appear in a stable picker.
    // A version with no tags (untagged) or with at least one non-pre-release tag
    // (e.g. 'latest') is always kept.
    var preReleaseRe = /alpha|beta|canary|nightly|snapshot|next(?:-\d|$)|rc(?:\d|$)|dev[-_]build|pre[-_]?release/i;
    if (channel === 'latest' || channel === 'stable') {
        compatibleVersions = compatibleVersions.filter(function(v) {
            var tags = v.tags || [];
            if (tags.length === 0) return true;
            return !tags.every(function(t) { return preReleaseRe.test(String(t)); });
        });
    }

    // Empty state (2): server returned versions but all were filtered out by
    // platform or pre-release rules for this channel (e.g. pi-coder on beta:
    // the upstream has versions but none survive the stable-channel pre-release
    // filter). Show a disabled placeholder so the user isn't left with a blank
    // picker — no polling needed since the data arrived and filtering is definitive.
    if (compatibleVersions.length === 0) {
        var emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.disabled = true;
        emptyOpt.selected = true;
        var channelLabel = (data.channel === 'beta') ? 'beta' : 'stable';
        emptyOpt.textContent = 'No versions available on this channel (' + channelLabel + ')';
        select.appendChild(emptyOpt);
        select.disabled = true;
        return;
    }

    compatibleVersions.forEach(function(v) {
        var opt = document.createElement('option');
        opt.value = v.version;
        // Always prefix with 'v' for consistency with the rest of the card
        // (title badge, stats dl, etc. all render v1.2.3).
        var label = 'v' + v.version;
        // De-dup: non-NPM agents emit tags=['installed'] as their source-of-truth,
        // but we also auto-append "(installed)" for the installed-version row.
        var tags = (v.tags || []).filter(function(t) { return t !== 'installed'; });
        if (tags.length > 0) {
            label += ' ' + tags.map(function(t) { return '[' + t + ']'; }).join(' ');
        }
        if (v.version === installed) label += ' (installed)';
        opt.textContent = label;
        if (v.version === installed) opt.selected = true;
        select.appendChild(opt);
    });

    // If installed version isn't in the list, add it at the top —
    // BUT skip the "0.0.0" sentinel that AgentRegistry returns for never-installed
    // agents. Otherwise the picker auto-selects 0.0.0 and Install passes that as
    // the NPM target, which always 404s (Pi Coder install-fail root cause).
    if (installed && installed !== '0.0.0' && installed !== 'unknown' &&
        !Array.from(select.options).some(function(o) { return o.value === installed; })) {
        var opt = document.createElement('option');
        opt.value = installed;
        opt.textContent = 'v' + installed + ' (installed)';
        opt.selected = true;
        select.insertBefore(opt, select.firstChild);
    }
}

function updateAgentBadge(id, data) {
    var item = $('[data-id="' + id + '"]');
    if (!item.length) return;

    var actionBtn = item.find('.agent-action-btn');
    if (!actionBtn.length) return;

    if (data.update) {
        item.addClass('has-update').removeClass('has-downgrade');
        // Update the action button
        actionBtn.removeClass('danger').addClass('info')
            .html('<i class="fa fa-arrow-circle-up"></i> UPGRADE to v' + data.update.available);
        // Pre-select the upgrade version in the dropdown so the install uses it
        var select = document.getElementById('version-select-' + id);
        if (select) { select.value = data.update.available; }
        // Update badge in header
        var meta = item.find('.agent-meta');
        if (!meta.find('.update-avail').length) {
            meta.append('<span class="agent-status-badge update-avail"><i class="fa fa-arrow-circle-up"></i> v' + data.update.available + '</span>');
        }
    }
}

// Version picker change handler. Fires the install directly with the chosen
// version (which installVersionAgent turns into install, upgrade, downgrade,
// or reinstall depending on the comparison to the currently-installed
// version). Works uniformly for every source type — NpmSource, GithubRelease
// Source, CurlInstallSource, and TarballSource all accept an explicit
// $targetVersion through the AgentSource::fetch interface.
function onVersionSelect(select) {
    var id = select.getAttribute('data-agent');
    var version = select.value;
    var installed = select.getAttribute('data-installed');
    if (!id || !version) return;
    // Strip the sentinel from entry labels; never forward empty/sentinel targets
    if (version === '0.0.0' || version === 'unknown' || version === 'installed') return;
    // Re-picking the current version is a no-op (prevents spurious reinstalls
    // when the <select> is rebuilt after a refresh and fires a synthetic change).
    if (version === installed) return;

    // Save the channel hint (latest/beta) inferred from the option's tag so
    // subsequent update checks consult the right dist-tag.
    var selectedOpt = select.options[select.selectedIndex];
    var text = selectedOpt ? selectedOpt.textContent : '';
    var tagMatch = text.match(/\[(\w+)\]/);
    var channel = tagMatch ? tagMatch[1] : 'latest';
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=set_agent_channel&agentId='
              + encodeURIComponent(id) + '&channel=' + encodeURIComponent(channel)
              + '&csrf_token=' + csrf);

    // Use the picker itself as the click-source for button-state UI. Works
    // on both v2 cards (.av2-card) and the legacy card layout.
    installVersionAgent(id, select, version);
}

// Simple semver compare (returns -1, 0, 1)
function versionCompare(a, b) {
    var pa = a.replace(/[^0-9.]/g, '').split('.').map(Number);
    var pb = b.replace(/[^0-9.]/g, '').split('.').map(Number);
    for (var i = 0; i < Math.max(pa.length, pb.length); i++) {
        var na = pa[i] || 0, nb = pb[i] || 0;
        if (na > nb) return 1;
        if (na < nb) return -1;
    }
    return 0;
}

// --- Page Load ---

$(function() {
    // Resume in-progress installations. v2 cards use .av2-install-panel.active.
    $('.av2-install-panel.active').each(function() {
        var id = this.id.replace('progress-', '');
        if (!id) return;
        var bar = $('#bar-' + id);
        var status = $('#status-text-' + id);
        var progress = $(this);
        var buttons = $('#buttons-' + id);
        _hideButtons(buttons);

        // Quick check: if install already completed, just reload immediately
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_install_status&agentId=' + id + '&csrf_token=' + csrf, function(d) {
            if (d.progress >= 100 || d.completed || d.progress < 0) {
                // Already done or no status — hide bar and reload
                progress.removeClass('active'); _showButtons(buttons);
                safeReload();
            } else {
                // Still in progress — start polling
                startInstallPolling(id, progress, bar, status, buttons, null, '');
            }
        }).fail(function() {
            // Status check failed — start polling anyway
            startInstallPolling(id, progress, bar, status, buttons, null, '');
        });
    });

    // Load version cache and populate pickers
    loadVersionCache();
});

// --- Existing Functions ---

function uninstallAgent(id, btn) {
    swal({ title: "Uninstall " + id + "?", text: "This will remove the agent and its storage data.", type: "warning", showCancelButton: true, confirmButtonText: "Uninstall", showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=uninstall_agent&agentId=' + id + '&csrf_token=' + csrf, function(r) {
            if (r && r.status === 'ok') {
                swal({ title: "Uninstalled", text: id + " has been removed.", type: "success", timer: 1500, showConfirmButton: false });
                setTimeout(function() { safeReload(); }, 1600);
            } else {
                swal("Uninstall Failed", (r && r.message) || "Unknown error. Check debug.log", "error");
            }
        }).fail(function() {
            swal("Uninstall Failed", "Server communication error. Check debug.log", "error");
        });
    });
}

function setAgentFilter(filter, el) {
    agentFilter = filter;
    localStorage.setItem('aicli_agent_filter', filter);
    $('.filter-btn').removeClass('active');
    $(el).addClass('active');
    filterAgents();
}

// Chrome's autofill fires asynchronously after the page's initial JS runs,
// so autocomplete="off" alone doesn't block it — the username "root" keeps
// appearing in the agent-search input. Defence in depth:
//   1) On DOMContentLoaded + a 200ms delay, wipe any value Chrome may have filled.
//   2) Register a one-shot "change" listener that wipes again if autofill slips through.
//   3) Re-run filterAgents after the wipe so the card grid stays in sync.
$(function() {
    function killAutofill() {
        var el = document.getElementById('agent-search-input');
        if (!el) return;
        if (el.value && el.value !== el.dataset.userTyped) {
            el.value = '';
            if (typeof filterAgents === 'function') filterAgents();
        }
    }
    setTimeout(killAutofill, 200);
    setTimeout(killAutofill, 800);
    $('#agent-search-input').on('input', function() {
        // Record user-typed values so the autofill killer doesn't wipe them.
        this.dataset.userTyped = this.value;
    });

    // Restore filter button visual state (agentFilter itself is already seeded
    // from localStorage in ManagerGlobalState.php on page load).
    if (agentFilter !== 'all') {
        $('.filter-btn').removeClass('active');
        $('.filter-btn').each(function() {
            var m = ($(this).attr('onclick') || '').match(/setAgentFilter\('([^']+)'/);
            if (m && m[1] === agentFilter) { $(this).addClass('active'); return false; }
        });
    }
    // Restore search input — set userTyped so killAutofill won't clear it.
    var savedSearch = localStorage.getItem('aicli_agent_search') || '';
    if (savedSearch) {
        var si = document.getElementById('agent-search-input');
        if (si) { si.value = savedSearch; si.dataset.userTyped = savedSearch; }
    }
    if (agentFilter !== 'all' || savedSearch) filterAgents();
});

function filterAgents() {
    const search = ($('#agent-search-input').val() || '').trim().toLowerCase();
    localStorage.setItem('aicli_agent_search', search);
    const cards = $('.av2-card');
    // v2 selector: .av2-card. installed/update state lives on data-* attrs.
    // Count match decisions inline — `cards.filter(':visible').length` polls
    // the DOM's computed visibility which can return 0 during initial render
    // (before CSS grid layout assigns offsetWidth/Height to the cards), so
    // the empty-state banner could appear even when every card matches the
    // filter. Counting from our own show/hide flag is layout-agnostic.
    let visible = 0;
    cards.each(function() {
        const item = $(this);
        const name = (item.data('name') || '').toLowerCase();
        const isInstalled = item.attr('data-installed') === '1';
        const hasUpdate = item.attr('data-has-update') === '1';
        let show = !search || name.includes(search);
        if (show) {
            if (agentFilter === 'installed' && !isInstalled) show = false;
            if (agentFilter === 'updates' && !hasUpdate) show = false;
        }
        if (show) { item.show(); visible++; } else { item.hide(); }
    });
    // Safety net: if the filter matched zero visible cards but there are cards
    // in the DOM (e.g. Chrome autofill put junk in the search input), surface a
    // one-off clear button rather than leaving the user staring at an empty grid.
    var $banner = $('#agent-search-empty-banner');
    if (visible === 0 && cards.length > 0 && (search || agentFilter !== 'all')) {
        if (!$banner.length) {
            $banner = $('<div id="agent-search-empty-banner" style="padding: 16px; text-align: center; color: var(--text-color); opacity: 0.65; grid-column: 1/-1; border: 1px dashed var(--border-color); border-radius: 8px;">No agents match <strong id="agent-search-empty-q"></strong>. <button type="button" class="av2-btn ghost" onclick="document.getElementById(\'agent-search-input\').value=\'\'; setAgentFilter(\'all\', document.querySelector(\'.filter-btn\')); filterAgents();" style="margin-left: 10px;">Clear search</button></div>');
            $('#agent-store-grid').append($banner);
        }
        $('#agent-search-empty-q').text(search ? '"' + search + '"' : 'the current filter');
        $banner.show();
    } else if ($banner.length) {
        $banner.hide();
    }
}

function checkUpdates(btn) {
    if (btn) {
        var $b = $(btn);
        $b.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Checking...');
    }
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=check_versions&csrf_token=' + csrf, function(r) {
        if (btn) $(btn).prop('disabled', false).html('<i class="fa fa-refresh"></i> Check for Updates');
        if (r.status === 'ok') {
            loadVersionCache(); // Refresh dropdowns with new data
        }
    }).fail(function() {
        if (btn) $(btn).prop('disabled', false).html('<i class="fa fa-refresh"></i> Check for Updates');
        swal("Error", "Failed to check for updates. Check debug.log", "error");
    });
}

function toggleAgentConfig(id, el) {
    const panel = $('#config-panel-' + id);
    if (panel.hasClass('collapsed')) panel.removeClass('collapsed').hide().slideDown(200);
    else panel.slideUp(200, function() { panel.addClass('collapsed'); });
}

// ==========================================================================
// Agent Card v2 — chip accordion, diff-detect rows, panel lazy-load + save.
// All dynamic HTML values flow through av2esc() for HTML-entity escaping
// before being written into the DOM.
// ==========================================================================

function av2ChipToggle(chip) {
    const card = chip.closest('.av2-card');
    if (!card) return;
    const target = chip.getAttribute('data-target');
    const already = chip.getAttribute('aria-expanded') === 'true';

    card.querySelectorAll('.av2-chip').forEach(c => c.setAttribute('aria-expanded', 'false'));
    card.querySelectorAll('.av2-panel').forEach(p => p.classList.remove('open'));

    if (already) return;
    chip.setAttribute('aria-expanded', 'true');
    const panel = card.querySelector('.av2-panel[data-panel="' + target + '"]');
    if (!panel) return;
    panel.classList.add('open');

    const agentId = card.getAttribute('data-agent');
    if (target === 'terminal' && !panel.dataset.loaded) { av2LoadTmuxPanel(agentId, panel); panel.dataset.loaded = '1'; }
    // Storage lives inside the Resources (runtime) panel now — lazy-load its body
    // on first open. Re-opening the chip reuses the cached render.
    // Note arg order: av2LoadStoragePanel(panelOrBody, agentId) — passing them
     // the other way round silently throws (strings have no classList) and the
     // "Loading…" placeholder stays forever.
     if (target === 'runtime' && !panel.dataset.storageLoaded) { av2LoadStoragePanel(panel, agentId); panel.dataset.storageLoaded = '1'; }
}

function av2DiffCheck(el) {
    const row = el.closest('.av2-row');
    if (!row) return;
    const def = row.dataset.agentDefault ?? row.dataset.builtin ?? '';
    row.classList.toggle('modified', String(el.value) !== String(def));
}
function av2ResetRow(btn, def) {
    const row = btn.closest('.av2-row');
    if (!row) return;
    const field = row.querySelector('input, select');
    if (!field) return;
    field.value = def;
    row.classList.remove('modified');
    field.dispatchEvent(new Event('change', { bubbles: true }));
}

function av2SaveAgentSetting(el) {
    const form = document.getElementById('aicli-settings-form');
    if (form && typeof saveAICliAgentsManager === 'function') saveAICliAgentsManager(form, true);
}

// Persist the selected release channel for an agent. Fires from the
// Stable / Beta / Pinned radio buttons. Server-side: set_agent_channel.
// On success, loadVersionCache() re-fetches get_version_cache, which calls
// VersionCheckService::getAvailableVersions — filtered server-side by the
// newly-saved channel — and repopulates the version dropdown via
// populateVersionPicker(). This is the required channel-change dropdown
// refresh (WP #264 Task 4): no separate re-fetch step is needed.
function av2SetChannel(agentId, channel) {
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=set_agent_channel&agentId=' + encodeURIComponent(agentId) + '&channel=' + encodeURIComponent(channel) + '&csrf_token=' + csrf, function(r) {
        if (r && r.status === 'ok') {
            // Re-fetch version cache so dropdown options reflect the new channel's
            // available versions (server-side filter in getAvailableVersions).
            if (typeof loadVersionCache === 'function') loadVersionCache();
        } else if (r && r.message) {
            swal('Error', r.message, 'error');
        }
    });
}

function av2SaveSecrets(form, event) {
    if (event) event.preventDefault();
    const agentId = form.dataset.agent;
    // Unraid nginx breaks multipart POSTs (see memory: feedback_unraid_multipart_hang).
    // Build a plain object that jQuery will urlencode for us.
    //
    // Field semantics (WP #736 follow-up): SEND every field EXCEPT those still
    // showing the masked '••••••••' placeholder (= set + untouched → server
    // keeps it). An empty value is sent verbatim and means "the user cleared
    // this field" → server deletes the key. Previously empty fields were
    // skipped, so clearing a secret was a silent no-op.
    const payload = { csrf_token: csrf };
    for (const [k, v] of new FormData(form)) {
        if (v === '••••••••') continue;   // untouched + set → don't touch it
        payload[k] = v;                    // '' (cleared) or a real value
    }
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=save_vault',
        method: 'POST', data: payload, dataType: 'json',
        success: function(r) {
            if (r && r.status === 'ok') {
                if (typeof swal === 'function') swal({title: 'Saved', text: 'Secrets updated for ' + agentId, type: 'success', timer: 1300, showConfirmButton: false});
                if (typeof av2HotApplyAgent === 'function') av2HotApplyAgent(agentId);
            } else {
                if (typeof swal === 'function') swal('Error', (r && r.message) || 'Save failed', 'error');
            }
        },
        error: function(xhr) {
            if (typeof swal === 'function') swal('Error', 'Failed to save secrets: ' + (xhr.responseText || xhr.statusText), 'error');
        }
    });
    return false;
}

// ── WP #736: free-form agent-level env vars + secrets (the "Variables" and
// "Secrets" sub-sections in the ENVS panel). add/edit/delete + hot-apply.

// Append a blank row to the free-form list inside the form the Add button lives in.
function av2FfAddRow(btn, kind) {
    var form = btn.closest('.av2-ff-form');
    if (!form) return;
    var list = form.querySelector('.av2-ff-list');
    if (!list) return;
    var empty = list.querySelector('.av2-ff-empty');
    if (empty) empty.remove();
    var nameInput = av2mkel('input', {
        'class': 'av2-ff-name', type: 'text',
        placeholder: kind === 'secret' ? 'SECRET_NAME' : 'VARIABLE_NAME',
        oninput: function(e){ e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g,''); }
    });
    var valInput = av2mkel('input', {
        'class': 'av2-ff-val',
        type: kind === 'secret' ? 'password' : 'text',
        'data-has-value': kind === 'secret' ? '0' : null,
        placeholder: 'value'
    });
    var delBtn = av2mkel('button', { type: 'button', 'class': 'av2-ff-del', title: 'Remove', onclick: function(){ av2FfRemoveRow(delBtn); } },
        [av2mkel('i', { 'class': 'fa fa-trash-o' })]);
    var row = av2mkel('div', { 'class': 'av2-ff-row' }, [nameInput, av2mkel('span', { 'class': 'av2-ff-eq' }, ['=']), valInput, delBtn]);
    list.appendChild(row);
    nameInput.focus();
}

function av2FfRemoveRow(btn) {
    var row = btn.closest('.av2-ff-row');
    if (row) row.remove();
}

// Collect [{name, value, hasValue}] from a free-form form's rows.
function av2FfCollect(form) {
    var out = {};
    form.querySelectorAll('.av2-ff-row').forEach(function(row) {
        var name = (row.querySelector('.av2-ff-name') || {}).value || '';
        name = name.trim();
        if (!name) return;
        var valEl = row.querySelector('.av2-ff-val');
        out[name] = valEl ? valEl.value : '';
    });
    return out;
}

// After an agent-tier env/secret save, restart the agent's running sessions
// in place (Ctrl-C → relaunch, picks up the new env via aicli-shell.sh's
// _aicli_load_envs). Workspace/tmux/ttyd are untouched. Reuses the existing
// agent_signal_reload + list_sessions_for_agent endpoints.
function av2HotApplyAgent(agentId) {
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=list_sessions_for_agent&agentId='
              + encodeURIComponent(agentId) + '&csrf_token=' + csrf, function(r) {
        var sessions = (r && r.sessions) || [];
        sessions.forEach(function(s) {
            if (!s || !s.id) return;
            $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=agent_signal_reload&id='
                      + encodeURIComponent(s.id) + '&csrf_token=' + csrf);
        });
        if (sessions.length > 0 && typeof swal === 'function') {
            // brief, non-blocking
            swal({ title: 'Applied', text: 'Restarted ' + sessions.length + ' running session(s) with the new env.', type: 'success', timer: 1800, showConfirmButton: false });
        }
    });
}

function av2FfSave(form, event, action, agentId, label) {
    if (event) event.preventDefault();
    var map = av2FfCollect(form);
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=' + action + '&agentId=' + encodeURIComponent(agentId) + '&csrf_token=' + csrf,
        method: 'POST',
        data: { csrf_token: csrf, envs: JSON.stringify(map), secrets: JSON.stringify(map) },
        dataType: 'json',
        success: function(r) {
            if (r && r.status === 'ok') {
                if (typeof swal === 'function') swal({ title: 'Saved', text: label + ' updated for ' + agentId, type: 'success', timer: 1300, showConfirmButton: false });
                av2HotApplyAgent(agentId);
            } else {
                if (typeof swal === 'function') swal('Error', (r && r.message) || 'Save failed', 'error');
            }
        },
        error: function(xhr) {
            if (typeof swal === 'function') swal('Error', 'Save failed: ' + (xhr.responseText || xhr.statusText || 'network error'), 'error');
        }
    });
    return false;
}

function av2SaveAgentEnvs(form, event) {
    return av2FfSave(form, event, 'save_agent_envs', form.dataset.agent, 'Variables');
}
function av2SaveAgentSecrets(form, event) {
    return av2FfSave(form, event, 'save_agent_secrets', form.dataset.agent, 'Secrets');
}

// DOM builder helpers — avoid innerHTML with any interpolated user data.
function av2mkel(tag, attrs, children) {
    const el = document.createElement(tag);
    for (const k in (attrs || {})) {
        if (attrs[k] == null) continue;
        if (k === 'class') el.className = attrs[k];
        else if (k.startsWith('on')) el[k] = attrs[k];
        else el.setAttribute(k, attrs[k]);
    }
    (children || []).forEach(c => {
        if (c == null) return;
        el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
}

// Known option sets for tmux keys — fields with a finite value set render as
// <select> dropdowns; free-form fields (history-limit, prefix, base-index,
// default-terminal) stay as text inputs. Matches tmux's own value enumerations.
const AV2_TMUX_OPTIONS = {
    'status':            ['on', 'off'],
    'mouse':             ['on', 'off'],
    'focus-events':      ['on', 'off'],
    'allow-passthrough': ['on', 'off'],
    'bell-action':       ['any', 'none', 'current', 'other'],
};

// Short tooltips per tmux key — shown on hover of the (i) icon next to each
// row. Sourced from tmux(1) man page; kept under ~120 chars so they render
// well in native title tooltips without wrapping oddly.
const AV2_TMUX_HELP = {
    'status':            'Show the status bar at the bottom of the terminal.',
    'mouse':             'Enable mouse support: click to select pane/window, drag to resize, scroll for history.',
    'history-limit':     'Lines of scrollback kept per pane. Higher = more memory per session.',
    'prefix':            'Command prefix key (default C-b). Every tmux keybinding is triggered by this combo first.',
    'base-index':        'Index of the first window — 0 matches shell conventions, 1 matches keyboard number row.',
    'bell-action':       'Which pane triggers a bell alert: any, none, current (focused only), other (unfocused only).',
    'default-terminal':  'TERM value exported to programs. screen-256color is safe; tmux-256color enables italics.',
    'focus-events':      'Forward focus-gained/focus-lost escape codes to apps (needed for vim/nvim auto-reload).',
    'allow-passthrough': 'Allow OSC escape sequences to pass through tmux — enables inline images, hyperlinks.',
};

function av2LoadTmuxPanel(agentId, panel) {
    const body = panel.querySelector('.av2-tmux-form');
    if (!body) return;
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=tmux_get_agent_defaults&agentId=' + encodeURIComponent(agentId) + '&csrf_token=' + csrf, function(r) {
        body.textContent = '';
        if (r.status !== 'ok') {
            body.appendChild(av2mkel('div', {class: 'av2-help'}, ['Failed to load: ' + (r.message || 'unknown error')]));
            return;
        }
        const agentDefs = r.settings || {};
        const builtin = r.builtin || {};
        const keys = r.allowedKeys || Object.keys(builtin);

        const help = av2mkel('p', {class: 'av2-help', style: 'margin:-4px 0 12px;'}, [
            'Changes auto-save. Rows differing from the plugin built-in default are flagged (●) — only divergent keys persist.'
        ]);
        body.appendChild(help);

        keys.forEach(function(key) {
            const b = String(builtin[key] ?? '');
            const cur = String(agentDefs[key] ?? b);
            const isMod = cur !== b;

            let field;
            if (AV2_TMUX_OPTIONS[key]) {
                // Enum field — render as a <select> with all valid options.
                field = av2mkel('select', {name: key}, AV2_TMUX_OPTIONS[key].map(opt => {
                    const o = av2mkel('option', {value: opt}, [opt]);
                    if (opt === cur) o.selected = true;
                    return o;
                }));
            } else {
                // Free-form — text input.
                field = av2mkel('input', {type: 'text', name: key, value: cur});
            }

            // Auto-save on any change. Debounced so rapid typing doesn't spam AJAX.
            const onFieldChange = () => {
                av2DiffCheck(field);
                av2ScheduleTmuxSave(body, agentId);
            };
            field.addEventListener('input', onFieldChange);
            field.addEventListener('change', onFieldChange);

            // Per-row revert button removed — users can edit the value directly
            // or use "Reset all to built-in" in the footer. Diff dot (●) on the
            // left gutter still flags divergent rows.
            const helpText = AV2_TMUX_HELP[key] || '';
            const tipBody = helpText ? (helpText + (b ? '  ·  Built-in: ' + b : '')) : ('Built-in: ' + b);
            // data-tip drives a CSS ::after tooltip (see ManagerStyles .av2-info)
            // so we don't depend on the native title timer, which can be swallowed
            // by Unraid's legacy tooltip plugins on some pages.
            const infoIcon = av2mkel('span', {
                class: 'av2-info',
                'data-tip': tipBody,
                'aria-label': tipBody,
                tabindex: '0',
            }, ['i']);
            const row = av2mkel('div', {class: 'av2-row' + (isMod ? ' modified' : ''), 'data-builtin': b}, [
                av2mkel('label', {}, [key]),
                av2mkel('div', {}, [field]),
                infoIcon,
            ]);
            body.appendChild(row);
        });

        body.appendChild(av2mkel('p', {class: 'av2-help'}, [
            'Applied every time this agent launches in any workspace. Workspaces can override any field from the workspace drawer.'
        ]));

        const resetAll = av2mkel('button', {type: 'button', class: 'av2-btn danger'}, ['Reset all to built-in']);
        resetAll.addEventListener('click', () => {
            av2ResetAllTmux(resetAll);
            av2ScheduleTmuxSave(body, agentId);
        });
        // Auto-save hint (replaces the prior manual Save button — users no longer
        // need to click anything to persist changes).
        const savedNote = av2mkel('span', {class: 'av2-save-note', 'data-role': 'save-note'}, ['Auto-saves on change']);
        body.appendChild(av2mkel('div', {class: 'av2-panel-footer'}, [savedNote, resetAll]));
        av2LoadAutoLaunchSection(agentId, body);
    }).fail(function() {
        body.textContent = '';
        body.appendChild(av2mkel('div', {class: 'av2-help'}, ['Network error loading tmux defaults.']));
    });
}

// Debounced auto-save (500ms). Guards against rapid-fire AJAX from typing in a
// text field. A small toast-like note updates in the panel footer: saving... → saved ✓
const _av2TmuxSaveTimers = new Map();
function av2ScheduleTmuxSave(form, agentId) {
    const prior = _av2TmuxSaveTimers.get(agentId);
    if (prior) clearTimeout(prior);
    _av2TmuxSaveTimers.set(agentId, setTimeout(() => {
        _av2TmuxSaveTimers.delete(agentId);
        av2SaveAgentTmuxQuiet(form, agentId);
    }, 500));
}

// Same as av2SaveAgentTmux but updates the inline "Auto-saves on change" note
// instead of popping a SweetAlert — auto-save shouldn't interrupt the user.
function av2SaveAgentTmuxQuiet(form, agentId) {
    const note = form.querySelector('[data-role="save-note"]');
    if (note) note.textContent = 'Saving…';
    const settings = {};
    form.querySelectorAll('.av2-row').forEach(function(row) {
        const input = row.querySelector('input, select');
        if (!input) return;
        settings[input.name] = input.value;
    });
    // Urlencoded form — Unraid nginx chokes on multipart boundaries on this
    // path, which was the root cause of the "Save failed" toast (memory:
    // feedback_unraid_multipart_hang).
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=tmux_save_agent_defaults',
        method: 'POST',
        data: { agentId: agentId, settings: JSON.stringify(settings), csrf_token: csrf },
        success: function(r) {
            if (r && r.status === 'ok') {
                if (note) {
                    note.textContent = 'Saved ✓';
                    note.classList.add('ok');
                    setTimeout(() => { note.textContent = 'Auto-saves on change'; note.classList.remove('ok'); }, 1800);
                }
                // Update chip state indicator (has-custom dot) based on current
                // modified-row count.
                const card = form.closest('.av2-card');
                const chip = card && card.querySelector('.av2-chip[data-target="terminal"]');
                if (chip) {
                    const modCount = form.querySelectorAll('.av2-row.modified').length;
                    chip.classList.toggle('has-custom', modCount > 0);
                }
            } else if (note) {
                note.textContent = 'Save failed';
                note.classList.add('bad');
            }
        },
        error: function() {
            if (note) { note.textContent = 'Save failed — network error'; note.classList.add('bad'); }
        }
    });
}

function av2SaveAgentTmux(btn, agentId) {
    const form = btn.closest('.av2-tmux-form');
    const settings = {};
    form.querySelectorAll('.av2-row').forEach(function(row) {
        const input = row.querySelector('input, select');
        if (!input) return;
        settings[input.name] = input.value;
    });
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=tmux_save_agent_defaults',
        method: 'POST',
        data: { agentId: agentId, settings: JSON.stringify(settings), csrf_token: csrf },
        success: function(r) {
            if (r && r.status === 'ok') {
                swal({title: 'Saved', text: 'Terminal defaults for ' + agentId, type: 'success', timer: 1500, showConfirmButton: false});
                const card = btn.closest('.av2-card');
                const chip = card && card.querySelector('.av2-chip[data-target="terminal"] .av2-v');
                if (chip) {
                    let n = 0;
                    form.querySelectorAll('.av2-row.modified').forEach(function(){ n++; });
                    chip.className = 'av2-v' + (n === 0 ? ' muted' : '');
                    chip.textContent = n === 0 ? 'built-in' : (n + ' custom');
                }
            } else {
                swal('Error', (r && r.message) || 'Save failed', 'error');
            }
        },
        error: function(xhr) { swal('Error', xhr.responseText || 'Network error', 'error'); }
    });
}

function av2ResetAllTmux(btn) {
    const form = btn.closest('.av2-tmux-form');
    form.querySelectorAll('.av2-row').forEach(function(row) {
        const input = row.querySelector('input, select');
        if (!input) return;
        input.value = row.dataset.builtin ?? '';
        row.classList.remove('modified');
    });
}

// ----- Auto-Launch workspace section (appended to Terminal chip panel) -----

function av2LoadAutoLaunchSection(agentId, panelBody) {
    // Sanitise an agent/workspace id for use as an HTML attribute. Server-supplied
    // but never user-typed in practice; defensive guard so for/id pairings stay valid
    // even if an id ever contains spaces or punctuation.
    const sanitiseId = function(s) {
        return String(s == null ? '' : s).replace(/[^a-zA-Z0-9_-]/g, '_');
    };
    // Resolve a human-friendly workspace label. The terminal UI stores workspace
    // names under .name; legacy data may carry .title; on bare-/ workspaces both
    // can be empty, so fall back to the last path segment ("Root" for /).
    const labelFor = function(ws) {
        if (ws.name)  return ws.name;
        if (ws.title) return ws.title;
        if (!ws.path || ws.path === '/') return 'Root';
        const tail = ws.path.replace(/\/+$/, '').split('/').pop();
        return tail || ws.path;
    };

    $.getJSON(
        '/plugins/unraid-aicliagents/AICliAjax.php?action=get_auto_launch&agentId=' +
        encodeURIComponent(agentId) + '&csrf_token=' + csrf,
        function(r) {
            if (r.status !== 'ok' || !r.workspaces || r.workspaces.length === 0) return;

            const section = av2mkel('div', {class: 'av2-al-section'}, []);
            section.appendChild(av2mkel('p', {class: 'av2-al-eyebrow'}, ['⚡ Auto-launch on open']));
            section.appendChild(av2mkel('p', {class: 'av2-al-caption'}, [
                'These workspaces start automatically when the AI Agents page opens or after a plugin upgrade.'
            ]));

            const saveNote = av2mkel('span', {class: 'av2-save-note', style: 'display:inline-block; margin-top:8px;'}, ['']);

            r.workspaces.forEach(function(ws) {
                const uid    = 'al-' + sanitiseId(agentId) + '-' + sanitiseId(ws.id);
                const armed  = !!ws.autoLaunch;
                const wsPath = ws.path || '/';

                const row = av2mkel('div', {
                    class: 'av2-al-row' + (armed ? ' armed' : ''),
                    title: wsPath,
                }, []);

                row.appendChild(av2mkel('div', {class: 'av2-al-id'}, [
                    av2mkel('span', {class: 'av2-al-name'}, [labelFor(ws)]),
                    av2mkel('span', {class: 'av2-al-path'}, [wsPath]),
                ]));

                const chkAuto = av2mkel('input', {type: 'checkbox', id: uid});
                if (armed) chkAuto.checked = true;

                const chkFresh = av2mkel('input', {type: 'checkbox', id: uid + '-fresh'});
                if (ws.freshIfNoResume) chkFresh.checked = true;

                row.appendChild(av2mkel('label', {class: 'av2-al-toggle', 'for': uid}, [
                    chkAuto,
                    av2mkel('span', {}, ['Auto-launch']),
                ]));

                row.appendChild(av2mkel('div', {class: 'av2-al-fresh'}, [
                    chkFresh,
                    av2mkel('label', {'for': uid + '-fresh'}, ['Start fresh if no resume']),
                ]));

                chkAuto.addEventListener('change', function() {
                    if (chkAuto.checked) {
                        row.classList.add('armed');
                    } else {
                        row.classList.remove('armed');
                        chkFresh.checked = false;
                    }
                    av2SaveAutoLaunch(agentId, ws.path, chkAuto.checked, chkFresh.checked, saveNote);
                });
                chkFresh.addEventListener('change', function() {
                    av2SaveAutoLaunch(agentId, ws.path, chkAuto.checked, chkFresh.checked, saveNote);
                });

                section.appendChild(row);
            });

            section.appendChild(saveNote);
            panelBody.appendChild(section);
        }
    ).fail(function() {
        // Network error or non-200 — surface a small error line so a silent
        // miss isn't mistaken for "no workspaces have auto-launch flags".
        const errEl = av2mkel('div', {class: 'av2-help', style: 'margin-top:12px; opacity:0.6;'}, [
            'Auto-launch section failed to load.',
        ]);
        panelBody.appendChild(errEl);
    });
}

function av2SaveAutoLaunch(agentId, path, autoLaunch, freshIfNoResume, noteEl) {
    if (noteEl) { noteEl.textContent = 'Saving…'; noteEl.className = 'av2-save-note'; }
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=save_auto_launch',
        method: 'POST',
        data: {
            agentId:         agentId,
            path:            path,
            autoLaunch:      autoLaunch      ? '1' : '0',
            freshIfNoResume: freshIfNoResume ? '1' : '0',
            csrf_token:      csrf,
        },
        success: function(r) {
            if (!noteEl) return;
            noteEl.textContent = (r && r.status === 'ok') ? 'Saved ✓' : 'Save failed';
            noteEl.className   = 'av2-save-note ' + ((r && r.status === 'ok') ? 'ok' : 'bad');
            setTimeout(function() { if (noteEl) noteEl.textContent = ''; }, 2000);
        },
        error: function() {
            if (noteEl) { noteEl.textContent = 'Save failed'; noteEl.className = 'av2-save-note bad'; }
        },
    });
}

function av2LoadStoragePanel(panelOrBody, agentIdMaybe) {
    // Accept either the panel itself (legacy) or a body+agentId pair. Find the
    // storage section's body element regardless.
    const body = (panelOrBody && panelOrBody.classList && panelOrBody.classList.contains('av2-storage-body'))
        ? panelOrBody
        : panelOrBody.querySelector('.av2-storage-body');
    const agentId = agentIdMaybe || (body && body.dataset.agent);
    if (!body || !agentId) return;

    // Correct action name: get_storage_status (not _metrics — that path never existed).
    // Response shape: r.agents[id] = {mounted, mount_point, dirty_mb, physical_mb, layers, layer_files, percent}.
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_storage_status&csrf_token=' + csrf, function(r) {
        const m = (r && r.agents && r.agents[agentId]) || {};
        const mb = (m.physical_mb != null) ? Number(m.physical_mb).toFixed(1) : '—';
        const dirty = (m.dirty_mb != null) ? Number(m.dirty_mb).toFixed(1) : '—';
        const layers = m.layers != null ? m.layers : '—';
        body.textContent = '';

        // Stats block.
        const dl = av2mkel('dl', {class: 'av2-chan-stat'}, [
            av2mkel('dt', {}, ['Footprint']),
            av2mkel('dd', {}, [String(mb) + ' MB (' + String(layers) + ' layer' + (layers === 1 ? '' : 's') + ')']),
            av2mkel('dt', {}, ['In-memory']),
            av2mkel('dd', {}, [String(dirty) + ' MB unsaved']),
            av2mkel('dt', {}, ['Mount']),
            av2mkel('dd', {}, [m.mounted ? 'mounted' : 'not mounted']),
        ]);
        body.appendChild(dl);

        // Per-agent action buttons (Sync / Consolidate / Repair) intentionally
        // omitted here — the Home Storage tab already owns those operations and
        // duplicating them on every agent card was noise. Stats only.
    }).fail(function() {
        body.textContent = '';
        body.appendChild(av2mkel('div', {class: 'av2-help'}, ['Storage stats unavailable.']));
    });
}

// WP #748 J / Phase B follow-up (b): contextual repair / clear-halt actions
// fired from the Store card foot. The card itself is PHP-rendered with the
// state pre-baked (boot-integrity cache + halt-marker check), so these
// handlers just gate the action behind a confirm modal and call the
// pre-existing AJAX endpoints (restore_from_sibling / install_agent /
// clear_halt). On success they safeReload() so the card re-renders with
// fresh state instead of trying to surgically update the DOM.

// Internal: GET-clear any halt for an agent, idempotently. Promise-style.
// Returns a jQuery-deferred that always resolves (never rejects) so callers
// can chain without conditional logic — clear_halt is a no-op when no halt
// marker exists, and we shouldn't block a recovery click on a non-halt case.
function _av2ClearHaltIdempotent(id, reason) {
    var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    return $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php',
        type: 'GET',
        data: { action: 'clear_halt', type: 'agent', id: id, reason: reason, csrf_token: token },
        dataType: 'json'
    }).always(function() { /* swallow — idempotent precursor */ });
}

function av2RepairAgent(id, mode, btn) {
    var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    if (mode === 'restore') {
        swal({
            title: 'Restore ' + id + '?',
            text: 'Move the SAFE_BACKUP sibling layer file(s) into the active persist path and re-register them in the manifest. Recovers the agent without a re-download.',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Restore',
            showLoaderOnConfirm: true,
            closeOnConfirm: false
        }, function() {
            $.ajax({
                url: '/plugins/unraid-aicliagents/AICliAjax.php',
                type: 'GET',
                data: { action: 'restore_from_sibling', type: 'agent', id: id, csrf_token: token },
                dataType: 'json'
            }).done(function(r) {
                if (r && r.status === 'ok') {
                    // Restore succeeded — also clear any halt so the agent
                    // mounts normally on next boot without a second click.
                    _av2ClearHaltIdempotent(id, 'restore_from_sibling_storecard').always(function() {
                        swal({ title: 'Restored', text: r.message || 'Sibling layer(s) restored.', type: 'success', timer: 1800, showConfirmButton: false });
                        setTimeout(function() { safeReload(); }, 1200);
                    });
                } else {
                    swal('Restore failed', (r && r.message) ? r.message : 'Check lifecycle log.', 'error');
                }
            }).fail(function() {
                swal('Restore failed', 'AJAX request failed. Check debug.log.', 'error');
            });
        });
        return;
    }
    if (mode === 'reinstall') {
        // Re-install at the current channel. Under J this re-bakes to a single
        // fresh `_consolidated_<dt>.sqsh` (commitChanges for $type === 'agent'
        // routes to consolidate). Pass no explicit version so the backend uses
        // @latest on whatever channel is currently saved.
        //
        // Clear any halt FIRST: installAgent → ensureAgentMounted → mount_stack.sh
        // in strict mode exits 1 on any non-healthy classification, which would
        // make a Re-install on a halted+corrupt agent fail with "Could not mount
        // agent storage". Clearing the halt up-front lets the re-install path
        // do its own classification on the fresh layer it's about to bake.
        // installVersionAgent has its own confirm modal, so we don't add one.
        if (typeof installVersionAgent !== 'function') {
            swal('Error', 'Re-install entry point not available — refresh the page and retry.', 'error');
            return;
        }
        _av2ClearHaltIdempotent(id, 'reinstall_precursor_storecard').always(function() {
            installVersionAgent(id, btn);
        });
        return;
    }
    swal('Error', 'Unknown repair mode: ' + mode, 'error');
}

function av2ClearAgentHalt(id, btn) {
    var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    swal({
        title: 'Clear halt for ' + id + '?',
        text: "Accept the current state. The mount halt will be removed and the agent will mount normally on next boot. Use this when you've understood the warning and want to proceed.",
        type: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Clear halt',
        showLoaderOnConfirm: true,
        closeOnConfirm: false
    }, function() {
        $.ajax({
            url: '/plugins/unraid-aicliagents/AICliAjax.php',
            type: 'GET',
            data: { action: 'clear_halt', type: 'agent', id: id, reason: 'user_manual_override_storecard', csrf_token: token },
            dataType: 'json'
        }).done(function(r) {
            if (r && r.status === 'ok') {
                swal({ title: 'Halt cleared', text: 'The agent will mount normally on next boot.', type: 'success', timer: 1800, showConfirmButton: false });
                setTimeout(function() { safeReload(); }, 1200);
            } else {
                swal('Failed', (r && r.message) ? r.message : 'Check lifecycle log.', 'error');
            }
        }).fail(function() {
            swal('Failed', 'AJAX request failed. Check debug.log.', 'error');
        });
    });
}

function av2SaveArgs(form, event) {
    if (event) event.preventDefault();
    const agentId = form.dataset.agent;
    const args = form.querySelector('textarea[name="args"]').value.trim();
    const errEl = form.querySelector('.av2-args-error');

    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php',
        method: 'GET',
        data: { action: 'save_agent_args', agentId: agentId, args: args, csrf_token: csrf },
        success: function(r) {
            if (r && r.status === 'ok') {
                if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
                const chip = document.querySelector('.av2-card[data-agent="' + agentId + '"] .av2-chip[data-target="args"]');
                if (chip) chip.classList.toggle('has-custom', args !== '');
                swal({title: 'Saved', text: 'CLI args updated for ' + agentId, type: 'success', timer: 1400, showConfirmButton: false});
            } else {
                const msg = (r && r.message) ? r.message : 'Save failed';
                if (errEl) { errEl.textContent = msg; errEl.style.display = 'block'; }
                else swal('Error', msg, 'error');
            }
        },
        error: function(xhr) {
            const msg = 'Failed to save args: ' + (xhr.responseText || xhr.statusText);
            if (errEl) { errEl.textContent = msg; errEl.style.display = 'block'; }
            else swal('Error', msg, 'error');
        }
    });
    return false;
}

</script>
