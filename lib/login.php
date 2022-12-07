<?php
// login.php -- HotCRP login helpers
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class LoginHelper {
    static function check_http_auth(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        assert($conf->opt("httpAuthLogin") !== null);

        // if user signed out of HTTP authentication, send a reauth request
        if (isset($_SESSION["reauth"])) {
            unset($_SESSION["reauth"]);
            header("HTTP/1.0 401 Unauthorized");
            if (is_string($conf->opt("httpAuthLogin")))
                header("WWW-Authenticate: " . $conf->opt("httpAuthLogin"));
            else
                header("WWW-Authenticate: Basic realm=\"HotCRP\"");
            exit;
        }

        // if user is still valid, OK
        if ($user->has_account_here())
            return;

        // check HTTP auth
        if (!isset($_SERVER["REMOTE_USER"]) || !$_SERVER["REMOTE_USER"]) {
            $conf->header("Error", "home");
            Conf::msg_error("This site is using HTTP authentication to manage its users, but you have not provided authentication data. This usually indicates a server configuration error.");
            $qreq->print_footer();
            exit;
        }
        $qreq->email = $_SERVER["REMOTE_USER"];
        if (validate_email($qreq->email))
            $qreq->preferredEmail = $qreq->email;
        else if (($x = $conf->opt("defaultEmailDomain"))
                 && validate_email($qreq->email . "@" . $x))
            $qreq->preferredEmail = $qreq->email . "@" . $x;
        $qreq->action = "login";
        self::login_redirect($conf, $qreq); // redirect on success

        $conf->header("Error", "home");
        Conf::msg_error("This site is using HTTP authentication to manage its users, and you have provided incorrect authentication data.");
        $qreq->print_footer();
        exit;
    }

    static function login_redirect(Conf $conf, Qrequest $qreq) {
        $external_login = $conf->external_login();

        // In all cases, we need to look up the account information
        // to determine if the user is registered
        if (!isset($qreq->email)
            || ($qreq->email = trim($qreq->email)) === "") {
            Ht::error_at("email", "Enter your email address.");
            return false;
        }

        // look up user in our database
        if (strpos($qreq->email, "@") === false) {
            self::unquote_double_quoted_request($qreq);
        }
        $user = $conf->user_by_whatever($qreq->email);

        // look up or create user in contact database
        $cdb_user = null;
        if ($conf->opt("contactdb_dsn")) {
            if ($user) {
                $cdb_user = $user->contactdb_user();
            } else {
                $cdb_user = $conf->contactdb_user_by_email($qreq->email);
            }
        }

        // create account if requested
        if ($qreq->action === "new" && $qreq->valid_post()) {
            if ($conf->opt("disableNewUsers") || $conf->opt("disableNonPC")) {
                Ht::error_at("email", "New users can’t self-register for this site.");
                return false;
            }
            $user = self::create_account($conf, $qreq, $user, $cdb_user);
            if (!$user) {
                return null;
            }
            // If we get here, it's the first account and we're going to
            // log them in automatically. XXX should show the password
            $qreq->password = $user->plaintext_password();
        }

        // auto-create account if external login
        if (!$user && $external_login) {
            $reg = Contact::safe_registration($_REQUEST);
            $reg->no_validate_email = true;
            if (!($user = Contact::create($conf, $reg))) {
                return Conf::msg_error($conf->db_error_html(true));
            }
            if ($conf->setting("setupPhase")) {
                return self::first_user($user, "", false);
            }
        }

        // if no user found, then fail
        if (!$user && (!$cdb_user || !$cdb_user->allow_contactdb_password())) {
            Ht::error_at("email", "No account for " . htmlspecialchars($qreq->email) . ". Did you enter the correct email address?");
            return false;
        }

        // if user disabled, then fail
        if (($user && $user->is_disabled())
            || (!$user && $cdb_user && $cdb_user->is_disabled())) {
            Ht::error_at("email", "Your account is disabled. Contact the site administrator for more information.");
            return false;
        }

        // maybe reset password
        $xuser = $user ? : $cdb_user;
        if ($qreq->action === "forgot" && $qreq->valid_post()) {
            $worked = $xuser->sendAccountInfo("forgot", true);
            if ($worked === "@resetpassword") {
                $conf->confirmMsg("A password reset link has been emailed to " . htmlspecialchars($qreq->email) . ". When you receive that email, follow its instructions to create a new password.");
            } else if ($worked) {
                $conf->confirmMsg("Your password has been emailed to " . htmlspecialchars($qreq->email) . ".  When you receive that email, return here to sign in.");
                $conf->log("Password sent", $xuser);
            }
            return null;
        }

        // check password
        if (!$external_login) {
            if (!$qreq->valid_post()) {
                Ht::warning_at("password", "Automatic login links have been disabled to improve site security. Enter your password to sign in.");
                return false;
            }

            $password = trim((string) $qreq->password);
            if ($password === "") {
                Ht::error_at("password", "Password missing.");
                return false;
            }

            $info = (object) [];
            if (!$xuser->check_password($password, $info)) {
                $error = "Incorrect password. If you’ve forgotten your password, enter your email address and use the “I forgot my password” option.";
                Ht::error_at("password", $error);
                return false;
            }
        }

        // mark activity
        $xuser->mark_login();

        // store authentication
        $qreq->qsession()->open_new_sid();
        self::change_session_users($qreq, [$xuser->email => 1]);

        // activate
        $user = $xuser->activate($qreq);
        $qreq->unset_csession("password_reset");

        // give chair privilege to first user (external login or contactdb)
        if ($conf->setting("setupPhase")) {
            self::first_user($user, "", false);
        }

        // redirect
        $nav = Navigation::get();
        $url = $nav->server . $nav->base_path;
        $url .= "?postlogin=1";
        if ($qreq->go !== null) {
            $url .= "&go=" . urlencode($qreq->go);
        }
        $conf->redirect($url);
    }

    /** @param Qrequest $qreq
     * @param array<string,1|-1> $uinstr */
    static function change_session_users($qreq, $uinstr) {
        $us = Contact::session_users($qreq);
        foreach ($uinstr as $e => $delta) {
            for ($i = 0; $i !== count($us); ++$i) {
                if (strcasecmp($us[$i], $e) === 0)
                    break;
            }
            if ($delta < 0 && $i !== count($us)) {
                array_splice($us, $i, 1);
            } else if ($delta > 0 && $i === count($us)) {
                $us[] = $e;
            }
        }
        if (count($us) > 1) {
            $qreq->set_gsession("us", $us);
        } else {
            $qreq->unset_gsession("us");
        }
        if (empty($us)) {
            $qreq->unset_gsession("u");
        } else if ($qreq->gsession("u") !== $us[0]) {
            $qreq->set_gsession("u", $us[0]);
        }
    }

    static function check_postlogin(Contact $user, Qrequest $qreq) {
        // Check for the cookie
        if (!$qreq->has_gsession("v")) {
            return Conf::msg_error("You appear to have disabled cookies in your browser. This site requires cookies to function.");
        }
        $qreq->unset_gsession("testsession");

        // Go places
        if (isset($qreq->go)) {
            $where = $qreq->go;
        } else if (($login_bounce = $qreq->gsession("login_bounce"))
                   && $login_bounce[0] === $user->conf->session_key) {
            $where = $login_bounce[1];
        } else {
            $qreq->set_csession("freshlogin", true);
            $where = $user->conf->hoturl_raw("index");
        }
        $user->conf->redirect($where);
        exit;
    }

    static private function unquote_double_quoted_request($qreq) {
        if (strpos($qreq->email, "@") !== false
            || strpos($qreq->email, "%40") === false)
            return false;
        // error_log("double-encoded request: " . json_encode($qreq));
        foreach ($qreq->keys() as $k)
            $qreq[$k] = rawurldecode($qreq[$k]);
        return true;
    }

    static private function create_account($conf, $qreq, $user, $cdb_user) {
        // check for errors
        if ($user && $user->has_account_here() && $user->activity_at > 0) {
            Ht::error_at("email", "An account already exists for " . htmlspecialchars($qreq->email) . ". Enter your password or select “Forgot your password?” to reset it.");
            return false;
        } else if ($cdb_user
                   && $cdb_user->allow_contactdb_password()
                   && $cdb_user->password_used()) {
            $desc = $conf->opt("contactdb_description") ? : "HotCRP";
            Ht::error_at("email", "An account already exists for " . htmlspecialchars($qreq->email) . " on $desc. Sign in using your $desc password or select “Forgot your password?” to reset it.");
            return false;
        } else if (!validate_email($qreq->email)) {
            Ht::error_at("email", "“" . htmlspecialchars($qreq->email) . "” is not a valid email address.");
            return false;
        }

        // create database account
        if (!$user || !$user->has_account_here()) {
            if (!($user = Contact::create($conf, Contact::safe_registration($_REQUEST))))
                return Conf::msg_error($conf->db_error_html(true));
        }

        $user->sendAccountInfo("create", true);
        $msg = "Successfully created an account for " . htmlspecialchars($qreq->email) . ".";

        // handle setup phase
        if ($conf->setting("setupPhase")) {
            self::first_user($user, $msg, true);
            return $user;
        }

        if (Mailer::allow_send($user->email)) {
            $msg .= " Login information has been emailed to you. Return here when you receive it to complete the registration process. If you don’t receive the email, check your spam folders and verify that you entered the correct address.";
        } else {
            if ($conf->opt("sendEmail"))
                $msg .= " The email address you provided seems invalid.";
            else
                $msg .= " The system cannot send email at this time.";
            $msg .= " Although an account was created for you, you need help to retrieve your password. Contact " . Text::user_html($conf->site_contact()) . ".";
        }
        if (isset($qreq->password) && trim($qreq->password) !== "") {
            $msg .= " The password you supplied on the login screen was ignored.";
        }
        $conf->confirmMsg($msg);
        return null;
    }

    static private function first_user($user, $msg, $is_create) {
        $msg .= " As the first user, you have been automatically signed in and assigned system administrator privilege.";
        if (!$user->conf->external_login()
            && $is_create
            && $user->plaintext_password()) {
            $msg .= " Your password is “<samp>" . htmlspecialchars($user->plaintext_password()) . "</samp>”. All later users will have to sign in normally.";
        }
        $user->save_roles(Contact::ROLE_ADMIN, null);
        $user->conf->save_setting("setupPhase", null);
        $user->conf->confirmMsg(ltrim($msg));
    }

    /** @param bool $explicit
     * @return Contact */
    static function logout(Contact $user, Qrequest $qreq, $explicit) {
        $qsess = $qreq->qsession();
        if ($qsess->maybe_open()) {
            $qsess->clear();
            $qsess->commit();
        }
        if ($explicit) {
            if ($user->conf->opt("httpAuthLogin")) {
                $qsess->open_new_sid();
                $qsess->set("reauth", true);
            } else {
                unlink_session();
            }
        }
        $user = Contact::make($user->conf);
        unset($qreq->actas, $qreq->cap, $qreq->forceShow, $qreq->override);
        return $user->activate($qreq);
    }
}
