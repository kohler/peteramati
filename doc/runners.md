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

5. All of the directories above the enabling `pa-jail.conf` file must
be owned by root and writable only by root.

A `pa-jail.conf` file “enables” a jail directory by containing a line
`enablejail` or `enablejail SUBDIR` (where `SUBDIR` matches the rest
of the jail). It “disables” a jail directory by containing a line
`disablejail` or `disablejail SUBDIR`.

Container initialization
------------------------

To initialize a student container, peteramati runs the following
steps.

