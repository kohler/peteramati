<?php
// mailer.php -- HotCRP mail template manager
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Mailer {
    const CONTEXT_BODY = 0;
    const CONTEXT_HEADER = 1;
    const CONTEXT_EMAIL = 2;

    const CENSOR_NONE = 0;
    const CENSOR_DISPLAY = 1;
    const CENSOR_ALL = 2;

    public static $email_fields = ["to" => "To", "cc" => "Cc", "bcc" => "Bcc", "reply-to" => "Reply-To"];
    public static $template_fields = ["to", "cc", "bcc", "reply-to", "subject", "body"];

    /** @var Conf */
    public $conf;
    /** @var ?Contact */
    protected $recipient;
    /** @var string */
    protected $eol;

    /** @var int */
    protected $width;
    /** @var bool */
    protected $flowed = false;
    /** @var int */
    protected $censor;
    /** @var ?string */
    protected $reason;
    /** @var bool */
    protected $adminupdate = false;
    /** @var ?string */
    protected $notes;
    /** @var ?string */
    public $capability_token;
    /** @var bool */
    protected $sensitive;

    /** @var ?MailPreparation */
    protected $preparation;
    /** @var int */
    protected $context = 0;
    /** @var ?string */
    protected $line_prefix;

    /** @var array<string,true> */
    private $_unexpanded = [];
    /** @var list<string> */
    protected $_errors_reported = [];
    /** @var ?MessageSet */
    private $_ms;

    /** @param ?Contact $recipient
     * @param array{width?:int,censor?:0|1|2,capability_token?:string,sensitive?:bool} $settings */
    function __construct(Conf $conf, $recipient = null, $settings = []) {
        $this->conf = $conf;
        $this->eol = $conf->opt("postfixEOL") ?? "\r\n";
        $this->flowed = !!$this->conf->opt("mailFormatFlowed");
        $this->reset($recipient, $settings);
    }

    /** @param ?Contact $recipient
     * @param array{width?:int,censor?:0|1|2,capability_token?:string,sensitive?:bool} $settings */
    function reset($recipient = null, $settings = []) {
        $this->recipient = $recipient;
        $this->width = $settings["width"] ?? 72;
        if ($this->width <= 0) {
            $this->width = 10000000;
        }
        $this->censor = $settings["censor"] ?? self::CENSOR_NONE;
        $this->reason = $settings["reason"] ?? null;
        $this->adminupdate = $settings["adminupdate"] ?? false;
        $this->notes = $settings["notes"] ?? null;
        $this->capability_token = $settings["capability_token"] ?? null;
        $this->sensitive = $settings["sensitive"] ?? false;
    }


    /** @param Contact $contact
     * @param string $out
     * @return string */
    function expand_user($contact, $out) {
        $r = Text::analyze_name($contact);
        if (is_object($contact)
            && ($contact->preferredEmail ?? "") != "") {
            $r->email = $contact->preferredEmail;
        }

        // maybe infer username
        if ($r->firstName === ""
            && $r->lastName === ""
            && $r->email !== "") {
            $this->infer_user_name($r, $contact);
        }

        $flags = $this->context === self::CONTEXT_EMAIL ? NAME_MAILQUOTE : 0;
        if ($r->email !== "") {
            $email = $r->email;
        } else {
            $email = "none";
            $flags |= NAME_B;
        }

        if ($out === "EMAIL") {
            return $flags & NAME_B ? "<{$email}>" : $email;
        } else if ($out === "CONTACT") {
            return Text::name($r->firstName, $r->lastName, $email, $flags | NAME_E);
        } else if ($out === "NAME") {
            if ($this->context !== self::CONTEXT_EMAIL) {
                $flags |= NAME_P;
            }
            return Text::name($r->firstName, $r->lastName, $email, $flags);
        } else if ($out === "FIRST") {
            return Text::name($r->firstName, "", "", $flags);
        } else if ($out === "LAST") {
            return Text::name("", $r->lastName, "", $flags);
        } else {
            return "";
        }
    }

    function infer_user_name($r, $contact) {
    }


    function kw_opt($args, $isbool) {
        $yes = $this->expandvar($args, true);
        if ($yes && !$isbool) {
            return $this->expandvar($args, false);
        }
        return $yes;
    }

    function kw_urlenc($args, $isbool) {
        $hasinner = $this->expandvar($args, true);
        if ($hasinner && !$isbool) {
            return urlencode($this->expandvar($args, false));
        }
        return $hasinner;
    }

    function kw_recipient($args, $isbool, $reciptype) {
        if ($this->preparation) {
            $this->preparation->preparation_owner = $this->recipient->email;
        }
        return $this->expand_user($this->recipient, $reciptype);
    }

    function kw_capability($args, $isbool) {
        if ($this->capability_token) {
            $this->sensitive = true;
        }
        return $isbool || $this->capability_token ? $this->capability_token : "";
    }

    function kw_login($args, $isbool, $name) {
        if (!$this->recipient) {
            return $this->conf->login_type() ? false : null;
        }

        $loginparts = "";
        if (($lt = $this->conf->login_type()) === null || $lt === "ldap") {
            $loginparts = "email=" . urlencode($this->recipient->email);
        }
        if ($name === "LOGINURL") {
            return $this->conf->opt("paperSite") . "/signin/" . ($loginparts ? "?{$loginparts}" : "");
        } else if ($name === "LOGINURLPARTS") {
            return $loginparts;
        }
        $pwd = $lt === null ? $this->recipient->plaintext_password() : null;
        if (!$pwd) {
            return $pwd;
        }
        $this->sensitive = true;
        return $this->censor ? "HIDDEN" : $pwd;
    }

    function kw_needpassword($args, $isbool) {
        if ($this->conf->login_type() || $this->censor) {
            return false;
        } else if ($this->recipient) {
            return $this->recipient->password_unset();
        } else {
            return null;
        }
    }

    function kw_passwordlink($args, $isbool) {
        if (!$this->recipient) {
            return $this->conf->login_type() ? false : null;
        } else if ($this->censor === self::CENSOR_ALL) {
            return null;
        }
        $this->sensitive = true;
        if (!$this->censor && !$this->preparation->reset_capability) {
            if ($this->recipient->contactDbId && $this->recipient->prefer_contactdb_password()) {
                $capmgr = $this->conf->capability_manager("U");
            } else {
                $capmgr = $this->conf->capability_manager();
            }
            $this->preparation->reset_capability = $capmgr->create(CAPTYPE_RESETPASSWORD, ["user" => $this->recipient, "timeExpires" => time() + 259200]);
        }
        $token = $this->censor ? "HIDDEN" : $this->preparation->reset_capability;
        return $this->conf->hoturl_raw("resetpassword", null, Conf::HOTURL_ABSOLUTE | Conf::HOTURL_NO_DEFAULTS) . "/" . urlencode($token);
    }

    /** @param string $what
     * @param bool $isbool
     * @return null|bool|string */
    function expandvar($what, $isbool) {
        if (str_ends_with($what, ")") && ($paren = strpos($what, "("))) {
            $name = substr($what, 0, $paren);
            $args = substr($what, $paren + 1, strlen($what) - $paren - 2);
        } else {
            $name = $what;
            $args = "";
        }

        // generic expansions: OPT, URLENC
        $len = strlen($name);
        if ($name === "OPT" && $args) {
            return $this->kw_opt($args, $isbool);
        } else if ($name === "URLENC" && $args) {
            return $this->kw_urlenc($args, $isbool);
        } else if ($name === "CONFNAME") {
            return $this->conf->full_name();
        } else if ($name === "CONFSHORTNAME") {
            return $this->conf->short_name;
        } else if ($name === "CONFLONGNAME") {
            return $this->conf->long_name;
        } else if ($name === "SIGNATURE") {
            return $this->conf->opt("emailSignature") ? : "- " . $this->conf->short_name . " Submissions";
        } else if ($name === "ADMIN" || $name === "SITECONTACT") {
            return $this->expand_user($this->conf->site_contact(), "CONTACT");
        } else if ($name === "ADMINNAME") {
            return $this->expand_user($this->conf->site_contact(), "NAME");
        } else if ($name === "ADMINEMAIL" || $name === "SITEEMAIL") {
            return $this->expand_user($this->conf->site_contact(), "EMAIL");
        } else if ($name === "URL") {
            if ($args === "") {
                return $this->conf->opt("paperSite");
            }
            $a = preg_split('/\s*,\s*/', substr($what, 5, $len - 7));
            for ($i = 0; $i < count($a); ++$i) {
                $a[$i] = $this->expand($a[$i], "urlpart");
                $a[$i] = preg_replace('/\&(?=\&|\z)/', "", $a[$i]);
            }
            return $this->conf->hoturl_absolute($a[0], isset($a[1]) ? $a[1] : "", Conf::HOTURL_NO_DEFAULTS);
        } else if ($name === "PHP") {
            return Navigation::get()->php_suffix;
        } else if (in_array($name, ["CONTACT", "NAME", "EMAIL", "FIRST", "LAST"])) {
            if (!$this->recipient) {
                return null;
            }
            return $this->kw_recipient($args, $isbool, $name);
        } else if ($name === "REASON" || $name === "ADMINUPDATE" || $name === "NOTES") {
            $which = strtolower($name);
            $value = $this->$which;
            if ($value === null && !$this->recipient) {
                return $isbool ? null : $what;
            } else if ($name === "ADMINUPDATE") {
                return $value ? "An administrator performed this update. " : "";
            } else {
                return $value === null ? "" : $value;
            }
        } else if ($name === "LOGINURL" || $name === "LOGINURLPARTS" || $name === "PASSWORD") {
            return $this->kw_login($args, $isbool, $name);
        } else if ($name === "PASSWORDLINK") {
            return $this->kw_passwordlink($args, $isbool);
        } else if ($name === "CAPABILITY") {
            return $this->kw_capability($args, $isbool);
        }

        // fallback
        if ($isbool) {
            return false;
        }
        if (!isset($this->_unexpanded[$what])) {
            $this->_unexpanded[$what] = true;
        }
        return null;
    }


    private function _pushIf(&$ifstack, $text, $yes) {
        if ($yes !== false && $yes !== true && $yes !== null)
            $yes = (bool) $yes;
        if ($yes === true || $yes === null)
            array_push($ifstack, $yes);
        else
            array_push($ifstack, $text);
    }

    private function _popIf(&$ifstack, &$text) {
        if (count($ifstack) == 0)
            return null;
        else if (($pop = array_pop($ifstack)) === true || $pop === null)
            return $pop;
        else {
            $text = $pop;
            return false;
        }
    }

    private function _handleIf(&$ifstack, &$text, $cond, $haselse) {
        assert($cond || $haselse);
        if ($haselse) {
            $yes = $this->_popIf($ifstack, $text);
            if ($yes !== null)
                $yes = !$yes;
        } else
            $yes = true;
        if ($yes && $cond)
            $yes = $this->expandvar(substr($cond, 1, strlen($cond) - 2), true);
        $this->_pushIf($ifstack, $text, $yes);
        return $yes;
    }

    private function _expandConditionals($rest) {
        $text = "";
        $ifstack = array();

        while (preg_match('/\A(.*?)%(IF|ELSE?IF|ELSE|ENDIF)((?:\(#?[-a-zA-Z0-9!@_:.\/]+(?:\([-a-zA-Z0-9!@_:.\/]*+\))*\))?)%(.*)\z/s', $rest, $m)) {
            $text .= $m[1];
            $rest = $m[4];

            if ($m[2] == "IF" && $m[3] != "")
                $yes = $this->_handleIf($ifstack, $text, $m[3], false);
            else if (($m[2] == "ELSIF" || $m[2] == "ELSEIF") && $m[3] != "")
                $yes = $this->_handleIf($ifstack, $text, $m[3], true);
            else if ($m[2] == "ELSE" && $m[3] == "")
                $yes = $this->_handleIf($ifstack, $text, false, true);
            else if ($m[2] == "ENDIF" && $m[3] == "")
                $yes = $this->_popIf($ifstack, $text);
            else
                $yes = null;

            if ($yes === null)
                $text .= "%" . $m[2] . $m[3] . "%";
        }

        return $text . $rest;
    }

    /** @param string $line
     * @return string */
    private function _lineexpand($line, $indent) {
        $text = "";
        $pos = 0;
        while (preg_match('/\G(.*?)(%(#?[-a-zA-Z0-9!@_:.\/]+(?:|\([^\)]*\)))%)/s', $line, $m, 0, $pos)) {
            $text .= $m[1];
            // Don't expand keywords that look like they are coming from URLs
            if (strlen($m[3]) >= 2
                && ctype_xdigit(substr($m[3], 0, 2))
                && strlen($line) >= $pos + strlen($m[0]) + 2
                && ctype_xdigit(substr($line, $pos + strlen($m[0]), 2))
                && preg_match('/\/\/\S+\z/', $text)) {
                $s = null;
            } else {
                $s = $this->expandvar($m[3], false);
            }
            $text .= $s ?? $m[2];
            $pos += strlen($m[0]);
        }
        $text .= substr($line, $pos);
        return prefix_word_wrap($this->line_prefix ?? "", $text, $indent,
                                $this->width, $this->flowed);
    }

    /** @param string $text
     * @param ?string $field
     * @return string */
    function expand($text, $field = null) {
        // leave early on empty string
        if ($text === "") {
            return "";
        }

        // width, expansion type based on field
        $old_context = $this->context;
        $old_width = $this->width;
        $old_line_prefix = $this->line_prefix;
        if (isset(self::$email_fields[$field])) {
            $this->context = self::CONTEXT_EMAIL;
            $this->width = 10000000;
        } else if ($field !== "body" && $field != "") {
            $this->context = self::CONTEXT_HEADER;
            $this->width = 10000000;
        } else {
            $this->context = self::CONTEXT_BODY;
        }

        // expand out conditionals first to avoid confusion with wordwrapping
        $text = $this->_expandConditionals(cleannl($text));

        // separate text into lines
        $lines = explode("\n", $text);
        if (!empty($lines) && $lines[count($lines) - 1] === "") {
            array_pop($lines);
        }

        $text = "";
        for ($i = 0; $i < count($lines); ++$i) {
            $line = rtrim($lines[$i]);
            if ($line == "") {
                $text .= "\n";
            } else if (preg_match('/\A%((?:REVIEWS|COMMENTS)(?:|\(.*\)))%\z/s', $line, $m)) {
                if (($m = $this->expandvar($m[1], false)) != "") {
                    $text .= $m . "\n";
                }
            } else if (strpos($line, "%") === false) {
                $text .= prefix_word_wrap("", $line, 0, $this->width, $this->flowed);
            } else {
                if (($line[0] === " " || $line[0] === "\t" || $line[0] === "*")
                    && preg_match('/\A([ *\t]*)%(\w+(?:|\([^\)]*\)))%(: .*)\z/s', $line, $m)
                    && $this->expandvar($m[2], true)) {
                    $line = $m[1] . $this->expandvar($m[2], false) . $m[3];
                }
                if (($line[0] === " " || $line[0] === "\t" || $line[0] === "*")
                    && preg_match('/\A([ \t]*\*[ \t]+|[ \t]*.*?: (?=%))(.*?: |)(%(\w+(?:|\([^\)]*\)))%)\s*\z/s', $line, $m)
                    && ($tl = tab_width($m[1], true)) <= 20) {
                    $this->line_prefix = $m[1] . $m[2];
                    if (str_starts_with($m[4] ?? "", "OPT(")) {
                        if (($yes = $this->expandvar($m[4], true))) {
                            $text .= prefix_word_wrap($this->line_prefix, $this->expandvar($m[4], false), $tl, $this->width, $this->flowed);
                        } else if ($yes === null) {
                            $text .= $line . "\n";
                        }
                    } else {
                        $text .= $this->_lineexpand($m[3], $tl);
                    }
                    continue;
                }
                $this->line_prefix = "";
                $text .= $this->_lineexpand($line, 0);
            }
        }

        // lose newlines on header expansion
        if ($this->context !== self::CONTEXT_BODY) {
            $text = rtrim(preg_replace('/[\r\n\f\x0B]+/', ' ', $text));
        }

        $this->context = $old_context;
        $this->width = $old_width;
        $this->line_prefix = $old_line_prefix;
        return $text;
    }

    /** @param array|object $x
     * @return array<string,string> */
    function expand_all($x) {
        $r = [];
        foreach ((array) $x as $k => $t) {
            if (in_array($k, self::$template_fields))
                $r[$k] = $this->expand($t, $k);
        }
        return $r;
    }

    /** @return array<string,string> */
    function get_template($templateName, $default = false) {
        global $mailTemplates;
        $m = $mailTemplates[$templateName];
        if (!$default && $this->conf) {
            if (($t = $this->conf->setting_data("mailsubj_" . $templateName)) !== null) {
                $m["subject"] = $t;
            }
            if (($t = $this->conf->setting_data("mailbody_" . $templateName)) !== null) {
                $m["body"] = $t;
            }
        }
        return $m;
    }

    /** @param string $name
     * @param bool $use_default
     * @return array{body:string,subject:string} */
    function expand_template($name, $use_default = false) {
        return $this->expand_all($this->get_template($name, $use_default));
    }


    /** @return MailPreparation */
    function prepare($template, $rest = []) {
        assert($this->recipient && $this->recipient->email);
        $prep = new MailPreparation($this->conf, $this->recipient);
        $this->populate_preparation($prep, $template, $rest);
        return $prep;
    }

    function populate_preparation(MailPreparation $prep, $template, $rest = []) {
        // look up template
        if (is_string($template) && $template[0] === "@") {
            $template = (array) $this->get_template(substr($template, 1));
        }
        if (is_object($template)) {
            $template = (array) $template;
        }
        // add rest fields to template for expansion
        foreach (self::$email_fields as $lcfield => $field) {
            if (isset($rest[$lcfield]))
                $template[$lcfield] = $rest[$lcfield];
        }

        // look up recipient; use preferredEmail if set
        if (!$this->recipient || !$this->recipient->email) {
            throw new Exception("No email in Mailer::send");
        }
        if (!isset($this->recipient->contactId)) {
            error_log("no contactId in recipient\n" . debug_string_backtrace());
        }
        $mimetext = new MimeText($this->eol);

        // expand the template
        $this->preparation = $prep;
        $mail = $this->expand_all($template);
        $this->preparation = null;

        $mail["to"] = MailPreparation::recipient_address(($prep->recipients())[0]);
        $subject = $mimetext->encode_header("Subject: ", $mail["subject"]);
        $prep->subject = substr($subject, 9);
        $prep->body = $mail["body"];

        // parse headers
        $fromHeader = $this->conf->opt("emailFromHeader");
        if ($fromHeader === null) {
            $fromHeader = $mimetext->encode_email_header("From: ", $this->conf->opt("emailFrom"));
            $this->conf->set_opt("emailFromHeader", $fromHeader);
        }
        $prep->headers = [];
        if ($fromHeader) {
            $prep->headers["from"] = $fromHeader . $this->eol;
        }
        $prep->headers["subject"] = $subject . $this->eol;
        $prep->headers["to"] = "";
        foreach (self::$email_fields as $lcfield => $field) {
            if (($text = $mail[$lcfield] ?? "") === "" || $text === "<none>") {
                continue;
            }
            if (($hdr = $mimetext->encode_email_header($field . ": ", $text))) {
                $prep->headers[$lcfield] = $hdr . $this->eol;
            } else {
                $mimetext->mi->field = $lcfield;
                $mimetext->mi->landmark = "{$field} field";
                $prep->append_item($mimetext->mi);
                $logmsg = "{$lcfield}: {$text}";
                if (!in_array($logmsg, $this->_errors_reported)) {
                    error_log("mailer error on {$logmsg}");
                    $this->_errors_reported[] = $logmsg;
                }
            }
        }
        $prep->headers["mime-version"] = "MIME-Version: 1.0" . $this->eol;
        $prep->headers["content-type"] = "Content-Type: text/plain; charset=utf-8"
            . ($this->flowed ? "; format=flowed" : "") . $this->eol;
        $prep->sensitive = $this->sensitive;
        if ($prep->has_error() && !($rest["no_error_quit"] ?? false)) {
            $this->conf->feedback_msg($prep->message_list());
        }
    }

    /** @param list<MailPreparation> $preps */
    static function send_combined_preparations($preps) {
        $n = count($preps);
        for ($i = 0; $i !== $n; ++$i) {
            $p = $preps[$i];
            if (!$p) {
                continue;
            }
            if (!$p->unique_preparation) {
                for ($j = $i + 1; $j !== $n; ++$j) {
                    if ($preps[$j]
                        && $p->can_merge($preps[$j])) {
                        $p->merge($preps[$j]);
                        $preps[$j] = null;
                    }
                }
            }
            $p->send();
        }
    }


    /** @return int */
    function message_count() {
        return $this->_ms ? $this->_ms->message_count() : 0;
    }

    /** @return iterable<MessageItem> */
    function message_list() {
        return $this->_ms ? $this->_ms->message_list() : [];
    }

    /** @return string */
    function full_feedback_text() {
        return $this->_ms ? $this->_ms->full_feedback_text() : "";
    }

    /** @param ?string $field
     * @param string $message
     * @return MessageItem */
    function warning_at($field, $message) {
        $this->_ms = $this->_ms ?? (new MessageSet)->set_ignore_duplicates(true)->set_want_ftext(true, 5);
        return $this->_ms->warning_at($field, $message);
    }

    /** @param string $ref */
    final function unexpanded_warning_at($ref) {
        if (preg_match('/\A%(\w+)/', $ref, $m)) {
            $kw = $m[1];
            $xref = $ref;
        } else {
            $kw = $ref;
            $xref = "%{$kw}%";
        }
        $text = $this->handle_unexpanded_keyword($kw, $xref);
        if ($text !== "") {
            $this->warning_at($xref, $text);
        }
    }

    /** @param string $kw
     * @param string $xref
     * @return string */
    function handle_unexpanded_keyword($kw, $xref) {
        if (preg_match('/\A(?:RESET|)PASSWORDLINK/', $kw)) {
            if ($this->conf->login_type()) {
                return "<0>This site does not use password links";
            } else if ($this->censor === self::CENSOR_ALL) {
                return "<0>Password links cannot appear in mails with Cc or Bcc";
            }
        }
        return "<0>Keyword not found";
    }
}

// load mail templates, including local ones if any
global $Opt;
require_once(SiteLoader::$root . "/src/mailtemplate.php");
if ((@include (SiteLoader::$root . "/conf/mailtemplate-local.php")) !== false) {
    /* do nothing */;
}
