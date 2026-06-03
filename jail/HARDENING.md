# pa-jail hardening design

Status: living document — original audit + plan, with completed items marked.
Parts of Phase 1 have landed (supplementary-group drop, `no_new_privs`, the
`snprintf` fix). The config parser is extracted and unit-tested; it parses the
resource-limit (`limit`/cgroup) directives, the `memory.*` names, and the pool
config (`cgroupbase`, `[cgroup PATH]`, `pool_limits`) — §6.3, §6.5. At run time
pa-jail builds the cgroup hierarchy from config: each jail's leaf lives under the
pool named by its `cgroupbase` (default `/sys/fs/cgroup/pa-jail`, `$SELF`-relative
forms resolved against `/proc/self/cgroup`), the pool carries the aggregate
`pool_limits`, and `pids.max`/`cpu.max`/`memory.max`/`memory.high` are enforced on
both leaf and pool. **Picking back up:** the remaining "Next" items are the
per-process `rlimit.*` limits and the `--limit` command-line override — see the
end of §6.5. Updated 2026-06.
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
   `/dev/pts` (`newinstance`), `/tmp`, `/run`, and links `/dev/ptmx` →
   `pts/ptmx` (so the always-allocated pty works without a manifest entry),
   then `pivot_root`s into the jail and detaches the old root
   (`umount2(..., MNT_DETACH)`).
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

1. **Resource limits — partially addressed.** Per-jail cgroup `pids.max` (fork
   bombs) and `cpu.max` (CPU rate) are now enforced from config (§6, §6.5). Still
   missing: a **memory cap** (`memory.max`/`RLIMIT_AS`) — so a memory balloon is
   still bounded only by host-global defaults — and the per-process `rlimit.*`
   belt-and-suspenders (`RLIMIT_FSIZE`, `RLIMIT_NOFILE`, `RLIMIT_CORE`, …).
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

1. ✅ **Done (`pids.max`, `cpu.max`) — cgroup v2 limits.** A per-run leaf cgroup
   is created and the namespace's init is placed in it before student code runs;
   `pids.max`/`cpu.max` come from config (§6). Kills fork bombs deterministically
   — verified end-to-end (`pids.max=15` caps a fork loop at 13). `memory.max`
   (+ `memory.swap.max`) is the next controller to add (one table row + one
   writer); see §6.5 for the runtime design and deployment requirements.
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

Phase 1 items 3, 4, and 6, Phase 3 item 12, and the cgroup half of item 1
(`pids.max`/`cpu.max`) have landed. The remaining near-mandatory work before
trusting this with adversarial untrusted code on a shared host is the rest of
items 1–2 (`memory.max`, then the `setrlimit` belt-and-suspenders) and item 5
(mount-flag enforcement).

## 6. Resource-limit configuration

### 6.1 `pa-jail.conf` matching and `.ini`-style sections

*(Status: sections, `pathmatch`, and cascade removal are **implemented** in
`pa-jailconf.cc`; `limit` directive **parsing** (§6.3) is implemented, returning
resolved limits in `jailperm::limits`; and the per-jail cgroup limits are
**applied** at run time (§6.5). `setrlimit` for the `rlimit.*` limits and the
`--limit` override remain a plan.)*

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

- `enablejail` / `disablejail` (and `limit ...`) target the **jaildir**:
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
# global scope: defaults for every jail (per-jail pids/cpu via cgroup,
# per-process belt-and-suspenders via rlimit.*)
limit pids.max=64,cpu.max=1,rlimit.nofile=256,rlimit.fsize=256m,rlimit.core=0

