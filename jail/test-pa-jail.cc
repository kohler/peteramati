// test-pa-jail.cc -- end-to-end tests that run pa-jail and check jail behavior
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms
//
// Unlike test-pa-jailconf (pure parser unit tests), this driver runs the real
// `pa-jail` binary and inspects what happens inside a jail. Running a jail needs
// root and Linux, so there are two modes:
//
//   ./test-pa-jail              run ./pa-jail directly (root on Linux)
//   ./test-pa-jail --docker     build and run pa-jail inside an ephemeral
//                               `gcc:14` container (works from macOS too)
//   ./test-pa-jail --pa-verbose run pa-jail with `-V` and print its command
//                               trace (mkdir/mount/echo/...) for each test
//
// The harness compiles on both Linux and macOS; in `--docker` mode the host only
// needs Docker. Each test writes /etc/pa-jail.conf, derives a manifest, runs a
// command in a jail, and asserts on the relayed output.

#undef NDEBUG
#include <cassert>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <climits>
#include <algorithm>
#include <format>
#include <string>
#include <vector>
#include <utility>
#include <unistd.h>
#include <sys/wait.h>

static bool use_docker = false;
static bool verbose = false;
static bool pa_verbose = false;     // pass `-V` to pa-jail and show its trace
static bool have_cgroup = true;     // does the pa-jail under test support cgroups?
                                    // (false when built/run with PA_HAVE_CGROUP=0)
static const char* BASE_IMAGE = "gcc:14";
static std::string run_image;       // throwaway image with pa-jail built in
static bool image_created = false;

// Single-quote `s` for safe inclusion in a /bin/sh command line.
static std::string shq(const std::string& s) {
    std::string r = "'";
    for (char c : s) {
        r += c == '\'' ? "'\\''" : std::string(1, c);
    }
    return r + "'";
}

static std::string cwd() {
    char buf[4096];
    if (!getcwd(buf, sizeof(buf))) {
        perror("getcwd");
        exit(1);
    }
    return buf;
}

// Run `cmd` via /bin/sh, capturing combined stdout+stderr; return {output, exit}.
static std::pair<std::string, int> capture(const std::string& cmd) {
    FILE* p = popen((cmd + " 2>&1").c_str(), "r");
    if (!p) {
        perror("popen");
        exit(1);
    }
    std::string out;
    char buf[4096];
    size_t n;
    while ((n = fread(buf, 1, sizeof(buf), p)) > 0) {
        out.append(buf, n);
    }
    int st = pclose(p);
    return {out, st == -1 || !WIFEXITED(st) ? -1 : WEXITSTATUS(st)};
}

// Extract the library paths from `ldd` output: the file after `=> ` on each
// line, plus a bare leading `/path` (the dynamic loader). `linux-vdso` and other
// pathless entries are skipped.
static std::vector<std::string> parse_ldd(const std::string& out) {
    std::vector<std::string> paths;
    for (size_t i = 0; i < out.size(); ) {
        size_t eol = out.find('\n', i);
        std::string line = out.substr(i, (eol == std::string::npos ? out.size() : eol) - i);
        i = eol == std::string::npos ? out.size() : eol + 1;

        std::string path;
        if (size_t arrow = line.find(" => "); arrow != std::string::npos) {
            path = line.substr(arrow + 4);
        } else if (size_t s = line.find_first_not_of(" \t");
                   s != std::string::npos && line[s] == '/') {
            path = line.substr(s);
        }
        if (size_t paren = path.find(" ("); paren != std::string::npos) {
            path = path.substr(0, paren);
        }
        if (!path.empty() && path[0] == '/'
            && std::find(paths.begin(), paths.end(), path) == paths.end()) {
            paths.push_back(path);
        }
    }
    return paths;
}

// A manifest that brings `shell` and its shared-library dependencies into a jail,
// derived from `ldd` run in the environment pa-jail will run in.
static std::vector<std::string> shell_manifest(const std::string& shell) {
    std::string cmd = "ldd " + shq(shell);
    if (use_docker) {
        cmd = "docker run --rm " + run_image + " " + cmd;
    }
    auto [out, code] = capture(cmd);
    if (code != 0) {
        fprintf(stderr, "ldd %s failed (exit %d):\n%s\n", shell.c_str(), code, out.c_str());
        exit(1);
    }
    std::vector<std::string> m{shell};
    for (const std::string& lib : parse_ldd(out)) {
        m.push_back(lib);
    }
    return m;
}

