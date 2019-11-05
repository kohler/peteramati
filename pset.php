<?php
// pset.php -- Peteramati problem set page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/p", "/p/h", "/p/u/h"));
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info, $Qreq;

$User = $Me;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq->u)))
    redirectSelf(array("u" => null));
assert($User == $Me || $Me->isPC);
Ht::stash_script("peteramati_uservalue=" . json_encode_browser($Me->user_linkpart($User)));

$Pset = ContactView::find_pset_redirect($Qreq->pset);

// load user repo and current commit
$Info = PsetView::make($Pset, $User, $Me);
if (($commit = $Qreq->newcommit) == null)
    $commit = $Qreq->commit;
if (!$Info->set_hash($commit) && $commit && $Info->repo) {
    $Conf->errorMsg("Commit " . htmlspecialchars($commit) . " isn’t connected to this repository.");
    redirectSelf(array("newcommit" => null, "commit" => null));
}

// maybe set commit
if (isset($Qreq->setgrader)
    && isset($Qreq->grader)
    && $Qreq->post_ok()
    && $Info->can_have_grades()
    && $Me->can_set_grader($Pset, $User)) {
    $grader = 0;
    foreach ($Conf->pc_members_and_admins() as $pcm) {
        if ($pcm->email === $_POST["grader"])
            $grader = $pcm->contactId;
    }
    if (!$grader && $_POST["grader"] !== "none")
        json_exit(["ok" => false, "error" => "No such grader"]);
    $Info->change_grader($grader);
    json_exit(["ok" => null, "grader_email" => $_POST["grader"]]);
}
if (isset($Qreq->setcommit)
    && isset($Qreq->grade)
    && check_post()
    && $Info->can_have_grades()
    && $Me->isPC
    && $Me != $User)
    $Info->mark_grading_commit();
if (isset($Qreq->setcommit))
    go($Info->hoturl("pset"));

// maybe set partner/repo
if ($Qreq->set_partner)
    ContactView::set_partner_action($User, $Qreq);
if ($Qreq->set_repo)
    ContactView::set_repo_action($User, $Qreq);
if ($Qreq->set_branch)
    ContactView::set_branch_action($User, $Qreq);

// maybe download file
if ($Qreq->download
    && check_post()) {
    $dl = get($Info->pset->downloads, $Qreq->download);
    if (!$dl
        || (!$dl->visible && $Info->viewer === $Info->user)) {
        header("HTTP/1.0 404 Not Found");
        exit;
    }
    $content = @file_get_contents($dl->file);
    if ($content === false) {
        if (!file_exists($dl->file)) {
            header("HTTP/1.0 404 Not Found");
        } else if (!is_readable($dl->file)) {
            header("HTTP/1.0 403 Forbidden");
        } else {
            header("HTTP/1.0 500 Internal Server Error");
        }
        exit;
    }
    if ($dl->timed) {
        $dls = $Info->user_info("downloaded_at") ? : [];
        $old_dls = $dls ? get($dls, $dl->key, []) : [];
        $old_dls[] = ($Info->viewer === $Info->user ? [$Now] : [$Now, $Info->viewer->contactId]);
        $Info->update_user_info(["downloaded_at" => [$dl->key => $old_dls]]);
    }
    session_write_close();
    header("Content-Type: " . Mimetype::type_with_charset(Mimetype::content_type($content)));
    header("Content-Disposition: attachment; filename=" . mime_quote_string($dl->filename));
    header("X-Content-Type-Options: nosniff");
    // Etag
    header("Content-Length: " . strlen($content));
    echo $content;
    exit;
}

