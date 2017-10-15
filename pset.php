<?php
// pset.php -- Peteramati problem set page
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/p", "/p/h", "/p/u/h"));
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info, $Commit;

$User = $Me;
if (isset($_REQUEST["u"])
    && !($User = ContactView::prepare_user($_REQUEST["u"])))
    redirectSelf(array("u" => null));
assert($User == $Me || $Me->isPC);
Ht::stash_script("peteramati_uservalue=" . json_encode($Me->user_linkpart($User)));

$Pset = ContactView::find_pset_redirect(req("pset"));

class Series {
    public $n;
    public $sum;
    public $sumsq;
    public $series;
    public $cdf;
    private $calculated;

    public function __construct() {
        $this->n = $this->sum = $this->sumsq = 0;
        $this->byg = $this->series = array();
        $this->calculated = false;
    }

    public function add($g) {
        $this->series[] = $g;
        $this->n += 1;
        $this->sum += $g;
        $this->sumsq += $g * $g;
        $this->calculated = false;
    }

    private function calculate() {
        sort($this->series);
        $this->cdf = array();
        $last = false;
        $subtotal = 0;
        $cdfi = 0;
        for ($i = 0; $i < count($this->series); ++$i) {
            if ($this->series[$i] !== $last) {
                $this->cdf[] = $last = $this->series[$i];
                $this->cdf[] = $i + 1;
                $cdfi += 2;
            } else
                $this->cdf[$cdfi - 1] = $i + 1;
        }
        $this->calculated = true;
    }

    public function summary() {
        if (!$this->calculated)
            $this->calculate();

        $r = (object) array("n" => $this->n, "cdf" => $this->cdf);
        if ($this->n != 0) {
            $r->mean = $this->sum / $this->n;

            $halfn = (int) ($this->n / 2);
            if ($this->n % 2 == 0)
                $r->median = ($this->series[$halfn-1] + $this->series[$halfn]) / 2.0;
            else
                $r->median = $this->series[$halfn];

            if ($this->n > 1)
                $r->stddev = sqrt(($this->sumsq - $this->sum * $this->sum / $this->n) / ($this->n - 1));
            else
                $r->stddev = 0;
        }

        return $r;
    }

    static public function truncate_summary_below($r, $cutoff) {
        $cx = $cutoff * $r->n;
        for ($i = 0; $i < count($r->cdf) && $r->cdf[$i+1] < $cx; $i += 2)
            /* nada */;
        if ($i !== 0) {
            $r->cdf = array_slice($r->cdf, $i);
            $r->cutoff = $cutoff;
        }
    }

}

// get JSON grade series data
if (isset($_REQUEST["gradecdf"])) {
    if (!$Me->isPC && !Contact::student_can_view_grade_cdf($Pset))
        json_exit(["error" => "Grades are not visible now"]);

    if ($Conf->setting("gradejson_pset$Pset->id", 0) < $Now - 30) {
        if (is_int($Pset->grades_visible))
            $notdropped = "(not c.dropped or c.dropped<$Pset->grades_visible)";
        else
            $notdropped = "not c.dropped";
        $q = "select cn.notes, c.extension from ContactInfo c\n";
        if ($Pset->gitless_grades)
            $q .= "\t\tjoin ContactGrade cn on (cn.cid=c.contactId and cn.pset=$Pset->psetid)";
        else
            $q .= "\t\tjoin ContactLink l on (l.cid=c.contactId and l.type=" . LINK_REPO . " and l.pset=$Pset->id)
		join RepositoryGrade rg on (rg.repoid=l.link and rg.pset=$Pset->id and not rg.placeholder)
		join CommitNotes cn on (cn.hash=rg.gradehash and cn.pset=rg.pset)\n";
        $result = $Conf->qe_raw($q . " where $notdropped");

        $series = new Series;
        $xseries = new Series;
        $noextra_series = $Pset->has_extra ? new Series : null;
        $has_extra = false;
        while (($row = edb_row($result)))
            if (($g = ContactView::pset_grade(json_decode($row[0]), $Pset))) {
                $series->add($g->total);
                if ($row[1])
                    $xseries->add($g->total);
                if ($noextra_series) {
                    $noextra_series->add($g->total_noextra);
                    if ($g->total_noextra != $g->total)
                        $has_extra = true;
                }
            }

        $r = $series->summary();
        if ($xseries->n)
            $r->extension = $xseries->summary();
        if ($has_extra)
            $r->noextra = $noextra_series->summary();

        $pgj = $Pset->gradeinfo_json(false);
        if ($pgj && isset($pgj->maxgrades->total)) {
            $r->maxtotal = $pgj->maxgrades->total;
            if (isset($r->noextra))
                $r->noextra->maxtotal = $pgj->maxgrades->total;
        }

        $Conf->save_setting("gradejson_pset$Pset->id", $Now, json_encode($r));
    }

    $j = json_decode($Conf->setting_data("gradejson_pset$Pset->id"));
    $j->ok = true;
    if (!$Pset->separate_extension_grades || !$User->extension)
        unset($j->extension);
    if ($User == $Me && $Pset->grade_cdf_cutoff) {
        Series::truncate_summary_below($j, $Pset->grade_cdf_cutoff);
        if (get($j, "extension"))
            Series::truncate_summary_below($j->extension, $Pset->grade_cdf_cutoff);
        if (get($j, "noextra"))
            Series::truncate_summary_below($j->noextra, $Pset->grade_cdf_cutoff);
    }
    json_exit($j);
}

