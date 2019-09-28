<?php
// mail.php -- HotCRP mail tool
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
require_once("src/mailclasses.php");
if (!$Me->privChair && !$Me->isPC)
    $Me->escape();
$Error = array();

// load mail from log
if (isset($Qreq->fromlog)
    && ctype_digit($Qreq->fromlog)
    && $Me->privChair) {
    $result = $Conf->qe("select * from MailLog where mailId=" . $Qreq->fromlog);
    if (($row = edb_orow($result))) {
        foreach (array("recipients", "q", "t", "cc", "replyto", "subject", "emailBody") as $field)
            if (isset($row->$field) && !isset($Qreq[$field]))
                $Qreq[$field] = $row->$field;
        if (@$row->q)
            $Qreq["plimit"] = 1;
        if ($Qreq->recipients && ($space = strpos($Qreq->recipients, " "))) {
            $Qreq->userrecipients = substr($Qreq->recipients, $space + 1);
            $Qreq->recipients = substr($Qreq->recipients, 0, $space);
        }
    }
}

// create options
$tOpt = array();
if ($Me->privChair) {
    $tOpt["s"] = "Submitted papers";
    $tOpt["unsub"] = "Unsubmitted papers";
    $tOpt["all"] = "All papers";
}
$tOpt["req"] = "Your review requests";
if (!isset($Qreq->t) || !isset($tOpt[$Qreq->t]))
    $Qreq->t = key($tOpt);

// mailer
$mailer_options = array("requester_contact" => $Me);
$null_mailer = new CS61Mailer(null, null, array_merge(array("width" => false), $mailer_options));

// template options
if (isset($Qreq->monreq))
    $Qreq->template = "myreviewremind";
if (isset($Qreq->template) && !isset($Qreq->check))
    $Qreq->loadtmpl = 1;

// paper selection
if (isset($Qreq->q) && trim($Qreq->q) == "(All)")
    $Qreq->q = "";
if (!isset($Qreq->p) && isset($Qreq->pap)) // support p= and pap=
    $Qreq->p = $Qreq->pap;
if (isset($Qreq->p) && is_string($Qreq->p))
    $Qreq->p = preg_split('/\s+/', $Qreq->p);
if (isset($Qreq->p) && is_array($Qreq->p)) {
    $papersel = array();
    foreach ($Qreq->p as $p)
        if (($p = cvtint($p)) > 0)
            $papersel[] = $p;
    sort($papersel);
    $Qreq->q = join(" ", $papersel);
    $Qreq->plimit = 1;
} else if (isset($Qreq->plimit)) {
    $Qreq->q = (string) $Qreq->q;
    $search = new PaperSearch($Me, array("t" => $Qreq->t, "q" => $Qreq->q));
    $papersel = $search->paperList();
    sort($papersel);
} else
    $Qreq->q = "";
if (isset($papersel) && count($papersel) == 0) {
    $Conf->errorMsg("No papers match that search.");
    unset($papersel);
    unset($Qreq->check, $Qreq->send);
}

if (isset($Qreq->monreq))
    $Conf->header("Monitor external reviews", "mail");
else
    $Conf->header("Mail", "mail");

$subjectPrefix = "[" . $Opt["shortName"] . "] ";


class MailSender {

    private $recip;
    private $sending;

    private $started = false;
    private $group;
    private $groupable = false;
    private $mcount = 0;
    private $mrecipients = array();
    private $mpapers = array();
    private $cbcount = 0;
    private $mailid_text = "";

    function __construct($recip, $sending) {
        global $Qreq;
        $this->recip = $recip;
        $this->sending = $sending;
        $this->group = $Qreq->group || !$Qreq->ungroup;
    }

    static function check($recip) {
        $ms = new MailSender($recip, false);
        $ms->run();
    }

    static function send($recip) {
        $ms = new MailSender($recip, true);
        $ms->run();
    }

