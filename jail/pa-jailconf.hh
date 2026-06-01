// pa-jailconf.hh -- Peteramati structure for pa-jail.conf files
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#pragma once
#include <cassert>
#include <array>
#include <string>
#include <string_view>
#include <stdexcept>

// Thrown by `pajailconf` (its constructors, `parse`, and `parse_pool`) on any
// configuration error: a malformed directive or limit value, an unknown limit
// name, or an unreadable/oversize/non-root-owned config file. `lineno` is the
// 1-based config line the error is attributed to, or 0 for file-level errors
// with no line.
struct pajailconf_error : std::runtime_error {
    int lineno;

    explicit pajailconf_error(std::string msg, int lineno_ = 0)
        : std::runtime_error(std::move(msg)), lineno(lineno_) {
    }
    // The message, prefixed with the config line number when one is known.
    std::string message() const {
        if (lineno > 0) {
            return "line " + std::to_string(lineno) + ": " + what();
        }
        return std::string(what());
    }
};

// pa-jail.conf

// Resource limits.
//
// Each limit is identified by a `jaillimit_id`. Config names are the REAL kernel
// names, namespaced by mechanism: a cgroup-v2 limit uses its interface filename
// (`pids.max`, `cpu.max`, `memory.max`) and is enforced *per-jail* on this
// jail's process tree; an rlimit uses `rlimit.<name>` (`rlimit.cpu`,
// `rlimit.nofile`) and is *per-process*. The mechanism -- and so the scope -- is
// visible in every name, with one thing to know: `rlimit.nproc` (RLIMIT_NPROC)
// is *per-uid/system-wide*, so it can reach across concurrent jails sharing a
// uid; the per-jail process cap is the distinctly-named `pids.max`. One name
// binds exactly one mechanism -- there is no fan-out.
//
// Adding a limit is a new `jaillimit_id` plus a row in `jaillimitinfo::infos[]`
// (pa-jailconf.cc); an rlimit's row carries its `RLIMIT_*`. The cgroup limits
// range over `[JLIMIT_CGROUP_FIRST, JLIMIT_CGROUP_LAST)` and the per-process
// rlimits over `[JLIMIT_RLIMIT_FIRST, JLIMIT_RLIMIT_LAST)`; anything after the
// rlimits (e.g. `tmpfs.size`) is neither, and is applied by its own mechanism.
enum jaillimit_id {
    JLIMIT_PIDS_MAX = 0,  // cgroup pids.max    -- max processes in the jail (count)
    JLIMIT_CPU_MAX,       // cgroup cpu.max     -- jail CPU rate, in millicores
                          //                       (1000 == one core; 1500 == "1.5")
    JLIMIT_MEMORY_MAX,    // cgroup memory.max  -- jail memory hard cap (bytes)
    JLIMIT_MEMORY_HIGH,   // cgroup memory.high -- jail memory throttle level (bytes)
    JLIMIT_MEMORY_SWAP_MAX,// cgroup memory.swap.max -- jail swap hard cap (bytes)
    JLIMIT_RLIMIT_CPU,    // RLIMIT_CPU    -- per-process CPU time (seconds)
    JLIMIT_RLIMIT_AS,     // RLIMIT_AS     -- per-process address space (bytes)
    JLIMIT_RLIMIT_FSIZE,  // RLIMIT_FSIZE  -- per-process max file size (bytes)
    JLIMIT_RLIMIT_NOFILE, // RLIMIT_NOFILE -- per-process open files (count)
    JLIMIT_RLIMIT_CORE,   // RLIMIT_CORE   -- per-process core-dump size (bytes)
    JLIMIT_RLIMIT_NPROC,  // RLIMIT_NPROC  -- per-uid process count (system-wide)
    JLIMIT_TMPFS_SIZE,    // mount  tmpfs.size  -- jail /tmp tmpfs `size=` cap (bytes)
    JLIMIT_COUNT,

    JLIMIT_CGROUP_FIRST = 0,
    JLIMIT_CGROUP_LAST = JLIMIT_RLIMIT_CPU,
    JLIMIT_RLIMIT_FIRST = JLIMIT_RLIMIT_CPU,
    JLIMIT_RLIMIT_LAST = JLIMIT_TMPFS_SIZE
};

// Limit value units. UNIT_SECONDS (a cumulative-time limit, `s`/`m`/`h`) is used
// by `rlimit.cpu`.
enum jaillimit_unit {
    UNIT_COUNT,
    UNIT_RATE,
    UNIT_BYTES,
    UNIT_SECONDS
};

struct jaillimitinfo {
    std::string_view name;
    jaillimit_unit unit;
    bool cgroup;
    int rlimit;

    constexpr bool is_cgroup() const {
        return cgroup;
    }
    std::string_view cgroup_controller() const {
        assert(cgroup);
        return name.substr(0, name.find('.'));
    }
    std::string_view cgroup_file() const {
        assert(cgroup);
        return name;
    }
    int rlimit_resource() const {
        assert(!cgroup && rlimit != -1);
        return rlimit;
    }

