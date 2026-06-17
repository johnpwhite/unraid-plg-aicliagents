<?php
/**
 * <module_context>
 * Description: JS for the header health status chip (R-09, Feature #1372) — polls
 *   aicliAjax('health_status') on Manager page load + every 60s (matches the server
 *   cache TTL), maps overall ok|warn|fail to green/amber/red, tooltip lists non-ok
 *   checks, click opens the Debug Console tab.
 * Dependencies: CommonLogging.php (aicliAjax), ManagerLayout.php (#aicli-health-chip),
 *   ManagerScripts.php (switchMainTab).
 * Constraints: Atomic UI fragment (< 60 lines). Read-only — never mutates state.
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
    setInterval(aicliHealthRefresh, 60000);
});
</script>