struct jail_run {
    std::string conf;                   // /etc/pa-jail.conf contents (root-owned)
    std::string user_shell;             // jail user's shell (must be pa-jail-allowed)
    std::vector<std::string> manifest;  // files to copy into the jail
    std::string jaildir;                // jail directory, e.g. /jails/echo
    std::string command;                // command run in the jail
    std::string setup;                  // extra shell run before pa-jail (e.g. build a helper)
    std::string limit;                  // `--limit` argument (empty = none)
    bool cgroup_prep = false;           // delegate cgroup controllers first
};

// The pa-jail binary's path (in the image, or locally).
static std::string pajail_path() {
    return use_docker ? "/build/pa-jail" : cwd() + "/pa-jail";
}

// The `pa-jail run ...` invocation for this test.
static std::string pajail_command(const jail_run& jr) {
    std::string c = shq(pajail_path()) + (pa_verbose ? " run -q -V" : " run -q");
    for (const std::string& f : jr.manifest) {
        c += " -F " + shq(f);
    }
    if (!jr.limit.empty()) {
        c += " --limit " + shq(jr.limit);
    }
    return c + " --fg " + shq(jr.jaildir) + " pajtest " + shq(jr.command);
}

// A self-contained /bin/sh script that sets up and runs one jail. pa-jail is
// already built (at /build/pa-jail in the image, or ./pa-jail locally).
static std::string gen_script(const jail_run& jr) {
    std::string s = "set -e\n";
    // a jail user with a home under /home and an allowed shell
    s += "id pajtest >/dev/null 2>&1 || useradd -m -d /home/pajtest -s "
        + shq(jr.user_shell) + " pajtest 2>/dev/null\n";
    // the config must be root-owned and not writable by non-root
    s += "cat > /etc/pa-jail.conf <<'PAJCONF'\n" + jr.conf + "PAJCONF\n"
         "chmod 644 /etc/pa-jail.conf\n";
    // the jail's parent must pre-exist root-owned; pa-jail creates the jail itself
    std::string parent = jr.jaildir.substr(0, jr.jaildir.rfind('/'));
    s += "mkdir -p " + shq(parent.empty() ? "/" : parent)
        + " && chmod 755 " + shq(parent.empty() ? "/" : parent) + "\n";
    if (jr.cgroup_prep) {
        // bootstrap the jail's cgroup base (delegate controllers, evacuating the
        // container's cgroup root) via the real `init` subcommand; needs the
        // config above to resolve the jaildir's cgroupbase
        s += shq(pajail_path()) + " init" + (pa_verbose ? " -V" : "")
            + " " + shq(jr.jaildir) + "\n";
    }
    s += jr.setup;
    s += pajail_command(jr) + "\n";
    return s;
}

static std::pair<std::string, int> run_jail(const jail_run& jr) {
    std::string script = gen_script(jr), cmd;
    if (use_docker) {
        cmd = "docker run --rm --privileged " + run_image + " sh -c " + shq(script);
    } else {
        cmd = "sh -c " + shq(script);
    }
    return capture(cmd);
}

static void expect_output(const char* name, const jail_run& jr, const std::string& expected) {
    if (verbose) {
        fprintf(stderr, "[%s] pa-jail.conf:\n%s[%s] exec: %s\n",
                name, jr.conf.c_str(), name, pajail_command(jr).c_str());
    }
    auto [out, code] = run_jail(jr);
    bool ok = out.find(expected) != std::string::npos;
    if (!ok || verbose || pa_verbose) {
        fprintf(stderr, "[%s] exit=%d, output:\n%s\n", name, code, out.c_str());
    }
    if (!ok) {
        // exit (not abort) so the atexit image cleanup still runs
        fprintf(stderr, "test-pa-jail: %s FAILED: expected `%s` in output\n",
                name, expected.c_str());
        exit(1);
    }
}

