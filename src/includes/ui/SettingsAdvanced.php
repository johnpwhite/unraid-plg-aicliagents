<?php
/**
 * <module_context>
 * Description: The "Advanced" part of a Settings card (Phase 5 of
 *   docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md). A native details element with a 44 px
 *   summary and its own (i) button.
 * Dependencies: SettingsCard.php (aicli_help_id, aicli_help_btn, aicli_help_panel), the
 *   .aicli-adv rules in ManagerStyles.php, help/cards.php (the key "adv-<cardId>").
 * Constraints: Atomic UI fragment. A function here only returns a string. The panel of the (i)
 *   sits before the details element, so the (i) works while the section is closed.
 * </module_context>
 */
require_once __DIR__ . '/SettingsCard.php';

if (!function_exists('aicli_adv_open')) {
    /**
     * The opening of the Advanced part of a card. $cardTitle feeds the accessible name of the
     * (i): "About Advanced settings of <title>". Close it with aicli_adv_close().
     */
    function aicli_adv_open(string $cardId, string $cardTitle): string
    {
        $id = aicli_help_id($cardId);
        $helpId = 'adv-' . $id;
        return aicli_help_panel($helpId)
            . '<details class="aicli-adv" id="aicli-adv-' . $id . '" data-testid="adv-' . $id . '">'
            . '<summary class="aicli-adv-summary" data-testid="adv-summary-' . $id . '"><span>Advanced</span>'
            . aicli_help_btn($helpId, 'Advanced settings of ' . $cardTitle) . '</summary>'
            . '<div class="aicli-adv-body">';
    }
}

if (!function_exists('aicli_adv_close')) {
    /** The end of the Advanced part. */
    function aicli_adv_close(): string
    {
        return '</div></details>';
    }
}