[/jails/run/*]
enablejail
limit pids.max=128

[/jails/build/*]
enablejail
limit pids.max=512,cpu.max=4,rlimit.as=4g

# equivalently, without sections, via the two-argument form:
limit /jails/build/* pids.max=512,cpu.max=4,rlimit.as=4g
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

### 6.3 `limit` directive: forms, scope, and value grammar

*(Status: the parser — both directive forms, the value grammar, and the two
starting limits — is **implemented** in `pa-jailconf.cc` and unit-tested in
`test-pa-jailconf` (`test_pajailconf_limit`); the two cgroup limits are also
**applied** at run time (cgroup setup in `pa-jail.cc`, §6.5). `setrlimit` for the
`rlimit.*` limits remains a plan.)*

Two directive forms:

```
limit <name>=<value>[,<name>=<value>...]            # current scope
limit <JDIR> <name>=<value>[,<name>=<value>...]     # only jails matching JDIR
```

The one-argument form applies to the **current scope**: at top level it is a
global default for every jail; inside a `[JAILPAT]` section it applies to that
section's jaildir (like an argless `enablejail`). The two-argument form applies
only on jails whose directory matches `JDIR` — a `pathmatch` glob resolved
exactly like an `enablejail` pattern (absolute, or section-relative inside a
section), and, inside a section, gated additionally by the section pattern.
Resolution is a single pass with **per-name last-write-wins overlay**: a global
default is overridden by a later matching section or two-arg directive.

**Value grammar.** `<value>` is a non-negative number with an optional unit, or
`unlimited` / `inf` / `max` for the infinite form. Units depend on the limit:
`k`/`m`/`g` (1024-based) for byte limits; `s`/`m`/`h` for cumulative-time limits;
plain integer for counts; for a CPU *rate* (`cpu.max`), a decimal in cores
(`1.5`) or a percentage of one core (`150%`), stored internally as millicores
(1000 = one core). A `!` suffix (`pids.max=512!`) pins the value: the command
line cannot loosen or override it (see 6.4). A malformed value, or an unknown
limit name, is fatal (`die`) — a limit never silently degrades to "unlimited".

**Naming convention — real kernel names, namespaced by mechanism.** One name
binds exactly one mechanism (no fan-out), and the name is the *real* kernel name,
so the mechanism — and thus the scope — is legible without a translation table:

- a **cgroup-v2** limit is named by its interface filename (`pids.max`,
  `cpu.max`, `memory.max`) and is enforced **per-jail** on this jail's process
  tree — the default, and the safe one;
- an **rlimit** is named `rlimit.<name>` (`rlimit.cpu`, `rlimit.nofile`) and is
  **per-process**.

The one scope subtlety: `rlimit.nproc` (`RLIMIT_NPROC`) is **per-uid /
system-wide**, so it can leak across concurrent jails sharing a uid. That is why
the per-jail process cap is the distinctly-named cgroup `pids.max`, never an
unqualified `nproc`; an operator who wants the per-uid bound must name
`rlimit.nproc` explicitly.

Recognized names (the starting set is the two per-jail cgroup limits; the others
are the planned near neighbors, added one table row at a time):

| name           | scope        | mechanism / file  | unit              | status |
|----------------|--------------|-------------------|-------------------|--------|
| `pids.max`     | per-jail     | cgroup `pids.max` | count             | ✅ implemented |
| `cpu.max`      | per-jail     | cgroup `cpu.max`  | rate (millicores) | ✅ implemented |
| `memory.max`   | per-jail     | cgroup `memory.max` | bytes           | planned |
| `rlimit.cpu`   | per-process  | `RLIMIT_CPU`      | seconds           | planned |
| `rlimit.as`    | per-process  | `RLIMIT_AS`       | bytes             | planned |
| `rlimit.fsize` | per-process  | `RLIMIT_FSIZE`    | bytes             | planned |
| `rlimit.nofile`| per-process  | `RLIMIT_NOFILE`   | count             | planned |
| `rlimit.core`  | per-process  | `RLIMIT_CORE`     | bytes             | planned |
| `rlimit.nproc` | per-uid      | `RLIMIT_NPROC`    | count             | planned |

Two name-vs-semantics notes. (1) `cpu.max` is a per-jail *rate*, not a
cumulative-seconds cap — cgroups have no "kill after N CPU-seconds" knob; the
per-process cumulative cap is the separately-named `rlimit.cpu`. Its value is our
cores/percent grammar (→ millicores), **not** the kernel file's raw
`"$QUOTA $PERIOD"` pair, which pa-jail computes at apply time. (2) `pids.max` is
a clean passthrough — the conf integer is exactly what is written to the file.

Keeping limits in `pa-jail.conf` puts them in the existing root-owned trust
anchor with directory matching and ownership checks, so the (less trusted)
caller can only tighten them, never loosen them.

### 6.4 Command-line override

```
--limit <name>=<value>[,<name>=<value>...]
```

Repeatable; later wins. The effective limit per name resolves as
global-default -> matching-section -> `--limit`, **but the command line may only
make a limit more restrictive, never looser**: when a conf value exists, the
applied value is `min(conf, cmdline)` (treating `unlimited` as +inf). A
`!`-pinned conf value ignores the command line entirely. If neither conf nor
command line sets a name, that limit is left at its inherited value, so the
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

Implemented since (`limit`/cgroup parsing):

- `pajailconf::get` now resolves limits in the same forward pass and returns
  them in `jailperm::limits` (a `jaillimits`: one `jaillimit{set, unlimited,
  pinned, value}` per `jaillimit_id`). The global-then-section overlay is
  per-name last-write-wins; the two directive forms and section gating are §6.3.
- `parse_limit_value` handles units / `unlimited` / the `!` pin; parse errors
  and unknown names `die()` (fail-safe, never silently "unlimited"). Units coded:
  `count` (`pids.max`), `rate` (`cpu.max`, millicores), `bytes`
  (`memory.max`/`memory.high`, 1024-based `k`/`m`/`g`). A `seconds` unit lands
  with the `rlimit.*` limits that need it.
- `limit_descs[]` is the name→(unit) table; a new limit is one enum value plus
  one row. Each row will gain its mechanism binding (cgroup file / `RLIMIT_*`)
  when application is wired.

Implemented since (cgroup application — `pa-jail.cc`):

- **All cgroup work runs in the host-ns root parent**, before/around `clone`, so
  it uses ordinary `/sys/fs/cgroup` paths and full root credentials and never
  reaches into the pivoted jail (the supervisor and `exec_go` are untouched).
  Each jail's leaf lives under the **pool named by its `cgroupbase`** (default
  `/sys/fs/cgroup/pa-jail`); the pool is created once, left in place, and carries
  the aggregate `pool_limits` shared by its jails, with each run getting its own
  leaf `<pool>/<pid>`. `cgroup_setup` delegates the needed controllers down the
  chain — into the pool parent's `subtree_control` (a no-op on systemd hosts,
  which already delegate `cpu`/`pids` at the root) and then into the pool's — and
  writes `pids.max`/`cpu.max`/`memory.max`/`memory.high` (a table-driven
  `cgroup_write_limits`; `cpu.max` as a `quota period` pair from the millicore
  rate) to both the pool and the leaf. cgroup v2 composes them as `min(leaf,
  pool, …)`.
- **Race-free placement via `clone3` + `CLONE_INTO_CGROUP`** (Linux 5.7+). The
  cloned child is born *inside* the leaf, so its limits apply from the first
  instruction and student code (forked much later) can never run unconfined —
  no separate `cgroup.procs` write, no synchronization. (An earlier cut placed
  the child after `clone` and held it on a pipe barrier until placed; the
  "benign race" assumption there was wrong in practice — the supervisor could
  fork the student before the parent's write landed — so atomic birth-placement
  is the right fix.) Only the cgroup path uses `clone3`; runs with no limits
  keep the plain `clone()`. The supervisor counts against `pids.max` (a few
  procs of overhead — set the limit accordingly).
- **Compile-time gating, runtime fail-closed.** The cgroup machinery is built
  only when the headers provide `clone3`/`CLONE_INTO_CGROUP` (`PA_HAVE_CGROUP`);
  on older headers it compiles out, so pa-jail still **builds and runs normally
  for non-cgroup use**. A config that *sets* a cgroup limit on such a build
  `die()`s with a clear "rebuild on a newer host or remove the limits" message
  rather than silently running unconfined — the check is at run time, keyed on
  whether a limit was actually requested, not a hard build error.
- **Cleanup is lazy, not a lingering process.** Removing a leaf needs root
  (you can only `rmdir` a child of the root-owned pool as root), and the
  supervisor can't help — by the time it ends it has pivoted away from
  `/sys/fs/cgroup` and dropped to the caller (so it handles untrusted student
  I/O without root). Rather than keep a privileged reaper alive for the whole
  run, **each setup reclaims the empty leaves of previous finished runs**
  (`cgroup_reclaim_stale`: scan the pool and `rmdir` every `<N>` leaf whose
  owning pa-jail process `N` is gone). A running jail is skipped two ways — its owner is alive
  and its leaf is populated (`rmdir` would fail) — so concurrent runs are
  undisturbed. Cost: a finished run's empty leaf lingers until the next run
  (steady-state ≈ concurrency; empty cgroups are essentially free). This keeps
  cleanup in root but only in the transient setup parent that is inherently
  root, with no new privileged process and no weakening of the "caller can only
  tighten" guarantee (a caller-`rmdir` would require a caller-writable cgroup
  ancestor, which would let the caller migrate the student out of its limits).
- **Fail-safe:** any cgroup write error `die()`s (a configured limit never
  silently fails to apply); `--verbose` logs every `mkdir`/`echo`.

Implemented since (pools — config parser only, `pa-jailconf`; **runtime not yet
wired**, see "Next" below):

- **`memory.max` / `memory.high`** limit names (bytes), so jails and pools can
  cap memory. New `jaillimit_id`s + `limit_descs[]` rows + the `bytes` unit.
- **`cgroupbase PATH`** directive → `jailperm::cgroupbase`: the pool a jail's leaf
  is created under. Valid as a global default and inside `[JAILPAT]` sections
  (section overrides global, last-match-wins); defaults to `default_cgroupbase`
  (`/sys/fs/cgroup/pa-jail`, the runtime's default pool). `$SELF`
  and `$SELF/sub` are stored **verbatim** (expanded only at apply time).
- **Typed `[cgroup PATH]` sections** define a pool's own limits; a bare
  **`[cgroup]`** (no path) defines limits applied to *every* pool. The header
  parser classifies the first bracket word: `cgroup` → a pool section, which the
  jaildir query *skips* (a pool's limits never leak into a jail's `limits`);
  anything else is a `[JAILPAT]` as before. A multi-word header that is neither a
  valid `[cgroup]`/`[cgroup PATH]` nor a single `[JAILPAT]` is an error.
- **`pajailconf::pool_limits(PATH)`** returns a pool's resolved limits — the union
  of `limit` directives in bare `[cgroup]` sections (every pool) and `[cgroup P]`
  sections whose `P` *literally* equals `PATH` (the same string `cgroupbase`
  carries, `$SELF` included), overlaid in file order (last write wins per name).
  Pool `limit`s take only the no-JDIR form.
- Tested in `test_pajailconf_cgroup` / `test_pajailconf_limit`. Deferred:
  rejecting `rlimit.*` inside `[cgroup …]` (no such names exist yet; a comment in
  `pool_limits` marks the spot).

**Deployment requirement (cgroup limits only).** To *use* cgroup limits you need
Linux 5.7+ at build time (headers with `clone3`/`CLONE_INTO_CGROUP`) and at run
time, a cgroup v2 unified hierarchy, and `<base>` a **delegated** cgroup: one
that pa-jail is allowed to create child cgroups under *and* that has the
`cpu`/`pids` controllers enabled for its children (in the base's
`cgroup.subtree_control`), so the per-run leaves can carry `pids.max`/`cpu.max`.
None of this constrains a build or run that uses no cgroup limits; and any
shortfall (old build, old kernel, `clone3` error) fails closed, never silently
unconfined.

- Running as root on a normal systemd host, `/sys/fs/cgroup` (the real root)
  already qualifies: root may create leaves there and migrate any process in,
  and systemd has enabled the controllers — so the default base just works.
- A process **confined** to its own cgroup subtree (e.g. inside Docker, where it
  lives in `/sys/fs/cgroup/docker/<id>/`) cannot create a leaf at the real root
  or migrate a process out of its subtree, so the default base silently no-ops
  (the jail stays uncapped). It needs a base prepared *inside* its own subtree:
  enable the controllers there — first moving the existing processes into a
  child, since cgroup v2's "no internal processes" rule forbids enabling
  controllers on a cgroup that holds processes. systemd's `Delegate=yes`
  (optionally `DelegateControllers=cpu pids`) sets this up for a service.

The pool is currently the compile-time `/sys/fs/cgroup/pa-jail`. The parser
already accepts `cgroupbase` (incl. `$SELF`) to point it at whatever delegated
subtree a deployment provides; wiring that into the runtime is "Next" item 1
below.

### Next — pick up here (in priority order)

Pools are now **wired into the runtime** and `memory.max`/`memory.high` are
applied; the config surface looks like:

```
cgroupbase /sys/fs/cgroup/grading          # default pool for all jails ($SELF[/sub] ok)

[cgroup /sys/fs/cgroup/grading]            # that pool's own (aggregate) limits
limit pids.max=4000,memory.high=48g,memory.max=56g,cpu.max=16

[/jails/build/*]
enablejail
cgroupbase /sys/fs/cgroup/grading-build    # route these jails to a different pool
limit pids.max=512,memory.max=4g           # each jail's own (per-leaf) limits
```

Done (`cgroup_setup`, `pa-jail.cc`):

- **Pools wired in.** `cgroup_setup(conf, perm)` takes the identity jail's `perm`
  (plumbed via `jaildirinfo::conf_` + `jailownerinfo::permjail_`); the pool is
  `cgroup_resolve_pool(perm.cgroupbase)` (a leading `$SELF` → our own cgroup from
  `/proc/self/cgroup`'s `0::/<path>` line, validated under the v2 mount with no
  `..`), and the leaf is `<pool>/<pid>`. The pool's parent delegates the needed
  controllers down to it, the pool down to its leaves; `cgroup_reclaim_stale`
  generalized unchanged.
- **All four cgroup limits applied** on both leaf and pool via a table-driven
  `cgroup_write_limits` (`cgroup_limit_infos[]` maps id → controller + file), and
  `memory` joins the delegated set when any `memory.*` limit is set. `pool_limits`
  is written onto the pool, so per-jail and pool caps compose as
  `min(leaf, pool, …)`. Verified by `--dry-run --verbose` (exact command sequence
  for default / literal / `$SELF` bases + path validation) and a live
  `--privileged` container (pool `pids.max=200` + leaf `pids.max=15` both created
  and applied). Still optional: `memory.swap.max`.

Remaining:

1. **`rlimit.*` limits** — add the names (`rlimit.cpu`, `rlimit.nofile`, …) with
   their `RLIMIT_*` binding + a `seconds` unit, applied via `setrlimit()` in the
   child just before the permanent privilege drop. Then **reject `rlimit.*` inside
   `[cgroup …]`** — pools are cgroup-only (the spot is commented in `pool_limits`).
2. **Command-line `--limit` override** (§6.4) — `min(conf, cmdline)` per name,
   `!`-pinned conf values ignore the command line.

**Testing note for a fresh agent.** `make check` exercises only the parser
(`test-pa-jailconf`); the privileged runtime has **no automated tests**. Verify
runtime changes two ways: (a) `pa-jail run --dry-run --verbose …` prints the exact
`mkdir`/`echo`/`rmdir` cgroup command sequence without touching the system; (b) for
real enforcement, a `docker run --privileged gcc:14` container with cgroup v2 —
free the cgroup root of processes (move them into a child cgroup) and enable the
controllers in its `cgroup.subtree_control`, then register a tiny **static**
fork-counter binary as the jail user's shell (in `/etc/shells`) and copy it into
the jail with `-F /path/to/forksh`. A `pids.max=15` run then prints `FORKCAP at 13
children` (15 − supervisor − shell). Because pa-jail now links `/dev/ptmx` on every
run (§1), the jail needs **no** `/dev/*` manifest entries for the pty to work — the
fork binary is the only file the manifest must copy. (`--cgroupns=host` is only
needed to exercise the *host's* real cgroup root rather than the container's own.)

### 6.6 Interaction with cgroups

`setrlimit` is per-process and weak for memory (each child may map up to
`RLIMIT_AS`) and useless against fork bombs as an aggregate cap; cgroup
`memory.max`/`pids.max` are the real, per-jail enforcement. Rather than a
parallel `cgX` vocabulary, cgroups and rlimits share the **one** `limit`
directive and parser: the *name* is the real kernel name and selects the
mechanism (§6.3), with the per-jail cgroup limits as the safe default and
`rlimit.*` limits as defense-in-depth where a per-process bound is wanted. So
`pids.max` is the primary fork-bomb defense and `rlimit.*` limits are the cheap
belt-and-suspenders, all through the identical precedence.

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
