<?php
// overview.php -- Peteramati overview page
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty() || !$Me->isPC)
    $Me->escape();

$Conf->header("Overview", "home");

echo '<div class="pa-grade-overview">';
echo '<div class="pa-grade-overview-users"></div>';
echo '<div class="pa-gradegrid">';
foreach ($Conf->psets() as $pset) {
    if ($Me->can_view_pset($pset)
        && !$pset->disabled) {
        echo '<div class="pa-grgraph';
        if (!$pset->grades_visible)
            echo ' pa-pset-hidden';
        echo '" data-pa-pset="', $pset->urlkey, '">';
        echo '<a class="qq ui js-grgraph-flip prev" href="">&lt;</a>';
        echo '<a class="qq ui js-grgraph-flip next" href="">&gt;</a>';
        echo '<h4 class="title">', htmlspecialchars($pset->title), '</h4>';
        echo '<div class="plot" style="height:200px"></div>';
        echo '<div class="statistics"></div></div>';
    }
}
echo '</div></div>';
Ht::stash_script("\$(\".pa-grgraph\").each(pa_gradecdf);\$(window).on(\"resize\",function(){\$(\".pa-grgraph\").each(pa_gradecdf)})");
echo '<hr class="c">';
$Conf->footer();
