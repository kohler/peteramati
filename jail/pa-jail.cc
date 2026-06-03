// pa-jail.cc -- Peteramati program sets up a jail for student code
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#include <sys/types.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <sys/mount.h>
#include <sys/select.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <sys/un.h>
#include <unistd.h>
#include <cassert>
#include <cstdio>
#include <cstdlib>
#include <cstdarg>
#include <cstring>
#include <cerrno>
#include <cmath>
#include <csignal>
#include <poll.h>
#include <dirent.h>
#include <termios.h>
#include <pwd.h>
#include <grp.h>
#include <fcntl.h>
#include <utime.h>
#include <getopt.h>
#include <fnmatch.h>
#include <string>
#include <list>
#include <unordered_map>
#include <vector>
#include <optional>
#include <iostream>
#include <sys/ioctl.h>
#include <sys/file.h>
#if __linux__
#include <mntent.h>
#include <sched.h>
#include <linux/sched.h>        // struct clone_args, CLONE_INTO_CGROUP
#include <sys/signalfd.h>
#include <sys/sysmacros.h>
#include <sys/syscall.h>
#include <sys/prctl.h>
#elif __APPLE__
#include <sys/param.h>
#include <sys/ucred.h>
#include <sys/mount.h>
#endif
#include "pa-jailconf.hh"
#include "pa-jutil.hh"

#define FLAG_CP       1        // copy even if source is symlink
#define FLAG_BIND     2
#define FLAG_BIND_RO  4
#define FLAG_MOUNT    8

#ifndef O_PATH
#define O_PATH 0
#endif
#ifndef MS_REMOUNT
#define MS_REMOUNT 0
#endif

typedef std::pair<dev_t, ino_t> devino;
namespace std { template <> struct hash<devino> {
    std::size_t operator()(const devino& di) const {
        return di.second | (di.first << (sizeof(std::size_t) - 8));
    }
}; }

static uid_t caller_owner;
static gid_t caller_group;

static std::unordered_map<std::string, int> dirtable;
static std::unordered_map<std::string, int> dst_table;
static std::unordered_map<devino, std::string> devino_table;
static bool verbose = false;
static bool dryrun = false;
static bool quiet = false;
static bool doforce = false;
static bool no_onlcr = false;
static long tsize[2] = {80, 25};
static FILE* verbosefile = stdout;
static std::string linkdir;
static std::string dstroot;
static int pidfd = -1;
static std::string pidfilename;
static std::string pidcontents;
static int timingfd = -1;
static std::string timingfilename;
static std::string ready_marker;
static int eventsourcefd = -1;
static std::string eventsourcefilename;
static volatile sig_atomic_t got_sigterm = 0;
#if __linux__
static int sigfd = -1;
#else
static int sigpipe[2];
#endif

enum jailaction {
    do_start, do_add, do_run, do_rm, do_mv
};


static const char* uid_to_name(uid_t u) {
    static uid_t old_uid = -1;
    static char buf[128];
    if (u != old_uid) {
        old_uid = u;
        if (struct passwd *pw = getpwuid(u)) {
            snprintf(buf, sizeof(buf), "%s", pw->pw_name);
        } else {
            snprintf(buf, sizeof(buf), "%u", (unsigned) u);
        }
    }
    return buf;
}

static const char* gid_to_name(gid_t g) {
    static gid_t old_gid = -1;
    static char buf[128];
    if (g != old_gid) {
        old_gid = g;
        if (struct group *gr = getgrgid(g)) {
            snprintf(buf, sizeof(buf), "%s", gr->gr_name);
        } else {
            snprintf(buf, sizeof(buf), "%u", (unsigned) g);
        }
    }
    return buf;
}


static int v_fchmod(int fd, mode_t mode, const std::string& pathname) {
    if (verbose) {
        fprintf(verbosefile, "chmod 0%o %s\n", mode, pathname.c_str());
    }
    return dryrun ? 0 : fchmod(fd, mode);
}

static int x_lchown(const char* path, uid_t owner, gid_t group) {
    if (verbose) {
        fprintf(verbosefile, "chown -h %s:%s %s\n", uid_to_name(owner), gid_to_name(group), path);
    }
    if (!dryrun && lchown(path, owner, group) != 0) {
        return perror_fail("chown %s: %s\n", path);
    }
    return 0;
}

static int x_lchownat(int fd, const char* component, uid_t owner, gid_t group, const std::string& dirpath) {
    if (verbose) {
        fprintf(verbosefile, "chown -h %s:%s %s%s\n", uid_to_name(owner), gid_to_name(group), dirpath.c_str(), component);
    }
    if (!dryrun && fchownat(fd, component, owner, group, AT_SYMLINK_NOFOLLOW) != 0) {
        return perror_fail("chown %s: %s\n", (dirpath + component).c_str());
    }
    return 0;
}

static int x_fchown(int fd, uid_t owner, gid_t group, const std::string& path) {
    if (verbose) {
        fprintf(verbosefile, "chown -h %s:%s %s\n", uid_to_name(owner), gid_to_name(group), path.c_str());
    }
    if (!dryrun && fchown(fd, owner, group) != 0) {
        return perror_fail("chown %s: %s\n", path.c_str());
    }
    return 0;
}

static int x_fchown_path(int fd, uid_t owner, gid_t group, const std::string& path) {
    if (verbose) {
        fprintf(verbosefile, "chown -h %s:%s %s\n", uid_to_name(owner), gid_to_name(group), path.c_str());
    }
#if O_PATH != 0
    if (!dryrun && fchownat(fd, "", owner, group, AT_EMPTY_PATH) != 0) {
        return perror_fail("chown %s: %s\n", path.c_str());
    }
#else
    if (!dryrun && fchown(fd, owner, group) != 0) {
        return perror_fail("chown %s: %s\n", path.c_str());
    }
#endif
    return 0;
}

static int v_mkdir(const char* pathname, mode_t mode) {
    if (verbose) {
        fprintf(verbosefile, "mkdir -m 0%o %s\n", mode, pathname);
    }
    return dryrun ? 0 : mkdir(pathname, mode);
}

static int v_mkdirat(int dirfd, const char* component, mode_t mode, const std::string& pathname) {
    if (verbose) {
        fprintf(verbosefile, "mkdir -m 0%o %s\n", mode, pathname.c_str());
    }
    return dryrun ? 0 : mkdirat(dirfd, component, mode);
}

// Ensure `pathname` exists as a directory, creating it and any missing parents.
// Uses `lstat`, so a symlink anywhere along the created portion is rejected
// rather than followed.
static int v_ensuredir(std::string pathname, mode_t mode) {
    pathname = path_noendslash(pathname);
    auto it = dirtable.find(pathname);
    if (it != dirtable.end()) {
        return it->second;
    }
    struct stat st;
    int r = lstat(pathname.c_str(), &st);
    if (r == 0 && !S_ISDIR(st.st_mode)) {
        errno = ENOTDIR;
        r = -1;
    }
    if (r == -1 && errno == ENOENT) {
        std::string parent_pathname = path_parentdir(pathname);
        if ((parent_pathname.length() == pathname.length()
             || v_ensuredir(parent_pathname, mode) >= 0)
            && v_mkdir(pathname.c_str(), mode) == 0) {
            r = 1;
        }
    }
    dirtable.insert(std::make_pair(pathname, r == 1 ? 0 : r));
    return r;
}

static int x_link(const char* oldpath, const char* newpath) {
    if (verbose) {
        fprintf(verbosefile, "rm -f %s\nln %s %s\n", newpath, oldpath, newpath);
    }
    if (!dryrun) {
        if (unlink(newpath) == -1 && errno != ENOENT) {
            return perror_fail("rm %s: %s\n", newpath);
        }
        if (link(oldpath, newpath) != 0) {
            return perror_fail("ln %s: %s\n", (std::string(oldpath) + " " + std::string(newpath)).c_str());
        }
    }
    return 0;
}

static int x_chmod(const char* path, mode_t mode) {
    if (verbose) {
        fprintf(verbosefile, "chmod 0%o %s\n", mode, path);
    }
    if (!dryrun && chmod(path, mode) != 0) {
        return perror_fail("chmod %s: %s\n", path);
    }
    return 0;
}

static bool x_mknod_eexist_ok(const char* path, mode_t mode, dev_t dev) {
    struct stat st;
    int old_errno = errno;
    bool ok = stat(path, &st) == 0 && st.st_mode == mode && st.st_rdev == dev;
    errno = old_errno;
    return ok;
}

static const char* dev_name(mode_t m, dev_t d) {
    static char buf[128];
    if (S_ISCHR(m)) {
        snprintf(buf, sizeof(buf), "c %d %d", major(d), minor(d));
    } else if (S_ISBLK(m)) {
        snprintf(buf, sizeof(buf), "b %d %d", major(d), minor(d));
    } else if (S_ISFIFO(m)) {
        return "p";
    } else {
        snprintf(buf, sizeof(buf), "%u %u", (unsigned) m, (unsigned) d);
    }
    return buf;
}

static int x_mknod(const char* path, mode_t mode, dev_t dev) {
    if (verbose) {
        fprintf(verbosefile, "mknod -m 0%o %s %s\n", mode, path, dev_name(mode, dev));
    }
    if (!dryrun
        && mknod(path, mode, dev) != 0
        && (errno != EEXIST || !x_mknod_eexist_ok(path, mode, dev))) {
        return perror_fail("mknod %s: %s\n", path);
    }
    return 0;
}

static bool x_symlink_eexist_ok(const char* oldpath, const char* newpath) {
    char lnkbuf[4096];
    int old_errno = errno;
    ssize_t r = readlink(newpath, lnkbuf, sizeof(lnkbuf));
    bool answer = (size_t) r == (size_t) strlen(oldpath) && memcmp(lnkbuf, oldpath, r) == 0;
    errno = old_errno;
    return answer;
}

static int x_symlink(const char* oldpath, const char* newpath) {
    if (verbose) {
        fprintf(verbosefile, "ln -s %s %s\n", oldpath, newpath);
    }
    if (!dryrun
        && symlink(oldpath, newpath) != 0
        && (errno != EEXIST || !x_symlink_eexist_ok(oldpath, newpath))) {
        return perror_fail("symlink %s: %s\n", (std::string(oldpath) + " " + newpath).c_str());
    }
    return 0;
}

static int x_copy_utimes(const char* path, const struct stat& st) {
    if (verbose) {
        fprintf(verbosefile, "touch -m -d @%ld %s\n", st.st_mtime, path);
    }
    if (!dryrun) {
        struct timespec ts[2];
        ts[0].tv_nsec = UTIME_OMIT;
#if __linux__
        ts[1] = st.st_mtim;
#else
        ts[1] = st.st_mtimespec;
#endif
        if (utimensat(-1, path, ts, AT_SYMLINK_NOFOLLOW) != 0) {
            return perror_fail("utimensat %s: %s\n", path);
        }
    }
    return 0;
}

static std::pair<pid_t, int> x_waitpid(pid_t child, int flags) {
    int status;
    while (true) {
        pid_t w = waitpid(child, &status, flags);
        if (w > 0 && WIFEXITED(status)) {
            return std::make_pair(w, WEXITSTATUS(status));
        } else if (w > 0) {
            return std::make_pair(w, 128 + WTERMSIG(status));
        } else if (w == 0) {
            errno = EAGAIN;
            return std::make_pair((pid_t) -1, -1);
        } else if (w == -1 && errno != EINTR) {
            return std::make_pair((pid_t) -1, -1);
        }
    }
}

#if __APPLE__
// Approximations of Linux-only system calls to allow Mac OS X compilation
// (NB: pa-jail is not expected to work on Mac OS X.)

int mount(const char*, const char* target, const char* fstype,
          unsigned long flags, const void*) {
    return ::mount(fstype, target, flags, nullptr);
}

int umount(const char* dir) {
    return ::unmount(dir, 0);
}

int setresuid(uid_t ruid, uid_t euid, uid_t suid) {
    if (ruid == euid && ruid == suid) {
        return setuid(ruid);
    } else if (ruid == euid && suid == ROOT) {
        return seteuid(euid);
    }
    errno = EINVAL;
    return -1;
}

int setresgid(gid_t rgid, gid_t egid, gid_t sgid) {
    if (rgid == egid && rgid == sgid) {
        return setgid(rgid);
    } else if (rgid == egid && sgid == ROOT) {
        return setegid(egid);
    }
    errno = EINVAL;
    return -1;
}
#endif


// jailmaking

#if __linux__
#define MFLAG(x) MS_ ## x
#elif __APPLE__
#define MFLAG(x) MNT_ ## x
#endif

struct mountarg {
    const char* name;
    int value;
    bool unparse;
};
static const mountarg mountargs[] = {
#if __linux__
    { "bind", MS_BIND, false },
    { "noatime", MS_NOATIME, true },
#endif
    { "nodev", MFLAG(NODEV), true },
#if __linux__
    { "nodiratime", MS_NODIRATIME, true },
#endif
    { "noexec", MFLAG(NOEXEC), true },
    { "nosuid", MFLAG(NOSUID), true },
#if __linux__
    { "private", MS_PRIVATE, true },
    { "rec", MS_REC, false },
#endif
#if __linux__ && defined(MS_RELATIME)
    { "relatime", MS_RELATIME, true },
#endif
#if __linux__
    { "remount", MS_REMOUNT, true },
#endif
    { "ro", MFLAG(RDONLY), true },
    { "rw", 0, true },
#if __linux__
    { "slave", MS_SLAVE, true },
#endif
#if __linux__ && defined(MS_STRICTATIME)
    { "strictatime", MS_STRICTATIME, true },
#endif
#if __linux__ && defined(MS_UNBINDABLE)
    { "unbindable", MS_UNBINDABLE, true },
#endif
};
static const mountarg* find_mountarg(const char* name, int namelen) {
    const mountarg* ma = mountargs;
    const mountarg* maend = ma + sizeof(mountargs) / sizeof(mountargs[0]);
    for (; ma != maend; ++ma)
        if ((int) strlen(ma->name) == namelen
            && memcmp(ma->name, name, namelen) == 0)
            return ma;
    return 0;
}


struct mountslot {
    std::string fsname;
    std::string type;
    unsigned long opts;
    std::string data;
    bool wanted;
    mountslot() : opts(0), wanted(false) {}
    mountslot(const char* fsname, const char* type, const char* mountopts);
    std::string debug_mountopts_args(unsigned long opts) const;
    std::string debug_mount_command(std::string dst, unsigned long opts) const;
    void add_mountopt(const char* mopt);
    const char* mount_data() const;
    bool mountable(std::string src, std::string dst) const;
    int x_mount(std::string dst, unsigned long opts);
};

mountslot::mountslot(const char* fsname_, const char* type_, const char* mopt)
    : fsname(fsname_), type(type_), opts(0), wanted(false) {
    while (mopt && *mopt) {
        const char* ok_first = mopt + strspn(mopt, ",");
        const char* ok_last = ok_first + strcspn(ok_first, ",=");
        const char* ov_last = ok_last + strcspn(ok_last, ",");
        if (const mountarg* ma = find_mountarg(ok_first, ok_last - ok_first)) {
            opts |= ma->value;
        } else if (ok_first != ov_last) {
            data += (data.empty() ? "" : ",") + std::string(ok_first, ov_last);
        }
        mopt = ov_last;
    }
}

std::string mountslot::debug_mountopts_args(unsigned long opts) const {
    std::string arg;
    if (!(opts & MFLAG(RDONLY))) {
        arg = "rw";
    }
    const mountarg* ma = mountargs;
    const mountarg* ma_last = ma + sizeof(mountargs) / sizeof(mountargs[0]);
    for (; ma != ma_last; ++ma) {
        if (ma->value && (opts & ma->value) && ma->unparse)
            arg += (arg.empty() ? "" : ",") + std::string(ma->name);
    }
    if (!data.empty()) {
        arg += (arg.empty() ? "" : ",") + data;
    }
#ifdef MS_BIND
    std::string start = opts & MS_REC ? " --rbind " : " --bind ";
    if ((opts & MS_BIND) && arg == "rw") {
        return start;
    } else if (opts & MS_BIND) {
        return start + "-o " + arg;
    }
#endif
    if (!arg.empty()) {
        return " -o " + arg;
    }
    return arg;
}

