<?php
/**
 * <module_context>
 * Description: PHP helpers for the Settings page. Phase 1 holds the (i) help button and
 *   its help panel. The shared card header (aicli_card_open and aicli_card_close) comes
 *   in Phase 3 of docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md.
 * Dependencies: src/includes/ui/help/*.php (the help texts), assets/ui/settings-help.js
 *   (the behaviour), the .aicli-help-* rules in ManagerStyles.php.
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
