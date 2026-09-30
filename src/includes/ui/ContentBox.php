<?php
/**
 * <module_context>
 *     <name>ContentBox</name>
 *     <description>Emits the script tag of assets/ui/aicli-content-box.js, which
 *     measures the part of the window that Unraid's own fixed menu does not
 *     cover (the left icon bar of the sidebar themes, gray and azure) and
 *     publishes it as the CSS variables --aicli-content-left / -right / -width
 *     (docs/specs/SIDEBAR_THEME_LAYOUT.md). Both .page files require this
 *     FIRST, before any plugin style or script, so every fixed element
 *     already has the variables at its first paint.</description>
 *     <dependencies>None.</dependencies>
 *     <constraints>A classic, blocking script: it must run before the SPA
 *     bundle, the Activity tray and voice.js read the variables.</constraints>
 * </module_context>
 */
$aicli_cb_file = '/usr/local/emhttp/plugins/unraid-aicliagents/assets/ui/aicli-content-box.js';
// Own mtime cache-buster: a session-safe overlay can replace this one file.
$aicli_cb_ver = (string) (@filemtime($aicli_cb_file) ?: time());
?>
<script src="/plugins/unraid-aicliagents/assets/ui/aicli-content-box.js?v=<?= htmlspecialchars($aicli_cb_ver) ?>"></script>