// load user repo and current commit
$Info = new PsetView($Pset, $User, $Me);
if (($Commit = req("newcommit")) == null)
    $Commit = req("commit");
if (!$Info->set_hash($Commit) && $Commit && $Info->repo) {
    $Conf->errorMsg("Commit " . htmlspecialchars($Commit) . " isn’t connected to this repository.");
    redirectSelf(array("newcommit" => null, "commit" => null));
}
$Commit = $Info->commit_hash();

// maybe set commit
if (isset($_REQUEST["setgrader"]) && isset($_POST["grader"]) && check_post()
    && $Info->can_have_grades() && $Me->can_set_grader($Pset, $User)) {
    $grader = 0;
    foreach ($Conf->pc_members() as $pcm)
        if ($pcm->email === $_POST["grader"])
            $grader = $pcm->contactId;
    if (!$grader && $_POST["grader"] !== "none")
        json_exit(["ok" => false, "error" => "No such grader"]);
    $Info->change_grader($grader);
    json_exit(["ok" => null, "grader_email" => $_POST["grader"]]);
}
if (isset($_REQUEST["setcommit"]) && isset($_REQUEST["grade"]) && check_post()
    && $Info->can_have_grades() && $Me->isPC && $Me != $User)
    $Info->mark_grading_commit();
if (isset($_REQUEST["setcommit"]))
    go($Info->hoturl("pset"));

// maybe set partner/repo
if (req("set_partner"))
    ContactView::set_partner_action($User);
if (req("set_repo"))
    ContactView::set_repo_action($User);
if (req("set_branch"))
    ContactView::set_branch_action($User);

// save grades
function save_grades(Pset $pset, PsetView $info, $values, $isauto) {
    if ($info->is_handout_commit())
        json_exit(["ok" => false, "error" => "This is a handout commit."]);
    $grades = $maxgrades = [];
    foreach ($pset->grades as $ge)
        if (isset($values[$ge->key])) {
            $g = trim($values[$ge->key]);
            if ($g === "")
                $g = null;
            else if (preg_match('_\A(?:0|[1-9]\d*)\z_', $g))
                $g = intval($g);
            else if (preg_match('_\A(?:0\.|\.\d|[1-9]\d*\.)\d*\z_', $g))
                $g = floatval($g);
            else
                continue;
            if (isset($values["old;" . $ge->key])) {
                $old_grade = $info->current_grade_entry($ge->key);
                if ((string) $old_grade != trim($values["old;" . $ge->key])
                    && $old_grade !== $g)
                    json_exit(["ok" => false, "error" => "This grade has been updated—please reload."]);
            }
            $grades[$ge->key] = $g;
        }
    $key = $isauto ? "autogrades" : "grades";
    if (!empty($grades))
        $info->update_current_info([$key => $grades]);
    return $grades;
}

if ($Me->isPC && $Me != $User && check_post()
    && isset($_REQUEST["setlatehours"]) && $Info->can_have_grades()) {
    if (isset($_REQUEST["late_hours"])
        && preg_match('_\A(?:0|[1-9]\d*)\z_', $_REQUEST["late_hours"])) {
        $Info->update_current_info(["late_hours" => intval($_REQUEST["late_hours"])]);
        $result = true;
    } else
        $result = false;
    if (isset($_REQUEST["ajax"]))
        json_exit($result);
    redirectSelf();
}

