<?php
// raw.php -- Peteramati file download page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty()) {
    $Me->escape();
}
global $User, $Pset, $Info, $Commit, $Qreq;

/** @return PsetView */
function raw_user_pset_info() {
    global $Conf, $User, $Pset, $Me, $Info, $Commit, $Qreq;
    $Info = PsetView::make($Pset, $User, $Me, $Qreq->newcommit ?? $Qreq->commit);
    if (!$Info->hash()) {
        json_exit(["ok" => false, "error" => $Info->repo ? "No repository." : "Commit " . htmlspecialchars($Qreq->newcommit ?? $Qreq->commit) . " isn’t connected to this repository."]);
    }
    return $Info;
}

ContactView::set_path_request(array("/@", "/@/p", "/@/p/h/f", "/@/p/f",
                                    "/p/h/f", "/p/f"));


// user, pset, runner
$User = $Me;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq->u))) {
    exit;
}
assert($User == $Me || $Me->isPC);

$Pset = ContactView::find_pset_redirect($Me, $Qreq->pset);

// repo
$Info = raw_user_pset_info();
$Repo = $Info->repo;
$Commit = $Info->commit_hash();
if (!$Repo || !$Commit || !$Info->can_view_repo_contents() || !$Qreq->file) {
    exit;
}

// file
$result = $Repo->gitrun("git cat-file blob $Commit:" . escapeshellarg($Qreq->file));
if ($result === null || $result === "") {
    $sizeresult = $Repo->gitrun("git cat-file -s $Commit:" . escapeshellarg($Qreq->file));
    if (trim($sizeresult) !== "0") {
        exit;
    }
}

// when commit is named, object doesn't change
if ($Qreq->commit) {
    header("Cache-Control: public, max-age=315576000");
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 315576000) . " GMT");
}

// filetype determination
$slash = strrpos($Qreq->file, "/");
$filename = substr($Qreq->file, $slash === false ? 0 : $slash + 1);
$dot = strrpos($filename, ".");
$ext = ($dot === false ? "" : strtolower(substr($filename, $dot + 1)));
if ($ext === "txt" || strcasecmp($filename, "README") === 0) {
    $mimetype = Mimetype::TXT_TYPE;
} else if ($ext === "html") {
    $mimetype = Mimetype::HTML_TYPE;
} else {
    $mimetype = Mimetype::content_type($result);
}

header("Content-Type: " . Mimetype::type_with_charset($mimetype));
if (zlib_get_coding_type() === false) {
    header("Content-Length: " . strlen($result));
}

// Accept header checking
if (isset($_SERVER["HTTP_ACCEPT"])) {
    $acceptable = false;
    foreach (explode(",", $_SERVER["HTTP_ACCEPT"]) as $type_params) {
        $semi = strpos($type_params, ";");
        $want = trim($semi === false ? $type_params : substr($type_params, 0, $semi));
        if ($want === "*/*"
            || (str_ends_with($want, "/*") && str_starts_with($mimetype, substr($want, 0, -2)))
            || $want === $mimetype) {
            $acceptable = true;
            break;
        }
    }
    if (!$acceptable) {
        header("HTTP/1.0 406 Not Acceptable");
        exit;
    }
}

echo $result;
