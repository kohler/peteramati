// pa-jailconf.cc -- Peteramati structure for pa-jail.conf files
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#include "pa-jailconf.hh"
#include "pa-jutil.hh"
#include <cstring>
#include <vector>
#include <fcntl.h>
#include <unistd.h>
#include <sys/stat.h>

static bool writable_only_by_root(const struct stat& st) {
    return st.st_uid == ROOT
        && (st.st_gid == ROOT || !(st.st_mode & S_IWGRP))
        && !(st.st_mode & S_IWOTH);
}

pajailconf::pajailconf() {
    int fd = open("/etc/pa-jail.conf", O_RDONLY | O_NOFOLLOW);
    if (fd == -1) {
        perror_die("/etc/pa-jail.conf");
    }

    struct stat st;
    if (fstat(fd, &st) != 0) {
        perror_die("/etc/pa-jail.conf");
    } else if (!writable_only_by_root(st)) {
        die("/etc/pa-jail.conf: Writable by non-root\n");
    }

    ssize_t nr = read(fd, buf_, sizeof(buf_));
    if (nr < 0) {
        perror_die("/etc/pa-jail.conf");
    } else if (nr == 0) {
        die("/etc/pa-jail.conf: Empty file\n");
    } else if (nr == sizeof(buf_)) {
        die("/etc/pa-jail.conf: Too big, max %zu bytes\n", sizeof(buf_));
    }
    len_ = nr;

    close(fd);
}

pajailconf::pajailconf(std::string_view s) {
    if (s.size() >= sizeof(buf_)) {
        die("pajailconf: String too big, max %zu bytes\n", sizeof(buf_));
    }
    memcpy(buf_, s.data(), s.size());
    len_ = s.size();
}

// Limit value units. (A `seconds` unit arrives with the rlimit limits that need
// it, e.g. `rlimit.cpu`.)
enum { UNIT_COUNT, UNIT_RATE, UNIT_BYTES };

struct limit_desc {
    const char* name;
    int unit;
};

// Indexed by `jaillimit_id`; order must match the enum. Names are the real
// kernel names (cgroup interface filename, or `rlimit.<name>`).
static const limit_desc limit_descs[JLIMIT_COUNT] = {
    { "pids.max",    UNIT_COUNT },
    { "cpu.max",     UNIT_RATE },
    { "memory.max",  UNIT_BYTES },
    { "memory.high", UNIT_BYTES }
};

static int limit_lookup(std::string_view name) {
    for (int i = 0; i != JLIMIT_COUNT; ++i) {
        if (name == limit_descs[i].name) {
            return i;
        }
    }
    return -1;
}

// Parse a run of decimal digits as an unsigned integer, dying on a non-digit or
// on overflow. `s` must be nonempty.
static unsigned long long parse_uint(std::string_view s) {
    if (s.empty()) {
        die("limit: empty number\n");
    }
    unsigned long long v = 0;
    for (char c : s) {
        if (c < '0' || c > '9') {
            die("limit: bad number `%.*s`\n", (int) s.size(), s.data());
        }
        unsigned long long nv = v * 10 + (c - '0');
        if (nv < v) {
            die("limit: number too large `%.*s`\n", (int) s.size(), s.data());
        }
        v = nv;
    }
    return v;
}

// Parse a decimal `s` (optional fractional part) and return floor(value*scale),
// where `scale` is a power of ten. Used to fold a CPU rate like `1.5` or `12.5`
// into a fixed-point integer (millicores). Fractional digits beyond `scale` are
// truncated.
static unsigned long long parse_decimal_scaled(std::string_view s,
                                               unsigned long long scale) {
    size_t dot = s.find('.');
    std::string_view ip = dot == std::string_view::npos ? s : s.substr(0, dot);
    std::string_view fp = dot == std::string_view::npos ? std::string_view() : s.substr(dot + 1);
    if (ip.empty() && fp.empty()) {
        die("limit: bad number `%.*s`\n", (int) s.size(), s.data());
    }
    unsigned long long v = ip.empty() ? 0 : parse_uint(ip) * scale;
    unsigned long long place = scale;
    for (char c : fp) {
        if (c < '0' || c > '9') {
            die("limit: bad number `%.*s`\n", (int) s.size(), s.data());
        }
        place /= 10;
        v += (c - '0') * place;     // `place` is 0 once we pass `scale`'s precision
    }
    return v;
}

