<?php
/**
 * <module_context>
 * Description: HTML layout for the Storage tab in AICliAgents Manager.
 * Dependencies: $csrf_token.
 * Constraints: Atomic UI fragment (< 100 lines).
 * </module_context>
 */
?>
<!-- TAB 3: STORAGE -->
<div id="tab-storage" class="aicli-tab-content aicli-layout">
    <div style="width: 100%;">

        <!-- Phase 4a: Boot Integrity Banner (populated by JS on tab open) -->
        <div id="aicli-boot-integrity-banner" style="display:none; margin-bottom:12px;"></div>

        <!-- System Resources -->
        <div class="aicli-card">
            <div class="aicli-card-header"><i class="fa fa-heartbeat text-orange-500"></i> System Resources</div>
            <div class="aicli-card-body">
                <div style="padding:10px; background:rgba(255,255,255,0.02); border-radius:4px;">
                    <div style="display:flex; justify-content:space-between; font-size:11px; margin-bottom:4px; opacity:0.8;">
                        <span>Unraid RAM Disk (Rootfs)</span>
                        <span id="rootfs-text">...</span>
                    </div>
                    <div class="stat-bar-wrap">
                        <div id="rootfs-bar" class="stat-bar-fill" style="background:#9C27B0;"></div>
                        <div class="stat-bar-text" id="rootfs-percent">0%</div>
                    </div>
                    <div style="font-size:9px; opacity:0.6; margin-top:4px;">Global Unraid OS RAM usage.</div>
                </div>
            </div>
        </div>

        <!-- Agent Storage Section -->
        <!-- WP #269: Flash/RAM colour key removed from section header (was floating
             far-right of the page title). The colours are self-evident now: Flash =
             dark blue progress bar segment, RAM = orange. The ZRAM row in the layer
             list (WP #267) carries the same orange marker so the legend is implicit. -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:13px; font-weight:700;">SquashFS Agent Storage</span>
                <span id="agents-text-summary" style="font-size:11px; opacity:0.6;">...</span>
            </div>
        </div>
        <div id="agents-stats-container" class="storage-entity-grid">
            <!-- Dynamically populated by renderAgentStats() -->
        </div>

        <!-- Home Storage Section -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; margin-top:24px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:13px; font-weight:700;">User Home Persistence</span>
                <span id="homes-text-summary" style="font-size:11px; opacity:0.6;">...</span>
            </div>
        </div>
        <div id="home-stats-container" class="storage-entity-grid">
            <!-- Dynamically populated by renderHomeStats() -->
        </div>
    </div>
</div>
