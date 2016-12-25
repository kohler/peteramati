<?php
// contactview.php -- HotCRP helper class for user related printouts
// Peteramati is Copyright (c) 2006-2016 Eddie Kohler
// See LICENSE for open-source distribution terms

class ContactView {
    static private $_reverse_pset_compare = false;

    static function set_path_request($paths) {
        global $Conf;
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
                else if ($p[$ppos] == "p" && $Conf->pset_by_key(get($x, $xpos)))
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
            $user = $Conf->user_by_whatever($usertext);
            if (!$user)
                $Conf->errorMsg("No such user “" . htmlspecialchars($usertext) . "”.");
            else if ($user->contactId == $Me->contactId)
                $user = $Me;
            else if (!$Me->isPC) {
                $Conf->errorMsg("You can’t see that user’s information.");
                $user = null;
            } else
                $user->set_anonymous(substr($usertext, 0, 5) === "[anon"
                                     || ($pset && $pset->anonymous));
        }
        if ($user && ($Me->isPC || $Me->chairContact)) {
            if ($pset && $pset->anonymous)
                $usertext = $user->anon_username;
            else
                $usertext = $user->username ? : $user->email;
        }
        return $user;
    }

    static function find_pset_redirect($psetkey) {
        global $Conf;
        $pset = $Conf->pset_by_key($psetkey);
        if ((!$pset || $pset->disabled)
            && ($psetkey !== null && $psetkey !== "" && $psetkey !== false))
            $Conf->errorMsg("No such problem set “" . htmlspecialchars($psetkey) . "”.");
        if (!$pset || $pset->disabled) {
            foreach ($Conf->psets() as $p)
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
        global $Conf;
        $psets = array();
        $istf = $contact && ($contact === true || $contact->isPC);
        $ischair = $contact instanceof Contact && $contact->privChair;
        foreach ($Conf->psets() as $pset)
            if (Contact::student_can_view_pset($pset)
                || (!$pset->disabled && $pset->gitless && $istf)
                || (!$pset->ui_disabled && $ischair))
                $psets[$pset->id] = $pset;
        self::$_reverse_pset_compare = !!$reverse;
        uasort($psets, "ContactView::pset_compare");
        return $psets;
    }

    static function user_pset_info(Contact $user, Pset $pset) {
        global $Me;
        return new PsetView($pset, $user, $Me);
    }

    static function add_regrades(PsetView $info) {
        list($user, $repo) = array($info->user, $info->repo);
        if (!isset($info->regrades) && !$info->pset->gitless_grades) {
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

    static function runner_logfile(PsetView $info, $checkt) {
        global $ConfSitePATH;
        return $ConfSitePATH . "/log/run" . $info->repo->cacheid
            . ".pset" . $info->pset->id
            . "/repo" . $info->repo->repoid
            . ".pset" . $info->pset->id
            . "." . $checkt . ".log";
    }

    static function runner_status_json(PsetView $info, $checkt, $json = null) {
        global $Now;
        if (!$json)
            $json = (object) array();
        $logfn = self::runner_logfile($info, $checkt);
        $lockfn = $logfn . ".pid";
        $pid_data = @file_get_contents($lockfn);
        if (ctype_digit(trim($pid_data))
            && !posix_kill(trim($pid_data), 0)
            && posix_get_last_error() == 3 /* ESRCH */)
            $pid_data = "dead\n";
        if ($pid_data === false || $pid_data === "0\n") {
            $json->done = true;
            $json->status = "done";
        } else if ($pid_data === "" || ctype_digit(trim($pid_data))) {
            $json->done = false;
            $json->status = "working";
            if ($Now - $checkt > 600)
                $json->status = "old";
        } else {
            $json->done = true;
            $json->status = "dead";
        }
        if ($json->done && $pid_data !== false) {
            unlink($lockfn);
            unlink($logfn . ".in");
        }
        return $json;
    }

    static function grade_json(PsetView $info) {
        global $Me;
        if (!$Me->can_view_grades($info->pset, $info->user, $info))
            return null;
        $pcview = $Me->isPC && $Me != $info->user;
        $notes = $info->current_info();
        $result = $info->pset->gradeinfo_json($pcview);
        $agx = get($notes, "autogrades");
        $gx = get($notes, "grades");
        if ($agx || $gx || $info->is_grading_commit()) {
            $g = $ag = array();
            $total = $total_noextra = 0;
            foreach ($info->pset->grades as $ge)
                if (!$ge->hide || $pcview) {
                    $key = $ge->name;
                    $gv = 0;
                    if ($agx && property_exists($agx, $key))
                        $ag[$key] = $g[$key] = $gv = $agx->$key;
                    if ($gx && property_exists($gx, $key))
                        $g[$key] = $gv = $gx->$key;
                    if (!$ge->no_total) {
                        $total += $gv;
                        if (!$ge->is_extra)
                            $total_noextra += $gv;
                    }
                }
            $g["total"] = $total;
            if ($total != $total_noextra)
                $g["total_noextra"] = $total_noextra;
            $result->grades = (object) $g;
            if ($pcview && count($ag))
                $result->autogrades = (object) $ag;
        }
        return $result;
    }

    static function runner_generic_json(PsetView $info, $checkt) {
        return (object) array("ok" => true,
                              "repoid" => $info->repo->repoid,
                              "pset" => $info->pset->urlkey,
                              "timestamp" => $checkt);
    }

    static function runner_json(PsetView $info, $checkt, $offset = -1) {
        if (ctype_digit($checkt))
            $logfn = self::runner_logfile($info, $checkt);
        else if (preg_match(',\.(\d+)\.log(?:\.lock|\.pid)?\z,', $checkt, $m)) {
            $logfn = $checkt;
            $checkt = $m[1];
        } else
            return false;

        $data = @file_get_contents($logfn, false, null, $offset);
        if ($data === false)
            return (object) array("error" => true, "message" => "No such log");

        // Fix up $data if it is not valid UTF-8.
        if (!is_valid_utf8($data)) {
            $data = UnicodeHelper::utf8_truncate_invalid($data);
            if (!is_valid_utf8($data))
                $data = UnicodeHelper::utf8_replace_invalid($data);
        }

        $json = self::runner_generic_json($info, $checkt);
        $json->data = $data;
        $json->offset = max($offset, 0);
        $json->lastoffset = $json->offset + strlen($data);
        self::runner_status_json($info, $checkt, $json);
        return $json;
    }

    static function runner_write(PsetView $info, $checkt, $data) {
        global $ConfSitePATH;
        if (!ctype_digit($checkt))
            return false;
        $logfn = self::runner_logfile($info, $checkt);
        $proc = proc_open("$ConfSitePATH/jail/pa-writefifo " . escapeshellarg($logfn . ".in"),
                          array(array("pipe", "r")), $pipes);
        if ($pipes[0]) {
            fwrite($pipes[0], $data);
            fclose($pipes[0]);
        }
        if ($proc)
            proc_close($proc);
    }

    static function echo_heading($user) {
        global $Me;
        $u = $Me->user_linkpart($user);
        if ($user !== $Me && !$user->is_anonymous && $user->contactImageId)
            echo '<img class="smallface61" src="' . hoturl("face", array("u" => $u, "imageid" => $user->contactImageId)) . '" />';

        echo '<h2 class="homeemail"><a href="',
            hoturl("index", array("u" => $u)), '">', htmlspecialchars($u), '</a>';
        if ($user->extension)
            echo " (X)";
        /*if ($Me->privChair && $user->is_anonymous)
            echo " ",*/
        if ($Me->privChair)
            echo "&nbsp;", become_user_link($user);
        echo '</h2>';

        if ($user !== $Me && !$user->is_anonymous)
            echo '<h3>', Text::user_html($user), '</h3>';

        if ($user->dropped)
            ContactView::echo_group("", '<strong class="err">You have dropped the course.</strong> If this is incorrect, contact us.');

        echo '<hr class="c" />';
    }

    static function echo_group($key, $value, $notes = null, $extra = null) {
        echo "<table class=\"pa-grp\"><tr><td class=\"cs61key\">", $key, "</td><td";
        if ($extra && isset($extra["nowrap"]) && $extra["nowrap"])
            echo " class=\"nw\"";
        echo ">", $value, "</td></tr><tr><td colspan=\"2\"><div class=\"cs61infgroup\">";
        if ($notes)
            foreach ($notes as $v) {
                $v = is_array($v) ? $v : array(false, $v);
                echo "<div class=\"", ($v[0] ? "pa-inf-error" : "pa-inf"),
                    "\">", $v[1], "</div>";
            }
        echo "</div></td></tr></table>\n";
    }

    static function echo_partner_group(PsetView $info) {
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
            $title = '<a href="' . hoturl("pset", ["u" => $Me->user_linkpart($partner), "pset" => $pset->id, "commit" => $info->maybe_commit_hash()]) . '">' . $title . '</a>';

        if ($editable)
            $value = Ht::entry("partner", $partner_email, array("style" => "width:32em"))
                . " " . Ht::submit("Save");
        else
            $value = htmlspecialchars($partner_email ? : "(none)");

        // check back-partner links
        $notes = array();
        $backpartners = $info->backpartners();
        if (count($backpartners) == 0 && $partner)
            $notes[] = array(true, "ERROR: " . htmlspecialchars($partner_email) . " has not listed you as a partner yet.");
        else if (count($backpartners) == 1 && $partner && $backpartners[0] == $partner->contactId) {
            if ($partner->dropped)
                $notes[] = array(true, "ERROR: We believe your partner has dropped the course.");
        } else if (count($backpartners) == 0 && !$partner) {
            if ($editable)
                $notes[] = array(false, "Enter your partner’s email, username, or HUID here.");
        } else {
            $backpartners[] = -1;
            $result = $Conf->qe("select " . ($user->is_anonymous ? "anon_username" : "email") . " from ContactInfo where contactId ?a", $backpartners);
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
              && ($pset = $Conf->pset_by_key(req("pset")))))
            return;
        if (!$Me->can_set_repo($pset, $user))
            return $Conf->errorMsg("You can’t edit repository information for that problem set now.");
        if ($user->set_partner($pset, $_REQUEST["partner"]))
            redirectSelf();
    }

    static function echo_repo_group(PsetView $info, $include_tarball = false) {
        global $Conf, $Me, $Now;
        if ($info->pset->gitless)
            return;
        list($user, $pset, $partner, $repo) =
            array($info->user, $info->pset, $info->partner, $info->repo);
        $editable = $info->can_set_repo && !$user->is_anonymous;

        $repo_url = $repo ? $repo->friendly_url() : "";
        $title = "repository";
        if (!RepositorySite::is_primary($repo))
            $title = $repo->reposite->friendly_siteclass() . " " . $title;
        if ($repo && $repo->url)
            $title = $user->link_repo($title, $repo->web_url());

        if ($editable)
            $value = Ht::entry("repo", $repo_url, array("style" => "width:32em"))
                . " " . Ht::submit("Save");
        else if ($user->is_anonymous)
            $value = $repo_url ? "[anonymous]" : "(none)";
        else
            $value = htmlspecialchars($repo_url ? $repo_url : "(none)");
        if ($repo_url) {
            $value .= ' <button class="b repoclip hottooltip" data-pa-repo="' . htmlspecialchars($repo->ssh_url()) . '"';
            if ($user->is_anonymous)
                $value .= ' data-tooltip="[anonymous]"';
            else
                $value .= ' data-tooltip="' . htmlspecialchars($repo->ssh_url()) . '"';
            $value .= ' type="button" onclick="false">Copy URL to clipboard</button>';
            Ht::stash_script('$(".repoclip").each(pa_init_repoclip)', "repoclip");
            if ($include_tarball && $info->commit_hash()
                && ($tarball_url = $info->tarball_url()))
                $value .= ' <a class="bsm q" href="' . htmlspecialchars($tarball_url) . '">Download tarball for ' . substr($info->commit_hash(), 0, 7) . '</a>';
        }

        // check repo
        $ms = new MessageSet($user);
        if ($repo) {
            $repo->check_working($ms);
            $repo->check_open($ms);
        }
        if ($partner && $info->partner_same()) {
            $prepo = $partner->repo($pset->id);
            if ((!$repo && $prepo) || ($repo && !$prepo)
                || ($repo && $prepo && $repo->repoid != $prepo->repoid)) {
                if ($prepo && $repo)
                    $prepo_url = ", " . htmlspecialchars($prepo->friendly_url_like($repo));
                else if ($prepo)
                    $prepo_url = ", " . htmlspecialchars($prepo->friendly_url());
                else
                    $prepo_url = "";
                $your_partner = "your partner’s";
                if ($Me->isPC)
                    $your_partner = '<a href="' . hoturl("pset", array("pset" => $pset->urlkey, "u" => $Me->user_linkpart($partner))) . '">' . $your_partner . '</a>';
                $ms->set_error_html("partner", "This repository differs from $your_partner$prepo_url.");
            }
        }
        if ($repo)
            $repo->check_ownership($user, $partner, $ms);
        $prefixes = ["", "WARNING: ", "ERROR: "];
        $notes = array_map(function ($m) use ($prefixes) {
            return [$m[2] > 0, $prefixes[$m[2]] . $m[1]];
        }, $ms->messages(true));
        if ($repo && $repo->truncated_psetdir($pset))
            $notes[] = array(true, "Please create your repository by cloning our repository. Creating your repository from scratch makes it harder for us to grade and harder for you to get pset updates.");
        if (!$repo) {
            $repoclasses = RepositorySite::site_classes($Conf);
            $x = commajoin(array_map(function ($k) { return Ht::link($k::global_friendly_siteclass(), $k::global_friendly_siteurl()); }, $repoclasses), "or");
            if ($editable)
                $notes[] = array(false, "Enter your $x repository URL here.");
        }

        // edit
        if ($editable)
            echo Ht::form(self_href(array("post" => post_value(), "set_repo" => 1, "pset" => $pset->urlkey))),
                '<div class="f-contain">';
        self::echo_group($title, $value, $notes);
        if ($editable)
            echo "</div></form>\n";

        return $repo;
    }

    static function set_repo_action($user) {
        global $Conf, $Me, $ConfSitePATH;
        if (!($Me->has_database_account() && check_post()
              && ($pset = $Conf->pset_by_key(req("pset")))))
            return;
        if (!$Me->can_set_repo($pset, $user))
            return Conf::msg_error("You can’t edit repository information for that problem set now.");

        // clean up repo url
        $repo_url = trim(req("repo"));
        if ($pset->repo_guess_patterns)
            for ($i = 0; $i + 1 < count($pset->repo_guess_patterns); $i += 2) {
                $x = preg_replace('`' . str_replace("`", "\\`", $pset->repo_guess_patterns[$i]) . '`s',
                                  $pset->repo_guess_patterns[$i + 1],
                                  $repo_url, -1, $nreplace);
                if ($x !== null && $nreplace) {
                    $repo_url = $x;
                    break;
                }
            }

        // does it contain odd characters?
        if (preg_match('_[,;\[\](){}\\<>&#=\\000-\\027]_', $repo_url))
            return Conf::msg_error("That repository contains funny characters. Remove them.");

        // record interested repositories
        $try_classes = [];
        foreach (RepositorySite::site_classes($user->conf) as $sitek) {
            $sniff = $sitek::sniff_url($repo_url);
            if ($sniff == 2) {
                $try_classes = [$sitek];
                break;
            } else if ($sniff)
                $try_classes[] = $sitek;
        }
        if (empty($try_classes))
            return Conf::msg_error("Invalid repository URL “" . htmlspecialchars($repo_url) . "”.");

        // check repositories
        $working = false;
        $ms = new MessageSet($user);
        foreach ($try_classes as $sitek) {
            $reposite = $sitek::make_url($repo_url, $user->conf);
            if ($reposite && $reposite->validate_working($ms) > 0) {
                $working = true;
                break;
            }
        }

        // if !working, complain
        if (!$working && !$ms->has_problems())
            return Conf::msg_error("Invalid repository URL “" . htmlspecialchars($repo_url) . "” (tried " . join(", ", array_map(function ($m) { return $m::global_friendly_siteclass(); }, $try_classes)) . ").");
        else if (!$working) {
            $msgs = join("<br />", $ms->messages()) ? : "Repository unreachable at the moment.";
            return Conf::msg_error($msgs);
        }

        // store repo
        $repo = Repository::find_or_create_url($reposite->url, $user->conf);
        $repo && $repo->check_open();
        if ($user->set_repo($pset, $repo))
            redirectSelf();
    }

    static function echo_repo_last_commit_group(PsetView $info, $commitgroup) {
        global $Me, $Conf;
        list($user, $repo) = array($info->user, $info->repo);
        if ($info->pset->gitless)
            return;

        if ($repo && !$info->user_can_view_repo_contents)
            $value = "(unconfirmed repository)";
        else if ($repo && $repo->snaphash)
            $value = substr($repo->snaphash, 0, 7) . " " . htmlspecialchars($repo->snapcommitline);
        else if ($repo)
            $value = "(checking)";
        else
            $value = "(no repo yet)";

        $notes = array();
        if ($repo && $repo->snapat && $info->can_view_repo_contents) {
            $n = "committed " . ago($repo->snapcommitat)
                . ", fetched " . ago($repo->snapat)
                . ", last checked " . ago($repo->snapcheckat);
            if ($Me->privChair)
                $n .= " <small style=\"padding-left:1em;font-size:70%\">group " . $repo->cacheid . ", repo" . $repo->repoid . "</small>";
            $notes[] = $n;
        }
        if ($repo && !$info->user_can_view_repo_contents) {
            if ($user->is_anonymous)
                $notes[] = array(true, "ERROR: The user hasn’t confirmed that they can view this repository.");
            else {
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
    For example, try these commands: <pre>git commit --allow-empty --author=" . htmlspecialchars(escapeshellarg($uname)) . " \\\n        -m \"Confirm repository\" &amp;&amp; git push</pre>");
            }
            $commitgroup = true;
        }

        if ($commitgroup) {
            echo "<div class=\"commitcontainer61\" data-pa-pset=\"", htmlspecialchars($info->pset->urlkey);
            if ($repo && $repo->snaphash && $info->can_view_repo_contents)
                echo "\" data-pa-commit=\"", $repo->snaphash;
            echo "\">";
            Ht::stash_script("checklatest61()", "checklatest61");
        }
        self::echo_group("last commit", $value, $notes);
        if ($commitgroup)
            echo "</div>";
    }

    static function echo_repo_grade_commit_group(PsetView $info) {
        list($user, $repo) = array($info->user, $info->repo);
        if ($info->pset->gitless_grades)
            return;
        else if (!$info->has_grading() || !$info->can_view_repo_contents)
            return self::echo_repo_last_commit_group($info, false);
        // XXX should check can_view_grades here

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

    static function echo_repo_flags_group($info) {
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
            self::echo_group("flagged commits", join("<br/>", $value));
        }
    }

    static function pset_grade($notesj, $pset) {
        if (!$pset->grades)
            return null;

        $total = $nonextra = 0;
        $r = array();
        $g = get($notesj, "grades");
        $ag = get($notesj, "autogrades");
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
        if (!empty($r)) {
            $r["total"] = $total;
            $r["total_noextra"] = $nonextra;
            if (count($rag))
                $r["autogrades"] = (object) $rag;
            return (object) $r;
        } else
            return null;
    }
}
