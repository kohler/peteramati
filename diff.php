<?php
// diff.php -- Peteramati diff page
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/@/p/h/h", "/p/h/h"));
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info, $Qreq;
$Qreq = make_qreq();

$User = $Me;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq->u)))
    redirectSelf(array("u" => null));
assert($User == $Me || $Me->isPC);
Ht::stash_script("peteramati_uservalue=" . json_encode($Me->user_linkpart($User)));

$Pset = ContactView::find_pset_redirect($Qreq->pset);
$Info = new PsetView($Pset, $User, $Me);
if (!$Qreq->commit || !$Qreq->commit1 || $Pset->gitless)
    $Me->escape();
$diff_options = ["wdiff" => false];

$hasha = $hashb = $hasha_mine = $hashb_mine = null;
$hrecent = $Pset->handout_commits();
if (($hasha = git_commit_in_list($hrecent, $Qreq->commit)))
    $diff_options["hasha_hrepo"] = true;
else
    $hasha = $hasha_mine = $Info->set_hash($Qreq->commit);
if (($hashb = git_commit_in_list($hrecent, $Qreq->commit1)))
    $diff_options["hashb_hrepo"] = true;
else
    $hashb = $hashb_mine = $Info->set_hash($Qreq->commit1);
if (!$hasha || !$hashb) {
    if (!$hasha)
        $Conf->errorMsg("Commit " . htmlspecialchars($Qreq->commit) . " is not connected to your repository.");
    if (!$hashb)
        $Conf->errorMsg("Commit " . htmlspecialchars($Qreq->commit1) . " is not connected to your repository.");
    $Me->escape();
}

$diff_options["hasha"] = $hasha;
if (!get($diff_options, "hasha_hrepo") || get($diff_options, "hashb_hrepo"))
    $diff_options["no_full"] = true;

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


$commita = $hasha_mine ? $Info->recent_commits($hasha) : $hrecent[$hasha];
$commitb = $hashb_mine ? $Info->recent_commits($hashb) : $hrecent[$hashb];
if ($commita->hash === $Info->grading_hash())
    $commita->subject .= "  ✱"; // space, nbsp
if ($commitb->hash === $Info->grading_hash())
    $commitb->subject .= "  ✱"; // space, nbsp
$TABWIDTH = $Info->commit_info("tabwidth") ? : 4;

echo "<table><tr><td><h2>diff</h2></td><td style=\"padding-left:10px;line-height:110%\">",
    "<div class=\"pa-dl gd\" style=\"padding:2px 5px\"><big><code>", substr($hasha, 0, 7), "</code> ", htmlspecialchars($commita->subject), "</big></div>",
    "<div class=\"pa-dl gi\" style=\"padding:2px 5px\"><big><code>", substr($hashb, 0, 7), "</code> ", htmlspecialchars($commitb->subject), "</big></div>",
    "</td></tr></table><hr>\n";

// collect diff and sort line notes
$diff = $User->repo_diff($Info->repo, $hashb, $Pset, $diff_options);
$all_linenotes = $hashb_mine ? $Info->commit_info("linenotes") : array();
$lnorder = new LinenotesOrder($all_linenotes, $Info->can_view_grades());
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

Ht::stash_script('$(".pa-note-entry").autogrow();jQuery(window).on("beforeunload",pa_beforeunload)');
echo "<div class='clear'></div>\n";
$Conf->footer();
