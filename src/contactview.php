<?php
// contactview.php -- HotCRP helper class for user related printouts
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler
// Distributed under an MIT-like license; see LICENSE

class ContactView {

    static private $_clipboard = false;
    static private $_reverse_pset_compare = false;

    static function set_path_request($paths) {
        $path = Navigation::path();
        if ($path === "")
            return;
        $x = explode("/", $path);
        if (count($x) && $x[count($x) - 1] == "")
            array_pop($x);
        foreach ($paths as $p) {
            $ppos = $xpos = 0;
            $commitsuf = "";
            $settings = array();
            while ($ppos < strlen($p) && $xpos < count($x)) {
                if ($p[$ppos] == "/")
                    ++$xpos;
                else if ($p[$ppos] == "p" && Pset::find(@$x[$xpos]))
                    $settings["pset"] = $x[$xpos];
                else if ($p[$ppos] == "H" && strlen($x[$xpos]) == 40
                         && ctype_xdigit($x[$xpos])) {
                    $settings["commit" . $commitsuf] = $x[$xpos];
                    $commitsuf = (int) $commitsuf + 1;
                } else if ($p[$ppos] == "h" && strlen($x[$xpos]) >= 6
                           && ctype_xdigit($x[$xpos])) {
                    $settings["commit" . $commitsuf] = $x[$xpos];
                    $commitsuf = (int) $commitsuf + 1;
                } else if ($p[$ppos] == "u" && strlen($x[$xpos])) {
                    if ($x[$xpos][0] != "@" && $x[$xpos][0] != "~")
                        $settings["u"] = $x[$xpos];
                    else if (strlen($x[$xpos]) > 1)
                        $settings["u"] = substr($x[$xpos], 1);
                } else if ($p[$ppos] == "@" && strlen($x[$xpos])
                           && ($x[$xpos][0] == "@" || $x[$xpos][0] == "~")) {
                    if (strlen($x[$xpos]) > 1)
                        $settings["u"] = substr($x[$xpos], 1);
                } else if ($p[$ppos] == "f") {
                    $settings["file"] = join("/", array_slice($x, $xpos));
                    $xpos = count($x) - 1;
                } else if ($p[$ppos] == "*")
                    $xpos = count($x) - 1;
                else {
                    $settings = null;
                    break;
                }
                ++$ppos;
            }
            if ($settings && $xpos == count($x) - 1) {
                foreach ($settings as $k => $v)
                    if (!isset($_GET[$k]) && !isset($_POST[$k]))
                        $_GET[$k] = $_REQUEST[$k] = $v;
                break;
            }
        }
    }

    static function prepare_user(&$usertext, $pset = null) {
        global $Conf, $Me;
        $user = $Me;
        if (isset($usertext) && $usertext) {
            $user = Contact::find_by_whatever($usertext);
            if (!$user)
                $Conf->errorMsg("No such user “" . htmlspecialchars($usertext) . "”.");
            else if ($user->contactId == $Me->contactId)
                $user = $Me;
            else if (!$Me->isPC) {
                $Conf->errorMsg("You can’t see that user’s information.");
                $user = null;
            } else
                $user->is_anonymous = (substr($usertext, 0, 5) === "[anon")
                    || ($pset && $pset->anonymous);
        }
        if ($user && ($Me->isPC || $Me->chairContact)) {
            if ($pset && $pset->anonymous)
                $usertext = $user->anon_username;
            else
                $usertext = $user->seascode_username ? : $user->email;
        }
        return $user;
    }

    static function find_pset_redirect($psetkey) {
        global $Conf;
        $pset = Pset::find($psetkey);
        if ((!$pset || $pset->disabled)
            && ($psetkey !== null && $psetkey !== "" && $psetkey !== false))
            $Conf->errorMsg("No such problem set “" . htmlspecialchars($psetkey) . "”.");
        if (!$pset || $pset->disabled) {
            foreach (Pset::$all as $p)
                if (!$p->disabled)
                    redirectSelf(array("pset" => $p->urlkey));
            go("index");
        }
        if ($pset)
            $pset->urlkey = $psetkey;
        return $pset;
    }

