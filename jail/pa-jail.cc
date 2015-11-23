// pa-jail.cc -- Peteramati program sets up a jail for student code
// Peteramati is Copyright (c) 2013-2015 Eddie Kohler and others
// Distributed under an MIT-like license; see LICENSE

#include <sys/types.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <sys/mount.h>
#include <sys/select.h>
#include <sys/time.h>
#include <unistd.h>
#include <stdio.h>
#include <stdlib.h>
#include <stdarg.h>
#include <string.h>
#include <errno.h>
#include <signal.h>
#include <dirent.h>
#include <termios.h>
#include <pwd.h>
#include <grp.h>
#include <fcntl.h>
#include <utime.h>
#include <assert.h>
#include <getopt.h>
#include <fnmatch.h>
#include <string>
#include <map>
#include <unordered_map>
#include <iostream>
#include <sys/ioctl.h>
#if __linux__
#include <mntent.h>
#include <sched.h>
#elif __APPLE__
#include <sys/param.h>
#include <sys/ucred.h>
#include <sys/mount.h>
#endif

#define ROOT 0

#define FLAG_CP 1        // copy even if source is symlink
#define FLAG_NOLINK 2    // never link from source

#ifndef O_PATH
#define O_PATH 0
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
static std::multimap<std::string, std::string> lnk_table;
static std::unordered_map<devino, std::string> devino_table;
static int exit_value = 0;
static bool verbose = false;
static bool dryrun = false;
static bool quiet = false;
static bool doforce = false;
static FILE* verbosefile = stdout;
static std::string linkdir;
static std::string dstroot;
static std::string pidfilename;
static int pidfd = -1;
static std::unordered_map<std::string, int> umount_table;
static volatile sig_atomic_t got_sigterm = 0;
static int sigpipe[2];

enum jailaction {
    do_start, do_init, do_run, do_rm, do_mv
};


// error helpers

static int perror_fail(const char* format, const char* arg1) {
    fprintf(stderr, format, arg1, strerror(errno));
    exit_value = 1;
    return 1;
}

static __attribute__((noreturn))
void die(const char* fmt, ...) {
    va_list val;
    va_start(val, fmt);
    vfprintf(stderr, fmt, val);
    va_end(val);
    exit(1);
}

static __attribute__((noreturn))
void perror_die(const char* message) {
    die("%s: %s\n", message, strerror(errno));
}

static inline __attribute__((noreturn))
void perror_die(const std::string& message) {
    perror_die(message.c_str());
}


// pathname helpers

static std::string path_endslash(const std::string& path) {
    if (path.empty() || path.back() != '/')
        return path + "/";
    else
        return path;
}

static std::string path_noendslash(std::string path) {
    while (path.length() > 1 && path.back() == '/')
        path = path.substr(0, path.length() - 1);
    return path;
}

static std::string path_parentdir(const std::string& path) {
    size_t npos = path.length();
    while (npos > 0 && path[npos - 1] == '/')
        --npos;
    while (npos > 0 && path[npos - 1] != '/')
        --npos;
    return path.substr(0, npos);
}

static std::string shell_quote(const std::string& argument) {
    std::string quoted;
    size_t last = 0;
    for (size_t pos = 0; pos != argument.length(); ++pos)
        if ((pos == 0 && argument[pos] == '~')
            || !(isalnum((unsigned char) argument[pos])
                 || argument[pos] == '_'
                 || argument[pos] == '-'
                 || argument[pos] == '~'
                 || argument[pos] == '.'
                 || argument[pos] == '/')) {
            if (quoted.empty())
                quoted = "'";
            if (argument[pos] == '\'') {
                quoted += argument.substr(last, pos - last) + "'\\''";
                last = pos + 1;
            }
        }
    if (quoted.empty())
        return argument;
    else {
        quoted += argument.substr(last) + "'";
        return quoted;
    }
}


static const char* uid_to_name(uid_t u) {
    static uid_t old_uid = -1;
    static char buf[128];
    if (u != old_uid) {
        old_uid = u;
        if (struct passwd *pw = getpwuid(u))
            snprintf(buf, sizeof(buf), "%s", pw->pw_name);
        else
            snprintf(buf, sizeof(buf), "%u", (unsigned) u);
    }
    return buf;
}

static const char* gid_to_name(gid_t g) {
    static gid_t old_gid = -1;
    static char buf[128];
    if (g != old_gid) {
        old_gid = g;
        if (struct group *gr = getgrgid(g))
            snprintf(buf, sizeof(buf), "%s", gr->gr_name);
        else
            snprintf(buf, sizeof(buf), "%u", (unsigned) g);
    }
    return buf;
}


static int v_mkdir(const char* pathname, mode_t mode) {
    if (verbose)
        fprintf(verbosefile, "mkdir -m 0%o %s\n", mode, pathname);
    return dryrun ? 0 : mkdir(pathname, mode);
}

static int v_mkdirat(int dirfd, const char* component, mode_t mode, const std::string& pathname) {
    if (verbose)
        fprintf(verbosefile, "mkdir -m 0%o %s\n", mode, pathname.c_str());
    return dryrun ? 0 : mkdirat(dirfd, component, mode);
}

static int v_fchmod(int fd, mode_t mode, const std::string& pathname) {
    if (verbose)
        fprintf(verbosefile, "chmod 0%o %s\n", mode, pathname.c_str());
    return dryrun ? 0 : fchmod(fd, mode);
}

static int v_ensuredir(std::string pathname, mode_t mode, bool nolink) {
    pathname = path_noendslash(pathname);
    auto it = dirtable.find(pathname);
    if (it != dirtable.end())
        return it->second;
    struct stat st;
    int r;
    if (nolink)
        r = lstat(pathname.c_str(), &st);
    else
        r = stat(pathname.c_str(), &st);
    if (r == 0 && !S_ISDIR(st.st_mode)) {
        errno = ENOTDIR;
        r = -1;
    }
    if (r == -1 && errno == ENOENT) {
        std::string parent_pathname = path_parentdir(pathname);
        if ((parent_pathname.length() == pathname.length()
             || v_ensuredir(parent_pathname, mode, false) >= 0)
            && v_mkdir(pathname.c_str(), mode) == 0)
            r = 1;
    }
    dirtable.insert(std::make_pair(pathname, r == 1 ? 0 : r));
    return r;
}

static int x_link(const char* oldpath, const char* newpath) {
    if (verbose)
        fprintf(verbosefile, "rm -f %s; ln %s %s\n", newpath, oldpath, newpath);
    if (!dryrun) {
        if (unlink(newpath) == -1 && errno != ENOENT)
            return perror_fail("rm %s: %s\n", newpath);
        if (link(oldpath, newpath) != 0)
            return perror_fail("ln %s: %s\n", (std::string(oldpath) + " " + std::string(newpath)).c_str());
    }
    return 0;
}

static int x_chmod(const char* path, mode_t mode) {
    if (verbose)
        fprintf(verbosefile, "chmod 0%o %s\n", mode, path);
    if (!dryrun && chmod(path, mode) != 0)
        return perror_fail("chmod %s: %s\n", path);
    return 0;
}

static int x_lchown(const char* path, uid_t owner, gid_t group) {
    if (verbose)
        fprintf(verbosefile, "chown -h %s:%s %s\n", uid_to_name(owner), gid_to_name(group), path);
    if (!dryrun && lchown(path, owner, group) != 0)
        return perror_fail("chown %s: %s\n", path);
    return 0;
}

static int x_lchownat(int fd, const char* component, uid_t owner, gid_t group, const std::string& dirpath) {
    if (verbose)
        fprintf(verbosefile, "chown -h %s:%s %s%s\n", uid_to_name(owner), gid_to_name(group), dirpath.c_str(), component);
    if (!dryrun && fchownat(fd, component, owner, group, AT_SYMLINK_NOFOLLOW) != 0)
        return perror_fail("chown %s: %s\n", (dirpath + component).c_str());
    return 0;
}

static int x_fchown(int fd, uid_t owner, gid_t group, const std::string& path) {
    if (verbose)
        fprintf(verbosefile, "chown -h %s:%s %s\n", uid_to_name(owner), gid_to_name(group), path.c_str());
    if (!dryrun && fchown(fd, owner, group) != 0)
        return perror_fail("chown %s: %s\n", path.c_str());
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
    if (S_ISCHR(m))
        snprintf(buf, sizeof(buf), "c %d %d", major(d), minor(d));
    else if (S_ISBLK(m))
        snprintf(buf, sizeof(buf), "b %d %d", major(d), minor(d));
    else if (S_ISFIFO(m))
        return "p";
    else
        snprintf(buf, sizeof(buf), "%u %u", (unsigned) m, (unsigned) d);
    return buf;
}

