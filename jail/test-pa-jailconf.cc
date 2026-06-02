#undef NDEBUG
#include "pa-jailconf.hh"
#include "pa-jutil.hh"
#include <cassert>
#include <cstdio>
#include <cstdint>
#include <string>

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
    assert(jc.get("/jails/a", "/skel/x").skeletondir == "/skel/x/");
    assert(jc.get("/jails/a", "/skel/x/").skeletondir == "/skel/x/");
    assert(jc.get("/jails/a", "/skel/x").allowed);
    assert(jc.get("/jails/a", "/other").skeletondir == "");
    assert(jc.get("/jails/a").skeletondir == "");
    assert(jc.get("/jails/a").allowed);

    // a bare `enableskeleton` enables nothing by itself
    jc = pajailconf("enablejail /jails/**\nenableskeleton\n");
    assert(jc.get("/jails/a", "/skel/x").skeletondir == "");

    // `disableskeleton` overrides a prior enable (last match wins)
    jc = pajailconf("enablejail /jails/**\nenableskeleton /skel/*\ndisableskeleton /skel/bad\n");
    assert(jc.get("/jails/a", "/skel/ok").skeletondir == "/skel/ok/");
    assert(jc.get("/jails/a", "/skel/bad").skeletondir == "");

    // a skeleton enable must not affect the jail's permdir (without the guard,
    // the `/jails/*` skeleton pattern would set permdir to "/jails/")
    jc = pajailconf("enablejail /jails/a\nenableskeleton /jails/*\n");
    assert(jc.get("/jails/a", "/jails/skel").skeletondir == "/jails/skel/");
    assert(jc.get("/jails/a", "/jails/skel").permdir == "/jails/a/");
}

void test_pajailconf_sections() {
    // A section gates on the JAIL DIRECTORY. An argless `enablejail` inside it
    // applies to the section's jaildir and derives permdir from the section
    // pattern -- as if it had carried that pattern as its argument.
    pajailconf jc("[/jails/run*]\nenablejail\n");
    assert(jc.get("/jails/run").allowed);
    assert(jc.get("/jails/run").permdir == "/jails/");
    assert(jc.get("/jails/runa").allowed);
    assert(jc.get("/jails/runa").permdir == "/jails/");
    assert(!jc.get("/jails/other"));        // jaildir outside the section glob
    assert(!jc.get("/jails"));
    assert(!jc.get("/other/run"));

    // a fully-literal section: permdir is the jaildir itself
    jc = pajailconf("[/data/jailbind]\nenablejail\n");
    assert(jc.get("/data/jailbind").allowed);
    assert(jc.get("/data/jailbind").permdir == "/data/jailbind/");
    assert(!jc.get("/data/jailbind/sub"));  // no cascade

    // a subtree section (`**`) gates the whole subtree; permdir is the prefix
    jc = pajailconf("[/jails/**]\nenablejail\n");
    assert(jc.get("/jails/a").allowed);
    assert(jc.get("/jails/a").permdir == "/jails/");
    assert(jc.get("/jails/a/b").allowed);
    assert(jc.get("/jails/a/b").permdir == "/jails/");
    assert(!jc.get("/other"));

    // an explicit pattern inside a section is gated by BOTH the section and its
    // own pattern (a directive matching the section but not the pattern is inert)
    jc = pajailconf("[/jails/**]\nenablejail /jails/run/*\n");
    assert(jc.get("/jails/run/x").allowed);
    assert(jc.get("/jails/run/x").permdir == "/jails/run/");
    assert(!jc.get("/jails/build/x"));      // in section, but pattern misses

    // `[]` and `[/**]` reset to the global scope: an argless directive there is
    // global (does not, by itself, locally enable any jaildir)
    jc = pajailconf("[/jails/a]\nenablejail\n[]\nenablejail /jails/b\n");
    assert(jc.get("/jails/a").allowed);
    assert(jc.get("/jails/a").permdir == "/jails/a/");
    assert(jc.get("/jails/b").allowed);
    assert(jc.get("/jails/b").permdir == "/jails/b/");

    // a per-jaildir disable inside a section vetoes only matching jaildirs
    jc = pajailconf("enablejail /jails/**\n[/jails/secret]\ndisablejail\n");
    assert(jc.get("/jails/ok").allowed);
    assert(!jc.get("/jails/secret"));

    // SKELETONS ARE PER-JAILDIR. Inside `[/foo]`, `enableskeleton SKEL` means
    // "jaildir /foo may use skeleton SKEL": gated by the section's jaildir, keyed
    // on the skeleton dir. A BARE `enableskeleton` enables nothing -- it is NOT
    // `enableskeleton /foo`.
    jc = pajailconf("[/jails/a]\nenablejail\nenableskeleton /skel/x\n");
    assert(jc.get("/jails/a", "/skel/x").allowed);
    assert(jc.get("/jails/a", "/skel/x").skeletondir == "/skel/x/");
    assert(jc.get("/jails/a", "/skel/y").skeletondir == "");   // wrong skeleton
    assert(jc.get("/jails/a").skeletondir == "");              // none requested

    jc = pajailconf("[/jails/a]\nenablejail\nenableskeleton\n");
    assert(jc.get("/jails/a", "/skel/x").skeletondir == "");   // bare = nothing
    assert(jc.get("/jails/a", "/jails/a").skeletondir == "");  // NOT `... /jails/a`

    // distinct jaildirs can carry distinct skeletons
    jc = pajailconf("[/jails/a]\nenablejail\nenableskeleton /skelA/*\n"
                    "[/jails/b]\nenablejail\nenableskeleton /skelB/*\n");
    assert(jc.get("/jails/a", "/skelA/1").skeletondir == "/skelA/1/");
    assert(jc.get("/jails/a", "/skelB/1").skeletondir == "");
    assert(jc.get("/jails/b", "/skelB/1").skeletondir == "/skelB/1/");
    assert(jc.get("/jails/b", "/skelA/1").skeletondir == "");

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
    assert(jc.get("/jails/run/special").allowed);
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
    assert(jc.get("/jails/x").allowed);
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
    assert(jc.get("/jails/a", "/skel/x").allowed);              // jail allowed
    assert(jc.get("/jails/a", "/skel/x").skeletondir == "");    // skeleton denied
    assert(jc.get("/jails/a", "/skel/x").disabled_lineno == 0);
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
    test_path_absolute();
    test_path_pa_validate();
    test_shell_quote();
    fuzz_shell_quote();
    fprintf(stderr, "test-pa-jailconf: all tests passed\n");
}
