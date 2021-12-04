<?php
// pset.php -- Peteramati problem set page
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/p", "/p/h", "/p/u/h"));
if ($Me->is_empty()) {
    $Me->escape();
}
global $User, $Pset, $Info, $Qreq;

$User = $Me;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq->u))) {
    redirectSelf(["u" => null]);
}
assert($User === $Me || $Me->isPC);
$Conf->set_siteinfo("uservalue", $Me->user_linkpart($User));

$Pset = ContactView::find_pset_redirect($Qreq->pset);

// load user repo and current commit
$Info = PsetView::make($Pset, $User, $Me, $Qreq->newcommit ?? $Qreq->commit);
if (($Qreq->newcommit ?? $Qreq->commit) && !$Info->hash()) {
    if ($Info->repo) {
        $Conf->errorMsg("Commit " . htmlspecialchars($Qreq->newcommit ?? $Qreq->commit) . " isn’t connected to this repository.");
        $Info->set_grading_or_latest_nontrivial_commit(); // XXX
        unset($Qreq->newcommit, $Qreq->commit);
    } else {
        $Conf->errorMsg("No repository has been configured for this pset.");
        ContactView::error_exit("404 Not Found", htmlspecialchars($Pset->title));
    }
}
$Conf->set_active_list(SessionList::find($Me, $Qreq));

// maybe set commit
if (isset($Qreq->setgrader)
    && isset($Qreq->grader)
    && $Qreq->valid_post()
    && $Info->can_have_grades()
    && $Me->can_set_grader($Pset, $User)) {
    $grader = 0;
    foreach ($Conf->pc_members_and_admins() as $pcm) {
        if ($pcm->email === $_POST["grader"])
            $grader = $pcm->contactId;
    }
    if (!$grader && $_POST["grader"] !== "none") {
        json_exit(["ok" => false, "error" => "No such grader"]);
    }
    $Info->change_grader($grader);
    json_exit(["ok" => true, "grader_email" => $_POST["grader"]]);
}
if (isset($Qreq->setcommit)
    && isset($Qreq->grade)
    && check_post()
    && $Info->can_have_grades()
    && $Me->isPC
    && $Me != $User) {
    $Info->mark_grading_commit();
}
if (isset($Qreq->setcommit)) {
    Navigation::redirect($Info->hoturl("pset"));
}

// maybe set partner/repo
if ($Qreq->set_partner) {
    ContactView::set_partner_action($User, $Qreq);
}
if ($Qreq->set_repo) {
    ContactView::set_repo_action($User, $Qreq);
}
if ($Qreq->set_branch) {
    ContactView::set_branch_action($User, $Qreq);
}

