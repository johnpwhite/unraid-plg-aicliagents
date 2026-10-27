<?php
/**
 * <module_context>
 * Description: The parts of an Agent Store card that are built from data and that tests render on their own:
 *   the versions block of the header and the two links of the foot (docs/specs/AGENT_STORE_CARD_HEADER.md, #793).
 * Dependencies: BootSpaceService (the size text).
 * Constraints: Atomic UI fragment. A function here only returns a string. It reads no disk and measures nothing (#552).
 * </module_context>
 */

/**
 * AGENT_STORE_CARD_HEADER.md, "Foot": the two links of the card foot. Each bracket is a number from
 * the last background measurement (a file read; the card never measures, #552). While a measurement
 * runs the brackets read "(measuring…)". With no measurement the links show with no bracket.
 * "Remove old versions" is hidden when the measurement found nothing old; when every old version is in
 * use it is plain text. Both links are disabled while the agent installs.
 * @param array{measured:bool,old_bytes:int,old_in_use:bool,uninstall_bytes:?int} $foot
 */
function av2_foot_links_html(array $foot, string $id, string $name, bool $measuring, bool $busy): string {
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $dis = $busy ? ' disabled aria-disabled="true"' : '';
    $frees = static function (?int $bytes) use ($measuring, $e): string {
        if ($measuring) return '<span class="av2-frees av2-muted"> (measuring…)</span>';
        return $bytes === null ? '' : '<span class="av2-frees"> (frees ' . $e(\AICliAgents\Services\BootSpaceService::sizeText($bytes)) . ')</span>';
    };
    $out = '';
    if ($foot['old_in_use']) {
        $out .= '<span class="av2-foot-note" data-testid="foot-old-in-use-' . $e($id) . '" title="A workspace still runs these versions. Close it or switch it, then remove them.">Old versions in use</span>';
    } elseif (!$foot['measured'] || $foot['old_bytes'] > 0) {
        $out .= '<button type="button" class="av2-foot-link av2-remove-old" data-agent="' . $e($id) . '" data-testid="remove-old-' . $e($id) . '"' . $dis
            . ' onclick="if (window.aicliRemoveOldVersions) { aicliRemoveOldVersions(this.getAttribute(\'data-agent\')); }"'
            . ' title="Deletes the older versions of this agent that no workspace uses. The version you use stays. After this you cannot roll back to them.">'
            . 'Remove old versions' . $frees($foot['measured'] ? $foot['old_bytes'] : null) . '</button>';
    }
    if ($out !== '') $out .= '<span class="av2-foot-sep" aria-hidden="true"> · </span>';
    $out .= '<button type="button" class="av2-foot-link av2-uninstall-link" data-agent="' . $e($id) . '" data-testid="uninstall-' . $e($id) . '"'
        . ' data-frees="' . ($foot['uninstall_bytes'] === null ? '' : (int)$foot['uninstall_bytes']) . '"' . $dis
        . ' aria-label="Uninstall ' . $e($name) . '" onclick="uninstallAgent(\'' . $e($id) . '\', this)"'
        . ' title="Removes this agent: the version you use, its old versions and its kept copies.">'
        . 'Uninstall' . $frees($foot['uninstall_bytes']) . '</button>';
    return $out;
}

/**
 * AGENT_STORE_CARD_HEADER.md, "Header": the versions are never cut. The plain status text stays for the screen
 * reader and the old tests; the visible block holds the installed version and, when the channel offers another
 * one, the new version as one button that opens the update overlay. A channel downgrade says "channel has", not "update".
 */
function av2_versions_html(string $id, string $name, bool $installed, bool $installing, bool $versionKnown, string $installedVer,
                           string $latestVer, bool $hasUpdate, bool $hasDowngrade, string $channel, string $statusText): string {
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $out = '<span class="aicli-sr-only">' . $e($statusText) . '</span><span class="av2-versions" data-testid="agent-versions-' . $e($id) . '">';
    if ($installing) {
        $out .= '<span class="av2-installing">Installing' . ($latestVer !== 'unknown' ? ' v' . $e($latestVer) : '') . '…</span>';
    } elseif (!$installed) {
        if ($latestVer !== 'unknown') $out .= '<span class="av2-ver-installed" aria-hidden="true">v' . $e($latestVer) . ' available</span>';
    } else {
        $out .= '<span class="av2-ver-installed" aria-hidden="true">' . ($versionKnown ? 'v' . $e($installedVer) : 'version not known yet') . '</span>';
        if ($hasUpdate || $hasDowngrade) {
            $aria = $hasUpdate
                ? 'Update ' . $name . ' from ' . $installedVer . ' to ' . $latestVer . '. Shows the release notes.'
                : 'The ' . ucfirst($channel) . ' channel has ' . $latestVer . '. Shows the choices.';
            $out .= '<button type="button" class="av2-newver" data-agent="' . $e($id) . '" data-kind="' . ($hasUpdate ? 'update' : 'change') . '"'
                . ' data-version="' . $e($latestVer) . '" data-testid="newver-' . $e($id) . '" aria-label="' . $e($aria) . '"'
                . ' title="' . $e($hasUpdate ? 'See what is new in v' . $latestVer . ' and update' : 'See the choices for v' . $latestVer) . '"'
                . ' onclick="av2OpenUpdateOverlay(\'' . $e($id) . '\', this)"><span class="av2-newver-pill"><i class="fa ' . ($hasUpdate ? 'fa-arrow-up' : 'fa-arrow-down') . '" aria-hidden="true"></i> v' . $e($latestVer) . '</span></button>';
        }
    }
    return $out . '</span>';
}