<?php
// diff.php -- Peteramati diff page
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// See LICENSE for open-source distribution terms

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
Ht::stash_script("peteramati_uservalue=" . json_encode($Me->user_linkpart($User)));

$Pset = ContactView::find_pset_redirect(@$_REQUEST["pset"]);
$Info = ContactView::user_pset_info($User, $Pset);
if (!get($_GET, "commit") || !get($_GET, "commit1") || $Pset->gitless)
    $Me->escape();
$diff_options = ["wdiff" => false];

$hasha = $hashb = $hasha_mine = $hashb_mine = null;
$hrecent = Contact::handout_repo_recent_commits($Pset);
if (($hasha = git_commit_in_list($hrecent, $_GET["commit"])))
    $diff_options["hasha_hrepo"] = true;
else
    $hasha = $hasha_mine = $Info->set_commit($_GET["commit"]);
if (($hashb = git_commit_in_list($hrecent, $_GET["commit1"])))
    $diff_options["hashb_hrepo"] = true;
else
    $hashb = $hashb_mine = $Info->set_commit($_GET["commit1"]);
if (!$hasha || !$hashb) {
    if (!$hasha)
        $Conf->errorMsg("Commit " . htmlspecialchars($_GET["commit"]) . " is not connected to your repository.");
    if (!$hashb)
        $Conf->errorMsg("Commit " . htmlspecialchars($_GET["commit1"]) . " is not connected to your repository.");
    $Me->escape();
}

$diff_options["hasha"] = $hasha;

$Conf->header(htmlspecialchars($Pset->title), "home");
echo "<div id='homeinfo'>";
ContactView::echo_heading($User);


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


$commita = $hasha_mine ? $Info->recent_commits($hasha) : $hrecent[$hasha];
$commitb = $hashb_mine ? $Info->recent_commits($hashb) : $hrecent[$hashb];
if ($commita->hash === $Info->grading_hash())
    $commita->subject .= "  ✱"; // space, nbsp
if ($commitb->hash === $Info->grading_hash())
    $commitb->subject .= "  ✱"; // space, nbsp
$TABWIDTH = $Info->commit_info("tabwidth") ? : 4;

echo "<table><tr><td><h2>diff</h2></td><td style=\"padding-left:10px;line-height:110%\">",
    "<div class=\"diffl61 gd\" style=\"padding:2px 5px\"><big><code>", substr($hasha, 0, 7), "</code> ", htmlspecialchars($commita->subject), "</big></div>",
    "<div class=\"diffl61 gi\" style=\"padding:2px 5px\"><big><code>", substr($hashb, 0, 7), "</code> ", htmlspecialchars($commitb->subject), "</big></div>",
    "</td></tr></table><hr>\n";

// collect diff and sort line notes
$diff = $User->repo_diff($Info->repo, $hashb, $Pset, $diff_options);
$all_linenotes = $hashb_mine ? $Info->commit_info("linenotes") : array();
$lnorder = new LinenotesOrder($all_linenotes, $Info->can_see_grades);
$lnorder->set_diff($diff);

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
    if ($lnorder->file($file)) {
        $has_any_linenotes = true;
        break;
    }

// line notes
foreach ($diff as $file => $dinfo) {
    $linenotes = $lnorder->file($file);
    $open = $linenotes || !$dinfo->boring || (!$hasha_mine && !$hashb_mine);
    $Info->echo_file_diff($file, $dinfo, $lnorder, $open);
}

Ht::stash_script('jQuery(".diffnoteentry61").autogrow();jQuery(window).on("beforeunload",beforeunload61)');
echo "<table id=\"diff61linenotetemplate\" style=\"display:none\"><tbody>";
echo_linenote_entry_row("", "", array($Info->is_grading_commit(), ""),
                        false, null);
echo "</tbody></table>";

echo "<div class='clear'></div>\n";
$Conf->footer();
