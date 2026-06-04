# Peteramati runners

Peteramati can run student code in Linux containers on request.

## Jail configuration

Student code containers are stored in a directory called the _jail_.
Restrictions on the jail directory aim to prevent accidental misconfiguration
from dumping student code all over your filesystem: a jail directory must have a
clean absolute pathname with no symbolic links, be owned and writable only by
root up through all its parents, and be enabled by the `/etc/pa-jail.conf`
configuration file.

The jail directory rules, the `/etc/pa-jail.conf` format (which directories may
be jails, and the resource limits they run under), and the `pa-jail`
command-line tool are documented in [`pajail.md`](pajail.md).

## Container components

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

## Creating a manifest

The `jail/pa-trace` program offers a pretty easy way to create a manifest.
