<?php
// run.php -- Peteramati runner page
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Psetid, $Info, $RecentCommits;

function quit($err = null) {
    global $Conf;
    $Conf->ajaxExit(array("ok" => false, "error" => $err));
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

if (!$Pset->run_dirpattern)
    quit("Configuration error (run_dirpattern)");
else if (!$Pset->run_jailfiles)
    quit("Configuration error (run_jailfiles)");
$Runner = null;
foreach ($Pset->runners as $r)
    if ($r->name == $_REQUEST["run"])
        $Runner = $r;
$RunMany = $Me->isPC && @$_GET["runmany"] && check_post();
if (!$Runner)
    quit("No such command");
else if (!$Me->can_view_run($Pset, $Runner, $RunMany ? null : $User)) {
    if (!$Me->isPC && !$Runner->visible)
        quit("Command reserved for TFs");
    else if ($Runner->disabled)
        quit("Command disabled");
    else
        quit("Can’t run command right now");
}

// magic multi-runner
if ($Me->isPC && @$_GET["runmany"] && check_post()) {
    $Conf->header(htmlspecialchars($Pset->title . " " . $Runner->title), "home");

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
        && $Runner->eval)
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
        // - lockfile contains "0\n": child has exited, remove it
        // - lockfile specified but not there
        // - no lockfile & last update < 30sec ago
        // - running for more than 5min (configurable)
        if ($row->lockfile && @file_get_contents($row->lockfile) === "0\n")
            unlink($row->lockfile);
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
    if ($Runner->nconcurrent > 0 && $Runner->nconcurrent < $nconcurrent)
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
if (!$Runner->command && $Runner->eval) {
    $json = ContactView::runner_generic_json($Info, $checkt);
    $json->done = true;
    $json->status = "done";
    runner_eval($Runner, $Info, $json);
    $Conf->ajaxExit($json);
}


// otherwise run
try {
    $rs = new RunnerState($Info, $Runner, $Queue);

    // recent
    if (@$_REQUEST["check"] == "recent" && count($rs->logged_checkts))
        $Conf->ajaxExit(ContactView::runner_json($Info, $rs->logged_checkts[0], $Offset));
    else if (@$_REQUEST["check"] == "recent")
        quit("no logs yet");

    if ($rs->is_recent_job_running())
        quit("recent job still running");

    // run
    $rs->start();

    // save information about execution
    $Info->update_commit_info(array("run" => array($Runner->name => $rs->checkt)));

    $Conf->ajaxExit(array("ok" => true,
                          "done" => false,
                          "status" => "working",
                          "repoid" => $Info->repo->repoid,
                          "pset" => $Info->pset->id,
                          "timestamp" => $rs->checkt));
} catch (Exception $e) {
    quit($e->getMessage());
}
