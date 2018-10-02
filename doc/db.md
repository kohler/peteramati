Peteramati database and data
============================

Some Peteramati information is stored in local Git repositories, and in the
database, in JSON, in idiosyncratic ways. What follows is a partial dump of
its formats.


Repositories
------------

Each Git repository tracked by Peteramati corresponds to a `Repository` row,
identified by `repoid` and by `url`.

Peteramati stores student repositories in Git as remotes. Peteramati
initializes up to 16 bare repositories in `PADIR/repo`, subdirectories `repo0`
through `repof`; call these the *cache repositories*. Each student repository
is a remote in one cache repository, identified by its `cacheid`. For
instance, `repoid` 200 will show up as a remote `repo200` in cache repository
`repo/repof`.

Handout repositories are cloned in every cache repository, to facilitate `git
diff`.

We don’t want to lose student code even if a student blows away their
repository with `git reset`. Every time a repository is fetched and the `HEAD`
of `master` changes, Peteramati sets a tag of the form
`repoREPOID.snapYYYYMMDD.HHMMSS`.


Commits
-------

* `gradercid`
* `autogrades`
* `grades`
* `linenotes`
    * key is filename, value is a linenote object:
        * key is `aLINENO` or `bLINENO`
        * value is array: [`iscomment`, `text`, `author(s)`, [`version`, `format`]]
        * or string: `text`
        * or integer, meaning a deleted note: `version`


Commit notes
------------