    static const std::array<jaillimitinfo, JLIMIT_COUNT> infos;
    static const jaillimitinfo& get(int n) {
        return infos[n];
    }
    static int lookup(std::string_view name);
};

// One resolved limit value. `set` is false when no directive named this limit,
// in which case it is left inherited (the feature is opt-in). `unlimited` maps
// to cgroup "max" (RLIM_INFINITY for rlimits); `value` is then ignored.
// `pinned` (a `!` suffix in the conf) forbids the command line from loosening
// the value -- see HARDENING.md §4.4. `soft` (a `?` suffix) means best-effort: if
// this limit cannot be *enforced* (no cgroup support, an undelegated controller,
// an unavailable rlimit), the run proceeds without it instead of failing -- the
// mechanism behind default limits (see HARDENING.md §4.6). The two are orthogonal
// and combine (`64!?`). `percent` (a `%` suffix, byte limits only) means `value`
// is a percentage of total RAM, not bytes -- the *parser* leaves it unresolved
// (it has no host access); the runtime multiplies it by introspected memory (see
// `host_mem_bytes`, pa-jail.cc) before use. The unit of `value` is per-limit (see
// the `jaillimit_id` comments).
struct jaillimit {
    bool set = false;
    bool unlimited = false;
    bool pinned = false;
    bool soft = false;
    bool percent = false;
    unsigned long long value = 0;
};

struct jaillimits {
    jaillimit l[JLIMIT_COUNT];

    const jaillimit& operator[](int i) const { return l[i]; }
    jaillimit& operator[](int i) { return l[i]; }

    template <typename F>
    bool any(F&& f, int first, int last) const {
        for (int i = first; i != last; ++i) {
            if (f(i, l[i]))
                return true;
        }
        return false;
    }

    template <typename F>
    bool any(F&& f) const {
        return any(std::forward<F>(f), 0, JLIMIT_COUNT);
    }

    void apply_overrides(const jaillimits& overrides);
};

// The pool cgroup a jail's per-run leaf is created under, named by `cgroupbase`
// (default below) and joined *literally* against `[cgroup PATH]` sections (see
// `pajailconf::parse_pool`); a `$SELF`-relative form is stored verbatim and
// expanded only at apply time.
inline constexpr char default_cgroupbase[] = "/sys/fs/cgroup/pa-jail";

// A `pajailconf` query and its result. (`dir`, `skeletondir`) are the inputs --
// the jail directory and an optional skeleton; the other fields are filled in
// by `parse()`. `enabled`/`skeleton_enabled` say whether each is permitted; for
// an enabled jail `permdir` is the create boundary (the shortest literal prefix
// among the matching `enablejail` globs, below which pa-jail may create
// components), `limits` the resolved resource limits, and `cgroupbase` the pool
// it joins (default `default_cgroupbase`, overridable by a `cgroupbase`
// directive). If `!enabled`, `disabled_lineno` is the 1-based line of the
// responsible `disablejail` (0 if none -- e.g. never enabled), used to explain it.
struct jailperm {
    std::string dir;
    std::string skeletondir;
    std::string permdir;
    std::string cgroupbase = default_cgroupbase;
    bool enabled = false;
    bool skeleton_enabled = false;
    int disabled_lineno = 0;
    jaillimits limits;

    jailperm() = default;
    jailperm(std::string dir_, std::string skeletondir_ = std::string())
        : dir(std::move(dir_)), skeletondir(std::move(skeletondir_)) {
        if (!dir.empty() && !dir.ends_with('/')) {
            dir.push_back('/');
        }
        if (!skeletondir.empty() && !skeletondir.ends_with('/')) {
            skeletondir.push_back('/');
        }
    }
    explicit operator bool() const {
        return enabled;
    }
    // Error-message fragment indicating the disabling line
    std::string disable_message() const {
        if (disabled_lineno > 0) {
            return "  (disabled on line " + std::to_string(disabled_lineno) + ")\n";
        }
        return std::string();
    }
};

struct pajailconf {
    pajailconf();
    pajailconf(std::string_view str);

    void parse(jailperm&) const;

    // Overlay the pool cgroup `path`'s configured limits onto `limits`, in place:
    // the `limit` directives in `[cgroup]` sections (which apply to every pool) and
    // `[cgroup PATH]` sections whose PATH literally equals `path` (the same string
    // a jail's `cgroupbase` carries), in file order (last write wins per name; a
    // config `unset` clears the entry). The caller pre-seeds `limits` with any
    // built-in defaults, which the config thus overrides. Cgroup-controller limits
    // only.
    void parse_pool(jaillimits& limits, std::string_view path) const;

    static void parse_limits(jaillimits& limits, std::string_view str);

    inline jailperm get(std::string dir, std::string skeletondir = std::string()) const {
        jailperm perm(std::move(dir), std::move(skeletondir));
        parse(perm);
        return perm;
    }

private:
    char buf_[8192];
    size_t len_;
};
