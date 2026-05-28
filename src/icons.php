<?php
// icons.php -- Peteramati helper class for user related printouts
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class Icons {
    /** @param string $name
     * @return string */
    static function svg_contents($name) {
        switch ($name) {
        case "download":
            return '<path d="M29.4 10h7.2L36 40l11-13 6 6-20 18-20-18 6-6L30 40z" fill="currentColor"/><path d="M60.5 52.6c0 5.4-4 8.4-8 8.4H11.5c-4 0-8-3-8-8V42h5v10c0 3 2 4 5 4h37c3 0 5-1 5-4v-10H60.5v10.6z" fill="currentColor"/>';
        case "plusminus":
            // "+" cross over a "−" bar, centered (PLUS-MINUS SIGN)
            return '<path d="M28.8 5h6.4v15.8h15.8v6.4h-15.8v15.8h-6.4v-15.8h-15.8v-6.4h15.8zM13 51h38v6.4h-38z" fill="currentColor"/>';
        case "markdown":
            return '<path d="M4 70V2h20l20 25L64 2h20v68H64V31L44 56 24 31v39zm125 0L99 37h20V2h20v35h20z" fill="currentColor"/>';
        case "hide-comments":
            return '<path d="m240 460c-59.3 0-116.9-25.7-155.6-64.4-38.7-38.8-64.4-96.3-64.4-155.6s25.7-116.9 64.4-155.6 96.3-64.4 155.6-64.4 116.8 25.7 155.6 64.4c38.7 38.7 64.4 96.3 64.4 155.6s-25.7 116.8-64.4 155.6c-38.8 38.7-96.3 64.4-155.6 64.4zm180-220c0-51.1-19-93.6-52.7-127.3s-76.2-52.7-127.3-52.7c-43.9 0-81.4 14-112.4 39.3l253.1 253.1c25.3-31 39.3-68.5 39.3-112.4zm-360 0c0 51.1 19 93.6 52.7 127.3s76.2 52.7 127.3 52.7c43.9 0 81.4-14 112.4-39.3l-253.1-253.1c-25.3 31-39.3 68.5-39.3 112.4z" fill="currentColor"/><path d="m192.4 143.5h34.4l-8.8 29.3-26.4-26.5 0.8-2.8zm-22.9 190.8h-34.3l35.5-118.3 26.4 26.4-27.6 91.9zm132.3-190.8h34.4l-34.1 113.4-26.4-26.4 26.1-87zm-22.9 190.8h-34.3l10.3-34.2 26.4 26.4-2.4 7.8z" fill="#008"/>';
        case "copy":
            // clipboard with arrow pointing in (head on the left); right edge gapped for the shaft
            return '<g fill="none" stroke="currentColor" stroke-width="5" stroke-linejoin="round" stroke-linecap="round"><path d="M48 30L48 19Q48 14 43 14L17 14Q12 14 12 19L12 53Q12 58 17 58L43 58Q48 58 48 53L48 42"/><path d="M28 9L32 9Q34 9 34.9 10.8L36.1 13.2Q37 15 35 15L25 15Q23 15 23.9 13.2L25.1 10.8Q26 9 28 9Z"/><path d="M52 36L28 36M35 29L28 36L35 43"/></g>';
        }
    }
    /** @param string ...$names
     * @return bool */
    static function stash_defs(...$names) {
        $svgs = [];
        foreach ($names as $name) {
            if (Ht::mark_stash("i-def-{$name}")) {
                $t = self::svg_contents($name);
                $svgs[] = "<g id=\"i-def-{$name}\">{$t}</g>";
            }
        }
        if (empty($svgs)) {
            return false;
        }
        Ht::stash_html("<svg hidden><defs>" . join("", $svgs) . "</defs></svg>");
        return true;
    }
    /** @return string */
    static function download() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64"><use href="#i-def-download"/></svg>';
    }
    /** @return string */
    static function plusminus() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64"><use href="#i-def-plusminus"/></svg>';
    }
    /** @return string */
    static function markdown() {
        return '<svg width="1.553em" height="0.7em" viewBox="0 0 162 73"><use href="#i-def-markdown"/></svg>';
    }
    /** @return string */
    static function hide_comments() {
        return '<svg width="1em" height="1em" style="vertical-align:-0.2em" viewBox="0 0 480 480"><use href="#i-def-hide-comments"/></svg>';
    }
    /** @return string */
    static function ui_copy() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64">' . self::svg_contents("copy") . '</svg>';
    }
}
