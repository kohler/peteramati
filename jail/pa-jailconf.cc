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
            if (action.size() == 2
                || action == "[/**]"
                || action == "[**]"
                || action == "[/**/]") {
                section = std::string();
                skip_section = false;
            } else {
                section = path_endslash(std::string(action.substr(1, action.size() - 2)));
                skip_section = !pathmatch(section, perm.dir);
            }
            continue;
        }
        if (skip_section) {
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
