<?php

namespace Aerys\Root;

use Amp\{ Promise, Success, Failure };
use Aerys\Response;

final class BlockingRoot extends Root {
    final protected function stat(string $path): \Generator {
        $stat = new Stat;
        $stat->exists = false;
        $stat->path = $path;

        if (!file_exists($path)) {
            return $stat;
        }

        // yield after blocking IO to give other code a chance to execute
        yield;

        if (is_dir($path)) {
            if ($indexPathArr = yield from $this->coalesceIndex($path)) {
                list($path, $info) = $indexPathArr;
            } else {
                return $stat;
            }
        } else {
            $info = stat($path);
        }

        clearstatcache(true, $path);

        $stat->exists = true;

        $stat->path = $path;
        $stat->size = $info[7];
        $stat->mtime = $info[9];
        $stat->inode = $info[1];
        $inode = $this->useEtagInode ? $stat->inode : "";
        $stat->etag = md5("{$stat->path}{$stat->mtime}{$stat->size}{$inode}");

        if (!$this->shouldBufferContent($stat)) {
            clearstatcache(true, $path);
            return $stat;
        }

        $buffer = @file_get_contents($path);
        clearstatcache(true, $path);
        if ($buffer === false) {
            throw new \RuntimeException(
                "Failed buffering file: {$path}"
            );
        }

        // yield after blocking IO to give other code a chance to execute
        yield;

        $this->bufferedFileCount++;
        $stat->buffer = $buffer;

        return $stat;
    }

    private function coalesceIndex(string $dirPath): \Generator {
        $dirPath = rtrim($dirPath, "/") . "/";
        foreach ($this->indexes as $indexFile) {
            $coalescedPath = $dirPath . $indexFile;
            $isIndexMatch = is_file($coalescedPath);

            // yield after blocking IO to give other code a chance to execute
            yield;

            if (!$isIndexMatch) {
                clearstatcache(true, $coalescedPath);
                continue;
            }

            return [$coalescedPath, stat($coalescedPath)];
        }
    }

    /**
     * It's safe to throw here because this function is resolved as
     * a coroutine by the server -- throwing leads to the appropriate
     * 500 response if output hasn't started. If output has started the
     * error is logged appropriately and output is discontinued.
     */
    final protected function respond(Response $response, Stat $stat, Range $range = null): \Generator {
        if (!$handle = @fopen($stat->path, "r")) {
            throw new \RuntimeException(
                "Failed opening file handle"
            );
        }

        // yield after blocking IO to give other code a chance to execute
        yield;

        if (empty($range)) {
            yield from $this->doNonRange($handle, $response);
        } elseif (empty($range->ranges[1])) {
            list($startPos, $endPos) = $range->ranges[0];
            yield from $this->doSingleRange($handle, $response, $startPos, $endPos);
        } else {
            yield from $this->doMultiRange($handle, $response, $stat, $range);
        }
    }

    private function doNonRange($handle, Response $response): \Generator {
        while (!@feof($handle)) {
            if (($chunk = @fread($handle, 8192)) === false) {
                throw new \RuntimeException(
                    "Failed reading from open file handle"
                );
            }
            $response->stream($chunk);

            // yield after blocking IO to give other code a chance to execute
            yield;
        }
    }

    private function doSingleRange($handle, Response $response, int $startPos, int $endPos): \Generator {
        $bytesRemaining = $endPos - $startPos;
        while ($bytesRemaining) {
            $toBuffer = ($bytesRemaining > 8192) ? 8192 : $bytesRemaining;
            if (($chunk = @fread($handle, $toBuffer)) === false) {
                throw new \RuntimeException(
                    "Failed reading from open file handle"
                );
            }
            $response->stream($chunk);

            // yield after blocking IO to give other code a chance to execute
            yield;
        }
    }

    private function doMultiRange($handle, Response $response, Stat $stat, Range $range): \Generator {
        foreach ($range->ranges as list($startPos, $endPos)) {
            $header = sprintf(
                "--%s\r\nContent-Type: %s\r\nContent-Range: bytes %d-%d/%d\r\n\r\n",
                $range->boundary,
                $range->contentType,
                $startPos,
                $endPos,
                $stat->size
            );
            $response->stream($header);
            yield from $this->doSingleRange($handle, $response, $startPos, $endPos);
            $response->stream("\r\n");
        }
        $response->stream("--{$range->boundary}--");
    }
}
