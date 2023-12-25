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
    '<table class="pap gtable want-gtable-fixed" id="pa-overview-table"></table>',
    '</div></div>';
echo '<div class="pa-gradegrid">';
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
        echo '<h4 class="title">', htmlspecialchars($pset->title), '</h4>';
        echo '<div class="pa-plot pa-grgraph-min-yaxis"></div>';
        echo '<div class="statistics"></div></div>';
    }
}
echo '</div></form>';

$Sset = new StudentSet($Me, StudentSet::ALL);
$sj = [];
$college = $Qreq->college || $Qreq->all || !$Qreq->extension;
$extension = $Qreq->extension || $Qreq->all;
foreach ($Sset->users() as $u) {
    if ($u->extension ? $extension : $college)
        $sj[] = StudentSet::json_basics($u, ($anonymity & 1) !== 0);
}
$jd = ["id" => "overview",
       "checkbox" => true,
       "anonymous" => ($anonymity & 1) !== 0,
       "has_nonanonymous" => ($anonymity & 2) !== 0,
       "overridable_anonymous" => ($anonymity & 1) !== 0,
       "col" => ["rownumber", ["type" => "checkbox", "className" => "uic uich js-range-click js-grgraph-highlight"], "user"]];
echo Ht::unstash(),
    '<script>$(".pa-grade-overview").each(function(){$pa.pset_table(this,',
    json_encode_browser($jd), ',', json_encode_browser($sj), ')});',
    '$(".pa-grgraph").each($pa.grgraph);',
    '$(window).on("resize",function(){$(".pa-grgraph").each($pa.grgraph)})',
    '</script>';

echo '<hr class="c">';
$Conf->footer();