std::string mountslot::debug_mount_command(std::string dst, unsigned long opts) const {
    return "mount -i -n -t " + type + debug_mountopts_args(opts) + " " + fsname + " " + dst;
}

void mountslot::add_mountopt(const char* inopt) {
    int inopt_len = strcspn(inopt, ",=");
    if (const mountarg* ma = find_mountarg(inopt, inopt_len)) {
        if (ma->value) {
            opts |= ma->value;
        } else {
            opts &= ~MFLAG(RDONLY);
        }
    } else {
        const char* mstart = data.c_str();
        const char* mopt = mstart;
        while (*mopt) {
            const char* ok_first = mopt + strspn(mopt, ",");
            const char* ok_last = ok_first + strcspn(ok_first, ",=");
            const char* ov_last = ok_last + strcspn(ok_last, ",");
            if (ok_last - ok_first == inopt_len
                && memcmp(inopt, ok_first, inopt_len) == 0) {
                int offset = ok_first - data.data();
                data = std::string(mstart, mopt)
                    + std::string(ov_last, mstart + data.length());
                mstart = data.c_str();
                mopt = mstart + offset;
            } else {
                mopt = ov_last;
            }
        }
        data += (data.empty() ? "" : ",") + std::string(inopt);
    }
}

const char* mountslot::mount_data() const {
    return data.empty() ? nullptr : data.c_str();
}

static int mount_status = 0; // 0: add, 1: run pre-fork, 2: in child
static std::vector<std::string> delayed_mounts;

bool mountslot::mountable(std::string src, std::string dst) const {
    if (verbose && false) {
        fprintf(verbosefile, "-checkmount %s %s type=%s status=%d wanted=%d-\n",
                src.c_str(), dst.c_str(), type.c_str(), mount_status, wanted ? 1 : 0);
    }
    if ((src == "/proc" && type == "proc")
        || (src == "/dev/pts" && type == "devpts")) {
        return mount_status == 2;
    } else if (src == "/tmp" && type == "tmpfs") {
        return mount_status != 1;
    } else if (src == "/run" && type == "tmpfs") {
        return false;
    } else if ((src == "/sys" && type == "sysfs")
               || (src == "/dev" && type == "udev")
               || wanted) {
        if (mount_status == 1) {
            delayed_mounts.push_back(src);
            delayed_mounts.push_back(dst);
            return false;
        }
        return true;
    }
    return false;
}

int mountslot::x_mount(std::string dst, unsigned long opts) {
    if (verbose) {
        fprintf(verbosefile, "%s\n", debug_mount_command(dst, opts).c_str());
    }
    if (dryrun) {
        return 0;
    }
    return mount(fsname.c_str(), dst.c_str(), type.c_str(), opts, mount_data());
}


typedef std::unordered_map<std::string, mountslot> mount_table_type;
static mount_table_type mount_table;

static int populate_mount_table() {
    static bool mount_table_populated = false;
    if (mount_table_populated) {
        return 0;
    }
    mount_table_populated = true;
#if __linux__
    FILE* f = setmntent("/proc/mounts", "r");
    if (!f) {
        return perror_fail("open %s: %s\n", "/proc/mounts");
    }
    while (struct mntent* me = getmntent(f)) {
        mountslot ms(me->mnt_fsname, me->mnt_type, me->mnt_opts);
        mount_table[me->mnt_dir] = ms;
    }
    fclose(f);
    return 0;
#elif __APPLE__
    struct statfs* mntbuf;
    int nmntbuf = getmntinfo(&mntbuf, MNT_NOWAIT);
    for (struct statfs* me = mntbuf; me != mntbuf + nmntbuf; ++me) {
        mountslot ms(me->f_mntfromname, me->f_fstypename, "");
        ms.opts = me->f_flags;
        mount_table[me->f_mntonname] = ms;
    }
    return 0;
#endif
}

static int handle_mount(std::string src, std::string dst, bool in_child) {
    auto it = mount_table.find(src);
    if (it == mount_table.end()
        || !it->second.mountable(src, dst)) {
        return 0;
    }

    auto dit = mount_table.find(dst);
    if (dit != mount_table.end()
        && dit->second.fsname == it->second.fsname
        && dit->second.type == it->second.type
        && dit->second.opts == it->second.opts
        && dit->second.data == it->second.data
        && !in_child) {
        // already mounted
        return 0;
    }

    auto xit = dst_table.find(dst);
    if (xit != dst_table.end()
        && xit->second > 1) {
        return 0;
    }
    dst_table[dst] = 2;

    if (in_child) {
        v_ensuredir(dst, 0555);
    }

    mountslot msx(it->second);
#if __linux__
    if (msx.type == "devpts" && in_child) {
        msx.add_mountopt("newinstance");
        msx.add_mountopt("ptmxmode=0666");
    }
    if ((msx.opts & MS_BIND) && in_child) {
        msx.add_mountopt("slave");
    }
#endif
    int r = msx.x_mount(dst, msx.opts);
    // if in child, try one more time with remount
    if (!dryrun && r != 0 && errno == EBUSY && in_child) {
        r = msx.x_mount(dst, msx.opts | MS_REMOUNT);
    }
#if __linux__
    // if bind mount, need to remount as slave
    if (r == 0 && (msx.opts & MS_BIND)) {
        r = msx.x_mount(dst, msx.opts | MS_REMOUNT);
    }
#endif
    if (r != 0) {
        return perror_fail("%s: %s\n", msx.debug_mount_command(dst, msx.opts).c_str());
    }
    return 0;
}

static int handle_umount(const mount_table_type::iterator& it) {
    if (verbose) {
        fprintf(verbosefile, "umount -i -n %s\n", it->first.c_str());
    }
    if (!dryrun && umount(it->first.c_str()) != 0) {
        fprintf(stderr, "umount %s: %s\n", it->first.c_str(), strerror(errno));
        exit(1);
    }
    if (dryrun) {
        dst_table[it->first.c_str()] = 3;
    }
    return 0;
}

static std::string unmounted(std::string dir, bool no_change = false) {
#ifdef MS_BIND
    auto it = mount_table.find(dir);
    if (it != mount_table.end()) {
        return it->second.opts & MS_BIND ? it->second.fsname : dir;
    }
    for (auto dit = delayed_mounts.begin(); dit != delayed_mounts.end(); dit += 2) {
        if (dit[1] == dir) {
            it = mount_table.find(dit[0]);
            return it->second.opts & MS_BIND ? dit[0] : dir;
        }
    }
    if (no_change || dir.empty()) {
        return dir;
    } else if (dir.back() == '/') {
        return unmounted(dir.substr(0, dir.length() - 1), true);
    }
    return unmounted(dir + '/', true);
#else
    (void) no_change;
    return dir;
#endif
}


static int handle_copy(std::string src, std::string subdst,
                       int flags, dev_t jaildev);
static int construct_jail(dev_t jaildev, std::string& str, bool nomount);

static void handle_symlink_dst(std::string dst, std::string src,
                               std::string lnk, dev_t jaildev)
{
    std::string root = dstroot;
    if (!linkdir.empty() && dst.substr(0, dstroot.length()) != dstroot) {
        root = linkdir;
    }

    // expand `lnk` into `dst`
    if (lnk[0] == '/') {
        src = lnk;
        dst = root + lnk;
    } else {
        while (true) {
            if (src.length() == 1) {
            give_up:
                return;
            }
            size_t srcslash = src.rfind('/', src.length() - 2),
                dstslash = dst.rfind('/', dst.length() - 2);
            if (srcslash == std::string::npos || dstslash == std::string::npos
                || dstslash < root.length()) {
                goto give_up;
            }
            src = src.substr(0, srcslash + 1);
            dst = dst.substr(0, dstslash + 1);
            if (lnk.length() > 3 && lnk[0] == '.' && lnk[1] == '.'
                && lnk[2] == '/') {
                lnk = lnk.substr(3);
            } else {
                break;
            }
        }
        src += lnk;
        dst += lnk;
    }

    if (dst.substr(root.length(), 6) != "/proc/") {
        handle_copy(src, dst.substr(root.length()), 0, jaildev);
    }
}

static int x_rm_f(const std::string &dst) {
    if (verbose) {
        fprintf(verbosefile, "rm -f %s\n", dst.c_str());
    }
    if (dryrun) {
        return 0;
    }
    int r = unlink(dst.c_str());
    if (r == -1 && errno != ENOENT) {
        return perror_fail("rm %s: %s\n", dst.c_str());
    }
    return 0;
}

static int x_cp_p(const std::string& src, const std::string& dst) {
    if (x_rm_f(dst)) {
        return 1;
    }
    if (verbose) {
        fprintf(verbosefile, "cp -p %s %s\n", src.c_str(), dst.c_str());
    }
    if (dryrun) {
        return 0;
    }

    pid_t child = fork();
    if (child == 0) {
        const char* args[6] = {
            "/bin/cp", "-p", src.c_str(), dst.c_str(), nullptr
        };
        execv("/bin/cp", (char**) args);
        exit(1);
    } else if (child < 0) {
        return perror_fail("%s: %s\n", "fork");
    }

    int status = x_waitpid(child, 0).second;
    if (status == 0) {
        return 0;
    } else if (status != -1) {
        return perror_fail("/bin/cp %s: Bad exit status\n", dst.c_str());
    }
    return perror_fail("/bin/cp %s: Did not exit\n", dst.c_str());
}

static inline int stat_mtimes_same(const struct stat& st1, const struct stat& st2) {
#if __linux__
    return st1.st_mtim.tv_sec == st2.st_mtim.tv_sec && st1.st_mtim.tv_nsec == st2.st_mtim.tv_nsec;
#else
    return st1.st_mtime == st2.st_mtime;
#endif
}

static int do_copy(const std::string& dst, const std::string& src,
                   const struct stat& ss, bool reuse_link, dev_t jaildev) {
    struct stat ds;
    int r = lstat(dst.c_str(), &ds);
    if (r == 0
        && ss.st_mode == ds.st_mode
        && ss.st_uid == ds.st_uid
        && ss.st_gid == ds.st_gid
        && ((!S_ISREG(ss.st_mode) && !S_ISLNK(ss.st_mode))
            || ss.st_size == ds.st_size)
        && ((!S_ISBLK(ss.st_mode) && !S_ISCHR(ss.st_mode))
            || ss.st_rdev == ds.st_rdev)
        && ((!S_ISREG(ss.st_mode) && !S_ISLNK(ss.st_mode))
            || stat_mtimes_same(ss, ds))) {
        if (S_ISREG(ss.st_mode)) {
            auto di = std::make_pair(ss.st_dev, ss.st_ino);
            devino_table.insert(std::make_pair(di, dst));
        }
        return 0;
    }

    // check for hard link to already-created file
    if (S_ISREG(ss.st_mode)) {
        if (reuse_link) {
            auto di = std::make_pair(ss.st_dev, ss.st_ino);
            auto it = devino_table.find(di);
            if (it != devino_table.end())
                return x_link(it->second.c_str(), dst.c_str());
            devino_table.insert(std::make_pair(di, dst));
        }
        return x_cp_p(src, dst);
    } else if (S_ISDIR(ss.st_mode)) {
        mode_t perm = ss.st_mode & (S_ISUID | S_ISGID | S_IRWXU | S_IRWXG | S_IRWXO);
        if (r == 0 && !S_ISDIR(ds.st_mode)) {
            errno = ENOTDIR;
            return perror_fail("%s: %s\n", dst.c_str());
        }
        if (v_mkdir(dst.c_str(), perm) != 0) {
            return 1;
        }
    } else if (S_ISCHR(ss.st_mode) || S_ISBLK(ss.st_mode)) {
        if (x_rm_f(dst)) {
            return 1;
        }
        // A manifest may list /dev/ptmx as a char device (pa-trace captures it):
        // it is mknod'd here like any other node, then exec_go() replaces it with
        // the `pts/ptmx` symlink the jail's newinstance devpts needs -- so no
        // /dev/ptmx special case is required here.
        mode_t mode = ss.st_mode & (S_IFREG | S_IFCHR | S_IFBLK | S_IFIFO | S_IFSOCK | S_ISUID | S_ISGID | S_IRWXU | S_IRWXG | S_IRWXO);
        if (x_mknod(dst.c_str(), mode, ss.st_rdev)) {
            return 1;
        }
    } else if (S_ISLNK(ss.st_mode)) {
        if (x_rm_f(dst)) {
            return 1;
        }
        char lnkbuf[4096];
        ssize_t r = readlink(src.c_str(), lnkbuf, sizeof(lnkbuf));
        if (r == -1) {
            return perror_fail("readlink %s: %s\n", src.c_str());
        } else if (r == sizeof(lnkbuf)) {
            return perror_fail("%s: Symbolic link too long\n", src.c_str());
        }
        lnkbuf[r] = 0;
        if (x_symlink(lnkbuf, dst.c_str())) {
            return 1;
        } else if (x_copy_utimes(dst.c_str(), ss)) {
            return 1;
        }
        handle_symlink_dst(dst, src, std::string(lnkbuf), jaildev);
    } else {
        // cannot deal
        return perror_fail("%s: Odd file type\n", src.c_str());
    }

    if (ss.st_uid != ROOT || ss.st_gid != ROOT) {
        return x_lchown(dst.c_str(), ss.st_uid, ss.st_gid);
    }
    return 0;
}

static int handle_copy(std::string src, std::string subdst,
                       int flags, dev_t jaildev) {
    static std::string last_parentdir;

    assert(subdst[0] == '/');
    assert(subdst.length() == 1 || subdst[1] != '/');
    assert(dstroot.back() != '/');
    assert(subdst.substr(0, dstroot.length()) != dstroot);

    // do not end in slash. lstat() on a symlink path actually follows the
    // symlink if the path ends in slash
    while (src.length() > 1 && src.back() == '/') {
        src = src.substr(0, src.length() - 1);
    }
    while (subdst.length() > 1 && subdst.back() == '/') {
        subdst = subdst.substr(0, subdst.length() - 1);
    }

    std::string dst = dstroot + subdst;
    if (dst_table.find(dst) != dst_table.end()) {
        return 1;
    }
    dst_table[dst] = 1;

    struct stat ss;

    std::string dst_parentdir = path_noendslash(path_parentdir(dst));
    if (dst_parentdir != last_parentdir
        && dst_parentdir.length() > dstroot.length()) {
        last_parentdir = dst_parentdir;
        if (dst_table.find(last_parentdir) == dst_table.end()) {
            int r = handle_copy(path_noendslash(path_parentdir(src)),
                                last_parentdir.substr(dstroot.length()),
                                0, jaildev);
            if (r != 0) {
                return r;
            }
        }
    }

    if (lstat(src.c_str(), &ss) != 0) {
        return perror_fail("lstat %s: %s\n", src.c_str());
    }

    // set up skeleton directory version
    if (!linkdir.empty()) {
        do_copy(linkdir + subdst, src, ss, true, jaildev);
    }

    if (do_copy(dst, src, ss, !(flags & FLAG_CP), jaildev)) {
        return 1;
    }

    if (S_ISDIR(ss.st_mode)) {
        return handle_mount(src, dst, false);
    }
    return 0;
}

inline const char* opt_wordskip(const char* s) {
    while (*s != ']' && *s != ';' && !isspace((unsigned char) *s)) {
        ++s;
    }
    return s;
}

inline bool opt_eq(const char* opt, const char* endopt,
                   const char* def, unsigned len) {
    return endopt - opt == len && memcmp(opt, def, len) == 0;
}

static std::string file_get_contents_error(std::string msg, int errorness) {
    if (errorness > 0) {
        fprintf(stderr, "%s\n", msg.c_str());
    }
    if (errorness > 1) {
        exit(1);
    }
    return "";
}

static std::string file_get_contents(std::string fname, int errorness) {
    FILE* f;
    if (fname == "-") {
        f = stdin;
        if (isatty(STDIN_FILENO)) {
            return file_get_contents_error("stdin: Is a tty", errorness);
        }
    } else {
        f = fopen(fname.c_str(), "r");
        if (!f) {
            return file_get_contents_error(fname + ": " + strerror(errno), errorness);
        }
    }
    std::string contents;
    while (!feof(f) && !ferror(f)) {
        char buf[BUFSIZ];
        size_t n = fread(buf, 1, BUFSIZ, f);
        if (n > 0) {
            contents.append(buf, n);
        }
    }
    if (ferror(f)) {
        return file_get_contents_error(fname + ": " + strerror(errno), errorness);
    }
    fclose(f);
    return contents;
}

