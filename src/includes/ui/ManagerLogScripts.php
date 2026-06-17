<?php
/**
 * <module_context>
 * Description: JavaScript logic for log management in AICliAgents Manager.
 * Dependencies: jQuery, SweetAlert, $csrf_token.
 * Constraints: Atomic UI fragment (< 100 lines).
 * </module_context>
 */
?>
<script>
function switchLog(type, el) {
    currentLog = type;
    $('.log-tab').removeClass('active');
    $(el).addClass('active');
    // Filters only parse the plugin's own debug.log line format — hide the row
    // for raw install/uninstall logs to avoid filtering everything away.
    $('#log-filter-row').toggle(type === 'debug');
    if (type === 'debug') refreshLogContexts();
    refreshLog(true);
}

// R-07: collect the active filter values (debug log only).
function logFilterParams() {
    const params = { type: currentLog, tail: $('#log-filter-tail').val() || 500 };
    if (currentLog !== 'debug') return params;
    const ctx = $('#log-filter-ctx').val();
    const lvl = $('#log-filter-level').val();
    const trc = ($('#log-filter-trace').val() || '').trim().replace(/^t:/, '');
    if (ctx) params.ctx = ctx;
    if (lvl !== '' && lvl != null) params.level = lvl;
    if (/^[a-z0-9]{4,16}$/.test(trc)) params.trace = trc;
    return params;
}

function refreshLog(force) {
    if (autoscrollPaused && !force) return;
    aicliAjax('get_log', logFilterParams(), function(data) {
        const body = $('#log-content');
        if (data.status === 'ok') {
            body.text(data.content);
            if (!autoscrollPaused) body.scrollTop(body[0].scrollHeight);
        } else {
            body.text("Error: " + data.message);
        }
    });
}

// R-07: populate the context dropdown from the recent tail (preserves selection).
function refreshLogContexts() {
    const sel = $('#log-filter-ctx');
    const current = sel.val();
    aicliAjax('get_log_contexts', {}, function(r) {
        if (r.status !== 'ok' || !Array.isArray(r.contexts)) return;
        sel.find('option:not(:first)').remove();
        r.contexts.forEach(function(c) {
            sel.append($('<option>').val(c).text(c));
        });
        if (current && r.contexts.indexOf(current) !== -1) sel.val(current);
    });
}

function resetLogFilters() {
    $('#log-filter-ctx').val('');
    $('#log-filter-level').val('');
    $('#log-filter-trace').val('');
    $('#log-filter-tail').val('500');
    refreshLog(true);
}

function copyLogToClipboard() {
    const text = $('#log-content').text();
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            swal({ title: "Copied!", text: "Log content copied to clipboard.", type: "success", timer: 1500, showConfirmButton: false });
        }).catch(function(err) {
            swal("Failed to copy", err.message, "error");
        });
    } else {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        try { document.execCommand('copy'); swal({ title: "Copied!", type: "success", timer: 1500, showConfirmButton: false }); }
        catch (err) { swal("Failed to copy", err.message, "error"); }
        document.body.removeChild(textarea);
    }
}

function clearSelectedLog() {
    swal({ title: "Clear " + currentLog + " log?", text: "This action cannot be undone.", type: "warning", showCancelButton: true, confirmButtonColor: "#f44336", confirmButtonText: "Yes, Clear It", showLoaderOnConfirm: true, closeOnConfirm: false }, function() {
        aicliAjax('clear_log', { type: currentLog }, function(r) {
            if (r.status === 'ok') {
                swal({ title: "Cleared", text: r.message, type: "success", timer: 1500, showConfirmButton: false });
                refreshLog();
            } else {
                swal("Failed", r.message, "error");
            }
        });
    });
}

function openMigrationLog() {
    switchLog('migration', $('.log-tab[data-type="migration"]')[0]);
}

function pauseAutoscroll(paused) {
    autoscrollPaused = paused;
    if (paused) $('#autoscroll-status').css({opacity: 1, visibility: 'visible'});
    else $('#autoscroll-status').css({opacity: 0, visibility: 'hidden'});
}

