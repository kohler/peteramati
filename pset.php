<?php
// pset.php -- HotCRP problem set page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
ContactView::set_path_request(array("/@", "/@/p", "/@/p/H", "/p", "/p/H", "/p/u", "/p/u/H"));
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info, $Commit;

$User = $Me;
if (isset($_REQUEST["u"])
    && !($User = ContactView::prepare_user($_REQUEST["u"])))
    redirectSelf(array("u" => null));
assert($User == $Me || $Me->isPC);
$Conf->footerScript("peteramati_uservalue=" . json_encode($Me->user_linkpart($User)));

$Pset = ContactView::find_pset_redirect(@$_REQUEST["pset"]);

class Series {

    public $n;
    public $sum;
    public $sumsq;
    public $byg;
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
        $this->byg[$g] = @($this->byg[$g] + 1);
        $this->n += 1;
        $this->sum += $g;
        $this->sumsq += $g * $g;
        $this->calculated = false;
    }

    private function calculate() {
        sort($this->series);
        ksort($this->byg, SORT_NUMERIC);
        $this->cdf = array();
        $subtotal = 0;
        foreach ($this->byg as $k => $v) {
            $subtotal += $v;
            $this->cdf[] = $k;
            $this->cdf[] = $subtotal;
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
        if ($i != 0) {
            $r->cdf = array_slice($r->cdf, $i);
            $r->cutoff = $cutoff;
        }
    }

}

// get JSON grade series data
if (isset($_REQUEST["gradecdf"])) {
    if (!$Me->isPC && !Contact::student_can_see_grade_cdf($Pset))
        $Conf->ajaxExit(array("error" => "Grades are not visible now"));

    if ($Conf->setting("gradejson_pset$Pset->id", 0) < $Now - 30) {
        if (is_int($Pset->grades_visible))
            $notdropped = "(not c.dropped or c.dropped<$Pset->grades_visible)";
        else
            $notdropped = "not c.dropped";
        $q = "select cn.notes, c.extension from ContactInfo c\n";
        if ($Pset->gitless)
            $q .= "\t\tjoin ContactGrade cn on (cn.cid=c.contactId and cn.pset=$Pset->psetid)";
        else
            $q .= "\t\tjoin ContactLink l on (l.cid=c.contactId and l.type=" . LINK_REPO . " and l.pset=$Pset->id)
		join RepositoryGrade rg on (rg.repoid=l.link and rg.pset=$Pset->id and not rg.placeholder)
		join CommitNotes cn on (cn.hash=rg.gradehash and cn.pset=rg.pset)\n";
        $result = $Conf->qe($q . " where $notdropped");

        $series = new Series;
        $xseries = new Series;
        while (($row = edb_row($result)))
            if (($g = ContactView::pset_grade(json_decode($row[0]), $Pset))) {
                $series->add($g->total);
                if ($row[1])
                    $xseries->add($g->total);
            }

        $r = $series->summary();
        if ($xseries->n)
            $r->extension = $xseries->summary();

        $pgj = ContactView::pset_grade_json($Pset, false);
        if ($pgj && isset($pgj->maxgrades->total))
            $r->maxtotal = $pgj->maxgrades->total;

        $Conf->save_setting("gradejson_pset$Pset->id", $Now, json_encode($r));
    }

    $j = json_decode($Conf->setting_data("gradejson_pset$Pset->id"));
    $j->ok = true;
    if (!$Pset->separate_extension_grades || !$User->extension)
        unset($j->extension);
    if ($User == $Me && $Pset->grade_cdf_cutoff) {
        Series::truncate_summary_below($j, $Pset->grade_cdf_cutoff);
        if (@$j->extension)
            Series::truncate_summary_below($j->extension, $Pset->grade_cdf_cutoff);
    }
    $Conf->ajaxExit($j);
}

// load user repo and current commit
$Info = ContactView::user_pset_info($User, $Pset);
if (($Commit = @$_REQUEST["newcommit"]) == null)
    $Commit = @$_REQUEST["commit"];
if (!$Info->set_commit($Commit) && $Commit && $Info->repo) {
    $Conf->errorMsg("Commit " . htmlspecialchars($Commit) . " isn’t connected to this repository.");
    redirectSelf(array("newcommit" => null, "commit" => null));
}
$Commit = $Info->commit_hash();

// get JSON grade data
if (isset($_REQUEST["gradestatus"])) {
    $gj = ContactView::grade_json($Info);
    if ($gj) {
        $gj->ok = true;
        $Conf->ajaxExit($gj);
    } else
        $Conf->ajaxExit(array("error" => "Grades are not visible now"));
}

