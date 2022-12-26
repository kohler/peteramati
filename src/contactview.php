<?php
// contactview.php -- HotCRP helper class for user related printouts
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

class ContactView {
    /** @param Qrequest $qreq
     * @param list<string> $paths
     * @param Conf $conf */
    static function set_path_request($qreq, $paths, $conf) {
        $path = Navigation::path();
        if ($path === "") {
            return;
        }
        $x = explode("/", $path);
        if (count($x) && $x[count($x) - 1] === "") {
            array_pop($x);
        }
        foreach ($x as &$xp) {
            $xp = urldecode($xp);
        }
        unset($xp);
        foreach ($paths as $p) {
            $ppos = $xpos = 0;
            $commitsuf = "";
            $settings = array();
            while ($ppos < strlen($p) && $xpos < count($x)) {
                if ($p[$ppos] === "/") {
                    ++$xpos;
                } else if ($p[$ppos] === "p"
                           && isset($x[$xpos])
                           && $conf->pset_by_key($x[$xpos])) {
                    $settings["pset"] = $x[$xpos];
                } else if ($p[$ppos] === "H"
                           // all special hashparts aren’t xdigit
                           && (strlen($x[$xpos]) === 40 || strlen($x[$xpos]) === 64 || !ctype_xdigit($x[$xpos]))
                           && ($hp = CommitRecord::canonicalize_hashpart($x[$xpos]))) {
                    $settings["commit" . $commitsuf] = $hp;
                    $commitsuf = (int) $commitsuf + 1;
                } else if ($p[$ppos] === "h"
                           && ($hp = CommitRecord::canonicalize_hashpart($x[$xpos]))) {
                    $settings["commit" . $commitsuf] = $hp;
                    $commitsuf = (int) $commitsuf + 1;
                } else if ($p[$ppos] === "u"
                           && strlen($x[$xpos])) {
                    if ($x[$xpos][0] !== "@" && $x[$xpos][0] !== "~") {
                        $settings["u"] = $x[$xpos];
                    } else if (strlen($x[$xpos]) > 1) {
                        $settings["u"] = substr($x[$xpos], 1);
                    }
                } else if ($p[$ppos] === "@"
                           && strlen($x[$xpos])
                           && ($x[$xpos][0] === "@" || $x[$xpos][0] === "~")) {
                    if (strlen($x[$xpos]) > 1) {
                        $settings["u"] = substr($x[$xpos], 1);
                    }
                } else if ($p[$ppos] === "f") {
                    $settings["file"] = join("/", array_slice($x, $xpos));
                    $xpos = count($x) - 1;
                } else if ($p[$ppos] === "*") {
                    $xpos = count($x) - 1;
                } else {
                    $settings = null;
                    break;
                }
                ++$ppos;
            }
            if ($settings && $xpos == count($x) - 1) {
                foreach ($settings as $k => $v) {
                    if (!isset($_GET[$k]) && !isset($_POST[$k]))
                        $_GET[$k] = $_REQUEST[$k] = $qreq[$k] = $v;
                }
                break;
            }
        }
    }

    /** @param string $u
     * @param Contact $viewer
     * @param bool $default_anonymous
     * @return ?Contact */
    static function find_user($u, $viewer, $default_anonymous = false) {
        $user = $viewer->conf->user_by_whatever($u);
        if (!$user) {
            return null;
        }
        if ($user && $user->contactId === $viewer->contactId) {
            return $viewer;
        }
        $user->set_anonymous(str_starts_with($u, "[anon") || $default_anonymous);
        return $user;
    }

    /** @param Qrequest $qreq
     * @param ?Pset $pset
     * @return ?Contact */
    static function prepare_user($qreq, $viewer, $pset = null) {
        $user = $viewer;
        if (isset($qreq->u) && $qreq->u) {
            $user = self::find_user($qreq->u, $viewer, $pset && $pset->anonymous);
            if (!$user) {
                $viewer->conf->errorMsg("No such user “" . htmlspecialchars($qreq->u) . "”.");
            } else if ($user->contactId !== $viewer->contactId && !$viewer->isPC) {
                $viewer->conf->errorMsg("You can’t see that user’s information.");
                $user = null;
            }
        }
        if ($user && $viewer->isPC) {
            if ($pset && $pset->anonymous) {
                $qreq->u = $user->anon_username;
            } else {
                $qreq->u = $user->username ? : $user->email;
            }
        }
        return $user;
    }

