// gradeentry.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities } from "./encoders.js";
import { hasClass, toggleClass } from "./ui.js";
import { linediff_find } from "./diff.js";
import { GradeClass } from "./gc.js";


let id_counter = 0, late_hours_entry;

export class GradeEntry {
    constructor(x) {
        Object.assign(this, x);
        this.type = this.type || "numeric";
        this.gc = GradeClass.find(this.type);
        this._abbr = null;
        this._all = null;
    }

    abbr() {
        this._abbr || this.compute_abbr();
        return this._abbr;
    }

    compute_abbr() {
        const order = this._all ? this._all.order : [this.key],
            entries = this._all ? this._all.entries : {[this.key]: this};
        let pabbr = {}, grade_titles = [];

        for (let i = 0; i !== order.length; ++i) {
            const k = order[i], ge = entries[k], t = ge.title || k;
            grade_titles.push(t);
            let m = t.match(/^(p)(?:art\s*|(?=\d))([-.a-z\d]+)(?:[\s:]+|$)/i)
                || t.match(/^(q)(?:uestion\s*|(?=\d))([-.a-z\d]+)(?:[\s:]+|$)/i)
                || t.match(/^[\s:]*()(\S{1,3}[\d.]*)[^\s\d]*[\s:]*/);
            if (!m) {
                ge._abbr = ":" + i;
            } else {
                let abbr = m[1] + m[2],
                    rest = t.substring(m[0].length),
                    abbrx;
                while ((abbrx = pabbr[abbr])) {
                    if (abbrx !== true
                        && (m = abbrx[1].match(/^(\S{1,3}[\d.]*)\S*[\s:]*(.*)$/))) {
                        abbrx[0]._abbr += m[1];
                        pabbr[abbrx[0]._abbr] = [abbrx[0], m[2]];
                        pabbr[abbr] = abbrx = true;
                    }
                    if ((m = rest.match(/^(\S{1,3}[\d.]*)[^\s\d]*[\s:]*(.*)$/))) {
                        abbr += m[1];
                        rest = m[2];
                    } else {
                        if (abbrx !== true) {
                            abbr += ":" + i;
                        }
                        break;
                    }
                }
                ge._abbr = abbr;
                pabbr[abbr] = [ge, rest];
            }
        }
    }

    html_skeleton(editable, live) {
        const name = this.key,
            title = this.title ? escape_entities(this.title) : name;
        let t;
        if ((editable || this.student) && this.gc.entry) {
            live = live !== false;
            let opts = {editable: editable},
                id = "pa-ge" + ++id_counter;
            t = (live ? '<form class="ui-submit ' : '<div class="') + 'pa-grade pa-p';
            if (this.type === "section") {
                t += ' pa-p-section';
            }
            if (this.visible === false) {
                t += ' pa-p-hidden';
            }
            t = t.concat('" data-pa-grade="', name);
            if (!live) {
                t = t.concat('" data-pa-grade-type="', this.gc.type);
            }
            t = t.concat('"><label class="pa-pt" for="', id, '">', title, '</label>');
            if (this.edit_description) {
                t += '<div class="pa-pdesc">' + escape_entities(this.edit_description) + '</div>';
            }
            t += this.gc.entry.call(this, id, opts) + (live ? '</form>' : '</div>');
        } else {
            t = '<div class="pa-grade pa-p';
            if (this.type === "section") {
                t += ' pa-p-section';
            }
            t += '" data-pa-grade="'.concat(name, '"><div class="pa-pt">', title, '</div>');
            if (this.type === "text") {
                t += '<div class="pa-pd pa-gradevalue"></div>';
            } else {
                t += '<div class="pa-pd"><span class="pa-gradevalue pa-gradewidth"></span>';
                if (this.max && this.type !== "letter") {
                    t += ' <span class="pa-gradedesc">of ' + this.max + '</span>';
                }
                t += '</div>';
            }
            t += '</div>';
        }
        return t;
    }

