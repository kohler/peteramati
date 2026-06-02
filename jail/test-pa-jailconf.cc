#undef NDEBUG
#include "pa-jailconf.hh"
#include "pa-jutil.hh"
#include <cassert>
#include <cstdio>

void test_pathmatch() {
    // slashes are significant; no insensitivity
    assert(pathmatch("/foo", "/foo"));
    assert(!pathmatch("/foo", "/foo/"));
    assert(!pathmatch("/foo/", "/foo"));
    assert(pathmatch("/foo/", "/foo/"));
    assert(!pathmatch("/a/b", "/a//b"));
    assert(pathmatch("/a//b", "/a//b"));
    assert(!pathmatch("/foo", "/foo/bar"));
    assert(!pathmatch("/foo", "/foobar"));
    assert(pathmatch("/", "/"));
    assert(!pathmatch("/", "/a"));

    // `*` and `?` match within a single component, not across `/`
    assert(pathmatch("/jails/run*", "/jails/run"));
    assert(pathmatch("/jails/run*", "/jails/runa"));
    assert(!pathmatch("/jails/run*", "/jails/runa/runb"));
    assert(pathmatch("/jails/~*", "/jails/~runa"));
    assert(pathmatch("/a/?", "/a/b"));
    assert(!pathmatch("/a/?", "/a/bc"));
    assert(!pathmatch("/a/?", "/a/b/c"));
    assert(pathmatch("/x*y*z", "/x_y_z"));

    // bracket expressions: members, ranges, negation, literal corners
    assert(pathmatch("/a/[abc]", "/a/b"));
    assert(!pathmatch("/a/[abc]", "/a/d"));
    assert(pathmatch("/a/[a-z]", "/a/m"));
    assert(!pathmatch("/a/[a-z]", "/a/M"));
    assert(pathmatch("/a/[A-Za-z]", "/a/M"));
    assert(pathmatch("/a/[!abc]", "/a/d"));
    assert(!pathmatch("/a/[!abc]", "/a/b"));
    assert(pathmatch("/a/[^abc]", "/a/d"));
    assert(!pathmatch("/a/[^abc]", "/a/a"));
    assert(pathmatch("/a/x[0-9]y", "/a/x5y"));
    assert(!pathmatch("/a/x[0-9]y", "/a/xay"));
    assert(pathmatch("/a/[]]", "/a/]"));        // `]` first = literal member
    assert(!pathmatch("/a/[!]]", "/a/]"));
    assert(pathmatch("/a/[!]]", "/a/a"));
    assert(pathmatch("/a/[a-]", "/a/-"));       // trailing `-` literal
    assert(pathmatch("/a/[a-]", "/a/a"));
    assert(pathmatch("/a/[", "/a/["));          // unterminated `[` literal
    assert(!pathmatch("/a/[", "/a/x"));
    assert(pathmatch("/a/[a-c]*", "/a/banana"));
    assert(!pathmatch("/a/[a-c]*", "/a/xenon"));

    // a leading `.` is matched only by a literal `.` (FNM_PERIOD)
    assert(!pathmatch("/foo/*", "/foo/.bar"));
    assert(!pathmatch("/foo/?bar", "/foo/.bar"));
    assert(pathmatch("/foo/.*", "/foo/.bar"));
    assert(!pathmatch("/a/[.]x", "/a/.x"));
    assert(!pathmatch("*.c", ".c"));
    assert(!pathmatch("/a/*.c/b", "/a/.c/b"));

    // `**` matches zero or more whole components, crossing `/`
    assert(!pathmatch("/foo/**", "/foo"));      // missing slash
    assert(pathmatch("/foo/**", "/foo/"));
    assert(pathmatch("/foo/**", "/foo/bar"));
    assert(pathmatch("/foo/**", "/foo/bar/baz"));
    assert(!pathmatch("/foo/**", "/foobar"));
    assert(pathmatch("/**", "/"));
    assert(pathmatch("/**", "/a/b/c"));
    assert(pathmatch("/a/**/c", "/a/c"));       // zero middle components
    assert(pathmatch("/a/**/c", "/a/x/c"));
    assert(pathmatch("/a/**/c", "/a/x/y/c"));
    assert(!pathmatch("/a/**/c", "/a/x/y/d"));
    assert(pathmatch("/a/**/b/**/c", "/a/x/b/y/z/c"));
    assert(pathmatch("/a/**/b/**/c", "/a/b/c"));
    assert(!pathmatch("/a/**/b/**/c", "/a/b/d"));
    assert(!pathmatch("/a/**/b", "/a"));

    // `**` crosses dotted components (FNM_PERIOD does not apply to `**`), so
    // `/**` disables everything, including top-level dotfiles
    assert(pathmatch("/foo/**", "/foo/.bar"));
    assert(pathmatch("/a/**/c", "/a/.x/c"));
    assert(pathmatch("/**", "/.hidden"));
    assert(pathmatch("/**", "/.a/.b"));
    assert(pathmatch("/a/**/z", "/a/.x/.y/z"));

    // `**` is special only as a whole component
    assert(pathmatch("/x**", "/xyz"));
    assert(!pathmatch("/x**", "/x/y"));
    assert(pathmatch("/**y", "/zzy"));
    assert(!pathmatch("/**y", "/z/y"));

    // greedy `**` still matches a fixed suffix
    assert(pathmatch("/**/c", "/c"));
    assert(pathmatch("/**/c", "/a/b/c"));
    assert(!pathmatch("/**/c", "/a/b/c/d"));

    // empty pattern and degenerate inputs
    assert(pathmatch("", ""));
    assert(!pathmatch("", "/"));
    assert(!pathmatch("/", ""));
    assert(!pathmatch("/foo", ""));

    // a literal pattern matches only itself
    assert(pathmatch("/foo/bar", "/foo/bar"));
    assert(!pathmatch("/foo/bar", "/foo/baz"));
    assert(!pathmatch("/foo/bar", "/foo/ba"));
    assert(!pathmatch("/foo/bar", "/foo/barx"));

    // `*` matches the empty string within a component
    assert(pathmatch("/a/*", "/a/"));           // trailing-slash component is empty
    assert(pathmatch("/a/*b", "/a/b"));
    assert(pathmatch("/a/b*", "/a/b"));
    assert(pathmatch("/*", "/a"));
    assert(pathmatch("/*", "/"));               // `*` matches the empty component
    assert(!pathmatch("/*", "/a/b"));           // `*` does not cross `/`

    // multiple `*` in one component, with backtracking
    assert(pathmatch("/a/*x*", "/a/xx"));
    assert(pathmatch("/a/*x*", "/a/axb"));
    assert(pathmatch("/*a*a*", "/banana"));
    assert(!pathmatch("/*a*a*", "/bann"));

    // `* LIT *` where LIT is absent must fail and must terminate
    // (the `*` backtrack once spun forever once `sp` reached end-of-component)
    assert(!pathmatch("/a/*x*", "/a/ab"));
    assert(!pathmatch("/*z*", "/abc"));
    assert(!pathmatch("/a/*x*y*", "/a/aaaa"));
    assert(!pathmatch("/*q*", "/"));
    assert(!pathmatch("/a/*x*", "/a/"));

    // consecutive/glued `*` (a non-whole-component `**` collapses to one `*`)
    assert(pathmatch("/a/**z", "/a/qqz"));      // `**z` == `*z`
    assert(pathmatch("/a/**z", "/a/z"));
    assert(!pathmatch("/a/**z", "/a/qq"));      // no trailing `z`
    assert(pathmatch("/a/*****", "/a/anything"));
    assert(pathmatch("/a/*****", "/a/"));       // collapsed `*` matches empty
    assert(pathmatch("/**z", "/qqz"));          // glued within the first component

    // `*` at end of a component
    assert(pathmatch("/a*/b", "/abc/b"));
    assert(pathmatch("/a*/b", "/a/b"));         // `a*` matches just `a`
    assert(pathmatch("/a*", "/abc"));
    assert(pathmatch("/a*/", "/abc/"));
    assert(pathmatch("/*/", "/abc/"));

    // suffix fast-path: a fixed tail after metacharacters
    assert(pathmatch("/a/*.c", "/a/foo.c"));
    assert(!pathmatch("/a/*.c", "/a/foo.h"));
    assert(!pathmatch("/foo*", "/bar"));        // fixed prefix in suffix path
    assert(pathmatch("/*/*.c", "/a/b.c"));
    assert(!pathmatch("/*/*.c", "/a/b/c.c"));   // each `*` stays in its component
    assert(pathmatch("/x/*.c", "/x/a.c"));

    // `*.c` must not match a dotfile `.c`: stripping the `.c` suffix leaves `*`
    // against a leading-`.` component, which FNM_PERIOD forbids
    assert(!pathmatch("/a/*.c", "/a/.c"));
    assert(!pathmatch("/x/*.c", "/x/.c"));

    // `?` does not match empty or `/`
    assert(!pathmatch("/a/?", "/a/"));
    assert(!pathmatch("/a?b", "/ab"));
    assert(pathmatch("/a?b", "/axb"));

    // brackets: digits, multiple ranges, single-char class, no cross-`/`
    assert(pathmatch("/a/[0-9]", "/a/5"));
    assert(!pathmatch("/a/[0-9]", "/a/x"));
    assert(pathmatch("/a/[a-c0-9]", "/a/9"));
    assert(pathmatch("/a/[a-c0-9]", "/a/b"));
    assert(!pathmatch("/a/[a-c0-9]", "/a/z"));
    assert(pathmatch("/a/[z]", "/a/z"));        // single-member class
    assert(!pathmatch("/a/[abc]", "/a//"));     // class never matches `/`
    assert(!pathmatch("/a/[!b]", "/a/b"));
    assert(pathmatch("/a/[!b]", "/a/c"));
    assert(pathmatch("/a/[-abc]", "/a/-"));     // leading `-` literal
    assert(pathmatch("/a/[abc-]", "/a/-"));     // trailing `-` literal (again)

    // a negated class still must not match a leading `.` (FNM_PERIOD)
    assert(!pathmatch("/x/[!q]y", "/x/.y"));

    // a `/` inside a bracket is inert: `/` is always a hard separator, so a
    // class can neither contain nor match it. The other members work normally.
    // (fnmatch with FNM_PATHNAME refuses these outright; intended divergence.)
    assert(pathmatch("/[/a]", "/a"));           // member `a` still matches
    assert(!pathmatch("/[/a]", "/b"));          // no member matches
    assert(pathmatch("/[!/]", "/a"));           // negated, `a` is not `/`
    assert(pathmatch("/[^/]", "/a"));
    assert(!pathmatch("/[!/]", "//"));          // a class never matches `/`,
    assert(!pathmatch("/[^/]", "//"));          // even when negated
    assert(!pathmatch("/[!a]", "//"));

    // backslash escapes a metacharacter, making it literal
    assert(pathmatch("/a/\\*", "/a/*"));
    assert(!pathmatch("/a/\\*", "/a/x"));
    assert(pathmatch("/a/\\?", "/a/?"));
    assert(!pathmatch("/a/\\?", "/a/x"));
    assert(pathmatch("/a/\\[abc", "/a/[abc"));  // escaped `[` is a literal
    assert(pathmatch("/a/\\\\", "/a/\\"));      // escaped backslash is literal

    // `**` interacts with fixed components on either side
    assert(pathmatch("/a/**", "/a/b/c/d"));
    assert(!pathmatch("/a/**", "/b/c"));        // fixed prefix must still match
    assert(pathmatch("/**/x/**", "/x/"));       // both `**` empty
    assert(!pathmatch("/**/x/**", "/x"));       // slashes required though
    assert(pathmatch("/**/x/**", "/a/x/b/c"));
    assert(!pathmatch("/**/x/**", "/a/y/b"));
    assert(pathmatch("/a/**/**/b", "/a/x/y/b")); // doubled `**`
    assert(pathmatch("/a/**/**/b", "/a/b"));

    // `**` backtracks to find a later occurrence of the suffix
    assert(pathmatch("/**/b", "/a/b/a/b"));
    assert(pathmatch("/**/a/b", "/a/x/a/b"));
    assert(!pathmatch("/**/a/b", "/a/x/a/c"));

    // a component glob after `**` is still single-component
    assert(pathmatch("/**/r*", "/a/b/run"));
    assert(!pathmatch("/**/r*", "/a/b/xyz"));
    assert(pathmatch("/**/*.c", "/a/b/x.c"));
    assert(!pathmatch("/**/*.c", "/a/b/x.h"));

    // regression: a literal component after `**` must match a WHOLE component,
    // not a suffix of one. (A removed suffix fast-path peeled the trailing
    // literal off the string, letting `**` swallow the leftover prefix, so
    // `/**/run` wrongly matched `/xrun`.)
    assert(pathmatch("/**/run", "/run"));
    assert(pathmatch("/**/run", "/x/run"));
    assert(!pathmatch("/**/run", "/xrun"));
    assert(!pathmatch("/**/run", "/runx"));
    assert(pathmatch("/jails/**/run", "/jails/run"));
    assert(pathmatch("/jails/**/run", "/jails/x/run"));
    assert(!pathmatch("/jails/**/run", "/jails/xrun"));
    assert(!pathmatch("/jails/**/run", "/jails/runx"));
    assert(pathmatch("/**/ab", "/ab"));
    assert(pathmatch("/**/ab", "/x/ab"));
    assert(!pathmatch("/**/ab", "/xab"));
}

