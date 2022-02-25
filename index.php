<?php
// index.php -- Peteramati home page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("lib/navigation.php");

/** @param NavigationState $nav
 * @return string */
function choose_page($nav) {
    $page = $nav->page;
    if ($page !== "" && $page[0] === "~") {
        $xpage = $nav->path_component(0, true);
        $nav->set_path("/" . $nav->raw_page . $nav->path_suffix(1));
        $page = $nav->set_page($xpage ?? "index");
    }
    $i = strlen($page) - 4;
    if ($i > 0 && substr($page, $i) === ".php") {
        $page = substr($page, 0, $i);
    }
    if ($page === "index") {
        return null;
    }
    if (is_readable($page . ".php")
        /* The following is paranoia (currently can't happen): */
        && strpos($page, "/") === false) {
        return $page . ".php";
    } else if ($page === "images" || $page === "scripts" || $page === "stylesheets") {
        $_GET["file"] = $page . $nav->path;
        return "cacheable.php";
    } else {
        header("HTTP/1.0 404 Not Found");
        exit;
    }
}

if (($page = choose_page(Navigation::get()))) {
    include $page;
} else {
    require_once("pages/home.php");
}
