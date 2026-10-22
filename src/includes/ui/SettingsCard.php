<?php
/**
 * <module_context>
 * Description: PHP helpers for the Settings page. The (i) help button and its help panel
 *   (Phase 1) and the shared card header, with its collapse button, status line and menu
 *   (aicli_card_open and aicli_card_close, Phase 3 of
 *   docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md).
 * Dependencies: src/includes/ui/help/*.php (the help texts), assets/ui/settings-help.js and
 *   assets/ui/settings-cards.js (the behaviour), the .aicli-help-* and .aicli-card-head
 *   rules in ManagerStyles.php.
 * Constraints: Atomic UI fragment (< 150 lines). A function here only returns a string.
 *   The same help id is used for the button and for the panel.
 * </module_context>
 */

if (!function_exists('aicli_help_id')) {
    /** A help id may hold only lower-case letters, digits and dashes. */
    function aicli_help_id(string $helpId): string
    {
        return preg_replace('/[^a-z0-9-]/', '', strtolower($helpId)) ?? '';
    }
}

if (!function_exists('aicli_help_texts')) {
    /** Every help text of every file in help/*.php, keyed by help id. Read once. */
    function aicli_help_texts(): array
    {
        static $all = null;
        if ($all === null) {
            $all = [];
            foreach (glob(__DIR__ . '/help/*.php') ?: [] as $file) {
                $part = require $file;
                if (is_array($part)) {
                    $all = array_merge($all, $part);
                }
            }
        }
        return $all;
    }
}

if (!function_exists('aicli_help_btn')) {
    /**
     * The (i) button. Put it inside the label of a setting row.
     * The label feeds the accessible name: "About <label>".
     */
    function aicli_help_btn(string $helpId, string $label): string
    {
        $id = aicli_help_id($helpId);
        $name = htmlspecialchars('About ' . $label, ENT_QUOTES, 'UTF-8');
        return '<button type="button" class="aicli-help-btn" data-aicli-help="' . $id . '"'
            . ' data-testid="help-btn-' . $id . '" aria-label="' . $name . '"'
            . ' aria-expanded="false" aria-controls="aicli-help-' . $id . '">'
            . '<i class="fa fa-info-circle" aria-hidden="true"></i></button>';
    }
}

if (!function_exists('aicli_help_panel')) {
    /**
     * The help panel. Put it under the control of the row. It is hidden until the (i) opens it.
     * $html is trusted markup. When it is null, the text comes from the help files.
     */
    function aicli_help_panel(string $helpId, ?string $html = null): string
    {
        $id = aicli_help_id($helpId);
        if ($html === null) {
            $html = (string)(aicli_help_texts()[$helpId] ?? '');
        }
        return '<div class="aicli-help-panel" id="aicli-help-' . $id . '" data-testid="help-panel-' . $id . '"'
            . ' role="note" hidden>' . $html . '</div>';
    }
}