    /** @param Contact $viewer */
    static function find_pset_redirect($psetkey, $viewer) {
        global $Qreq;
        $pset = $viewer->conf->pset_by_key($psetkey);
        if ((!$pset || $pset->disabled)
            && ($psetkey !== null && $psetkey !== "" && $psetkey !== false)) {
            $viewer->conf->errorMsg("No such problem set “" . htmlspecialchars($psetkey) . "”.");
        }
        if (!$pset || !$viewer->can_view_pset($pset)) {
            foreach ($viewer->conf->psets() as $p) {
                if ($viewer->can_view_pset($p))
                    $viewer->conf->redirect_self($Qreq, ["pset" => $p->urlkey]);
            }
            $viewer->conf->redirect();
        }
        if ($pset) {
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $pset->urlkey = $psetkey;
        }
        return $pset;
    }

    /** @param Contact $user
     * @param Contact $viewer */
    static function echo_heading($user, $viewer) {
        $u = $viewer->user_linkpart($user);
        if ($user !== $viewer && !$user->is_anonymous && $user->contactImageId) {
            echo '<img class="pa-smallface float-left" src="' . $user->conf->hoturl("face", ["u" => $u, "imageid" => $user->contactImageId]) . '" />';
        }

        echo '<h2 class="homeemail"><a href="',
            $user->conf->hoturl("index", array("u" => $u)), '">', htmlspecialchars($u), '</a>';
        if ($user->extension)
            echo " (X)";
        /*if ($viewer->privChair && $user->is_anonymous)
            echo " ",*/
        if ($viewer->privChair)
            echo "&nbsp;", become_user_link($user);
        if ($viewer->isPC
            && !$user->is_anonymous
            && $user->github_username
            && $viewer->conf->opt("githubOrganization")) {
            echo ' <button type="button" class="btn-qlink small ui js-repo-list need-tooltip" data-tooltip="List repositories" data-pa-user="', htmlspecialchars($user->github_username), '">®</button>';
        }
        echo '</h2>';

        if ($user !== $viewer && !$user->is_anonymous) {
            echo '<h3>', Text::user_html($user);
            if ($user->studentYear) {
                if (strlen($user->studentYear) <= 2
                    && preg_match('/\A(?:[1-9]|1[0-9]|20)\z/', $user->studentYear))
                    echo ' &#', (9311 + $user->studentYear), ';';
                else if (strlen($user->studentYear) === 1
                         && $user->studentYear >= "A"
                         && $user->studentYear <= "Z")
                    echo ' &#', (9398 + ord($user->studentYear) - 65), ';';
                else
                    echo ' ', htmlspecialchars($user->studentYear);
            }
            echo '</h3>';
        }

        if ($user->dropped)
            ContactView::echo_group("", '<strong class="err">You have dropped the course.</strong> If this is incorrect, contact us.');

        echo '<hr class="c" />';
    }

    static function echo_group($key, $value, $notes = null, $id = null) {
        echo '<div class="pa-p">',
            $id ? Ht::label($key, $id, ["class" => "pa-pt"]) : "<div class=\"pa-pt\">{$key}</div>",
            '<div class="pa-pv">';
        if ($notes && $value && !str_starts_with($value, '<div')) {
            $value = "<div>" . $value . "</div>";
        }
        echo $value;
        foreach ($notes ? : [] as $v) {
            $v = is_array($v) ? $v : array(false, $v);
            echo "<div class=\"", ($v[0] ? "pa-inf-error" : "pa-inf"),
                "\">", $v[1], "</div>";
        }
        echo "</div></div>\n";
    }

    static function echo_deadline_group(PsetView $info) {
        if (($dl = $info->deadline()) && $dl > Conf::$now) {
            self::echo_group("deadline", $info->conf->unparse_time($dl));
        }
    }

