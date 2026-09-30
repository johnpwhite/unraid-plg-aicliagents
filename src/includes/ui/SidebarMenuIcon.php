<?php
/**
 * <module_context>
 *     <name>SidebarMenuIcon</name>
 *     <description>The plugin's logo as the icon of its main-menu entry on
 *     Unraid's SIDEBAR themes (gray, azure), where the main menu is a 64 px
 *     vertical icon bar (docs/specs/SIDEBAR_THEME_LAYOUT.md, 2026-09-29).
 *     Unraid gives a Tasks page an icon there only through a `Code=` glyph of
 *     its icon fonts; without one the bar showed the clipped label "AICli".
 *     This rule draws the logo in the glyph's place: a 26 px box (the size of
 *     Unraid's own glyphs) that paints the logo's shape in the text colour of
 *     the entry, so it is grey in the dark bar and light on the red hover and
 *     active background, the same as Unraid's own icons. The label stays
 *     Unraid's own: clipped in the 64 px bar, shown when the entry expands on
 *     hover.</description>
 *     <dependencies>None. AICliMenuIcon.page (a Menu="Buttons" page, which
 *     Unraid evaluates in the head of EVERY page) echoes styleTag().</dependencies>
 *     <constraints>Every selector starts with `.Theme--sidebar`, the class
 *     Unraid puts on html for a sidebar theme only, so the top-menu themes
 *     (white, black) do not change. The rule must win over Unraid's own
 *     `.nav-item a[href='/AICliAgents']:before{content:'\f0d0'}` (generated
 *     from the page's Code= fallback glyph), so it carries `#menu`.</constraints>
 * </module_context>
 */

if (!class_exists('SidebarMenuIcon', false)) {
    final class SidebarMenuIcon
    {
        /** The Tasks page name; Unraid links the menu entry to "/<name>". */
        public const PAGE = 'AICliAgents';

        /** The plugin's logo: the same image as on the Plugins page (.plg icon=). */
        public const ICON_WEB = '/plugins/unraid-aicliagents/assets/icons/google-gemini.png';
        public const ICON_FILE = '/usr/local/emhttp/plugins/unraid-aicliagents/assets/icons/google-gemini.png';

        /** The logo URL with the plugin's mtime cache-buster (same as ContentBox.php). */
        public static function iconUrl(?string $file = null): string
        {
            $file = $file ?? self::ICON_FILE;
            $ver = (string) (@filemtime($file) ?: time());
            return self::ICON_WEB . '?v=' . rawurlencode($ver);
        }

        /** The CSS rules for the menu entry. Sidebar themes only. */
        public static function css(string $iconUrl): string
        {
            $sel = ".Theme--sidebar #menu .nav-item a[href='/" . self::PAGE . "']::before";
            // Only URL characters: the value can never end the rule.
            $url = "url('" . preg_replace('~[^A-Za-z0-9/._?=&%-]~', '', $iconUrl) . "')";
            return $sel . " {\n"
                . "    content: '';\n"
                . "    display: block;\n"
                . "    flex: 0 0 26px;\n"
                . "    width: 26px;\n"
                . "    height: 26px;\n"
                . "    background: currentColor;\n"
                . "    -webkit-mask: $url center / contain no-repeat;\n"
                . "    mask: $url center / contain no-repeat;\n"
                . "}\n"
                // A browser without CSS masks shows the logo in its own colour.
                . "@supports not ((mask-image: none) or (-webkit-mask-image: none)) {\n"
                . "    $sel { background: $url center / contain no-repeat; }\n"
                . "}\n";
        }

        public static function styleTag(?string $iconUrl = null): string
        {
            return '<style id="aicli-sidebar-menu-icon">' . "\n"
                . self::css($iconUrl ?? self::iconUrl()) . "</style>\n";
        }
    }
}
