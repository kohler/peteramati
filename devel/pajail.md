# Peteramati `pa-jail`

`pa-jail` is the setuid-root helper that Peteramati uses to build and run
untrusted student code in an isolated Linux container (a *jail*). Peteramati
invokes it automatically; as an administrator you mostly interact with it
through one configuration file, `/etc/pa-jail.conf`, which controls where jails
may live and what resource limits they run under.

This document covers reading and configuring `pa-jail`. For the container
contents (manifest, student repo, overlay) see [`runners.md`](runners.md).


## The program

`pa-jail` is installed setuid-root and setgid (`chown root:0`, `chmod
u+s,g+s`). It has five subcommands:

* `pa-jail add JAILDIR [USER]` — create or augment a jail directory.
* `pa-jail run JAILDIR USER [NAME=VALUE...] COMMAND...` — run `COMMAND` as the
  unprivileged `USER` inside the jail, under a timeout.
* `pa-jail rm JAILDIR` — unmount and remove a jail (like `rm -r --one-file-system`).
* `pa-jail mv SOURCE DEST` — safely move a jail.
* `pa-jail init JAILDIR` — one-time cgroup setup (see “Resource limits” below).

Every subcommand takes a `JAILDIR` that must be allowed by `/etc/pa-jail.conf`.

Two options help you see what `pa-jail` does without changing anything:

* `-V`, `--verbose` prints each action (every `mount`, `mkdir`, `cgroup` write,
  `setrlimit`, …) as it runs it.
* `-n`, `--dry-run` prints those actions *without* performing them.

So `pa-jail run --dry-run --verbose JAILDIR USER /bin/echo hi` prints the exact
sequence of mounts, cgroup writes, and limit settings a real run would perform —
the quickest way to understand or debug a configuration.


## The jail directory

A jail directory is an absolute pathname containing only letters, numbers, and
characters in `-._~`. There must be no symbolic links anywhere in the path, and
the directory and all its parents must be owned by root and writable only by
root. These rules keep a misconfiguration from scattering student files across
your filesystem, and keep a less-trusted caller from swapping the jail tree.

`pa-jail` may create the jail directory and intermediate components at run time,
but only *below* the boundary established by the matching `enablejail` pattern
(see “The create boundary” below). Components above that boundary must already
exist, root-owned.

A jail directory must also be **enabled** by `/etc/pa-jail.conf`.


## `/etc/pa-jail.conf`

`pa-jail` reads `/etc/pa-jail.conf` to decide which directories may be jails and
what limits they run under. The file must be owned by root, writable only by
root, no larger than 8 KB, and not a symbolic link; otherwise `pa-jail` refuses
to run. It is parsed one line at a time. Blank lines and lines beginning with
`#` are ignored.

The basic directives are:

```
enablejail PATTERN
disablejail PATTERN
enableskeleton PATTERN
disableskeleton PATTERN
```

`PATTERN` is a directory glob matched with `pathmatch`: `*`, `?`, and `[...]`
match within a single path component, and `**` matches across components (a
whole subtree). Matching is **exact** — a pattern matches a directory only when
their components line up, and `*` does not cross `/`. To act on a whole subtree,
say so explicitly with `**`:

```
disablejail /jails/**     # disables /jails and everything under it
disablejail /jails        # disables only /jails itself
```

* `enablejail PATTERN` allows jail directories matching `PATTERN`.
* `disablejail PATTERN` disallows them. (`allowjail` and `nojail` are accepted
  as synonyms for `enablejail` and `disablejail`.)

A query rescans the whole file and the decision is **last-match-wins**: the last
`enablejail`/`disablejail` line that matches the queried directory decides it.
An argless top-level `disablejail` (no pattern) is a global veto. A directory
that no line enables is disallowed.

```
# allow jails anywhere under /jails, except the reserved subtree
enablejail /jails/**
disablejail /jails/reserved/**
```

### The create boundary

When `pa-jail` is allowed to create a jail, it may create the jail directory and
any missing components *below* the **shortest** literal prefix among the
matching `enablejail` patterns. Components above that prefix must already exist
and be root-owned. For example, with `enablejail /jails/**`, the prefix is
`/jails`, so `/jails` must already exist but `pa-jail` may create
`/jails/cs61/pset1` beneath it.