// A shell `echo` runs in a jail built from an ldd-derived manifest.
static void test_echo() {
    jail_run jr;
    jr.conf = "enablejail /jails/**\n";
    jr.user_shell = "/bin/sh";
    jr.manifest = shell_manifest("/bin/sh");
    jr.jaildir = "/jails/echo";
    jr.command = "echo hello-from-jail";
    expect_output("echo", jr, "hello-from-jail");
}

// pa-jail hard-codes hardening flags on its internal mounts regardless of host
// config: /tmp gets nosuid+nodev (but NOT noexec -- student build output is
// executed); /proc gets nosuid+nodev+noexec; /dev/pts gets nosuid+noexec but NOT
// nodev (its pty slaves are devices). The host /tmp is mounted suid,dev,exec
// first, so the jail's flags must come from pa-jail, not be inherited.
static void test_mount_flags() {
    jail_run jr;
    jr.conf = "enablejail /jails/**\n";
    jr.user_shell = "/bin/sh";
    jr.manifest = shell_manifest("/bin/sh");
    jr.jaildir = "/jails/mnt";
    jr.setup = "mount -t tmpfs -o rw,suid,dev,exec tmpfs /tmp\n";
    // print "<mountpoint>=<options>" for the internal mounts, using only sh builtins
    jr.command = "while read d m t o r; do case \"$m\" in "
                 "/tmp|/proc|/dev/pts) echo \"$m=$o\";; esac; done < /proc/mounts";
    auto [out, code] = run_jail(jr);

    auto opts = [&](const char* mnt) -> std::string {
        std::string key = std::string(mnt) + "=";
        size_t p = out.find("\n" + key);
        p = p == std::string::npos ? (out.compare(0, key.size(), key) == 0 ? 0 : p) : p + 1;
        if (p == std::string::npos) {
            return "";
        }
        size_t b = p + key.size(), e = out.find('\n', b);
        return out.substr(b, (e == std::string::npos ? out.size() : e) - b);
    };
    auto has = [](const std::string& o, const char* flag) {
        return ("," + o + ",").find("," + std::string(flag) + ",") != std::string::npos;
    };
    std::string tmp = opts("/tmp"), proc = opts("/proc"), pts = opts("/dev/pts");
    bool ok = has(tmp, "nosuid") && has(tmp, "nodev") && !has(tmp, "noexec")
        && has(proc, "nosuid") && has(proc, "nodev") && has(proc, "noexec")
        && has(pts, "nosuid") && has(pts, "noexec") && !has(pts, "nodev");
    if (!ok || verbose || pa_verbose) {
        fprintf(stderr, "[mount] exit=%d\n  /tmp=%s\n  /proc=%s\n  /dev/pts=%s\n",
                code, tmp.c_str(), proc.c_str(), pts.c_str());
    }
    if (!ok) {
        fprintf(stderr, "test-pa-jail: mount FAILED: hardening flags wrong (see above)\n");
        exit(1);
    }
    printf("test-pa-jail: mount ok (/tmp nosuid+nodev, /dev/pts nosuid+noexec, /proc all)\n");
}

// `limit tmpfs.size=N` caps the jail's /tmp tmpfs via the mount `size=` option
// (a non-cgroup, non-rlimit limit). `--limit` tightens it but cannot loosen it.
// The host /tmp is a plain (uncapped) tmpfs, so the cap must come from pa-jail.
static void test_tmpfs_size() {
    jail_run jr;
    jr.conf = "enablejail /jails/**\n[/jails/**]\nlimit tmpfs.size=8m\n";
    jr.user_shell = "/bin/sh";
    jr.manifest = shell_manifest("/bin/sh");
    jr.jaildir = "/jails/sz";
    jr.setup = "mount -t tmpfs -o rw tmpfs /tmp\n";
    jr.command = "while read d m t o r; do case \"$m\" in /tmp) echo \"$o\";; esac; done < /proc/mounts";
    auto size_ok = [&](const char* limit, const char* want) -> bool {
        jr.limit = limit;
        auto [out, code] = run_jail(jr);
        bool ok = out.find(want) != std::string::npos;
        if (!ok || verbose || pa_verbose) {
            fprintf(stderr, "[tmpfs %s] exit=%d want=%s, /tmp=%s",
                    limit[0] ? limit : "(none)", code, want, out.c_str());
        }
        return ok;
    };
    // conf 8m (= 8192k); --limit 4m tightens; --limit 16m can't loosen (8m holds)
    bool ok = size_ok("", "size=8192k")
        && size_ok("tmpfs.size=4m", "size=4096k")
        && size_ok("tmpfs.size=16m", "size=8192k");
    if (!ok) {
        fprintf(stderr, "test-pa-jail: tmpfs FAILED: size cap wrong (see above)\n");
        exit(1);
    }
    printf("test-pa-jail: tmpfs ok (cap 8m, --limit tightens to 4m, can't loosen to 16m)\n");
}