static int x_mknod(const char* path, mode_t mode, dev_t dev) {
    if (verbose)
        fprintf(verbosefile, "mknod -m 0%o %s %s\n", mode, path, dev_name(mode, dev));
    if (!dryrun && mknod(path, mode, dev) != 0
        && (errno != EEXIST || !x_mknod_eexist_ok(path, mode, dev)))
        return perror_fail("mknod %s: %s\n", path);
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
    if (verbose)
        fprintf(verbosefile, "ln -s %s %s\n", oldpath, newpath);
    if (!dryrun && symlink(oldpath, newpath) != 0
        && (errno != EEXIST || !x_symlink_eexist_ok(oldpath, newpath)))
        return perror_fail("symlink %s: %s\n", (std::string(oldpath) + " " + newpath).c_str());
    return 0;
}

static std::pair<pid_t, int> x_waitpid(pid_t child, int flags) {
    int status;
    while (1) {
        pid_t w = waitpid(child, &status, flags);
        if (w > 0 && WIFEXITED(status))
            return std::make_pair(w, WEXITSTATUS(status));
        else if (w > 0)
            return std::make_pair(w, 128 + WTERMSIG(status));
        else if (w == 0) {
            errno = EAGAIN;
            return std::make_pair((pid_t) -1, -1);
        } else if (w == -1 && errno != EINTR)
            return std::make_pair((pid_t) -1, -1);
    }
}


// jailmaking

#if __linux__
#define MFLAG(x) MS_ ## x
#elif __APPLE__
#define MFLAG(x) MNT_ ## x
#endif

struct mountarg {
    const char* name;
    int value;
};
static const mountarg mountargs[] = {
#if __linux__
    { "noatime", MS_NOATIME },
#endif
    { "nodev", MFLAG(NODEV) },
#if __linux__
    { "nodiratime", MS_NODIRATIME },
#endif
    { "noexec", MFLAG(NOEXEC) },
    { "nosuid", MFLAG(NOSUID) },
#if __linux__ && defined(MS_RELATIME)
    { "relatime", MS_RELATIME },
#endif
    { "ro", MFLAG(RDONLY) },
    { "rw", 0 },
#if __linux__ && defined(MS_STRICTATIME)
    { "strictatime", MS_STRICTATIME },
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
    bool allowed;
    mountslot() : opts(0), allowed(false) {}
    mountslot(const char* fsname, const char* type, const char* mountopts,
              const char* dir);
    std::string debug_mountopts() const;
    void add_mountopt(const char* mopt);
    const char* mount_data() const;
};

mountslot::mountslot(const char* fsname_, const char* type_,
                     const char* mopt, const char* dir)
    : fsname(fsname_), type(type_), opts(0), allowed(false) {
    while (mopt && *mopt) {
        const char* ok_first = mopt + strspn(mopt, ",");
        const char* ok_last = ok_first + strcspn(ok_first, ",=");
        const char* ov_last = ok_last + strcspn(ok_last, ",");
        if (const mountarg* ma = find_mountarg(ok_first, ok_last - ok_first))
            opts |= ma->value;
        else if (ok_first != ov_last)
            data += (data.empty() ? "" : ",") + std::string(ok_first, ov_last);
        mopt = ov_last;
    }

    allowed = ((strcmp(dir, "/proc") == 0 && type == "proc")
               || (strcmp(dir, "/sys") == 0 && type == "sysfs")
               || (strcmp(dir, "/dev") == 0 && type == "udev")
               || (strcmp(dir, "/dev/pts") == 0 && type == "devpts"));
}

std::string mountslot::debug_mountopts() const {
    std::string arg;
    if (!(opts & MFLAG(RDONLY)))
        arg = "rw";
    const mountarg* ma = mountargs;
    const mountarg* ma_last = ma + sizeof(mountargs) / sizeof(mountargs[0]);
    for (; ma != ma_last; ++ma)
        if (ma->value && (opts & ma->value))
            arg += (arg.empty() ? "" : ",") + std::string(ma->name);
    if (!data.empty())
        arg += (arg.empty() ? "" : ",") + data;
    return arg;
}

void mountslot::add_mountopt(const char* inopt) {
    int inopt_len = strcspn(inopt, ",=");
    if (const mountarg* ma = find_mountarg(inopt, inopt_len)) {
        if (ma->value)
            opts |= ma->value;
        else
            opts &= ~MFLAG(RDONLY);
    } else {
        const char* mopt = data.c_str();
        while (*mopt) {
            const char* ok_first = mopt + strspn(mopt, ",");
            const char* ok_last = ok_first + strcspn(ok_first, ",=");
            const char* ov_last = ok_last + strcspn(ok_last, ",");
            if (ok_last - ok_first == inopt_len
                && memcmp(inopt, ok_first, inopt_len) == 0) {
                int offset = ok_first - data.data();
                data = std::string(data.data(), mopt)
                    + std::string(ov_last, data.data() + data.length());
                mopt = data.c_str() + offset;
            } else
                mopt = ov_last;
        }
        data += (data.empty() ? "" : ",") + std::string(inopt);
    }
}

const char* mountslot::mount_data() const {
    return data.empty() ? NULL : data.c_str();
}


typedef std::map<std::string, mountslot> mount_table_type;
mount_table_type mount_table;

static int populate_mount_table() {
    static bool mount_table_populated = false;
    if (mount_table_populated)
        return 0;
    mount_table_populated = true;
#if __linux__
    FILE* f = setmntent("/proc/mounts", "r");
    if (!f)
        return perror_fail("open %s: %s\n", "/proc/mounts");
    while (struct mntent* me = getmntent(f)) {
        mountslot ms(me->mnt_fsname, me->mnt_type, me->mnt_opts, me->mnt_dir);
        mount_table[me->mnt_dir] = ms;
    }
    fclose(f);
    return 0;
#elif __APPLE__
    struct statfs* mntbuf;
    int nmntbuf = getmntinfo(&mntbuf, MNT_NOWAIT);
    for (struct statfs* me = mntbuf; me != mntbuf + nmntbuf; ++me) {
        mountslot ms(me->f_mntfromname, me->f_fstypename, "", me->m_mntonname);
        ms.opts = me->f_flags;
        mount_table[me->f_mntonname] = ms;
    }
    return 0;
#endif
}

#if __APPLE__
int mount(const char*, const char* target, const char* fstype,
          unsigned long flags, const void*) {
    return ::mount(fstype, target, flags, NULL);
}

int umount(const char* dir) {
    return ::unmount(dir, 0);
}
#endif

static int handle_mount(mountslot ms, std::string dst, bool chrooted) {
    auto it = mount_table.find(dst);
    if (it != mount_table.end()
        && it->second.fsname == ms.fsname
        && it->second.type == ms.type
        && it->second.opts == ms.opts
        && it->second.data == ms.data
        && !chrooted)
        // already mounted
        return 0;
    mountslot msx(ms);
#if __linux__
    if (msx.type == "devpts" && chrooted) {
        msx.add_mountopt("newinstance");
        msx.add_mountopt("ptmxmode=0666");
    }
#endif
    if (verbose) {
        std::string opts = msx.debug_mountopts();
        fprintf(verbosefile, "mount -i -n -t %s%s%s %s %s\n",
                msx.type.c_str(), opts.empty() ? "" : " -o ", opts.c_str(),
                msx.fsname.c_str(), dst.c_str());
    }
    if (!dryrun) {
        int r = mount(msx.fsname.c_str(), dst.c_str(), msx.type.c_str(),
                      msx.opts, msx.mount_data());
        if (r != 0 && errno == EBUSY && chrooted)
            r = mount(msx.fsname.c_str(), dst.c_str(), msx.type.c_str(),
                      msx.opts | MS_REMOUNT, msx.mount_data());
        if (r != 0)
            return perror_fail("mount %s: %s\n", dst.c_str());
    }
    return 0;
}

static int handle_umount(const mount_table_type::iterator& it) {
    if (verbose)
        fprintf(verbosefile, "umount -i -n %s\n", it->first.c_str());
    if (!dryrun && umount(it->first.c_str()) != 0) {
        fprintf(stderr, "umount %s: %s\n", it->first.c_str(), strerror(errno));
        exit(1);
    }
    if (dryrun)
        umount_table[it->first.c_str()] = 1;
    return 0;
}

static int handle_copy(const std::string& src, std::string subdst,
                       int flags, dev_t jaildev, mode_t* srcmode);

static void handle_symlink_dst(std::string src, std::string dst,
                               std::string lnk, dev_t jaildev)
{
    std::string dst_lnkin = dst;

    // expand `lnk` into `dst`
    if (lnk[0] == '/') {
        src = lnk;
        dst = dstroot + lnk;
    } else {
        while (1) {
            if (src.length() == 1) {
            give_up:
                return;
            }
            size_t srcslash = src.rfind('/', src.length() - 2),
                dstslash = dst.rfind('/', dst.length() - 2);
            if (srcslash == std::string::npos || dstslash == std::string::npos
                || dstslash < dstroot.length())
                goto give_up;
            src = src.substr(0, srcslash + 1);
            dst = dst.substr(0, dstslash + 1);
            if (lnk.length() > 3 && lnk[0] == '.' && lnk[1] == '.'
                && lnk[2] == '/')
                lnk = lnk.substr(3);
            else
                break;
        }
        src += lnk;
        dst += lnk;
    }

    if (dst.substr(dstroot.length(), 6) != "/proc/") {
        mode_t srcmode;
        int r = handle_copy(src, dst.substr(dstroot.length()),
                            0, jaildev, &srcmode);
        // remember directory-level symbolic links
        if (r == 0 && S_ISDIR(srcmode)) {
            lnk_table.insert(std::make_pair(dst, dst_lnkin));
            lnk_table.insert(std::make_pair(dst_lnkin, dst));
        }
    }
}

