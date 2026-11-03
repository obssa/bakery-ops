<?php

declare(strict_types=1);

namespace App\Ops\Backup;

use App\Ops\OpsFailure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A disk's folder beside the dump (ADR 0020): every file of a local disk as a hard link in a folder of the night. BackupRunner takes
 * two: the private files disk (config files.disk, ADR 0015) into `.files`, and since step 6.1b the product photos' media disk (config
 * catalog.images.disk, ADR 0022) into `.media`. Both hold files written once and never changed (a receipt photo; a rendition named by
 * its sha256), so a link costs no space while the app keeps the file, and still keeps a file the app later purges (the orphan sweep, the
 * photo prune). A link that cannot be made (another file system) falls back to a copy, counted apart. A disk that is not local
 * (FILES_DISK=files_s3, or a bucket for the photos) has no folder to snapshot: the bucket keeps its own versions.
 */
final class FilesSnapshot
{
    /**
     * @return array{status: string, count: int, bytes: int, linked: int, copied: int}
     *
     * @throws OpsFailure backup.files_failed
     */
    public function take(string $target, string $disk): array
    {
        $root = (string) config("filesystems.disks.{$disk}.root");
        if ((string) config("filesystems.disks.{$disk}.driver") !== 'local' || $root === '') {
            return ['status' => 'not_local', 'count' => 0, 'bytes' => 0, 'linked' => 0, 'copied' => 0];
        }
        if (! @mkdir($target, 0700, true) && ! is_dir($target)) {
            throw OpsFailure::because('backup.files_failed');
        }
        $result = ['status' => 'ok', 'count' => 0, 'bytes' => 0, 'linked' => 0, 'copied' => 0];
        if (! is_dir($root)) {
            return $result;
        }

        $root = rtrim($root, '/');
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            if (! $entry instanceof SplFileInfo || $entry->isLink()) {
                continue;
            }
            $to = $target.substr($entry->getPathname(), strlen($root));
            if ($entry->isDir()) {
                if (! is_dir($to) && ! @mkdir($to, 0700, true) && ! is_dir($to)) {
                    throw OpsFailure::because('backup.files_failed');
                }

                continue;
            }
            if (@link($entry->getPathname(), $to)) {
                $result['linked']++;
            } elseif (@copy($entry->getPathname(), $to)) {
                $result['copied']++;
            } else {
                throw OpsFailure::because('backup.files_failed');
            }
            $result['count']++;
            $result['bytes'] += (int) $entry->getSize();
        }

        return $result;
    }
}