static void fix_jail_bind_src(dev_t jaildev,
                              std::string src, std::string want_tag,
                              std::string want_files) {
    std::string srcx = path_endslash(src) + ".pa-jail-bindtag";
    if (verbose) {
        fprintf(verbosefile, "test %s = `cat %s`\n", shell_quote(want_tag).c_str(), shell_quote(srcx).c_str());
    }
    std::string got_tag = file_get_contents(srcx, 0);
    while (!got_tag.empty() && isspace((unsigned char) got_tag.back())) {
        got_tag.pop_back();
    }
    if (got_tag != want_tag) {
        std::string contents = file_get_contents(want_files, 2);
        std::string old_dstroot = dstroot;
        dstroot = path_noendslash(src);
        construct_jail(jaildev, contents, true);
        dstroot = old_dstroot;
        if (verbose) {
            fprintf(verbosefile, "echo %s > %s\n", shell_quote(want_tag).c_str(), srcx.c_str());
        }
        if (!dryrun) {
            want_tag += "\n";
            int fd = open(srcx.c_str(), O_WRONLY | O_CREAT | O_TRUNC | O_NOFOLLOW, 0600);
            if (fd == -1
                || (size_t) write(fd, want_tag.data(), want_tag.length()) != want_tag.length()) {
                perror_die(srcx.c_str());
            }
            close(fd);
        }
    }
}

static int construct_jail(dev_t jaildev, std::string& str, bool nomount) {
    // prepare root
    if (x_chmod(dstroot.c_str(), 0755)
        || x_lchown(dstroot.c_str(), 0, 0)) {
        return 1;
    }
    dst_table[dstroot + "/"] = 1;

    // Mounts
    populate_mount_table();

    // Read a line at a time
    std::string cursrcdir("/"), curdstsubdir("/");
    std::string bind_tag, bind_files, mount_dst, mount_args;
    int base_flags = 0;

    const char* pos = str.data(), *endpos = pos + str.length();
    while (pos < endpos) {
        while (pos < endpos && isspace((unsigned char) *pos)) {
            ++pos;
        }
        const char* line = pos;
        while (pos < endpos && *pos != '\n') {
            ++pos;
        }
        const char* endline = pos;
        while (line < endline && isspace((unsigned char) endline[-1])) {
            --endline;
        }
        if (line == endline || line[0] == '#') {
            continue;
        }

        // 'directory:'
        if (endline[-1] == ':') {
            if (line + 2 == endline && line[0] == '.') {
                cursrcdir = std::string("/");
            } else if (line + 2 > endline && line[0] == '.' && line[1] == '/') {
                cursrcdir = std::string(line + 1, endline - 1);
            } else {
                cursrcdir = std::string(line, endline - 1);
            }
            if (cursrcdir[0] != '/') {
                cursrcdir = std::string("/") + cursrcdir;
            }
            while (cursrcdir.length() > 1
                   && cursrcdir[cursrcdir.length() - 1] == '/'
                   && cursrcdir[cursrcdir.length() - 2] == '/') {
                cursrcdir = cursrcdir.substr(0, cursrcdir.length() - 1);
            }
            if (cursrcdir[cursrcdir.length() - 1] != '/') {
                cursrcdir += '/';
            }
            curdstsubdir = cursrcdir;
            assert(curdstsubdir.back() == '/');
            continue;
        }

        // '[FLAGS]'
        int flags = base_flags;
        if (endline[-1] == ']') {
            // skip ' [FLAGS]'
            for (--endline; line < endline && endline[-1] != '['; --endline) {
                // do nothing
            }
            if (line == endline) {
                continue;
            }
            const char* opts = endline;
            do {
                --endline;
            } while (line < endline && isspace((unsigned char) endline[-1]));
            // parse flags
            while (true) {
                while (isspace((unsigned char) *opts) || *opts == ';') {
                    ++opts;
                }
                if (*opts == ']') {
                    break;
                }
                // read first option word
                const char* optstart = opts;
                opts = opt_wordskip(opts + 1);
                // process option
                int want = 0;
                if (opt_eq(optstart, opts, "cp", 2)) {
                    flags |= FLAG_CP;
                } else if (opt_eq(optstart, opts, "bind", 4)) {
                    flags |= FLAG_BIND;
                    want = FLAG_BIND;
                } else if (opt_eq(optstart, opts, "bind-ro", 7)) {
                    flags |= FLAG_BIND_RO;
                    want = FLAG_BIND;
                } else if (opt_eq(optstart, opts, "mount", 5)) {
                    flags |= FLAG_MOUNT;
                    want = FLAG_MOUNT;
                }
                if (want == FLAG_BIND) {
                    while (isspace((unsigned char) *opts)) {
                        ++opts;
                    }
                    const char* tagstart = opts;
                    opts = opt_wordskip(opts);
                    bind_tag = std::string(tagstart, opts);

                    while (isspace((unsigned char) *opts)) {
                        ++opts;
                    }
                    tagstart = opts;
                    opts = opt_wordskip(opts);
                    bind_files = std::string(tagstart, opts);
                } else if (want == FLAG_MOUNT) {
                    while (isspace((unsigned char) *opts)) {
                        ++opts;
                    }
                    const char* mountstart = opts;
                    opts = opt_wordskip(opts);
                    mount_dst = std::string(mountstart, opts);

                    while (isspace((unsigned char) *opts)) {
                        ++opts;
                    }
                    mountstart = opts;
                    while (*opts != ']' && *opts != ';') {
                        ++opts;
                    }
                    mount_args = std::string(mountstart, opts);
                }
                // skip to next option word
                while (*opts != ']' && *opts != ';') {
                    ++opts;
                }
            }
        }

        std::string src, dst;
        const char* arrow = (const char*) memmem(line, endline - line, " <- ", 4);
        if (arrow) {
            src = std::string(arrow + 4, endline);
        } else if (line[0] == '/') {
            src = std::string(line, endline);
        } else {
            src = cursrcdir + std::string(line, endline);
        }
        if (!arrow) {
            arrow = endline;
        }
        dst = curdstsubdir + std::string(line + (line[0] == '/'), arrow);

        // act on flags
        if (flags & (FLAG_BIND | FLAG_BIND_RO)) {
            if (!nomount) {
                if (flags & FLAG_MOUNT) {
                    fprintf(stderr, "%s: [mount] option ignored\n", src.c_str());
                }
                if (!bind_tag.empty() && !bind_files.empty()) {
                    fix_jail_bind_src(jaildev, src, bind_tag, bind_files);
                }
                mountslot ms(src.c_str(), "none",
                             flags & FLAG_BIND_RO ? "bind,rec,unbindable,ro" : "bind,rec,unbindable");
                ms.wanted = true;
                mount_table[src] = ms;
                v_ensuredir(dstroot + dst, 0555);
                handle_mount(src, dstroot + dst, false);
            }
        } else if (flags & FLAG_MOUNT) {
            if (!nomount) {
                mountslot ms(src.c_str(), mount_dst.c_str(), mount_args.c_str());
                ms.wanted = true;
                mount_table[src] = ms;
                v_ensuredir(dstroot + dst, 0555);
                handle_mount(src, dstroot + dst, false);
            }
        } else {
            handle_copy(src, dst, flags, jaildev);
        }
    }

    return ::exit_status;
}


// main program

struct jaildirinfo {
    jailperm perm;
    pajailconf* conf_ = nullptr;        // the config `perm` was resolved from
    std::string parent;
    int parentfd = -1;
    std::string component;
    dev_t dev = -1;

    jaildirinfo(const char* str, const std::string& skeletondir,
                jailaction action, pajailconf& jailconf);
    void check();
    void chown_home();
    void chown_recursive(const std::string& dir, uid_t owner, gid_t group);
    void remove();

private:
    void chown_recursive(int dirfd, std::string& dirbuf, uid_t owner,
                         gid_t group, bool ishome, dev_t dev);
    void remove_recursive(int dirfd, std::string component, std::string name);
};

jaildirinfo::jaildirinfo(const char* dirstr, const std::string& skeletonstr,
                         jailaction action, pajailconf& jailconf) {
    conf_ = &jailconf;
    perm.dir = path_pa_validate(path_absolute(dirstr));
    if (perm.dir.empty() || perm.dir == "/" || perm.dir[0] != '/') {
        fprintf(stderr, "%s: Bad jail directory\n", dirstr);
        exit(1);
    }
    if (!perm.dir.ends_with('/')) {
        perm.dir.push_back('/');
    }
    if (!skeletonstr.empty()) {
        perm.skeletondir = path_pa_validate(path_absolute(skeletonstr));
        if (perm.skeletondir.empty() || perm.skeletondir == "/" || perm.skeletondir[0] != '/') {
            fprintf(stderr, "%s: Bad skeleton directory\n", skeletonstr.c_str());
            exit(1);
        }
        if (!perm.skeletondir.ends_with('/')) {
            perm.skeletondir.push_back('/');
        }
    }
    jailconf.parse(perm);
    if (!perm) {
        die("%s: Jail disabled in /etc/pa-jail.conf\n%s",
            perm.dir.c_str(), perm.disable_message().c_str());
    } else if (!skeletonstr.empty() && !perm.skeleton_enabled) {
        die("%s: Skeleton disabled in /etc/pa-jail.conf\n",
            perm.skeletondir.c_str());
    }

    size_t last_pos = 0;
    int fd = -1;
    bool dryrunning = false;
    while (last_pos != perm.dir.size()) {
        // extract component
        size_t next_pos = last_pos;
        while (next_pos
               && next_pos < perm.dir.size()
               && perm.dir[next_pos] != '/') {
            ++next_pos;
        }
        if (!next_pos) {
            ++next_pos;
        }
        parent = perm.dir.substr(0, last_pos);
        component = perm.dir.substr(last_pos, next_pos - last_pos);
        std::string thisdir = perm.dir.substr(0, next_pos);
        last_pos = next_pos;
        while (last_pos != perm.dir.size() && perm.dir[last_pos] == '/') {
            ++last_pos;
        }

        // check whether we are below the permission directory
        bool allowed_here = !perm.permdir.empty()
            && last_pos >= perm.permdir.length()
            && perm.dir.substr(0, perm.permdir.length()) == perm.permdir;

        // open it and swap it in
        if (parentfd >= 0) {
            close(parentfd);
        }
        parentfd = fd;
        fd = openat(parentfd, component.c_str(),
                    O_PATH | O_CLOEXEC | O_NOFOLLOW);
        if (fd == -1 && !allowed_here && errno == ENOENT) {
            // A component above the permission directory is missing. For the
            // creating actions this is fatal: we must not let later code
            // (`v_ensuredir`) `mkdir -p` it unchecked, outside the boundary.
            // For `rm`/`mv` there is nothing to create, so stop the walk.
            if (action == do_add || action == do_run) {
                die("%s: Required parent directory does not exist\n",
                    thisdir.c_str());
            }
            break;
        }
        if (fd == -1
            && (dryrunning
                || (allowed_here
                    && errno == ENOENT
                    && (action == do_add || action == do_run)))) {
            if (v_mkdirat(parentfd, component.c_str(), 0755, thisdir) != 0) {
                fprintf(stderr, "mkdir %s: %s\n", thisdir.c_str(), strerror(errno));
                exit(1);
            }
            dirtable.insert(std::make_pair(thisdir, 0));
            fd = openat(parentfd, component.c_str(), O_CLOEXEC | O_NOFOLLOW);
            // turn off suid+sgid on created root directory
            if (last_pos == perm.dir.size() && (fd >= 0 || dryrun)
                && v_fchmod(fd, 0755, thisdir) != 0) {
                fprintf(stderr, "chmod %s: %s\n", thisdir.c_str(), strerror(errno));
                exit(1);
            }
            if (dryrun) {
                dryrunning = true;
                continue;
            }
        }
        if (fd == -1 && errno == ENOENT && action == do_rm && doforce) {
            exit(0);
        } else if (fd == -1) {
            fprintf(stderr, "%s: %s\n", thisdir.c_str(), strerror(errno));
            exit(1);
        }

        // stat it
        struct stat s;
        if (fstat(fd, &s) != 0) {
            perror_die(thisdir);
        }
        bool final_target = last_pos == perm.dir.size();
        // The final target is the jail root that we will `pivot_root` into and
        // run untrusted code under. Even at/below `permdir` (where we otherwise
        // trust the permission tree), a *pre-existing* jail root that is not
        // root-owned or is group/other-writable is a breakout path: an attacker
        // who can write it could stage a setuid binary or swap the tree. So
        // ownership-check the final target for the creating/running actions too.
        // A root-created root (the `mkdir` branch above) is 0755 root:root and
        // passes; this only rejects a loosely-permissioned pre-existing root.
        bool check_owner = (!allowed_here && !final_target)
            || (final_target && (action == do_add || action == do_run));
        if (!S_ISDIR(s.st_mode)) {
            errno = ENOTDIR;
            perror_die(thisdir);
        } else if (check_owner) {
            if (s.st_uid != ROOT) {
                die("%s: Not owned by root\n", thisdir.c_str());
            } else if ((s.st_gid != ROOT && (s.st_mode & S_IWGRP))
                       || (s.st_mode & S_IWOTH)) {
                die("%s: Writable by non-root\n", thisdir.c_str());
            }
        }
        dev = s.st_dev;
    }
    if (fd >= 0) {
        close(fd);
    }
}

void jaildirinfo::check() {
    assert(!perm.permdir.empty() && perm.permdir.back() == '/');
    assert(perm.dir.starts_with(perm.permdir));
}

// Chown `{dir}/home/` to be owned by root, and `{dir}/home/{user}`
// to be owned by `user:user`.
void jaildirinfo::chown_home() {
    populate_mount_table();
    std::string dirbuf = perm.dir + "home/";
    int dirfd = openat(parentfd, (component + "/home").c_str(),
                       O_CLOEXEC | O_NOFOLLOW);
    struct stat dirst;
    if (dirfd == -1 || fstat(dirfd, &dirst) != 0) {
        perror_die(dirbuf);
    }
    chown_recursive(dirfd, dirbuf, ROOT, ROOT, true, dirst.st_dev);
    close(dirfd);
}

// Chown `path` and directories under it to be owned by `owner:group`.
// `path` must be located under the jail’s `dir`. Walks from `dir`
// down to `path`, refusing to cross symbolic links. The final file
// may be a regular file or symbolic link (the link or file is chown'd)
//  or a directory (it and its children are chown'd recursively).
void jaildirinfo::chown_recursive(const std::string& path,
                                  uid_t owner, gid_t group) {
    populate_mount_table();
    assert(path.starts_with(perm.dir) && path.size() > perm.dir.size());
    int dirfd = openat(parentfd, component.c_str(),
                       O_PATH | O_CLOEXEC | O_NOFOLLOW);
    if (dirfd == -1) {
        perror_die(perm.dir);
    }
    // walk down to parent directory of `path`
    size_t pos = perm.dir.size();
    size_t lastpos = path.size() - (path.back() == '/');
    size_t dirpos = path.rfind('/', lastpos - 1);
    assert(pos > 0 && dirpos >= pos - 1 && dirpos < lastpos);
    struct stat st;
    while (pos < dirpos) {
        size_t nextpos = path.find('/', pos);
        assert(nextpos <= dirpos);
        int nextfd = openat(dirfd, path.substr(pos, nextpos - pos).c_str(),
                            O_PATH | O_CLOEXEC | O_NOFOLLOW);
        if (nextfd == -1 || fstat(nextfd, &st) != 0) {
            perror_die(path.substr(0, nextpos));
        } else if (S_ISLNK(st.st_mode)) {
            die("%s: Refusing to follow symbolic link\n", path.substr(0, nextpos).c_str());
        }
        close(dirfd);
        dirfd = nextfd;
        pos = nextpos + 1;
    }
    // open `path` itself
    std::string last_component(path.substr(dirpos + 1, lastpos - dirpos - 1));
    assert(!last_component.empty() && last_component.find('/') == std::string::npos);
    int lastfd = openat(dirfd, last_component.c_str(),
                        O_PATH | O_CLOEXEC | O_NOFOLLOW);
    if (lastfd == -1 || fstat(lastfd, &st) != 0) {
        perror_die(path);
    }
    if (x_fchown_path(lastfd, owner, group, path)) {
        exit(::exit_status);
    }
    if (S_ISDIR(st.st_mode)) {
        // reopen without O_PATH, chown recursively
        close(lastfd);
        lastfd = openat(dirfd, last_component.c_str(),
                        O_CLOEXEC | O_NOFOLLOW);
        if (lastfd == -1 || fstat(lastfd, &st) != 0 || !S_ISDIR(st.st_mode)) {
            perror_die(path);
        }
        std::string pathbuf = path_endslash(path);
        chown_recursive(lastfd, pathbuf, owner, group, false, st.st_dev);
    } else {
        close(lastfd);
    }
    close(dirfd);
}