void test_pathmatch_literal_prefix() {
    // fully literal: the whole pattern, with a trailing slash ensured
    assert(pathmatch_literal_prefix("/jail") == "/jail/");
    assert(pathmatch_literal_prefix("/jail/") == "/jail/");
    assert(pathmatch_literal_prefix("/data/jailbind") == "/data/jailbind/");
    assert(pathmatch_literal_prefix("/data/jailbind/") == "/data/jailbind/");
    // wildcard as its own component: prefix is the parent
    assert(pathmatch_literal_prefix("/data/jails/*") == "/data/jails/");
    assert(pathmatch_literal_prefix("/data/jails/*/") == "/data/jails/");
    assert(pathmatch_literal_prefix("/data/jails/**") == "/data/jails/");
    // wildcard glued to a literal: cut back to the last slash
    assert(pathmatch_literal_prefix("/jails/run*") == "/jails/");
    assert(pathmatch_literal_prefix("/jails/~*/") == "/jails/");
    // other wildcard characters also end the prefix
    assert(pathmatch_literal_prefix("/a/b?") == "/a/");
    assert(pathmatch_literal_prefix("/a/[bc]") == "/a/");
    assert(pathmatch_literal_prefix("/*") == "/");
    assert(pathmatch_literal_prefix("/a/b/c*d/e") == "/a/b/");
    // more cases
    assert(pathmatch_literal_prefix("ab") == "ab/");
    assert(pathmatch_literal_prefix("") == "/");
    assert(pathmatch_literal_prefix("ab/\\**") == "ab/");
    assert(pathmatch_literal_prefix("ab\\/\\**") == "ab/");
    assert(pathmatch_literal_prefix("ab\\/\\*/*") == "ab/*/");
    // backslash escapes: an escaped wildcard is literal and the backslash is
    // dropped from the result
    assert(pathmatch_literal_prefix("/a/\\*b") == "/a/*b/");
    assert(pathmatch_literal_prefix("/a/\\*b/c") == "/a/*b/c/");
    assert(pathmatch_literal_prefix("/a/\\*b*/c") == "/a/");
    assert(pathmatch_literal_prefix("/a/x\\?y") == "/a/x?y/");
    assert(pathmatch_literal_prefix("/a/\\[b]/c*") == "/a/[b]/");
    assert(pathmatch_literal_prefix("/a\\\\b") == "/a\\b/");
    // a backslash-escaped slash decodes to `/` and still separates components,
    // even when an earlier run was already flushed by another backslash
    assert(pathmatch_literal_prefix("/a/b\\/c") == "/a/b/c/");
    assert(pathmatch_literal_prefix("/a/b\\/c*") == "/a/b/");
    assert(pathmatch_literal_prefix("/a\\*b\\/c*") == "/a*b/");
    // doubled slashes are preserved (slashes match exactly)
    assert(pathmatch_literal_prefix("/a//b*") == "/a//");
    assert(pathmatch_literal_prefix("/a//") == "/a//");
}

