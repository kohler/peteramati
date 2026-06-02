// pa-jailconf.hh -- Peteramati structure for pa-jail.conf files
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#pragma once
#include <string>
#include <string_view>

// pa-jail.conf

// Result of a `pajailconf` query. `allowed` says whether the queried directory
// is permitted; for an allowed jail, `permdir` is the permission directory --
// the shortest literal prefix among the matching `enablejail` globs, below which
// pa-jail may create components. When a jail is denied, `disabled_lineno` is the
// 1-based config line of the `disablejail` directive responsible (0 if none --
// e.g. the jail was simply never enabled), used to explain the denial. It tracks
// only the jaildir axis, never the skeleton.
struct jailperm {
    bool allowed = false;
    std::string skeletondir;
    std::string permdir;
    int disabled_lineno = 0;

    explicit operator bool() const {
        return allowed;
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
    pajailconf(const std::string& str);

    jailperm get(std::string dir, std::string skeletondir = std::string()) const;

private:
    char buf_[8192];
    size_t len_;
};
