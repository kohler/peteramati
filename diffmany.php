<?php
// diffmany.php -- Peteramati multidiff page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ContactView::set_path_request(["/p"]);
if ($Me->is_empty() || !$Me->isPC)
    $Me->escape();
global $Pset, $Qreq, $psetinfo_idx, $all_viewed_gradeentries;
$Pset = ContactView::find_pset_redirect($Qreq->pset);

if ($Qreq->files) {
    $f = simplify_whitespace($Qreq->files);
    $Qreq->files = $f === "" ? [] : explode(" ", $f);
} else if ($Qreq->file) {
    $Qreq->files = [$Qreq->file];
} else {
    $Qreq->files = [];
}
$Qreq->files = $Pset->maybe_prefix_directory($Qreq->files);

$psetinfo_idx = 0;
$all_viewed_gradeentries = [];

function echo_one(Contact $user, Pset $pset, Qrequest $qreq) {
    global $Me, $psetinfo_idx, $all_viewed_gradeentries;
    ++$psetinfo_idx;
    $info = PsetView::make($pset, $user, $Me);
    if (!$pset->gitless && !$info->repo) {
        return;
    }
    $info->set_hash(false);
    echo '<div id="pa-psetinfo', $psetinfo_idx,
        '" class="pa-psetinfo pa-diffcontext',
        '" data-pa-pset="', htmlspecialchars($pset->urlkey),
        '" data-pa-user="', htmlspecialchars($Me->user_linkpart($user));
    if (!$pset->gitless && $info->commit_hash()) {
        echo '" data-pa-hash="', htmlspecialchars($info->commit_hash());
    }
    if (!$pset->gitless && $pset->directory) {
        echo '" data-pa-directory="', htmlspecialchars($pset->directory_slash);
    }
    if ($info->user_can_view_grades()) {
        echo '" data-pa-user-can-view-grades="yes';
    }
    if ($info->can_edit_grades_staff()
        || ($info->can_view_grades() && $info->is_current_grades())) {
        echo '" data-pa-gradeinfo="', htmlspecialchars(json_encode_browser($info->grade_json(true)));
    }
    echo '">';

    $u = $Me->user_linkpart($user);
    if ($user !== $Me && !$user->is_anonymous && $user->contactImageId) {
        echo '<img class="pa-smallface" src="' . hoturl("face", array("u" => $u, "imageid" => $user->contactImageId)) . '" />';
    }

    echo '<h2 class="homeemail"><a href="',
        hoturl("pset", array("u" => $u, "pset" => $pset->urlkey)), '">', htmlspecialchars($u), '</a>';
    if ($user->extension) {
        echo " (X)";
    }
    /*if ($Me->privChair && $user->is_anonymous)
        echo " ",*/
    if ($Me->privChair) {
        echo "&nbsp;", become_user_link($user);
    }
    echo '</h2>';

    if ($user !== $Me && !$user->is_anonymous) {
        echo '<h3>', Text::user_html($user), '</h3>';
    }
    echo '<hr class="c" />';

    if (!$pset->gitless && $info->commit()) {
        $lnorder = $info->viewable_line_notes();
        $onlyfiles = $qreq->files;
        $diff = $info->diff($info->base_handout_commit(), $info->commit(), $lnorder, ["onlyfiles" => $onlyfiles, "no_full" => true]);
        if (count($onlyfiles) == 1
            && isset($diff[$onlyfiles[0]])
            && $qreq->lines
            && preg_match('/\A\s*(\d+)-(\d+)\s*\z/', $qreq->lines, $m)) {
            $diff[$onlyfiles[0]] = $diff[$onlyfiles[0]]->restrict_linea(intval($m[1]), intval($m[2]) + 1);
        }
        $want_grades = $pset->has_grade_landmark;

        if (!empty($diff)) {
            if ($info->can_edit_grades_staff() && !$pset->has_grade_landmark_range) {
                PsetView::echo_pa_sidebar_gradelist();
                $want_grades = true;
            }
            foreach ($diff as $file => $dinfo) {
                $info->echo_file_diff($file, $dinfo, $lnorder,
                    ["open" => true, "id_by_user" => true, "hide_left" => true,
                     "no_heading" => count($qreq->files) == 1]);
            }
            if ($info->can_edit_grades_staff() && !$pset->has_grade_landmark_range) {
                PsetView::echo_close_pa_sidebar_gradelist();
            }
        }

        $all_viewed_gradeentries += $info->viewed_gradeentries; // XXX off if want all grades
    } else {
        echo '<div class="pa-gradelist',
            ($info->user_can_view_grades() ? "" : " pa-pset-hidden"), '"></div>';
        $want_grades = true;
    }

    echo "</div>\n";
    if ($want_grades) {
        echo Ht::unstash_script('pa_loadgrades.call(document.getElementById("pa-psetinfo' . $psetinfo_idx . '"))');
    }
    echo "<hr />\n";
}

$title = $Pset->title;
if ($Qreq->files)
    $title .= " > " . join(" ", $Qreq->files);
$Conf->header(htmlspecialchars($title), "home");

if ($Pset->grade_script) {
    foreach ($Pset->grade_script as $gs)
        Ht::stash_html($Conf->make_script_file($gs));
}
echo "<div class=\"pa-psetinfo pa-diffset pa-with-diffbar\" data-pa-gradeinfo=\"",
     htmlspecialchars(json_encode(new GradeExport($Pset, $Me->isPC))),
     "\">";
PsetView::echo_pa_diffbar();

if (trim((string) $Qreq->users) === "") {
    $want = [];
    $sset = new StudentSet($Me);
    $sset->set_pset($Pset);
    foreach ($sset as $info) {
        if (!$info->user->visited
            && $info->grading_hash()
            && !$info->user->dropped) {
            $want[] = $info->user->email;
            foreach ($info->user->links(LINK_PARTNER, $Pset->id) as $pcid)
                if (($u = $sset->user($pcid)))
                    $u->visited = true;
        }
    }
    $Qreq->users = join(" ", $want);
}

foreach (explode(" ", $Qreq->users) as $user) {
    if ($user !== "" && ($user = $Conf->user_by_whatever($user))) {
        echo_one($user, $Pset, $Qreq);
    } else if ($user !== "") {
        echo "<p>no such user ", htmlspecialchars($user), "</p>\n";
    }
}

foreach ($all_viewed_gradeentries as $gkey => $x) {
    $gradeentry = $Pset->all_grades[$gkey];
    if ($gradeentry->landmark_buttons) {
        foreach ($gradeentry->landmark_buttons as $lb) {
            if (is_object($lb) && isset($lb->summary_className)) {
                echo '<button type="button" class="ui btn ', $lb->summary_className, '" data-pa-class="', $lb->className, '">Summarize ', $lb->title, '</button>';
            }
        }
    }
}

Ht::stash_script('$(window).on("beforeunload",pa_beforeunload)');
echo "</div><div class='clear'></div>\n";
$Conf->footer();