    static function pset_compare($a, $b) {
        $adl = defval($a, "deadline_college", defval($a, "deadline", 0));
        $bdl = defval($b, "deadline_college", defval($b, "deadline", 0));
        if (!$adl != !$bdl)
            return $adl ? 1 : -1;
        if ($adl != $bdl)
            return ($adl < $bdl) == self::$_reverse_pset_compare ? 1 : -1;
        if (($cmp = strcasecmp(defval($a, "title", $a->id),
                               defval($b, "title", $b->id))) != 0)
            return $cmp;
        return $a->id < $b->id ? -1 : ($a->id == $b->id ? 0 : 1);
    }

    static function pset_list($contact, $reverse) {
        $psets = array();
        $istf = $contact && ($contact === true || $contact->isPC);
        $ischair = $contact instanceof Contact && $contact->privChair;
        foreach (Pset::$all as $pset)
            if (Contact::student_can_see_pset($pset)
                || (!$pset->disabled && $pset->gitless && $istf)
                || $ischair)
                $psets[$pset->id] = $pset;
        self::$_reverse_pset_compare = !!$reverse;
        uasort($psets, "ContactView::pset_compare");
        return $psets;
    }

    static function user_pset_info($user, $pset) {
        return new PsetView($pset, $user);
    }

    static function add_regrades($info) {
        list($user, $repo) = array($info->user, $info->repo);
        if (!isset($info->regrades) && !$info->pset->gitless) {
            $info->regrades = array();
            $result = Dbl::qe("select * from RepositoryGradeRequest where repoid=? and pset=? order by requested_at desc", $repo->repoid, $info->pset->psetid);
            while (($row = edb_orow($result))) {
                if (($rc = @$info->recent_commits($row->hash))) {
                    $row->subject = $rc->subject;
                    $row->commitat = $rc->commitat;
                }
                $info->regrades[$row->hash] = $row;
            }
        }
        return isset($info->regrades) && count($info->regrades);
    }

    static function runner_logfile($info, $checkt) {
        global $ConfSitePATH;
        return $ConfSitePATH . "/log/run" . $info->repo->cacheid
            . ".pset" . $info->pset->id
            . "/repo" . $info->repo->repoid
            . ".pset" . $info->pset->id
            . "." . $checkt . ".log";
    }

    static function runner_status_json($info, $checkt, $json = null) {
        global $Now;
        if (!$json)
            $json = (object) array();
        $lockfn = self::runner_logfile($info, $checkt) . ".lock";
        $lockf = @fopen($lockfn, "r+");
        if ($lockf === false) {
            $json->done = true;
            $json->status = "done";
        } else if (flock($lockf, LOCK_EX | LOCK_NB, $lockblock)) {
            $json->done = true;
            $json->status = "dead";
            flock($lockf, LOCK_UN);
        } else {
            $lockdata = fread($lockf, 1000);
            if (ctype_digit(trim($lockdata))
                && !posix_kill(trim($lockdata), 0)) {
                $json->done = true;
                $json->status = "dead";
            } else {
                $json->done = false;
                $json->status = "working";
                if ($Now - $checkt > 600)
                    $json->status = "old";
            }
        }
        if ($lockf)
            fclose($lockf);
        if ($json->status == "dead")
            unlink($lockfn);
        return $json;
    }

    static function pset_grade_json($pset, $pcview) {
        $max = array();
        $count = $maxtotal = 0;
        foreach ($pset->grades as $ge)
            if (!$ge->hide || $pcview) {
                $key = $ge->name;
                ++$count;
                if ($ge->max && ($pcview || !$ge->hide_max)) {
                    $max[$key] = $ge->max;
                    if (!$ge->is_extra)
                        $maxtotal += $ge->max;
                }
            }
        if ($maxtotal)
            $max["total"] = $maxtotal;
        return (object) array("nentries" => $count,
                              "maxgrades" => (object) $max);
    }

