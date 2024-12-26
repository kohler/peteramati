<?php
// diffmany.php -- Peteramati multidiff page
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
global $Conf, $Qreq, $Me;
ContactView::set_path_request($Qreq, ["/p"], $Conf);
if ($Me->is_empty() || !$Me->isPC) {
    $Me->escape();
}

class DiffMany_Page {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var Qrequest */
    public $qreq;
    /** @var Contact */
    public $viewer;
    /** @var list<string> */
    private $files;
    /** @var bool */
    private $fileglob = false;
    /** @var list<string> */
    private $suppress_grades = [];
    /** @var list<GradeEntry> */
    private $selected_grades;
    /** @var ?list<0|4|5|7> */
    private $values_vf;
    /** @var int */
    public $psetinfo_idx = 0;
    /** @var array<string,GradeEntry> */
    public $all_viewed = [];

    function __construct(Pset $pset, Qrequest $qreq, Contact $viewer) {
        $this->conf = $viewer->conf;
        $this->pset = $pset;
        $this->qreq = $qreq;
        $this->viewer = $viewer;

        if ($qreq->files) {
            $f = simplify_whitespace($qreq->files);
            $fs = $f === "" ? [] : explode(" ", $f);
            $this->fileglob = !!preg_match('/[\[\]*?]/', $qreq->files);
        } else if ($qreq->file) {
            $fs = [$qreq->file];
        } else {
            $fs = null;
        }
        if ($fs !== null) {
            $this->files = $pset->maybe_prefix_directory($fs);
        }

        if ($qreq->grade) {
            $grades = [];
            foreach ($pset->grades_by_key_list($qreq->grade, true) as $ge) {
                $grades[$ge->key] = true;
            }
            $this->values_vf = [];
            foreach ($pset->grades as $ge) {
                if (!isset($grades[$ge->key])) {
                    $this->suppress_grades[] = $ge->key;
                    $this->values_vf[] = 0;
                } else {
                    $this->selected_grades[] = $ge;
                    $this->values_vf[] = $ge->vf(null);
                }
            }
        }
    }

    function echo_one(Contact $user) {
        ++$this->psetinfo_idx;
        $pset = $this->pset;
        $info = PsetView::make($pset, $user, $this->viewer);
        foreach ($this->suppress_grades as $key) {
            $info->suppress_grade($key);
        }

        // should we skip?
        if ($pset->gitless ? !$info->visible_grades() : !$info->repo) {
            return;
        }

        $u = $this->viewer->user_linkpart($user);
        echo '<div id="pa-psetinfo', $this->psetinfo_idx,
            '" class="pa-psetinfo pa-psetinfo-partial pa-diffcontext',
            '" data-pa-pset="', htmlspecialchars($pset->urlkey),
            '" data-pa-user="', htmlspecialchars($u);
        if (!$pset->gitless && $info->hash()) {
            echo '" data-pa-commit="', htmlspecialchars($info->hash());
        }
        if (!$pset->gitless && $pset->directory) {
            echo '" data-pa-directory="', htmlspecialchars($pset->directory_slash);
        }
        if ($info->can_edit_scores()
            || ($info->can_view_any_grade() && $info->is_grading_commit())) {
            $gexp = $info->grade_export(PsetView::GXFM_TFSLICE, $this->values_vf);
            $gj = $gexp->jsonSerialize();
        } else {
            $gj = $info->info_json();
        }
        echo '" data-pa-gradeinfo=\'', json_escape_browser_sqattr($gj), '\'>';

        if ($user !== $this->viewer && !$user->is_anonymous && $user->contactImageId) {
            echo '<img class="pa-smallface" src="' . $info->conf->hoturl("face", ["u" => $u, "imageid" => $user->contactImageId]) . '" />';
        }

        echo '<h2 class="homeemail"><a href="',
            $info->conf->hoturl("pset", ["u" => $u, "pset" => $pset->urlkey]), '">', htmlspecialchars($u), '</a>';
        if ($user->extension) {
            echo " (X)";
        }
        /*if ($Me->privChair && $user->is_anonymous)
            echo " ",*/
        if ($this->viewer->privChair) {
            echo "&nbsp;", become_user_link($user);
        }
        echo '</h2>';

        if ($user !== $this->viewer && !$user->is_anonymous) {
            echo '<h3>', Text::user_html($user), '</h3>';
        }
        echo '<hr class="c">';
        $linkdetails = '<details class="dropmenu-details" role="menu">'
            . '<summary>'
            . Ht::button(htmlspecialchars($u), ["class" => "ui js-dropmenu-open need-dropmenu pa-dropmenu-diffmany q", "aria-haspopup" => "menu"])
            . '</summary><div class="dropmenu-container dropmenu-se"></div></details> /';

        if (!$pset->gitless && $info->hash() && $info->commit()) {
            $lnorder = $info->visible_line_notes();
            $onlyfiles = $this->fileglob ? $this->expand_files($info) : $this->files;
            $onefile = count($onlyfiles ?? []) === 1 && !$this->fileglob;
            $dctx = $info->diff_context($info->base_handout_commit(), $info->commit(), $lnorder, PsetView::DCTX_NO_LOCAL_DIFFCONFIG);
            $dctx->set_allowed_files($onlyfiles);
            $dctx->no_full = true;
            $diff = $info->diff($dctx);
            if ($onefile
                && isset($diff[$onlyfiles[0]])
                && $this->qreq->lines
                && preg_match('/\A\s*(\d+)-(\d+)\s*\z/', $this->qreq->lines, $m)) {
                $diff[$onlyfiles[0]] = $diff[$onlyfiles[0]]->restrict_linea(intval($m[1]), intval($m[2]) + 1);
            }
            $want_grades = $pset->has_grade_landmark;

            if (!empty($diff)) {
                $sbflags = 0;
                if ($info->can_edit_scores() && !$pset->has_grade_landmark_range) {
                    $sbflags |= PsetView::SIDEBAR_GRADELIST | PsetView::SIDEBAR_GRADELIST_LINKS;
                    $want_grades = true;
                }
                if (count($diff) > 2) {
                    $sbflags |= PsetView::SIDEBAR_FILENAV;
                }
                PsetView::print_sidebar_open($sbflags, $diff);
                foreach ($diff as $file => $dinfo) {
                    $info->echo_file_diff($file, $dinfo, $lnorder, [
                        "expand" => true,
                        "hide_left" => true,
                        "no_heading" => $onefile,
                        "diffcontext" => $linkdetails
                    ]);
                }
                PsetView::print_sidebar_close($sbflags);
            }

            $this->all_viewed += $info->viewed_gradeentries; // XXX off if want all grades
        } else {
            echo '<div class="pa-gradelist is-main"></div>';
            $want_grades = true;
        }

        echo "<hr>";
        if ($want_grades) {
            echo Ht::unstash_script('$pa.loadgrades.call(document.getElementById("pa-psetinfo' . $this->psetinfo_idx . '"))');
        }
        echo "</div>";
    }

