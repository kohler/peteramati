<?php
// run.php -- Peteramati runner page
// HotCRP and Peteramati are Copyright (c) 2006-2018 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info, $Qreq;

function quit($err = null) {
    global $Conf;
    json_exit(["ok" => false, "error" => htmlspecialchars($err), "error_text" => $err]);
}

function user_pset_info() {
    global $Conf, $User, $Me, $Pset, $Info, $Qreq;
    $Info = PsetView::make($Pset, $User, $Me);
    if (($Commit = $Qreq->newcommit) == null)
        $Commit = $Qreq->commit;
    if (!$Info->set_hash($Commit))
        quit(!$Info->repo ? "No repository" : "Commit $Commit isn’t connected to this repository");
    return $Info;
}

ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/p", "/p/h", "/p/u/h"));
$Qreq = make_qreq();

// user, pset, runner
$User = $Me;
if ($Qreq->u !== null
    && !($User = ContactView::prepare_user($Qreq->u)))
    json_exit(["ok" => false]);
assert($User == $Me || $Me->isPC);

$Pset = ContactView::find_pset_redirect($Qreq->pset);

// XXX this should be under `api`
if ($Qreq->flag && check_post($Qreq) && user_pset_info()) {
    $flags = (array) $Info->current_info("flags");
    if ($Qreq->flagid && !isset($flags["t" . $Qreq->flagid]))
        json_exit(["ok" => false, "error" => "No such flag"]);
    if (!$Qreq->flagid)
        $Qreq->flagid = "t" . $Now;
    $flag = get($flags, $Qreq->flagid, []);
    if (!get($flag, "uid"))
        $flag["uid"] = $Me->contactId;
    if (!get($flag, "started"))
        $flag["started"] = $Now;
    if (get($flag, "started", $Qreq->flagid) != $Now)
        $flag["updated"] = $Now;
    if ($Qreq->flagreason)
        $flag["conversation"][] = [$Now, $Me->contactId, $Qreq->flagreason];
    $updates = ["flags" => [$Qreq->flagid => $flag]];
    $Info->update_current_info($updates);
    json_exit(["ok" => true]);
}

// XXX this should be under `api`
if ($Qreq->resolveflag && check_post($Qreq) && user_pset_info()) {
    $flags = (array) $Info->current_info("flags");
    if (!$Qreq->flagid || !isset($flags[$Qreq->flagid]))
        json_exit(["ok" => false, "error" => "No such flag"]);
    if (get($flags[$Qreq->flagid], "resolved"))
        json_exit(["ok" => true]);
    $updates = ["flags" => [$Qreq->flagid => ["resolved" => [$Now, $Me->contactId]]]];
    $Info->update_current_info($updates);
    json_exit(["ok" => true]);
}

$Runner = null;
foreach ($Pset->runners as $r)
    if ($r->name == $Qreq->run)
        $Runner = $r;
$RunMany = $Me->isPC && get($_GET, "runmany") && check_post();
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
if ($Me->isPC && get($_GET, "runmany") && check_post()) {
    $t = $Pset->title;
    if (get($_GET, "ensure"))
        $t .= " Ensure";
    $t .= " " . $Runner->title;
    $Conf->header(htmlspecialchars($t), "home");

    echo '<h2 id="runmany61_who"></h2>',
        Ht::form(hoturl_post("run")),
        '<div class="f-contain">',
        Ht::hidden("u", ""),
        Ht::hidden("pset", $Pset->urlkey);
    if (get($_GET, "ensure"))
        echo Ht::hidden("ensure", 1);
    echo Ht::hidden("run", $Runner->name, ["id" => "runmany61", "data-pa-run-category" => $Runner->category_argument()]),
        '</div></form>';

    echo '<div id="pa-runout-' . $Runner->category . '">',
        '<div class="pa-run pa-run-short" id="pa-run-' . $Runner->category . '">',
        '<pre class="pa-runpre"></pre></div>',
        '</div>';

    echo '<div id="runmany61_users">',
        htmlspecialchars($_GET["users"]),
        '</div>';

    Ht::stash_script('runmany61()');
    echo '<div class="clear"></div>', "\n";
    $Conf->footer();
    exit;
}

// repo
$Info = user_pset_info();
$Repo = $Info->repo;

// can we run this?
if (!$Repo)
    quit("No repository to run");
else if (!$Info->commit())
    quit("No commit to run");
else if ($Qreq->run === null || !check_post())
    quit("Permission error");
else if (!$Info->can_view_repo_contents())
    quit("Unconfirmed repository");

// we’re gonna run it; check permission
if (!$Me->can_run($Pset, $Runner, $User))
    quit("You can’t run that command");

// extract request info
$Rstate = new RunnerState($Info, $Runner);
$Rstate->set_queueid($Qreq->get("queueid"));

// recent or checkup
if ($Qreq->check)
    json_exit($Rstate->check($Qreq));

// ensure
if ($Qreq->ensure) {
    $answer = $Rstate->check(new Qrequest("GET", ["check" => "recent"]));
    if ($answer->ok || !get($answer, "run_empty"))
        json_exit($answer);
}

// check runnability
if (!$Pset->run_dirpattern)
    quit("Configuration error (run_dirpattern)");
if (!$Pset->run_jailfiles)
    quit("Configuration error (run_jailfiles)");

// queue
$Queue = $Rstate->make_queue();
if ($Queue && !$Queue->runnable)
    json_exit(["onqueue" => true, "queueid" => $Queue->queueid, "nahead" => $Queue->nahead, "headage" => ($Queue->head_runat ? $Now - $Queue->head_runat : null)]);


// maybe eval
if (!$Runner->command && $Runner->eval) {
    $Rstate->set_checkt(time());
    $json = $Rstate->generic_json();
    $json->done = true;
    $json->status = "done";
    $Rstate->evaluate($json);
    json_exit($json);
}


// otherwise run
try {
    if ($Rstate->is_recent_job_running())
        quit("Recent job still running");
    session_write_close();

    // run
    $Rstate->start($Queue);

    // save information about execution
    $Info->update_commit_info(["run" => [$Runner->category => $Rstate->checkt]]);

    json_exit(["ok" => true,
               "done" => false,
               "status" => "working",
               "repoid" => $Info->repo->repoid,
               "pset" => $Info->pset->id,
               "timestamp" => $Rstate->checkt]);
} catch (Exception $e) {
    quit($e->getMessage());
}