function upload_grades($pset, $text, $fname) {
    global $Conf, $Me;
    assert($pset->gitless_grades);
    $csv = new CsvParser($text);
    $csv->set_header($csv->next());
    while (($line = $csv->next())) {
        if (($who = get($line, "github_username")) && $who !== "-")
            $user = $Conf->user_by_query("github_username=?", [$who]);
        else if (($who = get($line, "seascode_username")) && $who !== "-")
            $user = $Conf->user_by_query("seascode_username=?", [$who]);
        else if (($who = get($line, "huid")) && $who !== "-")
            $user = $Conf->user_by_query("huid=?", [$who]);
        else if (($who = get($line, "username")) && $who !== "-")
            $user = $Conf->user_by_query("github_username=? or seascode_username=? order by github_username=? desc limit 1", [$who, $who, $who]);
        else if (($who = get($line, "email")) && $who !== "-")
            $user = $Conf->user_by_email($who);
        else if (($who = get($line, "name"))) {
            list($first, $last) = Text::split_name($who);
            $user = $Conf->user_by_query("firstName like '?s%' and lastName=?", [$first, $last]);
            if ($user && $user->firstName != $first
                && !str_starts_with($user->firstName, "$first "))
                $user = null;
        } else
            continue;
        if ($user) {
            $info = new PsetView($pset, $user, $Me);
            if (!save_grades($pset, $info, $line, true))
                $Conf->errorMsg("no grades set for “" . htmlspecialchars($who) . "”");
        } else
            $Conf->errorMsg(htmlspecialchars($fname) . ":" . $csv->lineno() . ": unknown user “" . htmlspecialchars($who) . "”");
    }
    return true;
}

if ($Me->isPC && check_post() && isset($_REQUEST["uploadgrades"])
    && file_uploaded($_FILES["file"])) {
    if (($text = file_get_contents($_FILES["file"]["tmp_name"])) === false)
	$Conf->errorMsg("Internal error: cannot read file.");
    else if (upload_grades($Pset, $text, $_FILES["file"]["name"]))
        redirectSelf();
}

// save tab width, wdiff
if (isset($_REQUEST["tab"]) && ctype_digit($_REQUEST["tab"])
    && $_REQUEST["tab"] >= 1 && $_REQUEST["tab"] <= 16) {
    $tab = (int) $_REQUEST["tab"];
    $tab = $tab == 4 ? null : $tab;
    $Info->update_commit_info(array("tabwidth" => $tab));
} else if (isset($_REQUEST["tab"])
           && ($_REQUEST["tab"] == "" || $_REQUEST["tab"] == "none"))
    $Info->update_commit_info(array("tabwidth" => null));
if (isset($_REQUEST["wdiff"]))
    $Info->update_commit_info(array("wdiff" => ((int) $_REQUEST["wdiff"] != 0)));

// save run settings
if ($Me->isPC && $Me != $User && isset($_REQUEST["saverunsettings"])
    && check_post()) {
    $x = req("runsettings");
    if (empty($x))
        $x = null;
    $Info->update_commit_info(array("runsettings" => $x), true);
    if (isset($_REQUEST["ajax"]))
        json_exit(["ok" => true, "runsettings" => $x]);
}

// check for new commit
if ($User && $Info->repo)
    $Info->repo->refresh(30);

$Conf->header(htmlspecialchars($Pset->title), "home");
$xsep = " <span class='barsep'>&nbsp;·&nbsp;</span> ";


// Top: user info
echo "<div id='homeinfo'>";

function session_list_position($sl, $info) {
    $p = array_search($info->user->contactId, $sl->ids);
    if ($p !== false && isset($sl->psetids)) {
        $reqcommit = req("commit");
        $x = $p;
        while (isset($sl->ids[$x])
               && ($sl->ids[$x] !== $info->user->contactId
                   || $sl->psetids[$x] !== $info->pset->id
                   || ($sl->hashes[$x] !== "x"
                       ? $sl->hashes[$x] !== substr($reqcommit, 0, strlen($sl->hashes[$x]))
                       : !$reqcommit || $info->is_grading_commit())))
            ++$x;
        if (isset($sl->ids[$x]))
            $p = $x;
    }
    return $p;
}

function session_list_link($sl, $pos, $isprev, Contact $me, Contact $user) {
    global $Conf, $Pset, $User;
    $pset = $Pset;
    if (isset($sl->psetids) && isset($sl->psetids[$pos])
        && ($p = $Conf->pset_by_id($sl->psetids[$pos])))
        $pset = $p;
    $u = $me->user_linkpart($user, $pset->anonymous && !!get($sl, "anon"));
    $x = ["pset" => $pset->urlkey, "u" => $u];
    if (isset($sl->hashes) && isset($sl->hashes[$pos])
        && $sl->hashes[$pos] !== "x")
        $x["commit"] = $sl->hashes[$pos];
    $t = htmlspecialchars($x["u"]);
    if (isset($sl->psetids))
        $t .= " @" . htmlspecialchars($pset->title);
    if (isset($x["commit"]))
        $t .= "/" . $x["commit"];
    return '<a href="' . hoturl("pset", $x) . '">'
        . ($isprev ? "« " : "") . $t . ($isprev ? "" : " »") . '</a>';
}