    /** @return list<string> */
    private function expand_files(PsetView $info) {
        if (!$info->repo || !$info->hash()) {
            return $this->files;
        }
        $result = [];
        foreach ($info->repo->ls_files($info->hash(), $info->pset->directory) as $f) {
            foreach ($this->files as $pat) {
                if (fnmatch($pat, $f, FNM_PATHNAME | FNM_PERIOD)) {
                    $result[] = $f;
                }
            }
        }
        return $result;
    }

    function run() {
        $suffix = $this->files ? htmlspecialchars(" > " . join(" ", $this->files)) : "";
        $this->conf->set_multiuser_page();
        $this->conf->header('<span class="pset-title">' . htmlspecialchars($this->pset->title) . '</span>' . $suffix, "home");

        foreach ($this->pset->grade_script ?? [] as $gs) {
            Ht::stash_html($this->conf->make_script_file($gs));
        }
        Ht::stash_script("\$pa.long_page = true");
        $gexp = new GradeExport($this->pset);
        $gexp->export_entries();
        if ($this->values_vf !== null) {
            $gexp->set_fixed_values_vf($this->values_vf);
        }
        echo "<div class=\"pa-psetinfo pa-diffset\" data-pa-gradeinfo='",
            json_escape_browser_sqattr($gexp), "'>";

        $cluster_buttons = [];
        foreach ($this->selected_grades ?? [] as $ge) {
            if (($ge->config->cluster_by ?? null) && is_string($ge->config->cluster_by)) {
                $cluster_buttons[] = Ht::button("Cluster by " . htmlspecialchars($ge->title), ["class" => "ui pa-cluster-diff mr-3", "data-pa-grade" => $ge->key, "data-pa-clustering" => $ge->config->cluster_by]);
            }
        }
        if (!empty($cluster_buttons)) {
            echo '<div class="mt-3 mb-3">', join("", $cluster_buttons), '<hr></div>';
        }

        if (trim((string) $this->qreq->users) === "") {
            $want = [];
            $sset = new StudentSet($this->viewer, StudentSet::ALL);
            $sset->set_pset($this->pset);
            foreach ($sset as $info) {
                if (!$info->user->visited
                    && $info->grading_hash()
                    && !$info->user->dropped) {
                    $want[] = $info->user->email;
                    foreach ($info->user->links(LINK_PARTNER, $this->pset->id) as $pcid) {
                        if (($u = $sset->user($pcid)))
                            $u->visited = true;
                    }
                }
            }
            $this->qreq->users = join(" ", $want);
        }

        if (isset($this->qreq->anonymous)) {
            $anonymous = (bool) $this->qreq->anonymous;
        } else {
            $anonymous = $this->pset->anonymous;
        }
        foreach (explode(" ", $this->qreq->users) as $user) {
            if ($user === "") {
                continue;
            } else if (ctype_digit($user) && strlen($user) < 8) {
                if (($u = $this->conf->user_by_id(intval($user)))) {
                    $u->set_anonymous($anonymous);
                }
            } else {
                $u = $this->conf->user_by_whatever($user);
            }
            if ($u) {
                $this->echo_one($u);
            } else {
                echo "<p>no such user ", htmlspecialchars($user), "</p>\n";
            }
        }

        foreach ($this->all_viewed as $gkey => $x) {
            $gradeentry = $this->pset->all_grades[$gkey];
            if ($gradeentry->landmark_buttons) {
                foreach ($gradeentry->landmark_buttons as $lb) {
                    if (is_object($lb) && isset($lb->summary_className)) {
                        echo '<button type="button" class="ui ', $lb->summary_className, '" data-pa-class="', $lb->className, '">Summarize ', $lb->title, '</button>';
                    }
                }
            }
        }

        Ht::stash_script('$(window).on("beforeunload",$pa.beforeunload)');
        echo "</div><hr class=\"c\">\n";
        $this->conf->footer();
    }
}

(new DiffMany_Page(ContactView::find_pset_redirect($Qreq->pset, $Me), $Qreq, $Me))->run();
