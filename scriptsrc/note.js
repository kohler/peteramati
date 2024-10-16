// note.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, removeClass } from "./ui.js";
import { escape_entities } from "./encoders.js";
import { hoturl } from "./hoturl.js";
import { api_conditioner } from "./xhr.js";
import { ftext, render_onto } from "./render.js";
import { text_eq } from "./utils.js";
import { Filediff, Linediff } from "./diff.js";


export class Note {
    constructor() {
        this.iscomment = false;
        this.ftext = "";
        this.format = null;
    }

    empty() {
        return this.ftext === "";
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

    get linea() {
        return new Linediff(this.element || this.source).linea;
    }

    linea_within(bound) {
        return new Linediff(this.element || this.source).linea_within(bound);
    }

    get editable_text() {
        const ft = ftext.parse(this.ftext);
        return ft[1];
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
        let myftext = [null, ""];
        if (typeof x === "number") {
            this.iscomment = false;
            this.ftext = "";
            this.users = null;
            this.version = x;
        } else if (Array.isArray(x)) {
            this.iscomment = x[0];
            this.ftext = x[1];
            myftext = ftext.parse(x[1]);
            this.users = x[2] || null;
            this.version = x[3] && typeof x[3] === "number" ? x[3] : null;
        }
        this.format = myftext[0];

        const elt = this.element;
        if (elt) {
            if (this.ftext === "" && this.version == null) {
                elt.removeAttribute("data-pa-note");
            } else if (this.ftext === "" && this.users == null) {
                elt.setAttribute("data-pa-note", "" + this.version);
            } else {
                const a = [this.iscomment, this.ftext];
                if (this.users != null || this.version != null) {
                    a.push(this.users, this.version);
                }
                elt.setAttribute("data-pa-note", JSON.stringify(a));
            }
        }
        return this;
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
            } else if (e === t || hasClass(e, "pa-gn") || hasClass(e, "pa-gx") || e.hidden) {
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
            if (this.version || this.ftext !== "") {
                this.element.setAttribute("data-pa-note", JSON.stringify([this.iscomment, this.ftext, this.users, this.version]));
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
            $(this.element).find("textarea").val(this.editable_text);
        }
        return this;
    }

    render(transition) {
        this.force_element();

        if (hasClass(this.element, "editing")) {
            const $text = $(this.element).find("textarea");
            if ($text.length && !text_eq(this.editable_text, $text.val().replace(/\s+$/, ""))) {
                return false;
            }
            removeClass(this.element, "editing");
            $(this.element).find(":focus").blur();
        }

        const $td = $(this.element).find(".pa-notebox"),
            $content = $td.children();
        if (transition) {
            $content.slideUp(80).queue(function () { $content.remove(); });
        } else {
            $content.remove();
        }

        if (this.ftext === "") {
            fix_links_at(this.element);
            if (transition) {
                $(this.element).children().slideUp(80);
            } else {
                this.element.hidden = true;
            }
            return true;
        }

        const contdiv = document.createElement("div");
        contdiv.className = "pa-notecontent clearfix";
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
                const authordiv = document.createElement("div");
                authordiv.className = "pa-note-author";
                authordiv.append("[" + authors.join(", ") + "]");
                contdiv.append(authordiv);
            }
        }
        if (!this.iscomment) {
            const markdiv = document.createElement("div");
            markdiv.className = "pa-gradenote-marker";
            contdiv.append(markdiv);
        }
        const notediv = document.createElement("div");
        notediv.className = "pa-dr pa-note pa-".concat(this.iscomment ? "comment" : "grade", "note");
        contdiv.append(notediv);
        $td.append(contdiv);

        render_onto(notediv, "f", this.ftext);

        fix_links_at(this.element);
        if (transition) {
            $(contdiv).hide().slideDown(80);
        } else {
            $td.show();
        }
        this.element.hidden = false;
        return true;
    }

    save_ftext(ftext, iscomment) {
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

        const data = {ftext: ftext}, linea = this.linea_within(1000);
        if (iscomment) {
            data.iscomment = 1;
        }
        if (linea) {
            data.linea = linea;
        }

        if (grb) {
            grb.setAttribute("data-pa-notes-outstanding", +grb.getAttribute("data-pa-notes-outstanding") + 1);
        }

        return new Promise(function (resolve, reject) {
            api_conditioner(
                hoturl("=api/linenote", {
                    psetinfo: pi, file: self.file, line: self.lineid, oldversion: self.version || 0
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

    save_text(text, iscomment) {
        let format = this.format;
        if (format == null) {
            format = +document.body.getAttribute("data-default-format");
            if (isNaN(format))
                format = 0;
        }
        return this.save_ftext(ftext.unparse(format, text), iscomment);
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
