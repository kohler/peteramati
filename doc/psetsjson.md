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
Peteramati will read and merge all matching files.

If Peteramati cannot load a required `psets.json` file—either because the file
doesn't exist or is unreadable, or because of a JSON parsing error—it will
refuse to initialize.

Format
------

A `psets.json` configuration is a JSON object containing *problem sets* amd
other configuration information.

Problem sets
------------

`_defaults` XXX

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
    defaults to the problem set key, but may differ; for example, perhaps the
    problem set key is `pset1`, but you prefer the shorter `1` for URIs, since
    `peteramati/pset/pset1` looks redundant.

* `title`: string

    Human-friendly problem set name. This is the name displayed in the
    interface. Defaults to the problem set's JSON key.