    static function echo_partner_group(PsetView $info) {
        list($user, $pset, $partner) =
            array($info->user, $info->pset, $info->partner);
        if (!$pset->partner) {
            return;
        }

        // collect values
        $partner_email = "";
        if ($user->is_anonymous && $partner) {
            $partner_email = $partner->anon_username;
        } else if ($partner) {
            $partner_email = $partner->email;
        }
        $editable = $info->viewer->can_set_repo($pset, $user) && !$user->is_anonymous;

        $title = "partner";
        if ($info->viewer->isPC && $partner) {
            $title = '<a href="' . $info->conf->hoturl("pset", ["u" => $info->viewer->user_linkpart($partner), "pset" => $pset->id, "commit" => $info->hash()]) . '">' . $title . '</a>';
        }

        if ($editable) {
            $value = Ht::entry("partner", $partner_email, array("style" => "width:32em"))
                . " " . Ht::submit("Save");
        } else {
            $value = htmlspecialchars($partner_email ? : "(none)");
        }

        // check back-partner links
        $notes = array();
        $backpartners = $info->backpartners();
        if (count($backpartners) == 0 && $partner) {
            $notes[] = array(true, "ERROR: " . htmlspecialchars($partner_email) . " has not listed you as a partner yet.");
        } else if (count($backpartners) == 1 && $partner && $backpartners[0] == $partner->contactId) {
            if ($partner->dropped) {
                $notes[] = array(true, "ERROR: We believe your partner has dropped the course.");
            }
        } else if (count($backpartners) == 0 && !$partner) {
            if ($editable) {
                $notes[] = array(false, "Enter your partner’s email, username, or HUID here.");
            }
        } else {
            $backpartners[] = -1;
            $result = $info->conf->qe("select " . ($user->is_anonymous ? "anon_username" : "email") . " from ContactInfo where contactId ?a", $backpartners);
            $p = array();
            while (($row = $result->fetch_row())) {
                if ($info->viewer->isPC) {
                    $p[] = '<a href="' . $info->conf->hoturl("pset", array("pset" => $pset->urlkey, "u" => $row[0])) . '">' . htmlspecialchars($row[0]) . '</a>';
                } else {
                    $p[] = htmlspecialchars($row[0]);
                }
            }
            $notes[] = array(true, "ERROR: These users have listed you as a partner for this pset: " . commajoin($p));
        }

        if ($editable) {
            echo Ht::form($info->conf->selfurl(null, ["set_partner" => 1, "pset" => $pset->urlkey], Conf::HOTURL_POST)),
                "<div class='f-contain'>";
        }
        self::echo_group($title, $value, $notes);
        if ($editable) {
            echo "</div></form>";
        }
        echo "\n";
    }

    /** @param Contact $user
     * @param Contact $viewer
     * @param Qrequest $qreq */
    static function set_partner_action($user, $viewer, $qreq) {
        if (!($viewer->has_account_here()
              && $qreq->valid_post()
              && ($pset = $viewer->conf->pset_by_key($qreq->pset)))) {
            return;
        }
        if (!$viewer->can_set_repo($pset, $user)) {
            return $viewer->conf->errorMsg("You can’t edit repository information for that problem set now.");
        }
        if ($user->set_partner($pset, $_REQUEST["partner"])) {
            $viewer->conf->redirect_self($qreq);
        }
    }

