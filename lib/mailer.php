<?php
// mailer.php -- HotCRP mail template manager
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class MailPreparation {
    public $subject = "";
    public $body = "";
    public $preparation_owner = "";
    public $to = array();
    public $sendable = false;
    public $headers = array();
    public $errors = array();
}

class Mailer {
    const EXPAND_BODY = 0;
    const EXPAND_HEADER = 1;
    const EXPAND_EMAIL = 2;

    const EXPANDVAR_CONTINUE = -9999;

    public static $email_fields = array("to" => "To", "cc" => "Cc", "bcc" => "Bcc",
                                        "reply-to" => "Reply-To");

    protected $recipient = null;

    protected $width = 75;
    protected $sensitivity = null;
    protected $reason = null;
    protected $adminupdate = null;
    protected $notes = null;
    protected $preparation = null;
    public $capability = null;

    protected $expansionType = null;

    protected $_unexpanded = array();

    static private $eol = null;

    function __construct($recipient = null, $settings = array()) {
        $this->reset($recipient, $settings);
    }

    function reset($recipient = null, $settings = array()) {
        $this->recipient = $recipient;
        foreach (array("width", "sensitivity", "reason", "adminupdate", "notes",
                       "capability") as $k)
            $this->$k = $settings->$k ?? null;
        if ($this->width === null)
            $this->width = 75;
        else if (!$this->width)
            $this->width = 10000000;
    }

    static function eol() {
        global $Conf;
        if (self::$eol === null) {
            if (($x = $Conf->opt("postfixMailer")) === null)
                $x = $Conf->opt("postfixEOL");
            if (!$x)
                self::$eol = "\r\n";
            else if ($x === true || !is_string($x))
                self::$eol = PHP_EOL;
            else
                self::$eol = $x;
        }
        return self::$eol;
    }


    function expand_user($contact, $out) {
        $r = Text::analyze_name($contact);
        if (is_object($contact) && ($contact->preferredEmail ?? "") != "")
            $r->email = $contact->preferredEmail;

        // maybe infer username
        if ($r->firstName == "" && $r->lastName == "" && is_object($contact)
            && (($contact->email ?? "") !== "" || ($contact->preferredEmail ?? "") !== ""))
            $this->infer_user_name($r, $contact);

        if ($out == "NAME" || $out == "CONTACT")
            $t = $r->name;
        else if ($out == "FIRST")
            $t = $r->firstName;
        else if ($out == "LAST")
            $t = $r->lastName;
        else
            $t = "";
        if ($t == "" && $out == "NAME" && $r->email
            && $this->expansionType != self::EXPAND_EMAIL)
            $t = $r->email;
        if ($t != "" && $this->expansionType == self::EXPAND_EMAIL
            && preg_match('#[\000-\037()[\]<>@,;:\\".]#', $t))
            $t = "\"" . addcslashes($t, '"\\') . "\"";

        $email = $r->email;
        if ($email == "" && $this->expansionType == self::EXPAND_EMAIL)
            $email = "<none>";
        if ($out == "EMAIL")
            $t = $email;
        else if ($out == "CONTACT" && $this->expansionType == self::EXPAND_EMAIL) {
            if ($t == "")
                $t = $email;
            else if ($email[0] == "<")
                $t .= " $email";
            else
                $t .= " <$email>";
        } else if ($out == "CONTACT" && $email != "")
            $t = ($t == "" ? $email : "$t <$email>");

        return $t;
    }

    function infer_user_name($r, $contact) {
    }

