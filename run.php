<?php
// run.php -- HotCRP runner page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Psetid, $Info, $RecentCommits;

function quit($err = null) {
    global $Conf;
    $Conf->ajaxExit(array("error" => true, "message" => $err));
}

function user_pset_info() {
    global $Conf, $User, $Pset, $Info;
    $Info = ContactView::user_pset_info($User, $Pset);
    if (($Commit = @$_REQUEST["newcommit"]) == null)
        $Commit = @$_REQUEST["commit"];
    if (!$Info->set_commit($Commit))
        $Conf->ajaxExit(array("ok" => false, "error" => $Info->repo ? "No repository." : "Commit " . htmlspecialchars($Commit) . " isn’t connected to this repository."));
    return $Info;
}

ContactView::set_path_request(array("/@", "/@/p", "/@/p/H", "/p", "/p/H", "/p/u", "/p/u/H"));

// user, pset, runner
$User = $Me;
if (isset($_REQUEST["u"])
    && !($User = ContactView::prepare_user($_REQUEST["u"])))
    $Conf->ajaxExit(array("ok" => false));
assert($User == $Me || $Me->isPC);

$Pset = ContactView::find_pset_redirect(@$_REQUEST["pset"]);
$Psetid = $Pset->id;

if (isset($_POST["reqregrade"]) && check_post() && user_pset_info()) {
    Dbl::qe("insert into RepositoryGradeRequest (repoid,pset,hash,requested_at) values (?, ?, ?, ?) on duplicate key update requested_at=values(requested_at)",
            $Info->repo->repoid, $Info->pset->psetid, $Info->commit_hash(), $Now);
    Dbl::qe("delete from RepositoryGradeRequest where repoid=? and pset=? and requested_at<?",
            $Info->repo->repoid, $Info->pset->psetid, $Now);
    $Conf->ajaxExit(array("ok" => true));
}

if (!isset($Pset->run_dirpattern))
    quit("Configuration error (run_dirpattern)");
else if (!isset($Pset->run_jailfiles))
    quit("Configuration error (run_jailfiles)");
$Runner = null;
foreach (ContactView::prunners($Pset) as $r)
    if ($r->name == $_REQUEST["run"])
        $Runner = $r;
$RunMany = $Me->isPC && @$_GET["runmany"] && check_post();
if (!$Runner)
    quit("No such command");
else if (!$Me->can_view_run($Pset, $Runner, $RunMany ? null : $User)) {
    if (!$Me->isPC && !@$Runner->visible)
        quit("Command reserved for TFs");
    else if (@$Runner->disabled)
        quit("Command disabled");
    else
        quit("Can’t run command right now");
}

// magic multi-runner
if ($Me->isPC && @$_GET["runmany"] && check_post()) {
    $Conf->header(htmlspecialchars($Pset->title . " " . $Runner->text), "home");

    echo '<h2 id="runmany61_who"></h2>',
        Ht::form(hoturl_post("run")),
        '<div class="f-contain">',
        Ht::hidden("u", ""),
        Ht::hidden("pset", $Pset->urlkey),
        Ht::hidden("run", $Runner->name, array("id" => "runmany61")),
        '</div></form>';

    echo '<div id="run61out_' . $Runner->name . '">',
        '<div class="run61" id="run61_' . $Runner->name . '">',
        '<div class="run61in"><pre class="run61pre"></pre></div>',
        '</div>',
        '</div>';

    echo '<div id="runmany61_users">',
        htmlspecialchars($_GET["runmany"]),
        '</div>';

    $Conf->footerScript('runmany61()');
    echo '<div class="clear"></div>', "\n";
    $Conf->footer();
    exit;
}

// repo
$Info = user_pset_info();
$Repo = $Info->repo;
$RecentCommits = $Info->recent_commits();

// can we run this?
if (!$Repo)
    quit("No repository to run");
else if (!$Info->commit())
    quit("No commit to run");
else if (!isset($_REQUEST["run"]) || !check_post())
    quit("Permission error");
else if (!$Info->can_view_repo_contents && !$Me->isPC)
    quit("Unconfirmed repository");

// log directory
global $Logdir;
$Logdir = "$ConfSitePATH/log/run" . $Info->repo->cacheid
    . ".pset" . $Info->pset->id;
if (!is_dir($Logdir)) {
    $old_umask = umask(0);
    if (!mkdir($Logdir, 02770, true))
        quit("Error logging results");
    umask($old_umask);
}


// extract request info
$Queueid = cvtint(defval($_REQUEST, "queueid", -1));
$checkt = cvtint(defval($_REQUEST, "check"));
$Offset = cvtint(defval($_REQUEST, "offset", -1));