// Parse one limit value, e.g. `128`, `1.5`, `50%`, or `unlimited`. Sets
// `*unlimited` for the infinite forms; otherwise returns the value in the
// limit's own unit (a raw count, or millicores for a rate). Dies on any error
// (fail-safe -- a malformed limit never silently becomes "unlimited").
static unsigned long long parse_limit_value(int unit, std::string_view s,
                                            bool& unlimited) {
    unlimited = false;
    if (s == "unlimited" || s == "inf" || s == "max") {
        unlimited = true;
        return 0;
    }
    if (s.empty()) {
        die("limit: empty value\n");
    }
    if (unit == UNIT_RATE) {
        // a CPU rate in cores (`1.5`) or as a percentage of one core (`150%`),
        // stored as millicores (1000 == one core)
        if (s.back() == '%') {
            return parse_decimal_scaled(s.substr(0, s.size() - 1), 10);
        }
        return parse_decimal_scaled(s, 1000);
    }
    if (unit == UNIT_BYTES) {
        // integer with an optional 1024-based unit suffix
        unsigned long long mult = 1;
        switch (s.back()) {
        case 'k': case 'K': mult = 1024ULL; break;
        case 'm': case 'M': mult = 1024ULL * 1024; break;
        case 'g': case 'G': mult = 1024ULL * 1024 * 1024; break;
        }
        if (mult != 1) {
            s.remove_suffix(1);
        }
        unsigned long long v = parse_uint(s);
        if (v > ~0ULL / mult) {
            die("limit: number too large `%.*s`\n", (int) s.size(), s.data());
        }
        return v * mult;
    }
    return parse_uint(s);
}

// Parse a `NAME=VALUE[,NAME=VALUE...]` list, overlaying each named limit onto
// `out` (last write wins). A `!` suffix on a value pins it. Dies on a malformed
// item or an unknown limit name.
static void parse_limits(std::string_view limits, jaillimits& out) {
    while (!limits.empty()) {
        size_t comma = limits.find(',');
        std::string_view item = comma == std::string_view::npos ? limits : limits.substr(0, comma);
        limits.remove_prefix(comma == std::string_view::npos ? limits.size() : comma + 1);

        size_t eq = item.find('=');
        if (eq == std::string_view::npos) {
            die("limit: expected NAME=VALUE in `%.*s`\n", (int) item.size(), item.data());
        }
        std::string_view name = item.substr(0, eq);
        std::string_view val = item.substr(eq + 1);
        bool pinned = false;
        if (!val.empty() && val.back() == '!') {
            pinned = true;
            val.remove_suffix(1);
        }
        int id = limit_lookup(name);
        if (id < 0) {
            die("limit: unknown limit `%.*s`\n", (int) name.size(), name.data());
        }
        bool unlimited = false;
        unsigned long long v = parse_limit_value(limit_descs[id].unit, val, unlimited);
        out[id] = jaillimit{true, unlimited, pinned, v};
    }
}

static std::string_view pop_word(std::string_view& line) {
    const char* s = line.data();
    const char* end = s + line.size();
    if (*s == '[') {
        while (s != end && *s != ']') {
            ++s;
        }
        s += s != end;
    } else {
        while (s != end && !isspace((unsigned char) *s)) {
            ++s;
        }
    }
    std::string_view result(line.data(), s - line.data());
    while (s != end && isspace((unsigned char) *s)) {
        ++s;
    }
    line.remove_prefix(s - line.data());
    return result;
}

