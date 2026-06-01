# pa-jail hardening design

Status: living document — original audit + plan, with completed items marked.
Parts of Phase 1 have landed (supplementary-group drop, `no_new_privs`, the
`snprintf` fix); the config parser is extracted and unit-tested. Updated 2026-06.
Author: (generated audit + plan)
Scope: `jail/pa-jail.cc`, `jail/GNUmakefile`, `/etc/pa-jail.conf`, and the
invocation path in `src/queueitem.php` + `src/psetconfig.php`.

## 1. Background: what pa-jail is

`pa-jail` is a **setuid-root + setgid** helper (`GNUmakefile` installs it
`chown root:0`, `chmod u+s,g+s`) that the grading user invokes to run untrusted
student code. The relevant run path is:

`main()` → `jailownerinfo::exec()` → `clone()` → `exec_clone_function` →
`exec_go()` → final privilege drop → `execve()` of the student command, with a
supervisor process (PID 1 of the new PID namespace) enforcing a timeout.

What it does today for a run:

1. Drops euid/egid to the caller to open caller-owned side files
   (`seteuid`/`setegid`), then re-escalates real+eff+saved to root
   (`setresuid/setresgid(ROOT)`).
2. `clone()`s the child with `CLONE_NEWIPC | CLONE_NEWNS | CLONE_NEWPID`.
3. Makes `/` recursively slave (`MS_REC|MS_SLAVE`), mounts `/proc`,
   `/dev/pts`, `/tmp`, `/run`, then `pivot_root`s into the jail and detaches
   the old root (`umount2(..., MNT_DETACH)`).
4. Permanently drops to the unprivileged jail user
   (`setresgid(g,g,g)`/`setresuid(u,u,u)`) **before** `execve`, resets all
   signal handlers, `setsid`.
5. Supervisor enforces wall-clock + idle timeouts via `poll()`; on Linux it
   tears the jail down by exiting (killing the PID namespace).

### Threat model

- **Staff-controlled**: the command, jail flags, jaildir, username, skeleton /
  bind dirs, jailfiles manifest. Configured in `psetconfig.php`; students
  cannot set them.
- **Student-controlled**: the *code that runs inside* the jail (git checkout
  into the jail home) and a sanitized `runsettings` env allowlist
  (`PATH`/`LD_LIBRARY_PATH`/`HOME`/`SHELL`/`MAKE`/… are denylisted in
  `queueitem.php`).
- The PHP→jail boundary is `proc_open` with an argv array (no shell). The
  student command itself is run by `bash -lc` *inside* the jail as the
  unprivileged jail user.

## 2. What pa-jail already does well (preserve these)

- `pivot_root` + `MNT_DETACH` (not a bare `chroot`); old root unreachable.
- `MS_REC|MS_SLAVE` on `/` stops mount propagation back to the host.
- Correct privilege-drop ordering: gid before uid; final drop sets
  real=eff=saved to the user (root cannot be regained); happens before `execve`.
- No shell anywhere in privileged paths — all exec via argv vectors.
- Strong setuid path-walk: component-by-component `openat(O_PATH|O_NOFOLLOW)`,
  each ancestor required root-owned and not group/other-writable.
- `/etc/pa-jail.conf` must be root-owned, non-writable, opened `O_NOFOLLOW`.
- Symlink-safe, single-filesystem recursive delete (`remove_recursive`).
- FD hygiene: `close_unwanted_fds()` + `O_CLOEXEC`; child gets a pty, never the
  listening socket or pty master.
- Env allowlist instead of passing `environ` through.
- **Supplementary groups dropped** — `setgroups(0, NULL)` while still euid 0,
  before the final gid/uid drop, so the jail user keeps none of root's groups.
- **`prctl(PR_SET_NO_NEW_PRIVS, 1)`** set in the child before `execve`.

## 3. Missing container-security features (ranked)

1. **No resource limits at all** — no cgroups, no `setrlimit`. No fork-bomb
   protection (`RLIMIT_NPROC`/`pids.max`), no memory cap (`RLIMIT_AS`/
   `memory.max`), no CPU-time cap, no `RLIMIT_FSIZE`, `RLIMIT_NOFILE`,
   `RLIMIT_CORE`. Only the wall-clock/idle timeout throttles anything. A
   fork bomb or memory balloon is bounded only by host-global kernel defaults.
   **Highest risk: a student can take down the grading host.**