    private function echo_actions($extra_class = "") {
        global $Qreq;
        echo '<div class="aa', $extra_class, '">',
            Ht::submit("send", "Send", array("style" => "margin-right:4em")),
            ' &nbsp; ';
        $style = $this->groupable ? "" : "display:none";
        if (!$Qreq->group && $Qreq->ungroup)
            echo Ht::submit("group", "Gather recipients", array("style" => $style, "class" => "mail_groupable"));
        else
            echo Ht::submit("ungroup", "Separate recipients", array("style" => $style, "class" => "mail_groupable"));
        echo ' &nbsp; ', Ht::submit("cancel", "Cancel"), '</div>';
    }

    private function echo_prologue() {
        global $Conf, $Me, $Qreq;
        if ($this->started)
            return;
        echo Ht::form_div(hoturl_post("mail"));
        foreach (array("recipients", "subject", "emailBody", "cc", "replyto", "q", "t", "plimit") as $x)
            if (isset($Qreq[$x]))
                echo Ht::hidden($x, $Qreq[$x]);
        if (!$this->group)
            echo Ht::hidden("ungroup", 1);
        $recipients = (string) $Qreq->recipients;
        if ($this->sending) {
            echo "<div id='foldmail' class='foldc fold2c'>",
                "<div class='fn fx2 merror'>In the process of sending mail.  <strong>Do not leave this page until this message disappears!</strong><br /><span id='mailcount'></span></div>",
                "<div id='mailwarnings'></div>",
                "<span id='mailinfo'></span>",
                "<div class='fx'><div class='confirm'>Sent mail as follows.</div>",
                "<div class='aa'>",
                Ht::submit("go", "Prepare more mail"),
                "</div></div>",
                // This next is only displayed when Javascript is off
                "<div class='fn2 warning'>Sending mail. <strong>Do not leave this page until it finishes rendering!</strong></div>",
                "</div>";
        } else {
            if (isset($Qreq->emailBody) && $Me->privChair
                && (strpos($Qreq->emailBody, "%REVIEWS%")
                    || strpos($Qreq->emailBody, "%COMMENTS%"))) {
                if (!$Conf->timeAuthorViewReviews())
                    echo "<div class='warning'>Although these mails contain reviews and/or comments, authors can’t see reviews or comments on the site. (<a href='", hoturl("settings", "group=dec"), "' class='nw'>Change this setting</a>)</div>\n";
                else if (!$Conf->timeAuthorViewReviews(true))
                    echo "<div class='warning'>Mails to users who have not completed their own reviews will not include reviews or comments. (<a href='", hoturl("settings", "group=dec"), "' class='nw'>Change the setting</a>)</div>\n";
            }
            if (isset($Qreq->emailBody) && $Me->privChair
                && substr($recipients, 0, 4) == "dec:") {
                if (!$Conf->timeAuthorViewDecision())
                    echo "<div class='warning'>You appear to be sending an acceptance or rejection notification, but authors can’t see paper decisions on the site. (<a href='", hoturl("settings", "group=dec"), "' class='nw'>Change this setting</a>)</div>\n";
            }
            echo "<div id='foldmail' class='foldc fold2c'>",
                "<div class='fn fx2 warning'>In the process of preparing mail.  You will be able to send the prepared mail once this message disappears.<br /><span id='mailcount'></span></div>",
                "<div id='mailwarnings'></div>",
                "<div class='fx info'>Verify that the mails look correct, then select “Send” to send the checked mails.<br />",
                "Mailing to:&nbsp;", $this->recip->unparse(),
                "<span id='mailinfo'></span>";
            if (!preg_match('/\A(?:pc\z|pc:|all\z)/', $recipients)
                && $Qreq->plimit
                && $Qreq->q !== "")
                echo "<br />Paper selection:&nbsp;", htmlspecialchars($Qreq->q);
            echo "</div>";
            $this->echo_actions(" fx");
            // This next is only displayed when Javascript is off
            echo '<div class="fn2 warning">Scroll down to send the prepared mail once the page finishes loading.</div>',
                "</div>\n";
        }
        echo Ht::unstash_script("fold('mail',0,2)");
        $this->started = true;
    }

