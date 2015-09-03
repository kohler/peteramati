<?php
// diff.php -- HotCRP diff page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/@/p/h/h", "/p/h/h"));
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info;

$User = $Me;
if (isset($_REQUEST["u"])
    && !($User = ContactView::prepare_user($_REQUEST["u"])))
    redirectSelf(array("u" => null));
assert($User == $Me || $Me->isPC);
$Conf->footerScript("hotmonster_uservalue=" . json_encode($Me->user_linkpart($User)));

$Pset = ContactView::find_pset_redirect(@$_REQUEST["pset"]);
$Info = ContactView::user_pset_info($User, $Pset);
if (!@$_GET["commit"] || !@$_GET["commit1"] || @$Pset->gitless)
    $Me->escape();
$diff_options = array();

$hasha = $hashb = $hasha_mine = $hashb_mine = null;
if ($Info->repo) {
    $hasha = $hasha_mine = $Info->set_commit($_GET["commit"]);
    $hashb = $hashb_mine = $Info->set_commit($_GET["commit1"]);
}

$hrepo = null;
if (!$hasha || !$hashb) {
    $hrecent = Contact::handout_repo_recent_commits($Pset);
    if (!$hasha) {
        $hasha = git_commit_in_list($hrecent, $_GET["commit"]);
        $diff_options["basehash_hrepo"] = true;
    }
    if (!$hashb)
        $hashb = git_commit_in_list($hrecent, $_GET["commit1"]);
}

if (!$hasha || !$hashb) {
    if (!$hasha)
        $Conf->errorMsg("Commit " . htmlspecialchars($_GET["commit"]) . " is not connected to your repository.");
    if (!$hashb)
        $Conf->errorMsg("Commit " . htmlspecialchars($_GET["commit1"]) . " is not connected to your repository.");
    $Me->escape();
}

$Conf->header(htmlspecialchars($Pset->title), "home");
echo "<div id='homeinfo'>";
echo "<h2 class='homeemail'>", Text::user_html($User), "</h2>";
if ($User->dropped)
    ContactView::echo_group("", '<strong class="err">You have dropped the course.</strong> If this is incorrect, contact us.');


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


$commita = $hasha_mine ? $Info->recent_commits($hasha) : $hrecent[$hasha];
$commitb = $hashb_mine ? $Info->recent_commits($hashb) : $hrecent[$hashb];
if ($commita->hash === $Info->grading_hash())
    $commita->subject .= "  ✱"; // space, nbsp
if ($commitb->hash === $Info->grading_hash())
    $commitb->subject .= "  ✱"; // space, nbsp
$TABWIDTH = $Info->commit_info("tabwidth") ? : 8;

echo "<table><tr><td><h2>diff</h2></td><td style=\"padding-left:10px;line-height:110%\">",
    "<div class=\"diffl61 gd\" style=\"padding:2px 5px\"><big><code>", substr($hasha, 0, 7), "</code> ", htmlspecialchars($commita->subject), "</big></div>",
    "<div class=\"diffl61 gi\" style=\"padding:2px 5px\"><big><code>", substr($hashb, 0, 7), "</code> ", htmlspecialchars($commitb->subject), "</big></div>",
    "</td></tr></table><hr>\n";

// collect diff and sort line notes
$diff = $User->repo_diff($Info->repo, $hashb, $Pset, array("wdiff" => false /*$WDIFF*/,
                                                           "basehash" => $hasha));
$all_linenotes = $hashb_mine ? $Info->commit_info("linenotes") : array();
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

// check for any linenotes
$has_any_linenotes = false;
foreach ($diff as $file => $dinfo)
    if (defval($all_linenotes, $file, null)) {
        $has_any_linenotes = true;
        break;
    }

// line notes
foreach ($diff as $file => $dinfo) {
    $fileid = html_id_encode($file);
    $tabid = "file61_" . $fileid;
    $linenotes = defval($all_linenotes, $file, null);
    $display_table = $linenotes || !@$dinfo->boring;

    echo '<h3><a class="fold61" href="#" onclick="return fold61(',
        "'#$tabid'", ',this)"><span class="foldarrow">',
        ($display_table ? "&#x25BC;" : "&#x25B6;"),
        "</span>&nbsp;", htmlspecialchars($file), "</a>";
    if (!@$dinfo->removed)
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

echo "<div class='clear'></div>\n";
$Conf->footer();
