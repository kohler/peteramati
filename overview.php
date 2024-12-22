<?php
// overview.php -- Peteramati overview page
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty() || !$Me->isPC) {
    $Me->escape();
}

class Overview_Page {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var Qrequest
     * @readonly */
    public $qreq;


    function __construct(Contact $viewer, Qrequest $qreq)  {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
    }

    static function go(Contact $viewer, Qrequest $qreq) {
        $op = new Overview_Page($viewer, $qreq);
        $op->handle_requests();
        $op->render_page();
    }

    function handle_requests() {
    }

    function render_page() {
        $this->conf->header("Overview", "home", ["body_class" => "want-grgraph-hash"]);

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

        // summarize
        $display = $this->conf->config->_overview->display ?? ["*"];
        $anonymous = $this->conf->config->_overview->anonymous ?? null;
        $have = [];
        $anonymity = 0;
        foreach ($display as $req) {
            $ge = null;
            if (($dot = strpos($req, ".")) !== false) {
                $psets = [$this->conf->pset_by_key(substr($req, 0, $dot))];
                $ge = $psets[0]->gradelike_by_key(substr($req, $dot + 1));
            } else if ($req !== "*") {
                $psets = [$this->conf->pset_by_key($req)];
            } else {
                $psets = [];
                foreach ($this->conf->psets() as $pset) {
                    if ($this->viewer->can_view_pset($pset)
                        && !$pset->disabled
                        && !in_array($pset->key, $have))
                        $psets[] = $pset;
                }
            }
            foreach ($psets as $pset) {
                if (!$this->viewer->can_view_pset($pset) || $pset->disabled) {
                    continue;
                }
                $have[] = $ge ? "{$pset->key}.{$ge->key}" : $pset->key;
                $anonymity |= $pset->anonymous ? 1 : 2;
                echo '<div class="pa-grgraph';
                if (!$pset->scores_visible) {
                    echo ' pa-grp-hidden';
                }
                echo '" data-pa-pset="', $pset->urlkey;
                if ($ge) {
                    echo '" data-pa-grade="', $ge->key;
                }
                echo '">';
                echo '<button type="button" class="qo ui js-grgraph-flip prev">←</button>';
                echo '<button type="button" class="qo ui js-grgraph-flip next">→</button>';
                echo '<h4 class="title pa-grgraph-type">', htmlspecialchars($pset->title);
                if ($ge) {
                    echo ".", htmlspecialchars($ge->title);
                }
                echo '</h4><div class="pa-plot pa-grgraph-min-yaxis"></div><div class="statistics"></div></div>';
            }
        }
        echo '</div></form>';
        $anonymous = $anonymous ?? ($anonymity & 1) !== 0;

        $sset_flags = StudentSet::ENROLLED;
        if (friendly_boolean($this->qreq->college) || friendly_boolean($this->qreq->all)) {
            $sset_flags |= StudentSet::COLLEGE;
        }
        if (friendly_boolean($this->qreq->extension) || friendly_boolean($this->qreq->all)) {
            $sset_flags |= StudentSet::DCE;
        }
        if (($sset_flags & (StudentSet::COLLEGE | StudentSet::DCE)) === 0) {
            $sset_flags |= StudentSet::COLLEGE | StudentSet::DCE;
        }
        $sset = new StudentSet($this->viewer, $sset_flags);

        $sj = [];
        foreach ($sset->users() as $u) {
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
        $this->conf->footer();
    }
}

Overview_Page::go($Me, $Qreq);