void jaildirinfo::chown_recursive(int dirfd, std::string& dirbuf,
                                  uid_t owner, gid_t group,
                                  bool ishome, dev_t dev) {
    dirbuf = path_endslash(dirbuf);
    size_t dirbuflen = dirbuf.length();

    using ug_t = std::pair<uid_t, gid_t>;
    std::unordered_map<std::string, ug_t>* home_map = nullptr;
    if (ishome) {
        setpwent();
        home_map = new std::unordered_map<std::string, ug_t>;
        while (struct passwd* pw = getpwent()) {
            std::string name;
            if (pw->pw_dir
                && strncmp(pw->pw_dir, "/home/", 6) == 0
                && strchr(pw->pw_dir + 6, '/') == nullptr) {
                name = pw->pw_dir + 6;
            } else {
                name = pw->pw_name;
            }
            (*home_map)[name] = ug_t(pw->pw_uid, pw->pw_gid);
        }
    }

    DIR* dir = fdopendir(dirfd);
    if (!dir) {
        perror_die(dirbuf);
    }

    struct dirent* de;
    while ((de = readdir(dir))) {
        if (strcmp(de->d_name, ".") == 0 || strcmp(de->d_name, "..") == 0) {
            continue;
        }

        // don't follow symbolic links
        if (de->d_type == DT_LNK) {
            if (x_lchownat(dirfd, de->d_name, owner, group, dirbuf)) {
                exit(::exit_status);
            }
            continue;
        }

        // look up uid/gid if in home
        uid_t u = owner;
        gid_t g = group;
        if (home_map) {
            auto it = home_map->find(de->d_name);
            if (it != home_map->end()) {
                u = it->second.first;
                g = it->second.second;
            }
        }

        if (de->d_type == DT_DIR) {
            // recurse
            dirbuf += de->d_name;
            auto it = mount_table.find(dirbuf);
            if (it == mount_table.end()) { // not a mount point
                int subdirfd = openat(dirfd, de->d_name, O_CLOEXEC | O_NOFOLLOW);
                struct stat subdirst;
                if (subdirfd >= 0
                    && fstat(subdirfd, &subdirst) == 0
                    && subdirst.st_dev == dev
                    && S_ISDIR(subdirst.st_mode)) {
                    if (x_fchown(subdirfd, u, g, dirbuf)) {
                        exit(::exit_status);
                    }
                    chown_recursive(subdirfd, dirbuf, u, g, false, dev);
                } else if (subdirfd >= 0) {
                    close(subdirfd);
                }
            }
            dirbuf.resize(dirbuflen);
        } else {
            if (x_lchownat(dirfd, de->d_name, u, g, dirbuf)) {
                exit(::exit_status);
            }
        }
    }

    closedir(dir);
    delete home_map;
}

void jaildirinfo::remove() {
    remove_recursive(parentfd, component, path_endslash(perm.dir));
}

void jaildirinfo::remove_recursive(int parentdirfd, std::string component,
                                   std::string dirname) {
    auto it = dst_table.find(dirname);
    if (it != dst_table.end() && it->second == 3) { // unmounted file system
        return;
    }

    int dirfd = openat(parentdirfd, component.c_str(), O_RDONLY);
    struct stat dirst;
    if (dirfd == -1 || fstat(dirfd, &dirst) != 0) {
        perror_die(dirname);
    }
    if (dirst.st_dev != dev) { // --one-file-system
        close(dirfd);
        return;
    }

    DIR* dir = fdopendir(dirfd);
    if (!dir) {
        perror_die(dirname);
    }
    while (struct dirent* de = readdir(dir)) {
        if (de->d_type == DT_DIR) {
            if (strcmp(de->d_name, ".") == 0 || strcmp(de->d_name, "..") == 0) {
                continue;
            }
            std::string next_component = de->d_name;
            std::string next_dirname = dirname + next_component;
            remove_recursive(dirfd, next_component, dirname + next_component);
        } else {
            if (verbose) {
                fprintf(verbosefile, "rm %s%s\n", dirname.c_str(), de->d_name);
            }
            if (!dryrun && unlinkat(dirfd, de->d_name, de->d_type == DT_DIR ? AT_REMOVEDIR : 0) != 0) {
                perror_die("rm " + dirname + de->d_name);
            }
        }
    }
    closedir(dir);
    close(dirfd);

    if (verbose) {
        fprintf(verbosefile, "rmdir %s\n", dirname.c_str());
    }
    if (!dryrun && unlinkat(parentdirfd, component.c_str(), AT_REMOVEDIR) != 0) {
        perror_die("rmdir " + dirname);
    }
}


struct jbuffer {
    unsigned char* buf_;
    size_t head_ = 0;
    size_t tail_ = 0;
    size_t cap_;
    size_t bufpos_ = 0;
    bool rclosed_ = false;
    bool wclosed_ = false;
    int rerrno_ = 0;

    jbuffer(size_t cap)
        : buf_(new unsigned char[cap]), cap_(cap) {
    }
    jbuffer(jbuffer&& x)
        : buf_(x.buf_), head_(x.head_), tail_(x.tail_), cap_(x.cap_),
          bufpos_(x.bufpos_), rclosed_(x.rclosed_), wclosed_(x.wclosed_), rerrno_(x.rerrno_) {
        x.buf_ = nullptr;
    }
    jbuffer(const jbuffer&) = delete;
    jbuffer& operator=(const jbuffer&) = delete;
    jbuffer& operator=(jbuffer&&) = delete;
    ~jbuffer() {
        delete[] buf_;
    }

    inline void append(char ch);
    void append(const unsigned char* first, const unsigned char* last);
    inline void append(const char* first, const char* last);
    inline void append(const char* first, size_t n);
    const unsigned char* append_json_chars(const unsigned char* first, const unsigned char* last);
    void reserve(size_t n);
    bool read(int from);
    bool write(int to, size_t& to_off);
    void consume_to(size_t off);

    inline bool empty() const;
    inline bool can_read() const;
    inline bool can_write() const;
    inline bool done() const;
};

void jbuffer::append(char ch) {
    if (tail_ == cap_) {
        reserve(0);
    }
    buf_[tail_] = ch;
    ++tail_;
}

void jbuffer::append(const unsigned char* first, const unsigned char* last) {
    size_t n = last - first;
    if (cap_ - tail_ < n) {
        reserve(n);
    }
    memcpy(buf_ + tail_, first, n);
    tail_ += n;
}

void jbuffer::append(const char* first, const char* last) {
    append(reinterpret_cast<const unsigned char*>(first),
           reinterpret_cast<const unsigned char*>(last));
}

void jbuffer::append(const char* buf, size_t len) {
    append(reinterpret_cast<const unsigned char*>(buf),
           reinterpret_cast<const unsigned char*>(buf + len));
}

const unsigned char* jbuffer::append_json_chars(const unsigned char* first, const unsigned char* last) {
    const unsigned char* stop = first;
    const char hex[] = "0123456789ABCDEF";
    while (first != last) {
        if (*first == 0) {
        skip:
            append(stop, first);
            append('\x7F');
            ++first;
            stop = first;
        } else if (*first < 32 || *first == '\\' || *first == '\"') {
            append(stop, first);
            append('\\');
            if (*first == '\b') {
                append('b');
            } else if (*first == '\f') {
                append('f');
            } else if (*first == '\n') {
                append('n');
            } else if (*first == '\r') {
                append('r');
            } else if (*first == '\t') {
                append('t');
            } else if (*first >= 32) {
                append(*first);
            } else {
                append('u');
                append('0');
                append('0');
                append(hex[*first / 16]);
                append(hex[*first % 16]);
            }
            ++first;
            stop = first;
        } else if (*first < 0x80) {
            ++first;
        } else if (*first < 0xC2 || *first > 0xF4) {
            goto skip;
        } else if (last - first == 1) {
            break;
        } else if (first[1] < 0x80 || first[1] > 0xBF) {
            goto skip;
        } else if (*first < 0xE0) {
            first += 2;
        } else if ((*first == 0xE0 && first[1] < 0xA0)
                   || (*first == 0xED && first[1] > 0x9F)
                   || (*first == 0xF0 && first[1] < 0x90)
                   || (*first == 0xF4 && first[1] > 0x8F)) {
            goto skip;
        } else if (last - first == 2) {
            break;
        } else if (first[2] < 0x80 || first[2] > 0xBF) {
            goto skip;
        } else if (*first < 0xF0) {
            first += 3;
        } else if (last - first == 3) {
            break;
        } else if (first[3] < 0x80 || first[3] > 0xBF) {
            goto skip;
        } else {
            first += 4;
        }
    }
    append(stop, first);
    return first;
}

void jbuffer::reserve(size_t n) {
    if (n == 0) {
        n = std::min(cap_, size_t(131072));
    }
    size_t ncap = cap_;
    while (tail_ + n > ncap) {
        ncap = std::min(ncap * 2, ncap + 131072);
    }
    unsigned char* nbuf = new unsigned char[ncap];
    memcpy(nbuf, buf_, tail_);
    delete[] buf_;
    buf_ = nbuf;
    cap_ = ncap;
}

bool jbuffer::read(int from) {
    bool any = false;
    if (from >= 0 && !rclosed_ && tail_ != cap_) {
        ssize_t nr = ::read(from, &buf_[tail_], cap_ - tail_);
        if (nr != 0 && nr != -1) {
            tail_ += nr;
            any = true;
        } else if (nr == 0) {
            rclosed_ = true;
        } else if (nr == -1 && errno != EINTR && errno != EAGAIN) {
            rclosed_ = true;
            rerrno_ = errno;
        }
    }
    return any;
}

bool jbuffer::write(int to, size_t& off) {
    assert(off >= bufpos_ + head_ && off <= bufpos_ + tail_);
    bool any = false;
    if (to >= 0 && !wclosed_ && off != bufpos_ + tail_) {
        ssize_t nw = ::write(to, &buf_[off - bufpos_], bufpos_ + tail_ - off);
        if (nw != 0 && nw != -1) {
            off += nw;
            any = true;
        } else if (errno != EINTR && errno != EAGAIN) {
            wclosed_ = true;
        }
    }
    return any;
}

void jbuffer::consume_to(size_t off) {
    assert(off >= bufpos_ + head_ && off <= bufpos_ + tail_);
    head_ = off - bufpos_;
    if (tail_ >= 3 * cap_ / 4) {
        memmove(buf_, &buf_[head_], tail_ - head_);
        tail_ -= head_;
        bufpos_ += head_;
        head_ = 0;
    }
}

bool jbuffer::empty() const {
    return head_ == tail_;
}

bool jbuffer::can_read() const {
    return !rclosed_ && !wclosed_ && tail_ != cap_;
}

bool jbuffer::can_write() const {
    return !wclosed_ && head_ != tail_;
}

bool jbuffer::done() const {
    return rclosed_ && head_ == tail_;
}


struct esfd {
    int fd_;
    jbuffer jbuf_;
    size_t output_off_;
    size_t off_ = 0;

    esfd(int fd, size_t output_off)
        : fd_(fd), jbuf_(4096), output_off_(output_off) {
    }
    void write_header();
    void write_event(jbuffer& jbuf);
};

void esfd::write_header() {
    const char message[] = "HTTP/1.1 200 OK\r\nCache-Control: no-store\r\nContent-Type: text/event-stream\r\nX-Accel-Buffering: no\r\n\r\n";
    write(fd_, message, sizeof(message) - 1);
}

void esfd::write_event(jbuffer& jbuf) {
    char xbuf[2048];
    size_t n = snprintf(xbuf, sizeof(xbuf), "data:{\"offset\":%zu,\"data\":\"", output_off_);
    jbuf_.append(xbuf, n);
    const unsigned char* stop = jbuf_.append_json_chars(jbuf.buf_ + output_off_ - jbuf.bufpos_, jbuf.buf_ + jbuf.tail_);
    size_t newoff = jbuf.bufpos_ + (stop - jbuf.buf_);
    n = snprintf(xbuf, sizeof(xbuf), "\",\"end_offset\":%zu}\nid:%zu\n\n", newoff, newoff);
    jbuf_.append(xbuf, n);
    output_off_ = newoff;
}


// cgroup v2 resource limits.
//
// Everything here runs in the host-ns root parent (before/around `clone`), so it
// uses ordinary `/sys/fs/cgroup` paths and full root credentials, and never has
// to reach into the pivoted jail. All jails live under one pool cgroup
// `<base>/pa-jail` (so they can later be limited together); each run gets its own
// leaf `<base>/pa-jail/<pid>` under it carrying that jail's limits, and the
// cloned child (and so all student code) is born into the leaf. Cleanup needs
// root (you can only rmdir a child of the root-owned pool as root), so rather
// than keep a privileged reaper alive for the whole run, each setup reclaims the
// empty leaves of *previous* finished runs -- folding cleanup into work the root
// parent must do anyway.
#if __linux__

// clone3 with CLONE_INTO_CGROUP (Linux 5.7+) starts the child already inside the
// target cgroup, so its limits apply from birth -- no placement race.
// `struct clone_args`/`CLONE_INTO_CGROUP` come from <linux/sched.h>, `SYS_clone3`
// from <sys/syscall.h>. If the build's kernel headers predate that, cgroup
// support is compiled out (PA_HAVE_CGROUP 0): the binary still builds and runs,
// but a configured cgroup limit fails closed at run time (cgroup_setup below)
// rather than silently running unconfined.
#if defined(CLONE_INTO_CGROUP) && defined(SYS_clone3)
# define PA_HAVE_CGROUP 1
#else
# define PA_HAVE_CGROUP 0
#endif

// Each cgroup-v2 limit binds one controller and one interface file. (Used to
// decide which controllers to delegate and which files to write; needed in both
// build variants, so it sits outside the PA_HAVE_CGROUP guard.)
struct cgroup_limit_info {
    int id;                     // jaillimit_id
    const char* controller;     // cgroup.subtree_control name
    const char* file;           // interface filename under the cgroup
};
static const cgroup_limit_info cgroup_limit_infos[] = {
    { JLIMIT_PIDS_MAX,    "pids",   "pids.max" },
    { JLIMIT_CPU_MAX,     "cpu",    "cpu.max" },
    { JLIMIT_MEMORY_MAX,  "memory", "memory.max" },
    { JLIMIT_MEMORY_HIGH, "memory", "memory.high" }
};

// True if `lim` sets any cgroup-controller limit. (All current limits are cgroup
// limits; future `rlimit.*` limits would not appear in the table above.)
static bool cgroup_any(const jaillimits& lim) {
    for (const cgroup_limit_info& info : cgroup_limit_infos) {
        if (lim[info.id].set) {
            return true;
        }
    }
    return false;
}

#if PA_HAVE_CGROUP
static const char* cgroup_base = "/sys/fs/cgroup";    // cgroup v2 unified mount

// Write `value` to a cgroup control file. Fail-safe: any error dies, so a
// configured limit never silently fails to apply. (Runs only in the parent,
// before `clone`, so a die here is clean.)
static void cgroup_write(const std::string& path, const std::string& value) {
    if (verbose) {
        fprintf(verbosefile, "echo %s > %s\n", shell_quote(value).c_str(), path.c_str());
    }
    if (dryrun) {
        return;
    }
    int fd = open(path.c_str(), O_WRONLY | O_CLOEXEC);
    if (fd < 0) {
        perror_die("cgroup " + path);
    }
    ssize_t n = write(fd, value.data(), value.size());
    int e = errno;
    close(fd);
    if (n != (ssize_t) value.size()) {
        errno = e;
        perror_die("cgroup " + path);
    }
}

