<?php
// diffmany.php -- Peteramati multidiff page
// HotCRP and Peteramati are Copyright (c) 2006-2018 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ContactView::set_path_request(["/p"]);
if ($Me->is_empty() || !$Me->isPC)
    $Me->escape();
global $Pset, $Qreq, $psetinfo_idx;
$Qreq = make_qreq();
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

function echo_one(Contact $user, Pset $pset, Qrequest $qreq) {
    global $Me, $psetinfo_idx;
    ++$psetinfo_idx;
    $info = PsetView::make($pset, $user, $Me);
    if (!$info->repo)
        return;
    $info->set_hash(false);
    echo '<div id="pa-psetinfo', $psetinfo_idx, '" class="pa-psetinfo"',
        ' data-pa-pset="', htmlspecialchars($pset->urlkey),
        '" data-pa-user="', htmlspecialchars($Me->user_linkpart($user));
    if (!$pset->gitless && $info->commit_hash())
        echo '" data-pa-hash="', htmlspecialchars($info->commit_hash());
    if (!$pset->gitless && $pset->directory)
        echo '" data-pa-directory="', htmlspecialchars($pset->directory_slash);
    if ($Me->can_set_grades($pset, $info))
        echo '" data-pa-can-set-grades="yes';
    if ($info->user_can_view_grades())
        echo '" data-pa-user-can-view-grades="yes';
    if ($info->can_view_grades() && $info->has_assignable_grades())
        echo '" data-pa-gradeinfo="', htmlspecialchars(json_encode($info->grade_json()));
    echo '">';

    $u = $Me->user_linkpart($user);
    if ($user !== $Me && !$user->is_anonymous && $user->contactImageId)
        echo '<img class="pa-smallface" src="' . hoturl("face", array("u" => $u, "imageid" => $user->contactImageId)) . '" />';

    echo '<h2 class="homeemail"><a href="',
        hoturl("pset", array("u" => $u, "pset" => $pset->urlkey)), '">', htmlspecialchars($u), '</a>';
    if ($user->extension)
        echo " (X)";
    /*if ($Me->privChair && $user->is_anonymous)
        echo " ",*/
    if ($Me->privChair)
        echo "&nbsp;", become_user_link($user);
    echo '</h2>';

    if ($user !== $Me && !$user->is_anonymous)
        echo '<h3>', Text::user_html($user), '</h3>';
    echo '<hr class="c" />';

    if ($qreq->files) {
        $lnorder = $info->viewable_line_notes();
        $onlyfiles = $qreq->files;
        $hasha = $pset->handout_hash ? : $info->derived_handout_hash();
        $diff = $info->diff($hasha, $info->commit_hash(), $lnorder, ["onlyfiles" => $onlyfiles, "no_full" => true]);
        if (count($onlyfiles) == 1
            && isset($diff[$onlyfiles[0]])
            && $qreq->lines
            && preg_match('/\A\s*(\d+)-(\d+)\s*\z/', $qreq->lines, $m))
            $diff[$onlyfiles[0]] = $diff[$onlyfiles[0]]->restrict_linea(intval($m[1]), intval($m[2]) + 1);

        foreach ($diff as $file => $dinfo) {
            $info->echo_file_diff($file, $dinfo, $lnorder, true,
                ["no_heading" => count($qreq->files) == 1]);
        }

        $want_grades = $pset->has_grade_landmark;
    } else {
        echo '<div class="pa-gradelist',
            ($info->user_can_view_grades() ? "" : " pa-pset-hidden"), '"></div>';
        $want_grades = true;
    }

    echo "</div>\n";
    if ($want_grades)
        echo Ht::unstash_script('pa_loadgrades.call($("#pa-psetinfo' . $psetinfo_idx . '")[0], true)');
    echo "<hr />\n";
}

$title = $Pset->title;
if ($Qreq->files)
    $title .= " > " . join(" ", $Qreq->files);
$Conf->header(htmlspecialchars($title), "home");

if ($Qreq->files)
    echo Ht::button("Hide left", ["onclick" => "pa_diff_toggle_hide_left.call(this)"]);
echo "<hr />\n";

if (trim((string) $Qreq->users) === "") {
    $want = $visited = [];
    foreach ($Pset->students() as $s) {
        if (!isset($visited[$s->contactId])) {
            $want[] = $s->email;
            $visited[$s->contactId] = true;
            if ($s->pcid) {
                foreach (array_unique(explode(",", $s->pcid)) as $pcid) {
                    $visited[$pcid] = true;
                }
            }
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

Ht::stash_script('$(window).on("beforeunload",pa_beforeunload)');
echo "<div class='clear'></div>\n";
$Conf->footer();
