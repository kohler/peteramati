// pa-jutil.cc -- Peteramati helper functions for pa-jail
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#include "pa-jutil.hh"
#include <algorithm>
#include <cassert>
#include <cstdarg>
#include <cstdio>
#include <cstring>
#include <unistd.h>

int exit_status = 0;

int perror_fail(const char* format, const char* arg1) {
    fprintf(stderr, format, arg1, strerror(errno));
    ::exit_status = 1;
    return 1;
}

void die(const char* fmt, ...) {
    va_list val;
    va_start(val, fmt);
    vfprintf(stderr, fmt, val);
    va_end(val);
    exit(1);
}

void perror_die(const char* message) {
    die("%s: %s\n", message, strerror(errno));
}

std::string path_parentdir(const std::string& path) {
    size_t npos = path.size();
    while (npos > 1 && path[npos - 1] == '/') {
        --npos;
    }
    while (npos > 1 && path[npos - 1] != '/') {
        --npos;
    }
    return path.substr(0, npos);
}

std::string shell_quote(const std::string& argument) {
    if (argument.empty()) {
        // An empty word must be quoted: bare emptiness would vanish from the
        // command line rather than survive as an empty argument.
        return "''";
    }
    std::string quoted;         // quoted string prefix (if quotes required)
    size_t from = 0;            // first index not yet appended to `quoted`
    for (size_t pos = 0; pos != argument.size(); ++pos) {
        // skip safe characters
        if (isalnum((unsigned char) argument[pos])
            || argument[pos] == '_'
            || argument[pos] == '-'
            || argument[pos] == '.'
            || argument[pos] == '/'
            || (argument[pos] == '~' && pos != 0)) {
            continue;
        }
        // otherwise, quotes required
        if (quoted.empty()) {
            quoted = "'";
        }
        // a single quote cannot appear inside a '...' span: flush the
        // pending run, then close/escape/reopen the quote as '\''
        if (argument[pos] == '\'') {
            quoted += argument.substr(from, pos - from) + "'\\''";
            from = pos + 1;
        }
    }
    if (quoted.empty()) {
        // no special characters: use argument as-is
        return argument;
    }
    // flush the trailing run and close the open quote
    quoted += argument.substr(from) + "'";
    return quoted;
}

// Check a potential character class match starting at `pp` against the string
// starting at `sp`. Return 0 on no match, the (positive) number of pattern
// characters in the character class on successful match, and -1 if `[pp, pe)`
// does not contain a valid character class.
static size_t pathmatch_classcheck(const char* pp, const char* pe,
                                   const char* sp, const char* se,
                                   const char* scomponent) {
    if (sp == se
        || *sp == '/'
        || (*sp == '.' && sp == scomponent)) {
        return 0;
    }
    const char* cps = pp + 1;
    bool negated = cps != pe && (*cps == '!' || *cps == '^');
    if (negated) {
        ++cps;
    }
    const char* cpe = cps;
    if (cpe != pe && *cpe == ']') {
        ++cpe;
    }
    while (cpe != pe && *cpe != ']') {
        cpe += 1 + (*cpe == '\\' && cpe + 1 != pe);
    }
    if (cpe == pe) {
        return -1;
    }
    // NB: The range [cps, cpe) does not end with an unescaped backslash.
    // (We eliminated that case above.)
    while (cps != cpe) {
        unsigned char c1 = *cps;
        if (c1 == '\\') {
            ++cps;
            c1 = *cps;
        }
        ++cps;
        unsigned char c2 = c1;
        if (cps != cpe && *cps == '-' && cps + 1 != cpe) {
            ++cps;
            c2 = *cps;
            if (c2 == '\\') {
                ++cps;
                c2 = *cps;
            }
        }
        if ((unsigned char) *sp >= c1
            && (unsigned char) *sp <= c2) {
            return negated ? 0 : cpe + 1 - pp;
        }
    }
    return negated ? cpe + 1 - pp : 0;
}

