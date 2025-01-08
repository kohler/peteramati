<?php
// useractions.php -- HotCRP helpers for user actions
// HotCRP is Copyright (c) 2008-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class UserActions {
    static private function modify_password_mail($where, $dopassword, $sendtype, $ids) {
        global $Conf;
        $j = (object) array("ok" => true);
        $result = $Conf->qe("select * from ContactInfo where $where and contactId?a", $ids);
        while (($Acct = Contact::fetch($result, $Conf))) {
            if ($dopassword)
                $Acct->change_password(null, null, Contact::CHANGE_PASSWORD_PLAINTEXT | Contact::CHANGE_PASSWORD_NO_CDB);
            if ($sendtype && $Acct->plaintext_password() && !$Acct->is_disabled())
                $Acct->sendAccountInfo($sendtype, false);
            else if ($sendtype)
                $j->warnings[] = "Not sending mail to disabled account " . htmlspecialchars($Acct->email) . ".";
        }
        return $j;
    }

    static function disable($ids, $contact) {
        global $Conf;
        $result = Dbl::qe("update ContactInfo set disabled=1 where contactId?a and contactId!=?", $ids, $contact->contactId);
        if ($result && $result->affected_rows)
            return (object) array("ok" => true);
        else if ($result)
            return (object) array("ok" => true, "warnings" => array("Those accounts were already disabled."));
        else
            return (object) array("error" => true);
    }

    static function enable($ids, $contact) {
        global $Conf;
        $result = $Conf->qe("update ContactInfo set disabled=1 where contactId?a and password='' and contactId!=?", $ids, $contact->contactId);
        $result = $Conf->qe("update ContactInfo set disabled=0 where contactId?a and contactId!=?", $ids, $contact->contactId);
        if ($result && $result->affected_rows)
            return self::modify_password_mail("password='' and contactId!=" . $contact->contactId, true, "create", $ids);
        else if ($result)
            return (object) array("ok" => true, "warnings" => array("Those accounts were already enabled."));
        else
            return (object) array("error" => true);
    }

    static function reset_password($ids, $contact, $ifempty) {
        global $Conf;
        $result = self::modify_password_mail("contactId!=" . $contact->contactId . ($ifempty ? " and password=''" : ""), true, false, $ids);
        $Conf->success_msg("Passwords reset.");
        return $result;
    }

    static function send_account_info($ids, $contact) {
        global $Conf;
        return self::modify_password_mail("true", false, "send", $ids);
        $Conf->success_msg("Account information sent.");
    }
}