static int x_cp_p(const std::string& src, const std::string& dst) {
    if (verbose)
        fprintf(verbosefile, "rm -f %s; cp -p %s %s\n",
                dst.c_str(), src.c_str(), dst.c_str());
    if (dryrun)
        return 0;

    int r = unlink(dst.c_str());
    if (r == -1 && errno != ENOENT)
        return perror_fail("rm %s: %s\n", dst.c_str());

    pid_t child = fork();
    if (child == 0) {
        const char* args[6] = {
            "/bin/cp", "-p", src.c_str(), dst.c_str(), NULL
        };
        execv("/bin/cp", (char**) args);
        exit(1);
    } else if (child < 0)
        return perror_fail("%s: %s\n", "fork");

    int status = x_waitpid(child, 0).second;
    if (status == 0)
        return 0;
    else if (status != -1)
        return perror_fail("/bin/cp %s: Bad exit status\n", dst.c_str());
    else
        return perror_fail("/bin/cp %s: Did not exit\n", dst.c_str());
}

static bool same_contents(const std::string&, const struct stat& st1,
                          const std::string& fn2) {
    struct stat st2;
    int r = lstat(fn2.c_str(), &st2);
    return r == 0
        && st1.st_mode == st2.st_mode
        && st1.st_uid == st2.st_uid
        && st1.st_gid == st2.st_gid
        && st1.st_size == st2.st_size
        && st1.st_mtime == st2.st_mtime;
}

static int handle_copy(const std::string& src, std::string subdst,
                       int flags, dev_t jaildev, mode_t* srcmode) {
    assert(subdst[0] == '/');
    assert(subdst.length() == 1 || subdst[1] != '/');
    assert(dstroot.back() != '/');
    if (subdst.substr(0, dstroot.length()) == dstroot)
        fprintf(stderr, "XXX %s %s\n", subdst.c_str(), dstroot.c_str());
    assert(subdst.substr(0, dstroot.length()) != dstroot);

    std::string dst = dstroot + subdst;
    if (dst_table.find(dst) != dst_table.end())
        return 1;
    dst_table[dst] = 1;

    struct stat ss, ds;

    if (dst.back() != '/') {
        std::string parent_dst = path_parentdir(dst);
        if (dirtable.find(parent_dst) == dirtable.end()) {
            int r = lstat(parent_dst.c_str(), &ss);
            if (r == -1 && errno == ENOENT
                && handle_copy(path_parentdir(src),
                               parent_dst.substr(dstroot.length()),
                               0, jaildev, NULL) == 0)
                r = 0;
            if (r != 0)
                return r;
        }
    }

    if (lstat(src.c_str(), &ss) != 0)
        return perror_fail("lstat %s: %s\n", src.c_str());
    if (srcmode)
        *srcmode = ss.st_mode;
    ds.st_uid = ds.st_gid = ROOT;

    // check for hard link to already-created file
    if (S_ISREG(ss.st_mode) && !(flags & FLAG_CP)) {
        auto di = std::make_pair(ss.st_dev, ss.st_ino);
        auto it = devino_table.find(di);
        if (it != devino_table.end()) {
            if (x_link(it->second.c_str(), dst.c_str()) != 0)
                return 1;
            return 0;
        } else
            devino_table.insert(std::make_pair(di, dst));
    }

    if (S_ISREG(ss.st_mode) && !linkdir.empty() && !(flags & FLAG_CP)) {
        // regular file: link from skeleton directory
        std::string lnk = linkdir + src;
        if (!same_contents(src, ss, lnk)) {
            if (v_ensuredir(path_parentdir(lnk), 0700, true) < 0)
                return perror_fail("mkdir -p %s: %s\n", path_parentdir(lnk).c_str());
            if (x_cp_p(src, lnk) != 0)
                return 1;
        }
        if (x_link(lnk.c_str(), dst.c_str()) != 0)
            return 1;
        // XXX get true linkdir stats?
        ds = ss;
    } else if (S_ISREG(ss.st_mode)
               || (S_ISLNK(ss.st_mode) && (flags & FLAG_CP))) {
        // regular file (or [cp]-marked symlink): copy to destination
        if (x_cp_p(src, dst) != 0)
            return 1;
        ds = ss;
    } else if (S_ISDIR(ss.st_mode)) {
        // directory
        mode_t perm = ss.st_mode & (S_ISUID | S_ISGID | S_IRWXU | S_IRWXG | S_IRWXO);
        if (v_ensuredir(dst, perm, true) >= 0)
            ds.st_mode = perm | S_IFDIR;
        else
            return perror_fail("lstat %s: %s\n", dst.c_str());
    } else if (S_ISCHR(ss.st_mode) || S_ISBLK(ss.st_mode)) {
        // device file
        ss.st_mode &= (S_IFREG | S_IFCHR | S_IFBLK | S_IFIFO | S_IFSOCK | S_ISUID | S_ISGID | S_IRWXU | S_IRWXG | S_IRWXO);
        if (!dryrun && x_mknod(dst.c_str(), ss.st_mode, ss.st_rdev) != 0)
            return 1;
        ds.st_mode = ss.st_mode;
    } else if (S_ISLNK(ss.st_mode)) {
        // symbolic link
        char lnkbuf[4096];
        ssize_t r = readlink(src.c_str(), lnkbuf, sizeof(lnkbuf));
        if (r == -1)
            return perror_fail("readlink %s: %s\n", src.c_str());
        else if (r == sizeof(lnkbuf))
            return perror_fail("%s: Symbolic link too long\n", src.c_str());
        lnkbuf[r] = 0;
        if (x_symlink(lnkbuf, dst.c_str()) != 0)
            return 1;
        ds.st_mode = ss.st_mode;
        handle_symlink_dst(src, dst, std::string(lnkbuf), jaildev);
    } else
        // cannot deal
        return perror_fail("%s: Odd file type\n", src.c_str());

    // XXX preserve sticky bits/setuid/setgid?
    if (ds.st_mode != ss.st_mode
        && x_chmod(dst.c_str(), ss.st_mode))
        return 1;
    if ((ds.st_uid != ss.st_uid || ds.st_gid != ss.st_gid)
        && x_lchown(dst.c_str(), ss.st_uid, ss.st_gid))
        return 1;

    if (S_ISDIR(ss.st_mode)) {
        auto it = mount_table.find(src.c_str());
        if (it != mount_table.end() && it->second.allowed)
            return handle_mount(it->second, dst, false);
    }

    return 0;
}

static int construct_jail(dev_t jaildev, FILE* f) {
    // prepare root
    if (x_chmod(dstroot.c_str(), 0755)
        || x_lchown(dstroot.c_str(), 0, 0))
        return 1;
    dst_table[dstroot + "/"] = 1;

    // Mounts
    populate_mount_table();

    // Read a line at a time
    std::string cursrcdir("/"), curdstsubdir("/");
    int base_flags = linkdir.empty() ? FLAG_NOLINK : 0;

    char xbuf[BUFSIZ];
    while (fgets(xbuf, BUFSIZ, f)) {
        char* buf = xbuf;
        while (isspace((unsigned char) *buf))
            ++buf;
        int l = strlen(buf);
        while (l > 0 && isspace((unsigned char) buf[l - 1]))
            buf[--l] = 0;
        if (l == 0 || buf[0] == '#')
            continue;

        // 'directory:'
        if (buf[l - 1] == ':') {
            if (l == 2 && buf[0] == '.')
                cursrcdir = std::string("/");
            else if (l > 2 && buf[0] == '.' && buf[1] == '/')
                cursrcdir = std::string(buf + 1, buf + l - 1);
            else
                cursrcdir = std::string(buf, buf + l - 1);
            if (cursrcdir[0] != '/')
                cursrcdir = std::string("/") + cursrcdir;
            while (cursrcdir.length() > 1 && cursrcdir[cursrcdir.length() - 1] == '/' && cursrcdir[cursrcdir.length() - 2] == '/')
                cursrcdir = cursrcdir.substr(0, cursrcdir.length() - 1);
            if (cursrcdir[cursrcdir.length() - 1] != '/')
                cursrcdir += '/';
            curdstsubdir = cursrcdir;
            assert(curdstsubdir.back() == '/');
            continue;
        }

        // '[FLAGS]'
        int flags = base_flags;
        if (buf[l - 1] == ']') {
            // skip ' [FLAGS]'
            for (--l; l > 0 && buf[l-1] != '['; --l)
                /* do nothing */;
            if (l == 0)
                continue;
            char* opts = &buf[l];
            do {
                buf[--l] = 0;
            } while (l > 0 && isspace((unsigned char) buf[l-1]));
            // parse flags
            while (1) {
                while (isspace((unsigned char) *opts) || *opts == ',')
                    ++opts;
                if (!*opts || *opts == ']')
                    break;
                char* optstart = opts;
                ++opts;
                while (*opts && *opts != ']' && *opts != ','
                       && !isspace((unsigned char) *opts))
                    ++opts;
                if (opts - optstart == 2 && memcmp(optstart, "cp", 2) == 0)
                    flags |= FLAG_CP;
            }
        }

        std::string src, dst;
        char* arrow = strstr(buf, " <- ");
        if (buf[0] == '/' && arrow)
            src = std::string(arrow + 4);
        else if (buf[0] == '/')
            src = std::string(buf);
        else if (arrow)
            src = std::string(arrow + 4, buf + l);
        else
            src = cursrcdir + std::string(buf, buf + l);
        if (!arrow)
            arrow = buf + l;
        dst = curdstsubdir + std::string(buf + (buf[0] == '/'), arrow);
        handle_copy(src, dst, flags, jaildev, NULL);
    }

    return exit_value;
}