    static function grade_json($info) {
        global $Me;
        if (!$Me->can_see_grades($info->pset, $info->user, $info))
            return null;
        $pcview = $Me->isPC && $Me != $info->user;
        $notes = $info->commit_info();
        $result = self::pset_grade_json($info->pset, $pcview);
        if (isset($notes->autogrades) || isset($notes->grades)
            || $info->is_grading_commit()) {
            $g = $ag = array();
            $total = 0;
            foreach ($info->pset->grades as $ge)
                if (!$ge->hide || $pcview) {
                    $key = $ge->name;
                    $gv = 0;
                    if (isset($notes->autogrades->$key))
                        $ag[$key] = $g[$key] = $gv = $notes->autogrades->$key;
                    if (isset($notes->grades->$key))
                        $g[$key] = $gv = $notes->grades->$key;
                    if (!$ge->no_total)
                        $total += $gv;
                }
            $g["total"] = $total;
            $result->grades = (object) $g;
            if ($pcview && count($ag))
                $result->autogrades = (object) $ag;
        }
        return $result;
    }

    static function runner_generic_json($info, $checkt) {
        return (object) array("ok" => true,
                              "repoid" => $info->repo->repoid,
                              "pset" => $info->pset->urlkey,
                              "timestamp" => $checkt);
    }

    static function runner_json($info, $checkt, $offset = -1) {
        if (ctype_digit($checkt))
            $logfn = self::runner_logfile($info, $checkt);
        else if (preg_match(',\.(\d+)\.log(?:\.lock)?\z,', $checkt, $m)) {
            $logfn = $checkt;
            $checkt = $m[1];
        } else
            return false;

        $data = @file_get_contents($logfn, false, null, $offset);
        if ($data === false)
            return (object) array("error" => true, "message" => "No such log");

        // XXX UTF-8 argh
        $json = self::runner_generic_json($info, $checkt);
        $json->data = $data;
        $json->offset = max($offset, 0);
        $json->lastoffset = $json->offset + strlen($data);
        self::runner_status_json($info, $checkt, $json);
        return $json;
    }

    static function echo_group($key, $value, $notes = null, $extra = null) {
        echo "<table class=\"cs61grp\"><tr><td class=\"cs61key\">", $key, "</td>",
            "<td";
        if ($extra && isset($extra["nowrap"]) && $extra["nowrap"])
            echo " class=\"nowrap\"";
        echo ">", $value, "</td></tr><tr><td colspan=\"2\"><div class=\"cs61infgroup\">";
        if ($notes)
            foreach ($notes as $v) {
                $v = is_array($v) ? $v : array(false, $v);
                echo "<div class=\"", ($v[0] ? "cs61einf" : "cs61inf"),
                    "\">", $v[1], "</div>";
            }
        echo "</div></td></tr></table>\n";
    }

