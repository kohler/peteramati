// encoders.test.js -- tests for scriptsrc/encoders.js
import { test } from "node:test";
import assert from "node:assert/strict";
import {
    escape_entities, unescape_entities, urlencode, urldecode,
    regexp_quote, html_id_encode, html_id_decode
} from "../encoders.js";

test("escape_entities", () => {
    assert.equal(escape_entities("<a>&\"'"), "&lt;a&gt;&amp;&quot;&#39;");
    // non-strings pass through unchanged
    assert.equal(escape_entities(5), 5);
    assert.equal(escape_entities(null), null);
});

test("escape/unescape round-trip", () => {
    const s = "<a href=\"x\">tom & 'jerry'</a>";
    assert.equal(unescape_entities(escape_entities(s)), s);
});

test("unescape_entities known forms", () => {
    assert.equal(unescape_entities("&lt;a&gt;"), "<a>");
    assert.equal(unescape_entities("&#39;&apos;"), "''");
});

test("urlencode uses + for space and escapes specials", () => {
    assert.equal(urlencode("a b"), "a+b");
    assert.equal(urlencode("a!~*'()"), "a%21%7E%2A%27%28%29");
});

test("urlencode/urldecode round-trip", () => {
    const s = "a b & c=d/e?f";
    assert.equal(urldecode(urlencode(s)), s);
});

test("regexp_quote", () => {
    assert.equal(regexp_quote("a.b*c"), "a\\.b\\*c");
    assert.equal(regexp_quote("a-b"), "a-b");          // hyphen left alone
    assert.equal(regexp_quote("a-b", true), "a\\-b");  // escaped for charclass
});

test("html_id encode/decode round-trip", () => {
    for (const s of ["a b/c", "déjà vu", "x.y_z", "100%/done"]) {
        assert.equal(html_id_decode(html_id_encode(s)), s);
    }
    // encoded form is a safe id (no spaces)
    assert.equal(/\s/.test(html_id_encode("a b c")), false);
});
