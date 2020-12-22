<?php

use Phan\Config;

/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 *
 * @see src/Phan/Config.php
 * See Config for all configurable options.
 *
 * A Note About Paths
 * ==================
 *
 * Files referenced from this file should be defined as
 *
 * ```
 *   Config::projectPath('relative_path/to/file')
 * ```
 *
 * where the relative path is relative to the root of the
 * project which is defined as either the working directory
 * of the phan executable or a path passed in via the CLI
 * '-d' flag.
 */
return (function () {
$config = [
    // If true, missing properties will be created when
    // they are first seen. If false, we'll report an
    // error message.
    "allow_missing_properties" => false,

    // Allow null to be cast as any type and for any
    // type to be cast to null.
    "null_casts_as_any_type" => true,

    // Backwards Compatibility Checking
    "backward_compatibility_checks" => false,

    //"redundant_condition_detection" => true,
    //"dead_code_detection" => true,

    // Only emit critical issues to start with
    // (0 is low severity, 5 is normal severity, 10 is critical)
    "minimum_severity" => 0,

    // A list of directories that should be parsed for class and
    // method information. After excluding the directories
    // defined in exclude_analysis_directory_list, the remaining
    // files will be statically analyzed for errors.
    //
    // Thus, both first-party and third-party code being used by
    // your application should be included in this list.
    "directory_list" => [
        Config::projectPath("lib"),
        Config::projectPath("pages"),
        Config::projectPath("src"),
        Config::projectPath("batch"),
        Config::projectPath(".phan/stubs")
    ],

    "file_list" => [
        Config::projectPath("api.php"),
        Config::projectPath("authorize.php"),
        Config::projectPath("cacheable.php"),
        Config::projectPath("diff.php"),
        Config::projectPath("diffmany.php"),
        Config::projectPath("face.php"),
        Config::projectPath("index.php"),
        Config::projectPath("overview.php"),
        Config::projectPath("profile.php"),
        Config::projectPath("pset.php"),
        Config::projectPath("raw.php"),
        Config::projectPath("run.php")
    ],

    "exclude_file_list" => [
        Config::projectPath(".phan/config.php"),
        Config::projectPath("lib/collatorshim.php"),
        Config::projectPath("lib/polyfills.php"),
        Config::projectPath("lib/mailer.php"),
        Config::projectPath("mail.php"),
        Config::projectPath("resetpassword.php"),
        Config::projectPath("src/cs61mailer.php"),
        Config::projectPath("src/harvardseas_repositorysite.php")
    ],

    "globals_type_map" => [
        "Conf" => '\Conf',
        "Me" => '\Contact',
        "Qreq" => '\Qrequest'
    ],

    "suppress_issue_types" => [
        "PhanUnusedPublicMethodParameter",
        "PhanParamReqAfterOpt"
    ],

    "plugins" => [
        //".phan/plugins/RedundantDblResultPlugin.php"
    ]
];

if (file_exists(Config::projectPath(".phan/peteramati-config.php"))) {
    include(Config::projectPath(".phan/peteramati-config.php"));
}

return $config;
})();