    static function echo_partner_group($info) {
        global $Conf, $Me;
        list($user, $pset, $partner) =
            array($info->user, $info->pset, $info->partner);
        if (!$pset->partner)
            return;

        // collect values
        $partner_email = "";
        if ($user->is_anonymous && $partner)
            $partner_email = $partner->anon_username;
        else if ($partner)
            $partner_email = $partner->email;
        $editable = $info->can_set_repo && !$user->is_anonymous;

        $title = "partner";
        if ($Me->isPC && $partner)
            $title = '<a href="' . hoturl("pset", array("u" => $Me->user_linkpart($partner), "pset" => $pset->id, "commit" => $info->commit_hash())) . '">' . $title . '</a>';

        if ($editable)
            $value = Ht::entry("partner", $partner_email, array("style" => "width:32em"))
                . " " . Ht::submit("Save");
        else
            $value = htmlspecialchars($partner_email ? : "(none)");

        // check back-partner links
        $notes = array();
        $backpartners = array_unique($user->links(LINK_BACKPARTNER, $pset->id));
        $info->partner_same = false;
        if (count($backpartners) == 0 && $partner)
            $notes[] = array(true, "ERROR: " . htmlspecialchars($partner_email) . " has not listed you as a partner yet.");
        else if (count($backpartners) == 1 && $partner && $backpartners[0] == $partner->contactId) {
            $info->partner_same = true;
            if ($partner->dropped)
                $notes[] = array(true, "ERROR: We believe your partner has dropped the course.");
        } else if (count($backpartners) == 0 && !$partner) {
            if ($editable)
                $notes[] = array(false, "Enter your partner’s email, username, or HUID here.");
        } else {
            $backpartners[] = -1;
            $result = $Conf->qe("select " . ($user->is_anonymous ? "email" : "anon_username") . " from ContactInfo where contactId in (" . join(",", $backpartners) . ")");
            $p = array();
            while (($row = edb_row($result)))
                if ($Me->isPC)
                    $p[] = '<a href="' . hoturl("pset", array("pset" => $pset->urlkey, "u" => $row[0])) . '">' . htmlspecialchars($row[0]) . '</a>';
                else
                    $p[] = htmlspecialchars($row[0]);
            $notes[] = array(true, "ERROR: These users have listed you as a partner for this pset: " . commajoin($p));
        }

        if ($editable)
            echo Ht::form(self_href(array("post" => post_value(), "set_partner" => 1, "pset" => $pset->urlkey))),
                "<div class='f-contain'>";
        self::echo_group($title, $value, $notes);
        if ($editable)
            echo "</div></form>";
        echo "\n";
    }

    static function set_partner_action($user) {
        global $Conf, $Me;
        if (!($Me->has_database_account() && check_post()
              && ($pset = Pset::find(@$_REQUEST["pset"]))))
            return;
        if (!$Me->can_set_repo($pset, $user))
            return $Conf->errorMsg("You can’t edit repository information for that problem set now.");
        if ($user->set_partner($pset, $_REQUEST["partner"]))
            redirectSelf();
    }