    function expandvar($what, $isbool = false) {
        global $Conf;
        $len = strlen($what);

        // generic expansions: OPT, URLENC
        if ($len > 7 && substr($what, 0, 5) == "%OPT(" && substr($what, $len - 2) == ")%") {
            $inner = "%" . substr($what, 5, $len - 7) . "%";
            $yes = $this->expandvar($inner, true);
            if ($isbool)
                return $yes;
            else if ($yes)
                return $this->expandvar($inner, false);
            else
                return ($yes === null ? $what : "");
        }

        if ($len > 10 && substr($what, 0, 8) == "%URLENC(" && substr($what, $len - 2) == ")%") {
            $inner = "%" . substr($what, 8, $len - 10) . "%";
            $yes = $this->expandvar($inner, true);
            if ($isbool)
                return $yes;
            else if ($yes)
                return urlencode($this->expandvar($inner, false));
            else
                return ($yes === null ? $what : "");
        }

        // expansions that do not require a recipient
        if ($what == "%CONFNAME%")
            return $Conf->full_name();
        if ($what == "%CONFSHORTNAME%")
            return $Conf->short_name;
        if ($what == "%CONFLONGNAME%")
            return $Conf->long_name;
        if ($what == "%SIGNATURE%")
            return $Conf->opt("emailSignature") ? : "- " . $Conf->short_name . " Submissions";
        if ($what == "%ADMIN%" || $what == "%SITECONTACT%")
            return $this->expand_user($Conf->site_contact(), "CONTACT");
        if ($what == "%ADMINNAME%")
            return $this->expand_user($Conf->site_contact(), "NAME");
        if ($what == "%ADMINEMAIL%" || $what == "%SITEEMAIL%")
            return $this->expand_user($Conf->site_contact(), "EMAIL");
        if ($what == "%URL%")
            return $Conf->opt("paperSite");
        else if ($len > 7 && substr($what, 0, 5) == "%URL(" && substr($what, $len - 2) == ")%") {
            $a = preg_split('/\s*,\s*/', substr($what, 5, $len - 7));
            for ($i = 0; $i < count($a); ++$i) {
                $a[$i] = $this->expand($a[$i], "urlpart");
                $a[$i] = preg_replace('/\&(?=\&|\z)/', "", $a[$i]);
            }
            return $Conf->hoturl_absolute($a[0], isset($a[1]) ? $a[1] : "", Conf::HOTURL_NO_DEFAULTS);
        }
        if ($what == "%PHP%")
            return Navigation::php_suffix();
        if (preg_match('/\A%(CONTACT|NAME|EMAIL|FIRST|LAST)%\z/', $what, $m)) {
            if ($this->recipient) {
                if ($this->preparation)
                    $this->preparation->preparation_owner = $this->recipient->email;
                return $this->expand_user($this->recipient, $m[1]);
            } else if ($isbool)
                return false;
        }

        if ($what == "%LOGINNOTICE%") {
            if ($Conf->opt("disableCapabilities"))
                return $this->expand($Conf->opt("mailtool_loginNotice") ?? " To sign in, either click the link below or paste it into your web browser's location field.\n\n%LOGINURL%", $isbool);
            else
                return "";
        }
        if ($what == "%REASON%" || $what == "%ADMINUPDATE%" || $what == "%NOTES%") {
            $which = strtolower(substr($what, 1, strlen($what) - 2));
            $value = $this->$which;
            if ($value === null && !$this->recipient)
                return ($isbool ? null : $what);
            else if ($what == "%ADMINUPDATE%")
                return $value ? "An administrator performed this update. " : "";
            else
                return $value === null ? "" : $value;
        }

        $result = $this->expandvar_generic($what, $isbool);
        if ($result !== self::EXPANDVAR_CONTINUE)
            return $result;

        // exit if no recipient
        $external_password = $Conf->external_login();
        if (!$this->recipient) {
            if ($isbool && $what == "%PASSWORD%" && $external_password)
                return false;
            else
                return ($isbool ? null : $what);
        }

        // expansions that require a recipient
        if ($what == "%LOGINURL%" || $what == "%LOGINURLPARTS%" || $what == "%PASSWORD%") {
            $password = false;
            if (!$external_password) {
                $pwd_plaintext = $this->recipient->plaintext_password();
                if ($pwd_plaintext && !$this->sensitivity)
                    $password = $pwd_plaintext;
                else if ($pwd_plaintext && $this->sensitivity === "display")
                    $password = "HIDDEN";
            }
            $loginparts = "";
            if (!$Conf->opt("httpAuthLogin")) {
                $loginparts = "email=" . urlencode($this->recipient->email);
                if ($password)
                    $loginparts .= "&password=" . urlencode($password);
            }
            if ($what == "%LOGINURL%")
                return $Conf->opt("paperSite") . ($loginparts ? "/?" . $loginparts : "/");
            else if ($what == "%LOGINURLPARTS%")
                return $loginparts;
            else
                return ($isbool || $password ? $password : "");
        }
        if ($what == "%CAPABILITY%")
            return ($isbool || $this->capability ? $this->capability : "");

        $result = $this->expandvar_recipient($what, $isbool);
        if ($result !== self::EXPANDVAR_CONTINUE)
            return $result;

        // fallback
        if ($isbool)
            return false;
        else {
            $this->_unexpanded[$what] = true;
            return $what;
        }
    }