2. **No network isolation** — no `CLONE_NEWNET`. Jailed code shares the host
   network namespace: full outbound (and host-reachable inbound) network. No
   config knob exists.
3. **No seccomp filter** — full host syscall surface (`keyctl`, `bpf`,
   `userfaultfd`, `io_uring`, `ptrace`, `unshare`, …) reachable by student code.
4. **`nosuid`/`nodev`/`noexec` not enforced** — the mount-flag vocabulary exists
   in the parser but is honored only if the operator writes it into the manifest;
   the binary never hard-codes it. A setuid binary or device node in the jail
   tree is a live escalation path. (`no_new_privs` itself is now set — see §2.)
5. **No capability bounding-set / ambient drop**, and **no `mknod` allowlist**
   (arbitrary device major/minor from the manifest).
6. **No `CLONE_NEWUSER`** — setup runs as real host root; "root in the jail" is
   real root.
7. **No tmpfs size cap** — `/tmp`, `/run` mounted with no `size=` (default
   ~50% RAM): a RAM-fill DoS overlapping with (1).

(Resolved since the original audit and moved to §2: supplementary-group drop and
`no_new_privs`.)

## 4. Existing problems / bugs

- **macOS timeout path only kills the direct child** (`kill(child, SIGKILL)`,
  no `killpg`); a double-forked grandchild survives. Linux is saved by PID-ns
  teardown. (Non-production path, but wrong.)
- **Source-side population is not symlink/TOCTOU-hardened** (`lstat`→`/bin/cp`
  check-then-use; intermediate symlinks followed). Bounded by manifest trust,
  so lower priority.

(Resolved since the original audit: the unbounded `sprintf` building `HOME=`,
now `snprintf` into `homebuf[8192]` from `getpwnam()->pw_dir`; the "ownership
checks skipped for the jail root" gap — the `jaildirinfo` path walk now requires
the final jail-root target to be root-owned and non-writable for the
creating/running actions, even when it pre-exists at/below `permdir` (Phase 3
§12); and `shell_quote` is now tested (§7) — the optimized "return verbatim if
safe, else single-quote" form was kept and covered by explicit cases plus a
fuzzer, and an empty argument now quotes to `''` instead of vanishing.)

## 5. Hardening plan (phased)

### Phase 1 — stop host-takeover (low effort, high payoff)

All of these land in the child between `pivot_root` and `execve`, except the
cgroup setup (which is set up by the parent before/around `clone`).

1. **cgroup v2 limits.** Create a per-run cgroup under a delegated subtree;
   write `pids.max`, `memory.max` (+ `memory.swap.max`), optionally `cpu.max`;
   place the child in it (write `cgroup.procs`, or `clone3` + `CLONE_INTO_CGROUP`).
   Kills fork bombs and memory balloons deterministically — what the timeout
   cannot do. Driven by config (see §6).
2. **`setrlimit` belt-and-suspenders** in the child before exec: `RLIMIT_NPROC`,
   `RLIMIT_AS` (or rely on cgroup), `RLIMIT_FSIZE`, `RLIMIT_NOFILE`,
   `RLIMIT_CORE=0`, `RLIMIT_CPU`. (See §6 for the config surface.)
3. ✅ **Done — `setgroups(0, NULL)`** immediately before the final
   `setresgid`/`setresuid`.
4. ✅ **Done — `prctl(PR_SET_NO_NEW_PRIVS, 1)`** in the child before exec.
5. **Hard-enforce `nosuid,nodev,noexec`** on `/proc`, `/dev/pts`, `/tmp`,
   `/run` (and read-only where compatible); add `size=` to the tmpfs mounts.
6. ✅ **Done — fixed the unbounded `sprintf`** (→ `snprintf`).

### Phase 2 — shrink kernel / network attack surface

7. **Network namespace** (`CLONE_NEWNET`): default loopback-only for
   autograding (bring `lo` up, no veth); opt-in `run_network` flag + veth/NAT
   for psets that need it.
8. **seccomp-bpf filter** installed after `no_new_privs`, before `execve`,
   fail-closed. Start from a denylist of dangerous/unnecessary syscalls, then
   tighten toward an allowlist once real workloads are profiled.
9. **Capability bounding-set + ambient clear** (`PR_CAPBSET_DROP` loop, clear
   ambient) as defense-in-depth around the uid drop.

### Phase 3 — structural