### Skeletons

A *skeleton* is a directory whose contents are copied into a new jail (see
[`runners.md`](runners.md)). `enableskeleton`/`disableskeleton` control which skeletons a jail
may use. Unlike the jail directives, these are keyed on the *skeleton*
directory, not the jail directory.

### Sections

To attach settings to a group of jails without repeating the pattern, use
`.ini`-style section headers. A header `[JAILPAT]` opens a section whose
directives apply only when the queried jail directory matches `JAILPAT`. Inside
a section, `enablejail`, `disablejail`, and `limit` may be written with **no
pattern** — they then apply to `JAILPAT` itself:

```
[/jails/build/*]
enablejail                       # == enablejail /jails/build/*
limit pids.max=512,cpu.max=4
```

`enableskeleton`/`disableskeleton` are the exception: a bare `enableskeleton`
inside a section enables *no* skeleton (you must still name one), which is what
lets different jails use different skeletons.

`[]`, `[**]`, and `[/**]` reset back to the global (top-level) scope. A header
alone enables nothing; it only scopes the directives inside it.


## Resource limits

The `limit` directive caps the resources a jail may use. One grammar drives
three mechanisms, each named by its real kernel name:

```
limit NAME=VALUE[,NAME=VALUE...]          # current scope
limit JDIR NAME=VALUE[,NAME=VALUE...]     # only jails matching JDIR
```

| name              | what it limits                          | unit              |
|-------------------|-----------------------------------------|-------------------|
| `pids.max`        | processes (fork-bomb cap), per jail     | count             |
| `cpu.max`         | CPU rate, per jail                      | cores (`1.5`) or `%` |
| `memory.max`      | memory (hard cap), per jail             | bytes             |
| `memory.high`     | memory (throttle before `max`), per jail| bytes             |
| `memory.swap.max` | swap, per jail                          | bytes             |
| `rlimit.cpu`      | cumulative CPU time, per process        | seconds           |
| `rlimit.as`       | address space, per process              | bytes             |
| `rlimit.fsize`    | maximum file size, per process          | bytes             |
| `rlimit.nofile`   | open file descriptors, per process      | count             |
| `rlimit.core`     | core-dump size, per process             | bytes             |
| `rlimit.nproc`    | processes per *uid* (system-wide)       | count             |
| `tmpfs.size`      | size of the jail’s `/tmp` tmpfs         | bytes             |

The `pids.max`, `cpu.max`, and `memory.*` limits use Linux **cgroups** and bound
the jail as a whole; the `rlimit.*` limits use POSIX `setrlimit` and bound each
process individually. Prefer the cgroup limits for containment — `rlimit.nproc`
is per-uid (it leaks across concurrent jails sharing a user) and `rlimit.as`
caps virtual rather than resident memory (it breaks JITs and sanitizers), which
is why the per-jail process cap is the distinctly-named `pids.max`.

**Values.** A value is a non-negative number with an optional unit:

* bytes: `k`, `m`, `g` (1024-based), e.g. `memory.max=4g`;
* time: `s`, `m`, `h`, e.g. `rlimit.cpu=30s`;
* counts: a plain integer, e.g. `pids.max=512`;
* CPU rate: cores as a decimal (`cpu.max=1.5`) or a percent of one core
  (`cpu.max=150%`);
* a byte limit may be a **percent of RAM**: `memory.max=85%` resolves at run
  time against the machine (or container) memory.

`unlimited` (also `inf`, `max`) removes a limit; `unset` clears an inherited one.
A malformed value or unknown name is a fatal error — a limit never silently
becomes “unlimited”.

**Suffix flags.** Append `!` to **pin** a limit (the `--limit` command line
cannot change it) or `?` to make it **soft** (best-effort: if it can’t be
enforced — no cgroup support, an undelegated controller — the run proceeds
without it instead of failing). The flags combine: `pids.max=512!?`.

```
# global defaults for every jail
limit pids.max=1024,rlimit.core=0,tmpfs.size=512m

[/jails/build/*]
enablejail
limit pids.max=4000,memory.max=4g,cpu.max=4     # builds need more headroom
```

