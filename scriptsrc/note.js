// note.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, removeClass } from "./ui.js";
import { render_text } from "./render.js";
import { linediff_traverse, linediff_locate, linediff_find,
    linediff_lineid } from "./diff.js";


export class Note {
    constructor() {
        this.iscomment = false;
        this.text = "";
    }

    empty() {
        return this.text === "";
    }

    store_at(elt) {
        if (this.text === "" && this.version == null) {
            elt.removeAttribute("data-pa-note");
        } else if (this.text === "" && this.users == null) {
            elt.setAttribute("data-pa-note", "" + this.version);
        } else {
            const a = [this.iscomment, this.text];
            if (this.users != null || this.version != null || this.format != null) {
                a.push(this.users, this.version, this.format);
            }
            elt.setAttribute("data-pa-note", JSON.stringify(a));
        }
    }

    static parse(x) {
        const n = new Note;
        if (typeof x === "string" && x !== "") {
            x = JSON.parse(x);
        }
        if (typeof x === "number") {
            n.version = x;
        } else if (Array.isArray(x)) {
            n.iscomment = x[0];
            n.text = x[1];
            n.users = x[2] || null;
            n.version = (x[3] && typeof x[3] === "number" ? x[3] : null);
            n.format = (x[4] && typeof x[4] === "number" ? x[4] : null);
        }
        return n;
    }

    static at(elt) {
        const n = Note.parse(elt.getAttribute("data-pa-note"));
        n.element = elt;
        return n;
    }

    static html_skeleton_near(elt) {
        if (hasClass(elt, "pa-gw")) {
            return elt;
        }

        // traverse to previous visible line
        let line = elt.closest(".pa-dl");
        while (line && (line.nodeType !== Node.ELEMENT_NODE
                        || !line.offsetParent
                        || (!hasClass(line, "pa-gi") && !hasClass(line, "pa-gc") && !hasClass(line, "pa-gd")))) {
            line = line.previousSibling;
        }
        if (!line) {
            throw new Error("bad html_skeleton_near");
        }

        // determine lineid
        const lineid = linediff_lineid(line),
            isa = lineid.charAt(0) === "a",
            lineno = +lineid.substring(1);

        // traverse forward to insertion point
        let astart = null;
        line = line.nextSibling;
        while (line) {
            if (line.nodeType === Node.ELEMENT_NODE) {
                if (hasClass(line, "pa-gx")) {
                    break;
                } else if (hasClass(line, "pa-gi") || hasClass(line, "pa-gc")) {
                    if (isa || lineno < +line.firstChild.nextSibling.getAttribute("data-landmark")) {
                        break;
                    }
                    astart = null;
                } else if (hasClass(line, "pa-gd")) {
                    if (isa && lineno < +line.firstChild.getAttribute("data-landmark")) {
                        astart = line;
                        break;
                    }
                    astart = astart || line;
                }
            }
            line = line.nextSibling;
        }

        // perform insertion
        const tr = $('<div class="pa-dl pa-gw" data-landmark="'.concat(lineid, '"><div class="pa-notebox"></div></div>'))[0];
        elt.parentElement.insertBefore(tr, astart || line);
        return tr;
    }

    html_near(elt, transition) {
        var tr = Note.html_skeleton_near(elt), $tr = $(tr);
        this.store_at(tr);
        var $td = $tr.find(".pa-notebox"), $content = $td.children();
        if (transition) {
            $content.slideUp(80).queue(function () { $content.remove(); });
        } else {
            $content.remove();
        }

        if (this.text === "") {
            fix_links_at(tr);
            if (transition) {
                $tr.children().slideUp(80);
            } else {
                addClass(tr, "hidden");
            }
            return tr;
        }

        let t = '<div class="pa-notecontent clearfix">';
        if (this.users) {
            const authorids = $.isArray(this.users) ? this.users : [this.users],
                authors = [];
            for (let i in authorids) {
                const p = siteinfo.pc[authorids[i]];
                if (p) {
                    if (p.nick) {
                        authors.push(p.nick);
                    } else if (p.nicklen || p.lastpos) {
                        authors.push(p.name.substr(0, p.nicklen || p.lastpos - 1));
                    } else {
                        authors.push(p.name);
                    }
                }
            }
            if (authors.length) {
                t += '<div class="pa-note-author">[' + authors.join(', ') + ']</div>';
            }
        }
        t += '<div class="pa-note pa-' + (this.iscomment ? 'comment' : 'grade') + 'note';
        if (this.format) {
            t += '" data-format="' + this.format;
        }
        t += '"></div></div>';
        $td.append(t);

        if (!this.format) {
            $td.find(".pa-note").addClass("format0").text(this.text);
        } else {
            const r = render_text(this.format, this.text);
            $td.find(".pa-note").addClass("format" + (r.format || 0)).html(r.content);
        }

        fix_links_at(tr);

        if (transition) {
            $td.find(".pa-notecontent").hide().slideDown(80);
        } else {
            removeClass(tr, "hidden");
        }
        return tr;
    }

    load_fileid() {
        const tr = this.element,
            table = tr.closest(".pa-filediff");
        this._file = table.getAttribute("data-pa-file");
        if (tr.hasAttribute("data-landmark")) {
            this._lineid = tr.getAttribute("data-landmark");
        } else {
            this._lineid = linediff_lineid(linediff_traverse(tr, false, 1));
        }
    }

    get file() {
        if (!this._file && this.element) {
            this.load_fileid();
        }
        return this._file;
    }

    get lineid() {
        if (!this._lineid && this.element) {
            this.load_fileid();
        }
        return this._lineid;
    }
}


function note_anchor(tr) {
    let anal = linediff_locate(tr), td;
    if (anal && (td = linediff_find(anal.ufile, anal.lineid))) {
        return "#" + td.id;
    } else {
        return "";
    }
}

function set_link(tr, next_tr) {
    let $a = $(tr).find(".pa-note-links a");
    if (!$a.length) {
        $a = $('<a></a>');
        $('<div class="pa-note-links"></div>').append($a).prependTo($(tr).find(".pa-notecontent"));
    }

    $a.attr("href", note_anchor(next_tr));
    const t = next_tr ? "NEXT >" : "TOP";
    if ($a.text() !== t) {
        $a.text(t);
    }
}

function fix_links_at(tr) {
    const trs = $(".pa-gw");
    let notepos = 0;
    while (notepos < trs.length && trs[notepos] !== tr) {
        ++notepos;
    }
    if (notepos < trs.length) {
        let prevpos = notepos - 1;
        while (prevpos >= 0 && Note.at(trs[prevpos]).empty()) {
            --prevpos;
        }

        let nextpos = notepos + 1;
        while (nextpos < trs.length && Note.at(trs[nextpos]).empty()) {
            ++nextpos;
        }

        if (prevpos >= 0) {
            set_link(trs[prevpos], Note.at(tr).empty() ? trs[nextpos] : tr);
        }
        set_link(this, trs[nextpos]);
    }
}