if ($User->username && $Me->isPC && ($sl = $Conf->session_list())
    && ($p = session_list_position($sl, $Info)) !== false
    && ($p > 0 || $p < count($sl->ids) - 1)) {
    $result = $Conf->qe("select contactId, firstName, lastName, email,
        huid, anon_username, github_username, seascode_username, extension
        from ContactInfo where contactId?a",
        [$p > 0 ? $sl->ids[$p - 1] : -1, $p < count($sl->ids) - 1 ? $sl->ids[$p + 1] : -1]);
    $links = [null, null];
    while ($result && ($s = Contact::fetch($result)))
        $links[$p > 0 && $sl->ids[$p - 1] == $s->contactId ? 0 : 1] = $s;
    echo "<div class=\"has-hotlist\" style=\"color:gray;float:right\"",
        " data-hotlist=\"", htmlspecialchars(json_encode($sl)), "\">",
        "<h3 style=\"margin-top:0\">";
    if ($links[0])
        echo session_list_link($sl, $p - 1, true, $Me, $links[0]);
    if ($links[0] && $links[1])
        echo ' · ';
    if ($links[1])
        echo session_list_link($sl, $p + 1, false, $Me, $links[1]);
    echo "</h3></div>";
}

ContactView::echo_heading($User);
$u = $Me->user_linkpart($User);


// Per-pset

function diff_line_code($t) {
    global $TABWIDTH;
    while (($p = strpos($t, "\t")) !== false)
        $t = substr($t, 0, $p) . str_repeat(" ", $TABWIDTH - ($p % $TABWIDTH)) . substr($t, $p + 1);
    return htmlspecialchars($t);
}

function echo_grade_cdf() {
    global $Conf, $Info, $Pset, $User, $Me;
    $sepx = $User->extension && $Pset->separate_extension_grades;
    $xmark = $sepx ? "extension " : "";
    echo '<div id="pa-gradecdf" class="pa-gradecdf" style="float:right;position:relative">',
        '<table class="gradecdf61table"><tbody><tr>',
        '<td class="yaxislabelcontainer"></td>',
        '<td class="plot"><div style="width:300px;height:200px"></div></td>',
        '</tr><tr><td></td><td class="xaxislabelcontainer"></td></tr>',
        '</tbody></table>',
        '<table class="gradecdf61summary', ($sepx ? " extension" : " all"), '"><tbody>',
        '<tr class="gradecdf61mean"><td class="cap">', $xmark, ' mean</td><td class="val"></td></tr>',
        '<tr class="gradecdf61median"><td class="cap">', $xmark, ' median</td><td class="val"></td></tr>',
        '<tr class="gradecdf61stddev"><td class="cap">', $xmark, ' stddev</td><td class="val"></td></tr>',
        '</tbody></table>',
        '</div>';
    Ht::stash_script("pa_gradecdf(\$(\"#pa-gradecdf\"))");
}