10. **`CLONE_NEWUSER`** so jail-root maps to an unprivileged host uid (large
    refactor; interacts with the setuid model and mount setup — do last).
11. **`mknod` device allowlist** (`null`, `zero`, `full`, `random`, `urandom`,
    `tty`, `ptmx` only).
12. ✅ **Done — tightened `permdir` trust.** The `jaildirinfo` path walk now
    ownership-checks the final jail-root target (root-owned, not group/other-
    writable) for the creating/running actions even when it pre-exists at/below
    `permdir`. A root-created root is 0755 `root:root` and passes; this rejects a
    loosely-permissioned pre-existing root that could be used to stage a setuid
    binary or swap the tree.
13. `CLONE_NEWUTS`; consider `hidepid=2` on the proc mount.

Phase 1 items 3, 4, and 6, and Phase 3 item 12, have landed. The remaining
near-mandatory work before trusting this with adversarial untrusted code on a
shared host is items 1–2 (cgroup / `setrlimit`) and item 5 (mount-flag
enforcement).

## 6. Resource-limit configuration

### 6.1 `pa-jail.conf` matching and `.ini`-style sections

*(Status: sections, `pathmatch`, and cascade removal are **implemented** in
`pa-jailconf.cc`; `rlimit` directives in 6.3–6.6 remain a plan.)*

`pa-jail.conf` is loaded root-owned, non-writable, `O_NOFOLLOW`, <=8 KB, and
parsed line by line. Each `enablejail`/`disablejail` (and the `skeleton`
variants) either carries its own directory pattern or is argless. A query
rescans the whole file; the allow/deny decision is last-match-wins (an argless
top-level disable is a global veto), while `permdir` is shortest-match (§6.2).
Patterns are matched with `pathmatch` (see `pa-jutil`), which
supports `*`/`?`/`[...]` within a component and `**` for whole-subtree matching.

**Matching no longer cascades.** A pattern matches the queried directory
*exactly* (component counts must line up; `*` does not cross `/`). To act on a
whole subtree, say so explicitly with `**`: `disablejail /foo/**` disables
`/foo` and everything beneath it, whereas `disablejail /foo` disables only
`/foo` itself. (`pathmatch` queries are slash-terminated, and `/foo/**` matches
`/foo/`, so a `D/**` rule covers `D` as well as its descendants.)

To attach per-directory settings without repeating the pattern, add
`.ini`-style section headers:

```
[JAILPAT]
```

A header opens a section **gated on the jail directory** `JAILPAT` (matched with
`pathmatch` like any other pattern): every directive inside it acts only when the
queried jaildir matches `JAILPAT`. Inside a section, directives may be given with
**no pattern argument**, and each then acts on its own natural axis:

- `enablejail` / `disablejail` (and `rlimit ...`, planned) target the **jaildir**:
  argless, they apply to `JAILPAT` itself.
- `enableskeleton` / `disableskeleton` target a **skeleton directory**, which is
  a *different thing* from the jaildir. So an argless `enableskeleton` is **not**
  `enableskeleton JAILPAT`: a bare `enableskeleton` enables no skeleton at all.
  To allow a skeleton for this jaildir, name it — `enableskeleton /skel/x` inside
  `[/foo]` means "jaildir `/foo` may use skeleton `/skel/x`". This is what makes
  **per-jaildir skeletons** possible (different jails, different skeletons); a
  bare-`enableskeleton`-means-`JAILPAT` rule would foreclose it.

A header alone enables nothing; it only scopes the directives inside it.

Lines before the first header form the **global** (top-level) scope; `[]`,
`[/**]`, `[**]`, and `[/**/]` reset back to it. For the jaildir axis the model
is *argless directive = current scope* (the enclosing `JAILPAT`, or global at top
level), which generalizes the original "bare argless `enablejail` means global".
The skeleton axis is the exception above.

```
# global scope: defaults for every jail
rlimit nproc=64,nofile=256,fsize=256m,core=0,cpu=120

[/jails/run/*]
enablejail
rlimit nproc=128

[/jails/build/*]
enablejail
rlimit nproc=512,as=4g,cpu=600
```

### 6.2 Sections gate on the jail directory

A section header gates its directives on the jaildir: `[JAILPAT]` sets a
`skip_section` flag (`skip_section = !pathmatch(JAILPAT, dir)`) and every
directive inside is ignored unless the queried jaildir matches `JAILPAT`. On the
**jaildir axis** that makes an argless directive behave as if it carried
`JAILPAT` as its argument:

