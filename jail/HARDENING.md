# pa-jail hardening

`pa-jail` is a **setuid-root + setgid** helper (`GNUmakefile` installs it `chown
root:0`, `chmod u+s,g+s`) that the grading user invokes to run untrusted student
code. Run path:

`main()` → `jail_main()` → `jailownerinfo::exec()` → `clone()` →
`exec_clone_function` → `exec_go()` → final privilege drop → `execve()` of the
student command, with a supervisor process (PID 1 of the new PID namespace)
enforcing wall-clock + idle timeouts via `poll()` (on Linux it tears the jail
down by exiting, killing the PID namespace).

Living document: the security properties in place (§2), what is still missing
(§3), and the resource-limit configuration system (§4). Scope: `jail/pa-jail.cc`,
`jail/pa-jailconf.{cc,hh}`, `/etc/pa-jail.conf`, and the invocation path in
`src/queueitem.php` + `src/psetconfig.php`.

## 1. Threat model

- **Staff-controlled** (set in `psetconfig.php`; students cannot touch): the
  command, jail flags, jaildir, username, skeleton/bind dirs, jailfiles manifest.
- **Student-controlled**: the code that runs inside the jail (a git checkout into
  the jail home) and a sanitized env allowlist (`PATH`/`LD_LIBRARY_PATH`/`HOME`/
  `SHELL`/`MAKE`/… are denylisted in `queueitem.php`).
- The PHP→jail boundary is `proc_open` with an argv array (no shell). The student
  command runs under `bash -lc` *inside* the jail as the unprivileged jail user.

## 2. Security properties enforced today

