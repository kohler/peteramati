// search.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { regexp_quote } from "./encoders.js";

const OP_UNARY = 0x1;
const OP_ALLOW_SUBTYPE = 0x2;
const OP_SUBTYPE = 0x4;
const OP_UNNAMED = 0x8;
const OP_NOT = 0x10;
const OP_AND = 0x20;
const OP_OR = 0x40;
const OP_XOR = 0x80;

class SearchOperator {
    type;
    precedence;
    flags;
    subtype;

    constructor(type, precedence, flags, subtype) {
        this.type = type;
        this.precedence = precedence;
        this.flags = flags;
        this.subtype = subtype;
    }

    static get UNARY() { return OP_UNARY; }
    static get ALLOW_SUBTYPE() { return OP_ALLOW_SUBTYPE; }
    static get SUBTYPE() { return OP_SUBTYPE; }
    static get UNNAMED() { return OP_UNNAMED; }
    static get NOT() { return OP_NOT; }
    static get AND() { return OP_AND; }
    static get OR() { return OP_OR; }
    static get XOR() { return OP_XOR; }

    get unary() {
        return (this.flags & OP_UNARY) !== 0;
    }
    get allow_subtype() {
        return (this.flags & OP_SUBTYPE) !== 0;
    }

    make_subtype(subtype) {
        return new SearchOperator(this.type, this.precedence, (this.flags & ~OP_ALLOW_SUBTYPE) | OP_SUBTYPE, subtype);
    }
}

const REGEXP_TERMINATOR = /[\s\(\)\[\]\{\}]/y;

class SearchOperatorSet {
    #a;
    #xregex;
    static #simpleops;

    constructor(a) {
        this.#a = a != null ? a : {};
        this.#xregex = null;
    }

    define(name, op) {
        // XXX no backslashes allowed
        // XXX name should be punctuation or contain no punctuation
        this.#a[name] = op;
        this.#xregex = null;
    }

    lookup(name) {
        const op = this.#a[name];
        if (op) {
            return op;
        }
        const colon = name.indexOf(":");
        if (colon < 0) {
            return null;
        }
        const xop = this.#a[name.substring(0, colon)];
        if (!xop || !xop.allow_subtype) {
            return null;
        }
        const nop = xop.make_subtype(name.substring(colon + 1));
        this.#a[name] = nop;
        return nop;
    }

    get regex() {
        if (this.#xregex !== null) {
            return this.#xregex;
        }
        let ch = "";
        const br = [], alnum = [];
        for (const [name, op] of Object.entries(this.#a)) {
            if ((op.flags & (OP_SUBTYPE | OP_UNNAMED)) !== 0) {
                continue;
            }
            if (/^[-!"#$%&'()*+,.\/:;<=>?@\[\\\]^_`{|}~]+$/.test(name)) {
                if (name.length === 1) {
                    ch += regexp_quote(name, true);
                } else {
                    br.push(regexp_quote(name));
                }
            } else {
                let x = regexp_quote(name);
                if (op.allow_subtype) {
                    x += "(?::\\w+)?";
                }
                alnum.push(x);
            }
        }
        if (ch !== "") {
            br.push("[" + ch + "]");
        }
        if (alnum.length !== 0) {
            br.push("(?:" + alnum.join("|") + ")(?=[\\s\\(\\)\\[\\]\\{\\}]|$)");
        }
        this.#xregex = new RegExp(br.join("|"), "y");
        return this.#xregex;
    }

    static safe_terminator(str, pos = 0) {
        REGEXP_TERMINATOR.lastIndex = pos;
        return REGEXP_TERMINATOR.test(str);
    }

    static get simple_operators() {
        if (this.#simpleops != null) {
            return this.#simpleops;
        }
        const notop = new SearchOperator("not", 8, OP_UNARY | OP_NOT),
            spaceop = new SearchOperator("space", 7, OP_UNNAMED | OP_AND),
            andop = new SearchOperator("and", 6, OP_AND),
            xorop = new SearchOperator("xor", 5, OP_XOR),
            orop = new SearchOperator("or", 4, OP_OR);
        this.#simpleops = new SearchOperatorSet({
            "(": new SearchOperator("(", 0, OP_UNARY | OP_AND),
            ")": new SearchOperator(")", 0, OP_UNARY),
            NOT: notop,
            "-": notop,
            "!": notop,
            SPACE: spaceop,
            AND: andop,
            "&&": andop,
            XOR: xorop,
            "^^": xorop,
            OR: orop,
            "||": orop
        });
        return this.#simpleops;
    }