// main program

static std::string check_filename(std::string name) {
    const char *allowed_chars = "/0123456789-._ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz~";
    char buf[1024];

    if (strspn(name.c_str(), allowed_chars) != name.length()
        || name.empty()
        || name[0] == '~'
        || name.length() >= sizeof(buf))
        return std::string();

    char* out = buf;
    for (const char* s = name.c_str(); *s; ++s) {
        *out++ = *s;
        if (*s == '.' && (s[1] == '/' || s[1] == '\0')
            && s != name.c_str() && s[-1] == '/') {
            --out;
            ++s;
        } else if (*s == '.' && s[1] == '.' && (s[2] == '/' || s[2] == '\0')
                   && (s == name.c_str() || s[-1] == '/'))
            return std::string();
        while (*s == '/' && s[1] == '/')
            ++s;
    }
    while (out > buf + 1 && out[-1] == '/')
        --out;
    *out = '\0';
    return std::string(buf, out - buf);
}

static std::string absolute(const std::string& dir) {
    if (!dir.empty() && dir[0] == '/')
        return dir;
    char buf[BUFSIZ];
    if (getcwd(buf, BUFSIZ - 1))
        perror_die("getcwd");
    char* endbuf = buf + strlen(buf);
    while (endbuf - buf > 1 && endbuf[-1] == '/')
        --endbuf;
    memcpy(endbuf, "/", 2);
    return std::string(buf) + dir;
}

static std::string take_word(const std::string& str, size_t& pos) {
    while (pos < str.length() && str[pos] != '\n' && isspace((unsigned char) str[pos]))
        ++pos;
    if (pos < str.length() && !isspace((unsigned char) str[pos])) {
        size_t spos = pos;
        while (pos < str.length() && !isspace((unsigned char) str[pos]))
            ++pos;
        return str.substr(spos, pos - spos);
    } else
        return std::string();
}

struct jaildirinfo {
    std::string dir;
    std::string parent;
    int parentfd;
    std::string component;
    bool allowed;
    std::string permdir;
    dev_t dev;

    std::string skeletondir;
    bool skeleton_allowed;

    jaildirinfo(const char* str, const std::string& skeletondir,
                jailaction action);
    void check();
    void chown_home();
    void remove();

private:
    void parse_permfile(int conff, std::string conffname);
    void chown_recursive(int dirfd, std::string& dirbuf, int depth, uid_t owner, gid_t group);
    void remove_recursive(int dirfd, std::string component, std::string name);
};

jaildirinfo::jaildirinfo(const char* str, const std::string& skeletonstr,
                         jailaction action)
    : dir(check_filename(absolute(str))),
      parentfd(-1), allowed(false), dev(-1),
      skeletondir(skeletonstr), skeleton_allowed(false) {
    if (dir.empty() || dir == "/" || dir[0] != '/') {
        fprintf(stderr, "%s: Bad characters in filename\n", str);
        exit(1);
    }
    dir = path_endslash(dir);
    if (!skeletondir.empty())
        skeletondir = path_endslash(absolute(skeletondir));

    int fd = open("/etc/pa-jail.conf", O_RDONLY | O_NOFOLLOW);
    if (fd != -1) {
        parse_permfile(fd, "/etc/pa-jail.conf");
        close(fd);
    }
    if (!allowed) {
        fprintf(stderr, "Jails are disabled; perhaps you need to edit `/etc/pa-jail.conf`\n");
        exit(1);
    }

    size_t last_pos = 0;
    fd = -1;
    bool dryrunning = false;
    while (last_pos != dir.length()) {
        // extract component
        size_t next_pos = last_pos;
        while (next_pos && next_pos < dir.length() && dir[next_pos] != '/')
            ++next_pos;
        if (!next_pos)
            ++next_pos;
        parent = dir.substr(0, last_pos);
        component = dir.substr(last_pos, next_pos - last_pos);
        std::string thisdir = dir.substr(0, next_pos);
        last_pos = next_pos;
        while (last_pos != dir.length() && dir[last_pos] == '/')
            ++last_pos;

        // check whether we are below the permission directory
        bool allowed_here = !permdir.empty()
            && last_pos >= permdir.length()
            && dir.substr(0, permdir.length()) == permdir;

        // open it and swap it in
        if (parentfd >= 0)
            close(parentfd);
        parentfd = fd;
        fd = openat(parentfd, component.c_str(), O_PATH | O_CLOEXEC | O_NOFOLLOW);
        if (fd == -1 && !allowed_here && errno == ENOENT)
            break;
        if ((fd == -1 && dryrunning)
            || (fd == -1 && allowed_here && errno == ENOENT
                && (action == do_init || action == do_run))) {
            if (v_mkdirat(parentfd, component.c_str(), 0755, thisdir) != 0) {
                fprintf(stderr, "mkdir %s: %s\n", thisdir.c_str(), strerror(errno));
                exit(1);
            }
            dirtable.insert(std::make_pair(thisdir, 0));
            fd = openat(parentfd, component.c_str(), O_CLOEXEC | O_NOFOLLOW);
            // turn off suid+sgid on created root directory
            if (last_pos == dir.length() && (fd >= 0 || dryrun)
                && v_fchmod(fd, 0755, thisdir) != 0) {
                fprintf(stderr, "chmod %s: %s\n", thisdir.c_str(), strerror(errno));
                exit(1);
            }
            if (dryrun) {
                dryrunning = true;
                continue;
            }
        }
        if (fd == -1 && errno == ENOENT && action == do_rm && doforce)
            exit(0);
        else if (fd == -1) {
            fprintf(stderr, "%s: %s\n", thisdir.c_str(), strerror(errno));
            exit(1);
        }

        // stat it
        struct stat s;
        if (fstat(fd, &s) != 0)
            perror_die(thisdir);
        if (!S_ISDIR(s.st_mode)) {
            errno = ENOTDIR;
            perror_die(thisdir);
        } else if (!allowed_here && last_pos != dir.length()) {
            if (s.st_uid != ROOT)
                die("%s: Not owned by root\n", thisdir.c_str());
            else if ((s.st_gid != ROOT && (s.st_mode & S_IWGRP))
                     || (s.st_mode & S_IWOTH))
                die("%s: Writable by non-root\n", thisdir.c_str());
        }
        dev = s.st_dev;
    }
    if (!skeleton_allowed && !skeletondir.empty())
        die("%s: No `pa-jail.conf` enables skeleton directories here.\n", skeletondir.c_str());
    if (fd >= 0)
        close(fd);
}

static bool writable_only_by_root(const struct stat& st) {
    return st.st_uid == ROOT
        && (st.st_gid == ROOT || !(st.st_mode & S_IWGRP))
        && !(st.st_mode & S_IWOTH);
}

static std::string dirmatch_prefix(const std::string& pattern,
                                   const std::string& dir) {
    // return the prefix of `dir` that has the same number of slashes as
    // `pattern`
    size_t slcount = 0, slpos = 0;
    while ((slpos = pattern.find('/', slpos)) != std::string::npos)
        ++slcount, ++slpos;
    slpos = 0;
    while (slcount > 0 && (slpos = dir.find('/', slpos)) != std::string::npos)
        --slcount, ++slpos;
    return dir.substr(0, slpos);
}

static bool check_dirmatch_prefix(const std::string& pattern,
                                  const std::string& str) {
    return fnmatch(pattern.c_str(), dirmatch_prefix(pattern, str).c_str(),
                   FNM_PATHNAME | FNM_PERIOD) == 0;
}

