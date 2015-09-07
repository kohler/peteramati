<?php
// index.php -- Peteramati home page
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// Distributed under an MIT-like license; see LICENSE

require_once("lib/navigation.php");

function choose_page($page) {
    if ($page !== "" && $page[0] === "~") {
        $xpage = Navigation::path_component(0, true);
        Navigation::set_path("/" . $page . Navigation::path_suffix(1));
        $page = Navigation::set_page($xpage ? : "index");
    }
    if ($page === "index")
        return null;
    if (is_readable($page . ".php")
        /* The following is paranoia (currently can't happen): */
        && strpos($page, "/") === false)
        return $page . ".php";
    else if (preg_match(',\A(?:images|scripts|stylesheets)\z,', $page)) {
        $_REQUEST["file"] = $page . Navigation::path();
        return "cacheable.php";
    } else
        Navigation::redirect_site("index");
}

if (($page = choose_page(Navigation::page())))
    include $page;
else
    require_once("pages/home.php");
