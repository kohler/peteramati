<?php
// api.php -- HotCRP JSON API access page
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

class APIRequest {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;

        if ($qreq->base !== null) {
            $this->conf->set_siteurl($qreq->base);
        }
        if ($qreq->path_component(0)
            && substr($qreq->path_component(0), 0, 1) === "~") {
            $qreq->u = substr(urldecode($qreq->shift_path_components(1)), 2);
        }
        if (($x = $qreq->shift_path_components(1))) {
            $qreq->fn = urldecode(substr($x, 1));
        }
        if (($x = $qreq->shift_path_components(1))) {
            if (!$qreq->pset) {
                $qreq->pset = urldecode(substr($x, 1));
            }
            if (($x = $qreq->shift_path_components(1))) {
                if (!$qreq->commit) {
                    $qreq->commit = urldecode(substr($x, 1));
                }
            }
        }
    }

    function run() {
        $api = new APIData($this->viewer);

        // check user
        if ($this->qreq->u) {
            $user = $this->conf->user_by_whatever($this->qreq->u);
            if (!$this->viewer->isPC
                && (!$user || $user->contactId !== $this->viewer->contactId)) {
                return ["ok" => false, "error" => "Permission denied"];
            } else if (!$user) {
                return ["ok" => false, "error" => "User not found"];
            } else {
                $user->set_anonymous(substr($this->qreq->u, 0, 5) === "[anon");
                $api->user = $user;
            }
        }

        // check pset
        if ($this->qreq->pset
            && !($api->pset = $this->conf->pset_by_key($this->qreq->pset))) {
            return ["ok" => false, "error" => "Pset not found"];
        }
        if ($api->pset && $api->pset->disabled && !$this->viewer->privChair) {
            if ($this->viewer->isPC) {
                return ["ok" => false, "error" => "Pset disabled"];
            } else {
                return ["ok" => false, "error" => "Pset not found"];
            }
        } else if ($api->pset && !$api->pset->visible && !$this->viewer->isPC) {
            return ["ok" => false, "error" => "Pset not found"];
        }

        // check commit
        if ($api->pset && !$api->pset->gitless && !$this->viewer->is_empty()) {
            $api->repo = $api->user->repo($api->pset);
            $api->branch = $api->user->branch($api->pset);
        }
        if ($api->repo && $this->qreq->commit) {
            $api->hash = $this->qreq->commit;
        }

        // call api
        $uf = $this->conf->api($this->qreq->fn);
        if (!$uf
            && $api->pset) {
            $uf = $api->pset->api[$this->qreq->fn] ?? null;
        }
        if (!$uf
            && is_object($this->conf->config->_api ?? null)) {
            $uf = $this->conf->config->_api->{$this->qreq->fn} ?? null;
        }
        if ($uf
            && $api->pset
            && $api->pset->disabled
            && !($uf->anypset ?? false)) {
            return ["ok" => false, "error" => "Pset disabled"];
        }
        if ($uf
            && ($uf->redirect ?? false)
            && $this->qreq->redirect
            && preg_match('/\A(?![a-z]+:|\/).+/', $this->qreq->redirect)) {
            try {
                JsonResultException::$capturing = true;
                $j = $this->conf->call_api($uf, $this->viewer, $this->qreq, $api);
            } catch (JsonResultException $ex) {
                $j = $ex->result;
            }
            if (is_object($j) && $j instanceof JsonResult) {
                $j = $j->content;
            }
            if (!($j->ok ?? false) && !($j->error ?? false)) {
                Conf::msg_error("Internal error");
            } else if (($x = $j->error ?? false)) {
                Conf::msg_error(htmlspecialchars($x));
            } else if (($x = $j->error_html ?? false)) {
                Conf::msg_error($x);
            }
            $this->conf->redirect($this->conf->make_absolute_site($this->qreq->redirect));
        } else {
            return $this->conf->call_api($uf, $this->viewer, $this->qreq, $api);
        }
    }
}

json_exit((new APIRequest($Me, $Qreq))->run());