void test_pajailconf() {
    pajailconf jc("enablejail /jails/run*\nenablejail /jails/~*\n");
    assert(jc.check_jail("/jails/run").treedir == "/jails/run/");
    assert(jc.check_jail("/jails/run/").treedir == "/jails/run/");
    assert(!jc.check_jail("/jails"));
    assert(!jc.check_jail("/jails/"));
    assert(!jc.check_jail("/jails/runa/runb"));
    assert(!jc.check_jail("/jails/runa/runb/"));
    assert(jc.check_jail("/jails/runa").treedir == "/jails/runa/");
    assert(jc.check_jail("/jails/runa/").treedir == "/jails/runa/");
    assert(jc.check_jail("/jails/~runa").treedir == "/jails/~runa/");
    assert(jc.check_jail("/jails/~runa/").treedir == "/jails/~runa/");

    // a disable pattern cascades into subdirectories: `disablejail /` is a veto
    jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ndisablejail /\n");
    assert(!jc.check_jail("/jails/run"));
    assert(!jc.check_jail("/jails/run/"));
    assert(!jc.check_jail("/jails"));
    assert(!jc.check_jail("/jails/"));
    assert(!jc.check_jail("/jails/runa/runb"));
    assert(!jc.check_jail("/jails/runa/runb/"));
    assert(!jc.check_jail("/jails/runa"));
    assert(!jc.check_jail("/jails/runa/"));
    assert(!jc.check_jail("/jails/~runa"));
    assert(!jc.check_jail("/jails/~runa/"));

    jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ndisablejail /jails/runa\n");
    assert(jc.check_jail("/jails/run"));
    assert(jc.check_jail("/jails/run/"));
    assert(!jc.check_jail("/jails"));
    assert(!jc.check_jail("/jails/"));
    assert(!jc.check_jail("/jails/runa/runb"));
    assert(!jc.check_jail("/jails/runa/runb/"));
    assert(!jc.check_jail("/jails/runa"));
    assert(!jc.check_jail("/jails/runa/"));
    assert(jc.check_jail("/jails/~runa"));
    assert(jc.check_jail("/jails/~runa/"));

    // an explicit `treedir` directive widens the permission directory
    jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ntreedir /jails\n");
    assert(jc.check_jail("/jails/run").treedir == "/jails/");
    assert(jc.check_jail("/jails/run/").treedir == "/jails/");
    assert(!jc.check_jail("/jails"));
    assert(!jc.check_jail("/jails/"));
    assert(!jc.check_jail("/jails/runa/runb"));
    assert(!jc.check_jail("/jails/runa/runb/"));
    assert(jc.check_jail("/jails/runa").treedir == "/jails/");
    assert(jc.check_jail("/jails/runa/").treedir == "/jails/");
    assert(jc.check_jail("/jails/~runa").treedir == "/jails/");
    assert(jc.check_jail("/jails/~runa/").treedir == "/jails/");

    // a non-matching `treedir` leaves the permission directory at the jail dir
    jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ntreedir /hails\n");
    assert(jc.check_jail("/jails/run").treedir == "/jails/run/");
    assert(jc.check_jail("/jails/run/").treedir == "/jails/run/");
    assert(!jc.check_jail("/jails"));
    assert(!jc.check_jail("/jails/"));
    assert(!jc.check_jail("/jails/runa/runb"));
    assert(!jc.check_jail("/jails/runa/runb/"));
    assert(jc.check_jail("/jails/runa").treedir == "/jails/runa/");
    assert(jc.check_jail("/jails/runa/").treedir == "/jails/runa/");
    assert(jc.check_jail("/jails/~runa").treedir == "/jails/~runa/");
    assert(jc.check_jail("/jails/~runa/").treedir == "/jails/~runa/");
}