### Built-in defaults

Even with no configuration, `pa-jail` ships generous **soft** defaults so a host
is safe out of the box:

```
limit pids.max=1024?,tmpfs.size=512m?,rlimit.core=0?     # per jail
limit memory.high=70%?,memory.max=85%?,memory.swap.max=0?  # aggregate, per pool
```

These are parsed *before* `/etc/pa-jail.conf`, so any directive you write
overrides them and `limit NAME=unset` drops one. They are soft, so they apply
where the mechanism exists and stay quiet where it doesn’t. The design rule is
*stop the catastrophe, never bound legitimate grading work* — so the numbers err
generous; tighten them for your host as needed.

The memory budget is a percent of RAM and lives on the **pool** (below), not the
individual jail, because a percent is a slice of one fixed total: `C` concurrent
jails each allowed 85% would oversubscribe the host, but a single pool cap bounds
their sum for any concurrency.

### Tightening at run time

`pa-jail run -l/--limit NAME=VALUE,...` lets the caller adjust limits for one
run. The caller may only make a **hard** (unflagged) configuration limit *more*
restrictive, never looser — the security guarantee is that a hard limit in the
config can never be loosened from the command line. A **soft** default, by
contrast, may be replaced outright (to drop one entirely, override it to
`unlimited`). A `!`-pinned limit ignores `--limit` completely.


## cgroups and pools

The cgroup limits (`pids.max`, `cpu.max`, `memory.*`) require a Linux 5.7+
kernel and a cgroup v2 unified hierarchy. Each run gets its own cgroup *leaf*
under a shared *pool*; cgroup v2 makes the effective limit the minimum of the
leaf, the pool, and any enclosing cgroup, so a per-jail cap and a pool-wide cap
compose automatically.

The `cgroupbase` directive names the pool (default `/sys/fs/cgroup/pa-jail`):

```
cgroupbase /sys/fs/cgroup/grading          # pool for all jails

[cgroup /sys/fs/cgroup/grading]            # that pool’s own aggregate limits
limit pids.max=4000,memory.max=56g,cpu.max=16

[/jails/build/*]
enablejail
cgroupbase /sys/fs/cgroup/grading-build    # route these jails to another pool
limit pids.max=512,memory.max=4g           # each jail’s own limits
```

A `[cgroup PATH]` section sets limits for one pool; a bare `[cgroup]` section
sets limits for every pool. A leading `$SELF` in `cgroupbase` means “pa-jail’s
own cgroup” — useful inside a container, where `cgroupbase $SELF` puts the pool
in the container’s own cgroup so `pa-jail` adds only per-jail limits and leaves
the container’s own caps untouched.

### `pa-jail init`

Before cgroup limits will work, the controllers must be **delegated** to
`pa-jail`’s pool. On a normal systemd host running as root, `/sys/fs/cgroup`
already qualifies and nothing is needed. Inside a container, or under a confined
service, run the one-time bootstrap once per boot, before any jails:

```
pa-jail init JAILDIR
```

`init` resolves the `cgroupbase` that `pa-jail run JAILDIR` would use, enables
exactly the controllers that jail’s limits need, and (inside a container) moves
existing processes out of the way so the controllers can be delegated. It is
idempotent and best-effort: if a jaildir has no cgroup limits it does nothing,
and a controller that can’t be delegated is reported and skipped (a limit that
needed it then fails closed at run time). Run it from a systemd unit or a
container entrypoint, passing one jaildir for each pool you use.

If you set a cgroup limit but the controllers aren’t delegated, a **hard** limit
makes the run fail (it never silently runs unconfined), while a **soft** limit
prints a `Warning: Soft limit ... not set` and the run continues. Running
`pa-jail init` clears those warnings.


## User namespaces

`pa-jail run --userns` runs the student in a Linux user namespace, mapped to its
own unprivileged uid, with “root in the jail” (uid 0) left unmapped to the host
`nobody` and all capabilities dropped. This is an extra layer of defense and is
opt-in; it fails closed on a kernel without unprivileged user namespaces.
