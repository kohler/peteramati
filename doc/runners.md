Peteramati runners
==================

Peteramati can run student code in Linux containers on request.

Jail configuration
------------------

Student code containers are stored in a directory called the _jail_.
Restrictions on the jail directory aim to prevent accidental misconfiguration
from dumping student code all over your filesystem.

The jail directory is an absolute pathname. It can contain no special
characters—only letters, numbers, and characters in `-._~`. There must be no
symbolic links in the path, and the directory and the all its parents must be
owned by root and writable only by root.

The jail directory must also be _enabled_ by the `/etc/pa-jail.conf`
configuration file. That file looks like this:

```
enablejail PATTERN
disablejail PATTERN
treedir PATTERN
```

Each PATTERN is a shell wildcard pattern, such as `/jails/*`. The file
is parsed one line at a time.

* `enablejail PATTERN` allows jail directories that match `PATTERN`.

* `disablejail PATTERN` disallows jail directories with leading
  segments that match `PATTERN`. For instance, `disablejail /foo` will
  disable all jails under `/foo`, including `/foo/bar` and
  `/foo/bar/baz`.

* `treedir TREEPATTERN` marks `TREEPATTERN` as a tree directory. If a
  jail directory is allowed and has a leading segment that matches
  `TREEPATTERN`, then components of that directory after `TREEPATTERN`
  may be created at runtime. As a special case, `enablejail PATTERN/*`
  also acts like `treedir PATTERN`.

Container components
--------------------

A peteramati student container is built from the following components.

1. The _manifest_, which is a list of files on the host file system that are
   copied into the jail. The manifest should include important utilities like
   `/bin/sh` and configuration files like `/etc/passwd`, as well as files and
   programs used by student submissions (e.g. header files, libraries, and
   compilers).

2. The student’s code repository. This is checked out into
   `/home/STUDENTUSER/repo`, where STUDENTUSER is an unprivileged system user
   that runs the student’s code.

3. An optional _overlay_ tarball, which is unpacked over
   `/home/STUDENTUSER/repo`. Typically the overlay is used to reset files to
   pristine states—for instance, to reset a grading script, in case the
   student modified the one you handed out—or to add semi-secret configuration
   information, input files, or grading scripts.

The container list, student user, and overlay tarball are configured via
`psets.json`.

Creating a manifest
-------------------

The `jail/pa-trace` program offers a pretty easy way to create a manifest.