function echo_commit($Info) {
    global $Conf, $Me, $User, $Pset;
    global $TABWIDTH, $WDIFF;

    $Notes = $Info->commit_info();
    $TABWIDTH = $Info->commit_info("tabwidth") ? : 4;
    $WDIFF = isset($Notes->wdiff) ? $Notes->wdiff : false;

    // current commit and commit selector
    $sel = array();
    $curhead = $grouphead = null;
    foreach ($Info->recent_commits() as $k) {
        // visually separate older heads
        if ($curhead === null)
            $curhead = $k->fromhead;
        if ($curhead !== $k->fromhead && !$k->from_handout()) {
            if (!$grouphead)
                $sel["from.$k->fromhead"] = (object)
                    array("type" => "optgroup",
                          "label" => "Other snapshots");
            else
                $sel["from.$k->fromhead"] = null;
            $curhead = $grouphead = $k->fromhead;
        }
        // actual option
        $x = UnicodeHelper::utf8_prefix($k->subject, 72);
        if (strlen($x) != strlen($k->subject))
            $x .= "...";
        $sel[$k->hash] = substr($k->hash, 0, 7) . " " . htmlspecialchars($x);
    }
    $result = $Conf->qe("select hash from CommitNotes where (haslinenotes & ?)!=0 and pset=? and hash ?a",
                        $Me == $User && !$Info->can_view_grades() ? HASNOTES_COMMENT : HASNOTES_ANY,
                        $Pset->psetid, array_keys($sel));
    while (($row = edb_row($result)))
        $sel[$row[0]] .= " &nbsp;♪";
    if (($h = $Info->grading_hash()) && isset($sel[$h]))
        $sel[$h] = preg_replace('_(.*?)(?: &nbsp;)?(♪?)\z_', '$1 &nbsp;✱$2', $sel[$h]);
    if ($Info->is_grading_commit())
        $key = "grading commit";
    else
        $key = "this commit";
    $value = Ht::select("newcommit", $sel, $Info->commit_hash(),
                        array("onchange" => "jQuery(this).closest('form').submit()"));
    if ($Me != $User) {
        $x = $Info->is_grading_commit() ? "" : "font-weight:bold";
        $value .= " " . Ht::submit("grade", "Grade", array("style" => $x));
    }

    // view options
    $fold_viewoptions = !isset($_REQUEST["tab"]) && !isset($_REQUEST["wdiff"]);
    $value .= '<div class="viewoptions61">'
        . '<a class="q" href="#" onclick="return fold61(this.nextSibling,this.parentNode)">'
        . '<span class="foldarrow">'
        . ($fold_viewoptions ? '&#x25B6;' : '&#x25BC;')
        . '</span>&nbsp;options</a><span style="padding-left:1em'
        . ($fold_viewoptions ? ';display:none' : '') . '">tab width:';
    foreach (array(2, 4, 8) as $i)
        $value .= '&nbsp;<a href="' . self_href(array("tab" => $i)) . '"'
            . ($TABWIDTH == $i ? " class=\"q\"><strong>$i</strong>" : '>' . $i)
            . '</a>';
    $value .= '<span style="padding-left:1em">wdiff:';
    foreach (array("no", "yes") as $i => $t)
        $value .= '&nbsp;<a href="' . self_href(array("wdiff" => $i)) . '"'
            . (!$WDIFF == !$i ? " class=\"q\"><strong>$t</strong>" : '>' . $t)
            . '</a>';
    $value .= '</span></span></div>';

    // warnings
    $remarks = array();
    if (!$Info->grading_hash() && $Me != $User && !$Pset->gitless_grades)
        $remarks[] = array(true, "No commit has been marked for grading.");
    else if (!$Info->is_grading_commit() && $Info->grading_hash())
        $remarks[] = array(true, "This is not "
                           . "<a class=\"uu\" href=\"" . $Info->hoturl("pset", array("commit" => $Info->grading_hash())) . "\">the commit currently marked for grading</a>"
                           . " <span style=\"font-weight:normal\">(<a href=\"" . $Info->hoturl("diff", array("commit1" => $Info->grading_hash())) . "\">see diff</a>)</span>.");
    if (!$Info->is_latest_commit())
        $remarks[] = array(true, "This is not "
                           . "<a class=\"uu\" href=\"" . $Info->hoturl("pset", array("commit" => $Info->latest_hash())) . "\">the latest commit</a>"
                           . " <span style=\"font-weight:normal\">(<a href=\"" . $Info->hoturl("diff", array("commit1" => $Info->latest_hash())) . "\">see diff</a>)</span>.");
    if (($lh = $Info->late_hours()) && $lh->hours > 0) {
        $extra = array();
        if (get($lh, "commitat"))
            $extra[] = "commit at " . $Conf->printableTimestamp($lh->commitat);
        if (get($lh, "deadline"))
            $extra[] = "deadline " . $Conf->printableTimestamp($lh->deadline);
        $extra = count($extra) ? ' <span style="font-weight:normal">(' . join(", ", $extra) . ')</span>' : "";
        $remarks[] = array(true, "This commit uses " . plural($lh->hours, "late hour") . $extra . ".");
    }
    if (($Info->is_latest_commit() || $Me->isPC)
        && $Pset->handout_repo_url) {
        $last_handout = $Pset->latest_handout_commit();
        $last_myhandout = $last_handout ? $Info->derived_handout_hash() : false;
        if ($last_handout && $last_myhandout && $last_handout->hash == $last_myhandout)
            /* this is ideal: they have the latest handout commit */;
        else if ($last_handout && $last_myhandout)
            // they don't have the latest updates
            $remarks[] = array(true, "Updates are available for this problem set <span style=\"font-weight:normal\">(<a href=\"" . $Info->hoturl("diff", array("commit" => $last_myhandout, "commit1" => $last_handout->hash)) . "\">see diff</a>)</span>. Run <code>git pull handout master</code> to merge these updates.");
        else if ($last_handout)
            $remarks[] = array(true, "Please create your repository by cloning our repository. Creating your repository from scratch makes it harder for you to get pset updates.");
        else if (!$last_handout && $Me->isPC) {
            $handout_files = $Pset->handout_repo()->ls_files("master");
            if (!count($handout_files))
                $remarks[] = array(true, "The handout repository, " . htmlspecialchars($Pset->handout_repo_url) . ", contains no files; perhaps handout_repo_url is misconfigured.");
            else
                $remarks[] = array(true, "The handout repository, " . htmlspecialchars($Pset->handout_repo_url) . ", does not contain problem set code yet.");
        }
    }

    // actually print
    echo Ht::form($Info->hoturl_post("pset", array("commit" => null, "setcommit" => 1)), array("class" => "commitcontainer61", "data-pa-pset" => $Info->pset->urlkey, "data-pa-commit" => $Info->latest_hash())),
        "<div class=\"f-contain\">";
    ContactView::echo_group($key, $value, $remarks);
    echo "</div></form>\n";
}