    function expandvar_generic($what, $isbool) {
        return self::EXPANDVAR_CONTINUE;
    }

    function expandvar_recipient($what, $isbool) {
        return self::EXPANDVAR_CONTINUE;
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
            $yes = $this->expandvar("%" . substr($cond, 1, strlen($cond) - 2) . "%", true);
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

    private function _lineexpand($line, $info, $indent, $width) {
        $text = "";
        while (preg_match('/^(.*?)(%#?[-a-zA-Z0-9!@_:.\/]+(?:|\([^\)]*\))%)(.*)$/s', $line, $m)) {
            $text .= $m[1] . $this->expandvar($m[2], false);
            $line = $m[3];
        }
        $text .= $line;
        return prefix_word_wrap($info, $text, $indent, $width);
    }

    function expand($text, $field = null) {
        if (is_array($text)) {
            $a = array();
            foreach ($text as $k => $t)
                $a[$k] = $this->expand($t, $k);
            return $a;
        }

        // leave early on empty string
        if ($text == "")
            return "";

        // width, expansion type based on field
        $oldExpansionType = $this->expansionType;
        $width = 100000;
        if ($field == "to" || $field == "cc" || $field == "bcc"
            || $field == "reply-to")
            $this->expansionType = self::EXPAND_EMAIL;
        else if ($field != "body" && $field != "")
            $this->expansionType = self::EXPAND_HEADER;
        else {
            $this->expansionType = self::EXPAND_BODY;
            $width = $this->width;
        }

        // expand out %IF% and %ELSE% and %ENDIF%.  Need to do this first,
        // or we get confused with wordwrapping.
        $text = $this->_expandConditionals(cleannl($text));

        // separate text into lines
        $lines = explode("\n", $text);
        if (count($lines) && $lines[count($lines) - 1] === "")
            array_pop($lines);

        $text = "";
        $textstart = 0;
        for ($i = 0; $i < count($lines); ++$i) {
            $line = rtrim($lines[$i]);
            if ($line == "")
                $text .= "\n";
            else if (preg_match('/^%(?:REVIEWS|COMMENTS)(?:[(].*[)])?%$/', $line)) {
                if (($m = $this->expandvar($line, false)) != "")
                    $text .= $m . "\n";
            } else if (preg_match('/^([ \t][ \t]*.*?: )(%OPT\([\w()]+\)%)$/', $line, $m)) {

                if (($yes = $this->expandvar($m[2], true)))
                    $text .= prefix_word_wrap($m[1], $this->expandvar($m[2]), tabLength($m[1], true), $width);
                else if ($yes === null)
                    $text .= $line . "\n";
            } else if (preg_match('/^([ \t][ \t]*.*?: )(%\w+(?:|\([^\)]*\))%|\S+)\s*$/', $line, $m))
                $text .= $this->_lineexpand($m[2], $m[1], tabLength($m[1], true), $width);
            else if (strpos($line, '%') !== false)
                $text .= $this->_lineexpand($line, "", 0, $width);
            else
                $text .= prefix_word_wrap("", $line, 0, $width);
        }

        // lose newlines on header expansion
        if ($this->expansionType != self::EXPAND_BODY)
            $text = rtrim(preg_replace('/[\r\n\f\x0B]+/', ' ', $text));

        $this->expansionType = $oldExpansionType;
        return $text;
    }


    static function get_template($templateName, $default = false) {
        global $Conf, $mailTemplates;
        $m = $mailTemplates[$templateName];
        if (!$default && $Conf) {
            if (($t = $Conf->setting_data("mailsubj_" . $templateName)) !== null) {
                $m["subject"] = $t;
            }
            if (($t = $Conf->setting_data("mailbody_" . $templateName)) !== null) {
                $m["body"] = $t;
            }
        }
        return $m;
    }

    function expand_template($templateName, $default = false) {
        return $this->expand(self::get_template($templateName, $default));
    }


    static function allow_send($email) {
        global $Conf;
        return $Conf->opt("sendEmail")
            && ($at = strpos($email, "@")) !== false
            && ((($ch = $email[$at + 1]) !== "_" && $ch !== "e" && $ch !== "E")
                || !preg_match(';\A(?:_.*|example\.(?:com|net|org))\z;i', substr($email, $at + 1)));
    }

    function create_preparation() {
        return new MailPreparation;
    }

    function make_preparation($template, $rest = array()) {
        global $Conf;

        // look up template
        if (is_string($template) && $template[0] == "@")
            $template = self::get_template(substr($template, 1));
        // add rest fields to template for expansion
        foreach (self::$email_fields as $lcfield => $field)
            if (isset($rest[$lcfield]))
                $template[$lcfield] = $rest[$lcfield];

        // expand the template
        $prep = $this->preparation = $this->create_preparation();
        $m = $this->expand($template);
        $this->preparation = null;

        $subject = MimeText::encode_header("Subject: ", $m["subject"]);
        $prep->subject = substr($subject, 9);

        $prep->body = $m["body"];

        // look up recipient; use preferredEmail if set
        $recipient = $this->recipient;
        if (!$recipient || !$recipient->email)
            return Conf::msg_error("no email in Mailer::send");
        if (isset($recipient->preferredEmail)) {
            $recipient = (object) array("email" => $recipient->preferredEmail);
            foreach (array("firstName", "lastName", "name", "fullName") as $k)
                if (isset($this->recipient->$k))
                    $recipient->$k = $this->recipient->$k;
        }
        $prep->to = array(Text::user_email_to($recipient));
        $m["to"] = $prep->to[0];
        $prep->sendable = self::allow_send($recipient->email);

        // parse headers
        if (!$Conf->opt("emailFromHeader"))
            $Conf->set_opt("emailFromHeader", MimeText::encode_email_header("From: ", $Conf->opt("emailFrom")));
        $eol = self::eol();
        $prep->headers = array("from" => $Conf->opt("emailFromHeader") . $eol, "subject" => $subject . $eol, "to" => "");
        foreach (self::$email_fields as $lcfield => $field)
            if (($text = ($m[$lcfield] ?? "")) !== ""
                && $text !== "<none>") {
                if (($hdr = MimeText::encode_email_header($field . ": ", $text)))
                    $prep->headers[$lcfield] = $hdr . $eol;
                else {
                    $prep->errors[$lcfield] = $text;
                    if (!get($rest, "no_error_quit"))
                        Conf::msg_error("$field destination “<samp>" . htmlspecialchars($text) . "</samp>” isn't a valid email list.");
                }
            }
        $prep->headers["mime-version"] = "MIME-Version: 1.0" . $eol;
        $prep->headers["content-type"] = "Content-Type: text/plain; charset=utf-8" . $eol;

        if ($prep->errors && !get($rest, "no_error_quit"))
            return false;
        else
            return $prep;
    }

    static function preparation_differs($prep1, $prep2) {
        return $prep1->subject != $prep2->subject
            || $prep1->body != $prep2->body
            || get($prep1->headers, "cc") != get($prep2->headers, "cc")
            || get($prep1->headers, "reply-to") != get($prep2->headers, "reply-to")
            || $prep1->preparation_owner != $prep2->preparation_owner;
    }

    static function merge_preparation_to($prep, $to) {
        if (is_object($to) && isset($to->to))
            $to = $to->to;
        if (count($to) != 1 || count($prep->to) == 0
            || $prep->to[count($prep->to) - 1] != $to[0])
            $prep->to = array_merge($prep->to, $to);
    }

    static function send_preparation($prep) {
        global $Conf;
        if ($Conf->opt("internalMailer") === null)
            $Conf->set_opt("internalMailer", strncasecmp(PHP_OS, "WIN", 3) != 0);
        $headers = $prep->headers;
        $eol = self::eol();

        // create valid To: header
        $to = $prep->to;
        if (is_array($to))
            $to = join(", ", $to);
        $to = MimeText::encode_email_header("To: ", $to);
        $headers["to"] = $to . $eol;

        // set sendmail parameters
        $extra = $Conf->opt("sendmailParam");
        if (($sender = $Conf->opt("emailSender")) !== null) {
            @ini_set("sendmail_from", $sender);
            if ($extra === null)
                $extra = "-f" . escapeshellarg($sender);
        }

        if ($prep->sendable && $Conf->opt("internalMailer")
            && ($sendmail = ini_get("sendmail_path"))) {
            $htext = join("", $headers);
            $f = popen($extra ? "$sendmail $extra" : $sendmail, "wb");
            fwrite($f, $htext . $eol . $prep->body);
            $status = pclose($f);
            if (pcntl_wifexitedwith($status))
                return true;
            else {
                $Conf->set_opt("internalMailer", false);
                error_log("Mail " . $headers["to"] . " failed to send, falling back (status $status)");
            }
        }

        if ($prep->sendable) {
            if (strpos($to, $eol) === false) {
                unset($headers["to"]);
                $to = substr($to, 4); // skip "To: "
            } else
                $to = "";
            unset($headers["subject"]);
            $htext = substr(join("", $headers), 0, -2);
            return mail($to, $prep->subject, $prep->body, $htext, $extra);

        } else if (!$Conf->opt("sendEmail")
                   && !preg_match('/\Aanonymous\d*\z/', $to)) {
            unset($headers["mime-version"], $headers["content-type"]);
            $text = join("", $headers) . $eol . $prep->body;
            if (PHP_SAPI != "cli")
                $Conf->infoMsg("<pre>" . htmlspecialchars($text) . "</pre>");
            else if (!$Conf->opt("disablePrintEmail"))
                fwrite(STDERR, "========================================\n" . str_replace("\r\n", "\n", $text) .  "========================================\n");
            return null;
        }
    }


    protected function unexpanded_warning() {
        $a = array_keys($this->_unexpanded);
        natcasesort($a);
        for ($i = 0; $i < count($a); ++$i)
            $a[$i] = "<code>" . htmlspecialchars($a[$i]) . "</code>";
        if (count($a) == 1)
            return "Keyword-like string " . commajoin($a) . " was not recognized.";
        else
            return "Keyword-like strings " . commajoin($a) . " were not recognized.";
    }

    function nwarnings() {
        return count($this->_unexpanded);
    }

    function warnings() {
        $e = array();
        if (count($this->_unexpanded))
            $e[] = $this->unexpanded_warning();
        return $e;
    }

}

class MimeText {
    /// Quote potentially non-ASCII header text a la RFC2047 and/or RFC822.
    static function append(&$result, &$linelen, $str, $utf8) {
        if ($utf8) {
            // replace all special characters used by the encoder
            $str = str_replace(array('=',   '_',   '?',   ' '),
                               array('=3D', '=5F', '=3F', '_'), $str);
            // define nonsafe characters
            if ($utf8 > 1)
                $matcher = ',[^-0-9a-zA-Z!*+/=_],';
            else
                $matcher = ',[\x80-\xFF],';
            preg_match_all($matcher, $str, $m, PREG_OFFSET_CAPTURE);
            $xstr = "";
            $last = 0;
            foreach ($m[0] as $mx) {
                $xstr .= substr($str, $last, $mx[1] - $last)
                    . "=" . strtoupper(dechex(ord($mx[0])));
                $last = $mx[1] + 1;
            }
            $xstr .= substr($str, $last);
        } else
            $xstr = $str;

        // append words to the line
        while ($xstr != "") {
            $z = strlen($xstr);
            assert($z > 0);

            // add a line break
            $maxlinelen = ($utf8 ? 76 - 12 : 78);
            if (($linelen + $z > $maxlinelen && $linelen > 30)
                || ($utf8 && substr($result, strlen($result) - 2) == "?=")) {
                $result .= Mailer::eol() . " ";
                $linelen = 1;
                while (!$utf8 && $xstr !== "" && ctype_space($xstr[0])) {
                    $xstr = substr($xstr, 1);
                    --$z;
                }
            }

            // if encoding, skip intact UTF-8 characters;
            // otherwise, try to break at a space
            if ($utf8 && $linelen + $z > $maxlinelen) {
                $z = $maxlinelen - $linelen;
                if ($xstr[$z - 1] == "=")
                    $z -= 1;
                else if ($xstr[$z - 2] == "=")
                    $z -= 2;
                while ($z > 3
                       && $xstr[$z] == "="
                       && ($chr = hexdec(substr($xstr, $z + 1, 2))) >= 128
                       && $chr < 192)
                    $z -= 3;
            } else if ($linelen + $z > $maxlinelen) {
                $y = strrpos(substr($xstr, 0, $maxlinelen - $linelen), " ");
                if ($y > 0)
                    $z = $y;
            }

            // append
            if ($utf8)
                $astr = "=?utf-8?q?" . substr($xstr, 0, $z) . "?=";
            else
                $astr = substr($xstr, 0, $z);

            $result .= $astr;
            $linelen += strlen($astr);

            $xstr = substr($xstr, $z);
        }
    }

