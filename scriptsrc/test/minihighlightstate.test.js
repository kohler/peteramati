// minihighlightstate.test.js -- agreement test between the JS minihighlight
// multi-line state machine and the PHP src/minihighlightsummary.php scanner.
//
// minihighlight (JS) is the source of truth. For each corpus file we thread it
// line by line to get the entry state of every line, then run the PHP scanner
// over the same lines, reconstruct per-line entry states from its transition
// list, and require an exact match. This is what guards the two from drifting.
import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import path from "node:path";
import { minihighlight, minihighlight_summary_state } from "../minihighlight.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");

// Entry state of each line, per the JS source of truth (threaded top).
function js_entry_states(lang, lines) {
    const entries = [];
    let state = null;
    for (const line of lines) {
        entries.push(state);
        state = minihighlight(line, lang, state).top;
    }
    return entries;
}

// Run the PHP scanner on `text` (a whole-file string or a list of lines),
// returning its transitions.
function php_summary(lang, text) {
    const code = 'require $argv[1]; $in = json_decode(stream_get_contents(STDIN), true);'
        + ' echo json_encode(MinihighlightSummary::summary($in["lang"], $in["text"]));';
    const out = execFileSync("php", ["-r", code, root + "/src/minihighlightsummary.php"], {
        input: JSON.stringify({ lang, text }), encoding: "utf8"
    });
    return JSON.parse(out);
}

// Reconstruct per-line entry states from a PHP transition list, via the same
// decoder the client uses (also covering its binary search + bc reconstruction).
function php_entry_states(lang, transitions, n) {
    const entries = new Array(n);
    for (let L = 1; L <= n; ++L) {
        entries[L - 1] = minihighlight_summary_state(lang, transitions, L);
    }
    return entries;
}

function norm(s) {
    return s === null ? null : { k: s.k, c: s.c, f: s.f };
}

const corpus = {
    cpp: [
        "int x = 0; /* a comment",
        "   that spans lines */ int y;",
        "const char *s = \"a /* not a comment */ b\";",
        "// a line comment with /* inside",
        "const char *r = R\"delim(raw with )\" and /* inside",
        "still raw )delim\";",
        "const char *cont = \"line one \\",
        "line two\";",
        "auto t = u8\"unicode\";",
        "int z;"
    ],
    c: [
        "/* opening",
        "*/",
        "char c = '\\'';",
        "char *p = \"ends with backslash \\",
        "continued\";",
        "x();"
    ],
    javascript: [
        "const a = `template",
        "across ${1 + 2} lines`;",
        "const b = \"plain\"; // /* not a comment",
        "let c = /* block",
        "still */ 3;"
    ],
    python: [
        "x = 1  # comment",
        "doc = '''triple",
        "quoted",
        "string'''",
        "y = \"normal\"",
        "z = '''unterminated"
    ],
    shell: [
        "echo 'single quotes do not \\ continue'",
        "echo \"double with backslash \\",
        "second line\"",
        "x=1 # comment"
    ]
};

// summary() accepts either a whole-file string (split into lines on "\n" only,
// like RepositoryFileContent's explode) or a list of lines. A trailing "\r" is
// content, not a line ending, so the per-line entry states must match across a
// "\n" string, a "\r\n" string, a plain list, and a list with "\r"-terminated
// lines (the shape RepositoryFileContent yields for CRLF files).
const endings = [
    ["\\n string", lines => lines.join("\n")],
    ["\\r\\n string", lines => lines.join("\r\n")],
    ["list", lines => lines],
    ["\\r-terminated list", lines => lines.map(l => l + "\r")]
];

for (const [lang, lines] of Object.entries(corpus)) {
    const want = js_entry_states(lang, lines).map(norm);
    for (const [name, build] of endings) {
        test(`PHP scanner agrees with minihighlight (${lang}, ${name})`, () => {
            const got = php_entry_states(lang, php_summary(lang, build(lines)), lines.length).map(norm);
            for (let i = 0; i < lines.length; ++i) {
                assert.deepEqual(got[i], want[i],
                    `line ${i + 1} (${JSON.stringify(lines[i])}): entry state mismatch`);
            }
        });
    }
}

// A lone \r (not part of \r\n) is content, not a line ending -- it must NOT
// advance line numbers, matching RepositoryFileContent's explode("\n"). Here a
// block comment spans a line that contains a stray \r; the close must still be
// reported on line 3, so line 4's entry state is "none".
test("lone \\r does not shift line numbers", () => {
    const text = "/* open\nmid\rcr\n*/ close\nx";
    const lines = text.split("\n");                 // canonical \n line split
    assert.equal(lines.length, 4);
    const want = js_entry_states("cpp", lines).map(norm);
    const got = php_entry_states("cpp", php_summary("cpp", text), lines.length).map(norm);
    assert.deepEqual(got, want);
});