**Privilege model.** setuid-root → drop euid/egid to the caller to open
caller-owned side files, then re-escalate real+eff+saved to root →
`clone(CLONE_NEWIPC|NEWNS|NEWPID)`. In the child, as root: mounts + `pivot_root`,
then `setgroups(0)` (drop root's supplementary groups while still euid 0) and a
first **gid-before-uid** drop to the jail user that keeps saved-uid root for
pty/tty setup. Then `no_new_privs` → `setrlimit`s → the **final permanent**
gid-before-uid drop (`setresgid`/`setresuid` with real=eff=saved, so root cannot
be regained) → `setsid` → reset all signal handlers → optional `--userns` →
`execve`. The permanent drop and `no_new_privs` both precede `execve`; the
supervisor itself drops to the caller, so no root lingers for student I/O. No
shell appears in any privileged path — all exec via argv vectors.

**Filesystem isolation.** `pivot_root` + `umount2(MNT_DETACH)` (not a bare
`chroot`; the old root is unreachable); `MS_REC|MS_SLAVE` on `/` blocks mount
propagation back to the host; a symlink-safe, single-filesystem recursive delete
(`remove_recursive`). The jail's own mounts get hardening flags forced on
regardless of host config (`jail_mount_hardening`): `/proc` → `nosuid,nodev,-
noexec`; `/tmp`, `/run` → `nosuid,nodev`; `/dev/pts` → `nosuid,noexec` (no
`nodev` — pty slaves are devices). `noexec` is deliberately *not* on `/tmp`
(student build output is legitimately executed; since the student runs their own
code anyway it adds no boundary). pa-jail links `/dev/ptmx` → `pts/ptmx` on every
run, so the always-allocated pty needs no manifest entry.

**Trust anchors.** The setuid path-walk is component-by-component
`openat(O_PATH|O_NOFOLLOW)`, each ancestor required root-owned and not
group/other-writable; the final jail-root target must itself be root-owned and
non-writable even when it pre-exists at/below `permdir` (§4.2), so a
loosely-permissioned pre-existing root cannot be used to stage a setuid binary or
swap the tree. `/etc/pa-jail.conf` is loaded root-owned, non-writable,
`O_NOFOLLOW`, ≤8 KB.

**mknod device allowlist.** `do_copy` refuses to create any device node from the
manifest whose major:minor is not on a fixed **char**-device allowlist — `null`
(1:3), `zero` (1:5), `full` (1:7), `random` (1:8), `urandom` (1:9), `tty` (5:0),
`ptmx` (5:2); no block device, ever. The check is by major:minor (the real
kernel-driver authority, not the manifest path) and runs before the
pre-existing-match fast path, so a disallowed node is refused even if it already
sits in the jail. `mknod` wields the setuid binary's root authority and a device
node is a live channel to its driver, so an arbitrary manifest-named node
(`/dev/mem`, a raw disk) would hand jailed code a kernel-level capability.

**FD / env hygiene.** `close_unwanted_fds()` + `O_CLOEXEC`; the child gets a pty,
never the listening socket or the pty master. An env allowlist is passed instead
of `environ`.

**Resource limits.** One `limit NAME=VALUE,…` grammar drives cgroup v2
(per-jail), rlimits (per-process), and the `/tmp` tmpfs cap; built-in soft
defaults make a host safe out of the box with no config. See §4.

**Opt-in `--userns`.** Just before `execve`, the student (already dropped to its
non-root uid) `unshare`s a user namespace and writes an **identity** uid/gid map,
so it keeps its own non-root uid; jail-root (uid 0) is left unmapped, resolving to
the host overflow uid (`nobody`), so an in-jail escalation to uid 0 gains nothing
on the host. The namespace creator gets a full capability set, so `cap_drop_all`
then clears every capability (effective/permitted/inheritable via `capset`,
ambient, and the bounding set). `PR_SET_DUMPABLE(1)` is needed so the uid-changed
process can write its own `uid_map`. Fail-closed: a kernel without unprivileged
userns makes `--userns` die rather than silently run without it. The setup/mounts
still run as real root — making them rootless is the deferred "rootless setup"
refactor (§3).

## 3. Still missing (ranked) and known bugs

Before this can be trusted with adversarial untrusted code on a shared host:

1. **No network isolation** — no `CLONE_NEWNET`; jailed code shares the host
   network namespace (full outbound, host-reachable inbound). Plan: default
   loopback-only for autograding (bring `lo` up, no veth), with an opt-in
   `run_network` flag + veth/NAT for psets that need it.
2. **No seccomp filter** — the full host syscall surface (`keyctl`, `bpf`,
   `userfaultfd`, `io_uring`, `ptrace`, `unshare`, …) is reachable. Plan:
   seccomp-bpf installed after `no_new_privs`, before `execve`, fail-closed;
   denylist the dangerous/unnecessary calls first, tighten toward an allowlist
   once real workloads are profiled.
3. **Capability drop is `--userns`-only** — `cap_drop_all` should also run
   unconditionally around the default-path uid drop, as defense-in-depth.
4. **No read-only mounts** where compatible (the mount-flag hardening covers
   `nosuid,nodev,noexec` but not `ro`).
5. **`--userns` is opt-in** — make it default-on once verified on the grading
   hosts, then tackle the larger "rootless setup" refactor (run setup itself
   unprivileged, not as real root).
6. **Minor:** `CLONE_NEWUTS`; consider `hidepid=2` on the proc mount.

Known bugs:

- **macOS timeout path only kills the direct child** (`kill(child, SIGKILL)`, no
  `killpg`); a double-forked grandchild survives. Linux is saved by PID-ns
  teardown. Non-production path, but wrong.
- **Source-side population is not symlink/TOCTOU-hardened** (`lstat`→`/bin/cp`
  check-then-use; intermediate symlinks are followed). Bounded by manifest trust,
  so low priority.

## 4. Resource-limit configuration

Limits live in the root-owned `pa-jail.conf` so they ride the existing trust
anchor (directory matching + ownership checks); the less-trusted caller can only
*tighten* them (§4.4), never loosen them. The parser is extracted to
`pa-jailconf.{cc,hh}` and unit-tested independently (`make check`); the names and
mechanisms are tabled in `jaillimitinfo` (one row per limit: name, unit, and
mechanism binding — a cgroup interface file or a `RLIMIT_*`), with
`[JLIMIT_CGROUP_FIRST, JLIMIT_CGROUP_LAST)` / `[JLIMIT_RLIMIT_FIRST, …_LAST)`
range markers so the cgroup and rlimit application loops each cover their own
span.

### 4.1 Config matching and `.ini`-style sections

`pa-jail.conf` is parsed line by line. `enablejail`/`disablejail` (and the
`enableskeleton`/`disableskeleton` variants) each carry a directory pattern or
are argless. A query rescans the whole file; the allow/deny decision is
**last-match-wins** (an argless top-level `disablejail` is a global veto).

Patterns match with `pathmatch` (see `pa-jutil`): `*`/`?`/`[...]` within a
component, `**` for whole-subtree matching. **Matching does not cascade** — a
pattern matches the queried directory *exactly* (component counts line up; `*`
does not cross `/`). Breadth comes only from `**`: `disablejail /foo/**` disables
`/foo` and everything beneath it, whereas `disablejail /foo` disables only `/foo`.

`.ini`-style headers attach per-directory settings without repeating the pattern.
A header `[JAILPAT]` gates its directives on the jaildir (`skip_section =
!pathmatch(JAILPAT, dir)`); `[]`, `[/**]`, `[**]`, `[/**/]` reset to global
scope. Inside a section a directive may be **argless**, acting on its natural
axis:

- `enablejail`/`disablejail` and `limit` target the **jaildir**: argless, they
  apply to the current scope (`JAILPAT`, or global at top level). So argless
  `enablejail` in `[JAILPAT]` ≡ `enablejail JAILPAT`.
- `enableskeleton`/`disableskeleton` target a **skeleton directory** — a
  different thing from the jaildir. A bare `enableskeleton` enables *no* skeleton;
  you must name it (`enableskeleton /skel/x` inside `[/foo]` = "jaildir `/foo` may
  use skeleton `/skel/x`"). This keying-on-the-skeleton is what makes per-jaildir
  skeletons possible.

An explicit-pattern directive inside a section is gated by **both** the section
and its own pattern. A header alone enables nothing.

```
# global scope: defaults for every jail
limit pids.max=64,cpu.max=1,rlimit.nofile=256,rlimit.fsize=256m,rlimit.core=0

[/jails/build/*]
enablejail
limit pids.max=512,cpu.max=4,rlimit.as=4g

# the limit line above is equivalent, without a section, to the two-arg form:
limit /jails/build/* pids.max=512,cpu.max=4,rlimit.as=4g
```

### 4.2 `permdir` — the create boundary

`jailperm::permdir` is the **shortest** literal prefix among the matching
`enablejail` globs — not last-match-wins, and deliberately not longest.
Components above `permdir` must pre-exist root-owned; pa-jail may create those
at/below it. Shortest is order-independent and honors the broadest grant: if any
matching rule authorizes a whole subtree, pa-jail creates within it, so adding a
narrower overlapping rule (e.g. a section that only scopes limits) never silently
shrinks the create zone. Longest was rejected as marginal (the root-owned config
already vouches for the prefix, and the walk uses `O_NOFOLLOW` plus the
final-target root-owned check of §2) and inconsistent (it only bit when an
incidental more-specific rule overlapped). To defend a writable intermediate dir
below `permdir`, check intermediates in the walk directly rather than infer from
glob specificity.

### 4.3 `limit` directive — forms, value grammar, names

Two forms: `limit NAME=VALUE[,NAME=VALUE…]` applies to the current scope;
`limit JDIR NAME=VALUE[,…]` applies only on jails whose directory matches `JDIR`
(a `pathmatch` glob, gated additionally by any enclosing section). Resolution is
a single pass with **per-name last-write-wins overlay**: a global default is
overridden by a later matching section or two-arg directive.

**Value grammar.** A `<value>` is a non-negative number with an optional unit;
`unlimited`/`inf`/`max` for the infinite form; or `unset` to clear an inherited
limit. Units depend on the limit: `k`/`m`/`g` (1024-based) for bytes; `s`/`m`/`h`
for cumulative-time; plain integer for counts; for a CPU *rate* (`cpu.max`), a
decimal in cores (`1.5`) or a percent of one core (`150%`), stored as millicores
(1000 = one core). A **byte** limit may instead be **`NN%`** (≤100) of RAM, e.g.
`memory.max=85%`; the parser leaves it unresolved (`jaillimit::percent`, no host
access) and the runtime multiplies it by `host_mem_bytes()` — physical RAM
clamped by the `memory.max` at every level of pa-jail's own cgroup hierarchy (so
the VM/host RAM, or the container's budget when cgroup-capped). Two suffix flags,
in either order and combinable (`pids.max=512!?`): `!` **pins** (the command line
cannot touch it, §4.4); `?` makes it **soft**. A malformed value, an over-100
`%`, or an unknown limit name is fatal — a limit never silently degrades to
"unlimited".

**Names — real kernel names, namespaced by mechanism.** One name binds exactly
one mechanism, and the name *is* the real kernel name, so the mechanism (and thus
the scope) is legible without a translation table:

| name              | scope       | mechanism / file          | unit              |
|-------------------|-------------|---------------------------|-------------------|
| `pids.max`        | per-jail    | cgroup `pids.max`         | count             |
| `cpu.max`         | per-jail    | cgroup `cpu.max`          | rate (millicores) |
| `memory.max`      | per-jail    | cgroup `memory.max`       | bytes             |
| `memory.high`     | per-jail    | cgroup `memory.high`      | bytes             |
| `memory.swap.max` | per-jail    | cgroup `memory.swap.max`  | bytes             |
| `rlimit.cpu`      | per-process | `RLIMIT_CPU`              | seconds           |
| `rlimit.as`       | per-process | `RLIMIT_AS`               | bytes             |
| `rlimit.fsize`    | per-process | `RLIMIT_FSIZE`            | bytes             |
| `rlimit.nofile`   | per-process | `RLIMIT_NOFILE`           | count             |
| `rlimit.core`     | per-process | `RLIMIT_CORE`             | bytes             |
| `rlimit.nproc`    | per-uid     | `RLIMIT_NPROC`            | count             |
| `tmpfs.size`      | per-jail    | mount `size=` (`/tmp`)    | bytes             |

The scope subtlety: `rlimit.nproc` is **per-uid / system-wide**, so it can leak
across concurrent jails sharing a uid — which is why the per-jail process cap is
the distinctly-named cgroup `pids.max`, never an unqualified `nproc`. Two
name-vs-semantics notes: `cpu.max` is a per-jail *rate*, not a
cumulative-seconds cap (cgroups have no "kill after N CPU-seconds" knob; the
cumulative cap is the separate `rlimit.cpu`), and pa-jail computes its raw
`"$QUOTA $PERIOD"` pair at apply time from the millicore value. `tmpfs.size` is
the one limit that is **neither cgroup nor rlimit**: it becomes the `size=` option
on the jail's `/tmp` tmpfs, and only bites when `/tmp` is a tmpfs (a disk-backed
`/tmp` is not a RAM-fill vector).

**Soft (`?`) limits.** A soft limit is best-effort: if it cannot be *enforced* —
no cgroup support in the build, an undelegated controller, a failed cgroup
write/placement, an unavailable `RLIMIT_*` — the run proceeds without it instead
of dying, printing one `Warning: Soft limit \`NAME\` not set` (except when the
value is `unlimited`, where an unenforced "no constraint" is harmless). Wholesale
unavailability (no cgroup build / no cgroup v2 / a cgroup that can't be created)
is silent. A **hard** limit (the default) fails closed instead. The line is
precise: soft tolerates an *enforcement* gap, but a *config* error (bad value,
unknown name, malformed `cgroupbase`) is always fatal. Soft is the mechanism
behind the built-in defaults (§4.6).

**The `cgroup` pseudo-limit.** `cgroup` is the one name that is not a single
limit — it acts on *every* cgroup-controller limit at once, for dropping inherited
defaults on a trusted jail. `cgroup=unlimited` sets them all to `max` (still a
cgroup leaf, but uncapped); `cgroup=unset` clears them (if nothing else sets a
cgroup limit, the jail uses no cgroup at all). Both overlay per name like any
other limit (`cgroup=unset,pids.max=64` ⇒ only a pids cap), and `!` pins all of
them. No other `cgroup=` value is accepted.

### 4.4 Command-line override

`-l/--limit NAME=VALUE[,…]` (repeatable; later wins) resolves after the conf
(global-default → matching-section → `--limit`). What the caller may do depends
on how the conf set the limit:

- A **hard** conf limit (no `?`) may only be made **more restrictive**: the
  applied value is `min(conf, cmdline)` (treating `unlimited` as +∞); a looser
  `--limit` is ignored, and a `--limit …?` can lower the value but cannot relax a
  hard limit to soft.
- A **soft** conf limit (`?`) is a *default*, not a floor, so the command line
  **replaces it outright** — any value, looser or tighter, soft only if the
  override is. (To drop a soft default entirely, override it to `unlimited`; a
  literal command-line `unset` is not wired up.)
- A `!`-**pinned** conf value ignores the command line entirely.
- A name the conf left **unset** is *introduced* from the command line. An
  introduced/tightened cgroup limit takes effect only if its controller is
  already delegated (the flag tightens at run time, it does not re-run `init`).

The security-relevant guarantee: a *hard* limit can never be loosened by the
caller. Implemented in `jaillimits::apply_overrides`.

### 4.5 cgroups, pools, and deployment

**All cgroup work runs in the host-ns root parent**, before/around `clone`, using
ordinary `/sys/fs/cgroup` paths and full root credentials; it never reaches into
the pivoted jail. Each jail's per-run **leaf** is `<pool>/<pid>`, under the
**pool** named by its `cgroupbase` (default `/sys/fs/cgroup/pa-jail`). The pool is
created once and left in place, carries the aggregate limits shared by its jails,
and cgroup v2 composes the effective limit as `min(leaf, pool, …ancestors)` — so
per-jail leaf caps and a shared pool cap compose for free (`cgroup_setup`).

**Race-free placement via `clone3` + `CLONE_INTO_CGROUP`** (Linux 5.7+): the
child is born *inside* the leaf, so its limits apply from the first instruction
and student code forked later can never run unconfined — no separate
`cgroup.procs` write, no synchronization barrier. Only the cgroup path uses
`clone3`; runs with no cgroup limit keep the plain `clone()`. The supervisor
counts against `pids.max` (set the limit with a few procs of headroom).

**Compile-gated, runtime fail-closed.** The cgroup machinery builds only when the
headers provide `clone3`/`CLONE_INTO_CGROUP` (`PA_HAVE_CGROUP`); otherwise it
compiles out and pa-jail still builds and runs normally for non-cgroup use. A
config that *sets a hard cgroup limit* on such a build dies with a clear message
rather than running unconfined — keyed at run time on whether a limit was actually
requested.

**Lazy cleanup, no lingering reaper.** Removing a leaf needs root, but the
supervisor has by then pivoted away from `/sys/fs/cgroup` and dropped to the
caller. So instead of a privileged reaper, **each setup reclaims the empty leaves
of previous finished runs** (`cgroup_reclaim_stale`: scan the pool, `rmdir` every
`<N>` leaf whose owning pa-jail process `N` is gone). A running jail is skipped
two ways — its owner is alive *and* its leaf is populated, so `rmdir` fails
harmlessly. A finished run's empty leaf lingers only until the next run
(steady-state ≈ concurrency; empty cgroups are free). Keeping cleanup in the
inherently-root setup parent preserves the "caller can only tighten" guarantee (a
caller-writable cgroup ancestor would let the caller migrate the student out of
its limits). All `mkdir`/`echo`/`rmdir` are logged under `--verbose` and skipped
under `--dry-run`.

**`cgroupbase` and pools.** The `cgroupbase PATH` directive (a global default, or
per-`[JAILPAT]` section, last-match-wins) routes a jail's leaf to a chosen pool.
A leading `$SELF` expands to pa-jail's own cgroup (from `/proc/self/cgroup`'s
`0::/…` line), validated to sit under the v2 mount with no `..`; the value is
stored verbatim and expanded only at apply time. Pool limits come from typed
sections: `[cgroup PATH]` defines limits for that one pool, a bare `[cgroup]`
defines limits for *every* pool; the jaildir query skips these (a pool's limits
never leak into a jail's own `limits`). `pajailconf::parse_pool` overlays them,
matching `PATH` literally against the same string `cgroupbase` carries; pool
sections take only the no-`JDIR` form and only cgroup names (no `rlimit.*`).

```
cgroupbase /sys/fs/cgroup/grading          # default pool for all jails ($SELF[/sub] ok)

[cgroup /sys/fs/cgroup/grading]            # that pool's own aggregate limits
limit pids.max=4000,memory.high=48g,memory.max=56g,cpu.max=16

[/jails/build/*]
enablejail
cgroupbase /sys/fs/cgroup/grading-build    # route these jails to a different pool
limit pids.max=512,memory.max=4g           # each jail's own per-leaf limits
```

**Pool at the cgroup root.** `cgroupbase $SELF` can resolve the pool to the v2
root — on bare metal, or, under Docker, to the container's *own* namespace root
(`/proc/self/cgroup` reads `0::/` there). pa-jail never writes aggregate limits to
that cgroup: it has no controller interface files, and it belongs to the operator
(or Docker) — writing `memory.max` there would clobber the container's own cap.
Only the per-jail **leaf** limits apply (in leaves created under it; the real root
is exempt from cgroup v2's no-internal-processes rule). A *hard* pool limit that
thus can't be honored is fatal; the soft defaults drop with one `!quiet` note.

**Deployment requirement (cgroup limits only).** Linux 5.7+ at build time, a
cgroup v2 unified hierarchy at run time, and a **delegated** base — one pa-jail
may create children under, with the needed controllers enabled in its
`cgroup.subtree_control`. None of this constrains a build or run that uses no
cgroup limits; any shortfall fails closed, never silently unconfined. On a normal
systemd host the real `/sys/fs/cgroup` already qualifies (root may create leaves;
systemd has enabled the controllers). A process **confined** to its own subtree
(e.g. inside a container whose cgroup-namespace root is a non-root cgroup full of
processes) cannot enable controllers there while those processes are present —
the fix is to move them into a child first.

**`pa-jail init JAILDIR`** performs that one-time bootstrap as a subcommand
(alongside `run`/`rm`/`mv`). It resolves the `cgroupbase` that `run JAILDIR` would
use (so per-section pools are handled and the path is never repeated), enables
exactly the controllers that jaildir's leaf+pool limits need on that base and its
parent, and — unlike the per-run path — **evacuates** the parent's processes into
a `pa-jail.host` child when needed (the container case: a non-root cgroup that
still holds processes). If the jaildir has no cgroup limits it does nothing.
Best-effort and idempotent: an undelegatable controller is reported and skipped
(a per-jail limit needing it then fails closed at run time), a second `init` is a
no-op, and on a real systemd host it is a near-no-op. Run it once per boot, before
any jails (a systemd unit or container entrypoint), passing a jaildir for each
pool you use. This keeps the per-run path out of migrating processes it does not
own.

### 4.6 cgroups vs rlimits, and the built-in defaults

`setrlimit` is per-process — weak for memory (each child may map up to
`RLIMIT_AS`) and useless as an aggregate fork-bomb cap — so the cgroup limits are
the real per-jail enforcement and rlimits are belt-and-suspenders. Both share the
one `limit` directive and parser; the name selects the mechanism. **Best
practice:** prefer cgroups for the aggregate, containment-critical resources they
own — processes (`pids.max`), memory (`memory.max`/`memory.high`), CPU rate
(`cpu.max`); their per-process rlimit cousins are leaky (`RLIMIT_NPROC` per-uid)
or correctness-breaking (`RLIMIT_AS` caps *virtual*, not resident, memory). Use
`rlimit.*` for what cgroups can't express — `rlimit.core` (no core dumps),
`rlimit.nofile` (fd count), `rlimit.fsize` (per-file size) — and `tmpfs.size` for
the `/tmp` RAM-fill guard.

**Built-in defaults.** pa-jail ships a set of default limits (`default_limits_-
conf` in `pa-jail.cc`), parsed by the same `parse()`/`parse_pool()` machinery as
the real config and seeded **first**, so any directive overrides one and `NAME=
unset` drops it. All are **soft**, so they enforce where the mechanism exists and
quietly don't where it doesn't. The design rule is *stop the catastrophe, never
bound legit grading work* — so err generous.

```
limit pids.max=1024?,tmpfs.size=512m?,rlimit.core=0?
[cgroup]
limit memory.high=70%?,memory.max=85%?,memory.swap.max=0?
```

The **leaf-vs-pool split follows from what `%` means**: a percent is a slice of
one fixed total (RAM), so the quantity that must sum to ≤ that total is the
*aggregate* across concurrent jails — exactly what a **pool** `memory.max` caps
(cgroup v2 bounds the sum of a parent's children). A per-jail-leaf `memory.max=
85%` would let `C` concurrent jails reach `C×85%` and OOM the host; the pool form
is correct for any concurrency. So the memory `%` budget lives on the **pool**,
while the per-jail caps (`pids.max`, `tmpfs.size`, `rlimit.core`) live on the
**leaf**, which carries no memory default (a single jail may use up to the whole
pool; `min(leaf, pool)` still bounds the host). Caveat: an operator running
*multiple* pools must split the `%` (two `85%` pools = 170%).

- **`pids.max=1024`** (leaf) — the single most important default (fork bombs);
  ~5–20× any real build's peak. Drop to 256–512 for tighter.
- **`memory.high=70%` + `memory.max=85%`** (pool) — auto-scale to the VM/host (or
  container budget); `high` throttles (reclaim) before `max` kills, so a brief
  spike slows instead of being OOM-killed (a correct submission OOM-killed is a
  false failure). `memory.swap.max=0` makes `max` a true RAM cap (no swap-thrash).
- **`tmpfs.size=512m`** (leaf) — bounds the `/tmp` tmpfs RAM-fill (only when
  `/tmp` is a tmpfs). May also be `NN%`.
- **`rlimit.core=0`** (leaf) — pure upside; no giant core files, works without
  cgroups.

Deliberately *not* defaulted: `cpu.max` (slows builds; the supervisor timeout
already kills runaway CPU), `rlimit.as` (caps virtual not resident memory; breaks
JITs/sanitizers), `rlimit.cpu`/`rlimit.nproc` (redundant with the timeout and
`pids.max`), `rlimit.nofile` (lowering breaks large links), `rlimit.fsize` (only
a per-file cap; real risk of clipping legit output).

Because the defaults add soft cgroup limits to every run, a host with cgroup v2
present but **not** delegated to pa-jail's pool prints per-limit "soft … not set"
warnings until `pa-jail init` sets up delegation; gating those behind `-q` is a
possible follow-up.

## 5. Testing

`make check` builds and runs `test-pa-jailconf` — the pure, unprivileged helpers
only (the config parser, `pathmatch`, `path_absolute`, `path_pa_validate`,
`shell_quote`), not the privileged runtime.

`make check-jail-docker` runs the end-to-end driver `test-pa-jail` in a
`--privileged gcc:14` container (works from macOS; `make check-jail` runs it
locally as root on Linux). It runs the real `pa-jail` in a real jail and asserts
on behavior: `mount` flags, `tmpfs.size`, the `dev` allowlist, the built-in
`defaults`, `userns` (identity map + empty `CapBnd`), a shared-`pool` cap and a
per-jail leaf cap together, `soft` (hard refused / soft runs unconfined), and
`--limit`. Add new runtime tests there. Still uncovered: the path walk / ownership
checks, the privilege-drop details, and teardown.

Debugging by hand: `pa-jail run --dry-run --verbose …` prints the exact
`mkdir`/`echo`/`rmdir`/`setrlimit` sequence without touching the system. For real
enforcement, a `docker run --privileged gcc:14` container with cgroup v2 (free the
cgroup root of processes, enable the controllers in `cgroup.subtree_control`),
register a tiny **static** fork-counter as the jail user's shell (`/etc/shells` +
`-F /path/to/forksh`), and run with `pids.max=15`: it prints `FORKCAP at 13
children` (15 − supervisor − shell). Since pa-jail links `/dev/ptmx` on every run,
the manifest needs no `/dev/*` entries for the pty.

## 6. `shell_quote`

`shell_quote` (in `pa-jutil.cc`) is functionally correct but written compactly
(one variable serves as the needs-quoting flag, the opening-quote buffer, and the
escape accumulator; a trailing `substr` emits ordinary characters). Rather than
replace it with the simpler always-single-quote form, it is **kept and tested**,
since it sits on the `bash -lc` command-construction path: `test_shell_quote()`
pins exact output for the verbatim, single-quoted, and `'\''`-escape cases, and
`fuzz_shell_quote()` round-trips ~200k random strings against two oracles — a
hand-written single-quote decoder and a real `/bin/sh` that must parse the result
as exactly one word equal to the input (scaled by `PA_SHELL_FUZZ=N`). The fuzzer
caught one real defect: an empty argument used to quote to `""` (which vanishes
from a command line); it now quotes to `''`.
