<?php
// diff.php -- Peteramati diff page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
global $User, $Pset, $Info, $Qreq;
ContactView::set_path_request($Qreq, ["/@", "/@/p", "/@/p/h", "/@/p/h/h", "/p/h/h"], $Conf);
if ($Me->is_empty()) {
    $Me->escape();
}

$User = $Me;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq, $Me))) {
    redirectSelf(["u" => null]);
}
assert($User === $Me || $Me->isPC);
$Conf->set_siteinfo("uservalue", $Me->user_linkpart($User));

$Pset = ContactView::find_pset_redirect($Qreq->pset, $Me);
if ($Pset->gitless) {
    $Conf->errorMsg("That problem set does not use git.");
    $Me->escape(); // XXX stay on this page
}
$Info = PsetView::make($Pset, $User, $Me);
if (!$Info->repo) {
    $Conf->errorMsg("No repository.");
    $Me->escape(); // XXX stay on this page
}
if (!$Qreq->commit || !$Qreq->commit1) {
    if (!$Qreq->commit1) {
        $Qreq->commit1 = $Info->hash();
    }
    if (!$Qreq->commit) {
        $Qreq->commit = $Info->derived_handout_hash();
    }
    if ($Qreq->commit && $Qreq->commit1) {
        redirectSelf(["commit" => $Qreq->commit, "commit1" => $Qreq->commit1]);
    } else {
        $Me->escape();
    }
}

$commita = $Info->find_commit($Qreq->commit);
$commitb = $Info->find_commit($Qreq->commit1);
if (!$commita || !$commitb) {
    if (!$commita) {
        $Conf->errorMsg("Commit " . htmlspecialchars($Qreq->commit) . " is not connected to your repository.");
    }
    if (!$commitb) {
        $Conf->errorMsg("Commit " . htmlspecialchars($Qreq->commit1) . " is not connected to your repository.");
    }
    $Me->escape();
}
$Info->set_hash($commitb->hash);

if ($commita->hash === $Info->grading_hash()) {
    $commita->subject .= "  ✱"; // space, nbsp
}
if ($commitb->hash === $Info->grading_hash()) {
    $commitb->subject .= "  ✱"; // space, nbsp
}


$Conf->header(htmlspecialchars($Pset->title), "home");
ContactView::echo_heading($User, $Me);

$infoj = $Info->info_json();
$infoj["base_commit"] = $commita->hash;
$infoj["base_handout"] = $Pset->is_handout($commita);
echo '<div class="pa-psetinfo" data-pa-pset="', htmlspecialchars($Info->pset->urlkey),
    '" data-pa-base-commit="', htmlspecialchars($commita->hash),
    '" data-pa-commit="', htmlspecialchars($commitb->hash),
    '" data-pa-gradeinfo="', htmlspecialchars(json_encode_browser($infoj));
if (!$Pset->gitless && $Pset->directory) {
    echo '" data-pa-directory="', htmlspecialchars($Pset->directory_slash);
}
if ($Info->user->extension) {
    echo '" data-pa-user-extension="yes';
}
echo '">';

echo "<table class=\"mb-4\"><tr><td><h2>diff</h2></td><td style=\"padding-left:10px;line-height:110%\">",
    "<div class=\"pa-dl pa-gdsamp\" style=\"padding:2px 5px\"><big>",
    $Info->commit_link(substr($commita->hash, 0, 7), " " . htmlspecialchars($commita->subject), $commita->hash),
    "</big></div><div class=\"pa-dl pa-gisamp\" style=\"padding:2px 5px\"><big>",
    $Info->commit_link(substr($commitb->hash, 0, 7), " " . htmlspecialchars($commitb->subject), $commitb->hash),
    "</big></div></td></tr></table>";

// collect diff and sort line notes
$lnorder = $Pset->is_handout($commitb) ? $Info->empty_line_notes() : $Info->visible_line_notes();

// print line notes
$notelinks = [];
foreach ($lnorder->seq() as $note) {
    if (!$note->is_empty()) {
        $notelinks[] = $note->render_line_link_html($Pset);
    }
}
if (!empty($notelinks)) {
    ContactView::echo_group("notes", join(", ", $notelinks));
}

$diff = $Info->diff($commita, $commitb, $lnorder, [
    "no_full" => !$Pset->is_handout($commita) || $Pset->is_handout($commitb),
    "no_user_collapse" => true
]);
if ($diff) {
    echo '<div class="pa-diffset">';
    // diff and line notes
    foreach ($diff as $file => $dinfo) {
        $Info->echo_file_diff($file, $dinfo, $lnorder, ["only_diff" => true]);
    }
    echo '</div>';
}

Ht::stash_script('$(window).on("beforeunload",$pa.beforeunload)');
echo "</div><hr class=\"c\" />\n";
$Conf->footer();
