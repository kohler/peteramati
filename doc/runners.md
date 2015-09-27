Peteramati runners
==================

Peteramati can run student code in Linux containers on request. This
feature is powerful and requires configuration.

Jail configuration
------------------

Student code containers are stored in a directory called the _jail_.
Restrictions on the jail directory aim to prevent accidental
misconfiguration from dumping student code onto random parts of your
filesystem.

1. The absolute pathname can contain no special characters---only
letters, numbers, and characters in `-._~`.

2. There must be no symbolic links in the path.

3. The jail directory, or one of its parent directories, must contain
a `pa-jail.conf` file, owned by root and writable only by root, that
“enables” the jail directory (see below for how this works).

4. The jail directory and all of its parent directories _must not_
contain a `pa-jail.conf` file that is not owned by root, that is
writable by a user other than root, or that “disables” the jail
directory.

5. The directory containing the enabling `pa-jail.conf` file must be owned by
   root and writable only by root, as must all of its parent directories.

A `pa-jail.conf` file “enables” a jail directory by containing a line
`enablejail` or `enablejail SUBDIR` (where `SUBDIR` matches the rest
of the jail). It “disables” a jail directory by containing a line
`disablejail` or `disablejail SUBDIR`.

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
