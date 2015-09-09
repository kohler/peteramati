Peteramati `psets.json` files
=============================

Peteramati problem sets are configured primarily through JSON files. This
document describes the JSON format.

Location and composition
------------------------

The location of `psets.json` is set in `conf/options.php`:

    $Opt["psetsConfig"] = PATHNAME;

`PATHNAME` is relative to the Peteramati directory.

A `psets.json` file can be merged from several JSON components:

    $Opt["psetsConfig"] = [PATHNAME1, PATHNAME2, ...];

Peteramati will first read PATHNAME1, then PATHNAME2, and so forth, and merge
the resulting JSON objects. In case of key conflicts, later values win. For
example, given these files:

    psets1.json: { "a": "pset1", "b": {"c": "pset1"} }
    psets2.json: { "a": "pset2", "b": {"d": "pset2"} }

a `psetsConfig` option of `["psets1.json", "psets2.json"]` would produce this
result:

    { "a": "pset2", "b": {"c": "pset1", "d": "pset2"} }

You may also use shell wildcards in the PATHNAMEs (`*`, `?`, `[...]`).
Peteramati will read and merge any matching files.

If Peteramati cannot load a required `psets.json` file—either because the file
doesn't exist or is unreadable, or because of a JSON parsing error—it will
refuse to initialize.

Format
------

A `psets.json` configuration is a JSON object defining *problem sets*, plus
other configuration information. Here's a small example:

```json
{
    "1": {
        "psetid": 1,
        "title": "problem set 1",
        "partner": true,
        "grades": {
            "tests": {"max": 70},
            "style": {"max": 10, "is_extra": true}
        },
        "visible": true
    },
    "2": {
        "psetid": 2,
        "title": "problem set 2 (not ready yet)",
        "disabled": true
    },
    "_defaults": {
        "grade_cdf_cutoff": 0.25,
        "handout_repo_url": "git://code.seas.harvard.edu/cs61/cs61-psets.git"
    }
}
```

This defines two problem sets, "1" and "2". The `_defaults` entry defines
entries common to the problem sets. (All keys that start with an underscore
provide non-problem-set configuration.)

Problem sets
------------

A problem set defines everything about an assignment: its name and title, its
deadlines, its grade entries, and its run entries.

Problem sets have keys that don't start with underscore, and must contain a
`psetid` entry. The configuration for a problem set is obtained by merging it
with the `_defaults` object, if any.

Some settings are identified as “dates”. Dates are specified either as
integers, which are Unix timestamps (seconds since the epoch), or strings
(like "2014-10-07 00:00 EDT"), which are parsed.

### Identifiers

Each problem set has several identifiers. The `psetid` is used internally; the
others are shown to users in different contexts.

* `psetid`: positive integer **REQUIRED**

    Unique identifier for the problem set. This identifier is stored in the
    database, so you should never change it once you've released a problem set
    (or student work and grades will effectively disappear).

* problem set key: string

    This is the JSON key used to define the problem set. It cannot start with
    an underscore. It may be a numeric string, but if so, it must equal
    `psetid`.

* `urlkey`: string

    This string is the preferred way to identify the problem set in URIs. It
    defaults to the problem set key, but may differ; for example, if the
    problem set key is `pset1`, you might prefer the shorter `1` for URIs,
    since `peteramati/pset/pset1` looks redundant.

### Basics

* `title`: string

    Human-friendly problem set name. This is the name displayed in the
    interface. Defaults to the problem set's JSON key.

* `partner`: boolean

    Set to true if this assignment may be completed in pairs.

* `visible`: boolean or date

    Set to true to make the problem set visible to students. If false or
    absent, the problem set is hidden on the student UI. If a date, then the
    problem set will become visible at that time.

* `disabled`: boolean

    Set to true to hide the problem set from students and TFs. A disabled
    problem set is not present in the UI at all.

* `frozen`: boolean or date

    Set to true to prevent students from changing problem set data
    (repository, preferred commit).

* `anonymous`: boolean

    Set to true to hide student identities from graders.

### Git configuration

* `gitless`: boolean

    Set to true to mark the problem set as “gitless”. Use this for paper
    assignments, tests, etc.

* `handout_repo_url`: string

    Git URL for handout code.

* `directory`: string

    Subdirectory containing the problem set code. Use this when a user's
    repository contains data from multiple problem sets.

* `test_file`: string

    The name of a file that should be present in the student’s repository (in
    the specified `directory`, if any). Used to detect when a student ignores
    the subdirectory structure.

* `repo_guess_patterns`: array of strings

    These patterns are used to try to guess a student’s repository URL from
    their user name. If given, the array should contain a list of pattern,
    replacement pairs. The pattern is used to match against the student’s user
    name. If it does match, the corresponding replacement is used as a default
    repository URL. For example:

        ["^([_a-zA-Z0-9.]*s)$", "~$1/cs61/$1-cs61-psets",
         "^([_a-zA-Z0-9.]+)$", "~$1/cs61/$1s-cs61-psets"]

### Deadlines

* `deadline`: date

    The time at which the problem set is due.

### Grades

* `grades`: object or array of grade entries (see below)

* `grades_visible`: boolean or date

    Set to true to make grades visible to students. Implies `visible: true`.

* `grade_cdf_visible`: boolean

    Set to true to make the CDF of all grades visible to students, or false to
    hide it. Defaults to the value of `grade_visible`.

* `grade_cdf_cutoff`: number between 0 and 1

    The CDF graph of grades is cut off below this number. The idea is to avoid
    unnecessary student distress. For example, if `grade_cdf_cutoff` is 0.25,
    then students in the bottom quarter of the grade distribution will not be
    shown their exact standing within that quarter.

* `separate_extension_grades`: boolean

    If true, then extension students are shown their performance relative to
    other extension students (as well as all students); for instance, grade
    CDFs will have a separate extension-only line.

### Grade entries

A grade entry object defines a line in the grading rubric.

* `name`: string (REQUIRED but defaults to the key in `grades`)

    The internal name for the grade entry. This name is used inside the
    database, so don’t change it after grades are assigned. Every entry for a
    problem set must have a unique name.

* `title`: string

    The name shown for the grade entry.

* `max`: number

    The maximum number of points for this entry.

* `hide`: boolean

    If true, then students cannot see this grade (graders can).

* `hide_max`: boolean

    If true, then students cannot see the value of `max` (graders can).

* `is_extra`: boolean

    If true, then this is an extra-credit entry.

* `no_total`: boolean

    If true, then this grade is not included in the total.

Grade entries are displayed in order. Alternately, you can provide a
`grade_order` setting in the problem set; this should be an array of entry
names.

### Code display

Code display is controlled by the `diffs` setting, which is an object keyed by
regular expression. For example, this setting says:

```json
"diffs": {
    "README\\.txt": {"full": true, "priority": 1},
    "\\.gitignore|check\\.pl": {"boring": true},
    "out": {"ignore": true}
}
```

* Files named `README.txt` will be displayed in full (not as a diff), before
  other files (files by default have priority 0).

* Files named `.gitignore` and `check.pl` are “boring.” This means that any
  diffs in these files will not be displayed by default.

* Files and directories named `out` are entirely ignored; they will not appear
  in diffs at all.

Regular expressions must match full filename components, so the `"out"` entry
will not match a file named `"out.txt"`.

Alternately, the `ignore` setting is a shorthand way of specifying files to
ignore; the setting `"ignore": "*.txt"` means the same as

```json
"diffs": {".*\\.txt": {"ignore": true}}
```

### Running code

TBA
