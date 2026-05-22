// minihighlight.test.js -- tests for scriptsrc/minihighlight.js
//
// minihighlight_line(lang, text, state) returns {value, top}: `value` is HTML
// using highlight.js class names, `top` is the lexer state threaded to the next
// line.
import { test } from "node:test";
import assert from "node:assert/strict";
import { minihighlight_supports, minihighlight_line } from "../minihighlight.js";

test("minihighlight_supports recognizes languages and aliases", () => {
    for (const l of ["c", "cpp", "javascript", "typescript", "python", "shell", "make"]) {
        assert.equal(minihighlight_supports(l), true, l);
    }
    for (const [alias, ] of [["cc"], ["c++"], ["py"], ["js"], ["ts"], ["bash"], ["makefile"]]) {
        assert.equal(minihighlight_supports(alias), true, alias);
    }
    for (const l of ["rust", "ruby", "", null, undefined]) {
        assert.equal(minihighlight_supports(l), false, String(l));
    }
});

test("unsupported language returns null", () => {
    assert.equal(minihighlight_line("rust", "fn main() {}", null), null);
});

test("keyword / type / literal classification (c)", () => {
    assert.equal(minihighlight_line("c", "int x;", null).value,
        '<span class="hljs-type">int</span> x;');
    assert.match(minihighlight_line("c", "return x;", null).value, /<span class="hljs-keyword">return<\/span>/);
    assert.match(minihighlight_line("c", "true", null).value, /<span class="hljs-literal">true<\/span>/);
});

test("numbers", () => {
    assert.equal(minihighlight_line("c", "0x1F", null).value, '<span class="hljs-number">0x1F</span>');
    assert.match(minihighlight_line("c", "3.14", null).value, /hljs-number/);
});

test("HTML is escaped in plain text", () => {
    const r = minihighlight_line("c", "a < b\n", null);
    assert.equal(r.value, "a &lt; b\n");
    assert.equal(r.top, null);
});

test("line comment runs to end of line", () => {
    const r = minihighlight_line("c", "x // hi", null);
    assert.match(r.value, /<span class="hljs-comment">\/\/ hi<\/span>/);
    assert.equal(r.top, null);
});

test("single-line string", () => {
    assert.equal(minihighlight_line("c", '"hi"', null).value,
        '<span class="hljs-string">"hi"</span>');
});

test("c++ raw string", () => {
    assert.equal(minihighlight_line("cpp", 'R"(hi)"', null).value,
        '<span class="hljs-string">R"(hi)"</span>');
    assert.equal(minihighlight_line("cpp", 'LR"hello(h\\i\\)hello"', null).value,
        '<span class="hljs-string">LR"hello(h\\i\\)hello"</span>');
});

test("block comment threads state across lines", () => {
    const r1 = minihighlight_line("c", "/* hi\n", null);
    assert.equal(r1.value, '<span class="hljs-comment">/* hi</span>\n');
    assert.ok(r1.top);
    assert.equal(r1.top.k, "block");

    const r2 = minihighlight_line("c", "still */ x\n", r1.top);
    assert.match(r2.value, /<span class="hljs-comment">still \*\/<\/span>/);
    assert.match(r2.value, / x\n$/);
    assert.equal(r2.top, null);
});

test("javascript template literal threads state", () => {
    const r1 = minihighlight_line("javascript", "`abc\n", null);
    assert.equal(r1.value, '<span class="hljs-string">`abc</span>\n');
    assert.ok(r1.top);
    assert.equal(r1.top.k, "str");

    const r2 = minihighlight_line("javascript", "def`;\n", r1.top);
    assert.equal(r2.value, '<span class="hljs-string">def`</span>;\n');
    assert.equal(r2.top, null);
});

test("python triple-quoted string opens multi-line state", () => {
    const r1 = minihighlight_line("python", '"""doc\n', null);
    assert.equal(r1.value, '<span class="hljs-string">"""doc</span>\n');
    assert.ok(r1.top);
    assert.equal(r1.top.k, "str");
});
