<?php
// mailclasses.php -- HotCRP mail tool
// HotCRP is Copyright (c) 2006-2018 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class MailRecipients {

    private $usertype;
    public $pset = null;
    private $type = null;

    function __construct($contact, $type, $usertype) {
        global $Conf;
        if (preg_match(',\A(?:college|extension|students|all|pc)\z,', $usertype))
            $this->usertype = $usertype;
        else
            $this->usertype = "students";
        if (preg_match(',\A(\w+):(openrepo|brokenrepo|repo|norepo|workingrepo|partner|nopartner)\z,', $type, $m)
            && ($this->pset = $Conf->pset_by_key($m[1]))) {
            $this->type = $m[2];
            if ($this->usertype === "all")
                $this->usertype = "students";
        } else if (preg_match(',\A(?:students|all|pc(?::\S+)?)\z,', $type))
            $this->type = $this->usertype = $type;
        else
            $this->type = $this->usertype;
    }

    function selectors() {
        global $Conf;
        $sel = array("students" => "All students");
        foreach ($Conf->psets() as $pset)
            if ($pset->student_can_view()) {
                if (!$pset->gitless || $pset->partner)
                    $sel[] = array("optgroup", $pset->title);
                if (!$pset->gitless) {
                    $sel[$pset->urlkey . ":workingrepo"] = "$pset->title, working repo";
                    $sel[$pset->urlkey . ":brokenrepo"] = "$pset->title, broken repo";
                    $sel[$pset->urlkey . ":openrepo"] = "$pset->title, open repo";
                    $sel[$pset->urlkey . ":norepo"] = "$pset->title, no repo";
                }
                if ($pset->partner) {
                    $sel[$pset->urlkey . ":partner"] = "$pset->title, partner";
                    $sel[$pset->urlkey . ":nopartner"] = "$pset->title, no partner";
                }
            }
        $sel[] = array("optgroup");
        $sel["pc"] = "TFs";
        /*foreach (pcTags() as $t)
            if ($t != "pc")
                $sel["pc:$t"] = "#{$t} TFs";*/
        $sel["all"] = "All users";

        $usersel = array("all" => "All", "college" => "College", "extension" => "Extension");
        return Ht::select("recipients", $sel, $this->type, array("id" => "recipients", "onchange" => "setmailpsel(this)"))
            . " &nbsp;"
            . Ht::select("userrecipients", $usersel, $this->usertype, array("id" => "userrecipients"));
    }

    function unparse() {
        $t = array();
        if ($this->usertype == "college")
            $t[] = "College";
        else if ($this->usertype == "extension")
            $t[] = "Extension";
        else if ($this->usertype == "pc")
            $t[] = "TFs";
        else if (preg_match(',\Apc:\S+\z,', $this->usertype))
            $t[] = "#" . substr($this->usertype, 3) . " TFs";
        if ($this->pset) {
            $t[] = $this->pset->title;
            if ($this->type == "openrepo")
                $t[] = "open repository";
            else if ($this->type == "brokenrepo")
                $t[] = "broken repository";
            else if ($this->type == "workingrepo")
                $t[] = "working repository";
            else if ($this->type == "repo")
                $t[] = "repository";
            else if ($this->type == "norepo")
                $t[] = "no repository";
            else if ($this->type == "partner")
                $t[] = "partner";
            else if ($this->type == "nopartner")
                $t[] = "no partner";
        }
        if (!count($t))
            $t[] = ($this->usertype == "students" ? "All students" : "All");
        return join(", ", $t);
    }

    function query() {
        global $Conf, $papersel, $checkReviewNeedsSubmit;
        $columns = array("firstName, lastName, email, password, roles, c.contactId, ((roles&" . Contact::ROLE_PC . ")!=0) as isPC, preferredEmail, extension");

        // paper limit
        $where = $repojoin = array();
        if ($this->usertype == "college")
            $where[] = "(roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension and not dropped";
        else if ($this->usertype == "extension")
            $where[] = "(roles&" . Contact::ROLE_PCLIKE . ")=0 and extension and not dropped";
        else if ($this->usertype == "students")
            $where[] = "(roles&" . Contact::ROLE_PCLIKE . ")=0 and not dropped";
        else if ($this->usertype == "pc")
            $where[] = "(roles&" . Contact::ROLE_PC . ")!=0";
        else if (preg_match(',\Apc:\S+\z,', $this->usertype)) {
            $where[] = "(roles&" . Contact::ROLE_PC . ")!=0";
            $where[] = "contactTags like '% " . sqlq(substr($this->usertype, 3)) . " %'";
        }

        // pset
        if ($this->pset) {
            $repojoin[] = "left join ContactLink l on (l.cid=c.contactId and l.type=" . LINK_REPO . " and l.pset=" . $this->pset->id . ")
	left join Repository r on (r.repoid=l.link)
	left join RepositoryGrade rg on (rg.repoid=r.repoid and rg.pset=" . $this->pset->id . " and not rg.placeholder)\n";
            $columns[] = "r.repoid repoid, rg.gradehash gradehash";

            if ($this->type == "openrepo")
                $where[] = "r.open";
            else if ($this->type == "brokenrepo")
                $where[] = "not r.working";
            else if ($this->type == "workingrepo")
                $where[] = "r.working";
            else if ($this->type == "repo")
                $where[] = "r.repoid is not null";
            else if ($this->type == "norepo")
                $where[] = "r.repoid is null";
            else if ($this->type == "partner") {
                $repojoin[] = "left join ContactLink pl on (pl.cid=c.contactId and pl.type=" . LINK_PARTNER . " and pl.pset=" . $this->pset->id . ")";
                $where[] = "pl.cid is not null";
            } else if ($this->type == "nopartner") {
                $repojoin[] = "left join ContactLink pl on (pl.cid=c.contactId and pl.type=" . LINK_PARTNER . " and pl.pset=" . $this->pset->id . ")";
                $where[] = "pl.cid is null";
            }
        }

        // build query
        $where[] = "email not regexp '^anonymous[0-9]*\$'";
        return "select " . join(", ", $columns)
            . " from ContactInfo c\n"
            . join(" ", $repojoin)
            . " where " . join(" and ", $where) . " order by email";
    }

}