    private function echo_mailinfo($nrows_done, $nrows_left) {
        global $Conf;
        if (!$this->started)
            $this->echo_prologue();
        $s = "\$\$('mailcount').innerHTML=\"" . round(100 * $nrows_done / max(1, $nrows_left)) . "% done.\";";
        if (!$this->sending) {
            $m = plural($this->mcount, "mail") . ", "
                . plural($this->mrecipients, "recipient");
            if (count($this->mpapers) != 0)
                $m .= ", " . plural($this->mpapers, "paper");
            $s .= "\$\$('mailinfo').innerHTML=\"<span class='barsep'>·</span>" . $m . "\";";
        }
        if (!$this->sending && $this->groupable)
            $s .= "\$('.mail_groupable').show();";
        echo Ht::unstash_script($s);
    }

    private static function fix_body($prep) {
        if (preg_match('^\ADear (author|reviewer)\(s\)([,;!.\s].*)\z^s', $prep->body, $m))
            $prep->body = "Dear " . $m[1] . (count($prep->to) == 1 ? "" : "s") . $m[2];
    }

    private function send_prep($prep) {
        global $Conf, $Opt, $Qreq;

        $cbkey = "c" . join("_", $prep->contacts);
        if ($this->sending && !$Qreq[$cbkey])
            return;
        set_time_limit(30);
        $this->echo_prologue();

        self::fix_body($prep);
        ++$this->mcount;
        if ($this->sending) {
            Mailer::send_preparation($prep);
            foreach ($prep->contacts as $cid)
                $Conf->log("Account was sent mail" . $this->mailid_text, $cid, -1);
        }

        // hide passwords from non-chair users
        $show_prep = $prep;
        if (@$prep->sensitive) {
            $show_prep = $prep->sensitive;
            $show_prep->to = $prep->to;
            self::fix_body($show_prep);
        }

        echo '<div class="mail"><table>';
        $nprintrows = 0;
        foreach (array("To", "cc", "bcc", "reply-to", "Subject") as $k) {
            if ($k == "To") {
                $vh = array();
                foreach ($show_prep->to as $to)
                    $vh[] = htmlspecialchars(MimeText::decode_header($to));
                $vh = '<div style="max-width:60em"><span class="nw">' . join(',</span> <span class="nw">', $vh) . '</span></div>';
            } else if ($k == "Subject")
                $vh = htmlspecialchars(MimeText::decode_header($show_prep->subject));
            else if (($line = @$show_prep->headers[$k])) {
                $k = substr($line, 0, strlen($k));
                $vh = htmlspecialchars(MimeText::decode_header(substr($line, strlen($k) + 2)));
            } else
                continue;
            echo " <tr>";
            if (++$nprintrows > 1)
                echo "<td class='mhpad'></td>";
            else if ($this->sending)
                echo "<td class='mhx'></td>";
            else {
                ++$this->cbcount;
                echo '<td class="mhcb"><input type="checkbox" class="cb" name="', $cbkey,
                    '" value="1" checked="checked" data-range-type="mhcb" id="psel', $this->cbcount,
                    '" onclick="rangeclick(event,this)" /></td>';
            }
            echo '<td class="mhnp">', $k, ":</td>",
                '<td class="mhdp">', $vh, "</td></tr>\n";
        }

        echo " <tr><td></td><td></td><td class='mhb'><pre class='email'>",
            Ht::link_urls(htmlspecialchars($show_prep->body)),
            "</pre></td></tr>\n",
            "<tr><td class='mhpad'></td><td></td><td class='mhpad'></td></tr>",
            "</table></div>\n";
    }

    private function process_prep($prep, &$last_prep, $row) {
        // Don't combine senders if anything differs. Also, don't combine
        // mails from different papers, unless those mails are to the same
        // person.
        $mail_differs = CS61Mailer::preparation_differs($prep, $last_prep);
        $prep_to = $prep->to;

        if (!$mail_differs)
            $this->groupable = true;
        if ($mail_differs || !$this->group) {
            if (!@$last_prep->fake)
                $this->send_prep($last_prep);
            $last_prep = $prep;
            $last_prep->contacts = array();
            $last_prep->to = array();
        }

        if (@$prep->fake || isset($last_prep->contacts[$row->contactId]))
            return false;
        else {
            $last_prep->contacts[$row->contactId] = $row->contactId;
            $this->mrecipients[$row->contactId] = true;
            CS61Mailer::merge_preparation_to($last_prep, $prep_to);
            return true;
        }
    }