void test_path_absolute() {
    // an explicit `cwd` makes these independent of the real working directory;
    // every `cwd` passed in ends in `/`, matching what the getcwd path produces

    // absolute paths are returned verbatim (NOT canonicalized)
    assert(path_absolute("/x/y", "/a/b/") == "/x/y");
    assert(path_absolute("/x/../y", "/a/b/") == "/x/../y");
    assert(path_absolute("/", "/a/b/") == "/");

    // relative paths are appended to `cwd`
    assert(path_absolute("c", "/a/b/") == "/a/b/c");
    assert(path_absolute("c/d", "/a/b/") == "/a/b/c/d");

    // leading `./` segments are dropped
    assert(path_absolute("./c", "/a/b/") == "/a/b/c");
    assert(path_absolute("././c", "/a/b/") == "/a/b/c");
    assert(path_absolute(".", "/a/b/") == "/a/b/");

    // leading `../` segments ascend, clamping at the root
    assert(path_absolute("../c", "/a/b/") == "/a/c");
    assert(path_absolute("../../c", "/a/b/") == "/c");
    assert(path_absolute("../../../c", "/a/b/") == "/c");
    assert(path_absolute("../c", "/a/") == "/c");
    assert(path_absolute("../c", "/") == "/c");
    assert(path_absolute("../", "/a/b/") == "/a/");
    assert(path_absolute("..", "/a/b/") == "/a/");

    // mixed leading `./` and `../`, including bare trailing `.`/`..`
    assert(path_absolute(".././c", "/a/b/") == "/a/c");
    assert(path_absolute("./../c", "/a/b/") == "/a/c");
    assert(path_absolute("../..", "/a/b/c/") == "/a/");
    assert(path_absolute("./.", "/a/b/") == "/a/b/");

    // a leading run of slashes in the relative part is swallowed
    assert(path_absolute(".//c", "/a/b/") == "/a/b/c");

    // ONLY leading `.`/`..` are handled; embedded ones are left untouched
    // (callers must run the result through path_pa_validate)
    assert(path_absolute("x/../c", "/a/b/") == "/a/b/x/../c");
    assert(path_absolute("x/./c", "/a/b/") == "/a/b/x/./c");

    // empty relative path yields `cwd`
    assert(path_absolute("", "/a/b/") == "/a/b/");

    // with no explicit cwd, the result is absolute and ends with the input
    std::string a = path_absolute("foo/bar");
    assert(a[0] == '/');
    assert(a.size() >= 7 && a.compare(a.size() - 7, 7, "foo/bar") == 0);
}

