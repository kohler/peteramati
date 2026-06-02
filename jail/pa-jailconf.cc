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

jailperm pajailconf::get(std::string dir, std::string skeletondir) const {
    const char* pos = buf_;
    const char* last = buf_ + len_;
    bool allow_jail[2] = {false /* local */, true /* global */};
    bool allow_skeleton[2] = {false, true};
    jailperm perm;
    dir = path_endslash(dir);
    if (!skeletondir.empty()) {
        skeletondir = path_endslash(skeletondir);
    }

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

        if (arg.empty()) {
            // global allowance
            allowance[1] = value;
            perm.disabled_by = std::string();
        } else if (arg[0] == '/') {
            // check subdirectory match
            auto pattern = path_endslash(arg);
            auto d = allowance == allow_jail ? dir : skeletondir;
            if (pathmatch(pattern, d)) {
                allowance[0] = value;
                perm.disabled_by = pattern;
                if (value && allowance == allow_jail) {
                    perm.permdir = pathmatch_literal_prefix(pattern);
                }
            }
        }
    }

    perm.allowed = allow_jail[0] && allow_jail[1];
    if (perm.allowed) {
        perm.disabled_by = std::string();
    } else {
        perm.permdir = std::string();
    }
    if (allow_skeleton[0] && allow_skeleton[1]) {
        perm.skeletondir = skeletondir;
    }
    return perm;
}