    static function echo_repo_group($info, $include_tarball = false) {
        global $Conf, $Me, $Now;
        if ($info->pset->gitless)
            return;
        list($user, $pset, $partner, $repo) =
            array($info->user, $info->pset, $info->partner, $info->repo);
        $editable = $info->can_set_repo && !$user->is_anonymous;

        $repo_url = $user->seascode_repo_base($repo ? $repo->url : "");
        $title = "repository";
        if ($repo_url && strpos($repo_url, ":") === false)
            $title = $user->repo_link($repo_url, $title);

        if ($editable)
            $value = Ht::entry("repo", $repo_url, array("style" => "width:32em"))
                . " " . Ht::submit("Save");
        else if ($user->is_anonymous)
            $value = $repo_url ? "[anonymous]" : "(none)";
        else
            $value = htmlspecialchars($repo_url ? $repo_url : "(none)");
        if ($repo_url) {
            $value .= ' <button class="repoclip" style="display:none" data-clipboard-text="'
                . htmlspecialchars($repo->url)
                . '" type="button" onclick="false">Copy URL to clipboard</button>';
            if (!self::$_clipboard) {
                $Conf->footerScript("var zeroclip=new ZeroClipboard(jQuery('.repoclip'));zeroclip.on('load',function(){jQuery('.repoclip').show()});zeroclip.on('mousedown',function(){jQuery(this).css({color:'red'})});zeroclip.on('mouseup',function(){jQuery(this).css({color:''})})");
                self::$_clipboard = true;
            }
            if ($include_tarball && ($tarball_url = $info->tarball_url()))
                $value .= ' <a class="bsm q" href="' . htmlspecialchars($tarball_url) . '">Download tarball for ' . substr($info->commit_hash(), 0, 7) . '</a>';
        }

        // check repo
        $notes = array();
        if ($repo && !$repo->working) {
            if ($user->check_seascode_repo($pset, $repo, false)) {
                $Now = time();
                $Conf->qe("update Repository set `working`=$Now where repoid=$repo->repoid");
            } else
                $notes[] = array(true, "ERROR: " . Messages::$main->expand_html("repo_unreadable", $user->repo_messagedefs($repo)));
        }
        if (($open = $info->check_repo_open()) > 0)
            $notes[] = array(true, "ERROR: " . Messages::$main->expand_html("repo_toopublic", $user->repo_messagedefs($repo)));
        else if ($open < 0 && $Me->isPC)
            $notes[] = array(true, "WARNING: " . Messages::$main->expand_html("repo_toopublic_timeout", $user->repo_messagedefs($repo)));
        if ($partner && $info->partner_same) {
            $prepo = $partner->repo($pset->id);
            if ((!$repo && $prepo) || ($repo && !$prepo)
                || ($repo && $prepo && $repo->repoid != $prepo->repoid)) {
                if ($prepo)
                    $prepo_url = ", " . htmlspecialchars($user->seascode_repo_base($prepo->url));
                else
                    $prepo_url = "";
                $your_partner = "your partner’s";
                if ($Me->isPC)
                    $your_partner = '<a href="' . hoturl("pset", array("pset" => $pset->urlkey, "u" => $Me->user_linkpart($partner))) . '">' . $your_partner . '</a>';
                $notes[] = array(true, "ERROR: This repository differs from $your_partner$prepo_url.");
            }
        }
        if ($repo && $repo_url[0] == "~" && $user->seascode_username
            && !preg_match("_\A~(?:" . preg_quote($user->seascode_username)
                           . ($partner ? "|" . preg_quote($partner->seascode_username) : "")
                           . ")/_i", $repo_url)) {
            if ($partner)
                $notes[] = array(true, "ERROR: This repository belongs to neither you nor your partner.");
            else
                $notes[] = array(true, "ERROR: This repository does not belong to you.");
        }
        if ($repo && isset($repo->truncated_psetdir)
            && defval($repo->truncated_psetdir, $pset->id))
            $notes[] = array(true, "Please create your repository by cloning our repository. Creating your repository from scratch makes it harder for us to grade and harder for you to get pset updates.");
        if (!$repo)
            $notes[] = array(false, "Enter your " . Contact::seascode_home("code.seas") . " repository URL here.");

        // edit
        if ($editable)
            echo Ht::form(self_href(array("post" => post_value(), "set_seascode_repo" => 1, "pset" => $pset->urlkey))),
                '<div class="f-contain">';
        self::echo_group($title, $value, $notes);
        if ($editable)
            echo "</div></form>\n";

        return $repo;
    }

    static function set_seascode_repo_action($user) {
        global $Conf, $Me;
        if (!($Me->has_database_account() && check_post()
              && ($pset = Pset::find(@$_REQUEST["pset"]))))
            return;
        if (!$Me->can_set_repo($pset, $user))
            return $Conf->errorMsg("You can’t edit repository information for that problem set now.");
        if ($user->set_seascode_repo($pset, $_REQUEST["repo"]))
            redirectSelf();
    }

