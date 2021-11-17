// note.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, removeClass } from "./ui.js";
import { escape_entities } from "./encoders.js";
import { hoturl_gradeapi } from "./hoturl.js";
import { api_conditioner } from "./xhr.js";
import { render_text } from "./render.js";
import { text_eq } from "./utils.js";
import { Filediff, Linediff } from "./diff.js";


export class Note {
    constructor() {
        this.iscomment = false;
        this.text = "";
    }

    empty() {
        return this.text === "";
    }

    get file() {
        return Filediff.closest(this.element || this.source).file;
    }

    get lineid() {
        if (this.element) {
            return this.element.getAttribute("data-landmark");
        } else {
            return new Linediff(this.source).note_lineid;
        }
    }

    static parse(x) {
        const n = new Note;
        if (typeof x === "string" && x !== "") {
            x = JSON.parse(x);
        }
        n.assign(x);
        return n;
    }

    assign(x) {
        if (typeof x === "number") {
            this.iscomment = false;
            this.text = "";
            this.users = null;
            this.version = x;
            this.format = null;
        } else if (Array.isArray(x)) {
            this.iscomment = x[0];
            this.text = x[1];
            this.users = x[2] || null;
            this.version = (x[3] && typeof x[3] === "number" ? x[3] : null);
            this.format = (x[4] && typeof x[4] === "number" ? x[4] : null);
        }

        const elt = this.element;
        if (elt) {
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
    }

    static at(elt) {
        const n = Note.parse(elt.getAttribute("data-pa-note"));
        n.element = elt;
        return n;
    }

    static closest(elt) {
        const e = elt.closest(".pa-dl");
        return e ? Note.at(e) : null;
    }

    static near(t) {
        if (t instanceof Linediff) {
            t = t.element;
        }
        let e = t;
        while (e) {
            if (e.nodeType !== 1) {
                e = e.nextSibling;
            } else if (hasClass(e, "pa-gw")) {
                return Note.at(e);
            } else if (e === t || hasClass(e, "pa-gn") || hasClass(e, "pa-gx") || hasClass(e, "hidden")) {
                e = e.nextSibling;
            } else {
                break;
            }
        }
        const n = new Note();
        n.source = t;
        return n;
    }

    force_element() {
        if (!this.element) {
            const source = new Linediff(this.source),
                lineid = source.note_lineid,
                insertion = source.upper_bound(lineid);
            this.element = document.createElement("div");
            this.element.className = "pa-dl pa-gw";
            this.element.setAttribute("data-landmark", lineid);
            if (this.version || this.text !== "") {
                this.element.setAttribute("data-pa-note", JSON.stringify([this.iscomment, this.text, this.users, this.version, this.format]));
            }
            let box = document.createElement("div");
            box.className = "pa-notebox";
            this.element.appendChild(box);
            this.source.parentElement.insertBefore(this.element, insertion ? insertion.element : null);
        }
        return this.element;
    }

    cancel_edit() {
        if (this.element && hasClass(this.element, "editing")) {
            $(this.element).find("textarea").val(this.text);
        }
        return this;
    }

    render(transition) {
        this.force_element();

        if (hasClass(this.element, "editing")) {
            const $text = $(this.element).find("textarea");
            if ($text.length && !text_eq(this.text, $text.val().replace(/\s+$/, ""))) {
                return false;
            }
            removeClass(this.element, "editing");
            $(this.element).find(":focus").blur();
        }

        var $td = $(this.element).find(".pa-notebox"),
            $content = $td.children();
        if (transition) {
            $content.slideUp(80).queue(function () { $content.remove(); });
        } else {
            $content.remove();
        }

        if (this.text === "") {
            fix_links_at(this.element);
            if (transition) {
                $(this.element).children().slideUp(80);
            } else {
                addClass(this.element, "hidden");
            }
            return true;
        }

        let t = '<div class="pa-notecontent clearfix">';
        if (this.users) {
            const authorids = $.isArray(this.users) ? this.users : [this.users],
                authors = [];
            for (let i in authorids) {
                const p = siteinfo.pc ? siteinfo.pc[authorids[i]] : null;
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
        t = t.concat('<div class="pa-dr pa-note pa-', this.iscomment ? 'comment' : 'grade', 'note');
        if (this.format) {
            t = t.concat('" data-format="', this.format);
        }
        t += '"></div></div>';
        $td.append(t);

        if (!this.format) {
            $td.find(".pa-note").addClass("format0").text(this.text);
        } else {
            render_text(this.format, this.text, $td.find(".pa-note")[0]);
        }

        fix_links_at(this.element);
        if (transition) {
            $td.find(".pa-notecontent").hide().slideDown(80);
        } else {
            $td.show();
            removeClass(this.element, "hidden");
        }
        return true;
    }

    save(text, iscomment) {
        if (this.element) {
            if (hasClass(this.element, "pa-outstanding")) {
                return Promise.reject(new Error("Outstanding request"));
            }
            addClass(this.element, "pa-outstanding");
        }

        const editing = this.element && hasClass(this.element, "editing"),
            self = this,
            tr = this.element || this.source,
            fd = tr.closest(".pa-filediff"),
            pi = fd.closest(".pa-psetinfo"),
            grb = tr.closest(".pa-grade-range-block");

        const data = {note: text};
        if (iscomment) {
            data.iscomment = 1;
        }
        data.format = this.format;
        if (data.format == null) {
            data.format = document.body.getAttribute("data-default-format");
        }

        if (grb) {
            grb.setAttribute("data-pa-notes-outstanding", +grb.getAttribute("data-pa-notes-outstanding") + 1);
        }

        return new Promise(function (resolve, reject) {
            api_conditioner(
                hoturl_gradeapi(pi, "=api/linenote", {
                    file: self.file, line: self.lineid, oldversion: self.version || 0
                }), data
            ).then(function (data) {
                self.element && removeClass(self.element, "pa-outstanding");
                if (data && data.ok) {
                    self.force_element();
                    removeClass(self.element, "pa-save-failed");
                    const nd = data.linenotes[self.file];
                    self.assign(nd && nd[self.lineid]);
                    self.render(editing);
                    resolve(self);
                } else {
                    if (self.element) {
                        addClass(self.element, "pa-save-failed");
                    }
                    if (editing) {
                        $(self.element).find(".pa-save-message").html('<strong class="err">' + escape_entities(data.error || "Failed") + '</strong>');
                    }
                    reject(new Error(data.error || "Save failed"));
                }
                if (grb) {
                    resolve_grade_range(grb);
                }
            });
        });
    }
}


function set_link(tr, next_tr) {
    let $a = $(tr).find(".pa-note-links a"), t;
    if (!$a.length) {
        $a = $('<a></a>');
        $('<div class="pa-note-links"></div>').append($a).prependTo($(tr).find(".pa-notecontent"));
    }
    if (next_tr) {
        $a.attr("href", new Linediff(next_tr).hash);
        t = "NEXT >";
    } else {
        $a.attr("href", "");
        t = "TOP";
    }
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
        set_link(tr, trs[nextpos]);
    }
}

function resolve_grade_range(grb) {
    var count = +grb.getAttribute("data-pa-notes-outstanding") - 1;
    if (count) {
        grb.setAttribute("data-pa-notes-outstanding", count);
    } else {
        grb.removeAttribute("data-pa-notes-outstanding");
        $(grb).find(".pa-grade").each(function () {
            $pa.gradeentry_closest(this).save_landmark_grade(grb);
        });
    }
}