bool pathmatch(std::string_view pattern, std::string_view str) {
    const char* pp = pattern.data();
    const char* pe = pp + pattern.size();
    const char* sp = str.data();
    const char* se = sp + str.size();

    // check path
    const char* pstar = pp;
    const char* sstar = nullptr;
    const char* pstarstar = pp;
    const char* sstarstar = nullptr;
    const char* scomponent = sp;
    int classcheck;
    while (pp != pe || sp != se) {
        if (pp != pe) {
            if (*pp == '*') {
                if (pp + 1 != pe
                    && pp[1] == '*'
                    && (pp + 2 == pe || pp[2] == '/')
                    && (pp == pattern.data() || pp[-1] == '/')) {
                    pstarstar = pp;
                    sstarstar = sp;
                    pp += 2 + (pp != pattern.data() && pp + 2 != pe);
                    continue;
                }
                if (sp != scomponent
                    || sp == se
                    || *sp != '.') {
                    pstar = pp;
                    sstar = sp;
                    ++pp;
                    continue;
                }
            } else if (*pp == '?') {
                if (sp != se
                    && *sp != '/'
                    && (sp != scomponent || *sp != '.')) {
                    ++pp;
                    ++sp;
                    continue;
                }
            } else if (*pp == '['
                       && (classcheck = pathmatch_classcheck(pp, pe, sp, se, scomponent)) >= 0) {
                if (classcheck > 0) {
                    pp += classcheck;
                    ++sp;
                    continue;
                }
            } else { // normal character, possibly escaped
                if (*pp == '\\' && pp + 1 != pe) {
                    ++pp;
                }
                if (sp != se && *pp == *sp) {
                    if (*sp == '/') {
                        scomponent = sp + 1;
                        sstar = nullptr;
                    }
                    ++pp;
                    ++sp;
                    continue;
                }
            }
        }
        // match failed: advance * or **
        if (sstar != nullptr
            && sstar != se
            && *sstar != '/') {
            pp = pstar;
            sp = sstar + 1;
            continue;
        }
        if (sstarstar != nullptr
            && sstarstar != se) {
            pp = pstarstar;
            sp = sstarstar + 1;
            while (sp != se
                   && *sp != '/') {
                ++sp;
            }
            if (sp != se) {
                scomponent = sp = sp + 1;
                sstar = nullptr;
            }
            continue;
        }
        return false;
    }
    return true;
}

std::string pathmatch_literal_prefix(std::string_view pattern) {
    std::string prefix;
    size_t from = 0, pos = 0, slash = 0, pslash = 0;
    while (pos != pattern.size()) {
        char ch = pattern[pos];
        if (ch == '*' || ch == '?' || ch == '[') {
            if (slash < from) {
                prefix.resize(pslash);
            }
            pos = slash;
            break;
        }
        if (ch == '\\' && pos + 1 != pattern.size()) {
            if (from < slash) {
                pslash = prefix.size() + slash - from;
            }
            prefix.append(pattern.data() + from, pos - from);
            from = pos = pos + 1;
            ch = pattern[pos];
        }
        if (ch == '/') {
            slash = pos + 1;
        }
        ++pos;
    }
    if (from < pos) {
        prefix.append(pattern.data() + from, pos - from);
    }
    return path_endslash(prefix);
}

std::string path_absolute(std::string_view path, std::string_view cwd) {
    // check for absolute path
    if (!path.empty() && path[0] == '/') {
        return std::string(path);
    }
    // obtain cwd
    char buf[BUFSIZ];
    if (cwd.empty()) {
        if (getcwd(buf, BUFSIZ - 1) == nullptr) {
            perror_die("getcwd");
        }
        char* endbuf = buf + strlen(buf);
        while (endbuf - buf > 1 && endbuf[-1] == '/') {
            --endbuf;
        }
        *endbuf = '/';
        cwd = std::string_view(buf, endbuf + 1);
    }
    assert(!cwd.empty() && cwd.front() == '/' && cwd.back() == '/');
    // canonicalize `cwd + path`
    while (true) {
        if (path.starts_with("/")) {
            // `path` contained multiple slashes, e.g. `.//`
            path.remove_prefix(1);
        } else if (path.starts_with("./") || path == ".") {
            path.remove_prefix(std::min(size_t(2), path.size()));
        } else if (path.starts_with("../") || path == "..") {
            // remove final subdirectory
            while (cwd.size() > 1 && cwd.back() == '/') {
                cwd.remove_suffix(1);
            }
            while (cwd.size() > 1 && cwd.back() != '/') {
                cwd.remove_suffix(1);
            }
            path.remove_prefix(std::min(size_t(3), path.size()));
        } else {
            break;
        }
    }
    // return
    std::string result(cwd);
    result += path;
    return result;
}

std::string path_pa_validate(std::string_view name) {
    char buf[1024];
    if (name.empty()
        || name[0] == '~'
        || name.length() >= sizeof(buf)) {
        return std::string();
    }

    char* out = buf;
    const char* end = name.data() + name.size();
    for (const char* s = name.data(); s != end; ++s) {
        unsigned char ch = *s;
        if (ch == '.') {
            if ((s + 1 == end || s[1] == '/')
                && (out == buf || out[-1] == '/')) {
                // remove `/./` segments
                if (out != buf) {
                    --out;
                } else {
                    while (s + 1 != end && s[1] == '/') {
                        ++s;
                    }
                }
                continue;
            } else if (s + 1 != end
                       && s[1] == '.'
                       && (s + 2 == end || s[2] == '/')
                       && (out == buf || out[-1] == '/')) {
                // disallow `/../` segments
                return std::string();
            }
            // otherwise normal character
        } else if (ch == '/') {
            // condense `/+` into `/`
            while (s + 1 != end && s[1] == '/') {
                ++s;
            }
        } else if ((ch >= '-' && ch <= '9')  // `-./0123456789`
                   || (ch >= 'A' && ch <= 'Z')
                   || (ch >= 'a' && ch <= 'z')
                   || ch == '_'
                   || ch == '~') {
            // OK
        } else {
            return std::string();
        }
        *out++ = ch;
    }
    while (out > buf + 1 && out[-1] == '/') {
        --out;
    }
    return std::string(buf, out - buf);
}
