<?php
// resetpassword.php -- HotCRP password reset page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ResetPassword_Page::go($Conf, $Qreq);

class ResetPassword_Page {
    /** @var Conf */
    private $conf;
    /** @var Qrequest */
    private $qreq;

    function __construct(Conf $conf, Qrequest $qreq) {
        $this->conf = $conf;
        $this->qreq = $qreq;
    }

    function request() {
        if ($this->conf->external_login()) {
            $this->conf->msg("Password reset links aren’t used for this conference. Contact your system administrator if you’ve forgotten your password.", 2);
            $this->conf->redirect();
        }

        $resetcap = $this->qreq->resetcap;
        if ($resetcap === null
            && preg_match(',\A/(U?1[-\w]+)(?:/|\z),i', $this->qreq->path(), $m)) {
            $resetcap = $m[1];
        }
        if (!$resetcap) {
            $this->conf->msg("You didn’t enter the full password reset link into your browser. Make sure you include the reset code (the string of letters, numbers, and other characters at the end).", 2);
            $this->conf->redirect();
        }

        $iscdb = substr($resetcap, 0, 1) === "U";
        $capmgr = $this->conf->capability_manager($resetcap);
        $capdata = $capmgr->check($resetcap);
        if (!$capdata || $capdata->capabilityType != CAPTYPE_RESETPASSWORD) {
            $this->conf->msg("That password reset code has expired, or you didn’t enter it correctly.", 2);
            $this->conf->redirect();
        }

        if ($iscdb) {
            $Acct = Contact::contactdb_find_by_id($capdata->contactId);
        } else {
            $Acct = $this->conf->user_by_id($capdata->contactId);
        }
        if (!$Acct) {
            $this->conf->msg("That password reset code refers to a user who no longer exists. Either create a new account or contact the conference administrator.", 2);
            $this->conf->redirect();
        }

        // don't show information about the current user, if there is one
        global $Me;
        $Me = new Contact;

        $password_class = "";
        if (isset($_POST["go"]) && check_post()) {
            $_POST["password"] = trim($_POST["password"] ?? "");
            $_POST["password2"] = trim($_POST["password2"] ?? "");
            if ($_POST["password"] == "") {
                Conf::msg_error("You must enter a password.");
            } else if ($_POST["password"] !== $_POST["password2"]) {
                Conf::msg_error("The two passwords you entered did not match.");
            } else if (!Contact::valid_password($_POST["password"])) {
                Conf::msg_error("Invalid password.");
            } else {
                $flags = 0;
                if ($_POST["password"] === ($_POST["autopassword"] ?? null)) {
                    $flags |= Contact::CHANGE_PASSWORD_PLAINTEXT;
                }
                $Acct->change_password(null, $_POST["password"], $flags);
                if (!$iscdb
                    || !($log_acct = $this->conf->user_by_email($Acct->email))) {
                    $log_acct = $Acct;
                }
                $log_acct->log_activity("Password reset via " . substr($resetcap, 0, 8) . "...");
                $this->conf->confirmMsg("Your password has been changed. You may now sign in to the conference site.");
                $capmgr->delete($capdata);
                $this->conf->save_session("password_reset", (object) array("time" => Conf::$now, "email" => $Acct->email, "password" => $_POST["password"]));
                $this->conf->redirect();
            }
            $password_class = " has-error";
        }

        $this->conf->header("Reset password", "resetpassword");

        if (!isset($_POST["autopassword"])
            || trim($_POST["autopassword"]) != $_POST["autopassword"]
            || strlen($_POST["autopassword"]) < 16
            || !preg_match("/\\A[-0-9A-Za-z@_+=]*\\z/", $_POST["autopassword"]))
            $_POST["autopassword"] = Contact::random_password();

        echo "<div class='homegrp'>
        Welcome to the ", htmlspecialchars($this->conf->full_name()), " submissions site.";
        if ($this->conf->opt("conferenceSite")) {
            echo " For general information about ", htmlspecialchars($this->conf->short_name), ", see <a href=\"", htmlspecialchars($this->conf->opt("conferenceSite")), "\">the conference site</a>.";
        }

        echo "</div>
        <hr class='home' />
        <div class='homegrp' id='homereset'>\n",
            Ht::form($this->conf->hoturl_post("resetpassword")),
            '<div class="f-contain">',
            Ht::hidden("resetcap", $resetcap),
            Ht::hidden("autopassword", $_POST["autopassword"]),
            "<p>Use this form to reset your password. You may want to use the random password we’ve chosen.</p>";
        echo '<div class="f-i"><label>Email</label>', htmlspecialchars($Acct->email), '</div>',
            Ht::entry("email", $Acct->email, ["class" => "hidden", "autocomplete" => "username"]),
            '<div class="f-i"><label for="autopassword">Suggested strong password</label>',
            Ht::entry("autopassword", $_POST["autopassword"], ["size" => 36, "readonly" => true, "id" => "autopassword"]),
            '</div>',
            '<div class="f-i', $password_class, '"><label for="password">New password</label>',
            Ht::password("password", "", ["id" => "password", "size" => 36, "autocomplete" => "new-password", "autofocus" => true]),
            '</div>',
            '<div class="f-i', $password_class, '"><label for="password2">Repeat new password</label>',
            Ht::password("password2", "", ["id" => "password2", "size" => 36, "autocomplete" => "new-password"]),
            '</div>',
            '<div class="f-i" style="margin-top:2em">',
            Ht::submit("go", "Reset password", array("tabindex" => 1)),
            '</div></div></form><hr class="home"></div>', "\n";

        echo '<hr class="c" />', "\n";
        $this->conf->footer();
    }

    static function go(Conf $conf, Qrequest $qreq) {
        $rpp = new ResetPassword_Page($conf, $qreq);
        $rpp->request();
    }
}