    static simple_operator(name) {
        return this.simple_operators.#a[name];
    }
}

export class SearchExpr {
    kword;
    text;
    info;
    kwpos1;
    pos1;
    pos2;
    op;
    child;
    parent;

    static make_simple(text, pos1, parent = null) {
        const e = new SearchExpr;
        e.text = text;
        e.kwpos1 = e.pos1 = pos1;
        e.pos2 = pos1 + text.length;
        e.parent = parent;
        return e;
    }

    static make_keyword(kword, text, kwpos1, pos1, pos2, parent = null) {
        const e = new SearchExpr;
        e.kword = kword === "" ? null : kword;
        e.text = text;
        e.kwpos1 = kwpos1;
        e.pos1 = pos1;
        e.pos2 = pos2;
        e.parent = parent;
        return e;
    }

    static make_op_start(op, pos1, pos2, reference) {
        const e = new SearchExpr;
        e.op = op;
        if (op.unary) {
            e.kwpos1 = e.pos1 = pos1;
            e.pos2 = pos2;
            e.child = [];
            e.parent = reference;
        } else {
            e.kwpos1 = e.pos1 = reference.pos1;
            e.pos2 = pos2;
            e.child = [reference];
            e.parent = reference.parent;
        }
        return e;
    }

    static combine(opname, ...child) {
        const e = new SearchExpr;
        e.op = SearchOperatorSet.simple_operator(opname);
        e.child = child;
        return e;
    }

    get is_complete() {
        return !this.op || this.child.length > (this.op.unary ? 0 : 1);
    }

    get is_incomplete_paren() {
        return this.op && this.op.type === "(" && this.child.length === 0;
    }

    complete(pos) {
        if (!this.is_complete) {
            this.pos2 = pos;
            this.child.push(SearchExpr.make_simple("", pos));
        }
        const p = this.parent;
        if (!p) {
            return this;
        }
        p.child.push(this);
        p.pos2 = this.pos2;
        return p;
    }

    complete_paren(pos1, pos2) {
        let a = this,
            first = a.op && a.op.type === "(" && a.child.length === 0;
        while (!a.op || a.op.type !== "(" || first) {
            a = a.complete(pos1);
            first = false;
        }
        a.pos2 = pos2;
        if (a.child.length === 0) {
            a.child.push(SearchExpr.make_simple("", pos2));
        }
        return a;
    }

    flattened_children() {
        if (!this.op || this.op.unary) {
            return this.child || [];
        }
        const a = [];
        for (const ch of this.child) {
            if (ch.op
                && ch.op.type === this.op.type
                && ch.op.subtype === this.op.subtype) {
                Array.push.prototype.apply(a, ...ch.flattened_children());
            } else {
                a.push(ch);
            }
        }
        return a;
    }

    unparse(str, indent = "") {
        let ctx;
        if (!this.op) {
            ctx = this.kword ? `${this.kword}:${this.text}` : this.text;
            if (ctx.length > 40) {
                ctx = ctx.substring(0, 32) + "...";
            }
            return `${indent}@${this.kwpos1} ${ctx}`;
        }
        if (!str) {
            ctx = "";
        } else if (this.pos2 - this.kwpos1 > 40) {
            ctx = str.substring(this.kwpos1, this.kwpos1 + 16) + "..." + str.substring(this.pos2 - 16, this.pos2);
        } else {
            ctx = str.substring(this.kwpos1, this.pos2);
        }
        if (ctx !== "") {
            ctx = ` <<${ctx}>>`;
        }
        const ts = [`${indent}[[${this.op.type}]] @${this.kwpos1}${ctx}\n`];
        const nindent = indent + "  ";
        for (const ch of this.child) {
            ts.push(ch.unparse(str, nindent));
        }
        return ts.join("");
    }

