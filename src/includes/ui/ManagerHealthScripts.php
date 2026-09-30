<?php
/**
 * <module_context>
 * Description: JS for the header health status chip (R-09, Feature #1372) — polls
 *   aicliAjax('health_status') on Manager page load + every 60s (matches the server
 *   cache TTL), plus one debounced read when the page's event stream (re)connects,
 *   breaks, or carries a storage change (EVENT_STREAM_MULTIPLEX.md R6), maps overall ok|warn|fail to green/amber/red, tooltip lists non-ok
 *   checks, click opens the Debug Console tab.
 * Dependencies: CommonLogging.php (aicliAjax), ManagerLayout.php (#aicli-health-chip),
 *   ManagerScripts.php (switchMainTab).
 * Constraints: Atomic UI fragment (< 80 lines). Read-only — never mutates state.
 * </module_context>
 */
?>
<script>
var AICLI_HEALTH_COLORS = { ok: '#4caf50', warn: '#ffa726', fail: '#ef5350' };

function aicliHealthChipClick() {
    var btn = document.getElementById('aicli-tab-btn-debug');
    if (btn && typeof switchMainTab === 'function') switchMainTab('debug', btn);
}

function aicliHealthApply(r) {
    var dot = document.getElementById('aicli-health-dot');
    var chip = document.getElementById('aicli-health-chip');
    var label = document.getElementById('aicli-health-label');
    if (!dot || !chip) return;
    var overall = (r && r.overall) ? r.overall : 'unknown';
    dot.style.background = AICLI_HEALTH_COLORS[overall] || '#888';
    if (label) label.textContent = 'Health: ' + overall;
    var lines = [];
    if (r && r.checks) {
        Object.keys(r.checks).forEach(function (k) {
            var c = r.checks[k] || {};
            if (c.status !== 'ok') lines.push(k + ': ' + (c.status || '?') + (c.message ? ' — ' + c.message : ''));
        });
    }
    chip.title = 'Plugin health: ' + overall
        + (lines.length ? '\n' + lines.join('\n') : '\nAll checks OK')
        + '\nClick to open the Debug Console.';
}

function aicliHealthRefresh() {
    aicliAjax('health_status', {}, aicliHealthApply);
}

$(function () {
    aicliHealthRefresh();
    // The 60s read is the reconcile (it matches the server cache TTL).
    setInterval(aicliHealthRefresh, 60000);
    // EVENT_STREAM_MULTIPLEX.md R6: the chip also consumes the page's one stream.
    // A (re)connect, a broken stream, or a storage change are the moments health
    // most likely moved — one debounced, cached read; never a forced re-check.
    var healthHintTimer = null;
    function aicliHealthHint() {
        if (healthHintTimer) clearTimeout(healthHintTimer);
        healthHintTimer = setTimeout(aicliHealthRefresh, 5000);
    }
    window.addEventListener('aicli-reconcile', aicliHealthHint);
    window.addEventListener('aicli-event-status', aicliHealthHint);
    window.addEventListener('aicli-event', function (e) {
        if (e && e.detail && e.detail.channel === 'storage_status') aicliHealthHint();
    });
});
</script>
