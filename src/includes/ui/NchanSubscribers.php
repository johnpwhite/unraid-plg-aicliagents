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
                // REVIEW_2026-09-13_EVENTS_AND_SECURITY.md E2: a partial payload
                // (e.g. the array-stop scripts, before this same review's fix)
                // carries no `homes`/`artifacts` at all — calling these with
                // undefined blanked the whole Storage tab. Render each surface
                // only when the push actually carries it; a full snapshot always
                // does, so this changes nothing for a normal publish.
                if (data && typeof renderHomeStats === 'function') {
                    if (data.homes) { renderHomeStats(data.homes); }
                    if (data.artifacts) { renderCleanupCard(data.artifacts); }
                    if (data.rootfs) {
                        $('#rootfs-bar').css('width', data.rootfs.percent + '%');
                        $('#rootfs-percent').text(data.rootfs.percent + '%');
                        $('#rootfs-text').text(data.rootfs.used_mb + 'MB / ' + data.rootfs.total_mb + 'MB');
                    }
                }
                // EVENT_FIRST_RECONCILIATION.md fact table: "Maintenance /
                // force-reclaim state … (aicli_storage_status, maintenance key)".
                // `maintenance`, when present, is exactly what get_force_reclaim_state
                // returns — patch the Storage tab's banner the same way pollConsolidate
                // does (ManagerStorageScripts.php), between its 30s reconcile reads.
                if (data && data.maintenance && typeof window.applyConsolidateState === 'function') {
                    window.applyConsolidateState(data.maintenance);
                }
            } catch (e) { /* ignore parse errors from non-JSON messages */ }
        });
        storageSub.start();
        console.log('[AICli] Nchan: Subscribed to storage_status channel.');
    } catch (e) { console.warn('[AICli] Nchan storage subscription failed:', e); }

    // Migration Progress Channel (D2/D9, EVENT_PUBLISH_OBSERVABILITY.md R7): ONE
    // subscriber for the ONE `aicli_migrate_progress` channel, shared by two
    // publishers that used to have their own channel each:
    //   - the Btrfs-to-SquashFS shell script (a one-time legacy conversion that
    //     can be running before any button click) — payload {step, progress, ts}.
    //   - StorageHandler::migrateProgress (the "change agent/home storage path"
    //     flow, started by saveAICliAgentsManager()) — payload
    //     {step, progress, ts, file?}. That flow shows its own modal overlay
    //     (#aicli-migrate-overlay); this always-on subscriber updates it when
    //     present, and otherwise falls back to the legacy conversion banner
    //     (#migration-status-text / #migration-bar) that ManagerLayout renders
    //     while a Btrfs conversion is in progress. saveAICliAgentsManager() no
    //     longer opens its own subscription — this one already covers it.
    try {
        var migrationSub = new NchanSubscriber('/sub/aicli_migrate_progress', {subscriber: 'websocket', reconnectTimeout: 3000});
        migrationSub.on('message', function(msg) {
            try {
                var data = JSON.parse(msg);
                if (!data || !data.step) return;
                if ($('#aicli-migrate-overlay').length && typeof updateMigrateOverlay === 'function') {
                    updateMigrateOverlay(data.step, data.progress, data.file);
                    return;
                }
                $('#migration-status-text').text(data.step);
                if (typeof data.progress !== 'undefined') {
                    $('#migration-bar').css('width', data.progress + '%');
                }
                if (data.progress >= 100) {
                    setTimeout(function() { refreshStats(); }, 1000);
                }
            } catch (e) {}
        });
        migrationSub.start();
        console.log('[AICli] Nchan: Subscribed to migrate_progress channel.');
    } catch (e) { console.warn('[AICli] Nchan migrate_progress subscription failed:', e); }

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
