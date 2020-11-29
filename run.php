<?php
// run.php -- Peteramati runner page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty()) {
    $Me->escape();
}
global $User, $Pset, $Info, $Qreq;

function quit($err = null) {
    global $Conf;
    json_exit(["ok" => false, "error" => htmlspecialchars($err), "error_text" => $err]);
}

function user_pset_info() {
    global $Conf, $User, $Me, $Pset, $Info, $Qreq;
    $Info = PsetView::make($Pset, $User, $Me, $Qreq->newcommit ?? $Qreq->commit);
    if (!$Pset->gitless && !$Info->hash()) {
        quit(!$Info->repo ? "No repository" : "Commit " . ($Qreq->newcommit ?? $Qreq->commit) . " isn’t connected to this repository");
    }
    return $Info;
}

ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/p", "/p/h", "/p/u/h"));

// user, pset, runner
$User = $Me;
if ($Qreq->u !== null
    && !($User = ContactView::prepare_user($Qreq->u))) {
    json_exit(["ok" => false]);
}
assert($User == $Me || $Me->isPC);

$Pset = ContactView::find_pset_redirect($Qreq->pset);

$Runner = null;
foreach ($Pset->runners as $r) {
    if ($r->name == $Qreq->run)
        $Runner = $r;
}
$RunMany = $Me->isPC && $Qreq->runmany && $Qreq->valid_post();
if (!$Runner) {
    quit("No such command");
} else if (!$Me->can_view_run($Pset, $Runner, $RunMany ? null : $User)) {
    if (!$Me->isPC && !$Runner->visible) {
        quit("Command reserved for TFs");
    } else if ($Runner->disabled) {
        quit("Command disabled");
    } else {
        quit("Can’t run command right now");
    }
}

// magic multi-runner
if ($Me->isPC && $Qreq->runmany && $Qreq->valid_post()) {
    $t = $Pset->title;
    if ($Qreq->ensure) {
        $t .= " Ensure";
    }
    $t .= " " . $Runner->title;
    $Conf->header(htmlspecialchars($t), "home");

    echo '<h2 id="runmany61_who"></h2>',
        Ht::form($Conf->hoturl_post("run")),
        '<div class="f-contain">',
        Ht::hidden("u", ""),
        Ht::hidden("pset", $Pset->urlkey);
    if ($Qreq->ensure) {
        echo Ht::hidden("ensure", 1);
    }
    echo Ht::hidden("run", $Runner->name, ["id" => "runmany61", "data-pa-run-category" => $Runner->category_argument()]),
        '</div></form>';

    echo '<div id="pa-runout-' . $Runner->category . '">',
        '<div class="pa-run pa-run-short" id="pa-run-' . $Runner->category . '">',
        '<pre class="pa-runpre"></pre></div>',
        '</div>';

    echo '<div id="runmany61_users">',
        htmlspecialchars($Qreq->users),
        '</div>';

    Ht::stash_script('$pa.runmany()');
    echo '<div class="clear"></div>', "\n";
    $Conf->footer();
    exit;
}

// repo
$Info = user_pset_info();

// can we run this?
if ($Qreq->run === null || !$Qreq->valid_post()) {
    quit("Permission error");
} else if ($Runner->command) {
    if (!$Info->repo) {
        quit("No repository to run");
    } else if (!$Info->commit()) {
        quit("No commit to run");
    } else if (!$Info->can_view_repo_contents()) {
        quit("Unconfirmed repository");
    }
}

// we’re gonna run it; check permission
if (!$Me->can_run($Pset, $Runner, $User)) {
    quit("You can’t run that command");
}

// extract request info
$Rstate = new RunnerState($Info, $Runner);
$Rstate->set_queueid($Qreq->get("queueid"));

// recent or checkup
if ($Qreq->check) {
    json_exit($Rstate->check($Qreq));
}

// ensure
if ($Qreq->ensure) {
    $answer = $Rstate->check(new Qrequest("GET", ["check" => "recent"]));
    if ($answer->ok || !get($answer, "run_empty"))
        json_exit($answer);
}

// check runnability
if ($Runner->command && !$Pset->run_dirpattern) {
    quit("Configuration error (run_dirpattern)");
}
if ($Runner->command && !$Pset->run_jailfiles) {
    quit("Configuration error (run_jailfiles)");
}

// queue
$Queue = $Rstate->make_queue();
if ($Queue && !$Queue->runnable) {
    json_exit(["onqueue" => true, "queueid" => $Queue->queueid, "nahead" => $Queue->nahead, "headage" => ($Queue->head_runat ? Conf::$now - $Queue->head_runat : null)]);
}


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
    if ($Rstate->is_recent_job_running()) {
        quit("Recent job still running");
    } else if ($Info->pset->gitless) {
        quit("Nothing to do");
    }
    session_write_close();

    // run
    $Rstate->start($Queue);

    // save information about execution
    $Info->update_commit_notes(["run" => [$Runner->category => $Rstate->checkt]]);

    json_exit(["ok" => true,
               "done" => false,
               "status" => "working",
               "repoid" => $Info->repo->repoid,
               "pset" => $Info->pset->id,
               "timestamp" => $Rstate->checkt]);
} catch (Exception $e) {
    quit($e->getMessage());
}