// A pa-jail built without cgroup support must fail closed on a hard cgroup limit:
// the run aborts with the "does not support cgroups" message and the command
// never executes. (Used by the cgroup-enforcement tests when `!have_cgroup`.)
static void expect_cgroup_refused(const char* name, const std::string& conf,
                                  const std::string& jaildir) {
    jail_run jr;
    jr.conf = conf;
    jr.user_shell = "/bin/sh";
    jr.manifest = shell_manifest("/bin/sh");
    jr.jaildir = jaildir;
    jr.command = "echo should-not-run";
    auto [out, code] = run_jail(jr);
    bool ok = out.find("does not support cgroups") != std::string::npos
        && out.find("should-not-run") == std::string::npos;
    if (!ok || verbose || pa_verbose) {
        fprintf(stderr, "[%s] exit=%d, output:\n%s\n", name, code, out.c_str());
    }
    if (!ok) {
        fprintf(stderr, "test-pa-jail: %s FAILED: no-cgroup build did not refuse "
                "a hard cgroup limit\n", name);
        exit(1);
    }
}

// A command runs confined to a per-jail cgroup: a `limit` triggers cgroup setup,
// and the jailed process's /proc/self/cgroup is the pa-jail leaf.
static void test_cgroup() {
    std::string conf = "enablejail /jails/**\n[/jails/**]\nlimit pids.max=64\n";
    if (!have_cgroup) {
        expect_cgroup_refused("cgroup", conf, "/jails/cgroup");
        return;
    }
    jail_run jr;
    jr.conf = conf;
    jr.user_shell = "/bin/sh";
    jr.manifest = shell_manifest("/bin/sh");
    jr.jaildir = "/jails/cgroup";
    jr.command = "read cg < /proc/self/cgroup; echo \"mycgroup=$cg\"";
    jr.cgroup_prep = true;
    expect_output("cgroup", jr, "mycgroup=0::/pa-jail/");
}

// A static fork bomb: fork until `argv[1]` children exist or fork fails, then
// report. Children `pause()` to hold their pids. Under a cgroup `pids.max`, fork
// fails first ("capped"); unconfined, it reaches the limit ("not capped").
static const char FORKBOMB_SRC[] = R"FB(#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
int main(int argc, char** argv) {
    int limit = argc > 1 ? atoi(argv[1]) : 1000, n = 0;
    for (;;) {
        pid_t p = fork();
        if (p < 0) { printf("forkbomb: capped at %d\n", n); fflush(stdout); _exit(0); }
        if (p == 0) pause();
        if (++n >= limit) { printf("forkbomb: not capped\n"); fflush(stdout); _exit(0); }
    }
}
)FB";

// Shell that compiles the forkbomb to a static binary at /usr/local/bin/forkbomb
// (so it can run in the jail with no shared-library manifest entries).
static std::string forkbomb_setup() {
    return "cat > /tmp/forkbomb.c <<'FORKBOMB_EOF'\n" + std::string(FORKBOMB_SRC)
        + "FORKBOMB_EOF\ncc -static -O2 -o /usr/local/bin/forkbomb /tmp/forkbomb.c\n";
}

// The N from `forkbomb: capped at N`; INT_MAX if it reached its target ("not
// capped"); -1 if the forkbomb produced no result line at all.
static int forkbomb_cap(const std::string& out) {
    if (size_t p = out.find("forkbomb: capped at "); p != std::string::npos) {
        return atoi(out.c_str() + p + 20);
    }
    return out.find("forkbomb: not capped") != std::string::npos ? INT_MAX : -1;
}

