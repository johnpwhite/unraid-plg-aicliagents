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

        <!-- #131: home consolidation in-progress banner (toggled by the
             get_force_reclaim_state poll). Storage actions are guarded while it shows. -->
        <div id="aicli-consolidate-banner" style="display:none; margin-bottom:12px; background:rgba(37,99,235,0.12); border:1px solid #2563eb; border-radius:6px; padding:10px 16px; font-size:12px; color:#2563eb; align-items:center; gap:8px;">
            <i class="fa fa-spinner fa-spin"></i>
            <span>Storage tidy-up in progress — reclaiming disk space for this home. This can take a few minutes; storage actions are paused and sessions resume automatically when it finishes.</span>
        </div>

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

        <!-- Agent Storage section removed in v2026.05.13.05 (WP #748 J / Phase B):
             under single-layer-per-agent, the per-agent storage cards were vestigial
             — layer-count is always 1, dirty upper is always 0, "Persist Now" /
             "Consolidate Now" are meaningless, and the only remaining state worth
             showing (storage footprint) is now in the Store card foot. Repair /
             Restore actions remain accessible via the boot-integrity banner above
             when a real layer issue is detected. See
             docs/specs/STORAGE_DURABILITY_SUPERVISOR.md §"Storage-tab redesign
             under J". -->

        <!-- Home Storage Section -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; margin-top:24px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:13px; font-weight:700;">User Home Persistence</span>
                <span id="homes-text-summary" style="font-size:11px; opacity:0.6;">...</span>
            </div>
        </div>
        <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">Home folders are saved to Flash as compressed layers so they survive a reboot; the icons on each card let you save changes, merge layers into one, fix a stuck mount, or permanently delete the data.</p>
        <div id="home-stats-container" class="storage-entity-grid">
            <!-- Dynamically populated by renderHomeStats() -->
        </div>

        <?php
        // HOME_BACKUP.md R1/R12: a second, real copy of a home on a target the
        // operator chooses, separate from the Flash layers above. Settings here
        // are PLUGIN-WIDE (one target/schedule/etc. for every user); the job
        // itself, and the "Back up now" button below, run per user (R12).
        $bkTarget  = htmlspecialchars((string)($config['backup_target'] ?? ''), ENT_QUOTES, 'UTF-8');
        $bkQuiesce = ($config['backup_quiesce'] ?? 'cold') === 'warm' ? 'warm' : 'cold';
        $bkKeep    = (int)($config['backup_keep'] ?? 5);
        if ($bkKeep < 1) $bkKeep = 5;
        $bkSchedule = (string)($config['backup_schedule'] ?? 'off');
        $bkExcludesDefault = ".claude/plugins/**\n**/node_modules/**\n.grok/marketplace-cache/**\n.gemini/antigravity-cli/**\n.cache/**\n.claude/image-cache/**\n**/*.tmp";
        $bkExcludes = htmlspecialchars((string)($config['backup_excludes'] ?? $bkExcludesDefault), ENT_QUOTES, 'UTF-8');
        $bkNudgeVal = (string)($config['backup_nudge_working'] ?? '1') === '0' ? '0' : '1';
        $bkNudge    = $bkNudgeVal === '1' ? 'checked' : '';
        // Decompose 'off' | 'daily:HH:MM' | 'weekly:D:HH:MM' for the three controls below.
        $bkMode = 'off'; $bkTime = '02:00'; $bkDay = '0';
        if (preg_match('/^daily:(\d{2}:\d{2})$/', $bkSchedule, $m)) { $bkMode = 'daily'; $bkTime = $m[1]; }
        elseif (preg_match('/^weekly:(\d):(\d{2}:\d{2})$/', $bkSchedule, $m)) { $bkMode = 'weekly'; $bkDay = $m[1]; $bkTime = $m[2]; }
        $bkDayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        ?>
        <div style="margin-top:24px;">
            <div class="aicli-card">
                <div class="aicli-card-header"><i class="fa fa-life-ring text-orange-500"></i> Home backup</div>
                <div class="aicli-card-body">
                    <p style="margin:0 0 10px;opacity:.7;font-size:12px;">Makes a second, plain copy of a user's home on a target you choose, so damage to a folder, layer, or squash file on Flash does not destroy that copy too. This applies to every user with a home — the settings below apply to everyone, but each user's backup runs on its own.</p>

                    <div style="display:flex; flex-direction:column; gap:4px; margin-bottom:10px;">
                        <label style="font-size:11px; font-weight:700;">Target</label>
                        <div style="display:flex; gap:6px;">
                            <input type="text" id="backup_target" name="backup_target" placeholder="/mnt/cache/appdata/aicli-home-backup" value="<?=$bkTarget?>" style="flex:1; min-width:0; font-family:monospace; font-size:11px;">
                            <button type="button" class="aicli-btn-slim" onclick="aicliToggleBackupPicker(); return false;" title="Browse storage targets"><i class="fa fa-folder-open"></i> Browse…</button>
                            <button type="button" class="aicli-btn-slim" onclick="aicliCheckBackupTarget(); return false;">Check</button>
                        </div>
                        <div id="aicli-backup-picker" style="display:none; margin-top:4px;"></div>
                        <div id="aicli-backup-target-result" style="font-size:10px; min-height:14px;"></div>
                        <div style="font-size:10px; opacity:.6;">Copying a home's many small files over a /mnt/user share path can freeze the host, so a share you pick here always resolves to its pool or disk path instead.</div>
                    </div>

                    <div style="display:flex; gap:24px; flex-wrap:wrap; margin-bottom:10px;">
                        <div>
                            <label style="font-size:11px; font-weight:700; display:block; margin-bottom:2px;">Quiesce</label>
                            <label style="font-size:11px; display:block;"><input type="radio" name="backup_quiesce" value="cold" <?=$bkQuiesce === 'cold' ? 'checked' : ''?> onchange="autoSaveConfig()"> Cold — closes sessions first, most consistent</label>
                            <label style="font-size:11px; display:block;"><input type="radio" name="backup_quiesce" value="warm" <?=$bkQuiesce === 'warm' ? 'checked' : ''?> onchange="autoSaveConfig()"> Warm — no close, best effort</label>
                        </div>
                        <div>
                            <label style="font-size:11px; font-weight:700; display:block; margin-bottom:2px;">Keep</label>
                            <input type="number" name="backup_keep" min="1" max="50" value="<?=$bkKeep?>" style="width:70px;" onchange="autoSaveConfig()"> snapshots
                        </div>
                        <div>
                            <label style="font-size:11px; font-weight:700; display:block; margin-bottom:2px;">Continue on relaunch</label>
                            <label style="font-size:11px;"><input type="checkbox" id="backup_nudge_working_cb" <?=$bkNudge?> onchange="aicliBackupNudgeChanged()"> Tell an agent to continue if it was working when backup started</label>
                            <input type="hidden" id="backup_nudge_working" name="backup_nudge_working" value="<?=$bkNudgeVal?>">
                        </div>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center; margin-bottom:10px; flex-wrap:wrap;">
                        <label style="font-size:11px; font-weight:700;">Schedule</label>
                        <select id="backup_schedule_mode" onchange="aicliBackupScheduleChanged()">
                            <option value="off" <?=$bkMode === 'off' ? 'selected' : ''?>>Off</option>
                            <option value="daily" <?=$bkMode === 'daily' ? 'selected' : ''?>>Daily at</option>
                            <option value="weekly" <?=$bkMode === 'weekly' ? 'selected' : ''?>>Weekly on</option>
                        </select>
                        <select id="backup_schedule_day" style="display:<?=$bkMode === 'weekly' ? 'inline-block' : 'none'?>;" onchange="aicliBackupScheduleChanged()">
                            <?php foreach ($bkDayNames as $i => $dn): ?>
                                <option value="<?=$i?>" <?=(string)$i === $bkDay ? 'selected' : ''?>><?=$dn?></option>
                            <?php endforeach; ?>
                        </select>
                        <span id="backup_schedule_at_label" style="display:<?=$bkMode === 'off' ? 'none' : 'inline'?>;">at</span>
                        <input type="time" id="backup_schedule_time" value="<?=htmlspecialchars($bkTime, ENT_QUOTES, 'UTF-8')?>" style="display:<?=$bkMode === 'off' ? 'none' : 'inline-block'?>;" onchange="aicliBackupScheduleChanged()">
                        <input type="hidden" id="backup_schedule" name="backup_schedule" value="<?=htmlspecialchars($bkSchedule, ENT_QUOTES, 'UTF-8')?>">
                    </div>

                    <div style="margin-bottom:10px;">
                        <label style="font-size:11px; font-weight:700; display:block; margin-bottom:2px;">Excludes (one glob pattern per line)</label>
                        <textarea name="backup_excludes" rows="3" style="width:100%; font-family:monospace; font-size:10px;" onchange="autoSaveConfig()"><?=$bkExcludes?></textarea>
                    </div>

                    <div style="display:flex; justify-content:flex-end; margin-bottom:14px;">
                        <button type="button" class="aicli-btn-slim" style="font-weight:700;" onclick="saveAICliAgentsManager(document.getElementById('aicli-settings-form'), false); return false;"><i class="fa fa-save"></i> Save</button>
                    </div>

                    <div id="backup-users-container" style="display:flex; flex-direction:column; gap:8px;">
                        <!-- Dynamically populated by renderBackupUsers() -->
                    </div>

                    <p style="margin:10px 0 0;opacity:.6;font-size:10px;">Restore closes every session of that user, then reopens them once the restore finishes; a session that was working is told to continue. Each snapshot above has its own Restore… button.</p>
                </div>
            </div>
        </div>
    </div>
</div>