function echo_grader() {
    global $Conf, $Me, $User, $Pset, $Info;
    $gradercid = $Info->gradercid();
    if ($Info->is_grading_commit() && $Me->can_view_grader($Pset, $User)) {
        $pcm = $Conf->pc_members();
        $gpc = get($pcm, $gradercid);
        $value_post = "";
        if ($Me->can_set_grader($Pset, $User)) {
            $sel = array();
            if (!$gpc) {
                $sel["none"] = "(None)";
                $sel[] = null;
            }
            foreach ($Conf->pc_members() as $pcm)
                $sel[$pcm->email] = Text::name_html($pcm);
            $value = Ht::form($Info->hoturl_post("pset", array("setgrader" => 1)))
                . "<div>" . Ht::select("grader", $sel, $gpc ? $gpc->email : "none", array("onchange" => "setgrader61(this)"));
            $value_post = "<span class=\"ajaxsave61\"></span></div></form>";
        } else {
            if (isset($pcm[$gradercid]))
                $value = Text::name_html($pcm[$gradercid]);
            else
                $value = "???";
        }
        if ($Me->privChair)
            $value .= "&nbsp;" . become_user_link($gpc);
        ContactView::echo_group("grader", $value . $value_post);
    }
}

function echo_grade_cdf_here() {
    global $Me, $User, $Pset, $Info;
    if ($Info->can_view_grades()
        && ($Me != $User || $Pset->grade_cdf_visible)
        && $Info->has_assigned_grades())
        echo_grade_cdf();
}

function echo_all_grades() {
    global $Me, $User, $Pset, $Info;
    if ($Info->is_handout_commit())
        return;

    $has_grades = $Info->has_assigned_grades();
    if ($Info->can_view_grades() && ($Me !== $User || $has_grades)) {
        echo '<div class="pa-gradelist',
            ($Info->user_can_view_grades() ? "" : " pa-pset-hidden"), '"></div>';
        Ht::stash_script('pa_loadgrades.call($(".pa-psetinfo")[0], ' . json_encode($Info->grade_json()) . ')');
        if ($Pset->has_grade_landmark)
            Ht::stash_script('$(function(){pa_loadgrades.call($(".pa-psetinfo")[0], true)})');
        echo Ht::unstash();
    }

    $lhg = $Info->late_hours();
    if ($lhg && $User == $Me && $Info->can_view_grades()) {
        if ($lhg->hours || $has_grades) {
            echo '<div style="margin-top:1.5em">';
            ContactView::echo_group("late hours", '<span class="pa-grade" data-pa-grade="late_hours">' . htmlspecialchars($lhg->hours) . '</span>',
                                    array(), array("nowrap" => true));
            echo '</div>';
        }
    } else if ($User != $Me) {
        $lhag = $Info->late_hours(true);
        $value = '<span class="pa-gradeholder">'
            . Ht::entry("late_hours", $lhg ? $lhg->hours : "", array("onchange" => "jQuery(this).closest('form').submit()", "class" => "pa-grade"))
            . '</span>';
        $value .= " " . Ht::submit("Save", array("tabindex" => 1));
        $value .= ' <span class="ajaxsave61"></span>';
        if ($lhag && $lhag->hours !== $lhg->hours)
            $value .= ' <span class="autograde61">auto-late hours is ' . htmlspecialchars($lhag->hours) . '</span>';
        echo Ht::form($Info->hoturl_post("pset", array("setlatehours" => 1)),
                      array("onsubmit" => "return gradesubmit61(this)")),
            '<div class="f-contain" style="margin-top:1.5em">';
        ContactView::echo_group("late hours", $value,
                                array(), array("nowrap" => true));
        echo '</div></form>';
    }
}