void test_path_pa_validate() {
    // ordinary valid paths pass through unchanged
    assert(path_pa_validate("a/b") == "a/b");
    assert(path_pa_validate("a/b/c") == "a/b/c");
    assert(path_pa_validate("/abs/path") == "/abs/path");
    assert(path_pa_validate("file.txt") == "file.txt");
    assert(path_pa_validate("a-b_c.d~e") == "a-b_c.d~e");
    assert(path_pa_validate("/") == "/");

    // `/+` is condensed and trailing slashes are trimmed
    assert(path_pa_validate("a//b") == "a/b");
    assert(path_pa_validate("a///b") == "a/b");
    assert(path_pa_validate("a/b/") == "a/b");
    assert(path_pa_validate("a/b///") == "a/b");

    // `.` path segments are removed
    assert(path_pa_validate("a/./b") == "a/b");
    assert(path_pa_validate("a/././b") == "a/b");
    assert(path_pa_validate("./a") == "a");
    // a leading `./` followed by extra slashes must not leak a leading `/`
    // (regression: the old single-`++s` step left a stray `/`, yielding `/a`)
    assert(path_pa_validate(".//a") == "a");
    assert(path_pa_validate(".///a") == "a");

    // `..` path segments are disallowed everywhere (the hardening goal)
    assert(path_pa_validate("..").empty());
    assert(path_pa_validate("../b").empty());
    assert(path_pa_validate("a/..").empty());
    assert(path_pa_validate("a/../b").empty());
    assert(path_pa_validate("/..").empty());
    assert(path_pa_validate("/a/../b").empty());
    assert(path_pa_validate("a/b/../../c").empty());

    // `.` and `..` are only special as whole segments
    assert(path_pa_validate("..a") == "..a");
    assert(path_pa_validate("a..b") == "a..b");
    assert(path_pa_validate(".a") == ".a");
    assert(path_pa_validate("a.b") == "a.b");

    // a leading `~` is disallowed; a non-leading `~` is fine
    assert(path_pa_validate("~").empty());
    assert(path_pa_validate("~x").empty());
    assert(path_pa_validate("~/x").empty());
    assert(path_pa_validate("a/~x") == "a/~x");

    // characters outside `[-./0-9A-Za-z_~]` are rejected
    assert(path_pa_validate("a b").empty());
    assert(path_pa_validate("a*b").empty());
    assert(path_pa_validate("a;b").empty());
    assert(path_pa_validate("a\tb").empty());

    // paths that reduce to nothing come back empty (same as rejection)
    assert(path_pa_validate("").empty());
    assert(path_pa_validate(".").empty());
    assert(path_pa_validate("./").empty());
    assert(path_pa_validate("/.").empty());

    // length bound: `>= 1024` is rejected, `1023` is accepted
    assert(path_pa_validate(std::string(1023, 'a')) == std::string(1023, 'a'));
    assert(path_pa_validate(std::string(1024, 'a')).empty());
}

int main() {
    test_pathmatch();
    test_pathmatch_literal_prefix();
    test_pajailconf();
    test_path_absolute();
    test_path_pa_validate();
    fprintf(stderr, "test-pa-jailconf: all tests passed\n");
}