static void cgroup_mkdir(const std::string& path) {
    if (verbose) {
        fprintf(verbosefile, "mkdir %s\n", path.c_str());
    }
    if (!dryrun && mkdir(path.c_str(), 0755) != 0 && errno != EEXIST) {
        perror_die("mkdir " + path);
    }
}

// Is `ctrl` listed in a cgroup.controllers / subtree_control string (a run of
// space/newline-separated controller names)?
static bool cgroup_has_controller(const std::string& list, const char* ctrl) {
    size_t n = strlen(ctrl), pos = 0;
    while (pos < list.size()) {
        size_t end = pos;
        while (end < list.size() && !isspace((unsigned char) list[end])) {
            ++end;
        }
        if (end - pos == n && memcmp(list.data() + pos, ctrl, n) == 0) {
            return true;
        }
        for (pos = end; pos < list.size() && isspace((unsigned char) list[pos]); ++pos) {
        }
    }
    return false;
}

// Enable each controller in `need` in `dir`'s subtree_control, so dir's children
// can carry it. Already-delegated controllers are skipped, so on a systemd host
// (which already delegates cpu/pids at the root) this is a no-op for the base.
static void cgroup_delegate(const std::string& dir,
                            const std::vector<const char*>& need) {
    std::string enabled = dryrun ? std::string()
        : file_get_contents(dir + "/cgroup.subtree_control", 0);
    for (const char* c : need) {
        if (!cgroup_has_controller(enabled, c)) {
            cgroup_write(dir + "/cgroup.subtree_control", std::string("+") + c);
        }
    }
}

// Reclaim the leaves of previous finished runs: rmdir each `<pool>/<N>` whose
// owning pa-jail process `N` is gone. A still-running jail is skipped two ways --
// its owner is alive, and its leaf is populated so rmdir would fail anyway -- so
// neither a running nor a concurrently-starting run is disturbed. Best-effort:
// failures are ignored (at worst an empty dir lingers to the next run).
static void cgroup_reclaim_stale(const std::string& pool) {
    DIR* d = opendir(pool.c_str());
    if (!d) {
        return;
    }
    while (struct dirent* de = readdir(d)) {
        char* end;
        long pid = strtol(de->d_name, &end, 10);
        if (end == de->d_name || *end != '\0' || pid <= 0
            || kill((pid_t) pid, 0) == 0 || errno != ESRCH) {
            continue;           // not a `<pid>` leaf, or its owner may be alive
        }
        std::string leaf = pool + "/" + de->d_name;
        if (verbose) {
            fprintf(verbosefile, "rmdir %s\n", leaf.c_str());
        }
        if (!dryrun) {
            rmdir(leaf.c_str());        // fails harmlessly if not yet empty
        }
    }
    closedir(d);
}

// The value to write to a limit's interface file: `max` for unlimited, else the
// number -- except `cpu.max`, which is "$QUOTA $PERIOD" in microseconds (PERIOD
// pinned at 100ms, QUOTA = millicores * 100).
static std::string cgroup_limit_value(int id, const jaillimit& l) {
    if (id == JLIMIT_CPU_MAX) {
        return l.unlimited ? "max 100000"
                           : std::to_string(l.value * 100) + " 100000";
    }
    return l.unlimited ? "max" : std::to_string(l.value);
}

// Append the controllers that `lim`'s set limits need to `need` (deduplicated).
static void cgroup_add_controllers(const jaillimits& lim,
                                   std::vector<const char*>& need) {
    for (const cgroup_limit_info& info : cgroup_limit_infos) {
        if (!lim[info.id].set) {
            continue;
        }
        bool present = false;
        for (const char* c : need) {
            present = present || strcmp(c, info.controller) == 0;
        }
        if (!present) {
            need.push_back(info.controller);
        }
    }
}

// Write each set limit in `lim` to its interface file under `dir`.
static void cgroup_write_limits(const std::string& dir, const jaillimits& lim) {
    for (const cgroup_limit_info& info : cgroup_limit_infos) {
        if (lim[info.id].set) {
            cgroup_write(dir + "/" + info.file,
                         cgroup_limit_value(info.id, lim[info.id]));
        }
    }
}

// Resolve a `cgroupbase` config value to an absolute cgroup-v2 path. A leading
// `$SELF` expands to pa-jail's own cgroup, read from /proc/self/cgroup (the v2
// `0::/<path>` line); any other value is literal. The result must sit under the
// v2 mount and contain no `..` (cheap defense -- the conf is root-owned, but).
static std::string cgroup_resolve_pool(const std::string& base) {
    std::string path;
    if (base == "$SELF" || base.starts_with("$SELF/")) {
        std::string self = file_get_contents("/proc/self/cgroup", 2);
        size_t nl = self.find('\n');
        std::string_view line(self.data(), nl == std::string::npos ? self.size() : nl);
        if (!line.starts_with("0::/")) {
            die("cgroup: cannot resolve $SELF: no cgroup v2 line in /proc/self/cgroup\n");
        }
        // our cgroup path after `0::`, with the root `/` reduced to "" so the
        // join below never doubles a slash
        std::string selfpath = path_noendslash(std::string(line.substr(3)));
        if (selfpath == "/") {
            selfpath.clear();
        }
        path = cgroup_base + selfpath + base.substr(5);     // text after `$SELF`
    } else {
        path = base;
    }
    path = path_noendslash(path);
    if (!path.starts_with(std::string(cgroup_base) + "/")
        || path.find("/../") != std::string::npos
        || path.ends_with("/..")) {
        die("cgroup: invalid pool path `%s` (must be under %s, no `..`)\n",
            path.c_str(), cgroup_base);
    }
    return path;
}

// Create and configure this run's leaf cgroup, returning its path (empty if no
// cgroup limit is set anywhere, i.e. the feature is opt-in). The pool is the
// jail's `cgroupbase` (resolved); the leaf is `<pool>/<pid>`. cgroup v2 makes the
// effective limit `min(leaf, pool, ...ancestors)`, so per-jail leaf limits and a
// shared pool cap compose for free.
static std::string cgroup_setup(const pajailconf& conf, const jailperm& perm) {
    const jaillimits& leaf_lim = perm.limits;
    jaillimits pool_lim = conf.pool_limits(perm.cgroupbase);
    if (!cgroup_any(leaf_lim) && !cgroup_any(pool_lim)) {
        return std::string();
    }

    // controllers needed by either the per-jail leaf or the shared pool
    std::vector<const char*> need;
    cgroup_add_controllers(leaf_lim, need);
    cgroup_add_controllers(pool_lim, need);

    // The pool's parent delegates the controllers down to the pool, and the pool
    // down to its per-run leaves. The pool is created once and left in place
    // (idle pools are free) and carries the aggregate cap shared by its jails.
    std::string pool = cgroup_resolve_pool(perm.cgroupbase);
    cgroup_delegate(path_noendslash(path_parentdir(pool)), need);
    cgroup_mkdir(pool);
    cgroup_write_limits(pool, pool_lim);
    cgroup_delegate(pool, need);

    // clean up leaves from previous finished runs, then make this run's leaf (the
    // explicit rmdir clears a stale leaf left by a crashed run that reused our
    // pid; reclaim skips that one because we, its owner, are alive)
    cgroup_reclaim_stale(pool);
    std::string leaf = pool + "/" + std::to_string(getpid());
    if (!dryrun) {
        rmdir(leaf.c_str());
    }
    cgroup_mkdir(leaf);
    cgroup_write_limits(leaf, leaf_lim);
    return leaf;
}

#else  // !PA_HAVE_CGROUP

// Built without cgroup support (kernel headers older than 5.7). Fail closed if
// the config actually sets a cgroup limit; otherwise behave as if none was given.
static std::string cgroup_setup(const pajailconf& conf, const jailperm& perm) {
    if (cgroup_any(perm.limits) || cgroup_any(conf.pool_limits(perm.cgroupbase))) {
        die("cgroup limits configured, but this pa-jail was built without cgroup "
            "support (needs Linux kernel headers >= 5.7); rebuild on a newer host "
            "or remove the limits\n");
    }
    return std::string();
}

#endif  // PA_HAVE_CGROUP
#endif  // __linux__


class jailownerinfo {
  public:
    uid_t owner_ = ROOT;
    gid_t group_ = ROOT;
    std::string owner_home_;
    std::string owner_sh_;

    jailownerinfo();
    ~jailownerinfo();

    void init(const char* owner_name);
    void set_inputfd(int inputfd);
    void set_timeout(double timeout, double idle_timeout);
    void set_foreground(bool foreground);
    void exec(int argc, char** argv, jaildirinfo& jaildir, jaildirinfo& permjail);
    int exec_go();

  private:
    std::vector<const char*> newenv_;
    char** argv_ = nullptr;
    jaildirinfo* jaildir_ = nullptr;
    jaildirinfo* permjail_ = nullptr;
    int inputfd_ = -1;
    double timeout_ = -1.0;
    double idle_timeout_ = -1.0;
    bool foreground_= false;
    struct timeval start_time_;
    struct timeval expiry_;
    struct timeval active_time_;
    struct timeval idle_expiry_;
    jbuffer to_slave_;
    size_t to_slave_off_ = 0;
    jbuffer from_slave_;
    size_t from_slave_off_ = 0;
    std::list<esfd> esfds_;
    bool stdin_tty_;
    bool stdout_tty_;
    bool stderr_tty_;
    int ttyfd_;
    struct termios ttyfd_termios_;
    int child_status_ = -1;
    bool has_blocked_;
    unsigned long long timing_msec_ = 0;
    unsigned long long timing_offset_ = 0;
    size_t timing_count_ = 0;

    void start_sigpipe();
    void block(int ptymaster);
    int check_child_timeout(pid_t child, bool waitpid);
    void wait_background(pid_t child, int ptymaster);
    void write_timing();
    void exec_go_pty(int ptymaster, const char* ptyslavename, pid_t child);
    [[noreturn]] void exec_done(pid_t child, int exit_status);
};

jailownerinfo::jailownerinfo()
    : to_slave_(4096), from_slave_(8192) {
    stdin_tty_ = isatty(STDIN_FILENO);
    stdout_tty_ = isatty(STDOUT_FILENO);
    stderr_tty_ = isatty(STDERR_FILENO);
    // Assume all tty-opened fds are to the same tty.
    if (stdin_tty_ || stdout_tty_ || stderr_tty_) {
        ttyfd_ = stdin_tty_ ? STDIN_FILENO : (stdout_tty_ ? STDOUT_FILENO : STDERR_FILENO);
        tcgetattr(ttyfd_, &ttyfd_termios_);
    } else {
        ttyfd_ = -1;
    }
    auto stdout_off = lseek(STDOUT_FILENO, 0, SEEK_CUR);
    from_slave_.bufpos_ = from_slave_off_ = stdout_off < 0 ? 0 : stdout_off;
}

jailownerinfo::~jailownerinfo() {
    delete[] argv_;
}

void jailownerinfo::set_inputfd(int inputfd) {
    assert(inputfd_ < 0);
    inputfd_ = inputfd;
}

static bool check_shell(const char* shell) {
    bool found = false;
    char* sh;
    while (!found && (sh = getusershell())) {
        found = strcmp(sh, shell) == 0;
    }
    endusershell();
    return found;
}

void jailownerinfo::init(const char* owner_name) {
    if (strlen(owner_name) >= 1024) {
        die("%s: Username too long\n", owner_name);
    }

    struct passwd* pwnam = getpwnam(owner_name);
    if (!pwnam) {
        die("%s: No such user\n", owner_name);
    }

    owner_ = pwnam->pw_uid;
    group_ = pwnam->pw_gid;
    if (strcmp(pwnam->pw_dir, "/") == 0) {
        owner_home_ = "/home/nobody";
    } else if (strncmp(pwnam->pw_dir, "/home/", 6) == 0) {
        owner_home_ = pwnam->pw_dir;
    } else {
        die("%s: Home directory %s not under /home\n", owner_name, pwnam->pw_dir);
    }

    if (strcmp(pwnam->pw_shell, "/bin/bash") == 0
        || strcmp(pwnam->pw_shell, "/bin/sh") == 0
        || check_shell(pwnam->pw_shell)) {
        owner_sh_ = pwnam->pw_shell;
    } else {
        die("%s: Shell %s not allowed by /etc/shells\n", owner_name, pwnam->pw_shell);
    }

    if (owner_ == ROOT) {
        die("%s: Jail user cannot be root\n", owner_name);
    }
}

#if __linux__
extern "C" {
static int exec_clone_function(void* arg) {
    jailownerinfo* jailowner = static_cast<jailownerinfo*>(arg);
    return jailowner->exec_go();
}
}
#endif

static void write_pid(int p) {
    if (pidfd < 0) {
        return;
    }
    lseek(pidfd, 0, SEEK_SET);
    char buf[1024], *sx = buf;
    if (p > 0) {
        const char* s0 = pidcontents.data(), *s1 = s0 + pidcontents.length();
        while (s0 != s1 && sx != buf + 1024) {
            if (*s0 == '$' && s0 + 1 != s1 && s0[1] == '$') {
                int l = snprintf(sx, buf + 1024 - sx, "%d", p);
                sx += std::min(ssize_t(l), buf + 1024 - sx);
                s0 += 2;
            } else {
                *sx++ = *s0++;
            }
        }
    } else {
        *sx++ = '*';
    }
    if (sx != buf && sx != buf + 1024 && sx[-1] != '\n') {
        *sx++ = '\n';
    }
    ssize_t w = write(pidfd, buf, sx - buf);
    if (w != ssize_t(sx - buf) || ftruncate(pidfd, w) != 0) {
        perror_die(pidfilename);
    }
}

static struct timeval timer_add_delay(struct timeval tv, double delay) {
    struct timeval delta;
    double sec, usec;
    usec = modf(delay, &sec);
    delta.tv_sec = (long) sec;
    delta.tv_usec = (long) (usec * 1'000'000);
    timeradd(&tv, &delta, &tv);
    return tv;
}

static int timer_difference_ms(const struct timeval& lhs, struct timeval rhs) {
    timersub(&lhs, &rhs, &rhs);
    return rhs.tv_sec * 1000 + rhs.tv_usec / 1000;
}

void jailownerinfo::set_timeout(double timeout, double idle_timeout) {
    this->timeout_ = timeout;
    this->idle_timeout_ = idle_timeout;
}

void jailownerinfo::set_foreground(bool foreground) {
    this->foreground_ = foreground;
}

