Peteramati runners
==================

Peteramati can run student code in Linux containers on request. This
feature is powerful and requires configuration.

Jail configuration
------------------

Student code containers are stored in a directory called the _jail_.
Restrictions on the jail directory aim to prevent accidental misconfiguration
from dumping student code all over your filesystem.

The jail directory is specified as an absolute pathname. It can contain no
special characters—only letters, numbers, and characters in `-._~`. There must
be no symbolic links in the path.

The jail directory must also be _enabled_ by a `pa-jail.conf` configuration
file. Peteramati searches for `pa-jail.conf` files in `/etc`, and in all
ancestors of the jail directory, starting at the root. For instance, if the
jail directory were `/usr/local/pa-jails/cs101/user1`, peteramati would check
for:

    /etc/pa-jail.conf
    /pa-jail.conf
    /usr/pa-jail.conf
    /usr/local/pa-jail.conf
    /usr/local/pa-jails/pa-jail.conf
    /usr/local/pa-jails/cs101/pa-jail.conf

To enable a directory `DIR` and its descendants for jails, add a
`pa-jail.conf` line `enablejail DIR`. In the example above,
`/etc/pa-jail.conf` could include `enablejail /usr/local/pa-jails`, making
`/usr/local/pa-jails` the enabled ancestor of the full jail directory
`/usr/local/pa-jails/cs101/user1`. `DIR` can also be a shell-style matching
pattern, such as `/usr/local/pa-jail-*`.

To disable a jail directory and its descendants, add a line `disablejail DIR`
or `disablejail`. Peteramati will reject any jail that isn’t enabled.

Peteramati only _trusts_ `pa-jail.conf` files that are owned by root, writable
only by root, and located in directories owned by and writable only by root.
When encountering an untrusted `pa-jail.conf` file, peteramati ignores it if
the jail directory has been enabled by a past configuration file, and exits
with a fatal error if it has not.

Peteramati automatically creates missing directories, `mkdir -p` style,
underneath the enabled ancestor.

Container components
--------------------

A peteramati student container is built from the following components.

1. The _container list_, which is a list of files on the host file system that
   are copied into the jail. The container list should include important
   utilities like `/bin/sh` and configuration files like `/etc/passwd`, as
   well as files and programs used by student submissions (e.g. header files,
   libraries, and compilers).

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

Creating a container list
-------------------------

The `jail/pa-trace` program offers a pretty easy way to create a container
list.