void jaildirinfo::parse_permfile(int conff, std::string filename) {
    struct stat st;
    if (fstat(conff, &st) != 0)
        die("%s: %s\n", filename.c_str(), strerror(errno));
    else if (!writable_only_by_root(st)) {
        if (!allowed || verbose)
            fprintf(stderr, allowed ? "%s: Writable by non-root, ignoring\n" : "%s: Writable by non-root\n", filename.c_str());
        if (!allowed)
            exit(1);
        return;
    }

    char buf[8192];
    ssize_t nr = read(conff, buf, sizeof(buf));
    std::string str(buf, nr < 0 ? 0 : nr);
    size_t pos = 0;
    int allowed_globally = -1, allowed_locally = -1;
    int skeleton_allowed_globally = -1;
    std::string allowed_permdir;

    while (pos < str.length()) {
        std::string word1 = take_word(str, pos);
        std::string word2 = take_word(str, pos);
        while (take_word(str, pos).length())
            /* do nothing */;
        while (pos < str.length() && str[pos] == '\n')
            ++pos;
        if (!word2.empty() && word2[0] != '/')
            continue;

        std::string wdir = path_endslash(word2);
        if (word1 == "disablejail" || word1 == "nojail") {
            if (word2.empty())
                allowed_globally = allowed_locally = 0;
            else if (check_dirmatch_prefix(wdir, dir)) {
                allowed_locally = 0;
                allowed_permdir = word2;
            }
        } else if (word1 == "enablejail" || word1 == "allowjail") {
            if (word2.empty())
                allowed_globally = 1;
            else if (check_dirmatch_prefix(wdir, dir)) {
                allowed_locally = 1;
                allowed_permdir = dirmatch_prefix(wdir, dir);
            }
        } else if ((word1 == "enableskeleton" || word1 == "disableskeleton")
                   && !skeletondir.empty()) {
            bool allowed = word1 == "enableskeleton";
            if (word2.empty())
                skeleton_allowed_globally = allowed;
            else if (check_dirmatch_prefix(wdir, skeletondir))
                skeleton_allowed = word1 == "enableskeleton";
        }
    }

    if (allowed_locally > 0) {
        allowed = true;
        permdir = allowed_permdir;
    } else if (allowed_locally == 0)
        die("%s: Jails are disabled under here\n", allowed_permdir.c_str());
    else if (allowed_globally == 0)
        die("Jails are disabled\n");
    if (skeleton_allowed_globally == 0)
        skeleton_allowed = false;
}

void jaildirinfo::check() {
    assert(!permdir.empty() && permdir[permdir.length() - 1] == '/');
    assert(dir.substr(0, permdir.length()) == permdir);
}

void jaildirinfo::chown_home() {
    populate_mount_table();
    std::string buf = dir + "home/";
    int dirfd = openat(parentfd, (component + "/home").c_str(),
                       O_CLOEXEC | O_NOFOLLOW);
    if (dirfd == -1)
        perror_die(buf);
    chown_recursive(dirfd, buf, 1, ROOT, ROOT);
}

void jaildirinfo::chown_recursive(int dirfd, std::string& dirbuf, int depth, uid_t owner, gid_t group) {
    dirbuf = path_endslash(dirbuf);
    size_t dirbuflen = dirbuf.length();

    typedef std::pair<uid_t, gid_t> ug_t;
    std::map<std::string, ug_t>* home_map = NULL;
    if (depth == 1 && dirbuf.length() >= 6
        && memcmp(dirbuf.data() + dirbuf.length() - 6, "/home/", 6) == 0) {
        home_map = new std::map<std::string, ug_t>;
        setpwent();
        while (struct passwd* pw = getpwent()) {
            std::string name;
            if (pw->pw_dir && strncmp(pw->pw_dir, "/home/", 6) == 0
                && strchr(pw->pw_dir + 6, '/') == NULL)
                name = pw->pw_dir + 6;
            else
                name = pw->pw_name;
            (*home_map)[name] = ug_t(pw->pw_uid, pw->pw_gid);
        }
    }

    DIR* dir = fdopendir(dirfd);
    if (!dir) {
        if (errno == ENOENT && depth == 0 && dryrun)
            return;
        perror_die(dirbuf);
    }

    struct dirent* de;
    uid_t u;
    gid_t g;

    while ((de = readdir(dir))) {
        if (strcmp(de->d_name, ".") == 0 || strcmp(de->d_name, "..") == 0)
            continue;

        // don't follow symbolic links
        if (de->d_type == DT_LNK) {
            if (x_lchownat(dirfd, de->d_name, owner, group, dirbuf))
                exit(exit_value);
            continue;
        }

        // look up uid/gid
        u = owner, g = group;
        if (home_map) {
            auto it = home_map->find(de->d_name);
            if (it != home_map->end())
                u = it->second.first, g = it->second.second;
        }

        // recurse
        if (de->d_type == DT_DIR) {
            dirbuf += de->d_name;
            auto it = mount_table.find(dirbuf);
            if (it == mount_table.end()) { // not a mount point
                int subdirfd = openat(dirfd, de->d_name, O_CLOEXEC | O_NOFOLLOW);
                if (subdirfd == -1)
                    perror_die(dirbuf);
                if (x_fchown(subdirfd, u, g, dirbuf))
                    exit(exit_value);
                chown_recursive(subdirfd, dirbuf, depth + 1, u, g);
            }
            dirbuf.resize(dirbuflen);
        } else if (x_lchownat(dirfd, de->d_name, u, g, dirbuf))
            exit(exit_value);
    }

    closedir(dir);
    delete home_map;
}

void jaildirinfo::remove() {
    remove_recursive(parentfd, component, path_endslash(dir));
    if (verbose)
        fprintf(verbosefile, "rmdir %s\n", dir.c_str());
    if (!dryrun
        && unlinkat(parentfd, component.c_str(), AT_REMOVEDIR) != 0
        && !(errno == ENOENT && doforce))
        perror_die("rmdir " + dir);
}

void jaildirinfo::remove_recursive(int parentdirfd, std::string component,
                                   std::string dirname) {
    int dirfd = openat(parentdirfd, component.c_str(), O_RDONLY);
    struct stat dirst;
    if (dirfd == -1 || fstat(dirfd, &dirst) != 0)
        perror_die(dirname);
    if (dirst.st_dev != dev) { // --one-file-system
        close(dirfd);
        return;
    }
    DIR* dir = fdopendir(dirfd);
    if (!dir)
        perror_die(dirname);
    while (struct dirent* de = readdir(dir)) {
        if (de->d_type == DT_DIR) {
            if (strcmp(de->d_name, ".") == 0 || strcmp(de->d_name, "..") == 0)
                continue;
            std::string next_component = de->d_name;
            std::string next_dirname = dirname + next_component;
            if (umount_table.find(next_dirname) != umount_table.end())
                continue;
            remove_recursive(dirfd, next_component, next_dirname);
        }
        const char* op = de->d_type == DT_DIR ? "rmdir " : "rm ";
        if (verbose)
            fprintf(verbosefile, "%s%s%s\n", op, dirname.c_str(), de->d_name);
        if (!dryrun && unlinkat(dirfd, de->d_name, de->d_type == DT_DIR ? AT_REMOVEDIR : 0) != 0)
            perror_die(std::string(op) + dirname + de->d_name);
    }
    closedir(dir);
    close(dirfd);
}



class jailownerinfo {
  public:
    uid_t owner;
    gid_t group;
    std::string owner_home;
    std::string owner_sh;

    jailownerinfo();
    ~jailownerinfo();
    void init(const char* owner_name);
    void exec(int argc, char** argv, jaildirinfo& jaildir,
              int inputfd, double timeout, bool foreground);
    int exec_go();

  private:
    const char* newenv[4];
    char** argv;
    jaildirinfo* jaildir;
    int inputfd;
    struct timeval timeout;
    fd_set readset;
    fd_set writeset;
    struct buffer {
        char buf[8192];
        size_t head;
        size_t tail;
        bool input_closed;
        bool input_isfifo;
        bool output_closed;
        int rerrno;
        buffer()
            : head(0), tail(0), input_closed(false), input_isfifo(false),
              output_closed(false), rerrno(0) {
        }
        void transfer_in(int from);
        void transfer_out(int to);
    };
    buffer to_slave;
    buffer from_slave;
    bool has_stdin_termios;
    struct termios stdin_termios;
    int child_status;

    void start_sigpipe();
    void block(int ptymaster);
    int check_child_timeout(pid_t child, bool waitpid);
    void wait_background(pid_t child, int ptymaster);
    void exec_done(pid_t child, int exit_status) __attribute__((noreturn));

    static const char* const runmounts[];
};

jailownerinfo::jailownerinfo()
    : owner(ROOT), group(ROOT), argv(), has_stdin_termios(false),
      child_status(-1) {
}

jailownerinfo::~jailownerinfo() {
    delete[] argv;
}

static bool check_shell(const char* shell) {
    bool found = false;
    char* sh;
    while (!found && (sh = getusershell()))
        found = strcmp(sh, shell) == 0;
    endusershell();
    return found;
}