void jailownerinfo::exec(int argc, char** argv, jaildirinfo& jaildir, jaildirinfo& permjail) {
    // adjust environment; make sure we have a PATH
    char homebuf[8192];
    snprintf(homebuf, sizeof(homebuf), "HOME=%s", owner_home_.c_str());
    const char* path = "PATH=/usr/local/bin:/bin:/usr/bin";
    const char* lang = "LANG=C";
    const char* term = nullptr;
    const char* ld_library_path = nullptr;
    {
        extern char** environ;
        for (char** eptr = environ; *eptr; ++eptr) {
            if (strncmp(*eptr, "PATH=", 5) == 0) {
                path = *eptr;
            } else if (strncmp(*eptr, "LANG=", 5) == 0) {
                lang = *eptr;
            } else if (strncmp(*eptr, "TERM=", 5) == 0) {
                term = *eptr;
            } else if (strncmp(*eptr, "LD_LIBRARY_PATH=", 16) == 0) {
                ld_library_path = *eptr;
            }
        }
    }
    newenv_.push_back(path);
    newenv_.push_back(lang);
    if (term) {
        newenv_.push_back(term);
    }
    if (ld_library_path) {
        newenv_.push_back(ld_library_path);
    }
    newenv_.push_back(homebuf);
    while (argc > 0) {
        const char* arg = argv[0];
        const char* argpos = arg;
        while (*argpos && (isalnum((unsigned char) *argpos) || *argpos == '_')) {
            ++argpos;
        }
        if (arg == argpos || *argpos != '=') {
            break;
        }
        std::vector<const char*>::size_type i = 0;
        while (i < newenv_.size() && strncmp(newenv_[i], arg, argpos - arg) != 0) {
            ++i;
        }
        if (i < newenv_.size()) {
            newenv_[i] = arg;
        } else {
            newenv_.push_back(arg);
        }
        --argc, ++argv;
    }
    newenv_.push_back(nullptr);

    // create command
    delete[] argv_;
    argv_ = new char*[5 + argc];
    if (!argv_) {
        die("Out of memory\n");
    }
    int newargvpos = 0;
    std::string command;
    argv_[newargvpos++] = (char*) owner_sh_.c_str();
    argv_[newargvpos++] = (char*) "-l";
    if (argc == 0) {
        // just a login shell
    } else {
        argv_[newargvpos++] = (char*) "-c";
        if (argc == 1) {
            command = argv[0];
        } else {
            command = shell_quote(argv[0]);
            for (int i = 0; i < argc; ++i) {
                command += std::string(" ") + shell_quote(argv[i]);
            }
        }
        argv_[newargvpos++] = const_cast<char*>(command.c_str());
    }
    argv_[newargvpos++] = nullptr;

    // store other arguments
    this->jaildir_ = &jaildir;
    this->permjail_ = &permjail;
    gettimeofday(&this->start_time_, nullptr);
    if (this->timeout_ > 0) {
        this->expiry_ = timer_add_delay(this->start_time_, this->timeout_);
    } else {
        timerclear(&this->expiry_);
    }
    if (this->idle_timeout_ > 0) {
        this->active_time_ = this->start_time_;
        this->idle_expiry_ = timer_add_delay(this->active_time_, this->idle_timeout_);
    }

    // enter the jail
#if __linux__
    // set up the per-run cgroup (no-op unless cgroup limits are configured)
    std::string cgleaf = cgroup_setup(*permjail_->conf_, permjail_->perm);
    if (verbose) {
        fprintf(verbosefile, "-clone-\n");
    }
    int child;
    if (dryrun) {
        exec_clone_function(this);
        exit(0);
    } else if (cgleaf.empty()) {
        char* new_stack = (char*) malloc(256 * 1024);
        if (!new_stack) {
            die("Out of memory\n");
        }
        child = clone(exec_clone_function, new_stack + 256 * 1024,
                      CLONE_NEWIPC | CLONE_NEWNS | CLONE_NEWPID | SIGCHLD, this);
        if (child == -1) {
            perror_die("clone");
        }
    } else {
#if PA_HAVE_CGROUP
        // clone3 + CLONE_INTO_CGROUP: the child is born inside the leaf, so its
        // limits apply from the start and student code (forked much later) can
        // never run unconfined -- no placement race, no barrier. The leaf is
        // reclaimed by a later run's setup; no privileged process lingers.
        int cgfd = open(cgleaf.c_str(), O_RDONLY | O_CLOEXEC | O_DIRECTORY);
        if (cgfd < 0) {
            perror_die("cgroup " + cgleaf);
        }
        struct clone_args ca = {};
        ca.flags = CLONE_NEWIPC | CLONE_NEWNS | CLONE_NEWPID | CLONE_INTO_CGROUP;
        ca.exit_signal = SIGCHLD;
        ca.cgroup = (uint64_t) cgfd;
        long pid = syscall(SYS_clone3, &ca, sizeof(ca));
        if (pid < 0) {
            perror_die("clone3 (cgroup limits need Linux 5.7+)");
        } else if (pid == 0) {
            close(cgfd);
            _exit(exec_go());
        }
        close(cgfd);
        child = (int) pid;
#else
        die("internal error: cgroup leaf without cgroup support\n");  // unreachable
#endif
    }
#else
    int child = fork();
    if (child == 0) {
        exit(exec_go());
    }
#endif
    if (child == -1) {
        perror_die("fork");
    }
    write_pid(child);

    // we don't need file descriptors any more
    close(STDIN_FILENO);
    close(STDOUT_FILENO);
    close(STDERR_FILENO);

    int exit_status = 0;
    if (foreground_) {
        int r = setresgid(caller_group, caller_group, caller_group);
        (void) r;
        r = setresuid(caller_owner, caller_owner, caller_owner);
        (void) r;

        exit_status = x_waitpid(child, 0).second;

        if (ttyfd_ >= 0) {
            tcsetattr(ttyfd_, TCSANOW, &ttyfd_termios_);
        }
    } else {
        pidfd = -1;
    }
    exit(exit_status);
}

int jailownerinfo::exec_go() {
    std::string jdir = jaildir_->perm.dir;
    assert(jdir.back() == '/');
    std::string unmounted_jdir = unmounted(jdir);
    if (unmounted_jdir.back() != '/') {
        unmounted_jdir += '/';
    }

#if __linux__
    mount_status = 2;

    std::string parent_mnt = jdir + "mnt/.parent";
    std::string unmounted_parent_mnt = unmounted_jdir + "mnt/.parent";
    if (v_ensuredir(unmounted_parent_mnt, 0777) < 0) {
        perror_die("mkdir -p " + unmounted_parent_mnt);
    }

    // ensure we truly have a private mount namespace: no shared mounts
    // (some Linux distros, such as Ubuntu 15.10, have / a shared mount
    // by default, which means mount changes propagate despite CLONE_NEWNS.
    // This undoes the shared mount)
    if (verbose) {
        fprintf(verbosefile, "mount --make-rslave /\n");
    }
    if (mount("none", "/", nullptr, MS_REC | MS_SLAVE, nullptr) != 0) {
        perror_die("mount --make-rslave /");
    }

    populate_mount_table();     // ensure we know how to mount /proc
    for (size_t i = 0; i != delayed_mounts.size(); i += 2) {
        handle_mount(delayed_mounts[i], delayed_mounts[i+1], true);
    }
    handle_mount("/proc", jdir + "proc", true);
    handle_mount("/dev/pts", jdir + "dev/pts", true);
    // /dev/pts is mounted `newinstance`, so its pty multiplexor is
    // /dev/pts/ptmx. posix_openpt() requires a /dev/ptmx → /dev/pts/ptmx
    // symlink to this new multiplexor; we install it unconditionally.
    std::string ptmx = jdir + "dev/ptmx";
    x_rm_f(ptmx);
    x_symlink("pts/ptmx", ptmx.c_str());
    handle_mount("/tmp", jdir + "tmp", true);
    handle_mount("/run", jdir + "run", true);
#endif

    // chroot
#if __linux__
    if (unmounted_jdir == jdir) {
        if (verbose) {
            fprintf(verbosefile, "mount --bind %s\n", jdir.c_str());
        }
        if (!dryrun
            && mount(jdir.c_str(), jdir.c_str(), nullptr, MS_BIND | MS_REC, nullptr) != 0) {
            perror_die("mount --bind " + jdir);
        }
    }
    if (verbose) {
        fprintf(verbosefile, "pivot_root %s %s\n", jdir.c_str(), parent_mnt.c_str());
    }
    if (!dryrun
        && syscall(SYS_pivot_root, jdir.c_str(), parent_mnt.c_str()) != 0) {
        perror_die("pivot_root " + jdir + " " + parent_mnt);
    }
    if (verbose) {
        fprintf(verbosefile, "cd /\n");
    }
    if (!dryrun && chdir("/") != 0) {
        perror_die("cd");
    }
    std::string new_parent_mnt = parent_mnt.substr(jdir.size() - 1);
    if (verbose) {
        fprintf(verbosefile, "umount %s\n", new_parent_mnt.c_str());
    }
    if (!dryrun
        && umount2(new_parent_mnt.c_str(), MNT_DETACH) != 0) {
        perror_die("umount " + new_parent_mnt);
    }
#else
    if (verbose) {
        fprintf(verbosefile, "cd %s\n", jdir.c_str());
    }
    if (!dryrun && chdir(jdir.c_str()) != 0) {
        perror_die(jdir);
    }
    if (verbose) {
        fprintf(verbosefile, "chroot .\n");
    }
    if (!dryrun && chroot(".") != 0) {
        perror_die("chroot");
    }
#endif

    // upgrade privileges
    if (verbose) {
        fprintf(verbosefile, "su %s\n", uid_to_name(owner_));
    }
    if (!dryrun) {
        // drop all supplementary groups while still privileged;
        // setgroups(2) needs euid 0, which we still have here
        if (setgroups(0, nullptr) != 0) {
            perror_die("setgroups");
        }
        // change effective uid/gid, but save root for later
        if (setresgid(group_, group_, ROOT) != 0) {
            perror_die("setresgid");
        }
        if (setresuid(owner_, owner_, ROOT) != 0) {
            perror_die("setresuid");
        }
    }

    // create a pty
    int ptymaster = -1;
    char* ptyslavename = nullptr;
    if (verbose) {
        fprintf(verbosefile, "make-pty\n");
    }
    if (!dryrun) {
        // create pty
        if ((ptymaster = posix_openpt(O_RDWR | O_NOCTTY)) == -1) {
            perror_die("posix_openpt");
        }
        struct termios tty;
        if (tcgetattr(ptymaster, &tty) >= 0) {
            tty.c_iflag |= BRKINT | IGNPAR | IMAXBEL;
#ifdef IUTF8
            tty.c_iflag |= IUTF8;
#endif
            tcsetattr(ptymaster, TCSANOW, &tty);
        }
        if (grantpt(ptymaster) == -1) {
            perror_die("grantpt");
        }
        if (unlockpt(ptymaster) == -1) {
            perror_die("unlockpt");
        }
        if ((ptyslavename = ptsname(ptymaster)) == nullptr) {
            perror_die("ptsname");
        }
    }

    // change into their home directory
    if (verbose) {
        fprintf(verbosefile, "cd %s\n", owner_home_.c_str());
    }
    if (!dryrun && chdir(owner_home_.c_str()) != 0) {
        perror_die(owner_home_);
    }

    // check that shell exists
    if (!dryrun && access(owner_sh_.c_str(), R_OK | X_OK) != 0) {
        perror_die(owner_sh_);
    }

    // print ready marker
    if (!ready_marker.empty()) {
        if (verbose) {
            bool nl = ready_marker.back() == '\n';
            fprintf(verbosefile, "echo %s%s%s", nl ? "" : "-n ", ready_marker.c_str(), nl ? "" : "\n");
        }
        if (!dryrun) {
            fputs(ready_marker.c_str(), stdout);
            fflush(stdout);
        }
    }

    // run command
    if (verbose) {
        for (int i = 0; newenv_[i]; ++i) {
            fprintf(verbosefile, "%s ", newenv_[i]);
        }
        for (int i = 0; argv_[i]; ++i) {
            fprintf(verbosefile, i ? " %s" : "%s", shell_quote(argv_[i]).c_str());
        }
        fprintf(verbosefile, "\n");
    }

    if (!dryrun) {
        start_sigpipe();
        pid_t child = fork();
        if (child < 0) {
            perror_die("fork");
        } else if (child == 0) {
            child = getpid();
#if __linux__
            // sigfd is close-on-exec, but need to unblock signals
            sigset_t mask;
            sigemptyset(&mask);
            if (sigprocmask(SIG_SETMASK, &mask, nullptr) == -1) {
                perror_die("sigprocmask");
            }
#else
            close(sigpipe[0]);
            close(sigpipe[1]);
#endif

            // prevent the exec'd program from acquiring new
            // privileges (e.g. via a setuid binary in the jail);
            // preserved across execve
#if __linux__
            if (prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0) {
                perror_die("prctl(PR_SET_NO_NEW_PRIVS)");
            }
#endif

            // reduce privileges permanently
            if (setresgid(group_, group_, group_) != 0) {
                perror_die("setresgid");
            }
            if (setresuid(owner_, owner_, owner_) != 0) {
                perror_die("setresuid");
            }
            if (setsid() == -1) {
                perror_die("setsid");
            }
            if (ptyslavename) {
                exec_go_pty(ptymaster, ptyslavename, child);
            }

            // restore all signals to their default actions
            // (e.g., PHP may have ignored SIGPIPE; don't want that
            // to propagate to student code!)
            for (int sig = 1; sig < NSIG; ++sig) {
                signal(sig, SIG_DFL);
            }

            int r = execve(argv_[0], (char* const*) argv_,
                           (char* const*) newenv_.data());

            assert(r < 0);
            fprintf(stderr, "exec %s: %s\n", owner_sh_.c_str(), strerror(errno));
            exit(126);
        }

        wait_background(child, ptymaster);
    }

    return 0;
}

void jailownerinfo::exec_go_pty(int ptymaster, const char* ptyslavename, pid_t child) {
    int ptyslave = open(ptyslavename, O_RDWR);
    if (ptyslave == -1) {
        perror_die(ptyslavename);
    }
    close(ptymaster);
#ifdef TIOCSCTTY
    ioctl(ptyslave, TIOCSCTTY, 0);
#endif
    tcsetpgrp(ptyslave, child);
#ifdef TIOCGWINSZ
    if (tsize[0] > 0) {
        struct winsize ws;
        ioctl(ptyslave, TIOCGWINSZ, &ws);
        ws.ws_row = tsize[1];
        ws.ws_col = tsize[0];
        ioctl(ptyslave, TIOCSWINSZ, &ws);
    }
#endif
    struct termios tty;
    if (tcgetattr(ptyslave, &tty) >= 0) {
        tty.c_iflag |= BRKINT | IGNPAR | IMAXBEL;
#ifdef IUTF8
        tty.c_iflag |= IUTF8;
#endif
        if (no_onlcr) {
            tty.c_oflag &= ~ONLCR;
        }
        tcsetattr(ptyslave, TCSANOW, &tty);
    }

    if (inputfd_ > 0 || stdin_tty_) {
        dup2(ptyslave, STDIN_FILENO);
    }
    if (inputfd_ > 0 || stdout_tty_) {
        dup2(ptyslave, STDOUT_FILENO);
    }
    if (inputfd_ > 0 || stderr_tty_) {
        dup2(ptyslave, STDERR_FILENO);
    }
    close(ptyslave);
}

extern "C" {
#if !__linux__
void sighandler(int signo) {
    if (signo == SIGTERM) {
        got_sigterm = 1;
    }
    char c = (char) signo;
    ssize_t w = write(sigpipe[1], &c, 1);
    (void) w;
}
#endif
}

static void make_nonblocking(int fd) {
    fcntl(fd, F_SETFL, fcntl(fd, F_GETFL, 0) | O_NONBLOCK);
}

void jailownerinfo::start_sigpipe() {
#if __linux__
    sigset_t mask;
    sigemptyset(&mask);
    sigaddset(&mask, SIGCHLD);
    sigaddset(&mask, SIGTERM);
    if (sigprocmask(SIG_BLOCK, &mask, nullptr) == -1) {
        perror_die("sigprocmask");
    }
    sigfd = signalfd(-1, &mask, SFD_NONBLOCK | SFD_CLOEXEC);
    if (sigfd == -1) {
        perror_die("signalfd");
    }
#else
    int r = pipe(sigpipe);
    if (r != 0) {
        perror_die("pipe");
    }
    make_nonblocking(sigpipe[0]);
    make_nonblocking(sigpipe[1]);

    struct sigaction sa;
    sa.sa_handler = sighandler;
    sigemptyset(&sa.sa_mask);
    sa.sa_flags = 0;
    sigaction(SIGCHLD, &sa, nullptr);
    sigaction(SIGTERM, &sa, nullptr);
#endif

    if (inputfd_ > 0 || stdin_tty_) {
        make_nonblocking(inputfd_);
    }
    if (inputfd_ > 0 || stdout_tty_) {
        make_nonblocking(STDOUT_FILENO);
    }
}


