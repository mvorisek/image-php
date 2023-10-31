<?php

declare(strict_types=1);

function myexec(string $cmd): string
{
    exec($cmd, $lines, $resCode);
    if ($resCode !== 0) {
        throw new \Exception(
            'Non-zero exit' . "\n"
            . 'code: ' . $resCode . "\n"
            . 'cmd: ' . $cmd . "\n"
            . 'output:' . implode("\n", $lines) . "\n"
        );
    }

    return implode("\n", $lines);
}

class FileEntry
{
    /** @var int */
    public $size;
    /** @var int concat(device_id, inode_id, hash) */
    public $inode;
    /** @var int */
    public $hash;
}

$targetImageId = myexec('docker inspect --format="{{.Id}}" "' . $argv[1] . '"');

$baseImageId = null;
if (count($argv) > 2) {
    $baseImageId = myexec('docker inspect --format="{{.Id}}" "' . $argv[2] . '"');
}

$out = myexec('docker history --no-trunc --format "table {{.ID}}\t{{.CreatedBy}}" ' . $targetImageId);

$layers = [];
foreach (array_reverse(array_slice(explode("\n", $out), 1 /* first line is header */)) as $l) {
    [$lId, $lCmd] = preg_split('~\t| {2,}~', $l, 2);
    if ($lId === '<missing>') {
        continue;
    } elseif ($lId === $baseImageId) {
        $layers = [];
        $baseImageId = null;
    }

    $layers[] = [$lId, $lCmd];
}

// analyse filesystem diff between each layer
foreach (array_slice($layers, 1 /* skip one image for prev */, null, true) as $k => $lArr) {
    $lArrPrev = $layers[$k - 1];
    echo '--> analysing diff between:' . "\n";
    echo '--> ' . implode('  ', $lArrPrev) . "\n";
    echo '--> ' . implode('  ', $lArr) . "\n";

    $getImageFilesFx = function (string $imageId): array {
        $out = myexec('docker run "' . $imageId . '" /bin/sh -c \'find / -xdev -type f -exec du -b {} \; -exec stat -c %D_%i {} \; -exec sha256sum {} \;\'');
        $res = [];
        foreach (array_chunk(explode("\n", $out), 3) as [$l, $lDevInode, $lHash]) {
            [$size, $path] = preg_split('~\t| {2,}~', $l, 2);
            $hash = explode(' ', $lHash, 2)[0];
            $fileEntry = new FileEntry();
            $fileEntry->size = $size + 0;
            $fileEntry->inode = $lDevInode . '_' . $hash;
            $fileEntry->hash = $hash;
            $res[$path] = $fileEntry;
        }
        ksort($res);

        return $res;
    };
    $fsPrev = $getImageFilesFx($lArrPrev[0]);
    $fsCurr = $getImageFilesFx($lArr[0]);

    // calc dedup, prev must be always first
    $psByInode = [];
    $psByHash = [];
    foreach ($fsPrev as $p => $f) {
        $psByInode[$f->inode][] = $p;
        $psByHash[$f->hash][] = $p;
    }
    foreach ($fsCurr as $p => $f) {
        if (!isset($fsPrev[$p]) || $f->inode !== $fsPrev[$p]->inode) {
            $psByInode[$f->inode][] = $p;
        }
        if (!isset($fsPrev[$p]) || $f->hash !== $fsPrev[$p]->hash) {
            $psByHash[$f->hash][] = $p;
        }
    }

    // merge all seen paths and sort by largest size
    $pSorted = array_unique(array_merge(array_keys($fsPrev), array_keys($fsCurr)));
    usort($pSorted, function (string $pA, string $pB) use ($fsPrev, $fsCurr): int {
        $aSize = max(isset($fsCurr[$pA]) ? $fsCurr[$pA]->size : 0, isset($fsPrev[$pA]) ? $fsPrev[$pA]->size : 0);
        $bSize = max(isset($fsCurr[$pB]) ? $fsCurr[$pB]->size : 0, isset($fsPrev[$pB]) ? $fsPrev[$pB]->size : 0);

        return $bSize <=> $aSize;
    });

    // show removed/added files
    foreach ($pSorted as $p) {
        if (isset($fsPrev[$p]) && isset($fsCurr[$p]) && $fsPrev[$p]->inode === $fsCurr[$p]->inode
            || in_array($p, ['/.dockerenv', '/etc/hostname', '/etc/hosts', '/etc/resolv.conf'], true)) {
            continue;
        }

        foreach ([1 => $fsPrev, 0 => $fsCurr] as $isPrev => $fs) {
            if (!isset($fs[$p])) {
                continue;
            }
            $f = $fs[$p];

            echo $isPrev ? '-' : '+';
            if ($p !== reset($psByInode[$f->inode])) {
                echo '(deduped by inode from ' . reset($psByInode[$f->inode]) . ')';
            } else {
                if ($f->size !== 0 && $p !== reset($psByHash[$f->hash])) {
                    echo '(deduped by hash from ' . reset($psByHash[$f->hash]) . ')';
                }
                echo number_format($f->size / 1000, 3, '.', ' ') . 'K';
            }
            echo ' ' . $p . "\n";
        }
    }

    echo "\n\n";
}