    static function echo_repo_last_commit_group($info, $commitgroup) {
        global $Me, $Conf;
        list($user, $repo) = array($info->user, $info->repo);
        if ($info->pset->gitless)
            return;

        $hash = null;
        if ($repo && !$info->can_view_repo_contents)
            $value = "(unconfirmed repository)";
        else if ($repo && $repo->snaphash) {
            $value = substr($repo->snaphash, 0, 7) . " " . htmlspecialchars($repo->snapcommitline);
            $hash = $repo->snaphash;
        } else if ($repo)
            $value = "(checking)";
        else
            $value = "(no repo yet)";

        $notes = array();
        if ($repo && $repo->snapat && $info->can_view_repo_contents) {
            $n = "committed " . ago($repo->snapcommitat)
                . ", fetched " . ago($repo->snapat)
                . ", last checked " . ago($repo->snapcheckat);
            if ($Me->privChair)
                $n .= " <small style=\"padding-left:1em;font-size:70%\">repo/repo" . $repo->cacheid . ":repo" . $repo->repoid . "</small>";
            $notes[] = $n;
        } else if ($repo && !$info->can_view_repo_contents) {
            $uname = Text::analyze_name($user);
            if ($uname->name && $uname->email)
                $uname = "$uname->name <$uname->email>";
            else if ($uname->email)
                $uname = "Your Name <$uname->email>";
            else if ($uname->name)
                $uname = "$uname->name <youremail@example.com>";
            else
                $uname = "Your Name <youremail@example.com>";
            $notes[] = array(true, "ERROR: We haven’t confirmed that you can view this repository.<br>
We only let you view repositories that you’ve committed to.<br>
Fix this error by making a commit from your email address, " . htmlspecialchars($user->email) . ", and pushing that commit to the repository.<br>
For example, try these commands. (You’ll have to enter a commit message for the first command.) <pre>git commit --allow-empty --author=" . htmlspecialchars(escapeshellarg($uname)) . "; git push</pre>");
            $commitgroup = true;
        }

        if ($commitgroup) {
            echo "<div class=\"commitcontainer61\" peteramati_pset=\"", htmlspecialchars($info->pset->urlkey);
            if ($hash)
                echo "\" peteramati_commit=\"", $hash;
            echo "\">";
            $Conf->footerScript("checklatest61()", "checklatest61");
        }
        self::echo_group("last commit", $value, $notes);
        if ($commitgroup)
            echo "</div>";
    }

    static function echo_repo_grade_commit_group($info) {
        list($user, $repo) = array($info->user, $info->repo);
        if ($info->pset->gitless)
            return;
        else if (!$info->has_grading() || !$info->can_view_repo_contents)
            return self::echo_repo_last_commit_group($info, false);
        // XXX should check can_see_grades here

        $value = "";
        $ginfo = $info->grading_commit();
        if ($ginfo) {
            if (!is_object($ginfo))
                error_log(json_encode($ginfo) . " not an object, " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
            $value = substr($ginfo->hash, 0, 7) . " " . htmlspecialchars($ginfo->subject);
        }

        $notes = array();
        if ($ginfo && $ginfo->commitat)
            $notes[] = "committed " . ago($ginfo->commitat);
        if (($lh = $info->late_hours()) && $lh->hours > 0)
            $notes[] = '<strong class="overdue">' . plural($lh->hours, "late hour") . ' used</strong>';

        self::echo_group("grading commit", $value, array(join(", ", $notes)));
    }

    static function echo_repo_regrades_group($info) {
        global $Me;
        list($user, $repo) = array($info->user, $info->repo);
        if (self::add_regrades($info)) {
            foreach ($info->regrades as $row) {
                $t = "<a href=\"" . hoturl("pset", array("pset" => $info->pset->urlkey, "u" => $Me->user_linkpart($user), "commit" => $row->hash))
                    . "\">" . substr($row->hash, 0, 7) . "</a>";
                if (isset($row->subject))
                    $t .= " " . htmlspecialchars($row->subject);
                $value[] = $t;
            }
            self::echo_group("regrade requests", join("<br/>", $value));
        }
    }

    static function pset_grade($notesj, $pset) {
        if (!$pset->grades)
            return null;

        $total = $nonextra = 0;
        $r = array();
        $g = @$notesj->grades;
        $ag = @$notesj->autogrades;
        $rag = array();
        foreach ($pset->grades as $ge) {
            $key = $ge->name;
            $gv = null;
            if ($ag && isset($ag->$key))
                $gv = $rag[$key] = $ag->$key;
            if ($g && isset($g->$key))
                $gv = $g->$key;
            if ($gv !== null) {
                $r[$key] = $gv;
                if (!$ge->no_total) {
                    $total += $gv;
                    if (!$ge->is_extra)
                        $nonextra += $gv;
                }
            }
        }
        if (count($r)) {
            $r["total"] = $total;
            $r["total_noextra"] = $nonextra;
            if (count($rag))
                $r["autogrades"] = (object) $rag;
            return (object) $r;
        } else
            return null;
    }
}