    static function encode_email_header($header, $str) {
        if (preg_match('/[\r\n]/', $str))
            $str = simplify_whitespace($str);

        $text = $header;
        $linelen = strlen($text);

        // separate $str into emails, quote each separately
        while (true) {

            // try three types of match in turn:
            // 1. name <email> [RFC 822]
            $match = preg_match("/\\A[,\\s]*((?:(?:\"(?:[^\"\\\\]|\\\\.)*\"|[^\\s\\000-\\037()[\\]<>@,;:\\\\\".]+)\\s*?)*)\\s*<\\s*(.*?)\\s*>\\s*(.*)\\z/s", $str, $m);
            // 2. name including periods but no quotes <email> (canonicalize)
            if (!$match) {
                $match = preg_match("/\\A[,\\s]*((?:[^\\s\\000-\\037()[\\]<>@,;:\\\\\"]+\\s*?)*)\\s*<\\s*(.*?)\\s*>\\s*(.*)\\z/s", $str, $m);
                if ($match)
                    $m[1] = "\"$m[1]\"";
            }
            // 3. bare email
            if (!$match)
                $match = preg_match("/\\A[,\\s]*()<?\\s*([^\\s\\000-\\037()[\\]<>,;:\\\\\"]+)\\s*>?\\s*(.*)\\z/s", $str, $m);
            // otherwise, fail
            if (!$match)
                break;

            list($name, $email, $str) = array($m[1], $m[2], $m[3]);
            if (strpos($email, "@") !== false && !validate_email($email))
                return false;
            if ($str != "" && $str[0] != ",")
                return false;
            if ($email == "none" || $email == "hidden")
                continue;

            if ($text !== $header) {
                $text .= ", ";
                $linelen += 2;
            }

            // unquote any existing UTF-8 encoding
            if ($name != "" && $name[0] == "="
                && strcasecmp(substr($name, 0, 10), "=?utf-8?q?") == 0)
                $name = self::decode_header($name);

            $utf8 = preg_match('/[\x80-\xFF]/', $name) ? 2 : 0;
            if ($name != "" && $name[0] == "\""
                && preg_match("/\\A\"([^\\\\\"]|\\\\.)*\"\\z/s", $name)) {
                if ($utf8)
                    self::append($text, $linelen, substr($name, 1, -1), $utf8);
                else
                    self::append($text, $linelen, $name, false);
            } else if ($utf8)
                self::append($text, $linelen, $name, $utf8);
            else
                self::append($text, $linelen, rfc2822_words_quote($name), false);

            if ($name == "")
                self::append($text, $linelen, $email, false);
            else
                self::append($text, $linelen, " <$email>", false);
        }

        if (!preg_match('/\A[\s,]*\z/', $str))
            return false;
        return $text;
    }

    static function encode_header($header, $str) {
        if (preg_match('/[\r\n]/', $str))
            $str = simplify_whitespace($str);

        $text = $header;
        $linelen = strlen($text);
        if (preg_match('/[\x80-\xFF]/', $str))
            self::append($text, $linelen, $str, true);
        else
            self::append($text, $linelen, $str, false);
        return $text;
    }

    static function chr_hexdec_callback($m) {
        return chr(hexdec($m[1]));
    }

    static function decode_header($text) {
        if (strlen($text) > 2 && $text[0] == '=' && $text[1] == '?') {
            $out = '';
            while (preg_match('/\A=\?utf-8\?q\?(.*?)\?=(\r?\n )?/i', $text, $m)) {
                $f = str_replace('_', ' ', $m[1]);
                $out .= preg_replace_callback('/=([0-9A-F][0-9A-F])/',
                                              "MimeText::chr_hexdec_callback",
                                              $f);
                $text = substr($text, strlen($m[0]));
            }
            return $out . $text;
        } else
            return $text;
    }

}