function show_pset($info) {
    global $Me;
    echo "<hr/>\n";
    if ($Me->isPC && get($info->pset, "gitless_grades"))
        echo '<div style="float:right"><button type="button" onclick="jQuery(\'#upload\').show()">upload</button></div>';
    echo "<h2>", htmlspecialchars($info->pset->title), "</h2>";
    ContactView::echo_partner_group($info);
    ContactView::echo_repo_group($info, $Me != $info->user);
    ContactView::echo_repo_last_commit_group($info, false);
}

show_pset($Info);

if ($Me->isPC) {
    echo '<div id="upload" style="display:none"><hr/>',
        Ht::form($Info->hoturl_post("pset", array("uploadgrades" => 1))),
        '<div class="f-contain">',
        '<input type="file" name="file" />',
        Ht::submit("Upload"),
        '</div></form></div>';
}

echo "<hr>\n";
echo '<div class="pa-psetinfo" data-pa-pset="', htmlspecialchars($Info->pset->urlkey);
if (!$Pset->gitless && $Info->maybe_commit_hash())
    echo '" data-pa-hash="', htmlspecialchars($Info->commit_hash());
if ($Me->can_set_grades($Pset, $Info))
    echo '" data-pa-can-set-grades="yes';
if ($Info->user_can_view_grades())
    echo '" data-pa-user-can-view-grades="yes';
echo '">';