// A soft limit (`?`) that can't be enforced does not abort the run. With no
// cgroup_prep the cgroup base is undelegated, so a `pids.max` can't be applied:
// the hard form makes pa-jail die before exec, while the soft form warns and runs
// the command anyway. (Same jail, same un-enforceable limit -- only `?` differs.)
static void test_soft_limit() {
    jail_run jr;
    jr.user_shell = "/bin/sh";
    jr.manifest = shell_manifest("/bin/sh");
    jr.jaildir = "/jails/soft";
    jr.command = "echo soft-ran";       // cgroup_prep left false on purpose

    // hard: the un-enforceable limit must abort the run before exec (this also
    // proves the base really is undelegated here, so the soft success below is
    // meaningful and not just an enforced-but-harmless cap)
    jr.conf = "enablejail /jails/**\n[/jails/**]\nlimit pids.max=64\n";
    auto [hout, hcode] = run_jail(jr);
    if (hout.find("soft-ran") != std::string::npos) {
        fprintf(stderr, "[soft] hard-control output:\n%s\n", hout.c_str());
        fprintf(stderr, "test-pa-jail: soft FAILED: a hard limit ran without enforcement\n");
        exit(1);
    }

    // soft: the same limit, only `?` added, must run anyway (warnings suppressed
    // here by `-q`)
    jr.conf = "enablejail /jails/**\n[/jails/**]\nlimit pids.max=64?\n";
    auto [sout, scode] = run_jail(jr);
    if (sout.find("soft-ran") == std::string::npos || verbose || pa_verbose) {
        fprintf(stderr, "[soft] exit=%d, output:\n%s\n", scode, sout.c_str());
    }
    if (sout.find("soft-ran") == std::string::npos) {
        fprintf(stderr, "test-pa-jail: soft FAILED: soft limit did not run unconfined\n");
        exit(1);
    }
    printf("test-pa-jail: soft ok (hard limit refused, soft limit ran unconfined)\n");
}

// cgroup `pids.max` actually limits process creation: a fork bomb is stopped
// below the limit rather than running away. Attempting up to `pids.max` forks,
// the cap (which also counts the supervisor and shell) must trip first.
static void test_forkbomb() {
    std::string conf = "enablejail /jails/**\n[/jails/**]\nlimit pids.max=32\n";
    if (!have_cgroup) {
        expect_cgroup_refused("forkbomb", conf, "/jails/forkbomb");
        return;
    }
    jail_run jr;
    jr.conf = conf;
    jr.user_shell = "/bin/sh";
    jr.manifest = shell_manifest("/bin/sh");
    jr.manifest.push_back("/usr/local/bin/forkbomb");
    jr.jaildir = "/jails/forkbomb";
    jr.setup = forkbomb_setup();
    jr.command = "/usr/local/bin/forkbomb 32";
    jr.cgroup_prep = true;
    expect_output("forkbomb", jr, "forkbomb: capped at ");
}

