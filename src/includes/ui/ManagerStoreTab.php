<?php
/**
 * <module_context>
 * Description: Agent Store tab — v2 card layout with 5-chip progressive-disclosure panels
 *              (Channel / Secrets / Runtime / Terminal / Storage).
 * Dependencies: $registry, $config, $csrf_token.
 * </module_context>
 */

use AICliAgents\Services\TmuxService;
use AICliAgents\Services\ArgsService;
use AICliAgents\Services\BootIntegrityService;
use AICliAgents\Services\HaltService;

require_once __DIR__ . '/StoreCardParts.php'; // AGENT_STORE_CARD_HEADER.md: the versions block and the foot links of an agent card.
require_once __DIR__ . '/SettingsCard.php'; // aicli_card_open and aicli_help_btn: the agent cards use the shared header (Phase 7).

$vaultFile = \AICliAgents\Services\SecretPaths::flashRoot() . '/secrets.cfg';
$vault = file_exists($vaultFile) ? @parse_ini_file($vaultFile) : [];
$versionCache = \AICliAgents\Services\VersionCheckService::getCachedResults();

// AGENT_STORE_CARD_HEADER.md R14: the two figures of the card foot (what Remove old versions frees and what
// Uninstall frees) come from the last background measurement. This is one file read; the card runs no du
// and no glob for sizes (#552).
$footData = \AICliAgents\Services\BootSpaceService::read();
$footMeasuring = \AICliAgents\Services\BootSpaceService::isMeasuring();

// WP #748 J / Phase B follow-ups: per-agent storage-health surfacing in the
// card head + foot. Pull the cached boot-integrity sweep once and key it by
// entity so the per-agent lookup inside the foreach is O(1). HaltService is a
// cheap file-existence check per call — we call it inline.
$bootSweepCache = BootIntegrityService::readCachedSweep();
$bootSweepByEntity = [];
if (is_array($bootSweepCache) && !empty($bootSweepCache['sweep']) && is_array($bootSweepCache['sweep'])) {
    foreach ($bootSweepCache['sweep'] as $entry) {
        if (!empty($entry['entity'])) $bootSweepByEntity[$entry['entity']] = $entry;
    }
}
// Compact pill labels for non-healthy states. Anything not listed renders no pill.
$av2HealthLabels = [
    'halted'           => 'halted',
    'legacy_unmanaged' => 'unmanaged layers',
    'path_drift'       => 'path drift',
    'partial_loss'     => 'missing layer',
    'total_loss'       => 'missing',
    'corrupt_layers'   => 'corrupt',
    'host_mismatch'    => 'host mismatch',
];

$av2_chip_icons = [
    'channel'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v18M5 10l7-7 7 7"/></svg>',
    'secrets'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 14v3m6-3v.01M6 11h12v9H6zM8 11V7a4 4 0 118 0v4"/></svg>',
    'runtime'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M2 20h20"/></svg>',
    'terminal' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 10l3 2-3 2M13 14h4"/></svg>',
    'storage'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v6c0 1.7 4 3 9 3s9-1.3 9-3V5M3 11v6c0 1.7 4 3 9 3s9-1.3 9-3v-6"/></svg>',
];

/**
 * Resolve the secrets schema for an agent. Manifest field is `default_secrets`
 * (renamed 2026-05-11 per WP #736 ENV_AND_SECRETS_TIERS); the legacy name
 * `secrets` is accepted as a back-compat shim for hand-written agents.json
 * entries. Everything else falls back to a single `<env_prefix>_API_KEY` field
 * so existing npm agents keep their single-field UI without registry edits.
 */
function av2_secrets_schema(array $agent): array {
    if (!empty($agent['default_secrets']) && is_array($agent['default_secrets'])) return $agent['default_secrets'];
    if (!empty($agent['secrets']) && is_array($agent['secrets'])) return $agent['secrets'];
    $prefix = $agent['env_prefix'] ?? 'AGENT';
    return [[
        'env'   => $prefix . '_API_KEY',
        'label' => $agent['name'] . ' API Key',
        'type'  => 'password',
    ]];
}

