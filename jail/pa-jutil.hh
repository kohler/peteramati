// pa-jutil.hh -- Peteramati helper functions for pa-jail, test-pa-jail
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#pragma once
#include <string>

#define ROOT 0


// error helpers

// Track whether an error occurred; initially 0.
extern int exit_status;

// Print an error message to stderr, set `exit_status = 1`, and return 1.
// `format` may contain two `%s` format strings; the first argument is `arg1`,
// the second argument is `strerror(errno)`.
int perror_fail(const char* format, const char* arg1);

// Print an error message to stderr and `exit(1)`.
[[noreturn]] void die(const char* fmt, ...);

// Print an error message `{message}: {strerror(errno)}` to stderr and exit(1).
[[noreturn]] void perror_die(const char* message);

[[noreturn]] inline void perror_die(const std::string& message) {
    perror_die(message.c_str());
}


// pathname helpers

// Return `path` with a slash appended; always returns nonempty.
inline std::string path_endslash(std::string path) {
    if (path.empty() || path.back() != '/') {
        path += "/";
    }
    return path;
}

// Return `path` with a slash appended; always returns nonempty.
inline std::string path_endslash(std::string_view path) {
    return path_endslash(std::string(path));
}

// Return `path` with all trailing slashes removed; may return empty.
inline std::string path_noendslash(std::string path) {
    while (path.size() > 1 && path.back() == '/') {
        path = path.substr(0, path.size() - 1);
    }
    return path;
}

// Return the parent directory of `path`, ending in a slash.
// Examples: `a/b/c`→`a/b/`; `/`→`/`.
std::string path_parentdir(const std::string& path);

// Return an absolute version of `path`. If `path` starts with a slash,
// returns `path`; otherwise returns the current working directory with `path`
// appended. Initial `./` and `../` segments of `path` are removed
// mechanically, but `./` and `../` segments embedded within `path` remain.
std::string path_absolute(std::string_view path, std::string_view cwd = std::string_view());

// Return `path` validated according to Peteramati rules. Characters not in
// `[-./0-9A-Za-z_~]` are disallowed; `path` cannot be more than 1024
// characters long; `/+` is transformed to `/`; `./` path segments are
// removed; `../` path segments are disallowed; initial `~` is disallowed.
// Returns an empty string on invalid `path`.
std::string path_pa_validate(std::string_view path);

// Return a shell-safe rendering of `argument`. An argument made up entirely of
// "safe" characters is returned verbatim (no quoting); anything else is wrapped
// in single quotes. Examples: `a`→`a`; `a b`→`'a b'`; `a'b`→`'a'\''b'`.
std::string shell_quote(const std::string& argument);

// Match `str` against `pattern` like `fnmatch(pattern, str,
// FNM_PATHNAME | FNM_PERIOD)`, with one extension: a pattern component that is
// exactly `**` matches any run of zero or more path components (i.e. it matches
// across `/`). Every other component matches a single path component using
// `*` (>= 0 non-`/` chars), `?` (one non-`/` char), `[...]` bracket
// expressions (members, `a-z` ranges, leading `!`/`^` negation), and literals;
// an ordinary `*` does not cross `/`, and a leading `.` in a component must be
// matched literally. A `**` component matches any components, including ones
// that begin with `.`. Matching is bytewise (no locale/UTF-8 awareness), so a
// bracket matches a single byte. Slashes are matched exactly: leading,
// trailing, and doubled slashes are significant, so `/foo` does not match
// `/foo/`. Returns true on match.
bool pathmatch(std::string_view pattern, std::string_view str);

// Return the literal directory prefix of `pattern`: everything up to and
// including the last `/` that precedes the first wildcard character (`*`, `?`,
// or `[`). If `pattern` has no wildcard it is returned whole, with a trailing
// `/` added if absent. The result always ends in `/`. This is the deepest
// directory that any path matching `pattern` is guaranteed to share.
std::string pathmatch_literal_prefix(std::string_view pattern);