```
[/jails/build/*]              enablejail /jails/build/*
enablejail            ==
```

The **skeleton axis is not sugar this way** — its argument is a skeleton dir, so
a bare `enableskeleton` in `[JAILPAT]` is not `enableskeleton JAILPAT` (see
§6.1). It is gated by the section's jaildir but keyed on the skeleton dir, which
is what enables per-jaildir skeletons.

Rules as implemented (in `pajailconf::get`):

1. **Argless `enablejail`/`disablejail` apply to the current scope** -- the
   enclosing `[JAILPAT]`, or global at top level. (Argless skeleton directives
   act on the global skeleton axis, never a local one — see §6.1.)
2. **An explicit-pattern directive inside a section is gated by both:** it acts
   only when the queried dir is within `JAILPAT` *and* matches the directive's
   own pattern. (Not an error; simply scoped, which is occasionally useful and
   never surprising.)
3. **Backward compatible:** a file with no `[...]` header behaves as before;
   arg-bearing forms (`enablejail /pat`) work at top level unchanged.
4. **Precedence on overlap:** global scope first, then sections in file order,
   last-match-wins on the allow/deny decision; an argless top-level disable is a
   global veto. There is no implicit subtree cascade -- breadth comes only from
   `**` in a pattern.

The **`permdir`** (the create boundary returned in `jailperm::permdir`) is the
**shortest** literal prefix among the matching `enablejail` globs — *not*
last-match-wins, and deliberately not longest. Components above `permdir` must
pre-exist root-owned (§4); pa-jail may create those at/below it. Shortest is
order-independent and honors the broadest grant: if any matching rule authorizes
a whole subtree, pa-jail creates within it, so adding a narrower overlapping rule
(e.g. a section that only scopes rlimits) never silently shrinks the create zone.
We chose shortest over longest because longest's extra ownership-checking depth
was both marginal (the root-owned config already vouches for the prefix, and the
walk uses `O_NOFOLLOW` plus a final-target root-owned check) and *inconsistent* —
it only kicked in when an incidental more-specific rule happened to overlap. If
we want to defend a writable intermediate dir below `permdir`, the right lever is
to check pre-existing intermediates in the walk directly (§4), not to infer it
from glob specificity. An argless `enablejail` in `[JAILPAT]` contributes
`JAILPAT`'s literal prefix to this computation.

Removed along the way: the implicit "deny is greedy" subtree cascade (replaced by
explicit `**`).

### 6.3 `rlimit` value grammar

```
rlimit <name>=<value>[,<name>=<value>...]
```

- `<value>` is a non-negative integer with an optional unit, or `unlimited` /
  `inf`.
- Units: `k`/`m`/`g` (1024-based) for byte limits; `s`/`m`/`h` for `cpu`; plain
  integer for counts.
- A `!` suffix (`nproc=512!`) pins the value as authoritative: the command line
  cannot loosen or override it (see 6.4).

Recognized names (map to `RLIMIT_*`):

| name      | RLIMIT_        | unit family |
|-----------|----------------|-------------|
| `cpu`     | RLIMIT_CPU     | seconds     |
| `as`      | RLIMIT_AS      | bytes       |
| `data`    | RLIMIT_DATA    | bytes       |
| `stack`   | RLIMIT_STACK   | bytes       |
| `fsize`   | RLIMIT_FSIZE   | bytes       |
| `nofile`  | RLIMIT_NOFILE  | count       |
| `nproc`   | RLIMIT_NPROC   | count       |
| `core`    | RLIMIT_CORE    | bytes       |
| `memlock` | RLIMIT_MEMLOCK | bytes       |

Keeping limits in `pa-jail.conf` puts them in the existing root-owned trust
anchor with directory matching and ownership checks, so the (less trusted)
caller can only tighten them, never loosen them.

### 6.4 Command-line override

```
--rlimit <name>=<value>[,<name>=<value>...]
```

Repeatable; later wins. The effective limit per name resolves as
global-default -> matching-section -> `--rlimit`, **but the command line may only
make a limit more restrictive, never looser**: when a conf value exists, the
applied value is `min(conf, cmdline)` (treating `unlimited` as +inf). A
`!`-pinned conf value ignores the command line entirely. If neither conf nor
command line sets a name, that rlimit is left at its inherited value, so the
feature is opt-in.

