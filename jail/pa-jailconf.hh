// pa-jailconf.hh -- Peteramati structure for pa-jail.conf files
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#pragma once
#include <string>
#include <string_view>

// pa-jail.conf

// A `pajailconf` query and its result. (`dir`, `skeletondir`) are the inputs --
// the jail directory and an optional skeleton; the other fields are filled in
// by `parse()`. `enabled`/`skeleton_enabled` say whether each is permitted; for
// an enabled jail `permdir` is the create boundary (the shortest literal prefix
// among the matching `enablejail` globs, below which pa-jail may create
// components). If `!enabled`, `disabled_lineno` is the 1-based line of the
// responsible `disablejail` (0 if none -- e.g. never enabled), used to explain
// it.
struct jailperm {
    std::string dir;
    std::string skeletondir;
    std::string permdir;
    bool enabled = false;
    bool skeleton_enabled = false;
    int disabled_lineno = 0;

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

    inline jailperm get(std::string dir, std::string skeletondir = std::string()) const {
        jailperm perm(std::move(dir), std::move(skeletondir));
        parse(perm);
        return perm;
    }

private:
    char buf_[8192];
    size_t len_;
};
