<?php
// runenqueue.php -- Peteramati script for adding to the execution queue
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    require_once(__DIR__ . "/runqueue.php");
    exit(RunQueue_Batch::make_args(Conf::$main, $argv)->run());
} else {
    require_once(__DIR__ . "/runqueue.php");
}
