// pa-jailconf.hh -- Peteramati structure for pa-jail.conf files
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#pragma once
#include <string>
#include <string_view>

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
// The starting set is the two per-jail cgroup limits below. Adding a limit is a
// new `jaillimit_id` plus a row in `limit_descs[]` (pa-jailconf.cc).
enum jaillimit_id {
    JLIMIT_PIDS_MAX = 0,// cgroup pids.max    -- max processes in the jail (count)
    JLIMIT_CPU_MAX,     // cgroup cpu.max     -- jail CPU rate, in millicores
                        //                       (1000 == one core; 1500 == "1.5")
    JLIMIT_MEMORY_MAX,  // cgroup memory.max  -- jail memory hard cap (bytes)
    JLIMIT_MEMORY_HIGH, // cgroup memory.high -- jail memory throttle level (bytes)
    JLIMIT_COUNT
};

// One resolved limit value. `set` is false when no directive named this limit,
// in which case it is left inherited (the feature is opt-in). `unlimited` maps
// to cgroup "max" (RLIM_INFINITY for rlimits); `value` is then ignored.
// `pinned` (a `!` suffix in the conf) forbids the command line from loosening
// the value -- see HARDENING.md 6.4. The unit of `value` is per-limit (see the
// `jaillimit_id` comments).
struct jaillimit {
    bool set = false;
    bool unlimited = false;
    bool pinned = false;
    unsigned long long value = 0;
};

struct jaillimits {
    jaillimit l[JLIMIT_COUNT];
    const jaillimit& operator[](int i) const { return l[i]; }
    jaillimit& operator[](int i) { return l[i]; }
};

// The pool cgroup a jail's per-run leaf is created under, named by `cgroupbase`
// (default below) and joined *literally* against `[cgroup PATH]` sections (see
// `pajailconf::pool_limits`); a `$SELF`-relative form is stored verbatim and
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

    // Resolved limits of the pool cgroup `path` -- the union of the `limit`
    // directives in `[cgroup PATH]` sections whose PATH literally equals `path`
    // (the same string a jail's `cgroupbase` carries). Cgroup-controller limits
    // only.
    jaillimits pool_limits(std::string_view path) const;

    inline jailperm get(std::string dir, std::string skeletondir = std::string()) const {
        jailperm perm(std::move(dir), std::move(skeletondir));
        parse(perm);
        return perm;
    }

private:
    char buf_[8192];
    size_t len_;
};
