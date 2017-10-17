Peteramati runners
==================

Peteramati can run student code in Linux containers on request.

Jail configuration
------------------

Student code containers are stored in a directory called the _jail_.
Restrictions on the jail directory aim to prevent accidental misconfiguration
from dumping student code all over your filesystem.

The jail directory is specified as an absolute pathname. It can contain no
special characters—only letters, numbers, and characters in `-._~`. There must
be no symbolic links in the path.

The jail directory must also be _enabled_ by the `/etc/pa-jail.conf`
configuration file. To enable a directory `DIR` and its descendants for jails,
add the line `enablejail DIR` to `/etc/pa-jail.conf`. `DIR` can also be a
shell-style matching pattern, such as `/usr/local/pa-jail-*`.

To disable a jail directory and its descendants, add a line `disablejail DIR`
or `disablejail`. Peteramati will reject any jail that isn’t enabled.

Peteramati automatically creates missing directories, `mkdir -p` style,
underneath the enabled ancestor.

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