// ---------------------------------------------------------------------------
// R-08 (#1371): support/share UX — redacted bundle + summary. Server-side
// redaction throughout; nothing is ever auto-posted anywhere.
// ---------------------------------------------------------------------------
function diagEsc(s) {
    return $('<div>').text(String(s == null ? '' : s)).html();
}

function diagDownloadBundle() {
    swal({ title: "Building bundle…", text: "Collecting and redacting diagnostics.", type: "info", showConfirmButton: false });
    var anon = $('#diag-anon').is(':checked') ? 1 : 0;
    aicliAjax('diag_bundle_create', { anonymize: anon }, function(r) {
        if (r.status !== 'ok') { swal("Bundle failed", r.message, "error"); return; }
        swal({ title: "Bundle ready", text: r.file + " (" + Math.round(r.size / 1024) + " KB) — downloading…", type: "success", timer: 2500, showConfirmButton: false });
        var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
        window.location.href = '/plugins/unraid-aicliagents/AICliAjax.php?action=diag_bundle_download&file='
            + encodeURIComponent(r.file) + '&csrf_token=' + encodeURIComponent(token);
    }).fail(function() { swal("Bundle failed", "Request error.", "error"); });
}

function diagCopyForumPost() {
    aicliAjax('diag_summary', { format: 'bbcode' }, function(r) {
        if (r.status !== 'ok') { swal("Summary failed", r.message, "error"); return; }
        var done = function() {
            swal({ title: "Copied!", text: "Forum post copied to clipboard.\nRemember: attach the support bundle zip manually.", type: "success" });
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(r.summary).then(done).catch(function(e) { swal("Copy failed", e.message, "error"); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = r.summary; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) { swal("Copy failed", e.message, "error"); }
            document.body.removeChild(ta);
        }
    });
}

function diagCreateGithubIssue() {
    aicliAjax('diag_summary', { format: 'markdown' }, function(r) {
        if (r.status !== 'ok') { swal("Summary failed", r.message, "error"); return; }
        var body = r.summary;
        if (body.length > 6000) body = body.slice(0, 6000);
        body += "\n\n_Full details in attached bundle._";
        var url = r.repo_url + '/issues/new?title=' + encodeURIComponent(r.title || '[support] AI CLI Agents')
                + '&body=' + encodeURIComponent(body);
        // Opens prefilled in a new tab — the user reviews and submits; nothing is auto-posted.
        window.open(url, '_blank');
    });
}

function diagCheckKnownIssues() {
    var box = $('#diag-known-issues');
    box.show().html('<i class="fa fa-spinner fa-spin"></i> Checking known issues…');
    aicliAjax('diag_known_issues', {}, function(r) {
        if (r.status !== 'ok') { box.html('<span style="color:#f66;">' + diagEsc(r.message) + '</span>'); return; }
        var matches = (r.issues || []).filter(function(i) { return i.matched; });
        if (!matches.length) {
            box.html('<i class="fa fa-check" style="color:#0c0;"></i> No known issues matched recent logs (v' + diagEsc(r.version) + ').');
            return;
        }
        var html = matches.map(function(i) {
            var links = '';
            if (i.forum_url) links += ' <a href="' + diagEsc(i.forum_url) + '" target="_blank">forum thread</a>';
            if (i.fixed_in)  links += (links ? ' · ' : ' ') + 'fixed in ' + diagEsc(i.fixed_in);
            return '<div style="border:1px solid rgba(128,128,128,0.4); border-radius:4px; padding:6px 8px; margin:4px 0;">'
                 + '<b>' + diagEsc(i.id) + ' — ' + diagEsc(i.title) + '</b><br>'
                 + (i.workaround ? '<span>' + diagEsc(i.workaround) + '</span><br>' : '')
                 + (i.matched_line ? '<code style="font-size:10px; opacity:0.7;">' + diagEsc(i.matched_line) + '</code><br>' : '')
                 + '<span style="font-size:10px;">' + links + '</span>'
                 + '</div>';
        }).join('');
        box.html('<b>' + matches.length + ' known issue(s) matched:</b>' + html);
    }).fail(function() { box.html('<span style="color:#f66;">Known-issues request failed.</span>'); });
}
</script>