    fill_dom(element, g, options) {
        const $g = $(element),
            v = $g.find(".pa-gradevalue")[0];

        if (v && v.tagName !== "SPAN" && v.tagName !== "DIV") {
            this.fill_dom_editable(element, v, g, options || {});
        } else {
            const gt = this.text(g);
            if ($(v).text() !== gt) {
                $(v).text(gt);
            }
            toggleClass(element, "hidden", gt === "" && !this.max && this.type !== "section");
        }

        // maybe add landmark reference
        if (this.landmark
            && element.parentElement
            && hasClass(element.parentElement, "want-pa-landmark-links")) {
            var m = /^(.*):(\d+)$/.exec(this.landmark),
                $line = $(linediff_find(m[1], "a" + m[2])),
                want_gbr = "";
            if ($line.length) {
                var $pi = $(this).closest(".pa-psetinfo"),
                    directory = $pi[0].getAttribute("data-pa-directory") || "";
                if (directory && m[1].substr(0, directory.length) === directory) {
                    m[1] = m[1].substr(directory.length);
                }
                want_gbr = '@<a href="#' + $line[0].id + '" class="ui pa-goto">' + escape_entities(m[1] + ":" + m[2]) + '</a>';
            }
            var $pgbr = $g.find(".pa-gradeboxref");
            if (!$line.length) {
                $pgbr.remove();
            } else if (!$pgbr.length || $pgbr.html() !== want_gbr) {
                $pgbr.remove();
                $g.find(".pa-pd").first().append('<span class="pa-gradeboxref">' + want_gbr + '</span>');
            }
        }
    }

    fill_dom_editable(element, v, g, options) {
        const $g = $(element);

        // “grade is above max” message
        if (this.max) {
            if (!g || g <= this.max) {
                $g.find(".pa-gradeabovemax").remove();
            } else if (!$g.find(".pa-gradeabovemax").length) {
                $g.find(".pa-pd").after('<div class="pa-pd pa-gradeabovemax">Grade is above max</div>');
            }
        }

        // “autograde differs” message
        if (options.autograde == null || g === options.autograde) {
            $g.find(".pa-gradediffers").remove();
        } else {
            const txt = (this.key === "late_hours" ? "auto-late hours" : "autograde") +
                " is " + this.text(options.autograde);
            if (!$g.find(".pa-gradediffers").length) {
                $g.find(".pa-pd").first().append('<span class="pa-gradediffers"></span>');
            }
            const $ag = $g.find(".pa-gradediffers");
            if ($ag.text() !== txt) {
                $ag.text(txt);
            }
        }

        // grade value
        this.gc.reflect_value.call(this, v, g, options);
        if (options.reset && options.mixed) {
            v.setAttribute("placeholder", "Mixed");
        } else if (v.hasAttribute("placeholder")) {
            v.removeAttribute("placeholder");
        }
        if (options.reset) {
            v.setAttribute("data-default-value", this.simple_text(g));
        }
    }

    text(g) {
        return this.gc.text.call(this, g);
    }

    simple_text(g) {
        return this.gc.simple_text.call(this, g);
    }

    tcell(g) {
        return this.gc.tcell.call(this, g);
    }

    static closest(elt) {
        const e = elt.closest(".pa-grade"),
            gi = GradeSheet.closest(e);
        return gi.entries[e.getAttribute("data-pa-grade")];
    }

    static late_hours() {
        late_hours_entry = late_hours_entry || new GradeEntry({key: "late_hours", title: "late hours"});
        return late_hours_entry;
    }
}

export class GradeSheet {
    constructor(x) {
        Object.assign(this, x);
        if (this.entries) {
            for (let i in this.entries) {
                this.entries[i] = new GradeEntry(this.entries[i]);
                this.entries[i]._all = this;
            }
        }
        this.elements = this.entries;
    }

    fill_dom_at(element) {
        const k = element.getAttribute("data-pa-grade"),
            ge = this.entries[k];
        if (k === "late_hours") {
            GradeEntry.late_hours().fill_dom(element, this.late_hours, {autograde: this.auto_late_hours});
        } else if (ge) {
            ge.fill_dom(element, this.grades ? this.grades[ge.pos] : null, {autograde: this.autogrades ? this.autogrades[ge.pos] : null});
        }
    }

    static parse_json(x) {
        return new GradeSheet(JSON.parse(x));
    }

    static store(element, x) {
        $(element).data("pa-gradeinfo", new GradeSheet(x));
        window.$pa.loadgrades.call(element);
    }

    static closest(element) {
        let e = element.closest(".pa-psetinfo"), gi = null;
        while (e) {
            var gix = $(e).data("pa-gradeinfo");
            if (typeof gix === "string") {
                gix = GradeSheet.parse_json(gix);
                $(e).data("pa-gradeinfo", gix);
            }
            gi = gi ? $.extend(gi, gix) : gix;
            if (gi && gi.entries) {
                break;
            }
            e = e.parentElement.closest(".pa-psetinfo");
        }
        return gi;
    }
}
