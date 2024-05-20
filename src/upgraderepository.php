<?php
// upgraderepository.php -- Peteramati class for upgrading repositories
// Peteramati is Copyright (c) 2013-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class UpgradeRepository {
    /** @param string $cacheid
     * @return bool */
    static function upgrade_refs(Repository $repo, $cacheid) {
        $cwd = Repository::repodir_at($repo->conf, $cacheid);
        $sp = Repository::gitrun_subprocess($repo->conf,
            ["git", "remote", "rename", "repo{$repo->repoid}", "repo{$repo->repogid}"],
            $cwd);
        if (!$sp->ok) {
            $sp = Repository::gitrun_subprocess($repo->conf,
                ["git", "remote", "get-url", "repo{$repo->repoid}"],
                $cwd);
            return !$sp->ok;
        }

        preg_match_all('/^([0-9a-f]+) refs\/tags\/repo' . $repo->repoid . '\.(.*)$/m',
                       $repo->conf->repodir_refs($repo->cacheid),
                       $m, PREG_SET_ORDER);
        $deletes = [];
        foreach ($m as $mx) {
            $sp = Repository::gitrun_subprocess($repo->conf,
                ["git", "tag", "-f", "repo{$repo->repogid}.{$mx[2]}", $mx[1]],
                $cwd);
            if (!$sp->ok) {
                return false;
            }
            $deletes[] = "repo{$repo->repoid}.{$mx[2]}";
        }

        $n = count($deletes);
        for ($i = 0; $i !== $n; ) {
            $x = min(100, $n - $i);
            Repository::gitrun_subprocess($repo->conf,
                ["git", "tag", "-d", ...array_slice($deletes, $i, $x)],
                $cwd);
            $i += $x;
        }
        return true;
    }

    /** @return bool */
    static function upgrade_runs(Repository $repo) {
        $logroot = SiteLoader::$root . "/log";
        foreach (glob("{$logroot}/run{$repo->cacheid}.pset*") as $dir) {
            $have = "{$dir}/repo{$repo->repoid}";
            $want = "{$dir}/repo{$repo->repogid}";
            foreach (glob("{$have}.*") as $f) {
                rename($f, $want . substr($f, strlen($have)));
            }
        }
        return true;
    }
}
