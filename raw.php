<?php
// raw.php -- Peteramati file download page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info, $Commit, $Qreq;

function quit($err = null) {
    global $Conf;
    json_exit(["error" => true, "message" => $err]);
}

function user_pset_info() {
    global $Conf, $User, $Pset, $Me, $Info, $Commit, $Qreq;
    $Info = PsetView::make($Pset, $User, $Me);
    if (($Commit = $Qreq->newcommit) == null)
        $Commit = $Qreq->commit;
    if (!$Info->set_hash($Commit))
        json_exit(["ok" => false, "error" => $Info->repo ? "No repository." : "Commit " . htmlspecialchars($Commit) . " isn’t connected to this repository."]);
    return $Info;
}

ContactView::set_path_request(array("/@", "/@/p", "/@/p/h/f", "/@/p/f",
                                    "/p/h/f", "/p/f"));
$Qreq = make_qreq();

// user, pset, runner
$User = $Me;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq->u)))
    exit;
assert($User == $Me || $Me->isPC);

$Pset = ContactView::find_pset_redirect($Qreq->pset);

// repo
$Info = user_pset_info();
$Repo = $Info->repo;
$Commit = $Info->commit_hash();
if (!$Repo || !$Commit || !$Info->can_view_repo_contents() || !$Qreq->file)
    exit;

// file
$result = $Repo->gitrun("git cat-file blob $Commit:" . escapeshellarg($Qreq->file));
if ($result === null || $result === "") {
    $sizeresult = $Repo->gitrun("git cat-file -s $Commit:" . escapeshellarg($Qreq->file));
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
if ($Qreq->commit) {
    session_cache_limiter("");
    header("Cache-Control: public, max-age=315576000");
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 315576000) . " GMT");
}

echo $result;