// get commit data
if (isset($_REQUEST["latestcommit"])) {
    if (!$Info || $Pset->gitless || !$Info->latest_commit())
        $j = array("hash" => false);
    else if (!$Info->can_view_repo_contents)
        $j = array("hash" => false, "error" => "Unconfirmed repository");
    else {
        $j = clone $Info->latest_commit();
        unset($j->fromhead);
        $j->snaphash = $Info->repo->snaphash;
    }
    $Conf->ajaxExit($j);
}

// maybe set commit
if (isset($_REQUEST["setgrader"]) && isset($_POST["grader"]) && check_post()
    && ($Commit || $Pset->gitless) && $Me->can_set_grader($Pset, $User)) {
    $grader = 0;
    foreach (pcMembers() as $pcm)
        if ($pcm->email === $_POST["grader"])
            $grader = $pcm->contactId;
    if (!$grader && $_POST["grader"] !== "none")
        $Conf->ajaxExit(array("ok" => false, "error" => "No such grader"));
    $Info->change_grader($grader);
    $Conf->ajaxExit(array("ok" => null, "grader_email" => $_POST["grader"]));
}
if (isset($_REQUEST["setcommit"]) && isset($_REQUEST["grade"]) && check_post()
    && ($Commit || $Pset->gitless) && $Me->isPC && $Me != $User)
    $Info->mark_grading_commit();
if (isset($_REQUEST["setcommit"]))
    go($Info->hoturl("pset"));

// maybe set partner/repo
if (isset($_REQUEST["set_partner"]))
    ContactView::set_partner_action($User);
if (isset($_REQUEST["set_seascode_repo"]))
    ContactView::set_seascode_repo_action($User);

// save line notes
if ($Me->isPC && $Me != $User && check_post()
    && isset($_REQUEST["savelinenote"])
    && isset($_REQUEST["file"]) && isset($_REQUEST["line"])) {
    $lineid = $_REQUEST["line"];
    if (ctype_digit($lineid))
        $lineid = "b" . $lineid;
    if (($note = defval($_REQUEST, "note", null)))
        $note = array(isset($_REQUEST["iscomment"]) && $_REQUEST["iscomment"], $note, $Me->contactId);
    $Info->update_commit_info(array("linenotes" =>
                                    array($_REQUEST["file"] => array($lineid => ($note ? $note : null)))));
    if (isset($_REQUEST["ajax"]))
        $Conf->ajaxExit(array("ok" => true, "savednote" => ($note ? $note[1] : ""), "iscomment" => ($note && $note[0])));
    redirectSelf();
} else if (isset($_REQUEST["savelinenote"]) && isset($_REQUEST["ajax"]))
    $Conf->ajaxExit(false);

// save grades
function save_grades($user, $pset, $info, $values, $isauto) {
    $grades = array();
    foreach ($pset->grades as $ge)
        if (isset($values[$ge->name])) {
            $g = trim($values[$ge->name]);
            if ($g === "")
                $grades[$ge->name] = null;
            else if (preg_match('_\A(?:0|[1-9]\d*)\z_', $g))
                $grades[$ge->name] = intval($g);
            else if (preg_match('_\A(?:0||[1-9]\d*)(?:\.\d*)?\z_', $g))
                $grades[$ge->name] = floatval($g);
        }
    $key = $isauto ? "autogrades" : "grades";
    if (count($grades) && $pset->gitless)
        $user->update_contact_grade($pset, array($key => $grades));
    else if (count($grades))
        $info->update_commit_info(array($key => $grades));
    return count($grades);
}

if ($Me->isPC && $Me != $User && check_post()
    && isset($_REQUEST["setgrade"]) && ($Info->commit() || $Pset->gitless)) {
    $result = save_grades($User, $Pset, $Info, $_REQUEST, false);
    if (isset($_REQUEST["ajax"]))
        $Conf->ajaxExit($result != 0);
    redirectSelf();
}

if ($Me->isPC && $Me != $User && check_post()
    && isset($_REQUEST["setlatehours"]) && ($Info->commit() || $Pset->gitless)) {
    if (isset($_REQUEST["late_hours"])
        && preg_match('_\A(?:0|[1-9]\d*)\z_', $_REQUEST["late_hours"])) {
        $lh = intval($_REQUEST["late_hours"]);
        if ($Pset->gitless)
            $User->update_contact_grade($Pset, array("late_hours" => $lh));
        else
            $Info->update_commit_info(array("late_hours" => $lh));
        $result = true;
    } else
        $result = false;
    if (isset($_REQUEST["ajax"]))
        $Conf->ajaxExit($result);
    redirectSelf();
}