// Both a shared pool limit AND each jail's own (leaf) limit are enforced.
// /jails/a and /jails/b share one pool (the default `/sys/fs/cgroup/pa-jail`)
// whose aggregate `pids.max` (15) sits *between* the two jails' own limits:
//
//   pool   pids.max = 15
//   /jails/a  pids.max = 10   (leaf < pool -> the jail's own limit binds)
//   /jails/b  pids.max = 20   (leaf > pool -> the shared pool limit binds)
//
// Each jail runs in its own container, applying the effective `min(leaf, pool)`.
// A forkbomb that tries far more than either limit reports where it was stopped:
// /jails/a must cap below 10 (its own limit), /jails/b below 15 (the pool) and
// thus below its own 20 -- so a missing leaf limit (a would cap near 15) or a
// missing pool limit (b would cap near 20) both fail the test.
static void test_pool_limits() {
    std::string conf =
        "enablejail /jails/**\n"
        "[cgroup /sys/fs/cgroup/pa-jail]\n"
        "limit pids.max=15\n"
        "[/jails/a]\n"
        "limit pids.max=10\n"
        "[/jails/b]\n"
        "limit pids.max=20\n";
    if (!have_cgroup) {
        expect_cgroup_refused("pool", conf, "/jails/a");
        return;
    }
    auto fork_cap = [&](const char* jaildir) -> int {
        jail_run jr;
        jr.conf = conf;
        jr.user_shell = "/bin/sh";
        jr.manifest = shell_manifest("/bin/sh");
        jr.manifest.push_back("/usr/local/bin/forkbomb");
        jr.jaildir = jaildir;
        jr.setup = forkbomb_setup();
        jr.command = "/usr/local/bin/forkbomb 40";
        jr.cgroup_prep = true;
        auto [out, code] = run_jail(jr);
        int cap = forkbomb_cap(out);
        if (cap < 0 || verbose || pa_verbose) {
            fprintf(stderr, "[pool %s] exit=%d, output:\n%s\n", jaildir, code, out.c_str());
        }
        if (cap < 0) {
            fprintf(stderr, "test-pa-jail: pool FAILED: no forkbomb result for %s\n", jaildir);
            exit(1);
        }
        return cap;
    };
    int cap_a = fork_cap("/jails/a");   // bound by its own leaf limit (10)
    int cap_b = fork_cap("/jails/b");   // bound by the shared pool limit (15)
    if (!(cap_a >= 1 && cap_a < 10 && cap_b >= 1 && cap_b < 15 && cap_b > cap_a)) {
        fprintf(stderr, "test-pa-jail: pool FAILED: /jails/a capped at %d "
                "(want <10, leaf-bound), /jails/b capped at %d "
                "(want <15 and >%d, pool-bound)\n", cap_a, cap_b, cap_a);
        exit(1);
    }
    printf("test-pa-jail: pool ok (a leaf-capped at %d, b pool-capped at %d)\n",
           cap_a, cap_b);
}

// Per-process rlimit.* limits are applied to the student process via setrlimit:
// the jailed shell's `ulimit` reflects them. This jail sets no cgroup limit, so
// it also covers the plain-clone (no-cgroup) path with rlimits applied.
static void test_rlimit() {
    jail_run jr;
    jr.conf = "enablejail /jails/**\n[/jails/**]\nlimit rlimit.nofile=42,rlimit.core=0\n";
    jr.user_shell = "/bin/sh";
    jr.manifest = shell_manifest("/bin/sh");
    jr.jaildir = "/jails/rlimit";
    jr.command = "echo nofile=$(ulimit -n) core=$(ulimit -c)";
    expect_output("rlimit", jr, "nofile=42 core=0");
}

// `--limit` tightens a conf cgroup limit at run time but can never loosen it.
// The conf allows pids.max=32; `--limit pids.max=8` caps the forkbomb below 8,
// while `--limit pids.max=128` leaves the conf's 32 in force (cap well below 32).
static void test_limit() {
    if (!have_cgroup) {
        return;                     // needs real cgroup enforcement
    }
    jail_run jr;
    jr.conf = "enablejail /jails/**\n[/jails/**]\nlimit pids.max=32\n";
    jr.user_shell = "/bin/sh";
    jr.manifest = shell_manifest("/bin/sh");
    jr.manifest.push_back("/usr/local/bin/forkbomb");
    jr.jaildir = "/jails/limit";
    jr.setup = forkbomb_setup();
    jr.command = "/usr/local/bin/forkbomb 40";
    jr.cgroup_prep = true;

    auto cap_with = [&](const char* limit) -> int {
        jr.limit = limit;
        auto [out, code] = run_jail(jr);
        int cap = forkbomb_cap(out);
        if (cap < 0 || verbose || pa_verbose) {
            fprintf(stderr, "[limit %s] exit=%d cap=%d, output:\n%s\n", limit, code, cap, out.c_str());
        }
        return cap;
    };

    int tight = cap_with("pids.max=8");     // tighter than conf 32 -> applies
    int loose = cap_with("pids.max=128");   // looser than conf 32 -> ignored
    if (!(tight >= 1 && tight < 8 && loose >= 8 && loose < 32)) {
        fprintf(stderr, "test-pa-jail: limit FAILED: --limit pids.max=8 capped at %d "
                "(want <8), --limit pids.max=128 capped at %d (want >=8 and <32, "
                "i.e. conf 32 still in force)\n", tight, loose);
        exit(1);
    }
    printf("test-pa-jail: limit ok (tightened to %d, loosen attempt held at %d)\n",
           tight, loose);
}

