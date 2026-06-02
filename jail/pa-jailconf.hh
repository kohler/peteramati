// pa-jailconf.hh -- Peteramati structure for pa-jail.conf files
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#pragma once
#include <string>
#include <string_view>

// pa-jail.conf

// Result of a `pajailconf` query. `allowed` says whether the queried directory
// is permitted; for an allowed jail, `permdir` is the permission directory --
// the literal prefix of the matching `enablejail` glob, below which pa-jail may
// create components. `disabled_by` names the matching glob, used to explain a
// denial.
struct jailperm {
    bool allowed = false;
    std::string skeletondir;
    std::string permdir;
    std::string disabled_by;

    explicit operator bool() const {
        return allowed;
    }
    // Error-message fragment naming the responsible glob, or "" if none.
    std::string disable_message() const {
        if (!disabled_by.empty()) {
            return "  (disabled by " + disabled_by + ")\n";
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
