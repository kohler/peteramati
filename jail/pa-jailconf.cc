// pa-jailconf.cc -- Peteramati structure for pa-jail.conf files
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#include "pa-jailconf.hh"
#include "pa-jutil.hh"
#include <cstring>
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

pajailconf::pajailconf(const std::string& s) {
    if (s.size() >= sizeof(buf_)) {
        die("pajailconf: String too big, max %zu bytes\n", sizeof(buf_));
    }
    memcpy(buf_, s.data(), s.size());
    len_ = s.size();
}

static bool check_action(std::string_view action,
                         std::string_view prefix,
                         std::string_view type) {
    return action.size() == prefix.size() + type.size()
        && action.starts_with(prefix)
        && action.ends_with(type);
}

// Match `pattern` against `str` like the rest of pa-jail.conf. When `superdir`,
// also walk `pattern` and `str` slash-by-slash, truncate `str` to `pattern`'s
// component count, and store that ancestor through `store_superdir`; the match
// then tests the pattern against that ancestor (so the pattern matches `str` or
// any directory below it). Uses `pathmatch`, so behavior matches the historical
// `fnmatch(FNM_PATHNAME | FNM_PERIOD)` except where a pattern contains `**`.
static bool check_dirmatch(const std::string& pattern,
                           std::string str,
                           bool superdir = false,
                           std::string* store_superdir = nullptr) {
    if (superdir) {
        size_t patslashpos = 0, strslashpos = 0;
        while (true) {
            patslashpos = pattern.find('/', patslashpos);
            if (patslashpos == std::string::npos) {
                str = str.substr(0, strslashpos);
                if (store_superdir) {
                    *store_superdir = str;
                }
                break;
            }
            ++patslashpos;
            strslashpos = str.find('/', strslashpos);
            if (strslashpos == std::string::npos) {
                return false;
            }
            ++strslashpos;
        }
    }
    return pathmatch(pattern, str);
}

static std::string_view pop_word(std::string_view& line) {
    const char* pos = line.data();
    const char* last = pos + line.size();
    while (pos != last && isspace((unsigned char) *pos)) {
        ++pos;
    }
    const char* wpos = pos;
    while (pos != last && !isspace((unsigned char) *pos)) {
        ++pos;
    }
    line.remove_prefix(pos - line.data());
    return std::string_view(wpos, pos - wpos);
}

// Update `treedir` to the shortest matching permission directory for `str`.
static void set_treedir(std::string& treedir, std::string pattern,
                        const std::string& str, bool is_explicit) {
    if (!is_explicit
        && pattern.size() > 3
        && pattern.ends_with("/*/")) {
        pattern = pattern.substr(0, pattern.size() - 2);
    }
    std::string superdir;
    if (check_dirmatch(pattern, str, true, &superdir)
        && (treedir.empty() || treedir.size() > superdir.size())) {
        treedir = superdir;
    }
}

jailperm pajailconf::check_type(std::string_view type,
                                std::string dir,
                                bool superdir) const {
    const char* pos = buf_;
    const char* last = buf_ + len_;
    int allowed_globally = -1, allowed_locally = -1;
    jailperm perm;
    dir = path_endslash(dir);

    while (pos != last) {
        // take one line
        while (pos != last && isspace((unsigned char) *pos)) {
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
        pos = lpos;

        // pop action and argument
        auto action = pop_word(line);
        auto arg = pop_word(line);

        // check action
        int allowed;
        if (check_action(action, "disable", type)
            || check_action(action, "no", type)) {
            allowed = 0;
        } else if (check_action(action, "enable", type)
                   || check_action(action, "allow", type)) {
            allowed = 1;
        } else if (check_action(action, "treedir", "")) {
            if (!arg.empty() && arg[0] == '/') {
                auto pattern = path_endslash(std::string(arg));
                set_treedir(perm.treedir, pattern, dir, true);
            }
            continue;
        } else {
            continue;
        }

        if (arg.empty()) {
            // global allowance
            allowed_globally = allowed;
            if (!allowed) {
                allowed_locally = allowed;
            }
            perm.disabled_by = std::string();
        } else if (arg[0] == '/') {
            // check subdirectory match
            auto pattern = path_endslash(std::string(arg));
            if (check_dirmatch(pattern, dir, superdir || allowed <= 0)) {
                allowed_locally = allowed;
                perm.disabled_by = pattern;
                if (allowed > 0) {
                    set_treedir(perm.treedir, pattern, dir, false);
                }
            }
        }
    }

    perm.allowed = allowed_globally != 0 && allowed_locally > 0;
    if (perm.allowed) {
        perm.disabled_by = std::string();
    } else {
        perm.treedir = std::string();
    }
    return perm;
}
