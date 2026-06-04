# Peteramati `psets.json` files

Peteramati problem sets are configured primarily through JSON files. This
document describes the JSON format.

## Location and composition

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

## Format

A `psets.json` configuration is a JSON object. Keys that do not start with an
underscore define *problem sets*; keys that start with an underscore hold shared
configuration. Here's a small example:

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

This defines two problem sets, "1" and "2". The underscore keys define shared
configuration:

* `_defaults`: object merged into every problem set (recursively, with the
  problem set’s own settings winning).

* `_defaults_GROUP`: object merged into every problem set whose `category` is
  `GROUP` (applied before `_defaults`).

* `_formulas`: named formulas usable across problem sets (see “Formulas”).

* `_grade_types`: definitions of custom grade-entry types.

* `_queues`: run-queue definitions (see “Code execution”).

Some settings are identified as “dates”. Dates are specified either as integers,
which are Unix timestamps (seconds since the epoch), or strings (like "2014-10-07
00:00 EDT"), which are parsed. Some are “intervals”: a number of seconds, or a
string like `10m`, `20s`, `1.5h`, or `2d`.

## Problem sets

A problem set defines everything about an assignment: its name and title, its
deadlines, its grade entries, and its run entries.

Problem sets have keys that don't start with underscore, and must contain a
`psetid` entry. The configuration for a problem set is obtained by merging it
with `_defaults_GROUP` (if it has a `category`) and `_defaults`.

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

* `category`: string (alias `group`)

    Groups related problem sets (for instance, for display and for the
    `_defaults_GROUP` mechanism). Problem sets in the same category are
    considered successive versions of one another for “predecessor” purposes.

* `weight`: number

    Relative weight of this problem set, e.g. when computing aggregate grades.

* `order`: number (alias `position`)

    Determines display order among problem sets. Lower values sort first.

* `partner`: boolean

    Set to true if this assignment may be completed in pairs.

* `visible`: boolean or date

    Set to true to make the problem set visible to students. If false or
    absent, the problem set is hidden on the student UI. If a date, then the
    problem set will become visible at that time.

* `disabled`: boolean

    Set to true to hide the problem set from students and TFs. A disabled
    problem set is not present in the UI at all.

* `removed`: boolean (alias `admin_disabled`)

    Like `disabled`, but also marks the problem set as removed.

* `frozen`: boolean or date

    Set to true to prevent students from changing problem set data
    (repository, preferred commit).

* `anonymous`: boolean

    Set to true to hide student identities from graders.

* `hide_comments`: boolean

    Set to true to hide line comments for this problem set.

### Git configuration

* `gitless`: boolean

    Set to true to mark the problem set as “gitless”. Use this for paper
    assignments, tests, etc.

* `gitless_grades`: boolean

    Set to true to store the problem set’s grades independently of the
    student’s git history. Normally, peteramati stores a different grade per
    commit in the student’s history. (This facilitates regrades and can keep
    some grading history.) With `gitless_grades`, grades are stored once per
    student. Implied by `gitless`.

* `partner_repo`: string

    If `"same"` (the default), partners should have the same repository. If
    `"different"`, partners should have different repositories.

* `main_branch`: string

    The repository’s main branch. Defaults to `master` (or `main` if
    `handout_branch` is `main`).

* `no_branch`: boolean

    Set to true to disallow per-student branch selection.

* `handout_repo_url`: string

    Git URL for handout code. Required unless `gitless`.

* `handout_branch`: string

    Branch to use for handout code. Defaults to `main_branch`.

* `handout_hash`: string

    Commit hash to use for handout code and diffs. If not set, diffs are shown
    relative to a derived handout hash (the latest handout hash in the current
    commit’s history).

* `handout_warn_hash`: string

    Warn user if their handout code is before this hash. If not set,
    `handout_hash` is used; if that’s not set, the latest handout commit is
    used.

* `handout_warn_merge`: boolean

    If true, warn when a student’s history merged a stale handout.

* `directory`: string

    Subdirectory containing the problem set code. Use this when a user's
    repository contains data from multiple problem sets.

* `allow_directory_override`: boolean

    If true, allow detecting the problem set even when a student places code
    outside `directory`.

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

* `grading_commit_function`: string

    PHP callback used to choose the grading commit for a student.

### Deadlines

* `deadline`: date

    The time at which the problem set is due.

* `deadline_college`, `deadline_extension`: date

    Separate deadlines for college and extension students. If `deadline` is
    unset, it defaults to `deadline_college` (or `deadline_extension`).

* `obscure_late_hours`: boolean

    If true, then do not highlight when a commit is past the deadline.

* `no_late_hours`: boolean

    If true, suppress the automatic “late hours” grade entry.

## Grades

Peteramati collects grades from teachers and displays them to students. The
`grades` setting, which is either a keyed object or an array, defines the
lines in the grading rubric.

### Grade entries

The `grades` setting comprises a collection of entries. Each entry follows
this format.

* `key`: string (defaults to the key in `grades`)

    The internal name for the grade entry. This name is used in the database,
    so don’t change it after grades are assigned. Every entry for a problem
    set must have a unique name, and it cannot be a reserved name such as
    `total` or `late_hours`.

* `title`: string

    The name shown for the grade entry. Defaults to `key`.

* `type`: string

    The grade entry’s type. One of:

    * `null` or `"number"` — a numeric grade (the default);
    * `"checkbox"` — a single checkbox worth `max` points;
    * `"checkboxes"`, `"stars"`, `"poops"` — 1–`max` repeated marks;
    * `"letter"` — a letter grade (`max` must be 100);
    * `"select"` — one of the strings in `options`;
    * `"text"`, `"shorttext"`, `"markdown"` — free-form text;
    * `"section"` — a heading that groups following entries;
    * `"timermark"`, `"duration"` — time-based entries;
    * `"formula"` — a computed grade (see `formula`);
    * `"none"` — no value (display only).

    Custom types may be defined in the top-level `_grade_types`.

* `options`: array of strings

    The choices for a `select` entry.

* `formula`: string

    A grade formula. Setting `formula` implies `type: "formula"`.

* `round`: string

    For numeric grades, how entered values are rounded: `"none"`, `"up"`,
    `"down"`, or `"round"`.

* `max`: number

    The maximum number of points for this entry.

* `visible`: boolean (or set `hidden` for the inverse)

    If false, students cannot see this grade (graders can). Defaults to true.

* `visible_if`, `suppressed_if`: string

    Conditional visibility expressions.

* `max_visible`: boolean

    If false, students cannot see the value of `max` (graders can). Defaults to
    true.

* `answer`: boolean (alias `student`)

    If true, students can edit this grade (it is a student “answer”).

* `is_extra`: boolean

    If true, then this is an extra-credit entry.

* `no_total`: boolean (or set `in_total` for the inverse)

    If true, then this grade is not included in the total. Some types (e.g.
    `letter`, `formula`) are never in the total.

* `required`: boolean

    If true, the entry must be filled in.

* `concealed`: boolean

    If true, the entry is concealed from students even where grades are shown.

* `order`: number (alias `position`)

    Determines display order. Grade entries are sorted first by increasing
    `order`, and second by the order they appear in `psets.json`. Negative
    `order` entries appear first.

* `collate`: boolean

    If true, then gradesheets allow selecting this grade for many students.

* `table_color`: string

    A background color for the entry’s column in gradesheets.

* `landmark`: string, like `"FILENAME:LINE"`

    If set, then in diff display a text box for grade entry is placed
    underneath line FILENAME:LINE from the handout code.

* `landmark_range`: string, like `"FILENAME:LINE1:LINE2"`

    A range of handout lines associated with the entry. Implies `collate`.

* `landmark_buttons`: array

    Buttons shown at the landmark, for quick grade entry.

* `timeout`: interval; `timeout_entry`: string

    For `timermark` entries, the time limit and a companion entry.

* `disabled`: boolean; `disabled_if`: string

    Disable the entry, unconditionally or by expression.

* `allow_edit_function`, `account_edit_function`: string

    PHP callbacks controlling who may edit the grade and how edits are
    accounted.

To reorder grade entries explicitly, set the problem set’s `grade_order` to an
array of grade keys.

### Grade display

These problem set settings control how grades are displayed.

* `scores_visible`: boolean or date (alias `grades_visible`)

    Set to true to make grades visible to students. Implies `visible: true`.

* `grade_statistics_visible`: boolean, date, or `"grades"`

    Set to true to make statistics of all grades visible to students, or false
    to hide them. Defaults to `"grades"`, which means visible when grades are
    visible.

* `grade_cdf_cutoff`: number between 0 and 1

    The CDF graph of grades is cut off below this number. The idea is to avoid
    unnecessary student distress. For example, if `grade_cdf_cutoff` is 0.25,
    then students in the bottom quarter of the grade distribution will not be
    shown their exact standing within that quarter.

* `separate_extension_grades`: boolean

    If true, then extension students are shown their performance relative to
    other extension students (as well as all students); for instance, grade
    CDFs will have a separate extension-only line.

* `grades_history`: boolean

    If true, retain a history of grade changes.

* `grades_selection_function`: string

    PHP callback used to select which grades apply.

## Downloads

The `downloads` setting defines downloadable files associated with a problem
set (for example, handout archives or test inputs). It is a keyed object of
download entries; each entry has this format.

* `key`: string (defaults to the entry key)

    Internal identifier for the download.

* `title`: string

    Display title. Defaults to `key`.

* `file`: string **REQUIRED**

    The file to download.

* `filename`: string

    The download’s suggested filename. Defaults to `key`.

* `visible`: boolean, date, or `"grades"`

    When the download is offered to students.

* `timed`: boolean; `timeout`: interval

    If `timed`, the download starts a timer (e.g. for timed exams); `timeout`
    sets the limit.

* `order`: number (alias `position`)

    Display order.

## Formulas

The top-level `_formulas` object defines named formulas usable across problem
sets. Each entry has this format.

* `name`: string

    The formula’s identifier (usable in other formulas and grade entries).

* `title`, `description`: string

    Human-readable name and description.

* `formula`: string

    The formula expression.

* `visible`: boolean (or `hidden` for the inverse)

    Whether the formula is shown to students.

* `nonzero`: boolean

    If true, the formula is only displayed when its value is nonzero.

* `home_order`: number (alias `home_position`)

    Display order on the home page.

## Code display

Code display is controlled by the `diffs` problem set setting, which is an
object keyed by regular expression (or an array of objects with a `match`
key). For example:

```json
"diffs": {
    "README\\.txt": {"full": true, "order": -1},
    "\\.gitignore|check\\.pl": {"collapse": true},
    "out": {"ignore": true}
}
```

This setting says:

* Files named `README.txt` will be displayed in full (not as a diff), before
  other files (files by default have order 0).

* Files named `.gitignore` and `check.pl` are collapsed. This means that diffs
  in these files will not be displayed by default.

* Files and directories named `out` are entirely ignored; they will not appear
  in diffs at all.

Regular expressions must match full filename components, so the `"out"` entry
will not match a file named `"out.txt"`.

Each diff entry may set:

* `match`: string (alias `regex`)

    The regular expression matched against filename components. Alternately, use
    `filename` to match a literal filename suffix, or `extension` to match a
    filename extension (like `".c"`).

* `title`: string

    A display title for the file.

* `priority`: number (alias `match_priority`)

    When several entries match a file, higher-priority entries win.

* `order`: number (alias `position`); `extension_order`: number

    Display order among files.

* `ignore`: boolean

    Omit the file from diffs entirely.

* `full`: boolean

    Display the file in full rather than as a diff.

* `collapse`: boolean

    Collapse the file’s diff by default.

* `fileless`: boolean

    Treat the entry as not corresponding to a real file.

* `gradable`: boolean

    Allow line-by-line grade annotations on the file.

* `hide_if_anonymous`: boolean

    Hide the file when grading anonymously.

* `markdown`, `markdown_allowed`: boolean

    Render the file as Markdown, or allow Markdown rendering as an option.

* `highlight`, `highlight_allowed`: boolean

    Syntax-highlight the file, or allow highlighting as an option. (Peteramati
    highlights common languages by default based on filename.)

* `language`: string

    The syntax-highlighting language.

* `tabwidth`: integer

    Tab width for display.

* `base`: integer or string

    The diff base (e.g. a commit or handout reference) for this file.

The `ignore` setting is also available directly on the problem set as a
shorthand for files to ignore; `"ignore": "*.txt"` means the same as

```json
"diffs": {".*\\.txt": {"ignore": true}}
```

The problem set’s `diff_base` setting chooses a default diff base for all files.

## Code execution

Peteramati can run student code in a Linux container and log and display the
results. This is controlled by the `runners` problem set setting, which is a
keyed object of run entries. A `default_runner` object supplies defaults for
every runner, and `runner_order` (an array of names) reorders them.

Code running requires significant additional configuration; see
[runners.md](runners.md) and [pajail.md](pajail.md).

### Run entries

A run entry is an object following this format.

* `name`: string (defaults to the key in `runners`)

    The internal name for the runner. This name is used in the database, so
    don’t change it or information will be lost. Every runner in a problem set
    must have a unique name. The name should consist only of letters, digits,
    and underscores, and must start with a letter.

* `title`: string

    The display title for the runner. Defaults to `name`.

* `display_title`: string

    The display title for the runner’s output. Defaults to “`title` output”.

* `disabled`: boolean

    If true, then the runner is hidden from users.

* `visible`: boolean, date, or `"grades"`

    If true, then the runner is displayed to, and executable by, students as
    well as teachers. If set to `"grades"`, then the runner is displayed to
    students iff grades are visible to students.

* `display_visible`: boolean, date, or `"grades"`

    If true, then the runner’s _output_ is displayed to students. This can be
    set even if the runner itself is not visible.

* `order`: number (alias `position`)

    Determines display order. Runners are sorted first by increasing `order`,
    and second by the order they appear in `psets.json`. Negative `order`
    entries appear first.

* `transfer_warnings`: boolean or `"grades"`

    If true, then search runner output for text that looks like compiler
    warnings, and display any such warnings in the problem set diffs. If
    `"grades"`, then students can see these warnings when grades are visible,
    even if they cannot see the runner output.

* `transfer_warnings_priority`: number

    Priority for transferred warnings.

* `xterm_js`: boolean

    If true, use `xterm.js` instead of peteramati’s built-in terminal.

* `rows`, `columns`, `font_size`: integer

    Terminal geometry for display.

* `timed_replay`: boolean or number; `timed_replay_start`: string

    Replay recorded output with timing. A number sets the replay speed.

### Run commands

A runner can optionally run code inside a container on the peteramati host.
This depends on the suid-root `pa-jail` program in the `jail` subdirectory; see
[pajail.md](pajail.md).

* `command`: string

    The command to run.

* `username`: string

    The username under which the command runs. Defaults to the problem set’s
    `run_username` key, or `jail61user` if that is not set.

* `overlay`: string, object, or array

    Tarball(s) to extract over student code—for example, to replace files with
    pristine versions or add grading code. An object form takes a `file` and an
    optional `exclude` list. Defaults to the problem set’s `run_overlay`.

* `timeout`: interval

    Timeout after which the container will shut down. Defaults to the problem
    set’s `run_timeout`, or 10 minutes. If ≤0, there is no timeout.

* `idle_timeout`: interval

    Timeout after which the container will shut down if idle (no input or
    output). Defaults to the problem set’s `run_idle_timeout`, or 3 minutes. If
    ≤0, there is no timeout.

* `queue`: string; `nconcurrent`: integer

    Optional run queue (defined in the top-level `_queues`) and a limit on the
    number of concurrent runners in it.

* `rerun_timestamp`: boolean or date

    Force reruns of cached output produced before this time.

The problem set also carries run defaults used when a runner does not set its
own: `run_username`, `run_overlay`, `run_timeout`, `run_idle_timeout`,
`run_dirpattern`, `run_skeletondir`, `run_binddir`, `run_jailfiles`,
`run_jailmanifest`, and `run_xterm_js`.

### Evaluation

A runner may post-process its output with PHP callbacks.

* `require`: string (alias `load`); `ensure`: string or array of strings

    PHP file(s) to load before evaluation.

* `evaluate_function`: string (alias `eval`)

    PHP callback that evaluates the run, e.g. to compute a grade.

* `display_function`: string (alias `output_function`)

    PHP callback that customizes how the run’s output is displayed.

## Renamed settings

Several settings have been renamed. The old names are now errors (unless the
`allowObsoleteConfig` option is set). The most common renames:

| current name              | old name                   |
|---------------------------|----------------------------|
| `weight`                  | `group_weight`             |
| `removed`                 | `ui_disabled`              |
| `visible`                 | `show_to_students`         |
| `scores_visible`          | `show_grades_to_students`  |
| `grade_statistics_visible`| `grade_cdf_visible`        |
| `frozen`                  | `freeze`                   |
| `handout_branch`          | `handout_repo_branch`      |
| `handout_hash`            | `handout_commit_hash`      |
| `repo_guess_patterns`     | `repo_transform_patterns`  |
| `deadline_college`        | `college_deadline`         |
| `deadline_extension`      | `extension_deadline`       |
| `display_title`           | `output_title` (runner)    |
| `display_visible`         | `output_visible` (runner)  |
| `collapse`                | `boring` (diff)            |
| `gradable`                | `gradeable` (diff)         |