void jailownerinfo::block(int ptymaster) {
    std::vector<pollfd> p;

#if !__linux__
    int sigfd = sigpipe[0];
#endif
    p.push_back({sigfd, POLLIN, 0});

    if (to_slave_.can_read()) {
        p.push_back({inputfd_, POLLIN, 0});
    }

    short ptymaster_events = 0;
    if (from_slave_.can_read()) {
        ptymaster_events |= POLLIN;
    }
    if (to_slave_.can_write()) {
        ptymaster_events |= POLLOUT;
    }
    if (ptymaster_events) {
        p.push_back({ptymaster, ptymaster_events, 0});
    }

    if (from_slave_.can_write()) {
        p.push_back({STDOUT_FILENO, POLLOUT, 0});
    }

    size_t eventsourceindex = 0;
    if (eventsourcefd >= 0) {
        p.push_back({eventsourcefd, POLLIN, 0});
        eventsourceindex = p.size() - 1;
    }
    for (auto& esf : esfds_) {
        if (esf.jbuf_.can_write()) {
            p.push_back({esf.fd_, POLLOUT, 0});
        }
    }

    int timeout_ms = 3600000;
    if (esfds_.size()) {
        timeout_ms = 30000;
    }
    struct timeval now;
    if (timerisset(&expiry_) || idle_timeout_ > 0) {
        gettimeofday(&now, nullptr);
    }
    if (timerisset(&expiry_)) {
        if (timercmp(&now, &expiry_, <)) {
            timeout_ms = std::min(timeout_ms, timer_difference_ms(expiry_, now));
        } else {
            timeout_ms = 0;
        }
    }
    if (timerisset(&idle_expiry_)) {
        if (timercmp(&now, &idle_expiry_, <)) {
            timeout_ms = std::min(timeout_ms, timer_difference_ms(idle_expiry_, now));
        } else {
            timeout_ms = 0;
        }
    }

    int pollr = poll(p.data(), p.size(), 0);
    if (pollr == 0) {
        has_blocked_ = true;
        pollr = poll(p.data(), p.size(), timeout_ms);
    }
    assert(pollr >= 0);

    // read from signal pipe
    if (p[0].revents & POLLIN) {
#if __linux__
        struct signalfd_siginfo ssi;
        ssize_t r;
        while ((r = read(sigfd, &ssi, sizeof(ssi))) == sizeof(ssi)) {
            if (ssi.ssi_signo == SIGTERM) {
                got_sigterm = 1;
            }
        }
        assert(r == 0 || (r == -1 && errno == EAGAIN));
#else
        char buf[128];
        while (read(sigpipe[0], buf, sizeof(buf)) > 0) {
            /* skip */
        }
#endif
    }

    // accept new eventsource connections
    if (eventsourcefd >= 0
        && (p[eventsourceindex].revents & POLLIN)) {
        int cfd = accept(eventsourcefd, nullptr, nullptr);
        if (cfd >= 0) {
            esfds_.emplace_back(cfd, from_slave_.bufpos_ + from_slave_.head_);
            esfds_.back().write_header();
            esfds_.back().write_event(from_slave_);
        }
    }
}

int jailownerinfo::check_child_timeout(pid_t child, bool waitpid) {
    std::pair<pid_t, int> xr;
    do {
        xr = x_waitpid(-1, WNOHANG);
        if (xr.first == child) {
            child_status_ = xr.second;
        }
    } while (xr.first != -1);

    if (errno != EAGAIN && errno != ECHILD) {
        return 125;
    } else if (child_status_ >= 0 && waitpid) {
        return child_status_;
    } else if (got_sigterm) {
        return 128 + SIGTERM;
    } else {
        struct timeval now;
        if (timerisset(&expiry_) || timerisset(&idle_expiry_)) {
            gettimeofday(&now, nullptr);
            if ((timerisset(&expiry_) && timercmp(&now, &expiry_, >))
                || (timerisset(&idle_expiry_) && timercmp(&now, &idle_expiry_, >))) {
                return 124;
            }
        }
        errno = EAGAIN;
        return -1;
    }
}

void jailownerinfo::write_timing() {
    struct timeval now, delta;
    gettimeofday(&now, nullptr);
    timersub(&now, &this->start_time_, &delta);
    unsigned long long deltamsecs = (delta.tv_sec * 1'000'000 + delta.tv_usec) / 1000;
    char timingstr[256];
    ssize_t len;
    if (this->timing_count_ % 128 == 0) {
        len = snprintf(timingstr, sizeof(timingstr), "%llu,%llu\n", deltamsecs, (unsigned long long) from_slave_off_);
    } else {
        len = snprintf(timingstr, sizeof(timingstr), "+%llu,+%llu\n", deltamsecs - this->timing_msec_, (unsigned long long) from_slave_off_ - this->timing_offset_);
    }
    assert(len < 256);
    ssize_t written = 0;
    while (written < len) {
        ssize_t nw = write(timingfd, timingstr, len);
        if (nw < 0) {
            perror_die("Timing file");
        }
        written += nw;
    }
    this->timing_msec_ = deltamsecs;
    this->timing_offset_ = from_slave_off_;
    ++this->timing_count_;
}

void jailownerinfo::wait_background(pid_t child, int ptymaster) {
    // This process is the `init` (pid 1) of the new process namespace.
    // On Linux, if it dies, everything in the jail dies too.

    // go back to being the caller
    if (setresuid(ROOT, ROOT, ROOT) != 0
        || setresgid(caller_group, caller_group, caller_group) != 0
        || setresuid(caller_owner, caller_owner, caller_owner) != 0) {
        perror("setresuid");
        exec_done(child, 127);
    }

    fflush(stdout);

    if (ptymaster >= 0) {
        // if input is a tty, put it in raw mode with short blocking
        if (ttyfd_ >= 0) {
            struct termios tty = ttyfd_termios_;
            cfmakeraw(&tty);
            tty.c_cc[VMIN] = 1;
            tty.c_cc[VTIME] = 1;
            (void) tcsetattr(ttyfd_, TCSANOW, &tty);
        }

        make_nonblocking(ptymaster);
        if (inputfd_ == 0 && !stdin_tty_) {
            close(STDIN_FILENO);
            to_slave_.rclosed_ = to_slave_.wclosed_ = true;
        }
        if (inputfd_ == 0 && !stdout_tty_ && !stderr_tty_) {
            close(STDOUT_FILENO);
            from_slave_.rclosed_ = from_slave_.wclosed_ = true;
            from_slave_.rerrno_ = EIO; // don't misinterpret closed as error
        }
    } else {
        from_slave_.rclosed_ = from_slave_.wclosed_ = true;
        to_slave_.rclosed_ = to_slave_.wclosed_ = true;
        from_slave_.rerrno_ = EIO;
    }

    // listen on unix socket
    if (eventsourcefd > 0
        && listen(eventsourcefd, 50) != 0) {
        perror("listen");
        exec_done(child, 127);
    }

    while (true) {
        // check child and timeout
        // (only wait for child if read done/failed)
        int exit_status = check_child_timeout(child, from_slave_.done());
        if (exit_status != -1) {
            exec_done(child, exit_status);
        }

        // if child has not died, and read produced error, report it
        if (from_slave_.rclosed_ && from_slave_.rerrno_ != EIO) {
            fprintf(stderr, "read: %s%s", strerror(from_slave_.rerrno_), no_onlcr ? "\n" : "\r\n");
            exec_done(child, 125);
        }

        // wait for something to occur
        block(ptymaster);
        bool any = false;

        // transfer data to and from slave
        if (to_slave_.read(inputfd_)) {
            any = true;
        }
        if (!to_slave_.empty()
            && memmem(&to_slave_.buf_[to_slave_.head_], to_slave_.tail_ - to_slave_.head_, "\x1b\x03", 2) != nullptr) {
            exec_done(child, 128 + SIGTERM);
        }
        if (to_slave_.write(ptymaster, to_slave_off_)) {
            to_slave_.consume_to(to_slave_off_);
            any = true;
        }
        if (from_slave_.read(ptymaster)) {
            any = true;
        }
        if (has_blocked_ && timingfd != -1) {
            write_timing();
            has_blocked_ = false;
        }
        if (!from_slave_.empty()) {
            size_t last_off = from_slave_.bufpos_ + from_slave_.tail_;
            for (auto& esf : esfds_) {
                if (esf.output_off_ < last_off) {
                    esf.write_event(from_slave_);
                }
            }
        }
        if (from_slave_.write(STDOUT_FILENO, from_slave_off_)) {
            from_slave_.consume_to(from_slave_off_);
            any = true;
        }

        // transfer events
        for (auto it = esfds_.begin(); it != esfds_.end(); ) {
            if (it->jbuf_.write(it->fd_, it->off_)) {
                it->jbuf_.consume_to(it->off_);
            }
            if (it->jbuf_.wclosed_) {
                close(it->fd_);
                it = esfds_.erase(it);
            } else {
                ++it;
            }
        }

        // maybe reset idle timeout
        if (any && idle_timeout_ > 0) {
            gettimeofday(&active_time_, nullptr);
            idle_expiry_ = timer_add_delay(active_time_, idle_timeout_);
        }
    }
}

void jailownerinfo::exec_done(pid_t child, int exit_status) {
    if (timingfd != -1) {
        write_timing();
    }
    std::string xmsg;
    if (exit_status == 124 && !quiet) {
        xmsg = "...timed out";
    } else if (exit_status == 128 + SIGTERM && !quiet) {
        xmsg = "...terminated";
    } else if (verbose) {
        xmsg = "...terminating with status " + std::to_string(exit_status);
    }
    if (!xmsg.empty()) {
        const char* nl = no_onlcr ? "\n" : "\r\n";
        fprintf(stderr, inputfd_ > 0 || stderr_tty_ ? "%s\x1b[3;7;31m%s\x1b[K\x1b[0m%s\x1b[K%s" : "%s%s%s%s", nl, xmsg.c_str(), nl, nl);
    }
#if __linux__
    (void) child;
#else
    if (exit_status >= 124) {
        kill(child, SIGKILL);
    }
#endif
    if (ttyfd_ >= 0) {
        (void) tcsetattr(ttyfd_, TCSAFLUSH, &ttyfd_termios_);
    }
    fflush(stderr);
    // close event sources
    for (auto& esf : esfds_) {
        esf.jbuf_.append("data:{\"done\":true}\n\n", 20);
    }
    while (true) {
        std::vector<pollfd> p;
        for (auto it = esfds_.begin(); it != esfds_.end(); ) {
            if (it->jbuf_.write(it->fd_, it->off_)) {
                it->jbuf_.consume_to(it->off_);
            }
            if (it->jbuf_.wclosed_ || !it->jbuf_.can_write()) {
                close(it->fd_);
                it = esfds_.erase(it);
            } else {
                p.push_back({it->fd_, POLLOUT, 0});
                ++it;
            }
        }
        if (p.empty()) {
            break;
        }
        (void) poll(p.data(), p.size(), 5000);
    }
    exit(exit_status);
}


static void close_unwanted_fds() {
    DIR* dir = opendir("/dev/fd");
    while (auto de = readdir(dir)) {
        if (isdigit((unsigned char) de->d_name[0])) {
            char* ends;
            unsigned long fd = strtoul(de->d_name, &ends, 10);
            if (*ends == '\0' && fd > 2 && fd != (unsigned long) dirfd(dir)) {
                close(fd);
            }
        }
    }
    closedir(dir);
}


[[noreturn]] static void usage(jailaction action = do_start) {
    if (action == do_start) {
        fprintf(stderr, "Usage: pa-jail add [-nh] [-f FILE | -F DATA] [-S SKELETON] JAILDIR [USER]\n\
       pa-jail run [--fg] [-nqh] [-T TIMEOUT] [-I TIMEOUT] [-p PIDFILE] \\\n\
                   [-i INPUT] [-f FILE | -F DATA] [-S SKELETON] \\\n\
                   JAILDIR USER COMMAND\n\
       pa-jail mv SOURCE DEST\n\
       pa-jail rm [-nf] [--bg] JAILDIR\n");
    } else if (action == do_mv) {
        fprintf(stderr, "Usage: pa-jail mv [-n] SOURCE DEST\n\
Safely move a jail from SOURCE to DEST. SOURCE and DEST must be allowed\n\
by /etc/pa-jail.conf.\n\
\n\
  -n, --dry-run     Print actions that would be taken, don't run them\n");
    } else if (action == do_rm) {
        fprintf(stderr, "Usage: pa-jail rm [-nf] [--bg] JAILDIR\n\
Unmount and remove a jail. Like `rm -r[f] --one-file-system JAILDIR`.\n\
JAILDIR must be allowed by /etc/pa-jail.conf.\n\
\n\
  -f, --force       Do not complain if JAILDIR doesn't exist\n\
  -n, --dry-run     Print actions that would be taken, don't run them\n\
  -V, --verbose     Print actions as well as running them\n\
      --bg          Run in the background\n");
    } else {
        if (action == do_add) {
            fprintf(stderr, "Usage: pa-jail add [OPTIONS...] JAILDIR [USER]\n\
Create or augment a jail. JAILDIR must be allowed by /etc/pa-jail.conf.\n\n");
        } else {
            fprintf(stderr, "Usage: pa-jail run [OPTIONS...] JAILDIR USER [NAME=VALUE...] COMMAND...\n\
Run COMMAND as USER in the JAILDIR jail. JAILDIR must be allowed by\n\
/etc/pa-jail.conf.\n\n");
        }
        fprintf(stderr, "  -f, --manifest-file FILE  Populate jail with manifest from FILE\n");
        fprintf(stderr, "  -F, --manifest MANIFEST   Populate jail with MANIFEST\n");
        fprintf(stderr, "  -h, --chown-home          Change ownership of USER homedir\n");
        fprintf(stderr, "  -u, --chown-user DIR      Change ownership of DIR/** to USER\n");
        fprintf(stderr, "  -S, --skeleton SKELDIR    Populate jail from SKELDIR\n");
        if (action == do_run) {
            fprintf(stderr, "  -B, --bind BINDDIR        Build the jail in scaffold BINDDIR\n\
  -p, --pid-file PIDFILE    Write jail process PID to PIDFILE\n\
  -P, --pid-contents STR    Write STR to PIDFILE\n\
  -i, --input INPUTSOCKET   Use TTY, read input from INPUTSOCKET\n\
      --event-source SOCK   Listen on UNIX SOCK for event source connections\n\
      --ready[=STR]         Write STR to stdout when ready\n\
      --onlcr               Translate \\n -> \\r\\n in output [default]\n\
      --no-onlcr            Don't translate \\n -> \\r\\n in output\n\
      --size WxH            Set terminal size [80x25]\n\
  -t, --timing-file FILE    Write output timing data to FILE\n\
  -T, --timeout TIMEOUT     Kill the jail after TIMEOUT seconds\n\
  -I, --idle-timeout TIMEOUT  Kill the jail after TIMEOUT idle seconds\n\
  -q, --quiet               Don't print timeout or termination notices\n\
      --fg                  Run in the foreground\n");
        }
        fprintf(stderr, "  -n, --dry-run             Print actions, don't run them\n\
  -V, --verbose             Print actions and run them\n\
      --help                Print this message\n");
    }
    exit(1);
}

static struct option longoptions_before[] = {
    { "verbose", no_argument, nullptr, 'V' },
    { "dry-run", no_argument, nullptr, 'n' },
    { "help", no_argument, nullptr, 'H' },
    { nullptr, 0, nullptr, 0 }
};

#define ARG_ONLCR        1000
#define ARG_NO_ONLCR     1001
#define ARG_SIZE         1002
#define ARG_EVENT_SOURCE 1003
#define ARG_BG           1004
#define ARG_READY        1005

static struct option longoptions_run[] = {
    { "verbose", no_argument, nullptr, 'V' },
    { "dry-run", no_argument, nullptr, 'n' },
    { "help", no_argument, nullptr, 'H' },
    { "bind", required_argument, nullptr, 'B' },
    { "skeleton", required_argument, nullptr, 'S' },
    { "pid-file", required_argument, nullptr, 'p' },
    { "pid-contents", required_argument, nullptr, 'P' },
    { "contents-file", required_argument, nullptr, 'f' },
    { "contents", required_argument, nullptr, 'F' },
    { "manifest-file", required_argument, nullptr, 'f' },
    { "manifest", required_argument, nullptr, 'F' },
    { "fg", no_argument, nullptr, 'g' },
    { "timeout", required_argument, nullptr, 'T' },
    { "idle-timeout", required_argument, nullptr, 'I' },
    { "input", required_argument, nullptr, 'i' },
    { "chown-home", no_argument, nullptr, 'h' },
    { "chown-user", required_argument, nullptr, 'u' },
    { "onlcr", no_argument, nullptr, ARG_ONLCR },
    { "no-onlcr", no_argument, nullptr, ARG_NO_ONLCR },
    { "timing-file", required_argument, nullptr, 't' },
    { "size", required_argument, nullptr, ARG_SIZE },
    { "event-source", required_argument, nullptr, ARG_EVENT_SOURCE },
    { "ready", optional_argument, nullptr, ARG_READY },
    { "quiet", no_argument, nullptr, 'q' },
    { nullptr, 0, nullptr, 0 }
};

static struct option longoptions_rm[] = {
    { "verbose", no_argument, nullptr, 'V' },
    { "dry-run", no_argument, nullptr, 'n' },
    { "bg", no_argument, nullptr, ARG_BG },
    { "help", no_argument, nullptr, 'H' },
    { "force", no_argument, nullptr, 'f' },
    { nullptr, 0, nullptr, 0 }
};