    evaluate_simple(f) {
        let ok;
        if (!this.op) {
            ok = f(this);
        } else if ((this.op.flags & OP_AND) !== 0) {
            ok = true;
            for (const ch of this.child) {
                ok = ok && ch.evaluate_simple(f);
            }
        } else if ((this.op.flags & OP_OR) !== 0) {
            ok = false;
            for (const ch of this.child) {
                ok = ok || ch.evaluate_simple(f);
            }
        } else if ((this.op.flags & OP_XOR) !== 0) {
            ok = false;
            for (const ch of this.child) {
                if (ch.evaluate_simple(f))
                    ok = !ok;
            }
        } else if ((this.op.flags & OP_NOT) !== 0) {
            ok = !this.child[0] || !this.child[0].evaluate_simple(f);
        } else {
            throw new Error("unknown operator");
        }
        return ok;
    }
}

const REGEXP_STICKYSPACE = /\s+/y;
const REGEXP_KEYWORD = /[_a-zA-Z0-9][-_.a-zA-Z0-9]*(?=:)/y;

export class SearchParser {
    #str;
    pos;
    #len;
    last_pos = 0;

    constructor(str, pos1 = 0, pos2 = null) {
        this.#str = str;
        this.pos = pos1;
        this.#len = pos2 != null ? pos2 : str.length;

        // unlikely: passed a substring that ends mid-word; need to be careful
        if (this.#len < str.length
            && !SearchOperatorSet.safe_terminator(str, this.#len)
            && (this.#len === 0 || !SearchOperatorSet.safe_terminator(str, this.#len - 1))) {
            this.#str = str.substring(0, this.#len);
        }

        this.#set_span_and_pos(0);
    }

    empty() {
        return this.pos >= this.#len;
    }

    rest() {
        return this.#str.substring(this.pos, this.#len);
    }

    set_pos(pos) {
        this.pos = this.last_pos = pos;
        return this;
    }

    #set_span_and_pos(len) {
        this.last_pos = this.pos = Math.min(this.pos + len, this.#len);
        REGEXP_STICKYSPACE.lastIndex = this.pos;
        if (REGEXP_STICKYSPACE.test(this.#str)) {
            this.pos = Math.min(REGEXP_STICKYSPACE.lastIndex, this.#len);
        }
    }

    shift_keyword() {
        REGEXP_KEYWORD.lastIndex = this.pos;
        if (!REGEXP_KEYWORD.test(this.#str)
            || REGEXP_KEYWORD.lastIndex >= this.#len) {
            return "";
        }
        const kw = this.#str.substring(this.pos, REGEXP_KEYWORD.lastIndex);
        this.#set_span_and_pos(kw.length + 1);
        return kw;
    }

    shift_past(str) {
        // assert(substr_compare($this->str, $str, $this->pos, strlen($str)) === 0);
        this.#set_span_and_pos(str.length);
    }

    skip_whitespace() {
        this.#set_span_and_pos(0);
        return this.pos < this.#len;
    }

    shift_balanced_parens(endchars = null, allow_empty = false) {
        const pos0 = this.pos,
            pos1 = SearchParser.span_balanced_parens(this.#str, pos0, endchars, allow_empty);
        this.#set_span_and_pos(pos1 - pos0);
        return this.#str.substring(pos0, this.last_pos);
    }

    match(re) {
        re.lastIndex = this.pos;
        return re.exec(this.#str);
    }

    test(re) {
        re.lastIndex = this.pos;
        return re.test(this.#str) ? re.lastIndex : -1;
    }

    starts_with(substr) {
        const pos1 = this.pos + substr.length;
        return pos1 <= this.#len
            && this.#str.substring(this.pos, pos1) === substr;
    }

    #shift_operator(opset) {
        const pos0 = this.pos, pos1 = this.test(opset.regex);
        if (pos1 < 0 || pos1 > this.#len) {
            return null;
        }
        this.#set_span_and_pos(pos1 - pos0);
        return opset.lookup(this.#str.substring(pos0, pos1));
    }

    static span_balanced_parens(str, pos = 0, endchars = null, allow_empty = false) {
        endchars = endchars != null ? endchars : " \n\r\t\x0B\x0C";
        let pstack = "",
            plast = "",
            quote = 0,
            startpos = allow_empty ? -1 : pos,
            len = str.length;
        while (pos < len) {
            let ch = str.charAt(pos);
            // stop when done
            if (plast === ""
                && !quote
                && endchars !== ""
                && endchars.indexOf(ch) >= 0) {
                break;
            }
            // translate “” -> "
            if (ch === "“" || ch === "”") {
                ch = "\"";
            }
            if (quote) {
                if (ch === "\\" && pos + 1 < len) {
                    ++pos;
                } else if (ch === "\"") {
                    quote = 0;
                }
            } else if (ch === "(") {
                pstack += plast;
                plast = ")";
            } else if (ch === "[") {
                pstack += plast;
                plast = "]";
            } else if (ch === "{") {
                pstack += plast;
                plast = "}";
            } else if (ch === ")" || ch === "]" || ch === "}") {
                if (pos === startpos) {
                    ++startpos;
                } else {
                    let pcleared;
                    do {
                        pcleared = plast;
                        const pslen = pstack.length - 1;
                        if (pslen >= 0) {
                            plast = pstack.substring(pslen);
                            pstack = pstack.substring(0, pslen);
                        } else {
                            plast = pstack = "";
                        }
                    } while (ch !== pcleared && pcleared !== "");
                    if (pcleared === "") {
                        break;
                    }
                }
            } else if (ch === "\"") {
                quote = 1;
            }
            ++pos;
        }
        return pos;
    }

    static split_balanced_parens(str) {
        const w = [];
        if (str !== "") {
            const sp = new SearchParser(str);
            while (sp.skip_whitespace()) {
                w.push(sp.shift_balanced_parens());
            }
        }
        return w;
    }

    parse_expression(opset = null, spaceop = "SPACE", max_ops = 2048) {
        opset = opset != null ? opset : SearchOperatorSet.simple_operators;
        let cure = null, parens = 0, nops = 0;
        while (!this.empty()) {
            let pos1 = this.pos,
                op = this.#shift_operator(opset),
                pos2 = this.last_pos;
            if (!op && (!cure || !cure.is_complete)) {
                const kwpos1 = this.pos,
                    kw = this.shift_keyword();
                pos1 = this.pos;
                const text = this.shift_balanced_parens(null, true);
                pos2 = this.last_pos;
                cure = SearchExpr.make_keyword(kw, text, kwpos1, pos1, pos2, cure);
                continue;
            }

            if (op && op.type === ")") {
                if (parens === 0) {
                    continue;
                }
                cure = cure.complete_paren(pos1, pos2);
                --parens;
                continue;
            }

            if (!op || (op && op.unary && cure && cure.is_complete)) {
                op = opset.lookup(parens > 0 ? "SPACE" : spaceop);
                this.set_pos(pos1);
                pos2 = pos1;
            }

            if (!op.unary) {
                if (!cure || cure.is_incomplete_paren) {
                    cure = SearchExpr.make_simple("", pos1, cure);
                }
                while (cure.parent && cure.parent.op.precedence >= op.precedence) {
                    cure = cure.complete(pos1);
                }
            }

            if (nops >= max_ops) {
                return null;
            }

            cure = SearchExpr.make_op_start(op, pos1, pos2, cure);
            if (op.type === "(") {
                ++parens;
            }
            ++nops;
        }
        if (cure) {
            let nexte;
            while ((nexte = cure.complete(this.last_pos)) !== cure) {
                cure = nexte;
            }
        }
        return cure;
    }
}