// save grades
function save_grades(Pset $pset, PsetView $info, $values, $isauto) {
    if ($info->is_handout_commit())
        json_exit(["ok" => false, "error" => "This is a handout commit."]);
    $grades = $maxgrades = [];
    foreach ($pset->grades() as $ge) {
        if (isset($values[$ge->key])
            && ($g = $ge->parse_value($values[$ge->key])) !== false) {
            if (isset($values["old;" . $ge->key])) {
                $old_grade = $info->current_grade_entry($ge->key);
                if ($ge->values_differ($g, $old_grade))
                    json_exit(["ok" => false, "error" => "This grade has been updated—please reload."]);
            }
            $grades[$ge->key] = $g;
        }
    }
    $updates = [];
    if (!empty($grades))
        $updates[$isauto ? "autogrades" : "grades"] = $grades;
    if (isset($values["timestamp"]) && is_numeric($values["timestamp"])) {
        $timestamp = intval($values["timestamp"]);
        if ($timestamp >= 1400000000)
            $updates["timestamp"] = $timestamp;
        else if ($timestamp <= 0)
            $updates["timestamp"] = null;
    }
    if (!empty($updates))
        $info->update_grade_info($updates);
    return $grades;
}

function upload_grades($pset, $text, $fname) {
    global $Conf, $Me;
    assert($pset->gitless_grades);
    $csv = new CsvParser($text);
    $csv->set_header($csv->next());
    $errors = [];
    while (($line = $csv->next())) {
        if (($who = get($line, "email")) && $who !== "-") {
            $user = $Conf->user_by_email($who);
        } else if (($who = get($line, "github_username")) && $who !== "-") {
            $user = $Conf->user_by_whatever($who, Conf::USERNAME_GITHUB);
        } else if (($who = get($line, "seascode_username")) && $who !== "-") {
            $user = $Conf->user_by_whatever($who, Conf::USERNAME_HARVARDSEAS);
        } else if (($who = get($line, "huid")) && $who !== "-") {
            $user = $Conf->user_by_whatever($who, Conf::USERNAME_HUID);
        } else if (($who = get($line, "username")) && $who !== "-") {
            $user = $Conf->user_by_whatever($who, Conf::USERNAME_USERNAME);
        } else if (($who = get($line, "name"))) {
            list($first, $last) = Text::split_name($who);
            $user = $Conf->user_by_query("firstName like '?s%' and lastName=?", [$first, $last]);
            if ($user && $user->firstName != $first
                && !str_starts_with($user->firstName, "$first "))
                $user = null;
        } else {
            continue;
        }
        if ($user) {
            $info = PsetView::make($pset, $user, $Me);
            if (!save_grades($pset, $info, $line, true))
                $errors[] = htmlspecialchars($fname) . ":" . $csv->lineno() . ": no grades set";
        } else
            $errors[] = htmlspecialchars($fname) . ":" . $csv->lineno() . ": unknown user " . htmlspecialchars($who);
    }
    if (!empty($errors))
        $Conf->errorMsg(join("<br />\n", $errors));
    return true;
}

if ($Me->isPC && check_post() && isset($Qreq->uploadgrades)
    && file_uploaded($_FILES["file"])) {
    if (($text = file_get_contents($_FILES["file"]["tmp_name"])) === false)
	$Conf->errorMsg("Internal error: cannot read file.");
    else if (upload_grades($Pset, $text, $_FILES["file"]["name"]))
        redirectSelf();
}

// save tab width, wdiff
if (isset($Qreq->tab)
    && ctype_digit($Qreq->tab)
    && $Qreq->tab >= 1
    && $Qreq->tab <= 16) {
    $tab = (int) $Qreq->tab;
    $tab = $tab == 4 ? null : $tab;
    $Info->update_commit_info(["tabwidth" => $tab]);
} else if (isset($Qreq->tab)
           && ($Qreq->tab === "" || $Qreq->tab === "none"))
    $Info->update_commit_info(["tabwidth" => null]);
if (isset($Qreq->wdiff))
    $Info->update_commit_info(["wdiff" => ((int) $Qreq->wdiff != 0)]);

// save run settings
if ($Me->isPC && $Me != $User && isset($Qreq->saverunsettings)
    && check_post()) {
    $x = $Qreq->get_a("runsettings");
    if (empty($x))
        $x = null;
    $Info->update_commit_info(["runsettings" => $x], true);
    if (isset($Qreq->ajax))
        json_exit(["ok" => true, "runsettings" => $x]);
}

// check for new commit
if ($User && $Info->repo)
    $Info->repo->refresh(30);