    private function run() {
        global $Conf, $Opt, $Me, $Qreq, $Error, $subjectPrefix, $mailer_options;

        $subject = trim((string) $Qreq->subject);
        if (substr($subject, 0, strlen($subjectPrefix)) != $subjectPrefix)
            $subject = $subjectPrefix . $subject;
        $emailBody = $Qreq->emailBody;
        $template = array("subject" => $subject, "body" => $emailBody);
        $rest = array("cc" => $Qreq->cc, "reply-to" => $Qreq->replyto,
                      "pset" => $this->recip->pset, "no_error_quit" => true);
        $rest = array_merge($rest, $mailer_options);

        $mailer = new CS61Mailer($Me, null, $rest);
        $prep = $mailer->make_preparation($template, $rest);
        $paper_sensitive = preg_match('/%[A-Z0-9]+[(%]/', $prep->subject . $prep->body);

        $q = $this->recip->query($paper_sensitive);
        if (!$q)
            return $Conf->errorMsg("Bad recipients value");
        $result = $Conf->qe_raw($q);
        if (!$result)
            return;
        $recipients = (string) $Qreq->recipients;

        if ($this->sending) {
            $q = "recipients='" . sqlq($recipients)
                . "', cc='" . sqlq($Qreq->cc)
                . "', replyto='" . sqlq($Qreq->replyto)
                . "', subject='" . sqlq($Qreq->subject)
                . "', emailBody='" . sqlq($Qreq->emailBody) . "'";
            if ($Conf->sversion >= 79)
                $q .= ", q='" . sqlq($Qreq->q) . "', t='" . sqlq($Qreq->t) . "'";
            if (($log_result = Dbl::query_raw("insert into MailLog set $q")))
                $this->mailid_text = " #" . $log_result->insert_id;
            $Me->log_activity("Sending mail$this->mailid_text \"$subject\"");
        } else
            $rest["no_send"] = true;

        $mailer = new CS61Mailer;
        $fake_prep = (object) array("subject" => "", "body" => "", "to" => array(),
                                    "contactId" => array(), "fake" => 1);
        $last_prep = $fake_prep;
        $nrows_done = 0;
        $nrows_left = edb_nrows($result);
        $nwarnings = 0;
        $preperrors = array();
        while (($row = edb_orow($result))) {
            ++$nrows_done;

            $contact = new Contact($row);
            $mailer->reset($contact, $row, $rest);
            $prep = $mailer->make_preparation($template, $rest);

            if (@$prep->errors) {
                foreach ($prep->errors as $lcfield => $hline) {
                    $reqfield = ($lcfield == "reply-to" ? "replyto" : $lcfield);
                    $Error[$reqfield] = true;
                    $emsg = Mailer::$email_fields[$lcfield] . " destination isn’t a valid email list: <blockquote><tt>" . htmlspecialchars($hline) . "</tt></blockquote> Make sure email address are separated by commas; put names in \"quotes\" and email addresses in &lt;angle brackets&gt;.";
                    if (!isset($preperrors[$emsg]))
                        $Conf->errorMsg($emsg);
                    $preperrors[$emsg] = true;
                }
            } else if ($this->process_prep($prep, $last_prep, $row)) {
                if ((!$Me->privChair || @$Opt["chairHidePasswords"])
                    && !@$last_prep->sensitive) {
                    $srest = array_merge($rest, array("sensitivity" => "display"));
                    $mailer->reset($contact, $row, $srest);
                    $last_prep->sensitive = $mailer->make_preparation($template, $srest);
                }
            }

            if ($nwarnings != $mailer->nwarnings() || $nrows_done % 5 == 0)
                $this->echo_mailinfo($nrows_done, $nrows_left);
            if ($nwarnings != $mailer->nwarnings()) {
                $this->echo_prologue();
                $nwarnings = $mailer->nwarnings();
                echo "<div id='foldmailwarn$nwarnings' class='hidden'><div class='warning'>", join("<br />", $mailer->warnings()), "</div></div>";
                echo Ht::unstash_script("\$\$('mailwarnings').innerHTML = \$\$('foldmailwarn$nwarnings').innerHTML;");
            }
        }

        $this->process_prep($fake_prep, $last_prep, (object) array());
        $this->echo_mailinfo($nrows_done, $nrows_left);

        if (!$this->started && !count($preperrors))
            return $Conf->errorMsg("No users match “" . $this->recip->unparse() . "” for that search.");
        else if (!$this->started)
            return false;
        else if (!$this->sending)
            $this->echo_actions();
        echo "</div></form>";
        echo Ht::unstash_script("fold('mail', null);");
        $Conf->footer();
        exit;
    }

}