void jailownerinfo::init(const char* owner_name) {
    if (strlen(owner_name) >= 1024)
        die("%s: Username too long\n", owner_name);

    struct passwd* pwnam = getpwnam(owner_name);
    if (!pwnam)
        die("%s: No such user\n", owner_name);

    owner = pwnam->pw_uid;
    group = pwnam->pw_gid;
    if (strcmp(pwnam->pw_dir, "/") == 0)
        owner_home = "/home/nobody";
    else if (strncmp(pwnam->pw_dir, "/home/", 6) == 0)
        owner_home = pwnam->pw_dir;
    else
        die("%s: Home directory %s not under /home\n", owner_name, pwnam->pw_dir);

    if (strcmp(pwnam->pw_shell, "/bin/bash") == 0
        || strcmp(pwnam->pw_shell, "/bin/sh") == 0
        || check_shell(pwnam->pw_shell))
        owner_sh = pwnam->pw_shell;
    else
        die("%s: Shell %s not allowed by /etc/shells\n", owner_name, pwnam->pw_shell);

    if (owner == ROOT)
        die("%s: Jail user cannot be root\n", owner_name);
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
    if (pidfd >= 0) {
        lseek(pidfd, 0, SEEK_SET);
        char buf[1024];
        int l = sprintf(buf, "%d\n", p);
        ssize_t w = write(pidfd, buf, l);
        if (w != l || ftruncate(pidfd, l) != 0)
            perror_die(pidfilename);
    }
}

void jailownerinfo::exec(int argc, char** argv, jaildirinfo& jaildir,
                         int inputfd, double timeout, bool foreground) {
    // adjust environment; make sure we have a PATH
    char homebuf[8192];
    sprintf(homebuf, "HOME=%s", owner_home.c_str());
    const char* path = "PATH=/usr/local/bin:/bin:/usr/bin";
    const char* ld_library_path = NULL;
    {
        extern char** environ;
        for (char** eptr = environ; *eptr; ++eptr)
            if (strncmp(*eptr, "PATH=", 5) == 0)
                path = *eptr;
            else if (strncmp(*eptr, "LD_LIBRARY_PATH=", 16) == 0)
                ld_library_path = *eptr;
    }
    int newenvpos = 0;
    newenv[newenvpos++] = path;
    if (ld_library_path)
        newenv[newenvpos++] = ld_library_path;
    newenv[newenvpos++] = homebuf;
    newenv[newenvpos++] = NULL;

    // create command
    delete[] this->argv;
    this->argv = new char*[5 + argc - (optind + 2)];
    if (!this->argv)
        die("Out of memory\n");
    int newargvpos = 0;
    std::string command;
    this->argv[newargvpos++] = (char*) owner_sh.c_str();
    this->argv[newargvpos++] = (char*) "-l";
    this->argv[newargvpos++] = (char*) "-c";
    if (optind + 3 == argc)
        command = argv[optind + 2];
    else {
        command = shell_quote(argv[optind + 2]);
        for (int i = optind + 3; i < argc; ++i)
            command += std::string(" ") + shell_quote(argv[i]);
    }
    this->argv[newargvpos++] = const_cast<char*>(command.c_str());
    this->argv[newargvpos++] = NULL;

    // store other arguments
    this->jaildir = &jaildir;
    this->inputfd = inputfd;
    if (timeout > 0) {
        struct timeval now, delta;
        gettimeofday(&now, 0);
        delta.tv_sec = (long) timeout;
        delta.tv_usec = (long) ((timeout - delta.tv_sec) * 1000000);
        timeradd(&now, &delta, &this->timeout);
    } else
        timerclear(&this->timeout);

    // enter the jail
#if __linux__
    char* new_stack = (char*) malloc(256 * 1024);
    if (!new_stack)
        die("Out of memory\n");
    int child = clone(exec_clone_function, new_stack + 256 * 1024,
                      CLONE_NEWIPC | CLONE_NEWNS | CLONE_NEWPID, this);
    if (child == -1)
        perror_die("clone");
    int child_waitflags = __WALL;
#else
    int child = fork();
    if (child == 0)
        exit(exec_go());
    int child_waitflags = 0;
#endif
    if (child == -1)
        perror_die("fork");
    write_pid(child);

    // we don't need file descriptors any more
    close(STDIN_FILENO);
    close(STDOUT_FILENO);
    close(STDERR_FILENO);

    int exit_status = 0;
    if (foreground) {
        int r = setresgid(caller_group, caller_group, caller_group);
        (void) r;
        r = setresuid(caller_owner, caller_owner, caller_owner);
        (void) r;
        exit_status = x_waitpid(child, child_waitflags).second;
    } else
        pidfd = -1;
    exit(exit_status);
}

const char* const jailownerinfo::runmounts[] = {
#if __linux__
    "/proc", "/dev/pts", "/tmp",
#endif
    NULL
};

int jailownerinfo::exec_go() {
#if __linux__
    populate_mount_table();     // ensure we know how to mount /proc
#endif

    // chroot, remount /proc
    if (verbose)
        fprintf(verbosefile, "cd %s\n", jaildir->dir.c_str());
    if (!dryrun && chdir(jaildir->dir.c_str()) != 0)
        perror_die(jaildir->dir);
    if (verbose)
        fprintf(verbosefile, "chroot .\n");
    if (!dryrun && chroot(".") != 0)
        perror_die("chroot");
#if __linux__
    for (const char* const* m = runmounts; *m; ++m) {
        auto it = mount_table.find(*m);
        if (it != mount_table.end() && it->second.allowed) {
            v_ensuredir(*m, 0555, true);
            handle_mount(it->second, *m, true);
        }
    }
    (void) unlink("/dev/ptmx");
    x_symlink("pts/ptmx", "/dev/ptmx");
#endif

    // create a pty
    int ptymaster = -1;
    char* ptyslavename = NULL;
    if (verbose)
        fprintf(verbosefile, "sudo -u %s make-pty\n", uid_to_name(owner));
    if (!dryrun) {
        // change effective uid/gid, but save root for later
        if (setresgid(group, group, ROOT) != 0)
            perror_die("setresgid");
        if (setresuid(owner, owner, ROOT) != 0)
            perror_die("setresuid");
        // create pty
        if ((ptymaster = posix_openpt(O_RDWR)) == -1)
            perror_die("posix_openpt");
        if (grantpt(ptymaster) == -1)
            perror_die("grantpt");
        if (unlockpt(ptymaster) == -1)
            perror_die("unlockpt");
        if ((ptyslavename = ptsname(ptymaster)) == NULL)
            perror_die("ptsname");
    }

    // change into their home directory
    if (verbose)
        fprintf(verbosefile, "cd %s\n", owner_home.c_str());
    if (!dryrun && chdir(owner_home.c_str()) != 0)
        perror_die(owner_home);

    // check that shell exists
    if (!dryrun) {
        int f = open(owner_sh.c_str(), O_RDONLY);
        if (f < 0)
            perror_die("open" + owner_sh);
        close(f);
    }

    if (verbose) {
        for (int i = 0; newenv[i]; ++i)
            fprintf(verbosefile, "%s ", newenv[i]);
        for (int i = 0; this->argv[i]; ++i)
            fprintf(verbosefile, i ? " %s" : "%s", shell_quote(this->argv[i]).c_str());
        fprintf(verbosefile, "\n");
    }

    if (!dryrun) {
        start_sigpipe();
        pid_t child = fork();
        if (child < 0)
            perror_die("fork");
        else if (child == 0) {
            close(sigpipe[0]);
            close(sigpipe[1]);

            // reduce privileges permanently
            if (verbose)
                fprintf(verbosefile, "su %s\n", uid_to_name(owner));
            if (setresgid(group, group, group) != 0)
                perror_die("setresgid");
            if (setresuid(owner, owner, owner) != 0)
                perror_die("setresuid");

            if (setsid() == -1)
                perror_die("setsid");

            int ptyslave = open(ptyslavename, O_RDWR);
            if (ptyslave == -1)
                perror_die(ptyslavename);
#ifdef TIOCGWINSZ
            struct winsize ws;
            ioctl(ptyslave, TIOCGWINSZ, &ws);
            ws.ws_row = 24;
            ws.ws_col = 80;
            ioctl(ptyslave, TIOCSWINSZ, &ws);
#endif
            struct termios tty;
            if (tcgetattr(ptyslave, &tty) >= 0) {
                tty.c_oflag = 0; // no NL->NLCR xlation, no other proc.
                tcsetattr(ptyslave, TCSANOW, &tty);
            }
            dup2(ptyslave, STDIN_FILENO);
            dup2(ptyslave, STDOUT_FILENO);
            dup2(ptyslave, STDERR_FILENO);
            close(ptymaster);
            close(ptyslave);

            // restore all signals to their default actions
            // (e.g., PHP may have ignored SIGPIPE; don't want that
            // to propagate to student code!)
            for (int sig = 1; sig < NSIG; ++sig)
                signal(sig, SIG_DFL);

            if (execve(this->argv[0], (char* const*) this->argv,
                       (char* const*) newenv) != 0) {
                fprintf(stderr, "exec %s: %s\n", owner_sh.c_str(), strerror(errno));
                exit(126);
            }
        } else
            wait_background(child, ptymaster);
    }

    return 0;
}