    static function echo_repo_group(PsetView $info, $full = false) {
        global $Qreq;
        if ($info->pset->gitless) {
            return;
        }
        list($user, $pset, $partner, $repo) =
            array($info->user, $info->pset, $info->partner, $info->repo);
        $editable = $info->viewer->can_set_repo($pset, $user) && !$user->is_anonymous;

        $repo_url = $repo ? $repo->friendly_url() : "";
        $title = "repository";
        if (!RepositorySite::is_primary($repo)) {
            $title = $repo->reposite->friendly_siteclass() . " " . $title;
        }
        if ($repo && $repo->url) {
            $title = $user->link_repo($title, $repo->https_url());
        }
        $id = null;

        if ($editable) {
            $xvalue = $repo_url;
            $id = "repo-u{$user->contactId}p{$pset->id}";
            $js = ["style" => "width:32em", "id" => $id];
            $value = Ht::entry("repo", $xvalue, $js) . " " . Ht::submit("Save");
        } else if ($user->is_anonymous) {
            $value = $repo_url ? "[anonymous]" : "(none)";
        } else {
            $value = htmlspecialchars($repo_url ? $repo_url : "(none)");
        }
        if ($repo_url) {
            $value .= ' <button class="btn ui js-repo-copy need-tooltip" data-pa-repo="' . htmlspecialchars($repo->ssh_url()) . '"';
            if ($user->is_anonymous) {
                $value .= ' data-tooltip="[anonymous]"';
            } else {
                $value .= ' data-tooltip="' . htmlspecialchars($repo->ssh_url()) . '"';
            }
            $value .= ' type="button">Copy URL to clipboard</button>';
        }
        if ($repo && $info->viewer->privChair) {
            $value .= " <small style=\"padding-left:1em;font-size:60%\">group " . $repo->cacheid . ", repo" . $repo->repoid . "</small>";
        }

        // check repo
        $ms = new MessageSet;
        if ($repo) {
            $repo->check_working($user, $ms);
            $repo->check_open();
        }
        if ($partner && $info->partner_same() && !$pset->partner_repo) {
            $prepo = $partner->repo($pset->id);
            if ((!$repo && $prepo) || ($repo && !$prepo)
                || ($repo && $prepo && $repo->repoid != $prepo->repoid)) {
                if ($prepo && $repo) {
                    $prepo_url = ", " . htmlspecialchars($prepo->friendly_url_like($repo));
                } else if ($prepo) {
                    $prepo_url = ", " . htmlspecialchars($prepo->friendly_url());
                } else {
                    $prepo_url = "";
                }
                $your_partner = "your partner’s";
                if ($info->viewer->isPC) {
                    $your_partner = '<a href="' . $info->conf->hoturl("pset", array("pset" => $pset->urlkey, "u" => $info->viewer->user_linkpart($partner))) . '">' . $your_partner . '</a>';
                }
                $ms->error_at("partner", "This repository differs from $your_partner$prepo_url.");
            }
        }
        if ($repo) {
            $repo->check_ownership($user, $partner, $ms);
        }
        $prefixes = ["", "WARNING: ", "ERROR: "];
        $notes = array_map(function ($m) use ($prefixes) {
            return [$m->status > 0, $prefixes[max($m->status, 0)] . $m->message];
        }, $ms->message_list());
        if ($repo
            && $pset->handout_warn_merge !== false
            && $repo->truncated_psetdir($pset)) {
            $notes[] = [true, "Please create your repository by cloning our repository. Creating your repository from scratch makes it harder for us to grade and harder for you to get pset updates."];
        }
        if (!$repo) {
            $repoclasses = RepositorySite::site_classes($info->conf);
            $x = commajoin(array_map(function ($k) { return Ht::link($k::global_friendly_siteclass(), $k::global_friendly_siteurl()); }, $repoclasses), "or");
            if ($editable) {
                $notes[] = array(false, "Enter your $x repository URL here.");
            }
        }

        // edit
        if ($editable) {
            echo '<form data-pa-pset="', $pset->urlkey, '" class="ui-submit pa-setrepo">';
        }
        self::echo_group($title, $value, $notes, $id);
        if ($editable) {
            echo "</form>\n";
        }

        if (!$pset->no_branch) {
            self::echo_branch_group($info);
        }

        return $repo;
    }