// True if a running container is using `image` -- i.e. another test-pa-jail run.
static bool image_in_use(const std::string& image) {
    auto [out, code] = capture("docker ps -q --filter ancestor=" + image);
    return code == 0 && out.find_first_not_of(" \t\r\n") != std::string::npos;
}

// Remove the throwaway image, unless a concurrent run is using it. Registered
// with atexit, so it runs on normal exit and on a test failure's exit(1). Our
// own test containers are `--rm` and finished by now, so `image_in_use` is true
// only if another run holds it.
static void cleanup_image() {
    if (image_created) {
        image_created = false;
        if (!image_in_use(run_image)) {
            capture("docker rmi -f " + run_image);
        }
    }
}

// Build pa-jail once and commit it to a throwaway image the tests run from, so
// each test starts a container instead of recompiling. No-op outside --docker.
//
// The image has a fixed name (`test-pa-jail`), so a leftover from a killed run
// is reused rather than accumulating -- but if that image is actively running
// (a concurrent run), a per-pid name is used instead to leave it alone.
static void init_docker_image() {
    if (!use_docker) {
        return;
    }
    run_image = "test-pa-jail";
    if (image_in_use(run_image)) {
        run_image += "-" + std::to_string(getpid());
    } else {
        capture("docker rmi -f " + run_image);     // clear a killed run's leftover
    }

    std::string cname = "pa-jail-build-" + std::to_string(getpid());
    std::string docker_run = std::format("docker run --name {} -v {} ", cname, shq(cwd() + ":/src:ro"));
    if (const char* ev = getenv("PA_HAVE_CGROUP");
        ev && (strcmp(ev, "0") == 0 || strcmp(ev, "1") == 0)) {
        docker_run += std::format("--env PA_HAVE_CGROUP={} ", ev);
    }

    std::string build = "mkdir -p /build && make -C /build -f /src/GNUmakefile pa-jail";

    auto [out, code] = capture(std::format("{} {} sh -c {}", docker_run, BASE_IMAGE, shq(build)));
    if (code != 0) {
        fprintf(stderr, "test-pa-jail: building pa-jail failed:\n%s\n", out.c_str());
        capture("docker rm -f " + cname);
        exit(1);
    }

    auto [cout, ccode] = capture("docker commit " + cname + " " + run_image);
    capture("docker rm -f " + cname);
    if (ccode != 0) {
        fprintf(stderr, "test-pa-jail: docker commit failed:\n%s\n", cout.c_str());
        exit(1);
    }
    image_created = true;
    atexit(cleanup_image);
    if (verbose) {
        fprintf(stderr, "built throwaway image %s\n", run_image.c_str());
    }
}

int main(int argc, char** argv) {
    for (int i = 1; i < argc; ++i) {
        std::string a = argv[i];
        if (a == "--docker") {
            use_docker = true;
        } else if (a == "--verbose" || a == "-V") {
            verbose = true;
        } else if (a == "--pa-verbose") {
            pa_verbose = true;
        } else {
            fprintf(stderr, "usage: %s [--docker] [--verbose] [--pa-verbose]\n", argv[0]);
            return 1;
        }
    }

    // PA_HAVE_CGROUP=0 (passed through to the build) means the binary under test
    // has no cgroup support, so the cgroup-enforcement tests instead assert the
    // fail-closed behavior.
    if (const char* ev = getenv("PA_HAVE_CGROUP"); ev && strcmp(ev, "0") == 0) {
        have_cgroup = false;
    }

    init_docker_image();

    test_echo();
    test_mount_flags();
    test_tmpfs_size();
    test_cgroup();
    test_rlimit();
    test_forkbomb();
    test_pool_limits();
    test_soft_limit();
    test_limit();

    printf("test-pa-jail: all tests passed\n");
    return 0;
}
