<?php
/**
 * <module_context>
 * Description: Boot disk space tool "Convert agents to plain folders"
 *   (docs/specs/BOOT_DISK_SPACE.md R3, epic #534). Registers
 *   aicliBootSpace.actions['convert_agents']. The dialog asks the server for the sizes
 *   (AJAX agent_convert_estimate, #552: the numbers of the last background measurement, with their
 *   age and a Measure now button when they are older than 10 minutes; the dialog never measures
 *   itself), shows each layered agent with its size now and its
 *   estimated size as plain folders, the total extra space and the free space after,
 *   refuses when less than 10% of the disk or 2 GB would stay free, and then starts the
 *   conversion (AJAX convert_agents). Offered on an internal boot disk while
 *   Agent storage is not set to Compressed layers.
 * Dependencies: jQuery, SweetAlert, CommonLogging.php (aicliAjax), ManagerStorageScripts.php (escapeHtml, refreshStats).
 * Constraints: Atomic UI fragment.
 * </module_context>
 */
?>
<script>
(function () {
    // The room rule of agent_convert_fits (detect_backend.sh) and AgentStorageService::convertFits.
    var RESERVE_BYTES = 2147483648;

    function fmt(b) {
        b = Math.max(0, Number(b) || 0);
        if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
        return Math.round(b / 1048576) + ' MB';
    }

    // Pure: the numbers for a chosen set of agents.
    function figures(est, chosenIds) {
        var now = 0, plain = 0, i, a;
        for (i = 0; i < est.agents.length; i++) {
            a = est.agents[i];
            if (chosenIds.indexOf(a.id) < 0) continue;
            now += a.now_bytes; plain += a.plain_bytes;
        }
        var extra = Math.max(0, plain - now);
        var reserve = Math.max(RESERVE_BYTES, Math.floor(est.disk_bytes / 10));
        var after = est.free_bytes - extra;
        return { now: now, plain: plain, extra: extra, after: after, reserve: reserve,
                 short: after >= reserve ? 0 : reserve - after };
    }

    var WHY = {
        usb_boot: 'Agents on a USB boot stick keep compressed layers. Nothing to convert.',
        keep_agents_compressed: 'Agent storage is set to Compressed layers (Configuration tab). Set it to Plain folders to convert agents.',
        no_layered_agents: 'No agent uses compressed layers. Every installed agent already uses plain folders.',
        not_boot_disk: 'The agents are not on the boot disk. This conversion is for an internal boot disk.'
    };

    function summaryHtml(est, chosen) {
        var f = figures(est, chosen);
        var h = '<div data-testid="convert-agents-summary" style="margin-top:8px; line-height:1.7;">'
            + '<div>Size now: <b>' + fmt(f.now) + '</b> &rarr; as plain folders: <b>' + fmt(f.plain) + '</b>'
            + ' (extra <b>' + fmt(f.extra) + '</b>)</div>'
            + '<div>Free on the boot disk: <b>' + fmt(est.free_bytes) + '</b> now, <b>' + fmt(Math.max(0, f.after)) + '</b> after</div>';
        if (f.short > 0) {
            h += '<div data-testid="convert-agents-refusal" style="color:#e22; margin-top:4px;"><b>Not enough room.</b> After the conversion at least 10% of the boot disk and 2 GB must stay free. About <b>'
                + fmt(f.short) + '</b> are missing. Choose fewer agents, or free space first (remove old versions or left-over files).</div>';
        } else if (est.retained_bytes > 0) {
            h += '<div style="opacity:0.8; margin-top:4px;">The old layers (' + fmt(f.now)
                + ') stay on the boot disk for 14 days as a backup. Remove them sooner with Remove on the Left over line.</div>';
        }
        return h + '</div>';
    }

    function stamp(r) {
        return (window.aicliBootSpace && typeof window.aicliBootSpace.stampHtml === 'function') ? window.aicliBootSpace.stampHtml(r) : '';
    }

    function dialogHtml(est, answer) {
        var rows = '', i, a;
        for (i = 0; i < est.agents.length; i++) {
            a = est.agents[i];
            rows += '<tr><td><label><input type="checkbox" class="aicli-ca-pick" data-id="' + escapeHtml(a.id) + '" checked> '
                + escapeHtml(a.name) + '</label></td>'
                + '<td style="text-align:right;">' + fmt(a.now_bytes) + '</td>'
                + '<td style="text-align:right;">' + fmt(a.plain_bytes) + '</td></tr>';
        }
        return '<div style="text-align:left; font-size:12px;" data-testid="convert-agents-dialog">'
            + '<div style="margin-bottom:8px;">These agents use compressed layers. As plain folders they start faster and need no merges, '
            + 'but they use more space on the boot disk. Only the workspaces of one agent close at a time, and they open again when its conversion ends.</div>'
            + '<table style="width:100%; border-collapse:collapse;"><thead><tr><th style="text-align:left;">Agent</th>'
            + '<th style="text-align:right;">Size now</th><th style="text-align:right;">As plain folders</th></tr></thead><tbody>'
            + rows + '</tbody></table>'
            + (est.estimated ? '<div style="opacity:0.7; margin-top:4px;">The sizes as plain folders are estimates: about 5.8 times the size of the layer files, divided by the compression of the boot disk.</div>' : '')
            + '<div id="aicli-ca-summary">' + summaryHtml(est, est.agents.map(function (x) { return x.id; })) + '</div>'
            + (answer ? stamp(answer) : '')
            + '</div>';
    }

    function chosenIds() {
        var ids = [];
        $('.sweet-alert .aicli-ca-pick:checked').each(function () { ids.push(String($(this).data('id'))); });
        return ids;
    }

    function start(ids, est) {
        var all = ids.length === est.agents.length;
        aicliAjax('convert_agents', { agents: all ? 'all' : JSON.stringify(ids) }, function (r) {
            document.body.classList.remove('aicli-swal-above-tray');
            if (r && r.status === 'queued') {
                swal({ title: 'Conversion queued', text: r.message || 'Watch the activity tray for progress.',
                       type: 'info', timer: 3500, showConfirmButton: false });
            } else {
                swal('Not started', (r && r.message) || 'Unknown error. Check the Debug Console.', 'error');
            }
            if (typeof refreshStats === 'function') refreshStats(); if (window.aicliBootSpace && typeof window.aicliBootSpace.refresh === 'function') window.aicliBootSpace.refresh();
        }).fail(function (x, s, e) {
            // R-175: the dialog must not spin for ever when the request fails.
            document.body.classList.remove('aicli-swal-above-tray');
            swal('Not started', 'Could not reach the server. ' + aicliAjaxReason(x, s, e), 'error');
        });
    }

    window.aicliBootSpace = window.aicliBootSpace || { actions: {} };
    window.aicliBootSpace.actions['convert_agents'] = function (part) {
        if (typeof window.swal !== 'function') { window.alert('This needs the Unraid dialog library.'); return; }
        // The numbers come from the last background measurement (a read). This dialog measures nothing.
        aicliAjax('agent_convert_estimate', {}, function (r) {
            var est = r && r.estimate;
            if (window.aicliBootSpace) window.aicliBootSpace.reopen = function () { window.aicliBootSpace.actions['convert_agents'](part); };
            if (r && r.status === 'ok' && !est) {
                swal({ title: 'Convert agents to plain folders?', html: true, type: 'info',
                       text: '<div style="text-align:left; font-size:12px;">The sizes are not measured yet. Press Measure now.' + stamp(r) + '</div>' });
                return;
            }
            if (!est || r.status !== 'ok') {
                swal('Not available', (r && r.message) || 'The sizes could not be read.', 'error');
                return;
            }
            if (!est.offered) {
                swal({ title: 'Nothing to convert', text: WHY[est.reason] || 'Agents cannot be converted now.', type: 'info' });
                return;
            }
            // Lift the dialog above the open Activity tray, as the home conversion does.
            if (!document.getElementById('aicli-swal-above-tray-css')) {
                var st = document.createElement('style');
                st.id = 'aicli-swal-above-tray-css';
                st.textContent = 'body.aicli-swal-above-tray .sweet-overlay { z-index: 1000010 !important; }'
                    + 'body.aicli-swal-above-tray .sweet-alert { z-index: 1000011 !important; }';
                document.head.appendChild(st);
            }
            document.body.classList.add('aicli-swal-above-tray');
            swal({
                title: 'Convert agents to plain folders?',
                text: dialogHtml(est, r),
                html: true,
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Convert',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#b35900',
                // Keep the checked inputs in the dialog until the callback reads them.
                closeOnConfirm: false
            }, function (confirmed) {
                if (!confirmed) { document.body.classList.remove('aicli-swal-above-tray'); return; }
                var ids = chosenIds();
                if (!ids.length) {
                    document.body.classList.remove('aicli-swal-above-tray');
                    if (typeof window.swal.close === 'function') window.swal.close();
                    return;
                }
                // Do not close this dialog: the result dialog of start() replaces it.
                // A close() just before that swal() hides the new dialog when the
                // answer comes back inside the close animation.
                start(ids, est);
            });
            // Recount when the person ticks or unticks an agent; the Convert button is
            // off when nothing is chosen or the room rule refuses.
            var refresh = function () {
                var ids = chosenIds(), f = figures(est, ids);
                $('#aicli-ca-summary').html(summaryHtml(est, ids));
                $('.sweet-alert button.confirm').prop('disabled', ids.length === 0 || f.short > 0);
            };
            $(document).off('change.aicliCa').on('change.aicliCa', '.sweet-alert .aicli-ca-pick', refresh);
            refresh();
        });
    };
})();
</script>