    static function echo_branch_group(PsetView $info) {
        global $Qreq;
        list($user, $pset, $partner, $repo) =
            array($info->user, $info->pset, $info->partner, $info->repo);
        $editable = $info->viewer->can_set_repo($pset, $user) && !$user->is_anonymous;
        $branch = $user->branch($pset);
        $id = null;

        if ($editable) {
            $id = "branch-u{$user->contactId}p{$pset->id}";
            if ((!$repo && !$user->has_branch($pset))
                || $branch === $pset->main_branch) {
                $xvalue = null;
            } else {
                $xvalue = $branch;
            }
            $js = [
                "style" => "width:32em",
                "id" => $id,
                "placeholder" => $pset->main_branch
            ];
            $value = Ht::entry("branch", $xvalue, $js) . " " . Ht::submit("Save");
        } else if ($user->is_anonymous) {
            $value = $branch && $branch !== $pset->main_branch ? "[anonymous]" : $pset->main_branch;
        } else {
            $value = htmlspecialchars($branch);
        }

        // edit
        if ($editable) {
            echo '<form data-pa-pset="', $pset->urlkey, '" class="ui-submit pa-setbranch">';
        }
        self::echo_group("branch", $value, [], $id);
        if ($editable) {
            echo '</form>';
        }
    }

    static function echo_downloads_group(PsetView $info) {
        global $Qreq;
        $n = 0;
        foreach ($info->pset->downloads as $dl) {
            if ($info->user == $info->viewer
                && (!$dl->visible
                    || (is_int($dl->visible) && $dl->visible > Conf::$now))) {
                continue;
            }
            if (!$n)
                echo '<hr><h2>downloads</h2>';
            ++$n;

            $timer_start = 0;
            if ($dl->timed) {
                $all_dltimes = $info->user_jnote("downloaded_at");
                if ($all_dltimes && isset($all_dltimes->{$dl->key})) {
                    foreach ($all_dltimes->{$dl->key} as $dltime) {
                        if (!isset($dltime[1]) || $dltime[1] == $info->viewer->contactId) {
                            $timer_start = $timer_start ? min($timer_start, $dltime[0]) : $dltime[0];
                        }
                    }
                }
            }

            echo '<div class="pa-p', ($dl->visible ? "" : " pa-p-hidden");
            if ($timer_start) {
                echo ' pa-download-timed" data-pa-download-at="', htmlspecialchars($timer_start);
                if ($dl->timeout !== null) {
                    echo '" data-pa-download-expiry="', ($timer_start + $dl->timeout);
                }
                if ($info->repo
                    && !$info->pset->gitless
                    && ($h = $info->hash())
                    && ($ls = $info->connected_commit($h))) {
                    echo '" data-pa-commit-at="', $ls->commitat;
                }
            }
            echo '"><div class="pa-pt">', htmlspecialchars($dl->title), '</div><div class="pa-pv">';
            echo '<a href="', $info->conf->hoturl("pset", ["pset" => $info->pset->urlkey, "u" => $info->viewer->user_linkpart($info->user), "post" => post_value(), "download" => $dl->key]), '">', htmlspecialchars($dl->filename), '</a>';
            if ($timer_start)
                echo '<span class="pa-download-timer" style="padding-left:1em"></span>';
            echo '</span></div></div>';
        }
    }

    static function late_hour_note(PsetView $info) {
        if (($lh = $info->late_hours())
            && $lh > 0
            && (!$info->pset->obscure_late_hours || $info->can_view_some_grade())) {
            $t = plural($lh, "late hour") . " used";
            if (!$info->pset->obscure_late_hours) {
                $t = '<strong class="overdue">' . $t . '</strong>';
            }
            return $t;
        } else {
            return null;
        }
    }

    static private function unconfirmed_repository_note(PsetView $info) {
        if ($info->user->is_anonymous) {
            return [true, "ERROR: The user hasn’t confirmed that they can view this repository."];
        } else {
            $uname = Text::analyze_name($info->user);
            if ($uname->name && $uname->email) {
                $uname = "$uname->name <$uname->email>";
            } else if ($uname->email) {
                $uname = "Your Name <$uname->email>";
            } else if ($uname->name) {
                $uname = "$uname->name <youremail@example.com>";
            } else {
                $uname = "Your Name <youremail@example.com>";
            }
            $uname = addcslashes($uname, "\\\"\`\$!");
            return [true, "ERROR: We haven’t confirmed that you can view this repository.<br>
We only let you view repositories to which your course email address has committed.<br>
Fix this error by authoring a commit from " . htmlspecialchars($info->user->email) . " and pushing that commit to the repository.<br>
For example, try these commands: <pre>git commit --allow-empty --author=\"" . htmlspecialchars($uname) . "\" -m \"Confirm repository\"\ngit push</pre>"];
        }
    }

