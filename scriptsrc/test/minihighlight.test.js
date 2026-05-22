// minihighlight.test.js -- tests for scriptsrc/minihighlight.js
//
// minihighlight(text, lang, state) returns {value, top}: `value` is HTML
// using highlight.js class names, `top` is the lexer state threaded to the next
// line.
import { test } from "node:test";
import assert from "node:assert/strict";
import { minihighlight_supports, minihighlight } from "../minihighlight.js";

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
    assert.equal(minihighlight("fn main() {}", "rust", null), null);
});

test("keyword / type / literal classification (c)", () => {
    assert.equal(minihighlight("int x;", "c", null).value,
        '<span class="hljs-type">int</span> x;');
    assert.match(minihighlight("return x;", "c", null).value, /<span class="hljs-keyword">return<\/span>/);
    assert.match(minihighlight("true", "c", null).value, /<span class="hljs-literal">true<\/span>/);
});

test("numbers", () => {
    assert.equal(minihighlight("0x1F", "c", null).value, '<span class="hljs-number">0x1F</span>');
    assert.match(minihighlight("3.14", "c", null).value, /hljs-number/);
});

test("HTML is escaped in plain text", () => {
    const r = minihighlight("a < b\n", "c", null);
    assert.equal(r.value, "a &lt; b\n");
    assert.equal(r.top, null);
});

test("line comment runs to end of line", () => {
    const r = minihighlight("x // hi", "c", null);
    assert.match(r.value, /<span class="hljs-comment">\/\/ hi<\/span>/);
    assert.equal(r.top, null);
});

test("single-line string", () => {
    assert.equal(minihighlight('"hi"', "c", null).value,
        '<span class="hljs-string">"hi"</span>');
});

test("c++ raw string", () => {
    assert.equal(minihighlight('R"(hi)"', "cpp", null).value,
        '<span class="hljs-string">R"(hi)"</span>');
    assert.equal(minihighlight('LR"hello(h\\i\\)hello"', "cpp", null).value,
        '<span class="hljs-string">LR"hello(h\\i\\)hello"</span>');
});

test("block comment threads state across lines", () => {
    const r1 = minihighlight("/* hi\n", "c", null);
    assert.equal(r1.value, '<span class="hljs-comment">/* hi</span>\n');
    assert.ok(r1.top);
    assert.equal(r1.top.k, "bc");

    const r2 = minihighlight("still */ x\n", "c", r1.top);
    assert.match(r2.value, /<span class="hljs-comment">still \*\/<\/span>/);
    assert.match(r2.value, / x\n$/);
    assert.equal(r2.top, null);
});

test("javascript template literal threads state", () => {
    const r1 = minihighlight("`abc\n", "javascript", null);
    assert.equal(r1.value, '<span class="hljs-string">`abc</span>\n');
    assert.ok(r1.top);
    assert.equal(r1.top.k, "s");

    const r2 = minihighlight("def`;\n", "javascript", r1.top);
    assert.equal(r2.value, '<span class="hljs-string">def`</span>;\n');
    assert.equal(r2.top, null);
});

test("python triple-quoted string opens multi-line state", () => {
    const r1 = minihighlight('"""doc\n', "python", null);
    assert.equal(r1.value, '<span class="hljs-string">"""doc</span>\n');
    assert.ok(r1.top);
    assert.equal(r1.top.k, "s");
});

test("multi-line strings", () => {
    const r1 = minihighlight("`abc\ndef\nghi`", "javascript", null);
    assert.equal(r1.value, '<span class="hljs-string">`abc</span>\n<span class="hljs-string">def</span>\n<span class="hljs-string">ghi`</span>');

    const r2 = minihighlight("`abc\rdef\r\nghi`", "javascript", null);
    assert.equal(r2.value, '<span class="hljs-string">`abc</span>\n<span class="hljs-string">def</span>\n<span class="hljs-string">ghi`</span>');
});

test("blank line inside a multi-line construct emits no empty span", () => {
    const r = minihighlight("`a\n\nb`", "javascript", null);
    assert.equal(r.value, '<span class="hljs-string">`a</span>\n\n<span class="hljs-string">b`</span>');
    assert.doesNotMatch(r.value, /<span class="hljs-string"><\/span>/);
    assert.equal(r.top, null);
});

test("region beginning with a newline while in a construct terminates", () => {
    // Regression: a leading newline with state set must not spin (this test
    // hangs the suite if the lexer fails to make progress).
    const open = minihighlight("/*\n", "c", null).top;
    assert.equal(open.k, "bc");

    const r = minihighlight("\nx", "c", open);
    assert.equal(r.value, '\n<span class="hljs-comment">x</span>');
    assert.equal(r.top.k, "bc");

    const crlf = minihighlight("\r\nx", "c", open);
    assert.equal(crlf.value, '\n<span class="hljs-comment">x</span>');
    assert.equal(crlf.top.k, "bc");
});
