<?php
/**
 * <module_context>
 * Description: Global JavaScript state variables for AICliAgents Manager.
 * Dependencies: $csrf_token.
 * Constraints: Atomic UI fragment (< 50 lines).
 * </module_context>
 */
?>
<script>
let currentLog = 'debug';
let autoscrollPaused = false;
const csrf = <?= json_encode($csrf_token) ?>;
window.csrf_token = csrf;
const activeTerminalUser = '<?= ($config['user'] === '0' || $config['user'] === 0) ? 'root' : $config['user'] ?>';
let statsInterval = 5000;
let statsTimer = null;
let agentFilter = 'all';
<?php
    $agentPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence';
    $homePath = $config['home_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence';
    $storageAvailable = \AICliAgents\Services\StorageMountService::isPathAvailable($agentPath)
                     && \AICliAgents\Services\StorageMountService::isPathAvailable($homePath);
?>
window.aicli_storage_available = <?= json_encode($storageAvailable) ?>;
window.aicli_storage_path = <?= json_encode($agentPath) ?>;
window.aicli_storage_classification = <?= json_encode(\AICliAgents\Services\StorageMountService::classifyPath($agentPath)) ?>;
// D-403: Permanently suppress Unraid's "unsaved changes" dialog on this plugin's pages.
// Previous approaches (property trap, DOMReady removal, clearChanged) failed because Unraid
// re-binds the beforeunload handler every time any form input fires onchange.
// Nuclear fix: intercept addEventListener and jQuery.on to BLOCK all beforeunload registration.
(function() {
    // D-404 hardening: neuter the CANCELLATION mechanism itself (see the twin
    // comment in TerminalGlobalState.php for the full rationale — same three
    // layers: Event.preventDefault lock, BeforeUnloadEvent.returnValue lock,
    // capture-phase silencer as defence-in-depth).
    try {
        var _origPD_mgr = Event.prototype.preventDefault;
        Object.defineProperty(Event.prototype, 'preventDefault', {
            value: function() {
                if (this && this.type === 'beforeunload') return;
                return _origPD_mgr.apply(this, arguments);
            },
            writable: false, configurable: false, enumerable: false
        });
    } catch(e) {}
    try {
        if (window.BeforeUnloadEvent) {
            Object.defineProperty(BeforeUnloadEvent.prototype, 'returnValue', {
                get: function() { return ''; },
                set: function(_v) { /* swallow — never prompt */ },
                configurable: false
            });
        }
    } catch(e) {}
    try {
        EventTarget.prototype.addEventListener.call(window, 'beforeunload', function(e) {
            e.stopImmediatePropagation();
        }, true);
    } catch(e) {}
    // 1. Trap formHasUnsavedChanges so it always returns false
    try { window.formHasUnsavedChanges = false; } catch(e) {}
    try {
        Object.defineProperty(window, 'formHasUnsavedChanges', { get: function() { return false; }, set: function() {}, configurable: false });
    } catch(e) {}

    // 2. Remove any existing beforeunload handlers
    window.onbeforeunload = null;

    // 3. Block native addEventListener from registering beforeunload
    var origAddEventListener = EventTarget.prototype.addEventListener;
    EventTarget.prototype.addEventListener = function(type, fn, opts) {
        if (type === 'beforeunload') return; // silently block
        return origAddEventListener.call(this, type, fn, opts);
    };

    // 4. Block jQuery .on('beforeunload') — must run after jQuery is loaded
    $(function() {
        window.onbeforeunload = null;
        $(window).off('beforeunload');
        // Patch jQuery event system to block future beforeunload bindings
        var origOn = $.fn.on;
        $.fn.on = function() {
            if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].indexOf('beforeunload') !== -1) return this;
            return origOn.apply(this, arguments);
        };
    });

    // 5. Prevent onbeforeunload property from being set
    try {
        Object.defineProperty(window, 'onbeforeunload', { get: function() { return null; }, set: function() {}, configurable: false });
    } catch(e) {}
})();
</script>