void pajailconf::parse(jailperm& perm) const {
    // fail early on bad `perm.dir`
    perm.enabled = perm.skeleton_enabled = false;
    perm.permdir = std::string();
    if (perm.dir.empty()
        || !perm.dir.starts_with('/')
        || !perm.dir.ends_with('/')) {
        return;
    }

    const char* pos = buf_;
    const char* last = buf_ + len_;
    bool allow_jail[2] = {false /* local */, true /* global */};
    bool allow_skeleton[2] = {false, true};
    std::string section;
    bool skip_section = false;

    // permdir (the create boundary) is the SHORTEST matching enablejail prefix:
    // order-independent, and honors the broadest grant so a narrower overlapping
    // rule never shrinks the create zone.
    auto consider_permdir = [&](std::string_view pattern) {
        std::string pd = pathmatch_literal_prefix(pattern);
        if (perm.permdir.empty() || pd.size() < perm.permdir.size()) {
            perm.permdir = pd;
        }
    };

    std::vector<std::string_view> args;
    int lineno = 0;
    while (pos != last) {
        // take one line
        while (pos != last && isspace((unsigned char) *pos) && *pos != '\n') {
            ++pos;
        }
        if (pos == last) {
            break;
        }
        const char* lpos = pos;
        while (lpos != last && *lpos != '\n') {
            ++lpos;
        }
        std::string_view line(pos, lpos);
        pos = lpos + (lpos != last);
        ++lineno;

        // separate into words
        args.clear();
        while (!line.empty() && line[0] != '#') {
            args.push_back(pop_word(line));
        }
        if (args.empty()) {       // blank line or only comment
            continue;
        }

        // check for section
        std::string_view action = args[0];
        if (args.size() == 1
            && action.starts_with('[')
            && action.ends_with(']')) {
            std::string_view inner = action.substr(1, action.size() - 2);
            size_t wend = 0;
            while (wend < inner.size() && !isspace((unsigned char) inner[wend])) {
                ++wend;
            }
            if (inner.substr(0, wend) == "cgroup") {
                // a `[cgroup PATH]` pool section: its directives set pool limits
                // (see pool_limits), which a jaildir query ignores -- skip it
                section = std::string();
                skip_section = true;
            } else if (inner.empty()
                       || inner == "/**"
                       || inner == "**"
                       || inner == "/**/") {
                section = std::string();
                skip_section = false;
            } else {
                section = path_endslash(std::string(inner));
                skip_section = !pathmatch(section, perm.dir);
            }
            continue;
        }
        if (skip_section) {
            continue;
        }

        // the pool this jail joins; a section-scoped `cgroupbase` is gated by the
        // section (via skip_section above), a top-level one is the global default
        if (action == "cgroupbase") {
            if (args.size() != 2) {
                die("cgroupbase: usage `cgroupbase PATH`\n");
            }
            perm.cgroupbase = std::string(args[1]);
            continue;
        }

        // resolve a directory pattern argument the way enable/disable do: an
        // absolute pattern is taken as-is; a relative one is section-relative
        // (and meaningless outside a section). Returns "" to mean "no match".
        auto resolve_dir_pattern = [&](std::string_view arg) -> std::string {
            std::string pattern;
            if (arg.empty() || arg[0] != '/') {
                if (section.empty()) {
                    return std::string();
                }
                pattern = section;
                pattern.append(arg);
            } else {
                pattern = std::string(arg);
            }
            return path_endslash(pattern);
        };

        // resource limits: `limit LIMITS` applies to the current scope (the
        // section's jaildir, or every jail at top level); `limit JDIR LIMITS`
        // applies only on jails matching JDIR (gated additionally by the
        // enclosing section, like an explicit-pattern enablejail).
        if (action == "limit") {
            if (args.size() == 2) {
                parse_limits(args[1], perm.limits);
            } else if (args.size() == 3) {
                std::string pattern = resolve_dir_pattern(args[1]);
                if (!pattern.empty() && pathmatch(pattern, perm.dir)) {
                    parse_limits(args[2], perm.limits);
                }
            } else {
                die("limit: usage `limit [JDIR] NAME=VALUE,...`\n");
            }
            continue;
        }

        // check action
        bool* allowance = nullptr, value = false;
        if (action == "disablejail" || action == "nojail") {
            allowance = allow_jail;
        } else if (action == "enablejail" || action == "allowjail") {
            allowance = allow_jail;
            value = true;
        } else if (action == "disableskeleton") {
            allowance = allow_skeleton;
        } else if (action == "enableskeleton") {
            allowance = allow_skeleton;
            value = true;
        } else {
            continue;
        }

        if (args.size() == 1 && allowance == allow_jail && !section.empty()) {
            // no-arg `enablejail/disablejail` in section uses section as jaildir
            args.push_back(section);
        } else if (args.size() == 1) {
            // control global enabling
            if (allowance == allow_jail && !value && allowance[1]) {
                perm.disabled_lineno = lineno;
            }
            allowance[1] = value;
            continue;
        }

        // otherwise, determine directory
        std::string pattern;
        if (args[1].empty() || args[1][0] != '/') {
            if (section.empty()) {
                continue;
            }
            pattern = section;
            pattern.append(args[1]);
        } else {
            pattern = args[1];
        }
        if (pattern.empty() || pattern.back() != '/') {
            pattern.push_back('/');
        }

        // check subdirectory match
        const auto& d = allowance == allow_jail ? perm.dir : perm.skeletondir;
        if (pathmatch(pattern, d)) {
            if (allowance == allow_jail && value) {
                consider_permdir(pattern);
            } else if (allowance == allow_jail && allowance[0]) {
                perm.disabled_lineno = lineno;
            }
            allowance[0] = value;
        }
    }

    perm.enabled = allow_jail[0] && allow_jail[1];
    if (perm.enabled) {
        perm.disabled_lineno = 0;
    } else {
        perm.permdir = std::string();
    }
    perm.skeleton_enabled = allow_skeleton[0]
        && allow_skeleton[1]
        && perm.skeletondir.starts_with('/')
        && perm.skeletondir.ends_with('/');
}

