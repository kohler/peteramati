<?php
// overview.php -- Peteramati overview page
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty() || !$Me->isPC)
    $Me->escape();

$Conf->header("Overview", "home", ["body_class" => "want-grgraph-hash"]);

echo '<form class="pa-grade-overview">';
echo '<div class="pa-grade-overview-users"><div class="pa-grade-overview-users-inner">',
    '<label class="checki d-inline-block"><input type="checkbox" class="checkc uich js-grgraph-highlight-course" data-pa-highlight-range="93.5-" data-pa-highlight-type="h00"><strong class="hl-h00">A</strong></label> ',
    '<label class="checki d-inline-block"><input type="checkbox" class="checkc uich js-grgraph-highlight-course" data-pa-highlight-range="90-93.5" data-pa-highlight-type="h01"><strong class="hl-h01">A-</strong></label> ',
    '<label class="checki d-inline-block"><input type="checkbox" class="checkc uich js-grgraph-highlight-course" data-pa-highlight-range="86.5-90" data-pa-highlight-type="h02"><strong class="hl-h02">B+</strong></label> ',
    '<label class="checki d-inline-block"><input type="checkbox" class="checkc uich js-grgraph-highlight-course" data-pa-highlight-range="83.5-86.5" data-pa-highlight-type="h03"><strong class="hl-h03">B</strong></label> ',
    '<label class="checki d-inline-block"><input type="checkbox" class="checkc uich js-grgraph-highlight-course" data-pa-highlight-range="80-83.5" data-pa-highlight-type="h04"><strong class="hl-h04">B-</strong></label> ',
    '<table class="pap gtable" id="pa-overview-table"></table>',
    '</div></div>';
echo '<div class="pa-gradegrid">';
$any_anonymous = false;
foreach ($Conf->psets() as $pset) {
    if ($Me->can_view_pset($pset)
        && !$pset->disabled) {
        if ($pset->anonymous)
            $any_anonymous = true;
        echo '<div class="pa-grgraph';
        if (!$pset->scores_visible)
            echo ' pa-grp-hidden';
        echo '" data-pa-pset="', $pset->urlkey, '">';
        echo '<button type="button" class="btn-xlink ui js-grgraph-flip prev">&lt;</button>';
        echo '<button type="button" class="btn-xlink ui js-grgraph-flip next">&gt;</button>';
        echo '<h4 class="title">', htmlspecialchars($pset->title), '</h4>';
        echo '<div class="pa-plot pa-grgraph-min-yaxis"></div>';
        echo '<div class="statistics"></div></div>';
    }
}
echo '</div></form>';
Ht::stash_script("\$(\".pa-grgraph\").each(\$pa.grgraph);\$(window).on(\"resize\",function(){\$(\".pa-grgraph\").each(\$pa.grgraph)})");

$Sset = new StudentSet($Me, StudentSet::ALL);
$sj = [];
$college = $Qreq->college || $Qreq->all || !$Qreq->extension;
$extension = $Qreq->extension || $Qreq->all;
foreach ($Sset->users() as $u) {
    if ($u->extension ? $extension : $college)
        $sj[] = StudentSet::json_basics($u, $any_anonymous);
}
$jd = ["id" => "overview",
       "checkbox" => true,
       "anonymous" => $any_anonymous,
       "can_override_anonymous" => $any_anonymous,
       "col" => [["type" => "checkbox", "className" => "uic uich js-range-click js-grgraph-highlight"], "rownumber", "name2"]];
echo Ht::unstash(),
    '<script>$("#pa-overview-table").each(function(){$pa.render_pset_table.call(this,',
    json_encode_browser($jd), ',', json_encode_browser($sj), ')})</script>';
echo '<hr class="c">';
$Conf->footer();