?>
<!-- TAB 2: AGENT STORE (v2 card layout) -->
<div id="tab-store" class="aicli-tab-content aicli-layout">
    <div class="aicli-cards">
        <div class="aicli-card">
            <div class="aicli-card-header" style="justify-content:space-between;">
                <span><i class="fa fa-shopping-cart text-orange-500"></i> AI Agent Marketplace</span>
                <span style="display:flex; align-items:center; gap:10px;">
                    <!-- Forgejo #447: the state of the online check, always visible: cached + age, checking, just checked, failed. -->
                    <span id="version-check-status" data-testid="version-check-status" style="font-size:11px; opacity:0.8;" aria-live="polite"></span>
                    <button type="button" id="version-check-btn" class="aicli-btn-slim" onclick="checkUpdates(this)"><i class="fa fa-refresh"></i> Check for updates</button>
                </span>
            </div>
            <div class="aicli-card-body">
                <div class="agent-filter-bar">
                    <div class="agent-search">
                        <i class="fa fa-search"></i>
                        <!-- Autofill defence — Chrome aggressively treats any visible text input near a
                             page title as a login form and drops the server username in. autocomplete="off"
                             and non-standard name aren't enough in 2026. The reliable block is `readonly`
                             on page load (Chrome never autofills readonly inputs) + removing it on first
                             focus so the user can still type normally. role="search" + type="search"
                             add a hint for assistive tech. -->
                        <input type="search" id="agent-search-input" name="agent-search-q"
                               placeholder="Search agents..."
                               role="search" aria-label="Search agents"
                               autocomplete="off" autocorrect="off" autocapitalize="off"
                               spellcheck="false" readonly
                               onfocus="this.removeAttribute('readonly'); this.dataset.userFocused='1';"
                               onkeyup="filterAgents()"
                               oninput="filterAgents()">
                    </div>
                    <?php /* NATIVE_BUTTON_STYLE.md (2026-09-29): real toggle buttons
                             (keyboard, aria-pressed) with Unraid's tab look. */ ?>
                    <div class="agent-filters" role="group" aria-label="Show agents">
                        <button type="button" class="filter-btn active" aria-pressed="true" onclick="setAgentFilter('all', this)">All</button>
                        <button type="button" class="filter-btn" aria-pressed="false" onclick="setAgentFilter('installed', this)">Installed</button>
                        <button type="button" class="filter-btn" aria-pressed="false" onclick="setAgentFilter('updates', this)">Updates</button>
                    </div>
                    <button type="button" id="agent-sort-toggle" class="agent-sort-btn"
                            data-dir="asc" onclick="toggleAgentSort()"
                            title="Toggle agent card order (A&ndash;Z / Z&ndash;A)"
                            aria-label="Toggle agent sort order">
                        <i class="fa fa-sort-alpha-asc" id="agent-sort-icon"></i>
                        <span id="agent-sort-label">A&ndash;Z</span>
                    </button>
                    <?php /* Phase 7: the cards can be collapsed and moved. This button forgets the saved order and the open state. */ ?>
                    <button type="button" class="agent-sort-btn" data-aicli-card-action="reset-layout" data-testid="reset-layout-store"
                            onclick="av2AfterLayoutReset()" title="Show the cards A&ndash;Z and open them again">
                        <i class="fa fa-undo" aria-hidden="true"></i> <span>Reset layout</span>
                    </button>
                </div>

                <div class="av2-grid" id="agent-store-grid" data-aicli-cards="store">
                    <?php
                    // Phase 7: the default order of the cards is A-Z by name (the saved order of the person wins; see settings-cards.js).
                    $registryAz = $registry;
                    uasort($registryAz, static fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
                    foreach ($registryAz as $id => $agent):
                        if ($id === 'terminal') continue;

                        $installedVer = $agent['version'] ?? '0.0.0';
                        $agentCache = $versionCache[$id] ?? null;
                        $channel = $agent['channel'] ?? 'stable';
                        $distTags = (array)($agentCache['dist_tags'] ?? []);
                        if ($channel === 'pinned') {
                            $channelVer = $agent['pinned'] ?? null;
                        } elseif ($channel === 'beta') {
                            $channelVer = $distTags['beta'] ?? ($distTags['next'] ?? null);
                        } else {
                            $channelVer = $distTags['stable'] ?? ($distTags['latest'] ?? null);
                        }
                        $latestVer = $channelVer ?: ($distTags['latest'] ?? 'unknown');
                        $versionKnown = ($installedVer && !in_array($installedVer, ['unknown','0.0.0','installed'], true));
                        $hasUpdate   = ($versionKnown && $channelVer && version_compare($channelVer, $installedVer) > 0);
                        $hasDowngrade = ($versionKnown && $channelVer && version_compare($channelVer, $installedVer) < 0);

                        // Secrets state: count configured vs total for chip label
                        $secretsSchema = av2_secrets_schema($agent);
                        $secretsSet = 0;
                        foreach ($secretsSchema as $sec) {
                            if (!empty($vault[$sec['env'] ?? ''])) $secretsSet++;
                        }
                        $secretsTotal = count($secretsSchema);

                        // WP #736: free-form agent-level env vars + secrets (rendered below
                        // the schema-fields form in the ENVS panel). Free-form secrets are
                        // the subset of secrets.cfg keys tracked in the freeform sidecar
                        // (schema-managed keys stay in the form above; pre-feature keys not
                        // in either list are treated as schema-managed = not shown here).
                        $agentFfEnvs    = \AICliAgents\Services\EnvService::getAgentEnvs($id);
                        $ffSecretKeys   = \AICliAgents\Services\SecretService::getFreeformKeys();
                        $allSecretsMask = \AICliAgents\Services\SecretService::getAgentSecretsForUI();
                        $agentFfSecrets = [];
                        foreach ($ffSecretKeys as $k) {
                            if (array_key_exists($k, $allSecretsMask)) $agentFfSecrets[$k] = $allSecretsMask[$k];
                        }
                        $hasFreeformCustom = (!empty($agentFfEnvs) || !empty($agentFfSecrets));

                        // Terminal customization state
                        $tmuxAgentCustom = count(TmuxService::getAgentDefaults($id));

                        // Args customization state
                        $agentArgs = ArgsService::getAgentArgs($id);

                        // State for left rail
                        $state = 'state-ready';
                        if (!$agent['is_installed']) $state = 'state-notinstalled';
                        elseif ($hasUpdate || $hasDowngrade) $state = 'state-info';
                        elseif ($secretsTotal > 0 && $secretsSet < $secretsTotal) $state = 'state-warn';

                        // Install-in-progress hydration
                        $statusFile = \AICliAgents\Services\SecretPaths::stateRoot() . "/install-status-{$id}";
                        $isInstalling = false;
                        $statusData = ['progress' => 0, 'status_text' => 'Installing...'];
                        if (file_exists($statusFile)) {
                            $sd = json_decode(@file_get_contents($statusFile), true);
                            if ($sd && ($sd['progress'] ?? 0) > 0 && ($sd['progress'] ?? 0) < 100) {
                                $isInstalling = true;
                                $statusData = $sd;
                            }
                        }

                        $sourceLabel = '';
                        $sourceType = $agent['source']['type'] ?? (!empty($agent['npm_package']) ? 'npm' : '');
                        if (!empty($agent['source']['type'])) {
                            $sourceLabel = $agent['source']['type'];
                            if (!empty($agent['source']['repo'])) $sourceLabel .= ' · ' . $agent['source']['repo'];
                            elseif (!empty($agent['source']['package'])) $sourceLabel .= ' · ' . $agent['source']['package'];
                        } elseif (!empty($agent['npm_package'])) {
                            $sourceLabel = 'npm · ' . $agent['npm_package'];
                        }

                        // The foot figures of this agent, from the last measurement (see av2_foot_links_html).
                        $foot = \AICliAgents\Services\BootSpaceService::footSizes($id, $footData);

                        // WP #964: locally-retained version backups (captured by the
                        // WP #965 keep-a-copy upgrade flow). Surfaced in the version
                        // picker as a "Restore a kept backup" optgroup so the user can
                        // roll back to a version they previously ran without a re-fetch.
                        $retainedBackups = \AICliAgents\Services\InstallerService::listRetainedBackups($id);
                        $retainedJson    = htmlspecialchars(json_encode($retainedBackups), ENT_QUOTES, 'UTF-8');

                        // WP #748 J / Phase B follow-ups: per-agent storage-health.
                        // Pulled from the cached boot-integrity sweep + a quick halt-
                        // marker check. Drives an in-head pill plus contextual
                        // Repair / Clear-halt buttons in the foot.
                        $agentBoot          = $bootSweepByEntity["agent/$id"] ?? null;
                        $agentHealthState   = is_array($agentBoot) ? ($agentBoot['state'] ?? 'healthy') : 'healthy';
                        $agentSiblingsCount = is_array($agentBoot) ? (int)($agentBoot['evidence']['siblings_count'] ?? 0) : 0;
                        $agentIsHalted      = $agent['is_installed'] ? HaltService::isHalted('agent', $id) : false;
                        // The pill is shown for the halt OR any boot-integrity state in $av2HealthLabels.
                        // Halt takes priority in the label (it's the user-actionable state).
                        $agentPillState = '';
                        $agentPillLabel = '';
                        if ($agentIsHalted) {
                            $agentPillState = 'halted';
                            $agentPillLabel = $av2HealthLabels['halted'];
                        } elseif (isset($av2HealthLabels[$agentHealthState])) {
                            $agentPillState = $agentHealthState;
                            $agentPillLabel = $av2HealthLabels[$agentHealthState];
                        }
                        // Repair routing — when offered:
                        //   restore_from_sibling  if a SAFE_BACKUP sibling exists
                        //   install_agent         (re-install at current channel) otherwise, for missing/corrupt
                        $agentShowRestore   = $agentPillState !== '' && $agentSiblingsCount > 0;
                        $agentShowReinstall = !$agentShowRestore && in_array($agentHealthState, ['partial_loss','total_loss','corrupt_layers'], true);
                        $agentShowClearHalt = $agentIsHalted;

                        // Channels supported per install type. Tarball has no prerelease concept
                        // (TarballSource::checkUpdates logs WARN on beta and falls back to latest),
                        // so hide Beta for tarball-backed agents. curl_install delegates to
                        // GithubReleaseSource when a repo is set — so it supports beta there.
                        $curlHasRepo = ($sourceType === 'curl_install') && !empty($agent['source']['repo']);
                        $supportsBeta = in_array($sourceType, ['npm', 'github_release'], true) || $curlHasRepo;
                        // "Beta" label wording: npm uses the npm "next"/"beta" dist-tag; GitHub
                        // release sources surface prereleases. Rendered as "Beta" either way
                        // for user consistency — the underlying source translates.
                    ?>
                    <?php
                    // SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md, Phase 7: each agent card has the shared card
                    // header (chevron, icon, name, status, (i), actions, menu). The description, the
                    // release notes link, the install method and the package name sit behind the (i).
                    // The element ids and the classes that the scripts use stay: .av2-card, #buttons-<id>,
                    // .agent-action-btn, .av2-health-pill.
                    $e8 = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
                    if (!$agent['is_installed']) {
                        $agentStatus = 'Not installed' . ($latestVer !== 'unknown' ? ' · v' . $latestVer . ' available' : '');
                    } else {
                        $agentStatus = $versionKnown ? 'v' . $installedVer : 'version not known yet';
                        if ($hasUpdate) $agentStatus .= ' · update ' . $latestVer . ' available';
                        elseif ($hasDowngrade) $agentStatus .= ' · channel has ' . $latestVer;
                    }
                    // AGENT_STORE_CARD_HEADER.md, "Header": the versions are never cut. The plain status text stays
                    // for the screen reader (and the old tests); the visible block holds the installed version and,
                    // when the channel offers another one, the new version as one button that opens the update overlay.
                    $versionsHtml = av2_versions_html($id, (string)$agent['name'], (bool)$agent['is_installed'], $isInstalling, $versionKnown, (string)$installedVer, (string)$latestVer, $hasUpdate, $hasDowngrade, (string)$channel, $agentStatus);
                    $menuItems = [];
                    if ($agent['is_installed'] && ($hasUpdate || $hasDowngrade)) {
                        $menuItems[] = ['update', ($hasUpdate ? 'Update to v' : 'Change to v') . $latestVer . '…', ' data-agent="' . $e8($id) . '"'];
                    }
                    if ($agent['changelog_url'] ?? '') $menuItems[] = ['release-notes', 'Release notes ↗', ' data-agent="' . $e8($id) . '"'];
                    // The progress view shares one slot with the chip strip (R1): same height in every state.
                    // One row holds the status line and the extra controls (Cancel, Retry, Dismiss, Details); the bar is under it.
                    $av2ProgressHtml = '<div class="av2-install-panel' . ($isInstalling ? ' active' : '') . '" id="progress-' . $e8($id) . '">'
                        . '<div class="av2-install-row">'
                        . '<div class="av2-install-status" id="status-text-' . $e8($id) . '" role="status">' . $e8($statusData['status_text'] ?? 'Installing…') . '</div>'
                        . '<div class="av2-install-extra" id="extra-' . $e8($id) . '"></div></div>'
                        . '<div class="av2-install-bar"><span id="bar-' . $e8($id) . '" style="width:' . intval($statusData['progress'] ?? 0) . '%;"></span></div></div>';
                    $changelogUrl = (string)($agent['changelog_url'] ?? '');
                    $agentHelp = '<p>' . $e8($agent['description'] ?? '') . '</p>';
                    if ($changelogUrl !== '' && preg_match('#^https?://#', $changelogUrl)) {
                        // WP #963: optional release-notes link (Antigravity has no GitHub releases).
                        $agentHelp .= '<p><a class="av2-changelog-link" href="' . $e8($changelogUrl) . '" target="_blank" rel="noopener noreferrer">Release notes ↗</a></p>';
                    }
                    if ($hasUpdate && $agent['is_installed']) $agentHelp .= aicli_help_texts()['store-update'] ?? '';
                    $pkgName = (string)($agent['source']['repo'] ?? ($agent['source']['package'] ?? ($agent['npm_package'] ?? '')));
                    if ($sourceType !== '') {
                        $agentHelp .= '<p class="av2-help-source" data-testid="agent-install-method">Install method: ' . $e8($sourceType) . '.'
                            . ($pkgName !== '' ? ' Package: ' . $e8($pkgName) . '.' : '') . '</p>';
                    }
                    $pillHtml = '';
                    if ($agentPillState !== '') {
                        // WP #748 J / Phase B follow-up (a): storage-health pill, shown only when health is not healthy.
                        $pillHtml = '<span class="av2-health-pill" data-agent="' . $e8($id) . '" data-state="' . $e8($agentPillState) . '"'
                            . ' title="Storage health: ' . $e8($agentPillState) . ($agentSiblingsCount > 0 ? ' · ' . $agentSiblingsCount . ' sibling layer(s) available for restore' : '') . '"'
                            . ' style="display:inline-block; padding:2px 8px; border-radius:9px; font-size:9px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase; color:#fff; background:#c0392b; white-space:nowrap;">⚠ ' . $e8($agentPillLabel) . '</span>';
                    }
                    ob_start();
                    ?>
                    <div class="av2-actions">
                        <div class="av2-buttons" id="buttons-<?=$id?>" style="display:flex; gap:8px; <?=$isInstalling ? 'visibility:hidden; pointer-events:none;' : ''?>">
                            <?php if ($agent['is_installed']): ?>
                                <?php /* WP #748 J / Phase B follow-up (b): contextual storage-repair buttons.
                                         Smart Repair: restore_from_sibling when a SAFE_BACKUP sibling exists
                                         (fast, no download); else install_agent (re-install at the current
                                         channel — which under J re-bakes to a single fresh layer). Clear-halt
                                         only when the entity is currently halted. */ ?>
                                <?php if ($agentShowRestore): ?>
                                    <button type="button" class="av2-btn warn av2-agent-repair-btn"
                                            data-agent="<?=$id?>" data-mode="restore"
                                            onclick="av2RepairAgent('<?=$id?>', 'restore', this)"
                                            title="<?=$agentSiblingsCount?> sibling layer(s) found in a backup directory — restore them.">
                                        <i class="fa fa-reply"></i> Restore
                                    </button>
                                <?php elseif ($agentShowReinstall): ?>
                                    <button type="button" class="av2-btn warn av2-agent-repair-btn"
                                            data-agent="<?=$id?>" data-mode="reinstall"
                                            onclick="av2RepairAgent('<?=$id?>', 'reinstall', this)"
                                            title="No SAFE_BACKUP siblings — re-install the agent at the current channel to rebuild its single-layer storage footprint.">
                                        <i class="fa fa-wrench"></i> Re-install
                                    </button>
                                <?php endif; ?>
                                <?php if ($agentShowClearHalt): ?>
                                    <button type="button" class="av2-btn av2-agent-clearhalt-btn"
                                            data-agent="<?=$id?>"
                                            onclick="av2ClearAgentHalt('<?=$id?>', this)"
                                            title="Accept the current state and clear the halt — the entity will mount normally on next boot.">
                                        <i class="fa fa-check-circle"></i> Clear halt
                                    </button>
                                <?php endif; ?>
                                <?php if ($hasUpdate): ?>
                                    <button type="button" class="av2-btn primary agent-action-btn av2-upgrade-btn" data-agent="<?=$id?>" data-testid="upgrade-<?=$id?>" aria-label="Upgrade <?=$e8($agent['name'])?> to v<?=$e8($latestVer)?>" title="See what is new in v<?=$e8($latestVer)?> and update" onclick="av2OpenUpdateOverlay('<?=$id?>', this)"><i class="fa fa-arrow-circle-up"></i> Upgrade</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <button type="button" class="av2-btn primary agent-action-btn" data-agent="<?=$id?>" onclick="installVersionAgent('<?=$id?>', this, '<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?>')"><i class="fa fa-download"></i> Install</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                    $agentActions = $pillHtml . ob_get_clean();
                    echo aicli_card_open('agent-' . $id, 'fa-puzzle-piece', (string)$agent['name'], $agentStatus, 'agent-' . $id, $agentActions, [
                        'tag' => 'article',
                        'class' => 'av2-card ' . $state . (max(strlen((string)$installedVer), strlen((string)$latestVer)) > 20 || strlen((string)$installedVer) + strlen((string)$latestVer) > 34 ? ' av2-ver-long' : ''),
                        'domId' => 'av2-card-' . $id,
                        'iconHtml' => '<span class="av2-icon"><img src="' . $e8($agent['icon_url']) . '" alt="' . $e8($agent['name']) . '"></span>',
                        'helpHtml' => $agentHelp,
                        'statusHtml' => $versionsHtml,
                        'menuItems' => $menuItems,
                        'attrs' => ' data-agent="' . $e8($id) . '" data-name="' . $e8(strtolower($agent['name'])) . '" data-av2-state="' . ($isInstalling ? 'installing' : 'idle') . '"'
                            . ' data-installed="' . ($agent['is_installed'] ? '1' : '0') . '" data-has-update="' . ($hasUpdate ? '1' : '0') . '"',
                    ]);
                    ?>

                        <?php if ($agent['is_installed']): ?>
                        <?php /* WP #903 a11y: role="group", not "tablist" — the chips are
                           aria-expanded disclosure toggles (accordion vocabulary), and
                           tablist requires role="tab" children (axe aria-required-children,
                           critical). group permits button/span children as-is. */ ?>
                        <div class="av2-slot">
                        <div class="av2-strip" role="group"<?=$isInstalling ? ' inert' : ''?> aria-label="<?=htmlspecialchars($agent['name'], ENT_QUOTES, 'UTF-8')?> settings">
                            <button type="button" class="av2-chip" data-target="channel" title="Version: which release to install" onclick="av2ChipToggle(this)"><?=$av2_chip_icons['channel']?><span class="av2-label">Version</span></button>

                            <?php
                            // Envs chip: state visible via a colored dot indicator on the chip's
                            // trailing edge (see .av2-chip.has-warn / .has-ok / .has-custom in CSS).
                            // amber = schema field(s) still unconfigured; green = all schema fields
                            // set; the .has-custom variant (cyan) lights when the user has added any
                            // free-form vars/secrets (WP #736) even if there's no schema warning.
                            $secretsState = '';
                            if ($secretsTotal > 0 && $secretsSet < $secretsTotal)        $secretsState = 'has-warn';
                            elseif ($secretsTotal > 0 && $secretsSet === $secretsTotal)  $secretsState = 'has-ok';
                            if ($secretsState === '' && $hasFreeformCustom)               $secretsState = 'has-custom';
                            elseif ($secretsState === 'has-ok' && $hasFreeformCustom)      $secretsState = 'has-custom';
                            ?>
                            <button type="button" class="av2-chip <?=$secretsState?>" data-target="secrets" title="Variables: environment values and secrets the agent starts with" onclick="av2ChipToggle(this)"><span class="av2-label">Variables</span></button>

                            <button type="button" class="av2-chip" data-target="runtime" title="Storage: how much space the agent uses" onclick="av2ChipToggle(this)"><span class="av2-label">Storage</span></button>

                            <?php $tmuxState = $tmuxAgentCustom > 0 ? 'has-custom' : ''; ?>
                            <button type="button" class="av2-chip <?=$tmuxState?>" data-target="terminal" title="Terminal: the terminal settings for this agent" onclick="av2ChipToggle(this)"><span class="av2-label">Terminal</span></button>

                            <?php $argsState = $agentArgs !== '' ? 'has-custom' : ''; ?>
                            <button type="button" class="av2-chip <?=$argsState?>" data-target="args" title="Start options: command-line options the agent starts with" onclick="av2ChipToggle(this)"><span class="av2-label">Start options</span></button>
                            <?=aicli_help_btn('store-chips-' . $id, 'the settings of ' . $agent['name'])?>
                        </div>
                        <?=$av2ProgressHtml?>
                        </div>
                        <?=aicli_help_panel('store-chips-' . $id, aicli_help_texts()['store-chips'] ?? '')?>

                        <div class="av2-panels<?=$isInstalling ? ' av2-locked' : ''?>" id="av2-panels-<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>"<?=$isInstalling ? ' inert' : ''?>>
                            <!-- Channel panel — every section stacks label-above-control to match
                                 the Release channel pattern. No Source (already at card foot).
                                 No Check-for-Updates button (already at marketplace header). -->
                            <div class="av2-panel" data-panel="channel">
                                <h4>Release channel</h4>
                                <div class="av2-seg" role="radiogroup">
                                    <input type="radio" id="ch-stable-<?=$id?>" name="ch-<?=$id?>" value="stable" onchange="av2SetChannel('<?=$id?>', 'stable')" <?=$channel === 'stable' ? 'checked' : ''?>>
                                    <label for="ch-stable-<?=$id?>">Stable</label>
                                    <?php if ($supportsBeta): ?>
                                    <input type="radio" id="ch-beta-<?=$id?>" name="ch-<?=$id?>" value="beta" onchange="av2SetChannel('<?=$id?>', 'beta')" <?=$channel === 'beta' ? 'checked' : ''?>>
                                    <label for="ch-beta-<?=$id?>">Beta</label>
                                    <?php endif; ?>
                                    <input type="radio" id="ch-pinned-<?=$id?>" name="ch-<?=$id?>" value="pinned" onchange="av2SetChannel('<?=$id?>', 'pinned')" <?=!empty($agent['pinned']) ? 'checked' : ''?>>
                                    <label for="ch-pinned-<?=$id?>">Pinned</label>
                                </div>
                                <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">Stable gets tested releases, Beta gets newer releases that may be less tested, and Pinned locks the agent to one specific version so it never changes until you pick a new one.</p>

                                <div class="av2-chan-section" id="av2-pin-row-<?=$id?>">
                                    <h4>Version</h4>
                                    <select id="version-select-<?=$id?>" class="av2-chan-select version-picker" aria-label="Version to install" data-agent="<?=$id?>" data-backups="<?=$retainedJson?>" onchange="onVersionSelect(this)">
                                        <option value="">v<?=htmlspecialchars($installedVer, ENT_QUOTES, 'UTF-8')?> (loading...)</option>
                                    </select>
                                    <p class="av2-help" style="margin-top:6px;">Pick a version to install. The Upgrade button in the card footer installs the latest version on the selected channel.</p>
                                    <?php $succId = (string)($agent['successor'] ?? ''); if ($succId !== '' && isset($registry[$succId])): $succName = htmlspecialchars((string)($registry[$succId]['name'] ?? $succId), ENT_QUOTES, 'UTF-8'); $succDom = htmlspecialchars($succId, ENT_QUOTES, 'UTF-8'); ?>
                                    <!-- #829: a newer major release that ships as a separate agent is not in this list. Say where it is. -->
                                    <p class="av2-help" data-testid="successor-hint" style="margin-top:6px;"><b><?=$succName?></b> is a separate agent, not a version of <?=htmlspecialchars((string)$agent['name'], ENT_QUOTES, 'UTF-8')?>, so it is not in this list. It runs beside this one with its own settings and sessions. <a href="#av2-card-<?=$succDom?>" onclick="var c=document.getElementById('av2-card-<?=$succDom?>'); if (c) { c.scrollIntoView({behavior:'smooth', block:'start'}); } return false;"><?=!empty($registry[$succId]['is_installed']) ? 'Go to the ' . $succName . ' card' : 'Install it from the ' . $succName . ' card'?></a>.</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Envs panel (environment variables injected at launch; secrets + config) -->
                            <div class="av2-panel" data-panel="secrets">
                                <h4>Variables and secrets · set when the agent starts</h4>
                                <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">Agent-specific settings, such as API keys, that the plugin sets as environment variables each time this agent starts.</p>
                                <?php if (!empty($agent['data_dir_env']) && is_array($agent['data_dir_env'])): ?>
                                <?php /* #4: this agent honours an env var that relocates ALL its durable state.
                                         A value pointing outside the plugin home lands outside the persistent
                                         storage and silently vanishes on reboot — say so where envs are edited. */ ?>
                                <div class="av2-datadir-note" style="margin:0 0 8px;padding:6px 8px;border-radius:4px;font-size:11px;background:var(--mild-background-color,rgba(255,165,0,.08));border:1px solid rgba(255,165,0,.35);">
                                    <?php foreach ($agent['data_dir_env'] as $ddKey => $ddHelp): ?>
                                    <div><strong><code><?=htmlspecialchars((string)$ddKey, ENT_QUOTES, 'UTF-8')?></code></strong>
                                        <?php $ddSet = (string)($agentFfEnvs[$ddKey] ?? ''); if ($ddSet !== ''): ?><span style="color:#d9822b;font-weight:600;"> · currently set to <code><?=htmlspecialchars($ddSet, ENT_QUOTES, 'UTF-8')?></code></span><?php endif; ?>
                                        — <?=htmlspecialchars((string)$ddHelp, ENT_QUOTES, 'UTF-8')?></div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <form class="av2-secrets-form" data-agent="<?=$id?>" onsubmit="return av2SaveSecrets(this, event)">
                                    <?php foreach ($secretsSchema as $sec):
                                        $env = $sec['env'] ?? '';
                                        $label = $sec['label'] ?? $env;
                                        $type = $sec['type'] ?? 'password';
                                        $current = $vault[$env] ?? '';
                                    ?>
                                        <div class="av2-row">
                                            <label><?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8')?></label>
                                            <div class="av2-row-control">
                                                <?php if ($type === 'select'): ?>
                                                    <select name="<?=htmlspecialchars($env, ENT_QUOTES, 'UTF-8')?>">
                                                        <option value=""></option>
                                                        <?php foreach (($sec['options'] ?? []) as $opt): ?>
                                                            <option value="<?=htmlspecialchars($opt, ENT_QUOTES, 'UTF-8')?>" <?=$current === $opt ? 'selected' : ''?>><?=htmlspecialchars($opt, ENT_QUOTES, 'UTF-8')?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php elseif ($type === 'text'): ?>
                                                    <input type="text" name="<?=htmlspecialchars($env, ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars($current, ENT_QUOTES, 'UTF-8')?>" placeholder="<?=htmlspecialchars($sec['placeholder'] ?? '', ENT_QUOTES, 'UTF-8')?>">
                                                <?php else: ?>
                                                    <input type="password" data-aicli-eye name="<?=htmlspecialchars($env, ENT_QUOTES, 'UTF-8')?>" value="<?=$current !== '' ? '••••••••' : ''?>" data-has-value="<?=$current !== '' ? '1' : '0'?>" placeholder="Enter value">
                                                <?php endif; ?>
                                                <?php if (!empty($sec['help'])): ?><div class="av2-help" style="margin-top:4px;"><?=htmlspecialchars($sec['help'], ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
                                            </div>
                                            <span></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="av2-panel-footer">
                                        <button type="submit" class="av2-btn primary">Save</button>
                                    </div>
                                </form>

                                <!-- WP #736: free-form Variables (general env vars, plain text,
                                     RAM-state). Workspace-level ENV tab merges over these per key. -->
                                <div class="av2-ff-block">
                                    <h4>Variables <span class="av2-ff-hint">general env vars · merged with the workspace ENV tab (workspace wins)</span></h4>
                                    <form class="av2-ff-form" data-agent="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" data-kind="var" onsubmit="return av2SaveAgentEnvs(this, event)">
                                        <div class="av2-ff-list">
                                            <?php foreach ($agentFfEnvs as $k => $v): ?>
                                            <div class="av2-ff-row">
                                                <input class="av2-ff-name" type="text" value="<?=htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8')?>" placeholder="VARIABLE_NAME">
                                                <span class="av2-ff-eq">=</span>
                                                <input class="av2-ff-val" type="text" value="<?=htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8')?>" placeholder="value">
                                                <button type="button" class="av2-ff-del" title="Remove" onclick="av2FfRemoveRow(this)"><i class="fa fa-trash-o"></i></button>
                                            </div>
                                            <?php endforeach; ?>
                                            <?php if (empty($agentFfEnvs)): ?>
                                            <div class="av2-ff-empty">No agent-level variables yet.</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="av2-panel-footer" style="justify-content:space-between;">
                                            <button type="button" class="av2-btn" onclick="av2FfAddRow(this, 'var')"><i class="fa fa-plus"></i> Add variable</button>
                                            <button type="submit" class="av2-btn primary">Save</button>
                                        </div>
                                    </form>
                                </div>

                                <!-- WP #736: free-form Secrets (masked, 600 on Flash). Distinct
                                     from the schema fields above only in that the user names them;
                                     same secrets.cfg store. Leave a •••••••• value untouched to keep it. -->
                                <div class="av2-ff-block">
                                    <h4>Secrets <span class="av2-ff-hint">masked · stored 0600 on storage · leave •••••••• to keep</span></h4>
                                    <form class="av2-ff-form" data-agent="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" data-kind="secret" onsubmit="return av2SaveAgentSecrets(this, event)">
                                        <div class="av2-ff-list">
                                            <?php foreach ($agentFfSecrets as $k => $hasVal): ?>
                                            <div class="av2-ff-row">
                                                <input class="av2-ff-name" type="text" value="<?=htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8')?>" placeholder="SECRET_NAME">
                                                <span class="av2-ff-eq">=</span>
                                                <input class="av2-ff-val" type="password" data-aicli-eye value="<?=$hasVal ? '••••••••' : ''?>" data-has-value="<?=$hasVal ? '1' : '0'?>" placeholder="value">
                                                <button type="button" class="av2-ff-del" title="Remove" onclick="av2FfRemoveRow(this)"><i class="fa fa-trash-o"></i></button>
                                            </div>
                                            <?php endforeach; ?>
                                            <?php if (empty($agentFfSecrets)): ?>
                                            <div class="av2-ff-empty">No agent-level secrets yet.</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="av2-panel-footer" style="justify-content:space-between;">
                                            <button type="button" class="av2-btn" onclick="av2FfAddRow(this, 'secret')"><i class="fa fa-plus"></i> Add secret</button>
                                            <button type="submit" class="av2-btn primary">Save</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Runtime panel -->
                            <div class="av2-panel" data-panel="runtime">
                                <?php if ($sourceType === 'npm'): ?>
                                <!-- Process limits only shown for Node-based (npm) agents. Native
                                     binaries (Goose, anything from github_release/curl_install/
                                     tarball) do not honour --max-old-space-size so the row is
                                     pointless and confusing there — hide it entirely. -->
                                <h4>Process limits</h4>
                                <div class="av2-row" data-builtin="4096">
                                    <label for="node_memory_<?=$id?>">Max RAM (MB)</label>
                                    <div><input type="number" id="node_memory_<?=$id?>" name="node_memory_<?=$id?>" value="<?=intval($config["node_memory_$id"] ?? 4096)?>" min="512" max="65536" step="512" data-agent-setting="node_memory_<?=$id?>" oninput="av2DiffCheck(this)" onchange="av2SaveAgentSetting(this)"></div>
                                    <span class="av2-info" data-tip="Heap cap passed to Node via --max-old-space-size.  ·  Built-in: 4096" aria-label="Heap cap. Built-in 4096." tabindex="0">i</span>
                                </div>
                                <p class="av2-help">Passes <code>--max-old-space-size</code> to Node-based agents.</p>
                                <h4 style="margin-top: 18px;">Storage</h4>
                                <?php else: ?>
                                <h4>Storage</h4>
                                <?php endif; ?>
                                <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">How much disk space this agent uses on the server, and whether it has recent changes that are not yet saved to permanent storage.</p>
                                <div class="av2-storage-body" data-agent="<?=$id?>">
                                    <div style="padding: 12px; text-align:center; color: var(--text-color); opacity: 0.6; font-size: 11px;">Loading…</div>
                                </div>
                            </div>

                            <!-- Terminal panel -->
                            <div class="av2-panel" data-panel="terminal">
                                <h4>Terminal · settings for every workspace of this agent</h4>
                                <div class="av2-tmux-form" data-agent="<?=$id?>">
                                    <div style="padding: 12px; text-align:center; color: var(--text-color); opacity: 0.6; font-size: 11px;">Loading current settings…</div>
                                </div>
                            </div>

                            <!-- Args panel -->
                            <div class="av2-panel" data-panel="args">
                                <h4>Start options · for every workspace of this agent</h4>
                                <form class="av2-args-form" data-agent="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" onsubmit="return av2SaveArgs(this, event)">
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <textarea name="args" rows="3" style="width:100%; box-sizing:border-box; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; padding:8px 10px; border:1px solid var(--border-color,rgba(128,128,128,0.35)); border-radius:3px; background:var(--input-bg-color,rgba(255,255,255,0.03)); color:inherit; resize:vertical;" placeholder="--max-turns 10 --output-format json"><?=htmlspecialchars($agentArgs, ENT_QUOTES, 'UTF-8')?></textarea>
                                        <div class="av2-args-error" style="display:none; color:#dc6b44; font-size:11px;"></div>
                                        <p class="av2-help">Extra flags appended to the agent's launch command; workspace-level overrides are set in the session drawer. Rejected: <code>; | &amp; ` $</code></p>
                                    </div>
                                    <div class="av2-panel-footer">
                                        <button type="submit" class="av2-btn primary">Save</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- Not-installed variant: 4 chips, but ONLY Channel is active — it opens a
                             Channel panel showing the available version + install options. The other
                             three are rendered as disabled placeholders (.av2-chip.disabled) so every
                             card has identical visual rhythm without implying functionality that isn't
                             available pre-install. -->
                        <?php /* WP #903 a11y: role="group" — see installed-variant note above. */ ?>
                        <div class="av2-slot">
                        <div class="av2-strip" role="group"<?=$isInstalling ? ' inert' : ''?> aria-label="<?=htmlspecialchars($agent['name'], ENT_QUOTES, 'UTF-8')?> settings">
                            <button type="button" class="av2-chip" data-target="channel" title="Version: pick a release, then install" onclick="av2ChipToggle(this)">
                                <span class="av2-label">Version</span>
                            </button>
                            <span class="av2-chip disabled" title="Variables become configurable after install" aria-disabled="true"><span class="av2-label">Variables</span></span>
                            <span class="av2-chip disabled" title="Storage becomes visible after install" aria-disabled="true"><span class="av2-label">Storage</span></span>
                            <span class="av2-chip disabled" title="Terminal defaults become configurable after install" aria-disabled="true"><span class="av2-label">Terminal</span></span>
                            <span class="av2-chip disabled" title="Start options become configurable after install" aria-disabled="true"><span class="av2-label">Start options</span></span>
                            <?=aicli_help_btn('store-chips-' . $id, 'the settings of ' . $agent['name'])?>
                        </div>
                        <?=$av2ProgressHtml?>
                        </div>
                        <?=aicli_help_panel('store-chips-' . $id, aicli_help_texts()['store-chips'] ?? '')?>

                        <div class="av2-panels<?=$isInstalling ? ' av2-locked' : ''?>" id="av2-panels-<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>"<?=$isInstalling ? ' inert' : ''?>>
                            <!-- Channel panel (pre-install): same stacked layout as the installed
                                 variant — label above control, no Source (shown in card foot). -->
                            <div class="av2-panel" data-panel="channel">
                                <h4>Release channel</h4>
                                <div class="av2-seg" role="radiogroup">
                                    <input type="radio" id="ch-stable-<?=$id?>" name="ch-<?=$id?>" value="stable" onchange="av2SetChannel('<?=$id?>', 'stable')" <?=$channel === 'stable' ? 'checked' : ''?>>
                                    <label for="ch-stable-<?=$id?>">Stable</label>
                                    <?php if ($supportsBeta): ?>
                                    <input type="radio" id="ch-beta-<?=$id?>" name="ch-<?=$id?>" value="beta" onchange="av2SetChannel('<?=$id?>', 'beta')" <?=$channel === 'beta' ? 'checked' : ''?>>
                                    <label for="ch-beta-<?=$id?>">Beta</label>
                                    <?php endif; ?>
                                    <input type="radio" id="ch-pinned-<?=$id?>" name="ch-<?=$id?>" value="pinned" onchange="av2SetChannel('<?=$id?>', 'pinned')" <?=!empty($agent['pinned']) ? 'checked' : ''?>>
                                    <label for="ch-pinned-<?=$id?>">Pinned</label>
                                </div>
                                <p style="margin:2px 0 8px;opacity:.7;font-size:12px;">Stable gets tested releases, Beta gets newer releases that may be less tested, and Pinned locks the agent to one specific version so it never changes until you pick a new one.</p>

                                <div class="av2-chan-section" id="av2-pin-row-<?=$id?>">
                                    <h4>Version</h4>
                                    <select id="version-select-<?=$id?>" class="av2-chan-select version-picker" aria-label="Version to install" data-agent="<?=$id?>" data-backups="<?=$retainedJson?>" onchange="onVersionSelect(this)">
                                        <option value="">v<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?> (loading...)</option>
                                    </select>
                                    <p class="av2-help" style="margin-top:6px;">Pick a version, then use the Install button below to install it.</p>
                                </div>

                                <div class="av2-panel-footer">
                                    <?php
                                    // Pass $latestVer as the explicit target so the install always
                                    // matches the currently-selected channel, independent of whichever
                                    // row happens to be selected in the version picker. Without this,
                                    // Codex-CLI in particular could land on a platform-specific pre-
                                    // release because its npm dropdown lists many dist-tags (latest,
                                    // linux-x64, alpha-linux-x64 etc.) and select.value defaulted to
                                    // whatever option was first. 'unknown' is a sentinel that the JS
                                    // layer strips to empty (backend then uses @latest).
                                    ?>
                                    <button type="button" class="av2-btn primary agent-action-btn" data-agent="<?=$id?>" onclick="installVersionAgent('<?=$id?>', this, '<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?>')"><i class="fa fa-download"></i> Install v<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?></button>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php /* AGENT_STORE_CARD_HEADER.md, "Foot": two text links that say what each one frees. The numbers are
                                 from the last background measurement. A card that is not installed has no foot. */ ?>
                        <?php if ($agent['is_installed']): ?>
                        <div class="av2-foot" data-agent="<?=$e8($id)?>">
                            <span class="av2-foot-links" id="av2-foot-links-<?=$e8($id)?>" data-testid="foot-links-<?=$e8($id)?>"><?=av2_foot_links_html($foot, $id, (string)$agent['name'], $footMeasuring || $isInstalling, $isInstalling)?></span>
                        </div>
                        <?php endif; ?>
                    <?=aicli_card_close('article')?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
