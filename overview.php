<?php
// overview.php -- Peteramati overview page
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty() || !$Me->isPC)
    $Me->escape();

$Conf->header("Overview", "home", ["body_class" => "want-grgraph-hash"]);

echo '<form class="pa-grade-overview">';
echo '<div class="pa-grade-overview-users"><div>',
    '<label class="checki d-inline-block"><input type="checkbox" class="checkc uich js-grgraph-highlight-course" data-pa-highlight-range="93.5-" data-pa-highlight-type="h00"><strong class="hl-h00">A</strong></label>',
    '<label class="checki d-inline-block ml-2"><input type="checkbox" class="checkc uich js-grgraph-highlight-course" data-pa-highlight-range="90-93.5" data-pa-highlight-type="h01"><strong class="hl-h01">A-</strong></label>',
    '<label class="checki d-inline-block ml-2"><input type="checkbox" class="checkc uich js-grgraph-highlight-course" data-pa-highlight-range="86.5-90" data-pa-highlight-type="h02"><strong class="hl-h02">B+</strong></label>',
    '<label class="checki d-inline-block ml-2"><input type="checkbox" class="checkc uich js-grgraph-highlight-course" data-pa-highlight-range="83.5-86.5" data-pa-highlight-type="h03"><strong class="hl-h03">B</strong></label>',
    '<label class="checki d-inline-block ml-2"><input type="checkbox" class="checkc uich js-grgraph-highlight-course" data-pa-highlight-range="80-83.5" data-pa-highlight-type="h04"><strong class="hl-h04">B-</strong></label>',
    '</div>',
    '<input type="search" class="uii uikd js-ptable-search js-ptable-search-api ml-2" placeholder="Search">',
    '<table class="pap gtable want-gtable-fixed user-gtable" id="pa-overview-table"></table>',
    '</div><div class="pa-gradegrid">';
$anonymity = 0;
foreach ($Conf->psets() as $pset) {
    if ($Me->can_view_pset($pset)
        && !$pset->disabled) {
        $anonymity |= $pset->anonymous ? 1 : 2;
        echo '<div class="pa-grgraph';
        if (!$pset->scores_visible) {
            echo ' pa-grp-hidden';
        }
        echo '" data-pa-pset="', $pset->urlkey, '">';
        echo '<button type="button" class="qo ui js-grgraph-flip prev">←</button>';
        echo '<button type="button" class="qo ui js-grgraph-flip next">→</button>';
        echo '<h4 class="title pa-grgraph-type">', htmlspecialchars($pset->title), '</h4>';
        echo '<div class="pa-plot pa-grgraph-min-yaxis"></div>';
        echo '<div class="statistics"></div></div>';
    }
}
echo '</div></form>';

$Sset = new StudentSet($Me, StudentSet::ALL);
$sj = [];
$college = $extension = false;
if (friendly_boolean($Qreq->college) || friendly_boolean($Qreq->all)) {
    $college = true;
}
if (friendly_boolean($Qreq->extension) || friendly_boolean($Qreq->all)) {
    $extension = true;
}
if (!$college && !$extension) {
    $college = $extension = true;
}
foreach ($Sset->users() as $u) {
    if ($u->extension ? $extension : $college)
        $sj[] = StudentSet::json_basics($u, ($anonymity & 1) !== 0);
}
$jd = [
    "id" => "overview",
    "checkbox" => true,
    "anonymous" => ($anonymity & 1) !== 0,
    "has_nonanonymous" => ($anonymity & 2) !== 0,
    "overridable_anonymous" => ($anonymity & 1) !== 0,
    "col" => ["rownumber", ["name" => "checkbox", "type" => "checkbox", "className" => "uic uich js-range-click js-grgraph-highlight"], "user"]
];
echo Ht::unstash(),
    '<script>$pa.pset_table($(".pa-grade-overview")[0],',
    json_encode_browser($jd), ',', json_encode_browser($sj), ');',
    '$(".pa-grgraph").each($pa.grgraph);',
    '$(window).on("resize",function(){$(".pa-grgraph").each($pa.grgraph)})',
    '</script>';

echo '<hr class="c">';
$Conf->footer();
