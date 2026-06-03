// pa-jailconf.cc -- Peteramati structure for pa-jail.conf files
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#include "pa-jailconf.hh"
#include "pa-jutil.hh"
#include <format>
#include <cstring>
#include <cerrno>
#include <vector>
#include <fcntl.h>
#include <unistd.h>
#include <sys/stat.h>

namespace {
struct pajailconf_parser {
    std::string_view confstr;
    size_t pos = 0;
    int lineno = 0;
    std::vector<std::string_view> args;

    pajailconf_parser(std::string_view confstr_)
        : confstr(confstr_) {
    }
    explicit constexpr operator bool() const {
        return pos != confstr.size();
    }
    void next();

    // Return a `pajailconf_error` attributed to the current line, for the
    // caller to throw.
    template <typename... Args>
    pajailconf_error error(std::format_string<Args...> format, Args&&... args) const {
        return pajailconf_error(std::format(format, std::forward<Args>(args)...), lineno);
    }

    // Limit parsing, with errors attributed to the current line.
    void parse_limits(std::string_view limits, jaillimits& out) const;
    unsigned long long parse_limit_value(int unit, std::string_view s, bool& unlimited) const;
    unsigned long long parse_uint(std::string_view s) const;
    unsigned long long parse_decimal_scaled(std::string_view s, unsigned long long scale) const;
};

// Pop the next whitespace-separated word from `line`. A `[...]` bracket
// expression -- a glob character class, or a whole `[SECTION]` header -- is kept
// as one word, with nesting and `\` escapes honored, so whitespace inside it
// does not split the word.
static std::string_view pop_word(std::string_view& line) {
    const char* s = line.data();
    const char* end = s + line.size();
    int bdepth = 0;
    while (s != end && (bdepth > 0 || !isspace((unsigned char) *s))) {
        if (*s == '[') {
            ++bdepth;
        } else if (*s == ']' && bdepth > 0) {
            --bdepth;
        } else if (*s == '\\' && s + 1 != end) {
            ++s;
        }
        ++s;
    }
    std::string_view result(line.data(), s - line.data());
    while (s != end && isspace((unsigned char) *s)) {
        ++s;
    }
    line.remove_prefix(s - line.data());
    return result;
}

static void split_words(std::vector<std::string_view>& words, std::string_view str) {
    while (!str.empty() && isspace((unsigned char) str[0])) {
        str.remove_prefix(1);
    }
    words.clear();
    while (!str.empty()) {
        words.push_back(pop_word(str));
    }
}

void pajailconf_parser::next() {
    const char* s = confstr.data() + pos;
    const char* end = confstr.data() + confstr.size();
    while (s != end
           && isspace((unsigned char) *s)
           && *s != '\n') {
        ++s;
    }
    const char* linestart = s;
    while (s != end
           && *s != '\n') {
        ++s;
    }
    pos = (s - confstr.data()) + (s != end);
    ++lineno;

    // separate into words
    std::string_view line(linestart, s - linestart);
    args.clear();
    while (!line.empty() && line[0] != '#') {
        args.push_back(pop_word(line));
    }
}
}

static bool writable_only_by_root(const struct stat& st) {
    return st.st_uid == ROOT
        && (st.st_gid == ROOT || !(st.st_mode & S_IWGRP))
        && !(st.st_mode & S_IWOTH);
}

pajailconf::pajailconf() {
    int fd = open("/etc/pa-jail.conf", O_RDONLY | O_NOFOLLOW);
    if (fd == -1) {
        throw pajailconf_error(std::format("/etc/pa-jail.conf: {}", strerror(errno)));
    }

    struct stat st;
    if (fstat(fd, &st) != 0) {
        throw pajailconf_error(std::format("/etc/pa-jail.conf: {}", strerror(errno)));
    } else if (!writable_only_by_root(st)) {
        throw pajailconf_error("/etc/pa-jail.conf: Writable by non-root");
    }

    ssize_t nr = read(fd, buf_, sizeof(buf_));
    if (nr < 0) {
        throw pajailconf_error(std::format("/etc/pa-jail.conf: {}", strerror(errno)));
    } else if (nr == 0) {
        throw pajailconf_error("/etc/pa-jail.conf: Empty file");
    } else if (nr == sizeof(buf_)) {
        throw pajailconf_error(std::format("/etc/pa-jail.conf: Too big, max {} bytes", sizeof(buf_)));
    }
    len_ = nr;

    close(fd);
}