### 6.5 Implementation (now in `pa-jailconf`)

The config parser has been extracted to `jail/pa-jailconf.{cc,hh}` so it can be
unit-tested independently of pa-jail (`make check` runs `test-pa-jailconf`).
Note the scope of that suite: `test-pa-jailconf` exercises only the pure,
unprivileged helpers (the config parser, `pathmatch`, `path_absolute`,
`path_pa_validate`, and `shell_quote`). **There are no tests for the privileged
jail runtime** —
the path walk / ownership checks, `clone`/namespace setup, mount + `pivot_root`,
the privilege drop, or teardown. `make check-docker` does **not** exercise that
path either; it merely builds the code and runs the same `test-pa-jailconf`
suite inside a Linux container. Tests for the privileged functionality remain to
be written.

Implemented so far:

- `pajailconf::get(dir, skeletondir)` makes a single forward pass over the file,
  tracking the current section pattern and a `skip_section` flag; `[JAILPAT]`
  sets them (`[]`/`[/**]` reset to global). On the jaildir axis an argless
  directive in a section uses the section pattern; an explicit pattern is gated
  by `skip_section` *and* its own match. Skeleton directives are keyed on the
  skeleton dir, not the jaildir (§6.1).
- Matching is `pathmatch` directly (the section pattern is slash-terminated to
  match the slash-terminated query, like explicit patterns); there is **no**
  subtree cascade — neither enable nor disable spreads to subdirs, so breadth
  comes only from `**`. `<fnmatch.h>` is gone. (`--chown-user` no longer queries
  the config; it validates its argument with `path_pa_validate` and requires it
  to stay within the jail directory.)
- A jail query returns `jailperm{allowed, skeletondir, permdir,
  disabled_lineno}`; `permdir` is the create boundary (the shortest matching
  `enablejail` literal prefix — §6.2), below which pa-jail may create the jail.
  On a denial, `disabled_lineno` is the 1-based config line of the responsible
  `disablejail` (0 if the jail was simply never enabled); it tracks the jaildir
  axis only, and `disable_message()` renders it for the error.

Still a plan (rlimit work):

- `parse_rlimits(dir)` returns the resolved limits for `dir`
  (`std::array<std::optional<rlim_t>, RLIM_NLIMITS>` plus a "pinned" bitset),
  applying the global-then-section overlay.
- A shared `parse_rlimit_value(name, str)` handles units / `unlimited`; parse
  errors `die()` (fail-safe, never silently "unlimited").
- `setrlimit()` is applied in the child just before the permanent privilege drop
  (so `RLIMIT_NPROC` is in force for the student's `fork`s); logged under
  `verbose`.

### 6.6 Interaction with cgroups

`setrlimit` is per-process and weak for memory (each child may map up to
`RLIMIT_AS`); cgroup `memory.max`/`pids.max` are the real enforcement. Treat
rlimits as the cheap defense-in-depth first step; the same section syntax can
later carry cgroup knobs (`cgmemory`, `cgpids`, `cgcpu`) through the identical
parser and precedence.

## 7. `shell_quote` note

`shell_quote` (now in `pa-jutil.cc`) is **functionally correct** and now carries
explanatory comments, but is still written in a way that is hard to read by
inspection (one variable serving as the needs-quoting flag, the opening-quote
buffer, and the escape accumulator; the trailing `substr` is what actually emits
ordinary characters). Rather than replace it with the simpler always-quote form
below, the resolution was to **keep the optimized form and test it** in
`test-pa-jailconf` (`make check`), since it sits on the `bash -lc`
command-construction path. `test_shell_quote()` pins the exact output for the
verbatim, single-quoted, and `'\''`-escape cases; `fuzz_shell_quote()` then
checks a round-trip property over ~200k random strings against two independent
oracles — a hand-written single-quote decoder, and a real `/bin/sh` that must
parse the result as exactly one word equal to the input (capped low by default,
scaled by `PA_SHELL_FUZZ=N`). The fuzzer surfaced one real defect: an empty
argument used to quote to `""` (which vanishes from a command line rather than
surviving as an empty word); it now quotes to `''`.

The simpler always-single-quote form, kept here for reference, was the
considered alternative:

```cpp
static std::string shell_quote(const std::string& s) {
    std::string out = "'";
    for (char c : s) {
        out += (c == '\'') ? "'\\''" : std::string(1, c);
    }
    out += "'";
    return out;
}
```
