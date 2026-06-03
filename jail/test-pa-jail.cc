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
#include <string>
#include <vector>
#include <utility>
#include <algorithm>
#include <unistd.h>
#include <sys/wait.h>

static bool use_docker = false;
static bool verbose = false;
static bool pa_verbose = false;     // pass `-V` to pa-jail and show its trace
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
    return c + " --fg " + shq(jr.jaildir) + " pajtest " + shq(jr.command);
}

// A self-contained /bin/sh script that sets up and runs one jail. pa-jail is
// already built (at /build/pa-jail in the image, or ./pa-jail locally).
static std::string gen_script(const jail_run& jr) {
    std::string s = "set -e\n";
    if (jr.cgroup_prep) {
        // Delegate the cgroup controllers at the cgroup root so per-jail leaves
        // can carry limits. Needed inside a container (its cgroup root holds
        // processes and delegates nothing); a no-op where already delegated
        // (e.g. a systemd host). The `no internal processes` rule forbids
        // enabling controllers while the root holds processes, so move them out.
        s += "if ! grep -qw pids /sys/fs/cgroup/cgroup.subtree_control 2>/dev/null; then "
             "mkdir -p /sys/fs/cgroup/pajinit; "
             "for p in $(cat /sys/fs/cgroup/cgroup.procs 2>/dev/null); do "
             "echo $p > /sys/fs/cgroup/pajinit/cgroup.procs 2>/dev/null || true; done; "
             "echo +pids > /sys/fs/cgroup/cgroup.subtree_control 2>/dev/null || true; "
             "fi\n";
    }
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

// A command runs confined to a per-jail cgroup: a `limit` triggers cgroup setup,
// and the jailed process's /proc/self/cgroup is the pa-jail leaf.
static void test_cgroup() {
    jail_run jr;
    jr.conf = "enablejail /jails/**\n[/jails/**]\nlimit pids.max=64\n";
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

// cgroup `pids.max` actually limits process creation: a fork bomb is stopped
// below the limit rather than running away. Attempting up to `pids.max` forks,
// the cap (which also counts the supervisor and shell) must trip first.
static void test_forkbomb() {
    jail_run jr;
    jr.conf = "enablejail /jails/**\n[/jails/**]\nlimit pids.max=32\n";
    jr.user_shell = "/bin/sh";
    jr.manifest = shell_manifest("/bin/sh");
    jr.manifest.push_back("/usr/local/bin/forkbomb");
    jr.jaildir = "/jails/forkbomb";
    jr.setup = "cat > /tmp/forkbomb.c <<'FORKBOMB_EOF'\n" + std::string(FORKBOMB_SRC)
        + "FORKBOMB_EOF\ncc -static -O2 -o /usr/local/bin/forkbomb /tmp/forkbomb.c\n";
    jr.command = "/usr/local/bin/forkbomb 32";
    jr.cgroup_prep = true;
    expect_output("forkbomb", jr, "forkbomb: capped at ");
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
    std::string build = "mkdir -p /build && make -C /build -f /src/GNUmakefile pa-jail";

    auto [out, code] = capture("docker run --name " + cname + " -v "
        + shq(cwd() + ":/src:ro") + " " + BASE_IMAGE + " sh -c " + shq(build));
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

    init_docker_image();

    test_echo();
    test_cgroup();
    test_forkbomb();

    printf("test-pa-jail: all tests passed\n");
    return 0;
}