// maybe download file
if ($Qreq->download
    && check_post()) {
    $dl = $Info->pset->downloads[$Qreq->download] ?? null;
    if (!$dl
        || ($Info->viewer === $Info->user
            && (!$dl->visible
                || (is_int($dl->visible) && $dl->visible > Conf::$now)))) {
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
        $dls = $Info->user_jnote("downloaded_at") ?? null;
        $old_dls = $dls ? $dls->{$dl->key} ?? [] : [];
        $old_dls[] = ($Info->viewer === $Info->user ? [Conf::$now] : [Conf::$now, $Info->viewer->contactId]);
        $Info->update_user_notes(["downloaded_at" => [$dl->key => $old_dls]]);
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
    if ($info->is_handout_commit()) {
        json_exit(["ok" => false, "error" => "This is a handout commit."]);
    }
    $grades = $maxgrades = [];
    foreach ($pset->grades() as $ge) {
        if (isset($values[$ge->key])
            && ($g = $ge->parse_value($values[$ge->key], !$isauto)) !== false) {
            if (isset($values["old;" . $ge->key])) {
                $old_grade = $info->grade_value($ge);
                if ($ge->value_differs($g, $old_grade)) {
                    json_exit(["ok" => false, "error" => "This grade has been updated—please reload."]);
                }
            }
            $grades[$ge->key] = $g;
        }
    }
    $updates = [];
    if (!empty($grades)) {
        $updates[$isauto ? "autogrades" : "grades"] = $grades;
    }
    if (isset($values["timestamp"]) && is_numeric($values["timestamp"])) {
        $timestamp = intval($values["timestamp"]);
        if ($timestamp >= 1400000000) {
            $updates["timestamp"] = $timestamp;
        } else if ($timestamp <= 0) {
            $updates["timestamp"] = null;
        }
    }
    if (!empty($updates)) {
        $info->update_grade_notes($updates);
    }
    return $grades;
}

function upload_grades($pset, $text, $fname) {
    global $Conf, $Me;
    assert($pset->gitless_grades);
    $csv = new CsvParser($text);
    $csv->set_header($csv->next_list());
    $errors = [];
    while (($line = $csv->next_row())) {
        if (($who = $line["email"]) && $who !== "-") {
            $user = $Conf->user_by_email($who);
        } else if (($who = $line["github_username"]) && $who !== "-") {
            $user = $Conf->user_by_whatever($who, Conf::USERNAME_GITHUB);
        } else if (($who = $line["seascode_username"]) && $who !== "-") {
            $user = $Conf->user_by_whatever($who, Conf::USERNAME_HARVARDSEAS);
        } else if (($who = $line["huid"]) && $who !== "-") {
            $user = $Conf->user_by_whatever($who, Conf::USERNAME_HUID);
        } else if (($who = $line["username"]) && $who !== "-") {
            $user = $Conf->user_by_whatever($who, Conf::USERNAME_USERNAME);
        } else if (($who = $line["name"])) {
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
    if (!empty($errors)) {
        $Conf->errorMsg(join("<br />\n", $errors));
    }
    return true;
}

if ($Me->isPC && check_post() && isset($Qreq->uploadgrades)
    && file_uploaded($_FILES["file"])) {
    if (($text = file_get_contents($_FILES["file"]["tmp_name"])) === false) {
    	$Conf->errorMsg("Internal error: cannot read file.");
    } else if (upload_grades($Pset, $text, $_FILES["file"]["name"])) {
        redirectSelf();
    }
}

// save tab width, wdiff
if (isset($Qreq->tab)
    && ctype_digit($Qreq->tab)
    && $Qreq->tab >= 1
    && $Qreq->tab <= 16) {
    $tab = (int) $Qreq->tab;
    $tab = $tab == 4 ? null : $tab;
    $Info->update_commit_notes(["tabwidth" => $tab]);
} else if (isset($Qreq->tab)
           && ($Qreq->tab === "" || $Qreq->tab === "none")) {
    $Info->update_commit_notes(["tabwidth" => null]);
}
if (isset($Qreq->wdiff)) {
    $wdiff = (int) $Qreq->wdiff;
    $Info->update_commit_notes(["wdiff" => $wdiff > 0 ? true : null]);
}

// save run settings
if ($Me->isPC && $Me != $User && isset($Qreq->saverunsettings)
    && check_post()) {
    $x = $Qreq->get_a("runsettings");
    if (empty($x)) {
        $x = null;
    }
    $Info->update_commit_notes(["runsettings" => $x]);
    if (isset($Qreq->ajax)) {
        json_exit(["ok" => true, "runsettings" => $x]);
    }
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

function session_list_link($where, PsetView $info, $isprev) {
    $x = [];
    if (preg_match('/\A~(.*?)\/pset\/(.*?)(\/[0-9a-f]+|)\z/', $where, $m)) {
        $p = $info->conf->pset_by_key($m[2]) ?? $info->pset;
        $t = htmlspecialchars(urldecode($m[1])) . " @" . htmlspecialchars($p->title);
        $x = ["pset" => $p->urlkey, "u" => urldecode($m[1])];
        if ($m[3] !== "") {
            $t .= substr($m[3], 0, 8);
            $x["commit"] = substr($m[3], 1);
        }
    } else if ($where[0] === "~" && strpos($where, "/") === false) {
        $where = urldecode(substr($where, 1));
        $t = htmlspecialchars($where);
        $x = ["pset" => $info->pset->urlkey, "u" => $where];
    } else {
        return "";
    }
    return '<a href="' . $info->conf->hoturl("pset", $x) . '" class="track">'
        . ($isprev ? "« " : "") . $t . ($isprev ? "" : " »") . '</a>';
}

function echo_session_list_links(PsetView $info) {
    if (($sl = $info->conf->active_list())) {
        echo '<div class="pa-list-links">';
        if ($sl->prev ?? null) {
            echo session_list_link($sl->prev, $info, true);
        }
        if (($sl->prev ?? null) && ($sl->next ?? null)) {
            echo ' · ';
        }
        if ($sl->next ?? null) {
            echo session_list_link($sl->next, $info, false);
        }
        echo '</div>';
    }
}

if ($Me->isPC) {
    echo_session_list_links($Info);
}

ContactView::echo_heading($User);
$u = $Me->user_linkpart($User);


// Per-pset

/** @param PsetView $info */
function echo_grade_cdf($info) {
    global $Qreq;
    echo '<div id="pa-grade-statistics" class="pa-grgraph pa-grade-statistics hidden';
    if (!$info->user_can_view_grade_statistics()) {
        echo ' pa-pset-hidden';
    }
    echo '" data-pa-pset="', $info->pset->urlkey;
    if (is_string($Qreq->gg)) {
        echo '" data-pa-gg-type="', htmlspecialchars($Qreq->gg);
    }
    echo '"><a class="qq ui js-grgraph-flip prev" href="">&lt;</a>';
    echo '<a class="qq ui js-grgraph-flip next" href="">&gt;</a>';
    echo '<h4 class="title pa-grgraph-type"></h4>';
    if ($info->can_view_grade_statistics_graph()) {
        echo '<div class="plot" style="width:350px;height:200px"></div>';
    }
    echo '<div class="statistics"></div></div>';
    Ht::stash_script("\$(\"#pa-grade-statistics\").each(\$pa.grgraph)");
}

/** @param PsetView $info
 * @param Qrequest $qreq */
function echo_commit($info, $qreq) {
    $conf = $info->conf;
    $pset = $info->pset;
    $Notes = $info->commit_jnotes();
    $TABWIDTH = $Notes->tabwidth ?? $pset->baseline_diffconfig(".*")->tabwidth ?? 4;
    $WDIFF = $Notes->wdiff ?? false;
    $info->update_placeholder(null);

    // current commit and commit selector
    $sel = $bhashes = [];
    $curhead = $grouphead = null;
    foreach ($info->recent_commits() as $k) {
        // visually separate older heads
        if ($curhead === null) {
            $curhead = $k->fromhead;
        }
        if ($curhead !== $k->fromhead && !$pset->is_handout($k)) {
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
        if (strlen($x) !== strlen($k->subject)) {
            $x .= "...";
        }
        $sel[$k->hash] = substr($k->hash, 0, 7) . " " . htmlspecialchars($x);
        $bhashes[] = hex2bin($k->hash);
    }
    $notesflag = HASNOTES_ANY;
    if (!$info->pc_view && !$info->can_view_score()) {
        $notesflag = HASNOTES_COMMENT;
    }
    $result = $info->conf->qe("select bhash, haslinenotes, hasflags, hasactiveflags
        from CommitNotes where pset=? and bhash?a and (haslinenotes or hasflags)",
        $pset->psetid, $bhashes);
    while (($row = $result->fetch_row())) {
        $hex = bin2hex($row[0]);
        $f = "";
        if (((int) $row[1]) & $notesflag) {
            $f .= "♪";
        }
        if ($row[3]) {
            $f .= "⚑";
        } else if ($row[2]) {
            $f .= "⚐";
        }
        if ($f !== "") {
            $sel[bin2hex($row[0])] .= "  $f";
        }
    }
    Dbl::free($result);

    if (!empty($sel)
        && ($h = $info->grading_hash())
        && isset($sel[$h])) {
        $sel[$h] = preg_replace('/\A(.*?)(?:  |)((?:|♪)(?:|⚑|⚐))\z/', '$1 &nbsp;✱$2', $sel[$h]);
    }

    if ($info->is_grading_commit()) {
        $key = "grading commit";
    } else {
        $key = "this commit";
    }
    $value = Ht::select("newcommit", $sel, $info->commit_hash(), ["class" => "uich js-pset-setcommit"]);
    if ($info->pc_view) {
        $x = $info->is_grading_commit() ? "" : "font-weight:bold";
        $value .= " " . Ht::submit("grade", "Grade", array("style" => $x));
    }

    // view options
    $fold_viewoptions = !isset($qreq->tab) && !isset($qreq->wdiff);
    $value .= '<div class="pa-viewoptions">'
        . '<a class="q ui js-pset-viewoptions" href="">'
        . '<span class="foldarrow">'
        . ($fold_viewoptions ? '&#x25B6;' : '&#x25BC;')
        . '</span>&nbsp;options</a><span style="padding-left:1em"'
        . ($fold_viewoptions ? ' class="hidden"' : '') . '>tab width:';
    foreach ([2, 4, 8] as $i) {
        $value .= '&nbsp;<a href="' . $info->conf->selfurl($qreq, ["tab" => $i]) . '"'
            . ($TABWIDTH == $i ? " class=\"q\"><strong>$i</strong>" : '>' . $i)
            . '</a>';
    }
    $value .= '<span style="padding-left:1em">wdiff:';
    foreach (["no", "yes"] as $i => $t) {
        $value .= '&nbsp;<a href="' . $info->conf->selfurl($qreq, ["wdiff" => $i]) . '"'
            . (!$WDIFF == !$i ? " class=\"q\"><strong>$t</strong>" : '>' . $t)
            . '</a>';
    }
    $value .= '</span></span></div>';

    // warnings
    $remarks = [];
    if (!$pset->gitless_grades) {
        $gc = $info->grading_commit();
        if ($info->pc_view && !$gc) {
            $remarks[] = [true, "No commit has been marked for grading."];
        } else if ($gc && $gc->hash !== $info->commit_hash()) {
            $tc = $info->commit();
            $args = $tc->commitat > $gc->commitat
                ? ["commit" => $gc->hash, "commit1" => $tc->hash]
                : ["commit" => $tc->hash, "commit1" => $gc->hash];
            $remarks[] = [true, "This is not "
                . "<a class=\"uu\" href=\"" . $info->hoturl("pset", ["commit" => $gc->hash])
                . "\">the commit currently marked for grading</a>"
                . " <span class=\"n\">(<a href=\"" . $info->hoturl("diff", $args)
                . "\">see diff</a>)</span>."
            ];
        }
    }
    if (!$info->is_lateish_commit()) {
        $remarks[] = [true, "This is not "
                      . "<a class=\"uu\" href=\"" . $info->hoturl("pset", ["commit" => $info->latest_hash()]) . "\">the latest commit</a>"
                      . " <span style=\"font-weight:normal\">(<a href=\"" . $info->hoturl("diff", ["commit1" => $info->latest_hash()]) . "\">see diff</a>)</span>."];
    }
    $lhd = $info->late_hours_data();
    if ($lhd
        && isset($lhd->hours)
        && $lhd->hours > 0
        && ($info->viewer->isPC || !$pset->obscure_late_hours)) {
        $extra = array();
        if (isset($lhd->timestamp)) {
            $extra[] = "commit at " . $info->conf->unparse_time($lhd->timestamp);
        }
        if (isset($lhd->deadline)) {
            $extra[] = "deadline " . $info->conf->unparse_time($lhd->deadline);
        }
        $extra = count($extra) ? ' <span style="font-weight:normal">(' . join(", ", $extra) . ')</span>' : "";
        $remarks[] = [!$pset->obscure_late_hours, "This commit uses " . plural($lhd->hours, "late hour") . $extra . "."];
    }
    if (($info->is_lateish_commit() || $info->viewer->isPC)
        && $pset->handout_repo_url) {
        $last_handout = $pset->latest_handout_commit();
        $last_myhandout = $last_handout ? $info->derived_handout_hash() : null;
        if ($last_handout
            && $last_myhandout
            && $last_handout->hash == $last_myhandout) {
            /* this is ideal: they have the latest handout commit */
        } else if ($last_handout && $last_myhandout) {
            $need_handout_hash = $pset->handout_warn_hash ? : $pset->handout_hash;
            if ($need_handout_hash
                && ($hcf = $pset->handout_commits_from($need_handout_hash))
                && isset($hcf[$last_myhandout])) {
                // also fine
            } else {
                // they don't have the latest updates
                $cmd = "git pull handout";
                if ($pset->handout_hash) {
                    $cmd .= " " . htmlspecialchars($pset->handout_hash);
                } else {
                    $cmd .= " " . htmlspecialchars($pset->handout_branch);
                }
                $remarks[] = [true, "Updates are available for this problem set <span style=\"font-weight:normal\">(<a href=\"" . $info->hoturl("diff", array("commit" => $last_myhandout, "commit1" => $need_handout_hash ? : $last_handout->hash)) . "\">see diff</a>)</span>. Run <code>" . $cmd . "</code> to merge these updates."];
            }
        } else if ($last_handout && $pset->handout_warn_merge !== false) {
            $remarks[] = [true, "Please create your repository by cloning our repository. Creating your repository from scratch makes it harder for you to get pset updates.<br>This <em>somewhat dangerous</em> command will merge your repository with ours; back up your Git repository before trying it:<br><pre>git pull --allow-unrelated-histories \"" . htmlspecialchars($pset->handout_repo_url) . "\" --no-edit &amp;&amp; git push</pre>"];
        } else if (!$last_handout && $info->viewer->isPC) {
            $handout_files = $pset->handout_repo()->ls_files($pset->handout_branch);
            if (!count($handout_files)) {
                $remarks[] = [true, "The handout repository, " . htmlspecialchars($pset->handout_repo_url) . ", contains no files; perhaps handout_repo_url is misconfigured."];
            } else {
                $remarks[] = [true, "The handout repository, " . htmlspecialchars($pset->handout_repo_url) . ", does not contain problem set code yet."];
            }
        }
    }

    $xnotes = [];
    if (($c = $info->commit())) {
        $xnotes[] = "committed " . ago($c->commitat);
    }
    //$xnotes[] = "fetched " . ago($info->repo->snapat);
    $xnotes[] = "last checked " . ago($info->repo->snapcheckat);
    $remarks[] = join(", ", $xnotes);

    // actually print
    echo Ht::form($info->hoturl_post("pset", ["commit" => null, "setcommit" => 1]),
            ["class" => "pa-commitcontainer", "data-pa-pset" => $info->pset->urlkey, "data-pa-checkhash" => $info->latest_hash()]),
        "<div class=\"f-contain\">";
    ContactView::echo_group($key, $value, $remarks);
    echo "</div></form>\n";
}

/** @param PsetView $info */
function echo_grader($info) {
    $gradercid = $info->gradercid();
    if ($info->is_grading_commit()
        && $info->viewer->can_view_grader($info->pset, $info->user)) {
        $pcm = $info->conf->pc_members_and_admins();
        $gpc = $pcm[$gradercid] ?? null;
        $value_post = "";
        if ($info->viewer->can_set_grader($info->pset, $info->user)) {
            $sel = array();
            if (!$gpc) {
                $sel["none"] = "(None)";
                $sel[] = null;
            }
            foreach ($info->conf->pc_members_and_admins() as $pcm) {
                $sel[$pcm->email] = Text::name_html($pcm);
            }

            // if no current grader, highlight previous grader
            if (!$gpc) {
                $seen_pset = false;
                $older_pset = null;
                foreach ($info->conf->psets_newest_first() as $xpset) {
                    if ($xpset === $info->pset) {
                        $seen_pset = true;
                    } else if ($seen_pset && $xpset->category === $info->pset->category) {
                        $xinfo = PsetView::make($xpset, $info->user, $info->viewer);
                        if (($xcid = $xinfo->gradercid())
                            && ($pcm = ($info->conf->pc_members_and_admins())[$xcid] ?? null)) {
                            $sel[$pcm->email] .= " [✱" . htmlspecialchars($xpset->title) . "]";
                        }
                        break;
                    }
                }
            }

            $value = Ht::form($info->hoturl_post("pset", array("setgrader" => 1)))
                . "<div>" . Ht::select("grader", $sel, $gpc ? $gpc->email : "none", ["class" => "uich js-pset-setgrader"]);
            $value_post = "<span class=\"ajaxsave61\"></span></div></form>";
        } else {
            if (isset($pcm[$gradercid])) {
                $value = Text::name_html($pcm[$gradercid]);
            } else {
                $value = "???";
            }
        }
        if ($info->viewer->privChair) {
            $value .= "&nbsp;" . become_user_link($gpc);
        }
        ContactView::echo_group("grader", $value . $value_post);
    }
}

/** @param PsetView $info */
function echo_grade_cdf_here($info) {
    if ($info->can_view_grade_statistics()) {
        echo_grade_cdf($info);
    }
}

/** @param PsetView $info */
function echo_all_grades($info) {
    if ($info->is_handout_commit()) {
        return;
    }

    $has_grades = $info->can_view_nonempty_grade();
    if ($has_grades || $info->can_edit_grade()) {
        if ($info->pset->grade_script && $info->can_edit_grade()) {
            foreach ($info->pset->grade_script as $gs) {
                Ht::stash_html($info->conf->make_script_file($gs));
            }
        }
        echo '<div class="pa-gradelist is-main want-pa-landmark-links',
            ($info->user_can_view_score() ? "" : " pa-pset-hidden"), '"></div>';
        Ht::stash_script('$pa.store_gradeinfo($(".pa-psetinfo")[0],' . json_encode_browser($info->grade_json()) . ');');
        if ($info->pset->has_grade_landmark) {
            Ht::stash_script('$(function(){$(".pa-psetinfo").each($pa.loadgrades)})');
        }
        echo Ht::unstash();
    }

    $lhd = $info->late_hours_data();
    if ($lhd && $info->can_view_grade() && !$info->can_edit_scores()) {
        if (($has_grades
             && $info->can_view_nonempty_score())
            || (isset($lhd->hours)
                && $lhd->hours > 0
                && !$info->pset->obscure_late_hours)) {
            echo '<div class="pa-grade pa-p" data-pa-grade="late_hours">',
                '<label class="pa-pt" for="late_hours">late hours</label>',
                '<div class="pa-pv pa-gradevalue" id="late_hours">', $lhd->hours ?? 0, '</div>',
                '</div>';
        }
    } else if ($info->can_edit_scores() && $info->pset->late_hours_entry()) {
        echo '<div class="pa-grade pa-p e" data-pa-grade="late_hours">',
            '<label class="pa-pt" for="late_hours">late hours</label>',
            '<form class="ui-submit pa-pv"><span class="pa-gradewidth">',
            Ht::entry("late_hours", $lhd && isset($lhd->hours) ? $lhd->hours : "",
                      ["class" => "uich pa-gradevalue pa-gradewidth", "id" => "late_hours"]),
            '</span> <span class="pa-gradedesc"></span>';
        if ($lhd && isset($lhd->autohours) && $lhd->hours !== $lhd->autohours) {
            echo '<span class="pa-gradediffers">auto-late hours is ', $lhd->autohours, '</span>';
        }
        echo '</form></div>';
    }
}


/** @param PsetView $info */
function show_pset($info) {
    echo "<hr>\n";
    if ($info->pset->gitless_grades && $info->can_edit_scores()) {
        echo '<div style="float:right"><button type="button" class="ui js-pset-upload-grades">upload</button></div>';
    }
    echo "<h2>", htmlspecialchars($info->pset->title), "</h2>";
    ContactView::echo_partner_group($info);
    ContactView::echo_repo_group($info, $info->can_edit_grade());
    ContactView::echo_downloads_group($info);
}

show_pset($Info);

if ($Info->can_edit_scores()) {
    echo '<div id="upload" class="hidden"><hr/>',
        Ht::form($Info->hoturl_post("pset", ["uploadgrades" => 1])),
        '<div class="f-contain">',
        '<input type="file" name="file">',
        Ht::submit("Upload"),
        '</div></form></div>';
}

echo "<hr>\n";
echo '<div class="pa-psetinfo" data-pa-pset="', htmlspecialchars($Info->pset->urlkey);
if (!$Pset->gitless && $Info->hash()) {
    echo '" data-pa-repourl="', htmlspecialchars($Info->repo->url),
        '" data-pa-branch="', htmlspecialchars($Info->branch()),
        '" data-pa-hash="', htmlspecialchars($Info->commit_hash());
}
if (!$Pset->gitless && $Pset->directory) {
    echo '" data-pa-directory="', htmlspecialchars($Pset->directory_slash);
}
if ($Info->user->extension) {
    echo '" data-pa-user-extension="yes';
}
echo '">';

if ($Pset->gitless) {
    echo_grade_cdf_here($Info);
    echo_grader($Info);
    echo_all_grades($Info);

} else if ($Info->repo && !$Info->can_view_repo_contents()) {
    echo_grade_cdf_here($Info);
    ContactView::echo_commit_groups($Info);
    echo_grader($Info);
    echo_all_grades($Info);

} else if ($Info->repo && $Info->recent_commits()) {
    echo_grade_cdf_here($Info);
    echo_commit($Info, $Qreq);

    // print runners
    $runnerbuttons = array();
    $last_run = false;
    foreach ($Pset->runners as $r) {
        if ($Me->can_view_run($Pset, $r, $User)) {
            if ($Me->can_run($Pset, $r, $User)) {
                $b = Ht::button(htmlspecialchars($r->title), [
                    "value" => $r->name,
                    "class" => "btn ui pa-runner",
                    "data-pa-run-grade" => isset($r->eval) ? "true" : null
                ]);
                $runnerbuttons[] = ($last_run ? " &nbsp;" : "") . $b;
                $last_run = true;
            } else {
                $runnerbuttons[] = '<input type="hidden" class="pa-runner" value="' . htmlspecialchars($r->name) . '">';
            }
        }
    }
    if (count($runnerbuttons) && $Me->isPC && $Me != $User && $last_run) {
        $runnerbuttons[] = " &nbsp;"
            . Ht::button("+", ["class" => "btn ui pa-runconfig ui font-weight-bold",
                               "name" => "define"]);
    }
    if ((($Me->isPC && $Me != $User) || $Me == $User)
        && !$Info->is_handout_commit()) {
        $runnerbuttons[] = '<div class="g"></div>';
        $all_resolved = true;
        foreach ($Info->commit_jnote("flags") ?? [] as $k => $v) {
            $resolved = $v->resolved ?? false;
            $all_resolved = $all_resolved && $resolved;
            $conversation = "";
            if ($v->conversation ?? false) {
                $conversation = htmlspecialchars((string) $v->conversation[0][2]);
            }
            if ($resolved && $conversation === "") {
                continue;
            }
            $x = $resolved ? "Resolved" : "<strong>Flagged</strong>";
            if ($conversation !== "") {
                $x .= " (" . $conversation . ")";
            }
            if (!$resolved) {
                $x .= '<span style="display:inline-block;margin-left:1em">'
                    . Ht::button("Resolve", ["name" => "resolveflag", "class" => "ui js-pset-flag", "data-flagid" => $k])
                    . '</span>';
            }
            $runnerbuttons[] = $x . "<br />";
        }
        if ($all_resolved) {
            $runnerbuttons[] = Ht::button("Flag this commit", ["style" => "font-weight:bold;font-size:100%;background:#ffeeee", "class" => "ui js-pset-flag", "name" => "flag"]);
        }
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
            if (($runsettings = $Info->commit_jnote("runsettings"))) {
                echo '<script>$pa.load_runsettings(', json_encode_browser($runsettings), ')</script>';
            }
        }
        Ht::stash_script("\$('button.pa-runner').prop('disabled',false)");
    }

    // print current grader
    echo_grader($Info);

    // print grade entries
    echo_all_grades($Info);

    // collect diff and sort line notes
    $lnorder = $Info->visible_line_notes();
    if ($Info->commit()) {
        $diff = $Info->diff($Info->base_handout_commit(), $Info->commit(),
            $lnorder, ["wdiff" => !!$Info->commit_jnote("wdiff")]);
    } else {
        $diff = [];
    }

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

    // print runners
    if ($Info->is_handout_commit()) { // XXX this is a hack
        $crunners = $runlogger = null;
    } else {
        $crunners = $Info->commit_jnote("run");
        $runlogger = new RunLogger($Pset, $Info->repo);
    }
    $runcategories = [];
    $any_runners = false;
    foreach ($Pset->runners as $r) {
        if (!$Me->can_view_run($Pset, $r, $User)
            || isset($runcategories[$r->name])) {
            continue;
        }

        $rj = null;
        if ($crunners !== null
            && ($checkt = $crunners->{$r->name} ?? null)
            && (is_int($checkt) || is_array($checkt))) {
            $rj = $runlogger->job_full_response(is_int($checkt) ? $checkt : $checkt[0], $r);
        }
        if (!$rj && !$Me->can_run($Pset, $r, $User)) {
            continue;
        }
        if (!$any_runners) {
            echo '<div class="pa-runoutlist">';
            $any_runners = true;
        }

        $runcategories[$r->name] = true;
        echo '<div id="run-', $r->name, '" class="pa-runout';
        if (!$rj || !isset($rj->timestamp)) {
            echo ' hidden';
        }
        echo '"><h3><a class="qq ui pa-run-show" href="">',
            '<span class="foldarrow">&#x25B6;</span>',
            htmlspecialchars($r->output_title), '</a></h3>',
            '<div class="pa-run pa-run-short hidden" id="pa-run-', $r->name, '"';
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
    if ($any_runners) {
        echo '</div>';
    }

    // line notes
    if (!empty($diff)) {
        echo "<hr>\n";
        echo '<div class="pa-diffset">';
        if ($Info->can_edit_scores() && !$Pset->has_grade_landmark_range) {
            PsetView::echo_pa_sidebar_gradelist();
        }
        foreach ($diff as $file => $dinfo) {
            $Info->echo_file_diff($file, $dinfo, $lnorder, ["hide_left" => $Info->can_edit_scores()]);
        }
        if ($Info->can_edit_scores() && !$Pset->has_grade_landmark_range) {
            PsetView::echo_close_pa_sidebar_gradelist();
        }
        echo '</div>';
    }

    Ht::stash_script('$(window).on("beforeunload",$pa.beforeunload)');
} else {
    if ($Pset->gitless_grades) {
        echo_grade_cdf_here($Info);
    }
    ContactView::echo_commit_groups($Info);
    if ($Pset->gitless_grades) {
        echo_grader($Info);
        echo_all_grades($Info);
    }
}

echo "</div>\n";


if (!$Pset->gitless) {
    Ht::stash_script("\$pa.checklatest(\"{$Pset->urlkey}\")", "pa_checklatest");
}

echo "<div class='clear'></div>\n";
$Conf->footer();
