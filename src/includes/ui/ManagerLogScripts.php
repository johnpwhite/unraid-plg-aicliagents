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
    refreshLog();
}

function refreshLog() {
    if (autoscrollPaused) return;
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_log&type=' + currentLog + '&csrf_token=' + csrf, function(data) {
        const body = $('#log-content');
        if (data.status === 'ok') {
            body.text(data.content);
            if (!autoscrollPaused) body.scrollTop(body[0].scrollHeight);
        } else {
            body.text("Error: " + data.message);
        }
    });
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
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=clear_log&type=' + currentLog + '&csrf_token=' + csrf, function(r) {
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
</script>
