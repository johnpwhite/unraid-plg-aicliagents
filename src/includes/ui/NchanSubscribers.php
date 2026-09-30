<?php
/**
 * <module_context>
 * Description: Live-update consumers of the AICliAgents Manager page (storage
 *   status, migration progress, per-agent install progress).
 * Dependencies: window.aicliEvents — the page's ONE multiplexed stream
 *   (assets/ui/aicli-events.js via EventStream.php, loaded before this file;
 *   docs/specs/EVENT_STREAM_MULTIPLEX.md). Fallback: Unraid's NchanSubscriber
 *   (dynamix.js), one websocket per channel, as before. jQuery.
 * Constraints: window.aicliSubscribe(channel, fn) is the ONE way a Manager
 *   script subscribes; it returns {stop()}. It decides nothing from a message.
 *   Graceful fallback to polling if neither transport is available.
 * </module_context>
 */
?>
<script>
(function() {
    // D-402: real-time subscriptions replace polling for install progress and storage stats.
    // EVENT_STREAM_MULTIPLEX.md R1/R2: every channel rides the page's ONE
    // multiplexed stream. `fn` receives the parsed payload. Returns {stop()},
    // or null when no transport exists (the caller's poll then carries on).
    window.aicliSubscribe = function(channel, fn, reconnectMs) {
        var bus = window.aicliEvents;
        if (bus && typeof bus.on === 'function' && bus.channels && bus.channels.indexOf(channel) !== -1) {
            var off = bus.on(channel, function(evt) { fn(evt.data); });
            return { stop: off };
        }
        // Fallback: a page shell without aicli-events.js, or a channel the
        // page's list does not carry (an agent registered after page load).
        if (typeof NchanSubscriber === 'undefined') return null;
        var sub = new NchanSubscriber('/sub/aicli_' + channel, {subscriber: 'websocket', reconnectTimeout: reconnectMs || 5000});
        sub.on('message', function(msg) {
            var data;
            try { data = JSON.parse(msg); } catch (e) { return; }
            fn(data);
        });
        sub.start();
        return sub;
    };
    if (!(window.aicliEvents && typeof window.aicliEvents.on === 'function') && typeof NchanSubscriber === 'undefined') {
        console.log('[AICli] No live-update transport available — falling back to polling.');
        return;
    }

    // Storage Status Channel: Live updates after persist/consolidate/repair/wipe operations
    try {
        window.aicliSubscribe('storage_status', function(data) {
            try {
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
            } catch (e) { /* a render error must not break the stream's other consumers */ }
        }, 5000);
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
        window.aicliSubscribe('migrate_progress', function(data) {
            try {
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
        }, 3000);
        console.log('[AICli] Nchan: Subscribed to migrate_progress channel.');
    } catch (e) { console.warn('[AICli] Nchan migrate_progress subscription failed:', e); }

    // Install Progress Channels: Subscribe per-agent when install starts
    // Exposed globally so installAgent() in ManagerStoreScripts can call it.
    window.aicli_subscribeInstall = function(agentId, onProgress) {
        try {
            var sub = window.aicliSubscribe('install_' + agentId, function(data) {
                try { if (typeof onProgress === 'function') onProgress(data); } catch (e) {}
            }, 2000);
            console.log('[AICli] Nchan: Subscribed to install_' + agentId + ' channel.');
            return sub;
        } catch (e) {
            console.warn('[AICli] Nchan install subscription failed:', e);
            return null;
        }
    };
})();
</script>
