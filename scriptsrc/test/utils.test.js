// utils.test.js -- tests for the pure helpers in scriptsrc/utils.js
//
// utils.js touches `window` and `Element` at module load (it installs
// localStorage helpers and DOM polyfills), which don't exist under Node.
// We provide just enough of those globals, then dynamically import the module
// so the shims are in place before its top-level code runs.
import { test } from "node:test";
import assert from "node:assert/strict";

globalThis.window = { JSON };
if (typeof globalThis.Element === "undefined") {
    globalThis.Element = function () {};
    globalThis.Element.prototype = {};
}

const { sprintf, strftime, sec2text, text_eq, string_utf8_index, friendly_boolean }
    = await import("../utils.js");

test("sprintf", () => {
    assert.equal(sprintf("%d", 3.7), "3");
    assert.equal(sprintf("%05d", 42), "00042");
    assert.equal(sprintf("%x", 255), "ff");
    assert.equal(sprintf("%X", 255), "FF");
    assert.equal(sprintf("%.2f", 3.14159), "3.14");
    assert.equal(sprintf("%s!", "hi"), "hi!");
    assert.equal(sprintf("100%%"), "100%");
});

test("strftime", () => {
    const d = new Date(2020, 0, 5, 13, 7, 9); // local time, deterministic
    assert.equal(strftime("%Y-%m-%d", d), "2020-01-05");
    assert.equal(strftime("%H:%M:%S", d), "13:07:09");
    assert.equal(strftime("%b", d), "Jan");
});

test("sec2text", () => {
    assert.equal(sec2text(30), "30s");
    assert.equal(sec2text(90), "1m30s");
    assert.equal(sec2text(3600), "1h0m");
    assert.equal(sec2text(3600, "quarterhour"), "1h");
    assert.equal(sec2text(-30), "-30s");
    assert.equal(sec2text(-90), "-1m30s");
});

test("text_eq normalizes line endings", () => {
    assert.equal(text_eq("a\r\nb", "a\nb"), true);
    assert.equal(text_eq("a\rb", "a\nb"), true);
    assert.equal(text_eq(null, ""), true);
    assert.equal(text_eq("x", "x"), true);
    assert.equal(text_eq("a", "b"), false);
});

test("string_utf8_index maps utf-8 byte offsets to JS indexes", () => {
    assert.equal(string_utf8_index("hello", 2), 2);
    // "h" = 1 byte, "é" = 2 bytes -> byte offset 3 is JS index 2
    assert.equal(string_utf8_index("héllo", 3), 2);
});

test("friendly_boolean", () => {
    assert.equal(friendly_boolean("yes"), true);
    assert.equal(friendly_boolean("no"), false);
    assert.equal(friendly_boolean(true), true);
    assert.equal(friendly_boolean(1), true);
    assert.equal(friendly_boolean(0), false);
    assert.equal(friendly_boolean("maybe"), null);
    assert.equal(friendly_boolean({}), null);
});
