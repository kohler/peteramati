<?php
// raw.php -- Peteramati file download page
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Psetid, $Info, $Commit, $RecentCommits;

function quit($err = null) {
    global $Conf;
    $Conf->ajaxExit(array("error" => true, "message" => $err));
}

function user_pset_info() {
    global $Conf, $User, $Pset, $Info, $Commit;
    $Info = ContactView::user_pset_info($User, $Pset);
    if (($Commit = @$_REQUEST["newcommit"]) == null)
        $Commit = @$_REQUEST["commit"];
    if (!$Info->set_commit($Commit))
        $Conf->ajaxExit(array("ok" => false, "error" => $Info->repo ? "No repository." : "Commit " . htmlspecialchars($Commit) . " isn’t connected to this repository."));
    return $Info;
}

ContactView::set_path_request(array("/@", "/@/p", "/@/p/h/f", "/@/p/f",
                                    "/p/h/f", "/p/f"));

// user, pset, runner
$User = $Me;
if (isset($_REQUEST["u"])
    && !($User = ContactView::prepare_user($_REQUEST["u"])))
    exit;
assert($User == $Me || $Me->isPC);

$Pset = ContactView::find_pset_redirect(@$_REQUEST["pset"]);
$Psetid = $Pset->id;

// repo
$Info = user_pset_info();
$Repo = $Info->repo;
$Commit = $Info->commit_hash();
$RecentCommits = $Info->recent_commits();
if (!$Repo || !$Commit || !$Info->can_view_repo_contents || !@$_REQUEST["file"])
    exit;

// file
$result = Contact::repo_gitrun($Repo, "git cat-file blob $Commit:" . escapeshellarg($_REQUEST["file"]));
if ($result === null || $result === "") {
    $sizeresult = Contact::repo_gitrun($Repo, "git cat-file -s $Commit:" . escapeshellarg($_REQUEST["file"]));
    if (trim($sizeresult) !== "0")
        exit;
}

// filetype determination
$slash = strrpos($_REQUEST["file"], "/");
$filename = substr($_REQUEST["file"], $slash === false ? 0 : $slash + 1);
$dot = strrpos($filename, ".");
$ext = ($dot === false ? "" : strtolower(substr($filename, $dot + 1)));

if ($ext == "pdf" && substr($result, 0, 5) === "%PDF-")
    header("Content-Type: application/pdf");
else if ($ext == "txt" || strcasecmp($filename, "README") == 0)
    header("Content-Type: text/plain");
else if ($ext == "png" && substr($result, 0, 8) === "\x89PNG\x0d\x0a\x1a\x0a")
    header("Content-Type: image/png");
else if ($ext == "gif" && (substr($result, 0, 6) === "GIF87a"
                           || substr($result, 0, 6) === "GIF89a"))
    header("Content-Type: image/gif");
else if ($ext == "html")
    header("Content-Type: text/html");
else
    header("Content-Type: application/octet-stream");

// when commit is named, object doesn't change
if (@$_REQUEST["commit"]) {
    session_cache_limiter("");
    header("Cache-Control: public, max-age=315576000");
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 315576000) . " GMT");
}

echo $result;