extern "C" {
void sighandler(int signo) {
    if (signo == SIGTERM)
        got_sigterm = 1;
    char c = (char) signo;
    ssize_t w = write(sigpipe[1], &c, 1);
    (void) w;
}

void cleanup_pidfd(void) {
    if (pidfd >= 0)
        write_pid(0);
}
}

static void make_nonblocking(int fd) {
    fcntl(fd, F_SETFL, fcntl(fd, F_GETFL, 0) | O_NONBLOCK);
}

void jailownerinfo::start_sigpipe() {
    int r = pipe(sigpipe);
    if (r != 0)
        perror_die("pipe");
    make_nonblocking(inputfd);
    make_nonblocking(STDOUT_FILENO);
    make_nonblocking(sigpipe[0]);
    make_nonblocking(sigpipe[1]);

    struct sigaction sa;
    sa.sa_handler = sighandler;
    sigemptyset(&sa.sa_mask);
    sa.sa_flags = 0;
    sigaction(SIGCHLD, &sa, NULL);
    sigaction(SIGTERM, &sa, NULL);

    FD_ZERO(&readset);
    FD_ZERO(&writeset);
}

void jailownerinfo::buffer::transfer_in(int from) {
    if (tail == sizeof(buf) && head != 0) {
        memmove(buf, &buf[head], tail - head);
        tail -= head;
        head = 0;
    }

    if (from >= 0 && !input_closed && tail != sizeof(buf)) {
        ssize_t nr = read(from, &buf[tail], sizeof(buf) - tail);
        if (nr != 0 && nr != -1)
            tail += nr;
        else if (nr == 0 && !input_isfifo) {
            // don't want to give up on input if it's a fifo
            struct stat st;
            if (fstat(from, &st) == 0 && S_ISFIFO(st.st_mode))
                input_isfifo = true;
            else
                input_closed = true;
        } else if (nr == -1 && errno != EINTR && errno != EAGAIN) {
            input_closed = true;
            rerrno = errno;
        }
    }
}

void jailownerinfo::buffer::transfer_out(int to) {
    if (to >= 0 && !output_closed && head != tail) {
        ssize_t nw = write(to, &buf[head], tail - head);
        if (nw != 0 && nw != -1)
            head += nw;
        else if (errno != EINTR && errno != EAGAIN)
            output_closed = true;
    }
}

void jailownerinfo::block(int ptymaster) {
    int maxfd = sigpipe[0];
    FD_SET(sigpipe[0], &readset);

    if (!to_slave.input_closed && !to_slave.output_closed) {
        FD_SET(inputfd, &readset);
        maxfd < inputfd && (maxfd = inputfd);
    } else
        FD_CLR(inputfd, &readset);
    if (!to_slave.output_closed && to_slave.head != to_slave.tail) {
        FD_SET(ptymaster, &writeset);
        maxfd < ptymaster && (maxfd = ptymaster);
    } else
        FD_CLR(ptymaster, &writeset);

    if (!from_slave.input_closed && !from_slave.output_closed) {
        FD_SET(ptymaster, &readset);
        maxfd < ptymaster && (maxfd = ptymaster);
    } else
        FD_CLR(ptymaster, &readset);
    if (!from_slave.output_closed && from_slave.head != from_slave.tail) {
        FD_SET(STDOUT_FILENO, &writeset);
        maxfd < STDOUT_FILENO && (maxfd = STDOUT_FILENO);
    } else
        FD_CLR(STDOUT_FILENO, &writeset);

    if (timerisset(&timeout)) {
        struct timeval delay;
        gettimeofday(&delay, 0);
        timersub(&timeout, &delay, &delay);
        select(maxfd + 1, &readset, &writeset, NULL, &delay);
    } else
        select(maxfd + 1, &readset, &writeset, NULL, NULL);

    if (FD_ISSET(sigpipe[0], &readset)) {
        char buf[128];
        while (read(sigpipe[0], buf, sizeof(buf)) > 0)
            /* skip */;
    }
}

int jailownerinfo::check_child_timeout(pid_t child, bool waitpid) {
    std::pair<pid_t, int> xr;
    do {
        xr = x_waitpid(-1, WNOHANG);
        if (xr.first == child)
            child_status = xr.second;
    } while (xr.first != -1);
    if (errno != EAGAIN && errno != ECHILD)
        return 125;

    if (child_status >= 0 && waitpid)
        return child_status;

    if (got_sigterm)
        return 128 + SIGTERM;

    struct timeval now;
    if (timerisset(&timeout)
        && gettimeofday(&now, NULL) == 0
        && timercmp(&now, &timeout, >))
        return 124;

    errno = EAGAIN;
    return -1;
}

void jailownerinfo::wait_background(pid_t child, int ptymaster) {
    // go back to being the caller
    // XXX (preserve the saved root identity just in case)
    if (setresuid(ROOT, ROOT, ROOT) != 0
        || setresgid(caller_group, caller_group, ROOT) != 0
        || setresuid(caller_owner, caller_owner, ROOT) != 0) {
        perror("setresuid");
        exec_done(child, 127);
    }

    // if input is a tty, put it in non-canonical mode
    struct termios tty;
    if (tcgetattr(STDIN_FILENO, &stdin_termios) >= 0) {
        has_stdin_termios = true;
        tty = stdin_termios;
        // Noncanonical mode, disable signals, no echoing
        tty.c_lflag &= ~(ICANON | ISIG | ECHO);
        // Character-at-a-time input with blocking
        tty.c_cc[VMIN] = 1;
        tty.c_cc[VTIME] = 0;
        (void) tcsetattr(STDIN_FILENO, TCSAFLUSH, &tty);
    }

    // blocking reads please (well, block for up to 0.5sec)
    // the 0.5sec wait means we avoid long race conditions
    if (tcgetattr(ptymaster, &tty) >= 0) {
        tty.c_cc[VMIN] = 1;
        tty.c_cc[VTIME] = 5;
        tcsetattr(ptymaster, TCSANOW, &tty);
    }
    make_nonblocking(ptymaster);
    fflush(stdout);

    while (1) {
        block(ptymaster);
        to_slave.transfer_in(inputfd);
        if (to_slave.head != to_slave.tail
            && memmem(&to_slave.buf[to_slave.head], to_slave.tail - to_slave.head,
                      "\x1b\x03", 2) != NULL)
            exec_done(child, 128 + SIGTERM);
        to_slave.transfer_out(ptymaster);
        from_slave.transfer_in(ptymaster);
        from_slave.transfer_out(STDOUT_FILENO);

        // check child and timeout
        // (only wait for child if read done/failed)
        int exit_status = check_child_timeout(child, from_slave.input_closed);
        if (exit_status != -1)
            exec_done(child, exit_status);

        // if child has not died, and read produced error, report it
        if (from_slave.input_closed && from_slave.rerrno != EIO) {
            fprintf(stderr, "read: %s\n", strerror(from_slave.rerrno));
            exec_done(child, 125);
        }
    }
}

void jailownerinfo::exec_done(pid_t child, int exit_status) {
    const char* xmsg = nullptr;
    if (exit_status == 124 && !quiet)
        xmsg = "...timed out";
    if (exit_status == 128 + SIGTERM && !quiet)
        xmsg = "...terminated";
    if (xmsg)
        printf(isatty(STDOUT_FILENO) ? "\n\x1b[3;7;31m%s\x1b[0m\n" : "\n%s\n",
               xmsg);
#if __linux__
    (void) child;
#else
    if (exit_status >= 124)
        kill(child, SIGKILL);
#endif
    fflush(stdout);
    if (has_stdin_termios)
        (void) tcsetattr(STDIN_FILENO, TCSAFLUSH, &stdin_termios);
    exit(exit_status);
}


static __attribute__((noreturn)) void usage() {
    fprintf(stderr, "Usage: pa-jail init [-nh] [-f FILES] [-S SKELETON] JAILDIR [USER]\n");
    fprintf(stderr, "       pa-jail run [--fg] [-nqh] [-T TIMEOUT] [-p PIDFILE] [-i INPUT] \\\n");
    fprintf(stderr, "                   [-f FILES] [-S SKELETON] JAILDIR USER COMMAND\n");
    fprintf(stderr, "       pa-jail mv OLDDIR NEWDIR\n");
    fprintf(stderr, "       pa-jail rm [-nf] JAILDIR\n");
    exit(1);
}

static struct option longoptions_before[] = {
    { "verbose", no_argument, NULL, 'V' },
    { "dry-run", no_argument, NULL, 'n' },
    { "help", no_argument, NULL, 'H' },
    { NULL, 0, NULL, 0 }
};