if ($Pset->gitless) {
    echo_grade_cdf_here();
    echo_grader();
    echo_all_grades();

} else if ($Info->repo && !$Info->can_view_repo_contents()) {
    echo_grade_cdf_here();
    echo_grader();
    echo_all_grades();

} else if ($Info->repo && $Info->recent_commits()) {
    echo_grade_cdf_here();
    echo_commit($Info);

    // print runners
    $runnerbuttons = array();
    $last_run = false;
    foreach ($Pset->runners as $r)
        if ($Me->can_view_run($Pset, $r, $User)) {
            if ($Me->can_run($Pset, $r, $User)) {
                $b = Ht::button(htmlspecialchars($r->title),
                                array("name" => "run",
                                      "value" => $r->name,
                                      "class" => "btn runner61",
                                      "onclick" => "run61(this)",
                                      "data-pa-runclass" => $r->runclass_argument(),
                                      "data-pa-loadgrade" => isset($r->eval) ? "true" : null));
                $runnerbuttons[] = ($last_run ? " &nbsp;" : "") . $b;
                $last_run = true;
            } else
                $runnerbuttons[] = Ht::hidden("run", $r->name,
                                              array("class" => "runner61"));
        }
    if (count($runnerbuttons) && $Me->isPC && $Me != $User && $last_run)
        $runnerbuttons[] = " &nbsp;"
            . Ht::button("+",
                         array("class" => "runner61",
                               "style" => "font-weight:bold",
                               "name" => "define",
                               "onclick" => "runsetting61.add()"));
    if ((($Me->isPC && $Me != $User) || $Me == $User)
        && !$Info->is_handout_commit()) {
        $runnerbuttons[] = '<div class="g"></div>';
        $all_resolved = true;
        foreach ($Info->current_info("flags") ? : [] as $k => $v) {
            $resolved = get($v, "resolved");
            $all_resolved = $all_resolved && $resolved;
            $conversation = "";
            if (get($v, "conversation"))
                $conversation = htmlspecialchars((string) $v->conversation[0][2]);
            if ($resolved && $conversation === "")
                continue;
            $x = $resolved ? "Resolved" : "<strong>Flagged</strong>";
            if ($conversation !== "")
                $x .= " (" . $conversation . ")";
            if (!$resolved)
                $x .= '<span style="display:inline-block;margin-left:1em">'
                    . Ht::button("Resolve", ["name" => "resolveflag", "onclick" => "flag61(this)", "data-flagid" => $k])
                    . '</span>';
            $runnerbuttons[] = $x . "<br />";
        }
        if ($all_resolved)
            $runnerbuttons[] = Ht::button("Flag this commit", ["style" => "font-weight:bold;font-size:100%;background:#ffeeee", "onclick" => "flag61(this)", "name" => "flag"]);
    }
    if (!empty($runnerbuttons)) {
        echo Ht::form($Info->hoturl_post("run")),
            '<div class="f-contain">';
        ContactView::echo_group("", join("", $runnerbuttons));
        echo "</div></form>\n";
        if ($Me->isPC && $Me != $User) {
            echo Ht::form($Info->hoturl_post("pset", array("saverunsettings" => 1, "ajax" => 1))),
                '<div class="f-contain"><div id="runsettings61"></div></div></form>', "\n";
            // XXX always using grading commit's settings?
            if (($runsettings = $Info->commit_info("runsettings")))
                echo '<script>runsetting61.load(', json_encode($runsettings), ')</script>';
        }
        Ht::stash_script("jQuery('button.runner61').prop('disabled',false)");
    }

    // print current grader
    echo_grader();

    // print grade entries
    echo_all_grades();

    // collect diff and sort line notes
    $all_linenotes = $Me->can_view_comments($Pset) ? $Info->commit_info("linenotes") : null;
    $lnorder = new LinenotesOrder($all_linenotes, $Info->can_view_grades());
    $diff = $User->repo_diff($Info->repo, $Info->commit_hash(), $Pset, array("wdiff" => $WDIFF, "needfiles" => $lnorder->note_files()));
    $lnorder->set_diff($diff);

    // print line notes
    $notelinks = array();
    foreach ($lnorder->seq() as $fl) {
        $f = str_starts_with($fl[0], $Pset->directory_slash) ? substr($fl[0], strlen($Pset->directory_slash)) : $fl[0];
        $notelinks[] = '<a href="#L' . $fl[1] . '_' . html_id_encode($fl[0])
            . '" onclick="return pa_gotoline(this)" class="pa-noteref'
            . (!$fl[2] && !$Info->user_can_view_grades() ? " hiddennote61" : "")
            .'">' . htmlspecialchars($f) . ':' . substr($fl[1], 1) . '</a>';
    }
    if (count($notelinks))
        ContactView::echo_group("notes", join(", ", $notelinks));

    // print runners
    $crunners = $Info->commit_info("run");
    $runclasses = [];
    foreach ($Pset->runners as $r) {
        if (!$Me->can_view_run($Pset, $r, $User) || isset($runclasses[$r->runclass]))
            continue;

        $checkt = defval($crunners, $r->runclass);
        $rj = $checkt ? ContactView::runner_json($Info, $checkt) : null;
        if (!$rj && !$Me->can_run($Pset, $r, $User))
            continue;

        echo '<div id="run61out_' . $r->runclass . '"';
        if (!$rj || !isset($rj->timestamp))
            echo ' style="display:none"';
        echo '><h3><a class="fold61" href="#" onclick="',
            "return runfold61('$r->runclass')", '">',
            '<span class="foldarrow">&#x25B6;</span>&nbsp;',
            htmlspecialchars($r->output_title), '</a></h3>',
            '<div class="run61" id="run61_', $r->runclass, '" style="display:none"';
        if ($Pset->directory_noslash !== "")
            echo ' data-pa-directory="', htmlspecialchars($Pset->directory_noslash), '"';
        if ($rj && isset($rj->timestamp))
            echo ' data-pa-timestamp="', $rj->timestamp, '"';
        if ($rj && isset($rj->data) && ($pos = strpos($rj->data, "\n\n")))
            echo ' data-pa-content="', htmlspecialchars(substr($rj->data, $pos + 2)), '"';
        echo '><div class="run61in"><pre class="run61pre">';
        echo '</pre></div></div></h3></div>', "\n";
    }

    // check for any linenotes
    $has_any_linenotes = false;
    foreach ($diff as $file => $dinfo)
        if ($lnorder->file($file)) {
            $has_any_linenotes = true;
            break;
        }

    // line notes
    if (!empty($diff))
        echo "<hr style=\"clear:both\" />\n";
    foreach ($diff as $file => $dinfo) {
        $linenotes = $lnorder->file($file);
        $open = $linenotes
            || (!$dinfo->boring
                && ($Me != $Info->user || !$Info->can_view_grades()
                    || !$Info->is_grading_commit() || !$has_any_linenotes));
        $Info->echo_file_diff($file, $dinfo, $lnorder, $open);
    }

    Ht::stash_script('$(".pa-note-entry").autogrow();$(window).on("beforeunload",pa_beforeunload)');
} else {
    if ($Pset->gitless_grades)
        echo_grade_cdf_here();

    echo "<div class=\"commitcontainer61\" data-pa-pset=\"", htmlspecialchars($Info->pset->urlkey), "\">";
    ContactView::echo_group("this commit", "No commits yet for this problem set", array());
    echo "</div>\n";

    if ($Pset->gitless_grades) {
        echo_grader();
        echo_all_grades();
    }
}

echo "</div>\n";


Ht::stash_script('window.psetpost61="' . self_href(array("post" => post_value())) . '"');
if (!$Pset->gitless)
    Ht::stash_script("checklatest61()", "checklatest61");

echo "<div class='clear'></div>\n";
$Conf->footer();
