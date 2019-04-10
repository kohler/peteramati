<?php
// overview.php -- Peteramati overview page
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty() || !$Me->isPC)
    $Me->escape();

$Conf->header("Overview", "home");

echo '<form class="pa-grade-overview">';
echo '<div class="pa-grade-overview-users"><div class="pa-grade-overview-users-inner">',
    '<table class="pap" id="pa-overview-table"></table>',
    '</div></div>';
echo '<div class="pa-gradegrid">';
$any_anonymous = false;
foreach ($Conf->psets() as $pset) {
    if ($Me->can_view_pset($pset)
        && !$pset->disabled) {
        if ($pset->anonymous)
            $any_anonymous = true;
        echo '<div class="pa-grgraph';
        if (!$pset->grades_visible)
            echo ' pa-pset-hidden';
        echo '" data-pa-pset="', $pset->urlkey, '">';
        echo '<a class="qq ui js-grgraph-flip prev" href="">&lt;</a>';
        echo '<a class="qq ui js-grgraph-flip next" href="">&gt;</a>';
        echo '<h4 class="title">', htmlspecialchars($pset->title), '</h4>';
        echo '<div class="plot pa-grgraph-min-yaxis"></div>';
        echo '<div class="statistics"></div></div>';
    }
}
echo '</div></form>';
Ht::stash_script("\$(\".pa-grgraph\").each(pa_gradecdf);\$(window).on(\"resize\",function(){\$(\".pa-grgraph\").each(pa_gradecdf)})");

$Sset = new StudentSet($Me);
$sj = [];
foreach ($Sset->users() as $u)
    $sj[] = StudentSet::json_basics($u, false);
$jd = ["id" => "overview",
       "checkbox" => true,
       "anonymous" => $any_anonymous,
       "can_override_anonymous" => $any_anonymous,
       "col" => [["type" => "checkbox", "className" => "uix uich js-range-click js-grgraph-highlight"], "rownumber", "name"]];
echo Ht::unstash(),
    '<script>$("#pa-overview-table").each(function(){pa_render_pset_table.call(this,',
    json_encode_browser($jd), ',', json_encode_browser($sj), ')})</script>';
echo '<hr class="c">';
$Conf->footer();