function upload_grades($pset, $text, $fname) {
    global $Conf;
    assert($pset->gitless);
    $csv = new CsvParser($text);
    $csv->set_header($csv->next());
    while (($line = $csv->next())) {
        if (@$line["seascode_username"]) {
            $who = $line["seascode_username"];
            $user = Contact::find_by_username($who);
        } else if (@$line["name"]) {
            $who = $line["name"];
            list($first, $last) = Text::split_name($who);
            $user = Contact::find_by_query("firstName like '" . sqlqtrim($first) . "%' and lastName='" . sqlqtrim($last) . "'");
            if ($user && $user->firstName != $first
                && !str_starts_with($user->firstName, "$first "))
                $user = null;
        } else
            continue;
        if ($user) {
            if (!save_grades($user, $pset, null, $line, true))
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
    $tab = $tab == 8 ? null : $tab;
    $Info->update_commit_info(array("tabwidth" => $tab));
} else if (isset($_REQUEST["tab"])
           && ($_REQUEST["tab"] == "" || $_REQUEST["tab"] == "none"))
    $Info->update_commit_info(array("tabwidth" => null));
if (isset($_REQUEST["wdiff"]))
    $Info->update_commit_info(array("wdiff" => ((int) $_REQUEST["wdiff"] != 0)));

// save run settings
if ($Me->isPC && $Me != $User && isset($_REQUEST["saverunsettings"])
    && check_post()) {
    $x = @$_REQUEST["runsettings"];
    if (!count($x))
        $x = null;
    $Info->update_commit_info(array("runsettings" => $x), true);
    if (isset($_REQUEST["ajax"]))
        $Conf->ajaxExit(array("ok" => true, "runsettings" => $x));
}

// check for new commit
if ($User && $Info->repo)
    Contact::check_repo($Info->repo, 30);

$Conf->header(htmlspecialchars($Pset->title), "home");
$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";


// Top: user info
function user_prev_next($user, $pset) {
    global $Conf;

    $result = $Conf->qe("select c.contactId, c.firstName, c.lastName, c.email,
	c.huid, c.anon_username, c.seascode_username, c.extension, pl.link
	from ContactInfo c
	left join ContactLink pl on (pl.cid=c.contactId and pl.type=" . LINK_PARTNER . " and pl.pset=$pset->id)
	where (c.roles&" . Contact::ROLE_PCLIKE . ")=0 and not c.dropped
	group by c.contactId");
    $students = array();
    while (($s = edb_orow($result))) {
        $s->is_anonymous = $user->is_anonymous;
        Contact::set_sorter($s, @$_REQUEST["sort"]);
        $students[$s->contactId] = $s;
    }
    uasort($students, "Contact::compare");

    $links = array(null, null);
    $pos = 0;
    $uid = is_object($user) ? $user->contactId : $user;

    // mark user's partner as visited
    if (($s = @$students[$uid]) && $s->link && ($ss = @$students[$s->link]))
        $ss->visited = true;

    foreach ($students as $s) {
        if ($s->contactId == $uid)
            $pos = 1;
        else if (!@$s->visited) {
            $links[$pos] = $s;
            if ($pos)
                break;
            $s->visited = true;
            if ($s->link && ($ss = @$students[$s->link]))
                $ss->visited = true;
        }
    }

    if ($pos == 0)
        $links[0] = null;
    return $links;
}

echo "<div id='homeinfo'>";

if ($User->seascode_username && $Me->isPC) {
    // links to next/prev users
    $links = user_prev_next($User, $Pset);
    if ($links[0] || $links[1]) {
        $userkey = $User->is_anonymous ? "anon_username" : "seascode_username";
        echo "<div style=\"color:gray;float:right\"><h3 style=\"margin-top:0\">";
        if ($links[0]) {
            $u = $Me->user_linkpart($links[0], $User->is_anonymous);
            echo '<a href="', hoturl("pset", array("pset" => $Pset->urlkey, "u" => $u, "sort" => @$_REQUEST["sort"])), '">« ', htmlspecialchars($u), '</a>';
        }
        if ($links[0] && $links[1])
            echo ' · ';
        if ($links[1]) {
            $u = $Me->user_linkpart($links[1], $User->is_anonymous);
            echo '<a href="', hoturl("pset", array("pset" => $Pset->urlkey, "u" => $u, "sort" => @$_REQUEST["sort"])), '">', htmlspecialchars($u), ' »</a>';
        }
        echo "</h3></div>";
    }
}

$u = $Me->user_linkpart($User);
echo '<h2 class="homeemail"><a href="',
    hoturl("index", array("u" => $u)), '">', htmlspecialchars($u), '</a>';
if ($Me->privChair)
    echo "&nbsp;", become_user_link($User);
echo '</h2>';

if (!$User->is_anonymous && $User !== $Me)
    echo '<h3>', Text::user_html($User), '</h3>';

if ($User->dropped)
    ContactView::echo_group("", '<strong class="err">You have dropped the course.</strong> If this is incorrect, contact us.');

echo '<hr class="c" />';


// Per-pset

function diff_line_code($t) {
    global $TABWIDTH;
    while (($p = strpos($t, "\t")) !== false)
        $t = substr($t, 0, $p) . str_repeat(" ", $TABWIDTH - ($p % $TABWIDTH)) . substr($t, $p + 1);
    return str_replace("  ", " &nbsp;", htmlspecialchars($t));
}

function echo_linenote_entry_row($file, $lineid, $note, $displayed, $lnorder) {
    global $Pset, $Me, $User, $Info;
    if (!is_array($note))
        $note = array(false, $note);
    if (!$Me->isPC || $Me == $User || $displayed) {
        if ($Info->can_see_grades || $note[0]) {
            echo '<tr class="diffl61 gw">', /* NB script depends on this class */
                '<td colspan="2" class="difflnoteborder61"></td>',
                '<td class="difflnote61">';
            if ($lnorder) {
                $links = array();
                //list($pfile, $plineid) = $lnorder->get_prev($file, $lineid);
                //if ($pfile)
                //    $links[] = '<a href="#L' . $plineid . '_'
                //        . html_id_encode($pfile) . '">&larr; Prev</a>';
                list($nfile, $nlineid) = $lnorder->get_next($file, $lineid);
                if ($nfile)
                    $links[] = '<a href="#L' . $nlineid . '_'
                        . html_id_encode($nfile) . '">Next &gt;</a>';
                else
                    $links[] = '<a href="#">Top</a>';
                if (count($links))
                    echo '<div class="difflnoteptr61">',
                        join("&nbsp;&nbsp;&nbsp;", $links) , '</div>';
            }
            echo '<div class="note61',
                ($note[0] ? ' commentnote' : ' gradenote'),
                '">', htmlspecialchars($note[1]), '</div>',
                '<div class="clear"></div></td></tr>';
        }
        return;
    }
    echo '<tr class="diffl61 gw',
        ($note[0] ? ' isgrade61' : ' iscomment61'),
        '" savednote61="', htmlspecialchars($note[1]), '">', /* NB script depends on this class */
        '<td colspan="2" class="difflnoteborder61"></td>',
        '<td class="difflnote61">',
        '<div class="diffnoteholder61"',
        ($displayed ? "" : " style=\"display:none\""), ">",
        Ht::form($Info->hoturl_post("pset", array("savelinenote" => 1)),
                 array("onsubmit" => "return savelinenote61(this)")),
        "<div class=\"f-contain\">",
        Ht::hidden("file", $file),
        Ht::hidden("line", $lineid),
        Ht::hidden("iscomment", "", array("class" => "iscomment")),
        "<textarea class=\"diffnoteentry61\" name=\"note\">", htmlspecialchars($note[1]), "</textarea><br />";
    echo Ht::submit("Comment", array("onclick" => "return setiscomment61(this,1)")),
        ' ', Ht::submit("Grade", array("onclick" => "return setiscomment61(this,'')")),
        '<span class="ajaxsave61"></span>',
        "</div></form></div></td></tr>";
}

function echo_grade_cdf() {
    global $Conf, $Info, $Pset, $User, $Me;
    $sepx = $User->extension && $Pset->separate_extension_grades;
    $xmark = $sepx ? "extension " : "";
    echo '<div id="gradecdf61" style="float:right;position:relative">',
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
    $Conf->footerScript("gradecdf61(\"" . $Info->hoturl("pset", array("gradecdf" => 1)) . "\")");
}

function echo_grade_entry($ge) {
    global $User, $Me, $Info;
    $key = $ge->name;
    $grade = $autograde = null;
    $title = isset($ge->title) ? $ge->title : $key;
    $Notes = $Info->commit_info();
    if (@$Notes->autogrades && property_exists($Notes->autogrades, $key))
        $grade = $autograde = $Notes->autogrades->$key;
    if (@$Notes->grades && property_exists($Notes->grades, $key))
        $grade = $Notes->grades->$key;
    if (!$Info->can_see_grades
        || ($User == $Me && $grade === null && $ge->is_extra))
        return;

    $class = "grader61" . ($ge->no_total ? "" : " gradepart");
    if ($User == $Me) {
        $value = '<span class="' . $class . '" name61="' . $ge->name . '">' . htmlspecialchars(+$grade) . '</span>';
        if ($ge->max && !$ge->hide_max)
            $value .= ' <span class="grademax61">of ' . htmlspecialchars($ge->max) . '</span>';
    } else {
        $value = '<span class="gradeholder61">'
            . Ht::entry($key, $grade, array("onchange" => "jQuery(this).closest('form').submit()", "class" => $class))
            . '</span>';
        if ($ge->max)
            $value .= ' <span class="grademax61" style="display:inline-block;min-width:3.5em">of ' . htmlspecialchars($ge->max) . '</span>';
        $value .= " " . Ht::submit("Save", array("tabindex" => 1));
        $value .= ' <span class="ajaxsave61"></span>';
        if ($autograde && $autograde !== $grade)
            $value .= ' <span class="autograde61">autograde is ' . htmlspecialchars($autograde) . '</span>';
    }

    $remarks = array();
    if ($grade !== null && $ge->max && $grade > $ge->max && $User != $Me)
        $remarks[] = array(true, "Grade is above max");

    if ($User != $Me)
        echo Ht::form($Info->hoturl_post("pset", array("setgrade" => 1)),
                       array("onsubmit" => "return gradesubmit61(this)")),
            "<div class=\"f-contain\">";
    ContactView::echo_group($title, $value, $remarks, array("nowrap" => true));
    if ($User != $Me)
        echo "</div></form>";
}

function echo_grade_total($gj) {
    global $User, $Me, $Pset, $Info;
    if ($Info->can_see_grades && $gj && @$gj->grades && $gj->nentries > 1) {
        $value = '<span class="gradetotal61">' . $gj->grades->total . '</span>';
        if ($Me != $User)
            $value = '<span class="gradeholder61">' . $value . '</span>';
        if (@$gj->max->total)
            $value .= ' <span class="grademax61">of ' . $gj->maxgrades->total . '</span>';
        ContactView::echo_group("total", $value, null, array("nowrap" => true));
    }
}

class LinenotesOrder {
    private $diff;
    private $fileorder = array();
    private $lnseq;
    private $lnorder;
    private $totalorder;
    function __construct($linenotes, $diff, $seegradenotes) {
        $this->diff = $diff;
        $this->fileorder = array();
        $this->lnseq = array();
        $this->lnorder = array();
        $this->totalorder = array();
        if ($linenotes) {
            foreach ($this->diff as $file => $x)
                $this->fileorder[$file] = count($this->fileorder);
            foreach ($linenotes as $file => $notelist) {
                // Normally every file with notes will be present
                // already, but just in case---for example, the
                // handout repo got corrupted...
                if (!isset($this->fileorder[$file]))
                    $this->fileorder[$file] = count($this->fileorder);
                foreach ($notelist as $line => $note)
                    if ($seegradenotes || (is_array($note) && $note[0]))
                        $this->lnseq[] = array($file, $line, is_array($note) && $note[0]);
            }
            usort($this->lnseq, array($this, "compar"));
            foreach ($this->lnseq as $i => $fl)
                $this->lnorder[$fl[1] . "_" . $fl[0]] = $i;
        }
    }
    function seq() {
        return $this->lnseq;
    }
    function get_next($file, $lineid) {
        $seq = $this->lnorder[$lineid . "_" . $file];
        if ($seq === null || $seq == count($this->lnseq) - 1)
            return array(null, null);
        else
            return $this->lnseq[$seq + 1];
    }
    function get_prev($file, $lineid) {
        $seq = $this->lnorder[$lineid . "_" . $file];
        if ($seq === null || $seq == 0)
            return array(null, null);
        else
            return $this->lnseq[$seq - 1];
    }
    function compar($a, $b) {
        if ($a[0] != $b[0])
            return $this->fileorder[$a[0]] - $this->fileorder[$b[0]];
        if ($a[1][0] == $b[1][0])
            return (int) substr($a[1], 1) - (int) substr($b[1], 1);
        if (!isset($this->totalorder[$a[0]])) {
            $to = array();
            $n = 0;
            foreach ($this->diff[$a[0]]->diff as $l) {
                if ($l[0] == "+" || $l[0] == " ")
                    $to["b" . $l[2]] = ++$n;
                if ($l[0] == "-" || $l[0] == " ")
                    $to["a" . $l[1]] = ++$n;
            }
            $this->totalorder[$a[0]] = $to;
        } else
            $to = $this->totalorder[$a[0]];
        return $to[$a[1]] - $to[$b[1]];
    }
}

function echo_commit($Info) {
    global $Conf, $Me, $User, $Pset;
    global $TABWIDTH, $WDIFF;

    $Notes = $Info->commit_info();
    $TABWIDTH = defval($Notes, "tabwidth", 8);
    $WDIFF = isset($Notes->wdiff) ? $Notes->wdiff : false;

    // current commit and commit selector
    $sel = array();
    $curhead = $grouphead = null;
    foreach ($Info->recent_commits() as $k) {
        // visually separate older heads
        if ($curhead === null)
            $curhead = $k->fromhead;
        if ($curhead != $k->fromhead) {
            if (!$grouphead)
                $sel["from.$k->fromhead"] = (object)
                    array("type" => "optgroup",
                          "label" => "Other snapshots");
            else
                $sel["from.$k->fromhead"] = null;
            $curhead = $grouphead = $k->fromhead;
        }
        // actual option
        $x = utf8_substr($k->subject, 0, 72);
        if (strlen($x) != strlen($k->subject))
            $x .= "...";
        $sel[$k->hash] = substr($k->hash, 0, 7) . " " . htmlspecialchars($x);
    }
    $result = $Conf->qe("select hash from CommitNotes where (haslinenotes&" . ($Me == $User && !$Info->can_see_grades ? HASNOTES_COMMENT : HASNOTES_ANY) . ")!=0 and pset=$Pset->psetid and hash in ('" . join("','", array_keys($sel)) . "')");
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
        $value .= " " . Ht::submit("grade", "Grade", array("style" => $x))
            . " &nbsp" . $User->repo_link($Info->repo->url, "Download repo", "/archive-tarball/" . (substr($Info->commit_hash(), 0, 8) ? : "master"));
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
    if (!$Info->grading_hash() && $Me != $User)
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
        if (@$lh->commitat)
            $extra[] = "commit at " . $Conf->printableTimestamp($lh->commitat);
        if (@$lh->deadline)
            $extra[] = "deadline " . $Conf->printableTimestamp($lh->deadline);
        $extra = count($extra) ? ' <span style="font-weight:normal">(' . join(", ", $extra) . ')</span>' : "";
        $remarks[] = array(true, "This commit uses " . plural($lh->hours, "late hour") . $extra . ".");
    }
    if (($Info->is_latest_commit() || $Me->isPC)
        && $Pset->handout_repo_url) {
        $last_handout = Contact::handout_repo_latest_commit($Pset);
        $last_myhandout = $last_handout ? $Info->derived_handout_hash() : false;
        if ($last_handout && $last_myhandout && $last_handout == $last_myhandout)
            /* this is ideal: they have the latest handout commit */;
        else if ($last_handout && $last_myhandout)
            // they don't have the latest updates
            $remarks[] = array(true, "Updates are available for this problem set <span style=\"font-weight:normal\">(<a href=\"" . $Info->hoturl("diff", array("commit" => $last_myhandout, "commit1" => $last_handout)) . "\">see diff</a>)</span>. Run <code>git pull handout master</code> to merge these updates.");
        else if ($last_handout)
            $remarks[] = array(true, "Please create your repository by cloning our repository. Creating your repository from scratch makes it harder for you to get pset updates.");
        else if (!$last_handout && $Me->isPC) {
            $handout_files = Contact::repo_ls_files(Contact::handout_repo($Pset), "master");
            if (!count($handout_files))
                $remarks[] = array(true, "The handout repository, " . htmlspecialchars($Pset->handout_repo_url) . ", contains no files; perhaps handout_repo_url is misconfigured.");
            else
                $remarks[] = array(true, "The handout repository, " . htmlspecialchars($Pset->handout_repo_url) . ", does not contain problem set code yet.");
        }
    }

    // actually print
    echo Ht::form($Info->hoturl_post("pset", array("commit" => null, "setcommit" => 1)), array("class" => "commitcontainer61", "peteramati_pset" => $Info->pset->urlkey, "peteramati_commit" => $Info->latest_hash())),
        "<div class=\"f-contain\">";
    ContactView::echo_group($key, $value, $remarks);
    echo "</div></form>\n";
}

function echo_grader() {
    global $Me, $User, $Pset, $Info;
    $gradercid = $Info->gradercid();
    if ($Info->is_grading_commit() && $Me->can_see_grader($Pset, $User)) {
        $pcm = pcMembers();
        $gpc = @$pcm[$gradercid];
        $value_post = "";
        if ($Me->can_set_grader($Pset, $User)) {
            $sel = array();
            if (!$gpc) {
                $sel["none"] = "(None)";
                $sel[] = null;
            }
            foreach (pcMembers() as $pcm)
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
    if ($Info->can_see_grades
        && ($Me != $User || $Pset->grade_cdf_visible)) {
        $gj = ContactView::grade_json($Info);
        if (@$gj->grades)
            echo_grade_cdf();
    }
}

function echo_all_grades() {
    global $Me, $User, $Pset, $Info;
    $gj = ContactView::grade_json($Info);
    if (@$gj->grades || ($Info->can_see_grades && $Me != $User)) {
        echo_grade_total($gj);
        foreach ($Pset->grades as $ge)
            echo_grade_entry($ge);
    }

    $lhg = $Info->late_hours();
    if ($lhg && $User == $Me && $Info->can_see_grades) {
        if ($lhg->hours || @$gj->grades) {
            echo '<div style="margin-top:1.5em">';
            ContactView::echo_group("late hours", '<span class="grader61" name61="late_hours">' . htmlspecialchars($lhg->hours) . '</span>',
                                    array(), array("nowrap" => true));
            echo '</div>';
        }
    } else if ($User != $Me) {
        $lhag = $Info->late_hours(true);
        $value = '<span class="gradeholder61">'
            . Ht::entry("late_hours", $lhg ? $lhg->hours : "", array("onchange" => "jQuery(this).closest('form').submit()", "class" => "grader61"))
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
    if ($Me->isPC && @$info->pset->gitless)
        echo '<div style="float:right"><button type="button" onclick="jQuery(\'#upload\').show()">upload</button></div>';
    echo "<h2>", htmlspecialchars($info->pset->title), "</h2>";
    ContactView::echo_partner_group($info);
    ContactView::echo_repo_group($info);
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

if ($Pset->gitless) {
    echo_grade_cdf_here();
    echo_all_grades();

} else if ($Info->repo && !$Info->can_view_repo_contents
           && !$Me->isPC) {
    if ($Pset->grades)
        echo_grade_cdf_here();
    echo_grader();
    if ($Pset->grades)
        echo_all_grades();

} else if ($Info->repo && $Info->recent_commits()) {
    if ($Pset->grades)
        echo_grade_cdf_here();
    echo_commit($Info);

    // print runners
    $runnerbuttons = array();
    $last_run = false;
    foreach ($Pset->runners as $r)
        if ($Me->can_view_run($Pset, $r, $User)) {
            if ($Me->can_run($Pset, $r, $User)) {
                $b = Ht::button("run", htmlspecialchars($r->title),
                                array("value" => $r->name,
                                      "class" => "runner61",
                                      "style" => "font-weight:bold",
                                      "onclick" => "run61(this)",
                                      "loadgrade61" => isset($r->eval) ? "true" : null));
                $runnerbuttons[] = ($last_run ? " &nbsp;" : "") . $b;
                $last_run = true;
            } else
                $runnerbuttons[] = Ht::hidden("run", $r->name,
                                              array("class" => "runner61"));
        }
    if (count($runnerbuttons) && $Me->isPC && $Me != $User && $last_run)
        $runnerbuttons[] = " &nbsp;"
            . Ht::button("define", "+",
                         array("class" => "runner61",
                               "style" => "font-weight:bold",
                               "onclick" => "runsetting61.add()"));
    if (($Me->isPC && $Me != $User)
        || ($Info->grading_hash() && !$Info->is_grading_commit())
        || (!$Info->grading_hash() && $Pset->grades_visible)) {
        ContactView::add_regrades($Info);
        $already_requested = isset($Info->regrades[$Info->commit_hash()]);
        $runnerbuttons[] = ($last_run ? ' <span style="padding:0 1em"></span>' : "")
            . Ht::button("reqgrade",
                         $already_requested ? "Regrade Requested" : "Request Regrade",
                         array("style" => "font-weight:bold" . ($already_requested ? ";font-style:italic" : ""),
                               "onclick" => "reqregrade61(this)"));
    }
    if (count($runnerbuttons)) {
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
        $Conf->footerScript("jQuery('button.runner61').prop('disabled',false)");
    }

    // print current grader
    echo_grader();

    // print grade entries
    if ($Pset->grades)
        echo_all_grades();

    // collect diff and sort line notes
    $diff = $User->repo_diff($Info->repo, $Info->commit_hash(), $Pset, array("wdiff" => $WDIFF));
    $all_linenotes = $Info->commit_info("linenotes");
    $lnorder = new LinenotesOrder($all_linenotes, $diff, $Info->can_see_grades);

    // print line notes
    $notelinks = array();
    foreach ($lnorder->seq() as $fl) {
        $f = str_starts_with($fl[0], $Pset->directory_slash) ? substr($fl[0], strlen($Pset->directory_slash)) : $fl[0];
        $notelinks[] = '<a href="#L' . $fl[1] . '_' . html_id_encode($fl[0])
            . '" onclick="return gotoline61(this)" class="noteref61'
            . (!$fl[2] && !$Info->user_can_see_grades ? " hiddennote61" : "")
            .'">' . htmlspecialchars($f) . ':' . substr($fl[1], 1) . '</a>';
    }
    if (count($notelinks))
        ContactView::echo_group("notes", join(", ", $notelinks));

    // print runners
    $crunners = $Info->commit_info("run");
    foreach ($Pset->runners as $r) {
        if (!$Me->can_view_run($Pset, $r, $User))
            continue;

        $checkt = defval($crunners, $r->name);
        $rj = $checkt ? ContactView::runner_json($Info, $checkt) : null;
        if (!$rj && !$Me->can_run($Pset, $r, $User))
            continue;

        echo '<div id="run61out_' . $r->name . '"';
        if (!$rj || !isset($rj->timestamp))
            echo ' style="display:none"';
        echo '><h3><a class="fold61" href="#" onclick="',
            "return runfold61('$r->name')", '">',
            '<span class="foldarrow">&#x25B6;</span>&nbsp;',
            htmlspecialchars($r->output_title), '</a></h3>',
            '<div class="run61" id="run61_', $r->name, '" style="display:none"';
        if ($Pset->directory_noslash !== "")
            echo ' run61directory="', htmlspecialchars($Pset->directory_noslash), '"';
        if ($rj && isset($rj->timestamp))
            echo ' run61timestamp="', $rj->timestamp, '"';
        echo '><div class="run61in"><pre class="run61pre">';
        if ($rj && isset($rj->data) && ($pos = strpos($rj->data, "\n\n"))) {
            echo '<span class="run61timestamp">...started ',
                simplify_whitespace(strftime("%l:%M:%S%P %e %b %Y", $rj->timestamp)),
                "</span>\n";
            echo htmlspecialchars(substr($rj->data, $pos + 2));
        }
        echo '</pre></div></div></h3></div>', "\n";
    }

    // check for any linenotes
    $has_any_linenotes = false;
    foreach ($diff as $file => $dinfo)
        if (defval($all_linenotes, $file, null)) {
            $has_any_linenotes = true;
            break;
        }

    // line notes
    if (count($diff))
        echo "<hr style=\"clear:both\" />\n";
    foreach ($diff as $file => $dinfo) {
        $fileid = html_id_encode($file);
        $tabid = "file61_" . $fileid;
        $linenotes = defval($all_linenotes, $file, null);
        $display_table = $linenotes
            || (!$dinfo->boring
                && ($Me != $User || !$Info->can_see_grades
                    || !$Info->is_grading_commit() || !$has_any_linenotes));

        echo '<h3><a class="fold61" href="#" onclick="return fold61(',
            "'#$tabid'", ',this)"><span class="foldarrow">',
            ($display_table ? "&#x25BC;" : "&#x25B6;"),
            "</span>&nbsp;", htmlspecialchars($file), "</a>";
        if (!$dinfo->removed)
            echo '<a style="display:inline-block;margin-left:2em;font-weight:normal" href="', $Info->hoturl("raw", array("file" => $file)), '">[Raw]</a>';
        echo '</h3>';
        echo '<table id="', $tabid, '" class="code61 diff61 filediff61';
        if ($Me != $User)
            echo ' live';
        if (!$Info->user_can_see_grades)
            echo " hidegrade61";
        if (!$display_table)
            echo '" style="display:none';
        echo '" run61file="', htmlspecialchars($file), '" run61fileid="', $fileid, "\"><tbody>\n";
        if ($Me->isPC && $Me != $User)
            $Conf->footerScript("jQuery('#$tabid').mousedown(linenote61).mouseup(linenote61)");
        foreach ($dinfo->diff as $l) {
            if ($l[0] == "@")
                $x = array(" gx", "difflctx61", "", "", $l[3]);
            else if ($l[0] == " ")
                $x = array(" gc", "difflc61", $l[1], $l[2], $l[3]);
            else if ($l[0] == "-")
                $x = array(" gd", "difflc61", $l[1], "", $l[3]);
            else
                $x = array(" gi", "difflc61", "", $l[2], $l[3]);

            $aln = $x[2] ? "a" . $x[2] : "";
            $bln = $x[3] ? "b" . $x[3] : "";

            $ak = $bk = "";
            if ($linenotes && $aln && isset($linenotes->$aln))
                $ak = ' id="L' . $aln . '_' . $fileid . '"';
            if ($bln)
                $bk = ' id="L' . $bln . '_' . $fileid . '"';

            if (!$x[2] && !$x[3])
                $x[2] = $x[3] = "...";

            echo '<tr class="diffl61', $x[0], '">',
                '<td class="difflna61"', $ak, '>', $x[2], '</td>',
                '<td class="difflnb61"', $bk, '>', $x[3], '</td>',
                '<td class="', $x[1], '">', diff_line_code($x[4]), "</td></tr>\n";

            if ($linenotes && $bln && isset($linenotes->$bln))
                echo_linenote_entry_row($file, $bln, $linenotes->$bln, true,
                                        $lnorder);
            if ($linenotes && $aln && isset($linenotes->$aln))
                echo_linenote_entry_row($file, $aln, $linenotes->$aln, true,
                                        $lnorder);
        }
        echo "</tbody></table>\n";
    }

    $Conf->footerScript('jQuery(".diffnoteentry61").autogrow();jQuery(window).on("beforeunload",beforeunload61)');
    echo "<table id=\"diff61linenotetemplate\" style=\"display:none\"><tbody>";
    echo_linenote_entry_row("", "", array($Info->is_grading_commit(), ""),
                            false, null);
    echo "</tbody></table>";
} else {
    echo "<div class=\"commitcontainer61\" peteramati_pset=\"", htmlspecialchars($Info->pset->urlkey), "\">";
    ContactView::echo_group("this commit", "No commits yet for this problem set", array());
    echo "</div>\n";
}


$Conf->footerScript('window.psetpost61="'
                    . self_href(array("post" => post_value())) . '"');
if (!$Pset->gitless)
    $Conf->footerScript("checklatest61()", "checklatest61");

echo "<div class='clear'></div>\n";
$Conf->footer();