// Check paper outcome counts
$result = $Conf->q("select outcome, count(paperId), max(leadContactId), max(shepherdContactId) from Paper group by outcome");
$noutcome = array();
$anyLead = $anyShepherd = false;
while (($row = edb_row($result))) {
    $noutcome[$row[0]] = $row[1];
    if ($row[2])
        $anyLead = true;
    if ($row[3])
        $anyShepherd = true;
}

// Load template
if ($Qreq->loadtmpl) {
    $t = $Qreq->get("template", "genericmailtool");
    if (!isset($mailTemplates[$t])
        || !isset($mailTemplates[$t]["mailtool_name"]))
        $t = "genericmailtool";
    $template = $mailTemplates[$t];
    $Qreq->recipients = get($template, "mailtool_recipients", "s");
    if (($space = strpos($Qreq->recipients, " ")) !== false) {
        $Qreq->userrecipients = substr($Qreq->recipients, $space + 1);
        $Qreq->recipients = substr($Qreq->recipients, 0, $space);
    }
    if (isset($template["mailtool_search_type"]))
        $Qreq->t = $template["mailtool_search_type"];
    $Qreq->subject = $null_mailer->expand($template["subject"]);
    $Qreq->emailBody = $null_mailer->expand($template["body"]);
}


// Set recipients list, now that template is loaded
$recip = new MailRecipients($Me, (string) $Qreq->recipients, (string) $Qreq->userrecipients);


// Set subject and body if necessary
if (!isset($Qreq->subject))
    $Qreq->subject = $null_mailer->expand($mailTemplates["genericmailtool"]["subject"]);
if (!isset($Qreq->emailBody))
    $Qreq->emailBody = $null_mailer->expand($mailTemplates["genericmailtool"]["body"]);
if (substr($Qreq->subject, 0, strlen($subjectPrefix)) == $subjectPrefix)
    $Qreq->subject = substr($Qreq->subject, strlen($subjectPrefix));
if (isset($Qreq->cc) && $Me->privChair)
    $Qreq->cc = simplify_whitespace($Qreq->cc);
else if (isset($Opt["emailCc"]))
    $Qreq->cc = $Opt["emailCc"] ? $Opt["emailCc"] : "";
else
    $Qreq->cc = Text::user_email_to(Contact::site_contact());
if (isset($Qreq->replyto) && $Me->privChair)
    $Qreq->replyto = simplify_whitespace($Qreq->replyto);
else
    $Qreq->replyto = defval($Opt, "emailReplyTo", "");


// Check or send
if ($Qreq->loadtmpl || $Qreq->cancel)
    /* do nothing */;
else if ($Qreq->send && !$recip->error && check_post())
    MailSender::send($recip);
else if (($Qreq->check || $Qreq->group || $Qreq->ungroup)
         && !$recip->error
         && check_post())
    MailSender::check($recip);


if ($Qreq->monreq) {
    $plist = new PaperList(new PaperSearch($Me, array("t" => "req", "q" => "")), array("list" => true));
    $ptext = $plist->text("reqrevs", array("header_links" => true));
    if ($plist->count == 0)
        $Conf->infoMsg("You have not requested any external reviews.  <a href='", hoturl("index"), "'>Return home</a>");
    else {
        echo "<h2>Requested reviews</h2>\n\n", $ptext, "<div class='info'>";
        if ($plist->any->need_review)
            echo "Some of your requested external reviewers have not completed their reviews.  To send them an email reminder, check the text below and then select &ldquo;Prepare mail.&rdquo;  You’ll get a chance to review the emails and select specific reviewers to remind.";
        else
            echo "All of your requested external reviewers have completed their reviews.  <a href='", hoturl("index"), "'>Return home</a>";
        echo "</div>\n";
    }
    if (!$plist->any->need_review) {
        $Conf->footer();
        exit;
    }
}