static struct option* longoptions_action[] = {
    longoptions_before, longoptions_run, longoptions_run, longoptions_rm,
    longoptions_before
};
static const char* shortoptions_action[] = {
    "+Vn", "VnB:S:f:F:p:P:T:I:qi:hu:t:", "VnB:S:f:F:p:P:T:I:qi:hu:t:", "Vnf", "Vn"
};

static bool opt_strtod(double& v) {
    char* end;
    v = strtod(optarg, &end);
    return end != optarg && *end == '\0';
}

static bool range_strtol(long& v, const char* a, const char* b) {
    bool negative = false;
    if (a != b && (*a == '-' || *a == '+')) {
        negative = *a == '-';
        ++a;
    }
    if (a == b || *a < '0' || *a > '9') {
        return false;
    }
    unsigned long val = 0;
    while (a != b && *a >= '0' && *a <= '9') {
        val = 10 * val + *a - '0';
        ++a;
    }
    v = negative ? -val : val;
    return a == b;
}

// May throw an exception
static int jail_main(int argc, char** argv) {
    // parse arguments
    jailaction action = do_start;
    bool chown_home = false, foreground = false;
    double timeout = -1, idle_timeout = -1;
    std::string inputarg, linkarg, manifest, bindarg;
    std::vector<std::string> chown_user_args;
    pidcontents = "$$";

    int ch;
    while (true) {
        while ((ch = getopt_long(argc, argv, shortoptions_action[(int) action],
                                 longoptions_action[(int) action], nullptr)) != -1) {
            if (ch == 'V') {
                verbose = true;
            } else if (ch == 'B') {
                bindarg = optarg;
            } else if (ch == 'S') {
                linkarg = optarg;
            } else if (ch == 'n') {
                verbose = dryrun = true;
            } else if (ch == 'f' && action == do_rm) {
                doforce = true;
            } else if (ch == 'f') {
                manifest += file_get_contents(optarg, 2);
                if (!manifest.empty() && manifest.back() != '\n') {
                    manifest.push_back('\n');
                }
            } else if (ch == 'F') {
                manifest += optarg;
                if (!manifest.empty() && manifest.back() != '\n') {
                    manifest.push_back('\n');
                }
            } else if (ch == 'p' && action == do_run) {
                pidfilename = optarg;
            } else if (ch == 'P' && action == do_run) {
                pidcontents = optarg;
            } else if (ch == 'i') {
                inputarg = optarg;
            } else if (ch == ARG_EVENT_SOURCE) {
                eventsourcefilename = optarg;
            } else if (ch == ARG_ONLCR) {
                no_onlcr = false;
            } else if (ch == ARG_NO_ONLCR) {
                no_onlcr = true;
            } else if (ch == ARG_SIZE) {
                const char* ex;
                if (strcmp(optarg, "none") == 0) {
                    tsize[0] = tsize[1] = 0;
                } else if ((ex = strchr(optarg, 'x'))
                           && range_strtol(tsize[0], optarg, ex)
                           && range_strtol(tsize[1], ex + 1, optarg + strlen(optarg))
                           && tsize[0] > 0
                           && tsize[1] > 0) {
                    /* ok */
                } else {
                    usage();
                }
            } else if (ch == 'g') {
                foreground = true;
            } else if (ch == ARG_BG) {
                foreground = false;
            } else if (ch == ARG_READY) {
                ready_marker = optarg ? optarg : "\n";
            } else if (ch == 'h') {
                chown_home = true;
            } else if (ch == 'q') {
                quiet = true;
            } else if (ch == 'u') {
                chown_user_args.push_back(optarg);
            } else if (ch == 'T') {
                if (!opt_strtod(timeout)) {
                    usage();
                }
            } else if (ch == 'I') {
                char* end;
                idle_timeout = strtod(optarg, &end);
                if (end == optarg || *end != 0) {
                    usage();
                }
            } else if (ch == 't' && action == do_run) {
                timingfilename = optarg;
            } else { /* if (ch == 'H') */
                usage(action);
            }
        }
        if (action != do_start) {
            break;
        }
        if (optind == argc) {
            usage();
        } else if (strcmp(argv[optind], "rm") == 0) {
            action = do_rm;
            foreground = true;
        } else if (strcmp(argv[optind], "mv") == 0) {
            action = do_mv;
        } else if (strcmp(argv[optind], "init") == 0
                   || strcmp(argv[optind], "add") == 0) {
            action = do_add;
        } else if (strcmp(argv[optind], "run") == 0) {
            action = do_run;
        } else {
            usage();
        }
        argc -= optind;
        argv += optind;
        optind = 1;
    }

    // check arguments
    if (action == do_run && optind + 2 >= argc) {
        action = do_add;
    }
    bool has_runarg = !linkarg.empty() || !manifest.empty() || !inputarg.empty() || !eventsourcefilename.empty();
    if ((action == do_rm && optind + 1 != argc)
        || (action == do_mv && optind + 2 != argc)
        || (action == do_add && optind != argc - 1 && optind + 2 != argc)
        || (action == do_run && optind + 3 > argc)
        || (action == do_run && foreground && (!inputarg.empty() || !eventsourcefilename.empty()))
        || (action == do_rm && has_runarg)
        || (action == do_mv && has_runarg)
        || !argv[optind][0]
        || (action == do_mv && !argv[optind+1][0])) {
        usage();
    }
    if (verbose && !dryrun) {
        verbosefile = stderr;
    }

    // parse user
    jailownerinfo jailuser;
    if ((action == do_add || action == do_run) && optind + 1 < argc) {
        jailuser.init(argv[optind + 1]);
    }

    // revert to original user
    caller_owner = getuid();
    caller_group = getgid();
    if (!dryrun) {
        if (seteuid(caller_owner) != 0) {
            perror_die("seteuid");
        }
        if (setegid(caller_group) != 0) {
            perror_die("setegid");
        }
    }

    // close extra file descriptors
    if (action == do_run) {
        close_unwanted_fds();
    }

    // open pidfile as current user
    if (!pidfilename.empty() && verbose) {
        fprintf(verbosefile, "touch %s\nflock %s\n", pidfilename.c_str(), pidfilename.c_str());
    }
    if (!pidfilename.empty() && !dryrun) {
        pidfd = open(pidfilename.c_str(), O_WRONLY | O_CLOEXEC | O_CREAT, 0666);
        if (pidfd == -1) {
            perror_die(pidfilename);
        }
        while (true) {
            int r = flock(pidfd, LOCK_EX);
            if (r == 0) {
                break;
            } else if (r == -1 && errno != EINTR) {
                write_pid(-1);
                perror_die(pidfilename);
            }
        }
        write_pid(-1);
    }

    // open input file non-blocking as current user
    // if it is a named FIFO, open it read-write so we never get EOF
    int inputfd = 0;
    if (!inputarg.empty() && !dryrun) {
        struct stat st;
        int mode = O_RDONLY;
        if (stat(inputarg.c_str(), &st) == 0 && S_ISFIFO(st.st_mode)) {
            mode = O_RDWR;
        }
        inputfd = open(inputarg.c_str(), mode | O_CLOEXEC | O_NONBLOCK);
        if (inputfd == -1) {
            perror_die(inputarg);
        }
    }

    // create event source socket as current user
    if (!eventsourcefilename.empty() && !dryrun) {
        if (verbose) {
            fprintf(verbosefile, "socket %s\n", eventsourcefilename.c_str());
        }
        eventsourcefd = socket(AF_LOCAL, SOCK_STREAM, 0);
        if (eventsourcefd == -1) {
            perror_die("socket");
        }

        mode_t old_umask = umask(S_IROTH | S_IWOTH | S_IXOTH);
        sockaddr_un eventsource_addr;
        eventsource_addr.sun_family = AF_LOCAL;
        if (eventsourcefilename.length() + 1 > sizeof(eventsource_addr.sun_path)) {
            fprintf(stderr, "%s: socket name too long\n", eventsourcefilename.c_str());
            exit(1);
        }
        strcpy(eventsource_addr.sun_path, eventsourcefilename.c_str());
        int r = bind(eventsourcefd, (sockaddr*) &eventsource_addr, sizeof(eventsource_addr));
        if (r < 0) {
            perror_die("bind " + eventsourcefilename);
        }
        umask(old_umask);

        int flags;
        if (fcntl(eventsourcefd, F_SETFD, FD_CLOEXEC) == -1
            || (flags = fcntl(eventsourcefd, F_GETFL)) == -1
            || fcntl(eventsourcefd, F_SETFL, flags | O_NONBLOCK) == -1) {
            perror_die("fcntl");
        }
    } else if (!eventsourcefilename.empty() && verbose) {
        fprintf(verbosefile, "socket %s\n", eventsourcefilename.c_str());
    }

    // create timing file as current user
    if (!timingfilename.empty() && verbose) {
        fprintf(verbosefile, "touch %s\n", timingfilename.c_str());
    }
    if (!timingfilename.empty() && !dryrun) {
        timingfd = open(timingfilename.c_str(), O_WRONLY | O_CLOEXEC | O_CREAT | O_TRUNC, 0666);
        if (timingfd == -1) {
            perror_die(timingfilename);
        }
    }

    // escalate so that the real (not just effective) UID/GID is root. this is
    // so that the system processes will execute as root
    if (!dryrun && setresgid(ROOT, ROOT, ROOT) < 0) {
        perror_die("setresgid");
    }
    if (!dryrun && setresuid(ROOT, ROOT, ROOT) < 0) {
        perror_die("setresuid");
    }

    // check the jail directory
    // - no special characters
    // - path has no symlinks
    // - `/etc/pa-jail.conf` is owned by root, writable only by root
    // - `/etc/pa-jail.conf` enables the jail directory and does not disable
    //   the jail directory
    // - everything above that dir is owned by by root and writable only by
    //   root
    // - stuff below the allowed jail directory dynamically created as
    //   necessary
    // - try to eliminate TOCTTOU
    pajailconf jailconf;
    jaildirinfo jaildir(argv[optind], linkarg, action, jailconf);

    // `pa-jail run --bind BINDDIR` builds the jail in a shared scaffold BINDDIR
    // and binds the real jail's contents into it (via the manifest), rather than
    // building directly in JAILDIR. JAILDIR stays the identity that selects
    // config and gets its home chowned; BINDDIR is the build/pivot root. The
    // scaffold must itself be an allowed jail directory (for now via `enablejail`).
    std::optional<jaildirinfo> bindjail;
    if (!bindarg.empty()) {
        bindjail.emplace(bindarg.c_str(), std::string(), action, jailconf);
    }
    jaildirinfo& buildjail = bindjail ? *bindjail : jaildir;

    // move the sandbox if asked
    if (action == do_mv) {
        std::string newpath = path_pa_validate(path_absolute(argv[optind + 1]));
        if (newpath.empty() || newpath[0] != '/') {
            die("%s: Bad characters in move destination\n", argv[optind + 1]);
        }

        // allow second argument to be a directory
        struct stat s;
        if (stat(newpath.c_str(), &s) == 0 && S_ISDIR(s.st_mode)) {
            newpath = path_endslash(newpath) + jaildir.component;
        }

        // check jail allowance
        if (jailperm perm = jailconf.get(newpath); !perm) {
            die("%s: Destination jail disabled by /etc/pa-jail.conf\n%s",
                newpath.c_str(), perm.disable_message().c_str());
        }

        if (verbose) {
            fprintf(verbosefile, "mv %s%s %s\n", jaildir.parent.c_str(), jaildir.component.c_str(), newpath.c_str());
        }
        if (!dryrun && renameat(jaildir.parentfd, jaildir.component.c_str(), jaildir.parentfd, newpath.c_str()) != 0) {
            die("mv %s%s %s: %s\n", jaildir.parent.c_str(), jaildir.component.c_str(), newpath.c_str(), strerror(errno));
        }
        exit(0);
    }

    // kill the sandbox if asked
    if (action == do_rm) {
        assert(jaildir.perm.dir.ends_with("/"));
        if (!dryrun && !foreground) {
            pid_t p = fork();
            if (p > 0) {
                exit(0);
            } else if (p < 0) {
                perror_die("fork");
            }
        }
        // unmount EVERYTHING mounted in the jail!
        // INCLUDING MY HOME DIRECTORY
        populate_mount_table();
        for (auto it = mount_table.begin(); it != mount_table.end(); ++it) {
            if (it->first.starts_with(jaildir.perm.dir))
                handle_umount(it);
        }
        // remove the jail
        jaildir.remove();
        exit(0);
    }

    // check skeleton directory
    if (!jaildir.perm.skeletondir.empty()) {
        if (v_ensuredir(jaildir.perm.skeletondir, 0755) < 0) {
            perror_die(jaildir.perm.skeletondir);
        }
        linkdir = path_noendslash(jaildir.perm.skeletondir);
    }

    // create the home directory
    if (!jailuser.owner_home_.empty()) {
        assert(jaildir.perm.dir.ends_with('/') && jailuser.owner_home_.starts_with('/'));
        if (v_ensuredir(jaildir.perm.dir + "home", 0755) < 0) {
            perror_die(jaildir.perm.dir + "home");
        }
        std::string jailhome = jaildir.perm.dir + jailuser.owner_home_.substr(1);
        int r = v_ensuredir(jailhome, 0700);
        uid_t want_owner = action == do_add ? caller_owner : jailuser.owner_;
        gid_t want_group = action == do_add ? caller_group : jailuser.group_;
        if (r < 0
            || (r > 0 && x_lchown(jailhome.c_str(), want_owner, want_group))) {
            perror_die(jailhome);
        }
        // also create in skeleton, but ignore errors
        if (!linkdir.empty()) {
            assert(!linkdir.ends_with('/'));
            (void) v_ensuredir(linkdir + "/home", 0755);
            std::string linkhome = linkdir + jailuser.owner_home_;
            r = v_ensuredir(linkhome, 0700);
            if (r > 0) {
                x_lchown(linkhome.c_str(), jailuser.owner_, jailuser.group_);
            }
        }
    }

    // set ownership
    if (chown_home) {
        jaildir.chown_home();
    }
    for (const auto& f : chown_user_args) {
        if (f.empty()) {
            die("--chown-user directory must not be empty\n");
        }
        auto xf = path_pa_validate(path_absolute(f, jaildir.perm.dir));
        if (xf.empty()) {
            die("%s: Invalid --chown-user directory\n",
                f.c_str());
        } else if (!xf.starts_with(jaildir.perm.dir)) {
            // `jaildir.dir` ends in `/` while `xf` (from path_pa_validate) does
            // not, so this requires `xf` to be a strict subdirectory: the jail
            // root itself is intentionally excluded, since chowning it to the
            // ephemeral user would be an escape vector. Don't "fix" this by
            // normalizing the trailing slash.
            die("%s: --chown-user directory must be within %s\n",
                f.c_str(), jaildir.perm.dir.c_str());
        }
        jaildir.chown_recursive(xf, jailuser.owner_, jailuser.group_);
    }

    // construct the jail
    mount_status = optind + 2 < argc;
    dstroot = path_noendslash(buildjail.perm.dir);
    assert(dstroot != "/");
    if (!manifest.empty()) {
        mode_t old_umask = umask(0);
        if (construct_jail(buildjail.dev, manifest, false) != 0) {
            exit(1);
        }
        umask(old_umask);
    }

    // close `parentfd`
    close(jaildir.parentfd);
    jaildir.parentfd = -1;
    if (bindjail) {
        close(bindjail->parentfd);
        bindjail->parentfd = -1;
    }

    // maybe execute a command in the jail
    if (optind + 2 < argc) {
        jailuser.set_inputfd(inputfd);
        jailuser.set_timeout(timeout, idle_timeout);
        jailuser.set_foreground(foreground);
        jailuser.exec(argc - (optind + 2), argv + optind + 2, buildjail, jaildir);
    }

    // close timing and lock file if appropriate
    if (timingfd != -1) {
        close(timingfd);
    }

    exit(0);
}

int main(int argc, char** argv) {
    try {
        return jail_main(argc, argv);
    } catch (const pajailconf_error& e) {
        fprintf(stderr, "%s\n", e.message().c_str());
        exit(1);
    }
}