jaillimits pajailconf::pool_limits(std::string_view path) const {
    jaillimits limits;
    const char* pos = buf_;
    const char* last = buf_ + len_;
    bool in_pool = false;       // inside a `[cgroup PATH]` whose PATH == `path`
    std::vector<std::string_view> args;
    while (pos != last) {
        // take one line (mirrors parse()'s tokenization; pool limits carry no
        // line numbers, so this loop is the simpler half)
        while (pos != last && isspace((unsigned char) *pos) && *pos != '\n') {
            ++pos;
        }
        if (pos == last) {
            break;
        }
        const char* lpos = pos;
        while (lpos != last && *lpos != '\n') {
            ++lpos;
        }
        std::string_view line(pos, lpos);
        pos = lpos + (lpos != last);
        args.clear();
        while (!line.empty() && line[0] != '#') {
            args.push_back(pop_word(line));
        }
        if (args.empty()) {
            continue;
        }

        // a `[cgroup PATH]` header opens/closes a pool scope; we accumulate only
        // while inside one whose PATH literally equals `path`
        std::string_view action = args[0];
        if (args.size() == 1
            && action.starts_with('[')
            && action.ends_with(']')) {
            std::string_view inner = action.substr(1, action.size() - 2);
            size_t wend = 0;
            while (wend < inner.size() && !isspace((unsigned char) inner[wend])) {
                ++wend;
            }
            in_pool = false;
            if (inner.substr(0, wend) == "cgroup") {
                std::string_view arg = inner.substr(wend);
                while (!arg.empty() && isspace((unsigned char) arg.front())) {
                    arg.remove_prefix(1);
                }
                while (!arg.empty() && isspace((unsigned char) arg.back())) {
                    arg.remove_suffix(1);
                }
                in_pool = arg == path;
            }
            continue;
        }
        if (!in_pool) {
            continue;
        }

        // pool limits take only the `limit NAME=VALUE,...` form (no JDIR axis).
        // They are cgroup-controller limits; `rlimit.*` would be rejected here
        // once those names exist.
        if (action == "limit") {
            if (args.size() == 2) {
                parse_limits(args[1], limits);
            } else {
                die("limit: pool limits take `limit NAME=VALUE,...` (no JDIR)\n");
            }
        }
    }
    return limits;
}
