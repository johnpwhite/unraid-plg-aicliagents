<?php
/**
 * <module_context>
 * Description: The button "Restore from an archive…" in the footer of the Boot disk space card
 *   (docs/specs/BOOT_DISK_SPACE.md R8, #755). It registers aicliBootSpace.actions['restore_boot_data'].
 *   The dialog has three steps:
 *     1. pick a pool and an existing folder on it, and press "Find archives" (AJAX boot_restore_list);
 *     2. pick one archive from the list and press "Check this archive". A background job compares the
 *        archive with the home and changes nothing (POST boot_restore_plan, then boot_restore_result
 *        every 2 seconds);
 *     3. read what a restore would do: new, same and different files. Choose what happens to a
 *        different file (keep the file in the home, or replace it) and press "Restore". Only now
 *        is anything copied (POST boot_restore_start). The Activity tray shows the progress.
 *   A different file is kept by default. Nothing is overwritten without that choice.
 * Dependencies: jQuery, SweetAlert, 00-card.php (aicliBootSpace.post, refresh), aicliAjax.
 * Constraints: Atomic UI fragment. It reads no folder and copies nothing itself: the server does.
 * </module_context>
 */
?>
<script>
(function () {
    'use strict';
    var reg = window.aicliBootSpace = window.aicliBootSpace || { actions: {} };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function human(b) {
        b = Number(b) || 0;
        var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
        while (b >= 1024 && i < 4) { b /= 1024; i++; }
        return (i === 0 ? Math.round(b) : b.toFixed(1)) + ' ' + u[i];
    }
    function day(epoch) {
        var e = Number(epoch) || 0;
        return e > 0 ? new Date(e * 1000).toISOString().slice(0, 16).replace('T', ' ') : 'unknown';
    }
    function fail(msg) {
        if (typeof window.swal === 'function') swal('Not done', msg || 'Unknown error. Check the Debug Console.', 'error');
    }

    var STATE_TEXT = {
        'new': 'Not in the home. Will be copied.',
        'same': 'Already in the home with the same content.',
        'different': 'A different file is in the home.',
        'refused': 'Refused.',
        'damaged': 'The archive copy is damaged.'
    };

    // Pure: step 1. Pool select and folder field.
    function folderHtml(list) {
        var pools = (list && list.pools) || [];
        var saved = (list && list.saved) || '';
        var poolPath = '', folder = '';
        pools.forEach(function (p) {
            if (saved.indexOf(p.path + '/') === 0) { poolPath = p.path; folder = saved.substring(p.path.length + 1); }
        });
        if (!poolPath && pools.length) poolPath = pools[0].path;
        var opts = pools.map(function (p) {
            return '<option value="' + esc(p.path) + '"' + (p.path === poolPath ? ' selected' : '') + '>' + esc(p.path) + '</option>';
        }).join('');
        var noPool = !pools.length;
        return '<div style="text-align:left; font-size:12px; line-height:1.6;" data-testid="restore-boot-data-folder">' +
            '<div style="margin-bottom:8px;">Pick the folder that you chose when you archived. The plugin looks in its folder ' +
            '<code>aicliagents-archive</code> there. It changes nothing in this step.</div>' +
            '<label style="display:block;">Pool</label>' +
            '<select id="aicli-br-pool" style="width:100%; margin-bottom:6px;"' + (noPool ? ' disabled' : '') + '>' + opts + '</select>' +
            '<label style="display:block;">Folder in the pool</label>' +
            '<input type="text" id="aicli-br-folder" value="' + esc(folder) + '" placeholder="for example backups" style="width:100%; margin-bottom:6px;"' + (noPool ? ' disabled' : '') + '>' +
            (noPool ? '<div style="margin-top:8px; color:#e53935;">No pool is mounted. Start the array, then try again.</div>' : '') +
            '</div>';
    }
    reg.restoreFolderHtml = folderHtml;   // test seam

    // Pure: the label of one archive in the list.
    function archiveLabel(a) {
        return a.date + ' · ' + a.host + ' · ' + a.label + ' of home ' + a.user;
    }

    // Pure: step 2. The list of archives.
    function archivesHtml(list) {
        var arch = (list && list.archives) || [];
        if (!arch.length) {
            return '<div style="text-align:left; font-size:12px;" data-testid="restore-boot-data-none">No archive was found in this folder. ' +
                'Check the pool and the folder. The archive folder is <code>' + esc(list && list.folder) + '/aicliagents-archive</code>.</div>';
        }
        var opts = arch.map(function (a, i) {
            return '<option value="' + esc(a.path) + '"' + (i === 0 ? ' selected' : '') + '>' + esc(archiveLabel(a)) + '</option>';
        }).join('');
        return '<div style="text-align:left; font-size:12px; line-height:1.6;" data-testid="restore-boot-data-archives">' +
            '<div style="margin-bottom:8px;">' + arch.length + ' archive(s), newest first. The check compares the archive with the home and changes nothing. ' +
            'It can take a while for a big archive.</div>' +
            '<select id="aicli-br-archive" style="width:100%;">' + opts + '</select></div>';
    }
    reg.restoreArchivesHtml = archivesHtml;   // test seam

    // Pure: step 3. What a restore would do.
    function planHtml(plan) {
        var c = (plan && plan.counts) || {};
        var rows = (plan && plan.rows) || [];
        var total = Number(plan && plan.total) || rows.length;
        var li = rows.filter(function (r) { return r.state !== 'same'; }).slice(0, 40).map(function (r) {
            var note = STATE_TEXT[r.state] || r.state;
            if (r.state === 'different') {
                note += r.home_newer ? ' The file in the home is newer (' + day(r.home_mtime) + ' against ' + day(r.archived_mtime) + ').'
                                     : ' The archived file is newer or the same age.';
            }
            if (r.reason && (r.state === 'refused' || r.state === 'damaged')) note = r.reason;
            return '<li data-state="' + esc(r.state) + '"><code>' + esc(r.file) + '</code> (' + human(r.size) + ') &mdash; ' + esc(note) + '</li>';
        }).join('');
        var diff = Number(c.different) || 0;
        var policy = diff > 0
            ? '<div style="margin:8px 0; padding:8px; border:1px solid #e6a23c; border-radius:4px;" data-testid="restore-policy">' +
              '<b>' + diff + ' file(s) in the home are different from the archive.</b> A restore never overwrites them unless you choose so.' +
              '<label style="display:block; margin-top:4px;"><input type="radio" name="aicli-br-policy" value="skip" checked> Keep the files in the home (recommended)</label>' +
              '<label style="display:block;"><input type="radio" name="aicli-br-policy" value="replace"> Replace them with the archived files (' + human(plan.bytes_different) + ')</label></div>'
            : '';
        return '<div style="text-align:left; font-size:12px; line-height:1.6;" data-testid="restore-boot-data-plan">' +
            '<div style="margin-bottom:6px;">Archive of <b>' + esc(plan.kind) + '</b> for home <b>' + esc(plan.user) + '</b>, ' + total + ' file(s):</div>' +
            '<ul style="margin:0 0 6px 18px; padding:0;">' +
            '<li><b>' + (Number(c['new']) || 0) + '</b> new: not in the home, will be copied (' + human(plan.bytes_new) + ')</li>' +
            '<li><b>' + (Number(c.same) || 0) + '</b> the same: already in the home, nothing to do</li>' +
            '<li><b>' + diff + '</b> different: in the home with other content</li>' +
            ((Number(c.refused) || 0) + (Number(c.damaged) || 0) > 0
                ? '<li><b>' + ((Number(c.refused) || 0) + (Number(c.damaged) || 0)) + '</b> cannot be restored (refused path or damaged archive copy)</li>' : '') +
            '</ul>' + policy +
            (li ? '<div style="margin-top:6px;">Files that need a look' + (total > 40 ? ' (the first 40)' : '') + ':</div>' +
                  '<ul style="margin:0 0 6px 18px; padding:0; max-height:160px; overflow:auto;" data-testid="restore-boot-data-rows">' + li + '</ul>' : '') +
            '<div style="font-size:11px; opacity:0.85;">Nothing is copied until you press Restore. The archive itself stays as it is.</div></div>';
    }
    reg.restorePlanHtml = planHtml;   // test seam

    // Pure: can the plan do anything? (A new file, or a different file that the person may replace.)
    function planHasWork(plan) {
        var c = (plan && plan.counts) || {};
        return (Number(c['new']) || 0) + (Number(c.different) || 0) > 0;
    }
    reg.restorePlanHasWork = planHasWork;   // test seam

    // Poll boot_restore_result every 2 seconds until the job is done (at most 10 minutes).
    function waitForJob(jobId, done) {
        var n = 0;
        (function poll() {
            setTimeout(function () {
                aicliAjax('boot_restore_result', { job_id: jobId }, function (r) {
                    if (r && r.status === 'ok' && r.state === 'done') { done(r.result || {}); return; }
                    if (r && r.status === 'ok' && n++ < 300) { poll(); return; }
                    done({ status: 'error', message: (r && r.message) || 'The check did not finish.' });
                }).fail(function () { done({ status: 'error', message: 'The check could not be read.' }); });
            }, 2000);
        })();
    }
    reg.restoreWaitForJob = waitForJob;   // test seam

    function stepPlan(manifest) {
        reg.post('boot_restore_plan', { manifest: manifest }, function (q) {
            if (!q || q.status !== 'queued') { fail(q && q.message); return; }
            waitForJob(q.job_id, function (plan) {
                if (plan.status !== 'ok') { fail(plan.message); return; }
                var work = planHasWork(plan);
                swal({
                    title: work ? 'Restore these files?' : 'Nothing to restore',
                    text: planHtml(plan), html: true, type: work ? 'info' : 'success',
                    showCancelButton: work, confirmButtonText: work ? 'Restore' : 'Close', cancelButtonText: 'Cancel',
                    closeOnConfirm: false, showLoaderOnConfirm: work
                }, function (ok) {
                    if (!ok || !work) return;
                    var policy = $('input[name="aicli-br-policy"]:checked').val() || 'skip';
                    reg.post('boot_restore_start', { manifest: manifest, policy: policy }, function (r) {
                        if (r && r.status === 'queued') {
                            swal({ title: 'Restore started', text: 'Watch the activity tray for progress.', type: 'success', timer: 3000, showConfirmButton: false });
                            if (typeof reg.refresh === 'function') setTimeout(reg.refresh, 4000);
                        } else { fail(r && r.message); }
                    }).fail(function () { fail(); });
                });
            });
        }).fail(function () { fail(); });
    }

    function stepArchives(list) {
        var any = ((list && list.archives) || []).length > 0;
        swal({
            title: 'Restore from an archive', text: archivesHtml(list), html: true, type: 'info',
            showCancelButton: true, confirmButtonText: any ? 'Check this archive' : 'Close', cancelButtonText: 'Cancel',
            closeOnConfirm: false, showLoaderOnConfirm: any
        }, function (ok) {
            if (!ok || !any) return;
            var manifest = $('#aicli-br-archive').val() || '';
            if (!manifest) { fail('Pick an archive.'); return; }
            stepPlan(manifest);
        });
    }

    reg.actions['restore_boot_data'] = function () {
        if (typeof window.swal !== 'function') { fail('This page cannot show the dialog.'); return; }
        aicliAjax('boot_restore_list', { folder: '' }, function (list) {
            if (!list || list.status !== 'ok') { fail(list && list.message); return; }
            swal({
                title: 'Restore from an archive', text: folderHtml(list), html: true, type: 'info',
                showCancelButton: true, confirmButtonText: 'Find archives', cancelButtonText: 'Cancel',
                closeOnConfirm: false, showLoaderOnConfirm: true
            }, function (ok) {
                if (!ok) return;
                var pool = $('#aicli-br-pool').val() || '';
                var folder = ($('#aicli-br-folder').val() || '').trim().replace(/^\/+|\/+$/g, '');
                if (!pool || !folder) { fail('Pick a pool and a folder that already exists in it.'); return; }
                aicliAjax('boot_restore_list', { folder: pool + '/' + folder }, function (found) {
                    if (!found || found.status !== 'ok') { fail(found && found.message); return; }
                    stepArchives(found);
                });
            });
        });
    };
})();
</script>
