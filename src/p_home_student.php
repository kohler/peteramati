<?php
// src/p_home_student.php -- Peteramati home page, student view
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Home_Student_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Contact */
    public $viewer;

    function __construct(Contact $user, Contact $viewer) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->viewer = $viewer;
    }


    /** @param list<FlagTableRow> $flags */
    private function render_home_pset(PsetView $info, $flags) {
        echo "<hr>\n";
        $user_can_view = $info->user->can_view_pset($info->pset);
        if (!$user_can_view) {
            echo '<div class="pa-grp-hidden">';
        }
        $pseturl = $info->hoturl("pset", ["commit" => null]);
        echo "<h2><a class=\"btn\" style=\"font-size:inherit\" href=\"", $pseturl, "\">",
            htmlspecialchars($info->pset->title), "</a>";
        $x = [];
        $c = null;
        if ($info->user_can_view_grade() && $info->needs_answers()) {
            $x[] = "empty";
            $c = "gradesmissing";
        }
        if ($info->user_can_view_score() && $info->can_view_nonempty_score()) {
            if ($info->has_visible_required_scores()) {
                $x[] = "grade ready";
            } else {
                $x[] = "grade partially ready";
            }
            $c = "gradesready";
        }
        if ($x) {
            echo ' <a class="', $c, '" href="', $pseturl, '">(', join(", ", $x), ')</a>';
        }
        echo "</h2>";
        ContactView::echo_deadline_group($info);
        ContactView::echo_partner_group($info);
        ContactView::echo_repo_group($info);
        if ($info->repo) {
            $info->repo->refresh(30);
            Ht::stash_script("\$pa.checklatest(null)", "pa_checklatest");
        }
        ContactView::echo_commit_groups($info);
        if (!empty($flags) && $info->can_view_repo_contents()) {
            usort($flags, function ($a, $b) {
                return strnatcmp($a->flagid, $b->flagid);
            });
            echo '<div class="pa-p"><div class="pa-pt">flags</div><div class="pa-pv"><table><tbody>';
            foreach ($flags as $ftr) {
                echo '<tr><td class="pr-3">',
                    $info->commit_link(substr($ftr->hash(), 0, 7), "", $ftr->hash()),
                    '</td>';
                $start = $ftr->flag->started ?? 0;
                $resolved = $ftr->flag->resolved ?? [0];
                echo '<td class="pr-3">', $start ? date("j M H:i", $start) : '',
                    $resolved[0] ? ' (resolved ' . date("j M H:i", $resolved[0]) . ')'
                        : ' <span class="pa-flag-open">(open)</span>',
                    '</td>';
                $conversation = $ftr->flag->conversation ?? [];
                if (!empty($conversation)) {
                    echo '<td>', htmlspecialchars($conversation[0][2]), '</td>';
                } else {
                    echo '<td class="dim">(blank)</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';
        }
        if ($info->can_view_grade()
            && ($t = $info->visible_total()) !== null) {
            echo '<div class="pa-gradelist is-main mt-0"><div class="pa-p',
                !$user_can_view || $info->user_can_view_score() ? '' : ' pa-p-hidden',
                '"><div class="pa-pt">grade</div>',
                '<div class="pa-pv"><strong>', $t, '</strong>';
            if (($max = $info->grade_max_total())) {
                echo " / ", $max;
            }
            echo '</div></div></div>';
        }
        if ($info->repo && $user_can_view) {
            ContactView::echo_group("", '<strong><a href="' . $pseturl . '">view code</a></strong>');
        }
        echo $user_can_view ? "\n" : "</div>\n";
    }

    /** @return ?PsetView */
    private function psetview(Pset $pset) {
        if (!$pset->disabled
            && $this->viewer->can_view_pset($pset)
            && (!$pset->gitless_grades
                || $this->viewer->isPC
                || $pset->partner
                || $pset->upi_for($this->user)
                || $pset->answers_editable_student())) {
            return PsetView::make($pset, $this->user, $this->viewer);
        } else {
            return null;
        }
    }

    function render_default() {
        $linkpart = $this->viewer->user_linkpart($this->user);
        $this->conf->set_siteinfo("uservalue", $linkpart);

        // make studentset, track repos for flags
        $ss = StudentSet::make_empty_for($this->user, $this->viewer);
        $flagrepos = [];
        foreach ($this->conf->psets_newest_first() as $pset) {
            if (($info = $this->psetview($pset))) {
                $ss->add_info($info);
                if (!$pset->gitless && $info->repo) {
                    $flagrepos = $info->repo->repoid;
                }
            }
        }

        // load flags
        $flagsbypset = [];
        if ($flagrepos) {
            $result = Dbl::qe("select * from CommitNotes where hasflags=1 and repoid?a", $flagrepos);
            while (($cpi = CommitPsetInfo::fetch($result))) {
                foreach ((array) $cpi->jnote("flags") as $flagid => $v) {
                    $flagsbypset[$cpi->pset][] = new FlagTableRow($cpi, $flagid, $v);
                }
            }
            Dbl::free($result);
        }

        foreach ($this->conf->formulas_by_home_position() as $fc) {
            if (($this->viewer->isPC || $fc->visible)
                && ($gf = $fc->formula())
                && ($v = $gf->evaluate($this->user)) !== null
                && (!($fc->nonzero ?? false) || (float) $v !== 0.0)) {
                ContactView::echo_group(htmlspecialchars($fc->title), $v);
            }
        }

        foreach ($ss->infos($this->user->contactId) as $info) {
            $this->render_home_pset($info, $flagsbypset[$info->pset->id] ?? []);
        }

        if ($this->user->isPC) {
            echo "<div style='margin-top:5em'></div>\n";
            if ($this->user->dropped) {
                echo Ht::form($this->conf->hoturl("=index", ["set_undrop" => 1, "u" => $linkpart])),
                    Ht::submit("Undrop"), "</form>";
            } else {
                echo Ht::form($this->conf->hoturl("=index", ["set_drop" => 1, "u" => $linkpart])),
                    Ht::submit("Drop"), "</form>";
            }
        }
        if ($this->viewer->privChair && $this->user->can_enable()) {
            echo Ht::form($this->conf->hoturl("=index", ["enable_user" => 1, "u" => $linkpart])),
                Ht::submit("Enable"), "</form>";
        }
    }
}
