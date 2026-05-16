<?php
/**
 * <module_context>
 * Description: Nchan real-time subscriptions for AICliAgents Manager page.
 * Dependencies: Unraid's NchanSubscriber (bundled in dynamix.js), jQuery.
 * Constraints: Atomic UI fragment (< 80 lines). Graceful fallback to polling if Nchan unavailable.
 * </module_context>
 */
?>
<script>
(function() {
    // D-402: Nchan real-time subscriptions replace polling for install progress and storage stats.
    // NchanSubscriber is globally available from Unraid's dynamix.js bundle.
    if (typeof NchanSubscriber === 'undefined') {
        console.log('[AICli] NchanSubscriber not available — falling back to polling.');
        return;
    }

    // Storage Status Channel: Live updates after persist/consolidate/repair/wipe operations
    try {
        var storageSub = new NchanSubscriber('/sub/aicli_storage_status', {subscriber: 'websocket', reconnectTimeout: 5000});
        storageSub.on('message', function(msg) {
            try {
                var data = JSON.parse(msg);
                // WP #748 J / Phase B: per-agent storage cards removed from the
                // Storage tab; only the home + system surfaces need live updates.
                if (data && typeof renderHomeStats === 'function') {
                    renderHomeStats(data.homes);
                    renderCleanupCard(data.artifacts);
                    if (data.rootfs) {
                        $('#rootfs-bar').css('width', data.rootfs.percent + '%');
                        $('#rootfs-percent').text(data.rootfs.percent + '%');
                        $('#rootfs-text').text(data.rootfs.used_mb + 'MB / ' + data.rootfs.total_mb + 'MB');
                    }
                }
            } catch (e) { /* ignore parse errors from non-JSON messages */ }
        });
        storageSub.start();
        console.log('[AICli] Nchan: Subscribed to storage_status channel.');
    } catch (e) { console.warn('[AICli] Nchan storage subscription failed:', e); }

    // Migration Progress Channel: Live updates during Btrfs-to-SquashFS migration
    try {
        var migrationSub = new NchanSubscriber('/sub/aicli_migration', {subscriber: 'websocket', reconnectTimeout: 3000});
        migrationSub.on('message', function(msg) {
            try {
                var data = JSON.parse(msg);
                if (data && data.step) {
                    $('#migration-status-text').text(data.step);
                    if (typeof data.progress !== 'undefined') {
                        $('#migration-bar').css('width', data.progress + '%');
                    }
                    if (data.progress >= 100) {
                        setTimeout(function() { refreshStats(); }, 1000);
                    }
                }
            } catch (e) {}
        });
        migrationSub.start();
        console.log('[AICli] Nchan: Subscribed to migration channel.');
    } catch (e) { console.warn('[AICli] Nchan migration subscription failed:', e); }

    // Install Progress Channels: Subscribe per-agent when install starts
    // Exposed globally so installAgent() in ManagerStoreScripts can call it.
    window.aicli_subscribeInstall = function(agentId, onProgress) {
        try {
            var sub = new NchanSubscriber('/sub/aicli_install_' + agentId, {subscriber: 'websocket', reconnectTimeout: 2000});
            sub.on('message', function(msg) {
                try {
                    var data = JSON.parse(msg);
                    if (typeof onProgress === 'function') onProgress(data);
                } catch (e) {}
            });
            sub.start();
            console.log('[AICli] Nchan: Subscribed to install_' + agentId + ' channel.');
            return sub;
        } catch (e) {
            console.warn('[AICli] Nchan install subscription failed:', e);
            return null;
        }
    };
})();
</script>
