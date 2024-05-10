<?php
// dbqsession.php -- HotCRP fake session handler interface
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.
/** @phan-file-suppress PhanParamSignatureRealMismatchHasNoParamTypeInternal */

class FakeSessionHandler
    implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface {
    #[\ReturnTypeWillChange]
    function open($path, $sname) {
        return true;
    }

    #[\ReturnTypeWillChange]
    function close() {
        return true;
    }

    #[\ReturnTypeWillChange]
    function create_sid() {
        return "fake";
    }

    #[\ReturnTypeWillChange]
    function validateId($sid) {
        return true;
    }

    #[\ReturnTypeWillChange]
    function read($sid) {
        return "";
    }

    #[\ReturnTypeWillChange]
    function destroy($sid) {
        return true;
    }

    #[\ReturnTypeWillChange]
    function write($sid, $sdata) {
        return true;
    }

    #[\ReturnTypeWillChange]
    function updateTimestamp($sid, $sdata) {
        return $this->write($sid, $sdata);
    }

    #[\ReturnTypeWillChange]
    function gc($maxlifetime) {
        //error_log("gc");
        $now = time();
        $n = 0;
        $sdir = ini_get("session.save_path");
        if (!$sdir) {
            $sdir = sys_get_temp_dir();
        }
        foreach (glob("{$sdir}/sess_*") as $path) {
            if (file_exists($path)) {
                if (filemtime($path) + $maxlifetime < $now) {
                    unlink($path);
                } else {
                    clearstatcache(true, $path);
                }
            }
        }
        /** @phan-suppress-next-line PhanTypeMismatchReturn */
        return $n;
    }
}
