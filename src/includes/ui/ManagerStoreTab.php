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

$vaultFile = '/boot/config/plugins/unraid-aicliagents/secrets.cfg';
$vault = file_exists($vaultFile) ? @parse_ini_file($vaultFile) : [];
$versionCache = \AICliAgents\Services\VersionCheckService::getCachedResults();

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
                <button type="button" class="aicli-btn-slim" onclick="checkUpdates(this)"><i class="fa fa-refresh"></i> Check for Updates</button>
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
                    <div class="agent-filters">
                        <div class="filter-btn active" onclick="setAgentFilter('all', this)">All</div>
                        <div class="filter-btn" onclick="setAgentFilter('installed', this)">Installed</div>
                        <div class="filter-btn" onclick="setAgentFilter('updates', this)">Updates</div>
                    </div>
                </div>

                <div class="av2-grid" id="agent-store-grid">
                    <?php foreach ($registry as $id => $agent):
                        if ($id === 'terminal') continue;

                        $installedVer = $agent['version'] ?? '0.0.0';
                        $agentCache = $versionCache[$id] ?? null;
                        $channel = $agent['channel'] ?? 'latest';
                        $channelVer = $agentCache['dist_tags'][$channel] ?? null;
                        $latestVer = $channelVer ?: ($agentCache['dist_tags']['latest'] ?? 'unknown');
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
                        $statusFile = "/tmp/unraid-aicliagents/install-status-{$id}";
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
                    <article class="av2-card <?=$state?>"
                             data-agent="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>"
                             data-name="<?=htmlspecialchars(strtolower($agent['name']), ENT_QUOTES, 'UTF-8')?>"
                             data-installed="<?=$agent['is_installed'] ? '1' : '0'?>"
                             data-has-update="<?=$hasUpdate ? '1' : '0'?>">

                        <div class="av2-head">
                            <div class="av2-icon"><img src="<?=htmlspecialchars($agent['icon_url'], ENT_QUOTES, 'UTF-8')?>" alt="<?=htmlspecialchars($agent['name'], ENT_QUOTES, 'UTF-8')?>"></div>
                            <div>
                                <div class="av2-title"><?=htmlspecialchars($agent['name'], ENT_QUOTES, 'UTF-8')?></div>
                                <div class="av2-desc"><?=htmlspecialchars($agent['description'] ?? '', ENT_QUOTES, 'UTF-8')?></div>
                            </div>
                            <?php
                            // Stacked badge: installed version top row + upgrade indicator below.
                            // Keeps the header column narrow so description text has more horizontal
                            // room. Collapsed to a single row when no upgrade/downgrade indicator.
                            $badgeStacked = ($agent['is_installed'] && $versionKnown && ($hasUpdate || $hasDowngrade));
                            ?>
                            <span class="av2-badge <?=$badgeStacked ? 'stacked' : ''?>">
                                <span class="av2-badge-row">
                                    <span class="av2-dot"></span>
                                    <?php if ($agent['is_installed'] && $versionKnown): ?>
                                        v<?=htmlspecialchars($installedVer, ENT_QUOTES, 'UTF-8')?>
                                    <?php elseif ($agent['is_installed']): ?>
                                        <span title="Agent has a SquashFS volume on Flash but version isn't discovered yet (binary not mounted). Open the agent or run a check to refresh.">v?</span>
                                    <?php else: ?>
                                        v<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?>
                                    <?php endif; ?>
                                </span>
                                <?php if ($hasUpdate): ?>
                                    <span class="av2-badge-upgrade">↑ <?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?></span>
                                <?php elseif ($hasDowngrade): ?>
                                    <span class="av2-badge-upgrade">↓ <?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?></span>
                                <?php elseif (!$agent['is_installed']): ?>
                                    <span class="av2-badge-upgrade" style="color: var(--text-color); opacity: 0.55;">available</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <?php if ($agent['is_installed']): ?>
                        <div class="av2-strip" role="tablist">
                            <button type="button" class="av2-chip" data-target="channel" title="Release channel" onclick="av2ChipToggle(this)"><?=$av2_chip_icons['channel']?><span class="av2-label">Channel</span></button>

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
                            <button type="button" class="av2-chip <?=$secretsState?>" data-target="secrets" title="Envs — environment variables &amp; secrets injected at launch" onclick="av2ChipToggle(this)"><span class="av2-label">Envs</span></button>

                            <button type="button" class="av2-chip" data-target="runtime" title="Resources: RAM process limit + storage footprint" onclick="av2ChipToggle(this)"><span class="av2-label">Resources</span></button>

                            <?php $tmuxState = $tmuxAgentCustom > 0 ? 'has-custom' : ''; ?>
                            <button type="button" class="av2-chip <?=$tmuxState?>" data-target="terminal" title="Terminal (agent-wide tmux defaults)" onclick="av2ChipToggle(this)"><span class="av2-label">Terminal</span></button>

                            <?php $argsState = $agentArgs !== '' ? 'has-custom' : ''; ?>
                            <button type="button" class="av2-chip <?=$argsState?>" data-target="args" title="CLI Args — extra flags passed to the agent at launch" onclick="av2ChipToggle(this)"><span class="av2-label">Args</span></button>
                        </div>

                        <div class="av2-panels" id="av2-panels-<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>">
                            <!-- Channel panel — every section stacks label-above-control to match
                                 the Release channel pattern. No Source (already at card foot).
                                 No Check-for-Updates button (already at marketplace header). -->
                            <div class="av2-panel" data-panel="channel">
                                <h4>Release channel</h4>
                                <div class="av2-seg" role="radiogroup">
                                    <input type="radio" id="ch-latest-<?=$id?>" name="ch-<?=$id?>" value="latest" onchange="av2SetChannel('<?=$id?>', 'latest')" <?=$channel === 'latest' ? 'checked' : ''?>>
                                    <label for="ch-latest-<?=$id?>">Stable</label>
                                    <?php if ($supportsBeta): ?>
                                    <input type="radio" id="ch-beta-<?=$id?>" name="ch-<?=$id?>" value="beta" onchange="av2SetChannel('<?=$id?>', 'beta')" <?=$channel === 'beta' ? 'checked' : ''?>>
                                    <label for="ch-beta-<?=$id?>">Beta</label>
                                    <?php endif; ?>
                                    <input type="radio" id="ch-pinned-<?=$id?>" name="ch-<?=$id?>" value="pinned" onchange="av2SetChannel('<?=$id?>', 'pinned')" <?=!empty($agent['pinned']) ? 'checked' : ''?>>
                                    <label for="ch-pinned-<?=$id?>">Pinned</label>
                                </div>

                                <div class="av2-chan-section" id="av2-pin-row-<?=$id?>">
                                    <h4>Version</h4>
                                    <select id="version-select-<?=$id?>" class="av2-chan-select version-picker" data-agent="<?=$id?>" onchange="onVersionSelect(this)">
                                        <option value="">v<?=htmlspecialchars($installedVer, ENT_QUOTES, 'UTF-8')?> (loading...)</option>
                                    </select>
                                    <p class="av2-help" style="margin-top:6px;">Pick a version to install. Upgrade button in the card footer applies the latest on the selected channel.</p>
                                </div>
                            </div>

                            <!-- Envs panel (environment variables injected at launch; secrets + config) -->
                            <div class="av2-panel" data-panel="secrets">
                                <h4>Envs · injected at launch</h4>
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
                                                    <input type="password" name="<?=htmlspecialchars($env, ENT_QUOTES, 'UTF-8')?>" value="<?=$current !== '' ? '••••••••' : ''?>" data-has-value="<?=$current !== '' ? '1' : '0'?>" placeholder="Enter value">
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
                                    <h4>Secrets <span class="av2-ff-hint">masked · stored 0600 on Flash · leave •••••••• to keep</span></h4>
                                    <form class="av2-ff-form" data-agent="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" data-kind="secret" onsubmit="return av2SaveAgentSecrets(this, event)">
                                        <div class="av2-ff-list">
                                            <?php foreach ($agentFfSecrets as $k => $hasVal): ?>
                                            <div class="av2-ff-row">
                                                <input class="av2-ff-name" type="text" value="<?=htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8')?>" placeholder="SECRET_NAME">
                                                <span class="av2-ff-eq">=</span>
                                                <input class="av2-ff-val" type="password" value="<?=$hasVal ? '••••••••' : ''?>" data-has-value="<?=$hasVal ? '1' : '0'?>" placeholder="value">
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
                                    <label>Max RAM (MB)</label>
                                    <div><input type="number" name="node_memory_<?=$id?>" value="<?=intval($config["node_memory_$id"] ?? 4096)?>" min="512" max="65536" step="512" data-agent-setting="node_memory_<?=$id?>" oninput="av2DiffCheck(this)" onchange="av2SaveAgentSetting(this)"></div>
                                    <span class="av2-info" data-tip="Heap cap passed to Node via --max-old-space-size.  ·  Built-in: 4096" aria-label="Heap cap. Built-in 4096." tabindex="0">i</span>
                                </div>
                                <p class="av2-help">Passes <code>--max-old-space-size</code> to Node-based agents.</p>
                                <h4 style="margin-top: 18px;">Storage</h4>
                                <?php else: ?>
                                <h4>Storage</h4>
                                <?php endif; ?>
                                <div class="av2-storage-body" data-agent="<?=$id?>">
                                    <div style="padding: 12px; text-align:center; color: var(--text-color); opacity: 0.6; font-size: 11px;">Loading…</div>
                                </div>
                            </div>

                            <!-- Terminal panel -->
                            <div class="av2-panel" data-panel="terminal">
                                <h4>Terminal · agent-wide tmux defaults</h4>
                                <div class="av2-tmux-form" data-agent="<?=$id?>">
                                    <div style="padding: 12px; text-align:center; color: var(--text-color); opacity: 0.6; font-size: 11px;">Loading current settings…</div>
                                </div>
                            </div>

                            <!-- Args panel -->
                            <div class="av2-panel" data-panel="args">
                                <h4>CLI Arguments · agent-wide default</h4>
                                <form class="av2-args-form" data-agent="<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>" onsubmit="return av2SaveArgs(this, event)">
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <textarea name="args" rows="3" style="width:100%; box-sizing:border-box; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; padding:8px 10px; border:1px solid var(--border-color,rgba(128,128,128,0.35)); border-radius:3px; background:var(--input-bg-color,rgba(255,255,255,0.03)); color:inherit; resize:vertical;" placeholder="--max-turns 10 --output-format json"><?=htmlspecialchars($agentArgs, ENT_QUOTES, 'UTF-8')?></textarea>
                                        <div class="av2-args-error" style="display:none; color:#dc6b44; font-size:11px;"></div>
                                        <p class="av2-help">Extra flags appended to the agent's launch command. Workspace-level overrides are set in the session drawer. Rejected: <code>; | &amp; ` $</code></p>
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
                        <div class="av2-strip" role="tablist">
                            <button type="button" class="av2-chip" data-target="channel" title="Release channel — pick a version, then install" onclick="av2ChipToggle(this)">
                                <span class="av2-label">Channel</span>
                            </button>
                            <span class="av2-chip disabled" title="Envs become configurable after install" aria-disabled="true"><span class="av2-label">Envs</span></span>
                            <span class="av2-chip disabled" title="Resources become configurable after install" aria-disabled="true"><span class="av2-label">Resources</span></span>
                            <span class="av2-chip disabled" title="Terminal defaults become configurable after install" aria-disabled="true"><span class="av2-label">Terminal</span></span>
                            <span class="av2-chip disabled" title="CLI Args become configurable after install" aria-disabled="true"><span class="av2-label">Args</span></span>
                        </div>

                        <div class="av2-panels" id="av2-panels-<?=htmlspecialchars($id, ENT_QUOTES, 'UTF-8')?>">
                            <!-- Channel panel (pre-install): same stacked layout as the installed
                                 variant — label above control, no Source (shown in card foot). -->
                            <div class="av2-panel" data-panel="channel">
                                <h4>Release channel</h4>
                                <div class="av2-seg" role="radiogroup">
                                    <input type="radio" id="ch-latest-<?=$id?>" name="ch-<?=$id?>" value="latest" checked>
                                    <label for="ch-latest-<?=$id?>">Stable</label>
                                    <?php if ($supportsBeta): ?>
                                    <input type="radio" id="ch-beta-<?=$id?>" name="ch-<?=$id?>" value="beta">
                                    <label for="ch-beta-<?=$id?>">Beta</label>
                                    <?php endif; ?>
                                    <input type="radio" id="ch-pinned-<?=$id?>" name="ch-<?=$id?>" value="pinned">
                                    <label for="ch-pinned-<?=$id?>">Pinned</label>
                                </div>

                                <div class="av2-chan-section" id="av2-pin-row-<?=$id?>">
                                    <h4>Version</h4>
                                    <select id="version-select-<?=$id?>" class="av2-chan-select version-picker" data-agent="<?=$id?>" onchange="onVersionSelect(this)">
                                        <option value="">v<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?> (loading...)</option>
                                    </select>
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

                        <!-- Install progress sits in the sub-card region (where the panel content
                             renders), full-width. Not inside the cramped footer row. When installing,
                             the body region hides the chip strip + panels and shows this instead. -->
                        <div class="av2-install-panel <?=$isInstalling ? 'active' : ''?>" id="progress-<?=$id?>">
                            <div class="av2-install-status" id="status-text-<?=$id?>"><?=htmlspecialchars($statusData['status_text'] ?? 'Installing…', ENT_QUOTES, 'UTF-8')?></div>
                            <div class="av2-install-bar"><span id="bar-<?=$id?>" style="width:<?=intval($statusData['progress'] ?? 0)?>%;"></span></div>
                        </div>

                        <div class="av2-foot">
                            <span class="av2-meta"><?=htmlspecialchars($sourceLabel ?: '', ENT_QUOTES, 'UTF-8')?></span>
                            <div class="av2-actions">
                                <div class="av2-buttons" id="buttons-<?=$id?>" style="display:flex; gap:8px; <?=$isInstalling ? 'visibility:hidden; pointer-events:none;' : ''?>">
                                    <?php if ($agent['is_installed']): ?>
                                        <?php if ($hasUpdate): ?>
                                            <button type="button" class="av2-btn primary agent-action-btn" data-agent="<?=$id?>" onclick="installVersionAgent('<?=$id?>', this, '<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?>')"><i class="fa fa-arrow-circle-up"></i> Upgrade</button>
                                        <?php endif; ?>
                                        <button type="button" class="av2-btn danger" onclick="uninstallAgent('<?=$id?>', this)" title="Uninstall"><i class="fa fa-trash"></i> Uninstall</button>
                                    <?php else: ?>
                                        <button type="button" class="av2-btn primary agent-action-btn" data-agent="<?=$id?>" onclick="installVersionAgent('<?=$id?>', this, '<?=htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8')?>')"><i class="fa fa-download"></i> Install</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