echo Ht::form_div(hoturl_post("mail", "check=1")),
    Ht::hidden_default_submit("default", 1), "

<div class='aa' style='padding-left:8px'>
  <strong>Template:</strong> &nbsp;";
$tmpl = array();
foreach ($mailTemplates as $k => $v) {
    if (isset($v["mailtool_name"])
        && ($Me->privChair || defval($v, "mailtool_pc")))
        $tmpl[$k] = defval($v, "mailtool_priority", 100);
}
asort($tmpl);
foreach ($tmpl as $k => &$v) {
    $v = $mailTemplates[$k]["mailtool_name"];
}
if (!$Qreq->template || !isset($tmpl[$Qreq->template]))
    $Qreq->template = "genericmailtool";
echo Ht::select("template", $tmpl, $Qreq->template, array("onchange" => "highlightUpdate(\"loadtmpl\")")),
    " &nbsp;",
    Ht::submit("loadtmpl", "Load", array("id" => "loadtmpl")),
    " &nbsp;
 <span class='hint'>Templates are mail texts tailored for common conference tasks.</span>
</div>

<div class='mail' style='float:left;margin:4px 1em 12px 0'><table>\n";

// ** TO
echo '<tr><td class="mhnp">To:</td><td class="mhdd">',
    $recip->selectors(),
    "<div class='g'></div>\n";

// paper selection
echo '<div id="foldpsel" class="fold8c fold9o fold10c">';
echo '<table class="fx9"><tr><td>',
    Ht::checkbox("plimit", 1, isset($Qreq->plimit),
                  array("id" => "plimit",
                        "onchange" => "fold('psel', !this.checked, 8)")),
    "&nbsp;</td><td>", Ht::label("Choose individual papers", "plimit");
echo "<span class='fx8'>:</span><br /><div class='fx8'>";
$q = $Qreq->get("q", "(All)");
echo "Search&nbsp; ",
    Ht::entry("q", $Qreq->q,
              array("id" => "q", "hottemptext" => "(All)",
                    "class" => "hotcrp_searchbox", "size" => 36,
                    "title" => "Enter paper numbers or search terms")),
    " &nbsp;in &nbsp;",
    Ht::select("t", $tOpt, $Qreq->t, array("id" => "t")),
    "</div></td></tr></table>\n";

echo '<div class="fx9 g"></div></div>';

Ht::stash_script("fold(\"psel\",!\$\$(\"plimit\").checked,8);"
                 . "setmailpsel(\$\$(\"recipients\"))");

echo "</td></tr>\n";

// ** CC, REPLY-TO
if ($Me->privChair) {
    foreach (Mailer::$email_fields as $lcfield => $field)
        if ($lcfield !== "to" && $lcfield !== "bcc") {
            $xfield = ($lcfield == "reply-to" ? "replyto" : $lcfield);
            $ec = (isset($Error[$xfield]) ? " error" : "");
            echo "  <tr><td class='mhnp$ec'>$field:</td><td class='mhdp$ec'>",
                "<input type='text' class='textlite-tt' name='$xfield' value=\"",
                htmlspecialchars($Qreq[$xfield]), "\" size='64' />",
                ($xfield == "replyto" ? "<div class='g'></div>" : ""),
                "</td></tr>\n\n";
        }
}

// ** SUBJECT
echo "  <tr><td class='mhnp'>Subject:</td><td class='mhdp'>",
    "<tt>[", htmlspecialchars($Opt["shortName"]), "]&nbsp;</tt><input type='text' class='textlite-tt' name='subject' value=\"", htmlspecialchars($Qreq->subject), "\" size='64' /></td></tr>

 <tr><td></td><td class='mhb'>
  <textarea class='tt' rows='20' name='emailBody' cols='80'>", htmlspecialchars($Qreq->emailBody), "</textarea>
 </td></tr>
</table></div>\n\n";