// maybe eval
function runner_eval($runner, $info, $answer) {
    global $ConfSitePATH;
    if (isset($runner->load) && $runner->load[0] == "/")
        require_once($runner->load);
    else if (isset($runner->load))
        require_once($ConfSitePATH . "/" . $runner->load);
    $answer->result = call_user_func($runner->eval, $info);
}


// checkup
if ($checkt > 0
    && $answer = ContactView::runner_json($Info, $checkt, $Offset)) {
    if ($answer->status != "working" && $Queueid > 0)
        $Conf->qe("delete from ExecutionQueue where queueid=$Queueid and repoid=" . $Info->repo->repoid);
    if ($answer->status == "done"
        && $Me->can_run($Pset, $Runner, $User)
        && @$Runner->eval)
        runner_eval($Runner, $Info, $answer);
    $Conf->ajaxExit($answer);
}


// if not checkup, then we’re gonna run it; check permission
if (!$Me->can_run($Pset, $Runner, $User))
    quit("You can’t run that command");


// queue
function load_queue($queueid, $repo) {
    global $Conf, $Now;
    $result = $Conf->qe("select q.*,
            count(fq.queueid) nahead,
            min(if(fq.runat>0,fq.runat,$Now)) as head_runat,
            min(fq.nconcurrent) as ahead_nconcurrent
        from ExecutionQueue q
        left join ExecutionQueue fq on (fq.queueclass=q.queueclass and fq.queueid<q.queueid)
        where q.queueid=$queueid group by q.queueid");
    if (!($queue = edb_orow($result)))
        quit("Queued job was cancelled, try again");
    else if ($queue->repoid != $repo->repoid)
        quit("Queued job belongs to a different repository");
    return $queue;
}

function clean_queue($qname, $qconf, $qid) {
    global $Conf, $Now;
    $runtimeout = isset($qconf->runtimeout) ? $qconf->runtimeout : 300;
    $result = $Conf->qe("select * from ExecutionQueue where queueclass='" . sqlq($qname) . "' and queueid<$qid");
    while (($row = edb_orow($result))) {
        // remove dead items from queue
        // - lockfile specified but not there
        // - no lockfile & last update < 30sec ago
        // - running for more than 5min (configurable)
        if (($row->lockfile && !file_exists($row->lockfile))
            || ($row->runat <= 0 && $row->updateat < $Now - 30)
            || ($runtimeout && $row->runat > 0 && $row->runat < $Now - $runtimeout))
            $Conf->qe("delete from ExecutionQueue where queueid=$row->queueid");
    }
}

$Queue = null;
if (isset($Runner->queue)) {
    if ($Queueid < 0 && $checkt > 0)
        quit("No such job");
    if ($Queueid < 0) {
        $Conf->qe("insert into ExecutionQueue set
            queueclass='" . sqlq($Runner->queue) . "',
            insertat=$Now, updateat=$Now, repoid={$Info->repo->repoid},
            runat=0, status=0, psetid={$Pset->id}, hash='" . sqlq($Info->commit_hash()) . "',
            nconcurrent=" . (isset($Runner->nconcurrent) && $Runner->nconcurrent ? sqlq($Runner->nconcurrent) : "null"));
        $Queueid = $Conf->dblink->insert_id;
    } else
        $Conf->qe("update ExecutionQueue set updateat=$Now where queueid=$Queueid");
    $Queue = load_queue($Queueid, $Info->repo);

    $qconf = defval(defval($PsetInfo, "_queues", array()), $Runner->queue);
    if (!$qconf)
        $qconf = (object) array("nconcurrent" => 1);

    $nconcurrent = defval($qconf, "nconcurrent", 1000);
    if (@$Runner->nconcurrent > 0 && $Runner->nconcurrent < $nconcurrent)
        $nconcurrent = $Runner->nconcurrent;
    if (@$Queue->ahead_nconcurrent > 0 && $Queue->ahead_nconcurrent < $nconcurrent)
        $nconcurrent = $Queue->ahead_nconcurrent;

    for ($tries = 0; $tries < 2; ++$tries) {
        // error_log($User->seascode_username . ": $Queue->queueid, $nconcurrent, $Queue->nahead, $Queue->ahead_nconcurrent");
        if ($nconcurrent > 0 && $Queue->nahead >= $nconcurrent) {
            if ($tries)
                $Conf->ajaxExit(array("onqueue" => true, "queueid" => $Queue->queueid, "nahead" => $Queue->nahead, "headage" => ($Queue->head_runat ? $Now - $Queue->head_runat : null)));
            clean_queue($Runner->queue, $qconf, $Queue->queueid);
            $Queue = load_queue($Queueid, $Info->repo);
        } else
            break;
    }

    // if we get here we can actually run
}


$checkt = time();


// maybe eval
if (!@$Runner->command && @$Runner->eval) {
    $json = ContactView::runner_generic_json($Info, $checkt);
    $json->done = true;
    $json->status = "done";
    runner_eval($Runner, $Info, $json);
    $Conf->ajaxExit($json);
}


// look for existing lock
$logfs = glob("$Logdir/repo" . $Info->repo->repoid
              . ".pset" . $Info->pset->id
              . ".*.log*");
rsort($logfs);

// recent
if (defval($_REQUEST, "check") == "recent") {
    for ($i = 0; $i != count($logfs) && str_ends_with($logfs[$i], ".lock"); )
        ++$i;
    if ($i != count($logfs))
        $Conf->ajaxExit(ContactView::runner_json($Info, $logfs[$i], $Offset));
    else
        quit("No logs yet");
}

// otherwise, start a new request
function expand($x) {
    global $Opt, $Info, $ConfSitePATH;
    if (strpos($x, '${') !== false) {
        $x = str_replace('${REPOID}', $Info->repo->repoid, $x);
        $x = str_replace('${PSET}', $Info->pset->id, $x);
        $x = str_replace('${CONFDIR}', "conf/", $x);
        $x = str_replace('${SRCDIR}', "src/", $x);
        $x = str_replace('${HOSTTYPE}', defval($Opt, "hostType", ""), $x);
        $x = str_replace('${COMMIT}', $Info->commit_hash(), $x);
        $x = str_replace('${HASH}', $Info->commit_hash(), $x);
    }
    return $x;
}

foreach ($logfs as $lf)
    if (preg_match(',.*\.(\d+)\.log\.lock\z,', $lf, $m)
        && ($lstatus = ContactView::runner_status_json($Info, $m[1]))
        && $lstatus->status == "working")
        quit("Recent job still running");

$logfn = ContactView::runner_logfile($Info, $checkt);
$lockfn = $logfn . ".lock";
file_put_contents($lockfn, "");
if ($Queue)
    $Conf->qe("update ExecutionQueue set runat=$Now, status=1, lockfile='" . sqlq($lockfn) . "' where queueid=$Queue->queueid");

// check out the files
if (!chdir($ConfSitePATH))
    $Conf->ajaxExit(array("ok" => false));

$subdirarg = "";
if (isset($Info->repo->truncated_psetdir)
    && defval($Info->repo->truncated_psetdir, $Pset->id)
    && $Pset->directory_noslash !== "")
    $subdirarg = " -s$Pset->directory_noslash";

$skeletonarg = "";
if ($Pset->run_skeletondir)
    $skeletonarg .= " -l " . escapeshellarg($Pset->run_skeletondir);
else if (isset($Opt["run_jailskeleton"]))
    $skeletonarg .= " -l " . escapeshellarg($Opt["run_jailskeleton"]);

$username = "jail61user";
if (@$Runner->run_username)
    $username = $Runner->run_username;
else if (@$Pset->run_username)
    $username = $Pset->run_username;
if (!preg_match('/\A\w+\z/', $username))
    $Conf->ajaxExit(array("ok" => false, "error" => "bad run_username"));

$command = "jail/gitexecjail -u $username -p $Pset->id"
    . " -c $Repo->cacheid -H " . $Info->commit_hash()
    . $subdirarg . $skeletonarg
    . " $Repo->repoid $Pset->run_dirpattern";
if (isset($Pset->run_overlay) && $Pset->run_overlay != "")
    $command .= " $Pset->run_overlay";
else
    $command .= " -";

$command = expand("($command) </dev/null >>" . escapeshellarg($logfn) . " 2>&1");
//file_put_contents($logfn, $command . "\n", FILE_APPEND);
system($command);


// maybe store extra information
if (($runsettings = $Info->commit_info("runsettings"))) {
    $x = "";
    foreach ((array) $runsettings as $k => $v)
        $x .= "$k = $v\n";
    $info = posix_getpwnam($username);
    $jail61home = $info ? $info["dir"] : "/home/jail61";
    file_put_contents(expand("$Pset->run_dirpattern$jail61home/config.mk"), $x);
}


// run the command (see also jail/gitexecjail)
$command = expand("(echo; jail/loglock " . escapeshellarg($lockfn)
    . " -- jail/execjail -t$skeletonarg"
    . " $Pset->run_dirpattern $username "
    . escapeshellarg($Runner->command)
    . ") <$Pset->run_jailfiles >>" . escapeshellarg($logfn) . " 2>&1 &");
//file_put_contents($logfn, $command . "\n", FILE_APPEND);
system(expand($command));


// save information about execution
$Info->update_commit_info(array("run" => array($Runner->name => $checkt)));

$Conf->ajaxExit(array("ok" => true,
                      "done" => false,
                      "status" => "working",
                      "repoid" => $Info->repo->repoid,
                      "pset" => $Info->pset->id,
                      "timestamp" => $checkt));