if ($Pset->has_xterm_js) {
    $Conf->add_stylesheet("stylesheets/xterm.css");
    $Conf->add_javascript("scripts/xterm.js");
}
$Conf->header(htmlspecialchars($Pset->title), "home");
$xsep = ' <span class="barsep">&nbsp;·&nbsp;</span> ';


// Top: user info

function session_list_position($sl, $info) {
    global $Qreq;
    $p = array_search($info->user->contactId, $sl->ids);
    if ($p !== false && isset($sl->psetids)) {
        $reqcommit = $Qreq->commit;
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

if ($Me->isPC && ($sl = $Conf->session_list())
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
        " data-hotlist=\"", htmlspecialchars(json_encode_browser($sl)), "\">",
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

function echo_grade_cdf() {
    global $Info, $Qreq;
    echo '<div id="pa-grade-statistics" class="pa-grgraph pa-grade-statistics hidden';
    if (!$Info->user_can_view_grade_statistics()) {
        echo ' pa-pset-hidden';
    }
    echo '" data-pa-pset="', $Info->pset->urlkey;
    if (is_string($Qreq->gg)) {
        echo '" data-pa-gg-type="', htmlspecialchars($Qreq->gg);
    }
    echo '"><a class="qq ui js-grgraph-flip prev" href="">&lt;</a>';
    echo '<a class="qq ui js-grgraph-flip next" href="">&gt;</a>';
    echo '<h4 class="title pa-grgraph-type"></h4>';
    if ($Info->can_view_grade_statistics_graph()) {
        echo '<div class="plot" style="width:350px;height:200px"></div>';
    }
    echo '<div class="statistics"></div></div>';
    Ht::stash_script("\$(\"#pa-grade-statistics\").each(pa_gradecdf)");
}

function echo_commit($Info) {
    global $Conf, $Me, $User, $Pset;
    global $TABWIDTH, $WDIFF;

    $Notes = $Info->commit_info();
    $TABWIDTH = $Info->tabwidth();
    $WDIFF = isset($Notes->wdiff) ? $Notes->wdiff : false;

    // current commit and commit selector
    $sel = $bhashes = [];
    $curhead = $grouphead = null;
    foreach ($Info->recent_commits() as $k) {
        // visually separate older heads
        if ($curhead === null)
            $curhead = $k->fromhead;
        if ($curhead !== $k->fromhead && !$k->is_handout()) {
            if (!$grouphead) {
                $sel["from.$k->fromhead"] = (object) [
                    "type" => "optgroup", "label" => "Other snapshots"
                ];
            } else {
                $sel["from.$k->fromhead"] = null;
            }
            $curhead = $grouphead = $k->fromhead;
        }
        // actual option
        $x = UnicodeHelper::utf8_prefix($k->subject, 72);
        if (strlen($x) != strlen($k->subject))
            $x .= "...";
        $sel[$k->hash] = substr($k->hash, 0, 7) . " " . htmlspecialchars($x);
        $bhashes[] = hex2bin($k->hash);
    }
    $notesflag = HASNOTES_ANY;
    if ($Me == $User && !$Info->can_view_grades()) {
        $notesflag = HASNOTES_COMMENT;
    }
    $result = $Conf->qe("select bhash, haslinenotes, hasflags, hasactiveflags
        from CommitNotes where pset=? and bhash?a and (haslinenotes or hasflags)",
        $Pset->psetid, $bhashes);
    while (($row = edb_row($result))) {
        $hex = bin2hex($row[0]);
        $f = "";
        if ($row[1] & $notesflag)
            $f .= "♪";
        if ($row[3])
            $f .= "⚑";
        else if ($row[2])
            $f .= "⚐";
        if ($f !== "")
            $sel[bin2hex($row[0])] .= "  $f";
    }
    Dbl::free($result);

    if (!empty($sel)
        && ($h = $Info->update_grading_hash(true))
        && isset($sel[$h])) {
        $sel[$h] = preg_replace('_\A(.*?)(?:  |)((?:|♪)(?:|⚑|⚐))\z_', '$1 &nbsp;✱$2', $sel[$h]);
    }

    if ($Info->is_grading_commit()) {
        $key = "grading commit";
    } else {
        $key = "this commit";
    }
    $value = Ht::select("newcommit", $sel, $Info->commit_hash(),
                        array("onchange" => "jQuery(this).closest('form').submit()"));
    if ($Me != $User) {
        $x = $Info->is_grading_commit() ? "" : "font-weight:bold";
        $value .= " " . Ht::submit("grade", "Grade", array("style" => $x));
    }

    // view options
    $fold_viewoptions = !isset($Qreq->tab) && !isset($Qreq->wdiff);
    $value .= '<div class="viewoptions61">'
        . '<a class="q" href="#" onclick="return fold61(this.nextSibling,this.parentNode)">'
        . '<span class="foldarrow">'
        . ($fold_viewoptions ? '&#x25B6;' : '&#x25BC;')
        . '</span>&nbsp;options</a><span style="padding-left:1em"'
        . ($fold_viewoptions ? ' class="hidden"' : '') . '>tab width:';
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
    if (!$Info->grading_hash() && $Me != $User && !$Pset->gitless_grades) {
        $remarks[] = array(true, "No commit has been marked for grading.");
    } else if (!$Info->is_grading_commit() && $Info->grading_hash()) {
        $remarks[] = array(true, "This is not "
                           . "<a class=\"uu\" href=\"" . $Info->hoturl("pset", array("commit" => $Info->grading_hash())) . "\">the commit currently marked for grading</a>"
                           . " <span style=\"font-weight:normal\">(<a href=\"" . $Info->hoturl("diff", array("commit1" => $Info->grading_hash())) . "\">see diff</a>)</span>.");
    }
    if (!$Info->is_latest_commit()) {
        $remarks[] = array(true, "This is not "
                           . "<a class=\"uu\" href=\"" . $Info->hoturl("pset", array("commit" => $Info->latest_hash())) . "\">the latest commit</a>"
                           . " <span style=\"font-weight:normal\">(<a href=\"" . $Info->hoturl("diff", array("commit1" => $Info->latest_hash())) . "\">see diff</a>)</span>.");
    }
    $lhd = $Info->late_hours_data();
    if ($lhd && isset($lhd->hours) && $lhd->hours > 0) {
        $extra = array();
        if (isset($lhd->timestamp)) {
            $extra[] = "commit at " . $Conf->printableTimestamp($lhd->timestamp);
        }
        if (isset($lhd->deadline)) {
            $extra[] = "deadline " . $Conf->printableTimestamp($lhd->deadline);
        }
        $extra = count($extra) ? ' <span style="font-weight:normal">(' . join(", ", $extra) . ')</span>' : "";
        $remarks[] = array(true, "This commit uses " . plural($lhd->hours, "late hour") . $extra . ".");
    }
    if (($Info->is_latest_commit() || $Me->isPC)
        && $Pset->handout_repo_url) {
        $last_handout = $Pset->latest_handout_commit();
        $last_myhandout = $last_handout ? $Info->derived_handout_hash() : false;
        if ($last_handout
            && $last_myhandout
            && $last_handout->hash == $last_myhandout) {
            /* this is ideal: they have the latest handout commit */
        } else if ($last_handout && $last_myhandout) {
            $need_handout_hash = $Pset->handout_warn_hash ? : $Pset->handout_hash;
            if ($need_handout_hash
                && ($hcf = $Pset->handout_commits_from($need_handout_hash))
                && isset($hcf[$last_myhandout])) {
                // also fine
            } else {
                // they don't have the latest updates
                $cmd = "git pull handout";
                if ($Pset->handout_hash)
                    $cmd .= " " . htmlspecialchars($Pset->handout_hash);
                else if ($Pset->handout_branch)
                    $cmd .= " " . htmlspecialchars($Pset->handout_branch);
                else
                    $cmd .= " master";
                $remarks[] = array(true, "Updates are available for this problem set <span style=\"font-weight:normal\">(<a href=\"" . $Info->hoturl("diff", array("commit" => $last_myhandout, "commit1" => $need_handout_hash ? : $last_handout->hash)) . "\">see diff</a>)</span>. Run <code>" . $cmd . "</code> to merge these updates.");
            }
        } else if ($last_handout) {
            $remarks[] = [true, "Please create your repository by cloning our repository. Creating your repository from scratch makes it harder for you to get pset updates.<br>This <em>somewhat dangerous</em> command will merge your repository with ours; make a backup of your Git repository before trying it:<br><pre>git pull --allow-unrelated-histories \"" . htmlspecialchars($Pset->handout_repo_url) . "\" --no-edit &amp;&amp; git push</pre>"];
        } else if (!$last_handout && $Me->isPC) {
            $handout_files = $Pset->handout_repo()->ls_files("master");
            if (!count($handout_files))
                $remarks[] = array(true, "The handout repository, " . htmlspecialchars($Pset->handout_repo_url) . ", contains no files; perhaps handout_repo_url is misconfigured.");
            else
                $remarks[] = array(true, "The handout repository, " . htmlspecialchars($Pset->handout_repo_url) . ", does not contain problem set code yet.");
        }
    }

    // actually print
    echo Ht::form($Info->hoturl_post("pset", ["commit" => null, "setcommit" => 1]),
            ["class" => "pa-commitcontainer", "data-pa-pset" => $Info->pset->urlkey, "data-pa-commit" => $Info->latest_hash()]),
        "<div class=\"f-contain\">";
    ContactView::echo_group($key, $value, $remarks);
    echo "</div></form>\n";
}

function echo_grader() {
    global $Conf, $Me, $User, $Pset, $Info;
    $gradercid = $Info->gradercid();
    if ($Info->is_grading_commit() && $Me->can_view_grader($Pset, $User)) {
        $pcm = $Conf->pc_members_and_admins();
        $gpc = get($pcm, $gradercid);
        $value_post = "";
        if ($Me->can_set_grader($Pset, $User)) {
            $sel = array();
            if (!$gpc) {
                $sel["none"] = "(None)";
                $sel[] = null;
            }
            foreach ($Conf->pc_members_and_admins() as $pcm) {
                $sel[$pcm->email] = Text::name_html($pcm);
            }

            // if no current grader, highlight previous grader
            if (!$gpc) {
                $seen_pset = false;
                $older_pset = null;
                foreach ($Conf->psets_newest_first() as $xpset) {
                    if ($xpset === $Pset)
                        $seen_pset = true;
                    else if ($seen_pset && $xpset->group === $Pset->group) {
                        $xinfo = PsetView::make($xpset, $User, $Me);
                        if (($xcid = $xinfo->gradercid())
                            && ($pcm = get($Conf->pc_members_and_admins(), $xcid))) {
                            $sel[$pcm->email] .= " [✱" . htmlspecialchars($xpset->title) . "]";
                        }
                        break;
                    }
                }
            }

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
    global $Info;
    if ($Info->can_view_grade_statistics())
        echo_grade_cdf();
}

function echo_all_grades() {
    global $Me, $User, $Pset, $Info;
    if ($Info->is_handout_commit())
        return;

    $has_grades = $Info->has_assigned_grades();
    if ($Info->can_view_grades()
        && ($has_grades || $Info->can_edit_grades())) {
        if ($Pset->grade_script && $Info->can_edit_grades()) {
            foreach ($Pset->grade_script as $gs)
                Ht::stash_html($Info->conf->make_script_file($gs));
        }
        echo '<div class="pa-gradelist want-pa-landmark-links',
            ($Info->can_edit_grades() ? " editable" : " noneditable"),
            ($Info->user_can_view_grades() ? "" : " pa-pset-hidden"), '"></div>';
        Ht::stash_script('pa_loadgrades.call($(".pa-psetinfo")[0], ' . json_encode_browser($Info->grade_json()) . ')');
        if ($Pset->has_grade_landmark) {
            Ht::stash_script('$(function(){pa_loadgrades.call($(".pa-psetinfo")[0], true)})');
        }
        echo Ht::unstash();
    }

    $lhd = $Info->late_hours_data();
    if ($lhd && $Info->can_view_grades() && !$Info->can_edit_grades()) {
        if ((isset($lhd->hours) && $lhd->hours > 0) || $has_grades) {
            ContactView::echo_group("late hours", '<span class="pa-grade" data-pa-grade="late_hours">' . htmlspecialchars($lhd->hours) . '</span>');
        }
    } else if ($Info->can_edit_grades() && $Info->pset->late_hours_entry()) {
        echo '<form class="ui-submit pa-grade pa-p" data-pa-grade="late_hours">',
            '<label class="pa-pt" for="pa-lh">late hours</label>',
            '<div class="pa-pd">',
            Ht::entry("late_hours", $lhd && isset($lhd->hours) ? $lhd->hours : "",
                      ["class" => "uich pa-gradevalue"]);
        if ($lhd && isset($lhd->autohours) && $lhd->hours !== $lhd->autohours)
            echo '<span class="pa-gradediffers">auto-late hours is ', htmlspecialchars($lhd->autohours), '</span>';
        echo '</div></form>';
    }
}


function show_pset($info) {
    echo "<hr>\n";
    if ($info->can_edit_grades() && get($info->pset, "gitless_grades"))
        echo '<div style="float:right"><button type="button" onclick="jQuery(\'#upload\').show()">upload</button></div>';
    echo "<h2>", htmlspecialchars($info->pset->title), "</h2>";
    ContactView::echo_partner_group($info);
    ContactView::echo_repo_group($info, $info->can_edit_grades());
    ContactView::echo_repo_last_commit_group($info, false);
    ContactView::echo_downloads_group($info);
}

show_pset($Info);

if ($Info->can_edit_grades()) {
    echo '<div id="upload" style="display:none"><hr/>',
        Ht::form($Info->hoturl_post("pset", array("uploadgrades" => 1))),
        '<div class="f-contain">',
        '<input type="file" name="file">',
        Ht::submit("Upload"),
        '</div></form></div>';
}

echo "<hr>\n";
echo '<div class="pa-psetinfo" data-pa-pset="', htmlspecialchars($Info->pset->urlkey);
if (!$Pset->gitless && $Info->maybe_commit_hash()) {
    echo '" data-pa-hash="', htmlspecialchars($Info->commit_hash());
}
if (!$Pset->gitless && $Pset->directory) {
    echo '" data-pa-directory="', htmlspecialchars($Pset->directory_slash);
}
if ($Me->can_set_grades($Pset, $Info)) {
    echo '" data-pa-can-set-grades="yes';
}
if ($Info->user_can_view_grades()) {
    echo '" data-pa-user-can-view-grades="yes';
}
if ($Info->user->extension) {
    echo '" data-pa-user-extension="yes';
}
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
    foreach ($Pset->runners as $r) {
        if ($Me->can_view_run($Pset, $r, $User)) {
            if ($Me->can_run($Pset, $r, $User)) {
                $b = Ht::button(htmlspecialchars($r->title),
                                array("value" => $r->name,
                                      "class" => "btn pa-runner",
                                      "onclick" => "pa_run(this)",
                                      "data-pa-run-category" => $r->category_argument(),
                                      "data-pa-loadgrade" => isset($r->eval) ? "true" : null));
                $runnerbuttons[] = ($last_run ? " &nbsp;" : "") . $b;
                $last_run = true;
            } else {
                $runnerbuttons[] = '<input type="hidden" class="pa-runner" value="' . htmlspecialchars($r->name) . '">';
            }
        }
    }
    if (count($runnerbuttons) && $Me->isPC && $Me != $User && $last_run) {
        $runnerbuttons[] = " &nbsp;"
            . Ht::button("+",
                         array("class" => "btn pa-runner",
                               "style" => "font-weight:bold",
                               "name" => "define",
                               "onclick" => "pa_runsetting.add()"));
    }
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
                '<div class="f-contain"><div id="pa-runsettings"></div></div></form>', "\n";
            // XXX always using grading commit's settings?
            if (($runsettings = $Info->commit_info("runsettings")))
                echo '<script>pa_runsetting.load(', json_encode_browser($runsettings), ')</script>';
        }
        Ht::stash_script("jQuery('button.pa-runner').prop('disabled',false)");
    }

    // print current grader
    echo_grader();

    // print grade entries
    echo_all_grades();

    // collect diff and sort line notes
    $lnorder = $Info->viewable_line_notes();
    $diff = $Info->diff($Info->base_handout_commit(), $Info->commit(), $lnorder, ["wdiff" => $WDIFF]);

    // print line notes
    $notelinks = array();
    foreach ($lnorder->seq() as $fl) {
        $f = str_starts_with($fl[0], $Pset->directory_slash) ? substr($fl[0], strlen($Pset->directory_slash)) : $fl[0];
        $notelinks[] = '<a href="#L' . $fl[1] . '_' . html_id_encode($fl[0])
            . '" class="uix pa-goto pa-noteref'
            . (!$fl[2] && !$Info->user_can_view_grades() ? " pa-notehidden" : "")
            . '">' . htmlspecialchars($f) . ':' . substr($fl[1], 1) . '</a>';
    }
    if (!empty($notelinks)) {
        ContactView::echo_group("notes", join(", ", $notelinks));
    }

    // print runners
    if ($Info->is_handout_commit()) { // XXX this is a hack
        $crunners = [];
    } else {
        $crunners = $Info->commit_info("run");
    }
    $runcategories = [];
    foreach ($Pset->runners as $r) {
        if (!$Me->can_view_run($Pset, $r, $User)
            || isset($runcategories[$r->category])) {
            continue;
        }

        $rj = null;
        if (($checkt = get($crunners, $r->category))) {
            $rj = (new RunnerState($Info, $r, $checkt))->full_json();
        }
        if (!$rj && !$Me->can_run($Pset, $r, $User)) {
            continue;
        }

        $runcategories[$r->category] = true;
        echo '<div id="pa-runout-' . $r->category . '"';
        if (!$rj || !isset($rj->timestamp)) {
            echo ' class="hidden"';
        }
        echo '><h3><a class="fold61" href="#" onclick="',
            "return runfold61('{$r->category}')", '">',
            '<span class="foldarrow">&#x25B6;</span>',
            htmlspecialchars($r->output_title), '</a></h3>',
            '<div class="pa-run pa-run-short hidden" id="pa-run-', $r->category, '"';
        if ($r->xterm_js || ($r->xterm_js === null && $Pset->run_xterm_js)) {
            echo ' data-pa-xterm-js="true"';
        }
        if ($rj && isset($rj->timestamp)) {
            echo ' data-pa-timestamp="', $rj->timestamp, '"';
        }
        if ($rj && isset($rj->data) && ($pos = strpos($rj->data, "\n\n"))) {
            echo ' data-pa-content="', htmlspecialchars(substr($rj->data, $pos + 2)), '"';
        }
        echo '><pre class="pa-runpre"></pre></div></div>', "\n";
    }

    // line notes
    if (!empty($diff)) {
        echo "<hr>\n";
        foreach ($diff as $file => $dinfo) {
            $open = $lnorder->file_has_notes($file)
                || (!$dinfo->boring
                    && ($Me != $Info->user
                        || !$Info->can_view_grades()
                        || !$Info->is_grading_commit()
                        || !$lnorder->has_linenotes_in_diff));
            $Info->echo_file_diff($file, $dinfo, $lnorder, ["open" => $open]);
        }
    }

    Ht::stash_script('$(window).on("beforeunload",pa_beforeunload)');
} else {
    if ($Pset->gitless_grades)
        echo_grade_cdf_here();

    echo "<div class=\"pa-commitcontainer\" data-pa-pset=\"", htmlspecialchars($Info->pset->urlkey), "\">";
    ContactView::echo_group("this commit", "No commits yet for this problem set", array());
    echo "</div>\n";

    if ($Pset->gitless_grades) {
        echo_grader();
        echo_all_grades();
    }
}

echo "</div>\n";


if (!$Pset->gitless)
    Ht::stash_script("pa_checklatest()", "pa_checklatest");

echo "<div class='clear'></div>\n";
$Conf->footer();