if ($Me->privChair) {
    $result = $Conf->qe("select * from MailLog order by mailId desc limit 18");
    if (edb_nrows($result)) {
        echo "<div style='padding-top:12px'>",
            "<strong>Recent mails:</strong>\n";
        while (($row = edb_orow($result))) {
            echo "<div class='mhdd'><div style='position:relative;overflow:hidden'>",
                "<div style='position:absolute;white-space:nowrap'><a class='q' href=\"", hoturl("mail", "fromlog=" . $row->mailId), "\">", htmlspecialchars($row->subject), " &ndash; <span class='dim'>", htmlspecialchars($row->emailBody), "</span></a></div>",
                "<br /></div></div>\n";
        }
        echo "</div>\n\n";
    }
}


echo "<div class='aa' style='clear:both'>\n",
    Ht::submit("Prepare mail"), " &nbsp; <span class='hint'>You’ll be able to review the mails before they are sent.</span>
</div>


<div id='mailref'>Keywords enclosed in percent signs, such as <code>%NAME%</code> or <code>%REVIEWDEADLINE%</code>, are expanded for each mail.  Use the following syntax:
<div class='g'></div>
<table>
<tr><td class='plholder'><table>
<tr><td class='lxcaption'><code>%URL%</code></td>
    <td class='llentry'>Site URL.</td></tr>
<tr><td class='lxcaption'><code>%LOGINURL%</code></td>
    <td class='llentry'>URL for recipient to log in to the site.</td></tr>
<tr><td class='lxcaption'><code>%NUMSUBMITTED%</code></td>
    <td class='llentry'>Number of papers submitted.</td></tr>
<tr><td class='lxcaption'><code>%NUMACCEPTED%</code></td>
    <td class='llentry'>Number of papers accepted.</td></tr>
<tr><td class='lxcaption'><code>%NAME%</code></td>
    <td class='llentry'>Full name of recipient.</td></tr>
<tr><td class='lxcaption'><code>%FIRST%</code>, <code>%LAST%</code></td>
    <td class='llentry'>First and last names, if any, of recipient.</td></tr>
<tr><td class='lxcaption'><code>%EMAIL%</code></td>
    <td class='llentry'>Email address of recipient.</td></tr>
<tr><td class='lxcaption'><code>%REVIEWDEADLINE%</code></td>
    <td class='llentry'>Reviewing deadline appropriate for recipient.</td></tr>
</table></td><td class='plholder'><table>
<tr><td class='lxcaption'><code>%NUMBER%</code></td>
    <td class='llentry'>Paper number relevant for mail.</td></tr>
<tr><td class='lxcaption'><code>%TITLE%</code></td>
    <td class='llentry'>Paper title.</td></tr>
<tr><td class='lxcaption'><code>%TITLEHINT%</code></td>
    <td class='llentry'>First couple words of paper title (useful for mail subject).</td></tr>
<tr><td class='lxcaption'><code>%OPT(AUTHORS)%</code></td>
    <td class='llentry'>Paper authors (if recipient is allowed to see the authors).</td></tr>
<tr><td><div class='g'></div></td></tr>
<tr><td class='lxcaption'><code>%REVIEWS%</code></td>
    <td class='llentry'>Pretty-printed paper reviews.</td></tr>
<tr><td class='lxcaption'><code>%COMMENTS%</code></td>
    <td class='llentry'>Pretty-printed paper comments, if any.</td></tr>
<tr><td class='lxcaption'><code>%COMMENTS(TAG)%</code></td>
    <td class='llentry'>Comments tagged #TAG, if any.</td></tr>
<tr><td><div class='g'></div></td></tr>
<tr><td class='lxcaption'><code>%IF(SHEPHERD)%...%ENDIF%</code></td>
    <td class='llentry'>Include text only if a shepherd is assigned.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERD%</code></td>
    <td class='llentry'>Shepherd name and email, if any.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERDNAME%</code></td>
    <td class='llentry'>Shepherd name, if any.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERDEMAIL%</code></td>
    <td class='llentry'>Shepherd email, if any.</td></tr>
<tr><td class='lxcaption'><code>%TAGVALUE(t)%</code></td>
    <td class='llentry'>Value of paper’s tag <code>t</code>.</td></tr>
</table></td></tr>
</table></div>

</div></form>\n";

$Conf->footer();