    static function echo_commit_groups(PsetView $info) {
        $user = $info->user;
        $repo = $info->repo;
        $pset = $info->pset;
        if ($pset->gitless) {
            return;
        }

        echo '<div class="pa-commitcontainer" data-pa-pset="', $info->pset->urlkey;
        if ($repo && $repo->snaphash && $info->can_view_repo_contents()) {
            echo '" data-pa-checkhash="', $repo->snaphash;
        }
        echo '">';
        $want_latest = false;
        $notes = [];

        if (!$repo) {
            $title = "latest commit";
            $value = "(no configured repository)";
        } else if (!$info->can_view_repo_contents()) {
            $title = $info->grading_hash() ? "grading commit" : "latest commit";
            $value = "(unconfirmed repository)";
            $notes[] = self::unconfirmed_repository_note($info);
        } else {
            $xnotes = [];
            if (($ghash = $info->grading_hash())) {
                $title = "grading commit";
                if (($c = $info->grading_commit())) {
                    $value = $info->commit_link(substr($ghash, 0, 7), htmlspecialchars(" {$c->subject}"), $ghash);
                    $xnotes[] = "committed " . ago($c->commitat);
                } else {
                    $value = "<code>" . substr($ghash, 0, 7) . "</code> (disconnected commit)";
                }
                $want_latest = !$info->is_lateish_commit();
            } else if (($c = $info->latest_nontrivial_commit())) {
                $title = "latest commit";
                $value = $info->commit_link(substr($c->hash, 0, 7), htmlspecialchars(" {$c->subject}"), $c->hash);
                $xnotes[] = "committed " . ago($c->commitat);
            } else {
                $title = "latest commit";
                if (!$repo->snapat) {
                    $value = "(checking)";
                } else if ($pset->directory_noslash !== ""
                           && $repo->latest_commit(null, $info->branch)) {
                    $value = "(no commits yet for this pset)";
                } else {
                    $value = "(no such branch)";
                }
            }
            if (($lh = self::late_hour_note($info))) {
                $xnotes[] = $lh;
            }
            //$xnotes[] = "fetched " . ago($repo->snapat);
            $xnotes[] = "last checked " . ago($repo->snapcheckat);
            $notes[] = join(", ", $xnotes);
            if ($info->user !== $info->viewer && !$info->user_can_view_repo_contents()) {
                $notes[] = self::unconfirmed_repository_note($info);
            }
        }

        self::echo_group($title, $value, $notes);

        if ($want_latest && ($c = $info->latest_nontrivial_commit())) {
            $value = $info->commit_link(substr($c->hash, 0, 7), " " . htmlspecialchars($c->subject), $c->hash);
            self::echo_group("latest commit", $value, ["committed " . ago($c->commitat)]);
        }

        echo '</div>';
    }

    static function pset_grade($notesj, $pset) {
        if (!$pset->grades()) {
            return null;
        }

        $total = $nonextra = 0;
        $r = [];
        $g = $notesj->grades ?? null;
        $ag = $notesj->autogrades ?? null;
        $rag = array();
        foreach ($pset->grades() as $ge) {
            $key = $ge->key;
            $gv = null;
            if ($ag && isset($ag->$key)) {
                $gv = $rag[$key] = $ag->$key;
            }
            if ($g && isset($g->$key)) {
                $gv = $g->$key;
            }
            if ($gv !== null) {
                $r[$key] = $gv;
                if (!$ge->no_total) {
                    $total += $gv;
                    if (!$ge->is_extra) {
                        $nonextra += $gv;
                    }
                }
            }
        }
        if (!empty($r)) {
            $r["total"] = $total;
            $r["total_noextra"] = $nonextra;
            if (!empty($rag)) {
                $r["autogrades"] = (object) $rag;
            }
            return (object) $r;
        } else {
            return null;
        }
    }

    static function error_exit($status, $title) {
        header("HTTP/1.0 $status");
        Conf::$main->header($title, "home");
        echo "<hr class=\"c\">\n";
        Conf::$main->footer();
        exit;
    }
}
