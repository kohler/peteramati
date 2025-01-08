<?php
// cs61mailer.php -- Peteramati mail template manager
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class CS61Mailer extends Mailer {

    protected $permissionContact = null;
    protected $contacts = array();

    protected $row;
    /** @var ?Pset */
    protected $pset;
    /** @var ?PsetView */
    protected $_info;

    protected $_tagless = [];
    protected $_tags = [];


    function __construct($recipient = null, $row = null, $rest = []) {
        parent::__construct();
        $this->reset($recipient, $row, $rest);
    }

    /** @param ?Contact $recipient
     * @param array<string,mixed> $rest */
    function reset($recipient = null, $row = null, $rest = []) {
        global $Me, $Opt;
        parent::reset($recipient, $rest);
        $this->permissionContact = $rest["permissionContact"] ?? $this->recipient;
        $this->row = $row;
        $this->pset = $rest["pset"] ?? null;
        $this->_info = null;
        // Do not put passwords in email that is cc'd elsewhere
        if ((!$Me || !$Me->privChair || @$Opt["chairHidePasswords"])
            && (@$rest["cc"] || @$rest["bcc"])
            && (@$rest["sensitive"] === null || @$rest["sensitive"] === "display")) {
            $this->sensitive = "high";
        }
    }


    /** @return ?PsetView */
    private function get_pset_info() {
        if (!$this->_info && $this->pset) {
            $this->_info = PsetView::make($this->pset, $this->recipient, $this->permissionContact);
        }
        return $this->_info;
    }


    function expandvar_generic($what, $isbool) {
        global $Conf, $Opt;
        $len = strlen($what);
        if ($len > 12 && substr($what, 0, 10) == "%DEADLINE(" && substr($what, $len - 2) == ")%") {
            $inner = substr($what, 10, $len - 12);
            if ($isbool) {
                return $Conf->setting($inner) > 0;
            } else {
                return $Conf->unparse_setting_time($inner);
            }
        }

        if ($what == "%AUTHORVIEWCAPABILITY%" && @$Opt["disableCapabilities"])
            return "";

        return self::EXPANDVAR_CONTINUE;
    }

    function expandvar_recipient($what, $isbool) {
        global $Conf;

        // rest is only there if we have a pset
        if (!$this->pset)
            return self::EXPANDVAR_CONTINUE;

        if ($what == "%PSET%" || $what == "%TITLE%")
            return $this->pset->title;

        if ($what == "%REPO%") {
            if ($this->pset->gitless)
                return $isbool ? false : self::EXPANDVAR_CONTINUE;
            $info = $this->get_pset_info();
            if (!$info || !$info->repo)
                return $isbool ? false : "(no repo)";
            return $info->repo->https_url();
        }

        if ($what == "%PARTNER%") {
            if (!$this->pset->partner)
                return $isbool ? false : self::EXPANDVAR_CONTINUE;
            $info = $this->get_pset_info();
            if (!$info || !$info->partner)
                return $isbool ? false : "N/A";
            return Text::name_text($info->partner);
        }

        if (preg_match(',\A%(?:COMMIT(?:|HASH|ABBREV|TITLE|DATE)|LATEHOURS)%\z,', $what)) {
            if ($this->pset->gitless)
                return $isbool ? false : self::EXPANDVAR_CONTINUE;
            $info = $this->get_pset_info();
            $recent = $info && $info->hash() ? $info->commit() : null;
            if (!$recent) {
                if ($isbool) {
                    return false;
                } else if ($what == "%COMMITABBREV%" || $what == "%COMMITDATE%") {
                    return "N/A";
                } else {
                    return "(no commit)";
                }
            } else if ($what == "%COMMITHASH%") {
                return $recent->hash;
            } else if ($what == "%COMMITABBREV%") {
                return substr($recent->hash, 0, 7);
            } else if ($what == "%COMMITTITLE%") {
                return $recent->subject ? : "(empty)";
            } else if ($what == "%COMMIT%") {
                $subject = UnicodeHelper::utf8_prefix($recent->subject, 72);
                if (strlen($subject) != strlen($recent->subject)) {
                    $subject .= "...";
                }
                return substr($recent->hash, 0, 7) . ($subject === "" ? "" : " $subject");
            } else if ($what == "%COMMITDATE%") {
                return date("Y/m/d H:i:s", $recent->commitat);
            } else if ($what == "%LATEHOURS%") {
                // XXX should use PsetView::late_hours
                if ($this->pset->deadline_extension
                    && ($this->recipient->extension
                        || ($info->partner && $info->partner->extension))) {
                    $deadline = $this->pset->deadline_extension;
                } else if ($this->pset->deadline_college) {
                    $deadline = $this->pset->deadline_college;
                } else {
                    $deadline = $this->pset->deadline;
                }
                if (!$deadline || $recent->commitat <= $deadline) {
                    return $isbool ? false : "0";
                } else {
                    return (string) (int) (($recent->commitat - $deadline + 3599) / 3600);
                }
            }
        }
        if ($what == "%GRADEENTRIES%") {
            $info = $this->get_pset_info();
            if (!$info->can_view_some_grade()) {
                return $isbool ? false : "";
            }
            $t = "";
            $total = $maxtotal = 0; // XXX better computation
            foreach ($this->pset->grades as $ge) {
                $g = $info->grade_value($ge);
                if ($ge->is_extra ? $g : $g !== null) {
                    $t .= $ge->title . ": " . ($g ? : 0);
                    if ($ge->max && $ge->max_visible !== false)
                        $t .= " / " . $ge->max;
                    $t .= "\n";
                }
                if ($g && !$ge->no_total)
                    $total += $g;
                if (!$ge->is_extra && !$ge->no_total && $ge->max_visible !== false)
                    $maxtotal += $ge->max;
            }
            if ($total || $maxtotal) {
                $t .= "TOTAL: " . $total;
                if ($maxtotal)
                    $t .= " / " . $maxtotal;
                $t .= "\n";
            }
            return $t;
        }

        return self::EXPANDVAR_CONTINUE;
    }


    protected function unexpanded_warning() {
        $m = parent::unexpanded_warning();
        foreach ($this->_unexpanded as $t => $x)
            if (preg_match(',\A%(?:NUMBER|TITLE|PAPER|AUTHOR|REVIEW|COMMENT),', $t))
                $m .= " Paper-specific keywords like <code>" . htmlspecialchars($t) . "</code> weren’t recognized because this set of recipients is not linked to a paper collection.";
        if (isset($this->_unexpanded["%AUTHORVIEWCAPABILITY%"]))
            $m .= " Author view capabilities weren’t recognized because this mail isn’t meant for paper authors.";
        return $m;
    }

    /** @return int */
    function nwarnings() {
        return count($this->_unexpanded) + count($this->_tagless);
    }

    function warnings() {
        $e = array();
        if (count($this->_unexpanded))
            $e[] = $this->unexpanded_warning();
        return $e;
    }

    static function preparation_differs($prep1, $prep2) {
        return parent::preparation_differs($prep1, $prep2);
    }


    static function send_to($recipient, $template, $rest = array()) {
        if (!($recipient["disabled"] ?? false)) {
            $mailer = new CS61Mailer($recipient, null, $rest);
            if (($prep = $mailer->make_preparation($template, $rest)))
                self::send_preparation($prep);
        }
    }
}

// load mail templates, including local ones if any
global $Opt;
require_once(SiteLoader::$root . "/src/mailtemplate.php");
if ((@include (SiteLoader::$root . "/conf/mailtemplate-local.php")) !== false)
    /* do nothing */;
