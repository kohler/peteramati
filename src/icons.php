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
            return '<path d="M14.7 3.5h3.6L18 17l5.5-6.5 3 3-10 9-10-9 3-3L15 17z" fill="currentColor"/><path d="M31 23.3c0 2.7-2 4.2-4 4.2H5c-2 0-4-1.5-4-4V18h2.5v5c0 1.5 1 2 2.5 2h20c1.5 0 2.5-.5 2.5-2v-5H31v5.3z" fill="currentColor"/>';
        case "markdown":
            return '<path d="M4 70V2h20l20 25L64 2h20v68H64V31L44 56 24 31v39zm125 0L99 37h20V2h20v35h20z" fill="currentColor"/>';
        case "hide-comments":
            return '<path d="m240 460c-59.3 0-116.9-25.7-155.6-64.4-38.7-38.8-64.4-96.3-64.4-155.6s25.7-116.9 64.4-155.6 96.3-64.4 155.6-64.4 116.8 25.7 155.6 64.4c38.7 38.7 64.4 96.3 64.4 155.6s-25.7 116.8-64.4 155.6c-38.8 38.7-96.3 64.4-155.6 64.4zm180-220c0-51.1-19-93.6-52.7-127.3s-76.2-52.7-127.3-52.7c-43.9 0-81.4 14-112.4 39.3l253.1 253.1c25.3-31 39.3-68.5 39.3-112.4zm-360 0c0 51.1 19 93.6 52.7 127.3s76.2 52.7 127.3 52.7c43.9 0 81.4-14 112.4-39.3l-253.1-253.1c-25.3 31-39.3 68.5-39.3 112.4z" fill="currentColor"/><path d="m192.4 143.5h34.4l-8.8 29.3-26.4-26.5 0.8-2.8zm-22.9 190.8h-34.3l35.5-118.3 26.4 26.4-27.6 91.9zm132.3-190.8h34.4l-34.1 113.4-26.4-26.4 26.1-87zm-22.9 190.8h-34.3l10.3-34.2 26.4 26.4-2.4 7.8z" fill="#008"/>';
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
        assert(Ht::check_stash("i-def-download"));
        return '<svg width="1em" height="1em" style="vertical-align:-0.1em" viewBox="0 0 32 32"><use href="#i-def-download"/></svg>';
    }
    /** @return string */
    static function markdown() {
        assert(Ht::check_stash("i-def-markdown"));
        return '<svg width="1.553em" height="0.7em" viewBox="0 0 162 73"><use href="#i-def-markdown"/></svg>';
    }
    /** @return string */
    static function hide_comments() {
        assert(Ht::check_stash("i-def-hide-comments"));
        return '<svg width="1em" height="1em" style="vertical-align:-0.2em" viewBox="0 0 480 480"><use href="#i-def-hide-comments"/></svg>';
    }
}
