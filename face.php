<?php
// face.php -- Peteramati face page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");

class Face_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var ?Pset */
    public $pset;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
    }

    static function handle_image(Contact $user, $imageid, Contact $viewer) {
        if (($user === $viewer || $viewer->isPC)
            && $imageid
            && ctype_digit($imageid)
            && ($result = Dbl::qe("select mimetype, `data` from ContactImage where contactId=? and contactImageId=?", $user->contactId, $imageid))
            && ($row = $result->fetch_row())) {
            header("Content-Type: $row[0]");
            header("Cache-Control: public, max-age=31557600");
            header("Expires: " . gmdate("D, d M Y H:i:s", Conf::$now + 31557600) . " GMT");
            if (zlib_get_coding_type() === false) {
                header("Content-Length: " . strlen($row[1]));
            }
            echo $row[1];
        } else {
            header("Content-Type: image/gif");
            if (zlib_get_coding_type() === false) {
                header("Content-Length: 43");
            }
            echo "GIF89a\001\0\001\0\x80\0\0\0\0\0\0\0\0\x21\xf9\x04\x01\0\0\0\0\x2c\0\0\0\0\x01\0\x01\0\0\x02\x02\x44\x01\0\x3b";
        }
    }

    /** @param Contact $user */
    function face_output($user) {
        $u = $this->viewer->user_linkpart($user);
        if ($this->pset) {
            $link = $this->conf->hoturl("pset", ["u" => $u, "pset" => $this->pset->urlkey]);
        } else {
            $link = $this->conf->hoturl("index", ["u" => $u]);
        }
        echo '<div class="pa-facebook-entry">',
            '<a href="', $link, '">',
            '<img class="pa-face" src="' . $this->conf->hoturl("face", ["u" => $u, "imageid" => $user->contactImageId ? : 0]) . '" border="0" />',
            '</a>',
            '<h2><a class="q" href="', $link, '">', htmlspecialchars($u), '</a>';
        if ($this->viewer->privChair) {
            echo "&nbsp;", become_user_link($user);
        }
        echo '</h2>', Text::name_html($user),
            ($user->extension ? " (X)" : ""),
            ($user->email ? " &lt;" . htmlspecialchars($user->email) . "&gt;" : "");
        echo '</div>';
    }

    function render() {
        $this->conf->header("Thefacebook", "face");

        $result = $this->conf->qe("select contactId, email, firstName, lastName, github_username, contactImageId, extension from ContactInfo where roles=0 and dropped=0");
        $users = [];
        while (($user = Contact::fetch($result, $this->conf))) {
            $users[] = $user;
        }
        $sortspec = Contact::parse_sortspec($this->conf, $this->qreq->sort ?? "first");
        usort($users, $this->conf->user_comparator($sortspec));

        $sset = null;
        if ($this->qreq->grade
            && ($dot = strpos($this->qreq->grade, ".")) !== false
            && ($pset = $this->conf->pset_by_key(substr($this->qreq->grade, 0, $dot)))
            && $pset->gitless_grades
            && ($ge = $pset->gradelike_by_key(substr($this->qreq->grade, $dot + 1)))) {
            $sset = StudentSet::make_for($users, $this->viewer);
            $sset->set_pset($pset);
        }

        if ($this->qreq->pset) {
            $this->pset = $this->conf->pset_by_key($this->qreq->pset);
            $items = [];
            foreach ($users as $u) {
                $items[] = "~" . $this->viewer->user_linkpart($u);
            }
            echo '<div class="pa-facebook has-hotlist" data-hotlist="',
                htmlspecialchars(json_encode_browser(["pset" => $this->pset->urlkey, "items" => $items])),
                '">';
        } else {
            echo '<div class="pa-facebook">';
        }
        foreach ($users as $u) {
            $this->face_output($u);
        }
        echo '</div>';

        $this->qreq->print_footer();
    }

    static function run(Contact $viewer, Qrequest $qreq) {
        if ($viewer->is_empty()) {
            $viewer->escape();
        }

        ContactView::set_path_request($qreq, ["/u"], $viewer->conf);
        $user = $viewer;
        if (isset($qreq->u)) {
            $user = ContactView::prepare_user($qreq, $viewer);
        }

        if (isset($qreq->imageid)) {
            self::handle_image($user, $qreq->imageid, $viewer);
        } else if ($viewer->isPC) {
            (new Face_Page($viewer, $qreq))->render();
        } else {
            $viewer->escape();
        }
    }
}

Face_Page::run($Me, $Qreq);
