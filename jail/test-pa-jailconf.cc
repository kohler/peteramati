#undef NDEBUG
#include "pa-jailconf.hh"
#include "pa-jutil.hh"
#include <cassert>
#include <cstdio>
#include <cstdint>
#include <string>

// True if `f()` throws a `pajailconf_error`.
template <typename F>
static bool throws_config_error(F&& f) {
    try {
        f();
        return false;
    } catch (const pajailconf_error&) {
        return true;
    }
}

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
    // permdir is the literal prefix of the matching enable pattern: here the
    // wildcard is glued to a literal in the last component, so the prefix is the
    // parent
    pajailconf jc("enablejail /jails/run*\nenablejail /jails/~*\n");
    assert(jc.get("/jails/run").permdir == "/jails/");
    assert(jc.get("/jails/run/").permdir == "/jails/");
    assert(!jc.get("/jails"));
    assert(!jc.get("/jails/"));
    assert(!jc.get("/jails/runa/runb"));
    assert(!jc.get("/jails/runa/runb/"));
    assert(jc.get("/jails/runa").permdir == "/jails/");
    assert(jc.get("/jails/runa/").permdir == "/jails/");
    assert(jc.get("/jails/~runa").permdir == "/jails/");
    assert(jc.get("/jails/~runa/").permdir == "/jails/");

    // a wildcard that is its own component leaves the parent as permdir; a
    // fully-literal pattern is its own permdir (matches deployment configs)
    jc = pajailconf("enablejail /data/jails/*\nenablejail /data/jailbind\n");
    assert(jc.get("/data/jails/repo10").permdir == "/data/jails/");
    assert(jc.get("/data/jails/repo10/").permdir == "/data/jails/");
    assert(jc.get("/data/jailbind").permdir == "/data/jailbind/");
    assert(jc.get("/data/jailbind/").permdir == "/data/jailbind/");

    // global `disablejail` is a veto
    jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ndisablejail\n");
    assert(!jc.get("/jails/run"));
    assert(!jc.get("/jails/run/"));
    assert(!jc.get("/jails"));
    assert(!jc.get("/jails/"));
    assert(!jc.get("/jails/runa/runb"));
    assert(!jc.get("/jails/runa/runb/"));
    assert(!jc.get("/jails/runa"));
    assert(!jc.get("/jails/runa/"));
    assert(!jc.get("/jails/~runa"));
    assert(!jc.get("/jails/~runa/"));

    // `disablejail /**` is a veto also, since it cascades into subdirs
    jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ndisablejail /**\n");
    assert(!jc.get("/jails/run"));
    assert(!jc.get("/jails/run/"));
    assert(!jc.get("/jails"));
    assert(!jc.get("/jails/"));
    assert(!jc.get("/jails/runa/runb"));
    assert(!jc.get("/jails/runa/runb/"));
    assert(!jc.get("/jails/runa"));
    assert(!jc.get("/jails/runa/"));
    assert(!jc.get("/jails/~runa"));
    assert(!jc.get("/jails/~runa/"));

    jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ndisablejail /jails/runa\n");
    assert(jc.get("/jails/run"));
    assert(jc.get("/jails/run/"));
    assert(!jc.get("/jails"));
    assert(!jc.get("/jails/"));
    assert(!jc.get("/jails/runa/runb"));
    assert(!jc.get("/jails/runa/runb/"));
    assert(!jc.get("/jails/runa"));
    assert(!jc.get("/jails/runa/"));
    assert(jc.get("/jails/~runa"));
    assert(jc.get("/jails/~runa/"));

    // disable does NOT cascade: a broadly-enabled subdir survives a disable of
    // its parent; only the exact directory is disabled
    jc = pajailconf("enablejail /jails/**\ndisablejail /jails/runa\n");
    assert(jc.get("/jails/runa/runb"));
    assert(jc.get("/jails/runa/runb/"));
    assert(!jc.get("/jails/runa"));
    assert(!jc.get("/jails/runa/"));
    assert(jc.get("/jails/runb"));

    // an explicit `/**` is needed to disable a whole subtree
    jc = pajailconf("enablejail /jails/**\ndisablejail /jails/runa/**\n");
    assert(!jc.get("/jails/runa"));
    assert(!jc.get("/jails/runa/"));
    assert(!jc.get("/jails/runa/runb"));
    assert(!jc.get("/jails/runa/runb/"));
    assert(jc.get("/jails/runb"));

    // global and local allowances are independent axes, ANDed together; a global
    // disable can be undone by a later global enable without re-matching locally
    jc = pajailconf("enablejail /jails/run*\ndisablejail\n");
    assert(!jc.get("/jails/run"));
    jc = pajailconf("enablejail /jails/run*\ndisablejail\nenablejail\n");
    assert(jc.get("/jails/run"));

    // skeleton allowance parallels jail allowance, keyed on the skeleton dir
    jc = pajailconf("enablejail /jails/**\nenableskeleton /skel/*\n");
    jailperm perm("/jails/a", "/skel/x");
    jc.parse(perm);
    assert(perm.skeletondir == "/skel/x/");
    assert(perm.skeleton_enabled);
    assert(perm.enabled);
    assert(!jc.get("/jails/a", "/other").skeleton_enabled);
    assert(!jc.get("/jails/a").skeleton_enabled);
    assert(jc.get("/jails/a").enabled);

    // a bare `enableskeleton` enables nothing by itself
    jc = pajailconf("enablejail /jails/**\nenableskeleton\n");
    assert(!jc.get("/jails/a", "/skel/x").skeleton_enabled);

    // `disableskeleton` overrides a prior enable (last match wins)
    jc = pajailconf("enablejail /jails/**\nenableskeleton /skel/*\ndisableskeleton /skel/bad\n");
    assert(jc.get("/jails/a", "/skel/ok").skeletondir == "/skel/ok/");
    assert(jc.get("/jails/a", "/skel/ok").skeleton_enabled);
    assert(!jc.get("/jails/a", "/skel/bad").skeleton_enabled);

    // a skeleton enable must not affect the jail's permdir (without the guard,
    // the `/jails/*` skeleton pattern would set permdir to "/jails/")
    jc = pajailconf("enablejail /jails/a\nenableskeleton /jails/*\n");
    assert(jc.get("/jails/a", "/jails/skel").skeleton_enabled);
    assert(jc.get("/jails/a", "/jails/skel").permdir == "/jails/a/");
}

void test_pajailconf_sections() {
    // A section gates on the JAIL DIRECTORY. An argless `enablejail` inside it
    // applies to the section's jaildir and derives permdir from the section
    // pattern -- as if it had carried that pattern as its argument.
    pajailconf jc("[/jails/run*]\nenablejail\n");
    assert(jc.get("/jails/run").enabled);
    assert(jc.get("/jails/run").permdir == "/jails/");
    assert(jc.get("/jails/runa").enabled);
    assert(jc.get("/jails/runa").permdir == "/jails/");
    assert(!jc.get("/jails/other"));        // jaildir outside the section glob
    assert(!jc.get("/jails"));
    assert(!jc.get("/other/run"));

    // a fully-literal section: permdir is the jaildir itself
    jc = pajailconf("[/data/jailbind]\nenablejail\n");
    assert(jc.get("/data/jailbind").enabled);
    assert(jc.get("/data/jailbind").permdir == "/data/jailbind/");
    assert(!jc.get("/data/jailbind/sub"));  // no cascade

    // a subtree section (`**`) gates the whole subtree; permdir is the prefix
    jc = pajailconf("[/jails/**]\nenablejail\n");
    assert(jc.get("/jails/a").enabled);
    assert(jc.get("/jails/a").permdir == "/jails/");
    assert(jc.get("/jails/a/b").enabled);
    assert(jc.get("/jails/a/b").permdir == "/jails/");
    assert(!jc.get("/other"));

    // an explicit pattern inside a section is gated by BOTH the section and its
    // own pattern (a directive matching the section but not the pattern is inert)
    jc = pajailconf("[/jails/**]\nenablejail /jails/run/*\n");
    assert(jc.get("/jails/run/x").enabled);
    assert(jc.get("/jails/run/x").permdir == "/jails/run/");
    assert(!jc.get("/jails/build/x"));      // in section, but pattern misses

    // `[]` and `[/**]` reset to the global scope: an argless directive there is
    // global (does not, by itself, locally enable any jaildir)
    jc = pajailconf("[/jails/a]\nenablejail\n[]\nenablejail /jails/b\n");
    assert(jc.get("/jails/a").enabled);
    assert(jc.get("/jails/a").permdir == "/jails/a/");
    assert(jc.get("/jails/b").enabled);
    assert(jc.get("/jails/b").permdir == "/jails/b/");

    // a per-jaildir disable inside a section vetoes only matching jaildirs
    jc = pajailconf("enablejail /jails/**\n[/jails/secret]\ndisablejail\n");
    assert(jc.get("/jails/ok").enabled);
    assert(!jc.get("/jails/secret"));

    // SKELETONS ARE PER-JAILDIR. Inside `[/foo]`, `enableskeleton SKEL` means
    // "jaildir /foo may use skeleton SKEL": gated by the section's jaildir, keyed
    // on the skeleton dir. A BARE `enableskeleton` enables nothing -- it is NOT
    // `enableskeleton /foo`.
    jc = pajailconf("[/jails/a]\nenablejail\nenableskeleton /skel/x\n");
    assert(jc.get("/jails/a", "/skel/x").enabled);
    assert(jc.get("/jails/a", "/skel/x").skeleton_enabled);
    assert(!jc.get("/jails/a", "/skel/y").skeleton_enabled);   // wrong skeleton
    assert(!jc.get("/jails/a").skeleton_enabled);              // none requested

    jc = pajailconf("[/jails/a]\nenablejail\nenableskeleton\n");
    assert(!jc.get("/jails/a", "/skel/x").skeleton_enabled);   // bare = nothing
    assert(!jc.get("/jails/a", "/jails/a").skeleton_enabled);  // NOT `... /jails/a`

    // distinct jaildirs can carry distinct skeletons
    jc = pajailconf("[/jails/a]\nenablejail\nenableskeleton /skelA/*\n"
                    "[/jails/b]\nenablejail\nenableskeleton /skelB/*\n");
    assert(jc.get("/jails/a", "/skelA/1").skeletondir == "/skelA/1/");
    assert(jc.get("/jails/a", "/skelA/1").skeleton_enabled);
    assert(!jc.get("/jails/a", "/skelB/1").skeleton_enabled);
    assert(jc.get("/jails/b", "/skelB/1").skeletondir == "/skelB/1/");
    assert(jc.get("/jails/b", "/skelB/1").skeleton_enabled);
    assert(!jc.get("/jails/b", "/skelA/1").skeleton_enabled);

    // permdir is the SHORTEST matching literal prefix, independent of file order:
    // the broadest grant wins, so a narrower overlapping rule never shrinks the
    // create zone
    jc = pajailconf("enablejail /jails/**\nenablejail /jails/run/*\n");
    assert(jc.get("/jails/run/x").permdir == "/jails/");
    jc = pajailconf("enablejail /jails/run/*\nenablejail /jails/**\n");
    assert(jc.get("/jails/run/x").permdir == "/jails/");
    assert(jc.get("/jails/build/y").permdir == "/jails/");     // only broad rule
    // a narrower rule alone still gives its own (longer) prefix
    jc = pajailconf("enablejail /jails/run/*\n");
    assert(jc.get("/jails/run/x").permdir == "/jails/run/");

    // a section added over a broad grant (e.g. just to scope future rlimits)
    // does not tighten the create boundary
    jc = pajailconf("enablejail /jails/**\n[/jails/run/special]\nenablejail\n");
    assert(jc.get("/jails/run/special").enabled);
    assert(jc.get("/jails/run/special").permdir == "/jails/");
}

void test_pajailconf_disable_lineno() {
    // a disable overriding an earlier enable: blame points at the disable line
    pajailconf jc("enablejail /jails/x\ndisablejail /jails/x\n");
    assert(!jc.get("/jails/x"));
    assert(jc.get("/jails/x").disabled_lineno == 2);
    assert(jc.get("/jails/x").disable_message() == "  (disabled on line 2)\n");

    // a global (argless top-level) veto blames its own line
    jc = pajailconf("enablejail /jails/x\ndisablejail\n");
    assert(!jc.get("/jails/x"));
    assert(jc.get("/jails/x").disabled_lineno == 2);

    // an argless disable inside a section blames that line
    jc = pajailconf("enablejail /jails/**\n[/jails/secret]\ndisablejail\n");
    assert(!jc.get("/jails/secret"));
    assert(jc.get("/jails/secret").disabled_lineno == 3);

    // never enabled: there is no responsible disable line
    jc = pajailconf("enablejail /jails/x\n");
    assert(!jc.get("/jails/y"));
    assert(jc.get("/jails/y").disabled_lineno == 0);
    assert(jc.get("/jails/y").disable_message() == "");

    // allowed despite an earlier (overridden) disable: the stamped line is dropped
    jc = pajailconf("disablejail /jails/x\nenablejail /jails/x\n");
    assert(jc.get("/jails/x").enabled);
    assert(jc.get("/jails/x").disabled_lineno == 0);

    // blank and comment lines count toward the line number
    jc = pajailconf("# comment\n\nenablejail /jails/x\ndisablejail /jails/x\n");
    assert(jc.get("/jails/x").disabled_lineno == 4);

    // a global veto BEFORE the local enable still blames the veto -- a later
    // enable does not stamp, so it cannot overwrite the responsible line
    jc = pajailconf("disablejail\nenablejail /jails/x\n");
    assert(!jc.get("/jails/x"));
    assert(jc.get("/jails/x").disabled_lineno == 1);

    // a redundant second disable does not move the blame off the first
    jc = pajailconf("enablejail /jails/x\ndisablejail /jails/x\ndisablejail /jails/x\n");
    assert(!jc.get("/jails/x"));
    assert(jc.get("/jails/x").disabled_lineno == 2);

    // a disable with no prior enable changed nothing, so it is not blamed
    jc = pajailconf("disablejail /jails/x\n");
    assert(!jc.get("/jails/x"));
    assert(jc.get("/jails/x").disabled_lineno == 0);

    // the skeleton axis never stamps a (jaildir) line number: a disabled skeleton
    // leaves the jail allowed with disabled_lineno == 0
    jc = pajailconf("enablejail /jails/a\nenableskeleton /skel/*\ndisableskeleton /skel/x\n");
    assert(jc.get("/jails/a", "/skel/x").enabled);              // jail allowed
    assert(!jc.get("/jails/a", "/skel/x").skeleton_enabled);    // skeleton denied
    assert(jc.get("/jails/a", "/skel/x").disabled_lineno == 0);
}

void test_pajailconf_query() {
    pajailconf jc("enablejail /jails/**\n");

    // the constructor (via get) slash-terminates the query, so a non-terminated
    // dir is fixed up and enabled -- and parse() leaves the fixed-up value alone
    assert(jc.get("/jails/a"));
    assert(jc.get("/jails/a").dir == "/jails/a/");
    assert(jc.get("/jails/a/").dir == "/jails/a/");
    // but the constructor does NOT make a relative dir absolute, so it stays
    // disabled (a relative jaildir never matches an absolute pattern)
    assert(!jc.get("jails/a"));
    assert(!jc.get("jails/a/"));

    // parse() reads `dir`/`skeletondir` verbatim -- it never rewrites them. A dir
    // assigned directly (bypassing the constructor's fix-up) that is not absolute
    // and slash-terminated is fail-safe disabled.
    auto parsed = [&](std::string dir, std::string skel = std::string()) {
        jailperm perm;                  // default ctor: no fix-up
        perm.dir = std::move(dir);
        perm.skeletondir = std::move(skel);
        jc.parse(perm);
        return perm;
    };
    assert(parsed("/jails/a/"));                     // well-formed -> enabled
    assert(parsed("/jails/a/").dir == "/jails/a/");  // parse() didn't touch it
    assert(!parsed("/jails/a"));                      // no trailing slash
    assert(!parsed("jails/a/"));                      // relative
    assert(!parsed("jails/a"));                       // relative + no slash
    assert(!parsed(""));                              // empty
    // the malformed dir survives parse() intact, so an error can still name it
    {
        jailperm perm;
        perm.dir = "/jails/a";
        jc.parse(perm);
        assert(!perm);
        assert(perm.dir == "/jails/a");
    }

    // skeletondir: a malformed value leaves the jail enabled but the skeleton
    // disabled (skeleton_enabled also requires absolute + slash-terminated)
    jc = pajailconf("enablejail /jails/**\nenableskeleton /skel/*\n");
    assert(parsed("/jails/a/", "/skel/x/").enabled);
    assert(parsed("/jails/a/", "/skel/x/").skeleton_enabled);     // well-formed
    assert(parsed("/jails/a/", "/skel/x").enabled);               // jail unaffected
    assert(!parsed("/jails/a/", "/skel/x").skeleton_enabled);     // no trailing slash
    assert(!parsed("/jails/a/", "skel/x/").skeleton_enabled);     // relative
    // the malformed skeletondir also survives parse() intact
    {
        jailperm perm;
        perm.dir = "/jails/a/";
        perm.skeletondir = "/skel/x";
        jc.parse(perm);
        assert(perm.enabled);
        assert(!perm.skeleton_enabled);
        assert(perm.skeletondir == "/skel/x");
    }
}

void test_pajailconf_cgroup() {
    // no `cgroupbase` directive -> the built-in default pool
    pajailconf jc("enablejail /jails/**\n");
    assert(jc.get("/jails/a").cgroupbase == default_cgroupbase);

    // a top-level `cgroupbase` is the global default for every jail
    jc = pajailconf("enablejail /jails/**\ncgroupbase /sys/fs/cgroup/grading\n");
    assert(jc.get("/jails/a").cgroupbase == "/sys/fs/cgroup/grading");

    // a section-scoped `cgroupbase` overrides only matching jaildirs; others keep
    // the global default (last-match-wins, gated by the section)
    jc = pajailconf("enablejail /jails/**\n"
                    "cgroupbase /pool/default\n"
                    "[/jails/build/*]\ncgroupbase /pool/build\n");
    assert(jc.get("/jails/build/x").cgroupbase == "/pool/build");
    assert(jc.get("/jails/run/x").cgroupbase == "/pool/default");

    // `$SELF`-relative forms are stored verbatim (expanded only at apply time)
    jc = pajailconf("enablejail /jails/**\ncgroupbase $SELF/grading\n");
    assert(jc.get("/jails/a").cgroupbase == "$SELF/grading");

    // `[cgroup PATH]` sections define pool limits, looked up by literal PATH
    jc = pajailconf("enablejail /jails/**\n"
                    "[cgroup /sys/fs/cgroup/pa-jail]\n"
                    "limit pids.max=4000,memory.max=24g\n");
    {
        jaillimits pl = jc.pool_limits("/sys/fs/cgroup/pa-jail");
        assert(pl[JLIMIT_PIDS_MAX].set && pl[JLIMIT_PIDS_MAX].value == 4000);
        assert(pl[JLIMIT_MEMORY_MAX].value == 24ULL << 30);
        assert(!pl[JLIMIT_CPU_MAX].set);
    }
    // an undefined pool has no limits
    assert(!jc.pool_limits("/sys/fs/cgroup/other")[JLIMIT_PIDS_MAX].set);
    // and a pool section does NOT leak into a jaildir query's own limits
    assert(!jc.get("/jails/a").limits[JLIMIT_PIDS_MAX].set);

    // the default pool joins by literal path: cgroupbase default <-> [cgroup default]
    jc = pajailconf(std::string("enablejail /jails/**\n[cgroup ")
                    + default_cgroupbase + "]\nlimit pids.max=1000\n");
    {
        jailperm p = jc.get("/jails/a");
        assert(p.cgroupbase == default_cgroupbase);
        assert(jc.pool_limits(p.cgroupbase)[JLIMIT_PIDS_MAX].value == 1000);
    }

    // multiple pools carry distinct limits; jails route to them via cgroupbase
    jc = pajailconf("enablejail /jails/**\n"
                    "[cgroup /pool/run]\nlimit pids.max=128\n"
                    "[cgroup /pool/build]\nlimit pids.max=512,memory.max=32g\n"
                    "[/jails/build/*]\ncgroupbase /pool/build\n");
    assert(jc.pool_limits("/pool/run")[JLIMIT_PIDS_MAX].value == 128);
    assert(jc.pool_limits("/pool/build")[JLIMIT_PIDS_MAX].value == 512);
    assert(jc.pool_limits("/pool/build")[JLIMIT_MEMORY_MAX].value == 32ULL << 30);
    assert(jc.get("/jails/build/x").cgroupbase == "/pool/build");

    // a `$SELF`-relative pool joins literally (the parser never expands it)
    jc = pajailconf("enablejail /jails/**\n"
                    "cgroupbase $SELF/grading\n"
                    "[cgroup $SELF/grading]\nlimit pids.max=200\n");
    assert(jc.pool_limits("$SELF/grading")[JLIMIT_PIDS_MAX].value == 200);
    assert(jc.pool_limits(jc.get("/jails/a").cgroupbase)[JLIMIT_PIDS_MAX].value == 200);

    // multiple limit lines in one pool accumulate (last wins per name)
    jc = pajailconf("[cgroup /p]\nlimit pids.max=64,cpu.max=1\nlimit pids.max=256\n");
    assert(jc.pool_limits("/p")[JLIMIT_PIDS_MAX].value == 256);
    assert(jc.pool_limits("/p")[JLIMIT_CPU_MAX].value == 1000);

    // a bare `[cgroup]` applies to every pool, overlaid with the pool's own
    // `[cgroup PATH]` limits (last write wins per name, in file order)
    jc = pajailconf("[cgroup]\nlimit pids.max=64,cpu.max=2\n"
                    "[cgroup /p]\nlimit pids.max=256\n");
    assert(jc.pool_limits("/p")[JLIMIT_PIDS_MAX].value == 256);    // pool overrides
    assert(jc.pool_limits("/p")[JLIMIT_CPU_MAX].value == 2000);    // inherited from `[cgroup]`
    assert(jc.pool_limits("/other")[JLIMIT_PIDS_MAX].value == 64); // only the `[cgroup]` limits
    assert(jc.pool_limits("/other")[JLIMIT_CPU_MAX].value == 2000);

    // `[cgroup]` is a pool section, so a jaildir query skips its limits
    jc = pajailconf("enablejail /jails/**\n[cgroup]\nlimit pids.max=64\n");
    assert(jc.get("/jails/a").enabled);
    assert(!jc.get("/jails/a").limits[JLIMIT_PIDS_MAX].set);

    // malformed `[...]` headers are errors: a multi-word non-cgroup section,
    // and a `[cgroup ...]` that is neither `[cgroup]` nor `[cgroup PATH]`
    assert(throws_config_error([] { pajailconf("[/a /b]\nenablejail /a\n").get("/a"); }));
    assert(throws_config_error([] { pajailconf("[cgroup /p /q]\n").get("/a"); }));

    // pools are cgroup-only: an `rlimit.*` (per-process) limit in a `[cgroup]`
    // section is rejected -- in a named pool and in the bare `[cgroup]`
    assert(throws_config_error([] { pajailconf("[cgroup /p]\nlimit rlimit.nofile=256\n").pool_limits("/p"); }));
    assert(throws_config_error([] { pajailconf("[cgroup]\nlimit pids.max=64,rlimit.core=0\n").pool_limits("/p"); }));
    // but cgroup limits there are still fine
    jc = pajailconf("[cgroup /p]\nlimit pids.max=64,memory.max=1g\n");
    assert(jc.pool_limits("/p")[JLIMIT_PIDS_MAX].value == 64);
}

// The jaillimitinfo table: each row sits at its `jaillimit_id` index, its name
// round-trips through lookup(), and the cgroup limits are exactly the contiguous
// head `[JLIMIT_CGROUP_FIRST, JLIMIT_CGROUP_LAST)` (the rest are rlimits).
void test_jaillimitinfo() {
    // name <-> JLIMIT_* index correspondence (a sample from each mechanism)
    assert(jaillimitinfo::get(JLIMIT_PIDS_MAX).name == "pids.max");
    assert(jaillimitinfo::get(JLIMIT_MEMORY_HIGH).name == "memory.high");
    assert(jaillimitinfo::get(JLIMIT_RLIMIT_CPU).name == "rlimit.cpu");
    assert(jaillimitinfo::get(JLIMIT_RLIMIT_NOFILE).name == "rlimit.nofile");

    // lookup() inverts it; an unknown name is -1
    assert(jaillimitinfo::lookup("pids.max") == JLIMIT_PIDS_MAX);
    assert(jaillimitinfo::lookup("rlimit.nproc") == JLIMIT_RLIMIT_NPROC);
    assert(jaillimitinfo::lookup("bogus") == -1);
    assert(jaillimitinfo::lookup("") == -1);
    // every row's name round-trips to its own index
    for (int id =0; id != JLIMIT_COUNT; ++id) {
        assert(jaillimitinfo::lookup(jaillimitinfo::get(id).name) == id);
    }

    // check cgroup/rlimit ranges
    for (int id = JLIMIT_CGROUP_FIRST; id != JLIMIT_CGROUP_LAST; ++id) {
        assert(jaillimitinfo::get(id).is_cgroup());
    }
    for (int id = JLIMIT_RLIMIT_FIRST; id != JLIMIT_RLIMIT_LAST; ++id) {
        assert(!jaillimitinfo::get(id).is_cgroup());
    }

    // a cgroup row's controller is the name before `.`; an rlimit row carries a
    // real RLIMIT_* (not the -1 placeholder)
    assert(jaillimitinfo::get(JLIMIT_PIDS_MAX).cgroup_controller() == "pids");
    assert(jaillimitinfo::get(JLIMIT_MEMORY_MAX).cgroup_controller() == "memory");
    assert(jaillimitinfo::get(JLIMIT_MEMORY_HIGH).cgroup_controller() == "memory");
    assert(jaillimitinfo::get(JLIMIT_RLIMIT_CORE).rlimit_resource() >= 0);

    // tmpfs.size is neither cgroup nor rlimit -- it sits past the rlimit range
    assert(jaillimitinfo::lookup("tmpfs.size") == JLIMIT_TMPFS_SIZE);
    assert(!jaillimitinfo::get(JLIMIT_TMPFS_SIZE).is_cgroup());
    assert(JLIMIT_TMPFS_SIZE >= JLIMIT_RLIMIT_LAST);   // excluded from both ranges
}

void test_pajailconf_limit() {
    // a global one-arg `limit` is a default applied to every allowed jail;
    // unnamed limits stay unset (the feature is opt-in)
    pajailconf jc("enablejail /jails/**\nlimit pids.max=128,cpu.max=1.5\n");
    jailperm p = jc.get("/jails/a");
    assert(p.enabled);
    assert(p.limits[JLIMIT_PIDS_MAX].set);
    assert(!p.limits[JLIMIT_PIDS_MAX].unlimited);
    assert(!p.limits[JLIMIT_PIDS_MAX].pinned);
    assert(p.limits[JLIMIT_PIDS_MAX].value == 128);
    assert(p.limits[JLIMIT_CPU_MAX].set);
    assert(p.limits[JLIMIT_CPU_MAX].value == 1500);     // cpu.max is millicores
    // a jail with no `limit` directive has no limits set
    jc = pajailconf("enablejail /jails/**\n");
    assert(!jc.get("/jails/a").limits[JLIMIT_PIDS_MAX].set);
    assert(!jc.get("/jails/a").limits[JLIMIT_CPU_MAX].set);

    // CPU rates: cores (decimal) and percentages both fold to millicores
    jc = pajailconf("enablejail /j\nlimit cpu.max=1\n");
    assert(jc.get("/j").limits[JLIMIT_CPU_MAX].value == 1000);
    jc = pajailconf("enablejail /j\nlimit cpu.max=0.5\n");
    assert(jc.get("/j").limits[JLIMIT_CPU_MAX].value == 500);
    jc = pajailconf("enablejail /j\nlimit cpu.max=2\n");
    assert(jc.get("/j").limits[JLIMIT_CPU_MAX].value == 2000);
    jc = pajailconf("enablejail /j\nlimit cpu.max=50%\n");
    assert(jc.get("/j").limits[JLIMIT_CPU_MAX].value == 500);
    jc = pajailconf("enablejail /j\nlimit cpu.max=150%\n");
    assert(jc.get("/j").limits[JLIMIT_CPU_MAX].value == 1500);
    jc = pajailconf("enablejail /j\nlimit cpu.max=12.5%\n");
    assert(jc.get("/j").limits[JLIMIT_CPU_MAX].value == 125);
    // fractional digits beyond the stored precision truncate
    jc = pajailconf("enablejail /j\nlimit cpu.max=1.2345\n");
    assert(jc.get("/j").limits[JLIMIT_CPU_MAX].value == 1234);

    // memory.max / memory.high are byte limits with 1024-based k/m/g suffixes
    jc = pajailconf("enablejail /j\nlimit memory.max=4096\n");
    assert(jc.get("/j").limits[JLIMIT_MEMORY_MAX].set);
    assert(jc.get("/j").limits[JLIMIT_MEMORY_MAX].value == 4096);
    jc = pajailconf("enablejail /j\nlimit memory.max=512k\n");
    assert(jc.get("/j").limits[JLIMIT_MEMORY_MAX].value == 512ULL * 1024);
    jc = pajailconf("enablejail /j\nlimit memory.high=2M\n");
    assert(jc.get("/j").limits[JLIMIT_MEMORY_HIGH].value == 2ULL * 1024 * 1024);
    jc = pajailconf("enablejail /j\nlimit memory.max=8g\n");
    assert(jc.get("/j").limits[JLIMIT_MEMORY_MAX].value == 8ULL * 1024 * 1024 * 1024);
    jc = pajailconf("enablejail /j\nlimit memory.swap.max=2g\n");
    assert(jc.get("/j").limits[JLIMIT_MEMORY_SWAP_MAX].value == 2ULL << 30);
    assert(jaillimitinfo::get(JLIMIT_MEMORY_SWAP_MAX).cgroup_controller() == "memory");
    // tmpfs.size is a byte limit too (a /tmp mount cap, not cgroup/rlimit)
    jc = pajailconf("enablejail /j\nlimit tmpfs.size=64m\n");
    assert(jc.get("/j").limits[JLIMIT_TMPFS_SIZE].value == 64ULL << 20);
    // a `[cgroup]` pool rejects it, like any non-cgroup limit
    assert(throws_config_error([] { pajailconf("[cgroup /p]\nlimit tmpfs.size=64m\n").pool_limits("/p"); }));
    // throttle + hard cap together
    jc = pajailconf("enablejail /j\nlimit memory.high=20g,memory.max=24g\n");
    assert(jc.get("/j").limits[JLIMIT_MEMORY_HIGH].value == 20ULL << 30);
    assert(jc.get("/j").limits[JLIMIT_MEMORY_MAX].value == 24ULL << 30);
    jc = pajailconf("enablejail /j\nlimit memory.max=max\n");
    assert(jc.get("/j").limits[JLIMIT_MEMORY_MAX].unlimited);

    // `unlimited`/`inf`/`max` are the infinite forms; value is ignored
    jc = pajailconf("enablejail /j\nlimit pids.max=unlimited\n");
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].set);
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].unlimited);
    jc = pajailconf("enablejail /j\nlimit pids.max=inf,cpu.max=max\n");
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].unlimited);
    assert(jc.get("/j").limits[JLIMIT_CPU_MAX].unlimited);

    // `cgroup=unlimited` sets every cgroup limit to unlimited at once, and
    // leaves the per-process `rlimit.*` limits untouched
    jc = pajailconf("enablejail /j\nlimit cgroup=unlimited\n");
    for (int id = JLIMIT_CGROUP_FIRST; id != JLIMIT_CGROUP_LAST; ++id) {
        assert(jc.get("/j").limits[id].set);
        assert(jc.get("/j").limits[id].unlimited);
    }
    for (int id = JLIMIT_RLIMIT_CPU; id != JLIMIT_COUNT; ++id) {
        assert(!jc.get("/j").limits[id].set);
    }
    // it overlays like any other limit (a later specific limit overrides)
    jc = pajailconf("enablejail /j\nlimit cgroup=unlimited,pids.max=64\n");
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].value == 64);
    assert(!jc.get("/j").limits[JLIMIT_PIDS_MAX].unlimited);
    assert(jc.get("/j").limits[JLIMIT_MEMORY_MAX].unlimited);
    // a `!` suffix pins all of them
    jc = pajailconf("enablejail /j\nlimit cgroup=unlimited!\n");
    assert(jc.get("/j").limits[JLIMIT_CPU_MAX].pinned);

    // `cgroup=unset` clears every cgroup limit (overriding inherited defaults)
    jc = pajailconf("enablejail /j\nlimit pids.max=64,memory.max=1g\nlimit cgroup=unset\n");
    for (int id = 0; id != JLIMIT_COUNT; ++id) {
        assert(!jc.get("/j").limits[id].set);
    }
    // and overlays: clear all, then set one
    jc = pajailconf("enablejail /j\nlimit cgroup=unset,pids.max=64\n");
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].value == 64);
    assert(!jc.get("/j").limits[JLIMIT_MEMORY_MAX].set);

    // only `unlimited` / `unset` are accepted after `cgroup=`
    assert(throws_config_error([] { pajailconf("enablejail /j\nlimit cgroup=64\n").get("/j"); }));
    assert(throws_config_error([] { pajailconf("enablejail /j\nlimit cgroup=max\n").get("/j"); }));

    // `=unset` clears one named limit (symmetric with `=unlimited`)
    jc = pajailconf("enablejail /j\nlimit pids.max=64,cpu.max=2\nlimit pids.max=unset\n");
    assert(!jc.get("/j").limits[JLIMIT_PIDS_MAX].set);
    assert(jc.get("/j").limits[JLIMIT_CPU_MAX].value == 2000);   // others untouched

    // a `!` suffix pins the value
    jc = pajailconf("enablejail /j\nlimit pids.max=64!\n");
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].value == 64);
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].pinned);
    jc = pajailconf("enablejail /j\nlimit pids.max=64\n");
    assert(!jc.get("/j").limits[JLIMIT_PIDS_MAX].pinned);
    assert(!jc.get("/j").limits[JLIMIT_PIDS_MAX].soft);

    // a `?` suffix marks the value soft (best-effort); orthogonal to `!`, and
    // the two combine in either order
    jc = pajailconf("enablejail /j\nlimit pids.max=64?\n");
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].value == 64);
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].soft);
    assert(!jc.get("/j").limits[JLIMIT_PIDS_MAX].pinned);
    jc = pajailconf("enablejail /j\nlimit pids.max=64!?\n");
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].pinned);
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].soft);
    jc = pajailconf("enablejail /j\nlimit pids.max=64?!\n");
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].pinned);
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].soft);
    // soft works on the other value forms too
    jc = pajailconf("enablejail /j\nlimit memory.max=2g?\nlimit rlimit.core=0?\n");
    assert(jc.get("/j").limits[JLIMIT_MEMORY_MAX].soft);
    assert(jc.get("/j").limits[JLIMIT_MEMORY_MAX].value == 2ULL << 30);
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_CORE].soft);
    // `cgroup=` carries soft to every cgroup limit
    jc = pajailconf("enablejail /j\nlimit cgroup=unlimited?\n");
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].soft);
    assert(jc.get("/j").limits[JLIMIT_MEMORY_HIGH].soft);

    // last write wins, per name; the overlay leaves untouched names alone
    jc = pajailconf("enablejail /jails/**\nlimit pids.max=64,cpu.max=1\nlimit pids.max=256\n");
    assert(jc.get("/jails/a").limits[JLIMIT_PIDS_MAX].value == 256);
    assert(jc.get("/jails/a").limits[JLIMIT_CPU_MAX].value == 1000);

    // two-arg `limit JDIR LIMITS` applies only on jails matching JDIR
    jc = pajailconf("enablejail /jails/**\nlimit /jails/run/* pids.max=128\n");
    assert(jc.get("/jails/run/a").limits[JLIMIT_PIDS_MAX].value == 128);
    assert(!jc.get("/jails/build/a").limits[JLIMIT_PIDS_MAX].set);
    // JDIR honors `**` like any other pattern
    jc = pajailconf("enablejail /jails/**\nlimit /jails/run/** pids.max=128\n");
    assert(jc.get("/jails/run/a").limits[JLIMIT_PIDS_MAX].value == 128);
    assert(jc.get("/jails/run/a/b").limits[JLIMIT_PIDS_MAX].value == 128);
    assert(!jc.get("/jails/build").limits[JLIMIT_PIDS_MAX].set);

    // global default, then a two-arg override for a subset: overlay precedence
    jc = pajailconf("enablejail /jails/**\nlimit pids.max=64\nlimit /jails/big/* pids.max=512\n");
    assert(jc.get("/jails/small").limits[JLIMIT_PIDS_MAX].value == 64);
    assert(jc.get("/jails/big/x").limits[JLIMIT_PIDS_MAX].value == 512);

    // an argless `limit` inside a section applies to the section's jaildir
    jc = pajailconf("[/jails/run/*]\nenablejail\nlimit pids.max=128\n");
    assert(jc.get("/jails/run/a").enabled);
    assert(jc.get("/jails/run/a").limits[JLIMIT_PIDS_MAX].value == 128);
    assert(!jc.get("/jails/run/a/b").limits[JLIMIT_PIDS_MAX].set);  // outside section

    // a section's relative JDIR is section-relative; gated by BOTH the section
    // and the directive's own pattern
    jc = pajailconf("[/jails/**]\nenablejail\nlimit run/* pids.max=200\n");
    assert(jc.get("/jails/run/a").limits[JLIMIT_PIDS_MAX].value == 200);
    assert(!jc.get("/jails/build/a").limits[JLIMIT_PIDS_MAX].set);  // pattern misses
    assert(!jc.get("/other/run/a").limits[JLIMIT_PIDS_MAX].set);    // section misses

    // distinct sections carry distinct limits
    jc = pajailconf("[/jails/a]\nenablejail\nlimit pids.max=10\n"
                    "[/jails/b]\nenablejail\nlimit pids.max=20\n");
    assert(jc.get("/jails/a").limits[JLIMIT_PIDS_MAX].value == 10);
    assert(jc.get("/jails/b").limits[JLIMIT_PIDS_MAX].value == 20);

    // a global default overlaid by a per-section value
    jc = pajailconf("limit pids.max=64\n[/jails/run]\nenablejail\nlimit pids.max=128\n");
    assert(jc.get("/jails/run").limits[JLIMIT_PIDS_MAX].value == 128);

    // per-process rlimit.* limits parse alongside the cgroup limits, on their
    // own units (count, bytes, seconds), and share the same overlay machinery
    jc = pajailconf("enablejail /j\nlimit rlimit.nofile=256,rlimit.core=0\n");
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_NOFILE].value == 256);
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_CORE].set);
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_CORE].value == 0);
    jc = pajailconf("enablejail /j\nlimit rlimit.as=4g,rlimit.fsize=256m\n");
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_AS].value == 4ULL << 30);
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_FSIZE].value == 256ULL << 20);
    jc = pajailconf("enablejail /j\nlimit rlimit.nproc=unlimited\n");
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_NPROC].unlimited);

    // rlimit.cpu is a cumulative-time limit: bare number is seconds, s/m/h scale
    jc = pajailconf("enablejail /j\nlimit rlimit.cpu=30\n");
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_CPU].value == 30);
    jc = pajailconf("enablejail /j\nlimit rlimit.cpu=30s\n");
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_CPU].value == 30);
    jc = pajailconf("enablejail /j\nlimit rlimit.cpu=2m\n");
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_CPU].value == 120);
    jc = pajailconf("enablejail /j\nlimit rlimit.cpu=1h\n");
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_CPU].value == 3600);

    // cgroup and rlimit limits mix freely in one directive (the §6.1 example)
    jc = pajailconf("enablejail /j\nlimit pids.max=64,cpu.max=1,rlimit.nofile=256,"
                    "rlimit.fsize=256m,rlimit.core=0\n");
    assert(jc.get("/j").limits[JLIMIT_PIDS_MAX].value == 64);
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_NOFILE].value == 256);
    assert(jc.get("/j").limits[JLIMIT_RLIMIT_FSIZE].value == 256ULL << 20);
}

// `--limit` command-line overrides (parse_limit_override + apply_limit_override).
// The command line may only TIGHTEN: per name the result is the more restrictive
// of conf and cmdline, a `!`-pinned conf value is immune, and hard beats soft.
void test_limit_override() {
    // a set limit at value `v` (flags default off)
    auto mk = [](unsigned long long v, bool pinned = false, bool soft = false,
                 bool unlimited = false) {
        jaillimit l;
        l.set = true; l.unlimited = unlimited; l.pinned = pinned;
        l.soft = soft; l.value = v;
        return l;
    };

    // parse_limit_override: same grammar as a conf `limit`, repeated flags overlay
    jaillimits over;
    parse_limit_override("pids.max=10,memory.max=1g", over);
    assert(over[JLIMIT_PIDS_MAX].value == 10 && over[JLIMIT_PIDS_MAX].set);
    assert(over[JLIMIT_MEMORY_MAX].value == 1ULL << 30);
    parse_limit_override("pids.max=5", over);               // last write wins per name
    assert(over[JLIMIT_PIDS_MAX].value == 5);
    assert(over[JLIMIT_MEMORY_MAX].value == 1ULL << 30);    // untouched
    // a malformed value or unknown name throws
    assert(throws_config_error([] { jaillimits x; parse_limit_override("pids.max=bad", x); }));
    assert(throws_config_error([] { jaillimits x; parse_limit_override("nope=1", x); }));

    int id = JLIMIT_PIDS_MAX;
    // tighter override wins; looser is ignored
    { jaillimits b, o; b[id] = mk(64); o[id] = mk(10);
      apply_limit_override(b, o); assert(b[id].value == 10); }
    { jaillimits b, o; b[id] = mk(64); o[id] = mk(128);
      apply_limit_override(b, o); assert(b[id].value == 64); }
    // cmdline-only introduces; conf-only is kept
    { jaillimits b, o; o[id] = mk(10);
      apply_limit_override(b, o); assert(b[id].set && b[id].value == 10); }
    { jaillimits b, o; b[id] = mk(64);
      apply_limit_override(b, o); assert(b[id].value == 64); }
    // a `!`-pinned conf value ignores the override entirely
    { jaillimits b, o; b[id] = mk(64, true); o[id] = mk(10);
      apply_limit_override(b, o); assert(b[id].value == 64); }
    // unlimited = +infinity: conf-unlimited is tightened by a finite cmdline,
    // but a cmdline `unlimited` can never loosen a finite conf value
    { jaillimits b, o; b[id] = mk(0, false, false, true); o[id] = mk(10);
      apply_limit_override(b, o); assert(!b[id].unlimited && b[id].value == 10); }
    { jaillimits b, o; b[id] = mk(10); o[id] = mk(0, false, false, true);
      apply_limit_override(b, o); assert(!b[id].unlimited && b[id].value == 10); }
    // hard beats soft (cmdline can tighten soft->hard, but not loosen hard->soft)
    { jaillimits b, o; b[id] = mk(10, false, true); o[id] = mk(10);
      apply_limit_override(b, o); assert(!b[id].soft); }
    { jaillimits b, o; b[id] = mk(10); o[id] = mk(5, false, true);
      apply_limit_override(b, o); assert(b[id].value == 5 && !b[id].soft); }
    { jaillimits b, o; b[id] = mk(10, false, true); o[id] = mk(5, false, true);
      apply_limit_override(b, o); assert(b[id].value == 5 && b[id].soft); }
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

void test_shell_quote() {
    // entirely-safe strings are returned verbatim (no quoting)
    assert(shell_quote("abc") == "abc");
    assert(shell_quote("ABZ019") == "ABZ019");
    assert(shell_quote("a_b-c.d/e") == "a_b-c.d/e");
    assert(shell_quote("/usr/bin/foo-bar_baz.sh") == "/usr/bin/foo-bar_baz.sh");
    // `~` is safe only when it is NOT the first character
    assert(shell_quote("a~") == "a~");
    assert(shell_quote("a~b") == "a~b");
    assert(shell_quote("~") == "'~'");          // leading tilde forces quoting
    assert(shell_quote("~root") == "'~root'");

    // empty argument must become an explicit empty word, not vanish
    assert(shell_quote("") == "''");

    // any other character forces single-quoting of the whole word; the safe
    // characters around it are included inside the quotes
    assert(shell_quote("a b") == "'a b'");
    assert(shell_quote(" ") == "' '");
    assert(shell_quote("$PATH") == "'$PATH'");
    assert(shell_quote("a;b") == "'a;b'");
    assert(shell_quote("a&b|c") == "'a&b|c'");
    assert(shell_quote("a*b?c[d]") == "'a*b?c[d]'");
    assert(shell_quote("a\"b") == "'a\"b'");
    assert(shell_quote("a\\b") == "'a\\b'");
    assert(shell_quote("a\nb") == "'a\nb'");
    assert(shell_quote("a\tb") == "'a\tb'");
    assert(shell_quote("#a=b:c") == "'#a=b:c'");

    // single quotes are emitted as the close/escape/reopen idiom `'\''`
    assert(shell_quote("'") == "''\\'''");       // ''  \'  ''  -> one literal '
    assert(shell_quote("a'b") == "'a'\\''b'");
    assert(shell_quote("'a") == "''\\''a'");
    assert(shell_quote("a'") == "'a'\\'''");
    assert(shell_quote("''") == "''\\'''\\'''"); // two adjacent quotes
}

// Independent oracle: decode a string produced by shell_quote the way a POSIX
// shell would, treating it as a single word. Models only what shell_quote can
// emit -- runs of literal characters and `'...'` spans (with single quotes
// rendered as the unquoted-and-backslash-escaped `\'`). Returns false if the
// input does not parse to exactly one word: an unquoted blank (which would
// split the word) or an unterminated quote both fail.
static bool shq_decode(const std::string& q, std::string& out) {
    out.clear();
    bool any = false;
    size_t i = 0, n = q.size();
    while (i < n) {
        char c = q[i];
        if (c == ' ' || c == '\t' || c == '\n') {
            return false;       // shell_quote must never leave a blank unquoted
        }
        any = true;
        if (c == '\'') {
            ++i;
            while (i < n && q[i] != '\'') {
                out += q[i];
                ++i;
            }
            if (i == n) {
                return false;   // unterminated single quote
            }
            ++i;                // consume the closing quote
        } else if (c == '\\') {
            if (i + 1 == n) {
                return false;   // dangling backslash
            }
            out += q[i + 1];
            i += 2;
        } else {
            out += c;
            ++i;
        }
    }
    return any;                 // empty input == zero words == failure
}

// Best-effort second oracle: ask a real /bin/sh to parse `q` as a word list and
// report the count and the first word's bytes. Returns false (and the test
// skips it) if a shell cannot be run in this environment. `set --` makes the
// quoted text the positional parameters; we print the count, a literal `X`
// separator (never produced by the digit count), then `$1` verbatim.
static bool shell_can_run = true;
static bool shell_eval_word(const std::string& q, int& count, std::string& word) {
    std::string cmd = "set -- " + q + "; printf %s \"$#\"; printf X; printf %s \"$1\"";
    FILE* f = popen(cmd.c_str(), "r");
    if (!f) {
        return false;
    }
    std::string out;
    char buf[4096];
    size_t nr;
    while ((nr = fread(buf, 1, sizeof(buf), f)) > 0) {
        out.append(buf, nr);
    }
    int rc = pclose(f);
    if (rc != 0) {
        return false;
    }
    size_t x = out.find('X');
    if (x == std::string::npos) {
        return false;
    }
    count = atoi(out.substr(0, x).c_str());
    word = out.substr(x + 1);
    return true;
}

void fuzz_shell_quote() {
    // an "interesting" alphabet: safe characters, shell metacharacters, quotes,
    // whitespace, and a couple of high bytes (quoted under the C locale)
    static const char alpha[] = {
        'a', 'Z', '5', '_', '-', '.', '/', '~',
        ' ', '\t', '\n', '\'', '"', '`', '$', '\\',
        ';', '&', '|', '<', '>', '(', ')', '{', '}',
        '*', '?', '[', ']', '#', '!', '=', ':', '+', '%', '^',
        (char) 0x80, (char) 0xC3
    };
    const size_t nalpha = sizeof(alpha) / sizeof(alpha[0]);

    // probe once whether a shell is usable for cross-checking
    {
        int c; std::string w;
        shell_can_run = shell_eval_word("ok", c, w) && c == 1 && w == "ok";
    }

    // deterministic PRNG (xorshift32, fixed seed) so any failure reproduces
    uint32_t st = 0x9e3779b9u;
    auto rnd = [&]() {
        st ^= st << 13; st ^= st >> 17; st ^= st << 5;
        return st;
    };

    // The pure decoder oracle is cheap; the real-shell cross-check forks a shell
    // per case, so it is capped low by default. `PA_SHELL_FUZZ=N` scales it up
    // (e.g. for a thorough local run); 0 disables it.
    const int iters = 200000;
    int shell_budget = 20;
    if (const char* e = getenv("PA_SHELL_FUZZ")) {
        shell_budget = atoi(e);
    }
    int shell_checked = 0;
    for (int it = 0; it != iters; ++it) {
        size_t len = rnd() % 13;        // 0..12 bytes, including the empty word
        std::string s;
        for (size_t j = 0; j != len; ++j) {
            s += alpha[rnd() % nalpha];
        }

        std::string q = shell_quote(s);

        // oracle 1: our own decoder round-trips to exactly the original word
        std::string decoded;
        bool ok = shq_decode(q, decoded);
        if (!ok || decoded != s) {
            fprintf(stderr, "fuzz_shell_quote: decode mismatch for %zu bytes\n",
                    s.size());
            assert(ok && decoded == s);
        }

        // oracle 2: a real shell parses it as one word equal to the original.
        // Cap the shell round-trips to keep `make check` fast.
        if (shell_can_run && shell_checked < shell_budget) {
            ++shell_checked;
            int count = -1;
            std::string word;
            if (shell_eval_word(q, count, word)) {
                if (count != 1 || word != s) {
                    fprintf(stderr, "fuzz_shell_quote: shell saw %d word(s)"
                            " for %zu-byte input\n", count, s.size());
                    assert(count == 1 && word == s);
                }
            }
        }
    }
    if (!shell_can_run) {
        fprintf(stderr, "test-pa-jailconf: (note) no usable /bin/sh;"
                " skipped shell cross-check of shell_quote\n");
    }
}

int main() {
    test_pathmatch();
    test_pathmatch_literal_prefix();
    test_pajailconf();
    test_pajailconf_sections();
    test_pajailconf_disable_lineno();
    test_pajailconf_query();
    test_pajailconf_limit();
    test_pajailconf_cgroup();
    test_jaillimitinfo();
    test_limit_override();
    test_path_absolute();
    test_path_pa_validate();
    test_shell_quote();
    fuzz_shell_quote();
    fprintf(stderr, "test-pa-jailconf: all tests passed\n");
}
