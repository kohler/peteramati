<?php
// harvardseas_repositorysite.php -- Peteramati code.seas.harvard.edu repositories
// Peteramati is Copyright (c) 2013-2016 Eddie Kohler
// See LICENSE for open-source distribution terms

class HarvardSEAS_RepositorySite extends RepositorySite {
    public $conf;
    public $base;
    public $siteclass = "harvardseas";
    function __construct(Conf $conf, $url, $base) {
        $this->conf = $conf;
        $this->url = $url;
        $this->base = $base;
    }

    const MAINHOST = "code.seas.harvard.edu";
    const MAINURL = "https://code.seas.harvard.edu/";
    static function make_url($url, Conf $conf) {
        if (preg_match('_\A(?:code\.seas(?:\.harvard(?:\.edu)?)?[:/])?/*((?:[^/:@]+/)?[^/:@]+/[^/:@]+?)(?:\.git|)\z_i', $url, $m))
            return new HarvardSEAS_RepositorySite($conf, "git@code.seas.harvard.edu:" . $m[1] . ".git", $m[1]);
        if (preg_match('_\A(?:https?://|git://|ssh://(?:git@)?|git@|)' . self::MAINHOST . '(?::/*|/+)(.*?)(?:\.git|)\z_i', $url, $m))
            return new HarvardSEAS_RepositorySite($conf, $url, $m[1]);
        return null;
    }
    static function sniff_url($url) {
        if (preg_match('_\A(?:https?://|git://|ssh://(?:git@)?|git@|)' . self::MAINHOST . '(?::/*|/+)(.*?)(?:\.git|)\z_i', $url, $m))
            return 2;
        else if (preg_match('_\A(?:code\.seas(?:\.harvard(?:\.edu)?)?[:/])/*((?:[^/:@]+/)?[^/:@]+/[^/:@]+?)(?:\.git|)\z_i', $url, $m))
            return 2;
        else if (preg_match('_\A/*((?:[^/:@]+/)?[^/:@]+/[^/:@]+?)(?:\.git|)\z_i', $url, $m))
            return 1;
        return 0;
    }
    static function home_link($html) {
        return Ht::link($html, self::MAINURL);
    }

    static function echo_username_form(Contact $user, $first) {
        global $Me;
        if (!$first && !$user->seascode_username)
            return;
        echo Ht::form(hoturl_post("index", array("set_username" => 1, "u" => $Me->user_linkpart($user), "reposite" => "harvardseas"))),
            '<div class="f-contain">';
        $notes = array();
        if (!$user->seascode_username)
            $notes[] = array(true, "Please enter your " . self::home_link("code.seas.harvard.edu") . " username and click “Save.”");
        ContactView::echo_group(self::home_link("code.seas") . " username",
                                Ht::entry("username", $user->seascode_username)
                                . "  " . Ht::submit("Save"), $notes);
        echo "</div></form>";
    }
    static function save_username(Contact $user, $username) {
        global $Me;
        // does it contain odd characters?
        $username = trim((string) $username);
        if ($username === "") {
            if ($Me->privChair)
                return $user->change_username("seascode", null);
            return Conf::msg_error("Empty username.");
        }
        if (preg_match('_[@,;:~/\[\](){}\\<>&#=\\000-\\027]_', $username))
            return Conf::msg_error("The username “" . htmlspecialchars($username) . "” contains funny characters. Remove them.");

        // is it in use?
        $x = $user->conf->fetch_value("select contactId from ContactInfo where seascode_username=?", $username);
        if ($x && $x != $user->contactId)
            return Conf::msg_error("That username is already in use.");

        // is it valid?
        $htopt = array("timeout" => 5, "ignore_errors" => true);
        $context = stream_context_create(array("http" => $htopt));
        $userurl = self::MAINURL . "~" . htmlspecialchars($username);
        $response_code = 509;
        if (($stream = fopen($userurl, "r", false, $context))) {
            if (($metadata = stream_get_meta_data($stream))
                && ($w = get($metadata, "wrapper_data"))
                && is_array($w)
                && preg_match(',\AHTTP/[\d.]+\s+(\d+)\s+(.+)\z,', $w[0], $m))
                $response_code = (int) $m[1];
            fclose($stream);
        }

        if ($response_code == 404)
            return Conf::msg_error("That username doesn’t appear to exist. Check your spelling.");
        else if ($response_code != 200)
            return Conf::msg_error("Error contacting " . htmlspecialchars($userurl) . " (response code $response_code). Maybe try again?");

        return $user->change_username("seascode", $username);
    }

    function friendly_siteclass() {
        return "code.seas";
    }
    static function global_friendly_siteclass() {
        return "code.seas";
    }
    static function global_friendly_siteurl() {
        return "https://code.seas.harvard.edu/";
    }

    function web_url() {
        return "https://code.seas.harvard.edu/" . $this->base;
    }
    function ssh_url() {
        return "git@code.seas.harvard.edu:" . $this->base . ".git";
    }
    function git_url() {
        return "git://code.seas.harvard.edu/" . $this->base . ".git";
    }
    function friendly_url() {
        return $this->base ? : $this->url;
    }

    function message_defs(Contact $user) {
        $base = $user->is_anonymous ? "[anonymous]" : $this->base;
        return ["REPOGITURL" => "git@code.seas.harvard.edu:$base.git", "REPOBASE" => $base, "HARVARDSEAS" => 1];
    }

    function validate_open(MessageSet $ms = null) {
        return RepositorySite::run_ls_remote($this->conf, $this->git_url(), $output);
    }
    function validate_working(MessageSet $ms = null) {
        $status = RepositorySite::run_ls_remote($this->conf, $this->ssh_url(), $output);
        $answer = join("\n", $output);
        if ($status == 0 && $ms)
            $ms->set_error_html("working", Messages::$main->expand_html("repo_unreadable", $this->message_defs($ms->user)));
        if ($status > 0 && !preg_match(',^[0-9a-f]{40}\s+refs/heads/master,m', $answer)) {
            if ($ms)
                $ms->set_error_html("working", Messages::$main->expand_html("repo_nomaster", $this->message_defs($ms->user)));
            $status = 0;
        }
        return $status;
    }
    function validate_ownership(Repository $repo, Contact $user, Contact $partner = null,
                                MessageSet $ms = null) {
        $xpartner = $partner && $partner->seascode_username;
        if (!str_starts_with($this->base, "~") || !$user->seascode_username)
            return -1;
        if (preg_match('_\A~(?:' . preg_quote($user->seascode_username)
                       . ($partner ? "|" . preg_quote($partner->seascode_username) : "")
                       . ')/_i', $this->base))
            return 1;
        if ($ms)
            $ms->set_error_html("ownership", $partner ? "This repository belongs to neither you nor your partner." : "This repository does not belong to you.");
        return 0;
    }
}