pajailconf::pajailconf(std::string_view s) {
    if (s.size() >= sizeof(buf_)) {
        throw pajailconf_error(std::format("pajailconf: String too big, max {} bytes", sizeof(buf_)));
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

// Parse a run of decimal digits as an unsigned integer, throwing on a non-digit
// or on overflow. `s` must be nonempty.
unsigned long long pajailconf_parser::parse_uint(std::string_view s) const {
    if (s.empty()) {
        throw error("limit: Empty number");
    }
    unsigned long long v = 0;
    for (char c : s) {
        if (c < '0' || c > '9') {
            throw error("limit: Bad number `{}`", s);
        }
        unsigned long long nv = v * 10 + (c - '0');
        if (nv < v) {
            throw error("limit: Number too large `{}`", s);
        }
        v = nv;
    }
    return v;
}

// Parse a decimal `s` (optional fractional part) and return floor(value*scale),
// where `scale` is a power of ten. Used to fold a CPU rate like `1.5` or `12.5`
// into a fixed-point integer (millicores). Fractional digits beyond `scale` are
// truncated.
unsigned long long pajailconf_parser::parse_decimal_scaled(std::string_view s,
                                                           unsigned long long scale) const {
    size_t dot = s.find('.');
    std::string_view ip = dot == std::string_view::npos ? s : s.substr(0, dot);
    std::string_view fp = dot == std::string_view::npos ? std::string_view() : s.substr(dot + 1);
    if (ip.empty() && fp.empty()) {
        throw error("limit: Bad number `{}`", s);
    }
    unsigned long long v = ip.empty() ? 0 : parse_uint(ip) * scale;
    unsigned long long place = scale;
    for (char c : fp) {
        if (c < '0' || c > '9') {
            throw error("limit: Bad number `{}`", s);
        }
        place /= 10;
        v += (c - '0') * place;     // `place` is 0 once we pass `scale`'s precision
    }
    return v;
}

// Parse one limit value, e.g. `128`, `1.5`, `50%`, or `unlimited`. Sets
// `*unlimited` for the infinite forms; otherwise returns the value in the
// limit's own unit (a raw count, or millicores for a rate). Throws on any error
// (fail-safe -- a malformed limit never silently becomes "unlimited").
unsigned long long pajailconf_parser::parse_limit_value(int unit, std::string_view s,
                                                        bool& unlimited) const {
    unlimited = false;
    if (s == "unlimited" || s == "inf" || s == "max") {
        unlimited = true;
        return 0;
    }
    if (s.empty()) {
        throw error("limit: Empty value");
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
            throw error("limit: Number too large `{}`", s);
        }
        return v * mult;
    }
    return parse_uint(s);
}

// Parse a `NAME=VALUE[,NAME=VALUE...]` list, overlaying each named limit onto
// `out` (last write wins). A `!` suffix on a value pins it. Throws on a
// malformed item or an unknown limit name.
void pajailconf_parser::parse_limits(std::string_view limits, jaillimits& out) const {
    while (!limits.empty()) {
        size_t comma = limits.find(',');
        std::string_view item = comma == std::string_view::npos ? limits : limits.substr(0, comma);
        limits.remove_prefix(comma == std::string_view::npos ? limits.size() : comma + 1);

        size_t eq = item.find('=');
        if (eq == std::string_view::npos) {
            throw error("limit: Expected NAME=VALUE in `{}`", item);
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
            throw error("limit: Unknown limit `{}`", name);
        }
        bool unlimited = false;
        unsigned long long v = parse_limit_value(limit_descs[id].unit, val, unlimited);
        out[id] = jaillimit{true, unlimited, pinned, v};
    }
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

    pajailconf_parser parser({buf_, len_});
    std::vector<std::string_view> words;
    while (parser) {
        parser.next();
        if (parser.args.empty()) {    // blank line or only comment
            continue;
        }

        // check for section
        std::string_view action = parser.args[0];
        if (parser.args.size() == 1
            && action.starts_with('[')
            && action.ends_with(']')) {
            split_words(words, action.substr(1, action.size() - 2));
            if (words.empty()) {
                words.push_back("/**");
            }
            if (words.size() > 2
                || (words.size() == 2 && words[0] != "cgroup")) {
                throw parser.error("Bad `[...]` section header");
            } else if (words[0] == "cgroup") {
                section = std::string();
                skip_section = true;
            } else if (words[0] == "/**"
                       || words[0] == "**"
                       || words[0] == "/**/") {
                // `[]` or a whole-tree wildcard resets to global scope
                section = std::string();
                skip_section = false;
            } else {
                section = path_endslash(words[0]);
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
            if (parser.args.size() != 2) {
                throw parser.error("Expected `cgroupbase PATH`");
            }
            perm.cgroupbase = std::string(parser.args[1]);
            continue;
        }

        // resolve a directory pattern argument the way enable/disable do: an
        // absolute pattern is taken as-is; a relative one is section-relative
        // (and meaningless outside a section). Returns "" to mean "no match".
        auto resolve_dir_pattern = [&](std::string_view arg) -> std::string {
            std::string pattern;
            if (arg.empty() || arg[0] != '/') {
                if (section.empty()) {
                    return std::string(); // `pathmatch("", dir)` always fails
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
            if (parser.args.size() != 2 && parser.args.size() != 3) {
                throw parser.error("Expected `limit [JDIR] NAME=VALUE,...`");
            }
            if (parser.args.size() == 2
                || pathmatch(resolve_dir_pattern(parser.args[1]), perm.dir)) {
                parser.parse_limits(parser.args.back(), perm.limits);
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

        if (parser.args.size() == 1 && allowance == allow_jail && !section.empty()) {
            // no-arg `enablejail/disablejail` in section uses section as jaildir
            parser.args.push_back(section);
        } else if (parser.args.size() == 1) {
            // control global enabling
            if (allowance == allow_jail && !value && allowance[1]) {
                perm.disabled_lineno = parser.lineno;
            }
            allowance[1] = value;
            continue;
        }

        // otherwise, determine directory
        std::string pattern;
        if (parser.args[1].empty() || parser.args[1][0] != '/') {
            if (section.empty()) {
                continue;
            }
            pattern = section;
            pattern.append(parser.args[1]);
        } else {
            pattern = parser.args[1];
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
                perm.disabled_lineno = parser.lineno;
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
    bool in_pool = false;       // inside a `[cgroup]`/`[cgroup PATH]` for `path`
    pajailconf_parser parser({buf_, len_});
    std::vector<std::string_view> words;
    while (parser) {
        parser.next();
        if (parser.args.empty()) {
            continue;
        }

        // a `[cgroup PATH]` header opens a pool scope for that one pool, a bare
        // `[cgroup]` for every pool; we accumulate while inside one for `path`
        std::string_view action = parser.args[0];
        if (parser.args.size() == 1
            && action.starts_with('[')
            && action.ends_with(']')) {
            split_words(words, action.substr(1, action.size() - 2));
            in_pool = !words.empty()
                && words[0] == "cgroup"
                && (words.size() == 1
                    || (words.size() == 2 && words[1] == path));
            continue;
        }
        if (!in_pool) {
            continue;
        }

        // pool limits take only the `limit NAME=VALUE,...` form (no JDIR axis).
        // They are cgroup-controller limits; `rlimit.*` would be rejected here
        // once those names exist.
        if (action == "limit") {
            if (parser.args.size() != 2) {
                throw parser.error("Expected `limit NAME=VALUE,...` in cgroup section");
            }
            parser.parse_limits(parser.args[1], limits);
        }
    }
    return limits;
}