static struct option longoptions_run[] = {
    { "verbose", no_argument, NULL, 'V' },
    { "dry-run", no_argument, NULL, 'n' },
    { "help", no_argument, NULL, 'H' },
    { "skeleton", required_argument, NULL, 'S' },
    { "pid-file", required_argument, NULL, 'p' },
    { "files", required_argument, NULL, 'f' },
    { "fg", no_argument, NULL, 'F' },
    { "timeout", required_argument, NULL, 'T' },
    { "input", required_argument, NULL, 'i' },
    { "chown-home", no_argument, NULL, 'h' },
    { NULL, 0, NULL, 0 }
};

static struct option longoptions_rm[] = {
    { "verbose", no_argument, NULL, 'V' },
    { "dry-run", no_argument, NULL, 'n' },
    { "help", no_argument, NULL, 'H' },
    { "force", no_argument, NULL, 'f' },
    { NULL, 0, NULL, 0 }
};

static struct option* longoptions_action[] = {
    longoptions_before, longoptions_run, longoptions_run, longoptions_rm, longoptions_before
};
static const char* shortoptions_action[] = {
    "+Vn", "VnS:f:p:T:qi:h", "VnS:f:p:T:qi:h", "Vnf", "Vn"
};

int main(int argc, char** argv) {
    // parse arguments
    jailaction action = do_start;
    bool chown_home = false, foreground = false;
    double timeout = -1;
    std::string filesarg, inputarg, linkarg;

    int ch;
    while (1) {
        while ((ch = getopt_long(argc, argv, shortoptions_action[(int) action],
                                 longoptions_action[(int) action], NULL)) != -1) {
            if (ch == 'V')
                verbose = true;
            else if (ch == 'S')
                linkarg = optarg;
            else if (ch == 'n')
                verbose = dryrun = true;
            else if (ch == 'f' && action == do_rm)
                doforce = true;
            else if (ch == 'f')
                filesarg = optarg;
            else if (ch == 'p')
                pidfilename = optarg;
            else if (ch == 'i')
                inputarg = optarg;
            else if (ch == 'F')
                foreground = true;
            else if (ch == 'h')
                chown_home = true;
            else if (ch == 'q')
                quiet = true;
            else if (ch == 'T') {
                char* end;
                timeout = strtod(optarg, &end);
                if (end == optarg || *end != 0)
                    usage();
            } else /* if (ch == 'H') */
                usage();
        }
        if (action != do_start)
            break;
        if (optind == argc)
            usage();
        else if (strcmp(argv[optind], "rm") == 0)
            action = do_rm;
        else if (strcmp(argv[optind], "mv") == 0)
            action = do_mv;
        else if (strcmp(argv[optind], "init") == 0)
            action = do_init;
        else if (strcmp(argv[optind], "run") == 0)
            action = do_run;
        else
            usage();
        argc -= optind;
        argv += optind;
        optind = 1;
    }

    // check arguments
    if (action == do_run && optind + 2 >= argc)
        action = do_init;
    if ((action == do_rm && optind + 1 != argc)
        || (action == do_mv && optind + 2 != argc)
        || (action == do_init && optind != argc - 1 && optind + 2 != argc)
        || (action == do_run && optind + 3 > argc)
        || (action == do_rm && (!linkarg.empty() || !filesarg.empty() || !inputarg.empty()))
        || (action == do_mv && (!linkarg.empty() || !filesarg.empty() || !inputarg.empty()))
        || !argv[optind][0]
        || (action == do_mv && !argv[optind+1][0]))
        usage();
    if (verbose && !dryrun)
        verbosefile = stderr;

    // parse user
    jailownerinfo jailuser;
    if ((action == do_init || action == do_run) && optind + 1 < argc)
        jailuser.init(argv[optind + 1]);

    // open file list as current user
    FILE* filesf = NULL;
    if (filesarg == "-") {
        filesf = stdin;
        if (isatty(STDIN_FILENO))
            die("stdin: Is a tty\n");
    } else if (!filesarg.empty()) {
        filesf = fopen(filesarg.c_str(), "r");
        if (!filesf)
            perror_die(filesarg);
    }

    // open infile non-blocking as current user
    int inputfd = 0;
    if (!inputarg.empty() && !dryrun) {
        inputfd = open(inputarg.c_str(), O_RDONLY | O_CLOEXEC | O_NONBLOCK);
        if (inputfd == -1)
            perror_die(inputarg);
    }

    // open pidfile as current user
    if (!pidfilename.empty() && verbose)
        fprintf(verbosefile, "touch %s\n", pidfilename.c_str());
    if (!pidfilename.empty() && !dryrun) {
        pidfd = open(pidfilename.c_str(), O_WRONLY | O_CLOEXEC | O_CREAT | O_TRUNC, 0666);
        if (pidfd == -1)
            perror_die(pidfilename);
        atexit(cleanup_pidfd);
    }

    // escalate so that the real (not just effective) UID/GID is root. this is
    // so that the system processes will execute as root
    caller_owner = getuid();
    caller_group = getgid();
    if (!dryrun && setresgid(ROOT, ROOT, ROOT) < 0)
        perror_die("setresgid");
    if (!dryrun && setresuid(ROOT, ROOT, ROOT) < 0)
        perror_die("setresuid");

    // check the jail directory
    // - no special characters
    // - path has no symlinks
    // - at least one permdir has a file `pa-jail.conf` owned by root
    //   and writable only by root, that contains `enablejail`
    // - everything above that dir is owned by by root and writable only by
    //   root
    // - no permdir has a file `pa-jail.conf` not owned by root,
    //   writable by other than root, or containing `disablejail`
    // - stuff below the dir containing the allowing `pa-jail.conf`
    //   dynamically created if necessary
    // - try to eliminate TOCTTOU
    jaildirinfo jaildir(argv[optind], linkarg, action);

    // move the sandbox if asked
    if (action == do_mv) {
        std::string newpath = check_filename(absolute(argv[optind + 1]));
        if (newpath.empty() || newpath[0] != '/')
            die("%s: Bad characters in move destination\n", argv[optind + 1]);
        else if (newpath.length() <= jaildir.permdir.length()
                 || newpath.substr(0, jaildir.permdir.length()) != jaildir.permdir)
            die("%s: Not a subdirectory of %s\n", newpath.c_str(), jaildir.permdir.c_str());

        // allow second argument to be a directory
        struct stat s;
        if (stat(newpath.c_str(), &s) == 0 && S_ISDIR(s.st_mode))
            newpath = path_endslash(newpath) + jaildir.component;

        if (verbose)
            fprintf(verbosefile, "mv %s%s %s\n", jaildir.parent.c_str(), jaildir.component.c_str(), newpath.c_str());
        if (!dryrun && renameat(jaildir.parentfd, jaildir.component.c_str(), jaildir.parentfd, newpath.c_str()) != 0)
            die("mv %s%s %s: %s\n", jaildir.parent.c_str(), jaildir.component.c_str(), newpath.c_str(), strerror(errno));
        exit(0);
    }

    // kill the sandbox if asked
    if (action == do_rm) {
        // unmount EVERYTHING mounted in the jail!
        // INCLUDING MY HOME DIRECTORY
        jaildir.dir = path_endslash(jaildir.dir);
        populate_mount_table();
        for (auto it = mount_table.begin(); it != mount_table.end(); ++it)
            if (it->first.length() >= jaildir.dir.length()
                && memcmp(it->first.data(), jaildir.dir.data(),
                          jaildir.dir.length()) == 0)
                handle_umount(it);
        // remove the jail
        jaildir.remove();
        exit(0);
    }

    // check skeleton directory
    if (jaildir.skeleton_allowed) {
        if (v_ensuredir(jaildir.skeletondir, 0700, true) < 0)
            perror_die(jaildir.skeletondir);
        linkdir = path_noendslash(jaildir.skeletondir);
    }

    // create the home directory
    if (!jailuser.owner_home.empty()) {
        if (v_ensuredir(jaildir.dir + "/home", 0755, true) < 0)
            perror_die(jaildir.dir + "/home");
        std::string jailhome = jaildir.dir + jailuser.owner_home;
        int r = v_ensuredir(jailhome, 0700, true);
        uid_t want_owner = action == do_init ? caller_owner : jailuser.owner;
        gid_t want_group = action == do_init ? caller_group : jailuser.group;
        if (r < 0
            || (r > 0 && x_lchown(jailhome.c_str(), want_owner, want_group)))
            perror_die(jailhome);
    }

    // set ownership
    if (chown_home)
        jaildir.chown_home();
    dstroot = path_noendslash(jaildir.dir);
    assert(dstroot != "/");

    // construct the jail
    if (filesf) {
        mode_t old_umask = umask(0);
        if (construct_jail(jaildir.dev, filesf) != 0)
            exit(1);
        fclose(filesf);
        umask(old_umask);
    }

    // close `parentfd`
    close(jaildir.parentfd);
    jaildir.parentfd = -1;

    // maybe execute a command in the jail
    if (optind + 2 < argc)
        jailuser.exec(argc, argv, jaildir, inputfd, timeout, foreground);

    exit(0);
}
