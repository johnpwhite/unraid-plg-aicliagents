<?php
/**
 * <module_context>
 * Description: The "Remove old versions" tool of the Boot disk space card
 *   (docs/specs/BOOT_DISK_SPACE.md R4, epic #534). It registers
 *   aicliBootSpace.actions['remove_old_versions'] and the global function
 *   aicliRemoveOldVersions(agentId), which the Agent Store card also calls.
 *   The dialog lists what goes, the space it frees and what stays because a
 *   workspace uses it, and asks for a confirm before it removes anything.
 * Dependencies: jQuery, SweetAlert, CommonLogging.php (aicliAjax), escapeHtml.
 * Constraints: Atomic UI fragment. Backend: AJAX agent_old_versions (read-only) and
 *   remove_old_versions (StorageHandler). #552: the list comes from the last background
 *   measurement. The dialog shows its age, and a Measure now button when it is older than
 *   10 minutes or missing; it never measures itself. The removal runs as a supervisor job
 *   (op removeold) and the Activity tray shows its steps.
 * </module_context>
 */
?>
<script>
(function () {
    function fmtBytes(n) {
        n = Math.max(0, Number(n) || 0);
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
        // The same rule as BootSpaceService::sizeText and the Agent Store card foot: one decimal below 10 MB, whole MB above.
        if (n < 10485760) return (n / 1048576).toFixed(1) + ' MB';
        if (n < 1073741824) return Math.round(n / 1048576) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    }
    function esc(s) { return (typeof escapeHtml === 'function') ? escapeHtml(String(s)) : $('<div>').text(String(s)).html(); }
    function lastPart(p) { var a = String(p || '').split('/'); return a[a.length - 1] || p; }
    var KIND = { plain: 'version folder', layer: 'storage layer', rollback: 'rollback copy' };

    // PURE: the HTML of the list dialog from the agent_old_versions answer.
    function stamp(r) {
        return (window.aicliBootSpace && typeof window.aicliBootSpace.stampHtml === 'function') ? window.aicliBootSpace.stampHtml(r) : '';
    }
    function listHtml(r) {
        var items = (r && r.items) || [], skipped = (r && r.skipped) || [], h = '<div style="text-align:left; font-size:12px; line-height:1.6;">';
        if (r && r.has_data === false) {
            h += '<div>The old versions are not measured yet. Press Measure now.</div>';
        } else if (!items.length) {
            h += '<div>There is nothing to remove. Each agent keeps only the version in use.</div>';
        } else {
            h += '<div style="margin-bottom:6px;">This removes <b>' + items.length + '</b> old item(s) and frees <b>'
               + fmtBytes(r.total_bytes) + '</b> on the boot disk.</div><ul style="margin:0 0 0 18px; padding:0; max-height:180px; overflow:auto;">';
            items.forEach(function (it) {
                h += '<li><b>' + esc(it.agent) + '</b>: ' + esc(KIND[it.kind] || it.kind) + ' <code>' + esc(lastPart(it.path)) + '</code> (' + fmtBytes(it.bytes) + ')</li>';
            });
            h += '</ul>';
            h += '<div style="margin-top:6px; opacity:0.8;">You can no longer roll back to a removed version.</div>';
        }
        if (skipped.length) {
            h += '<div style="margin-top:8px;"><b>Stays on the disk:</b></div><ul style="margin:0 0 0 18px; padding:0; max-height:120px; overflow:auto;">';
            skipped.forEach(function (s) {
                h += '<li><b>' + esc(s.agent) + '</b> <code>' + esc(lastPart(s.path)) + '</code>: ' + esc(s.reason) + '</li>';
            });
            h += '</ul>';
        }
        return h + stamp(r) + '</div>';
    }

    // agent: an agent id, 'all', or a card line object with an .agent field.
    function run(agent) {
        if (agent && typeof agent === 'object') agent = agent.agent;
        agent = agent || 'all';
        if (typeof window.swal !== 'function') {
            window.alert('Remove old versions needs the dialog library. Reload the page.');
            return;
        }
        // The list comes from the last measurement (a read). This dialog measures nothing.
        aicliAjax('agent_old_versions', { agent: agent }, function (r) {
            if (!r || r.status !== 'ok') {
                swal('Cannot list the old versions', (r && r.message) || 'Unknown error. Check the Debug Console.', 'error');
                return;
            }
            if (window.aicliBootSpace) window.aicliBootSpace.reopen = function () { run(agent); };
            var any = (r.items || []).length > 0;
            swal({
                title: agent === 'all' ? 'Remove old versions of all agents?' : 'Remove old versions of ' + agent + '?',
                text: listHtml(r),
                html: true,
                type: any ? 'warning' : 'info',
                showCancelButton: any,
                confirmButtonText: 'Remove',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#b35900',
                showLoaderOnConfirm: true,
                closeOnConfirm: false
            }, function (confirmed) {
                if (!confirmed || !any) return;
                // The removal is a supervisor job. The request returns at once; the Activity tray
                // shows the steps and the result, and the job starts a fresh measurement when it ends.
                aicliAjax('remove_old_versions', { agent: agent }, function (res) {
                    if (res && res.status === 'queued') {
                        swal({ title: 'Removal queued', text: res.message || 'Watch the activity tray for progress.', type: 'info', timer: 3500, showConfirmButton: false });
                        // The Agent Store cards show what each action frees: ask again until the new measurement is in.
                        if (typeof window.av2WatchFootAfterRemoval === 'function') window.av2WatchFootAfterRemoval(agent);
                    } else {
                        swal('Not removed', (res && res.message) || 'Unknown error. Check the Debug Console.', 'error');
                    }
                    if (typeof refreshStats === 'function') refreshStats();
                }).fail(function (x, s, e) {
                    // R-175: the dialog must not spin for ever when the request fails.
                    swal('Not removed', 'Could not reach the server. ' + aicliAjaxReason(x, s, e), 'error');
                });
            });
        });
    }

    window.aicliRemoveOldVersions = run;
    window.aicliRemoveOldVersionsHtml = listHtml;   // test seam
    window.aicliBootSpace = window.aicliBootSpace || { actions: {} };
    window.aicliBootSpace.actions['remove_old_versions'] = run;
})();
</script>