if (!function_exists('aicli_card_open')) {
    /**
     * The shared card header, the card help panel, the [more] menu and the opening tag of the body.
     * $icon is a Font Awesome name such as "fa-globe". $status is the one-line status that the
     * server builds from $config. $helpId is a key of the help files. $actionsHtml holds the
     * existing buttons of the card. $opts: format (the status format for the live update),
     * bodyClass, domId (keeps the element id that a script already uses), closed (force a
     * collapsed default), warning (the card opens itself), pos (the default place in the list; a
     * tab that adds cards later, such as Storage, gives each card a fixed place). Phase 7 adds: tag
     * (article for a list item), class (more classes), attrs (more attributes, trusted markup),
     * iconHtml (an image in place of the Font Awesome icon), helpHtml (a help text that the server
     * builds, for a card whose text is data), style (an inline style on the card).
     * A status that starts with "Off" makes the card start collapsed.
     */
    function aicli_card_open(string $id, string $icon, string $title, string $status, string $helpId, string $actionsHtml = '', array $opts = []): string
    {
        $id = aicli_help_id($id);
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $closed = !empty($opts['closed']) || preg_match('/^Off\b/i', $status) === 1;
        $attrs = ' data-aicli-card="' . $id . '" data-testid="card-' . $id . '"'
            . ($closed ? ' data-aicli-default="closed"' : '')
            . (!empty($opts['format']) ? ' data-aicli-status-format="' . $e((string)$opts['format']) . '"' : '')
            . (!empty($opts['warning']) ? ' data-aicli-warning="1"' : '')
            . (isset($opts['pos']) ? ' data-aicli-pos="' . (int)$opts['pos'] . '"' : '');
        $menu = 'aicli-card-menu-' . $id;
        $btn = static fn(string $act, string $label): string => '<button type="button" class="aicli-btn-slim aicli-card-menu-item"'
            . ' data-aicli-card-action="' . $act . '" data-testid="card-' . $act . '-' . $id . '">' . $label . '</button>';
        $tag = preg_replace('/[^a-z]/', '', strtolower((string)($opts['tag'] ?? 'div'))) ?: 'div';
        $extra = !empty($opts['class']) ? ' ' . $e((string)$opts['class']) : '';
        $style = !empty($opts['style']) ? ' style="' . $e((string)$opts['style']) . '"' : '';
        $iconHtml = isset($opts['iconHtml']) ? (string)$opts['iconHtml'] : '<i class="fa ' . $e($icon) . ' text-orange-500" aria-hidden="true"></i>';
        return '<' . $tag . ' class="aicli-card' . $extra . '" id="' . aicli_help_id((string)($opts['domId'] ?? ('aicli-card-' . $id))) . '"' . $attrs . $style . (string)($opts['attrs'] ?? '') . '>'
            . '<div class="aicli-card-head" data-testid="card-head-' . $id . '">'
            . '<button type="button" class="aicli-card-toggle" id="aicli-card-toggle-' . $id . '" data-aicli-card-toggle'
            . ' data-testid="card-toggle-' . $id . '" aria-expanded="true" aria-controls="aicli-card-body-' . $id . '"'
            . ' aria-describedby="aicli-card-status-' . $id . '"><i class="fa fa-chevron-down aicli-card-chevron" aria-hidden="true"></i>'
            . $iconHtml
            . '<span class="aicli-card-title">' . $e($title) . '</span></button>'
            . '<span class="aicli-card-status" id="aicli-card-status-' . $id . '" data-aicli-card-status data-testid="card-status-' . $id . '">' . $e($status) . '</span>'
            . aicli_help_btn($helpId, $title) . $actionsHtml
            . '<button type="button" class="aicli-card-menu-btn" data-aicli-card-menu aria-haspopup="true" aria-expanded="false" aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown"'
            . ' aria-controls="' . $menu . '" aria-label="Menu of the card ' . $e($title) . '" data-testid="card-menu-' . $id . '">'
            . '<i class="fa fa-ellipsis-v" aria-hidden="true"></i></button></div>'
            . aicli_help_panel($helpId, isset($opts['helpHtml']) ? (string)$opts['helpHtml'] : null)
            . '<div class="aicli-card-menu" id="' . $menu . '" role="group" aria-label="Card layout" hidden>'
            . $btn('move-up', 'Move up') . $btn('move-down', 'Move down') . $btn('move-top', 'Move to top')
            . $btn('collapse-all', 'Collapse all') . $btn('expand-all', 'Expand all') . $btn('reset-layout', 'Reset layout') . '</div>'
            . '<div class="aicli-card-body ' . $e((string)($opts['bodyClass'] ?? '')) . '" id="aicli-card-body-' . $id . '">';
    }
}

if (!function_exists('aicli_card_close')) {
    /** The end of the body and of the card. $tag is the tag that aicli_card_open used. */
    function aicli_card_close(string $tag = 'div'): string
    {
        return '</div></' . (preg_replace('/[^a-z]/', '', strtolower($tag)) ?: 'div') . '>';
    }
}
