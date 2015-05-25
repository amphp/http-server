<?php

namespace Aerys\Root;

use Amp\{ Promise, Deferred, UvReactor };
use Aerys\Response;

final class UvRoot extends Root {
    private $loop;

    public function __construct(string $root, UvReactor $reactor) {
        parent::__construct($root, $reactor);
        $this->loop = $reactor->getUnderlyingLoop();
    }

    private function noop() {}

    final protected function stat(string $path): \Generator {
        $stat = new UvStat;
        $stat->exists = false;

        if (!$info = yield $this->getFileInfo($path)) {
            return $stat;
        }

        if ($info["mode"] & \UV::S_IFDIR) {
            if ($indexPathArr = yield from $this->coalesceIndex($path)) {
                list($path, $info) = $indexPathArr;
            } else {
                return $stat;
            }
        }

        $stat->exists = true;
        $stat->path = $path;
        $stat->size = $info["size"];
        $stat->mtime = $info["mtime"];
        $stat->inode = $info["ino"];
        $inode = $this->useEtagInode ? $stat->inode : "";
        $stat->etag = md5("{$stat->path}{$stat->mtime}{$stat->size}{$inode}");

        // We need these specifically for our UvStat instance
        $stat->handle = $info["handle"];
        $stat->loop = $this->loop;

        if ($this->shouldBufferContent($stat)) {
            $stat->buffer = yield $this->buffer($stat->handle, $info["size"]);
            $this->bufferedFileCount += isset($stat->buffer);
        }

        return $stat;
    }

    private function getFileInfo(string $path): Promise {
        $promisor = new Deferred;
        uv_fs_open($this->loop, $path, \UV::O_RDONLY, 0, function($fh) use ($promisor) {
            if ($fh === -1 || $fh === false) {
                // file does not exist
                $promisor->succeed(null);
                return;
            }

            uv_fs_fstat($this->loop, $fh, function($r, $info) use ($fh, $promisor) {
                if ($info) {
                    $info["handle"] = $fh;
                    $promisor->succeed($info);
                } else {
                    uv_fs_close($this->loop, $fh, [$this, "noop"]);
                    $promisor->fail(new \RuntimeException(
                        "File stat failed"
                    ));
                }
            });
        });

        return $promisor->promise();
    }

    private function coalesceIndex(string $dirPath): \Generator {
        $dirPath = rtrim($dirPath, "/") . "/";
        foreach ($this->indexes as $indexPath) {
            $coalescedPath = $dirPath . $indexPath;
            if (!$info = yield $this->getFileInfo($coalescedPath)) {
                continue;
            }
            if ($info["mode"] & \UV::S_IFDIR) {
                uv_fs_close($this->loop, $info["handle"], [$this, "noop"]);
                continue;
            }
            return [$coalescedPath, $info];
        }
    }

    private function buffer($fh, int $size): Promise {
        $promisor = new Deferred;

        uv_fs_read($this->loop, $fh, 0, $size, function($fh, $nread, $buffer) use ($promisor, $size) {
            $result = ($nread === $size) ? $buffer : null;
            $promisor->succeed($result);
        });

        return $promisor->promise();
    }

    /**
     * It's safe to throw here because this function is resolved as
     * a coroutine by the server -- throwing leads to the appropriate
     * 500 response if output hasn't started. If output has started the
     * error is logged appropriately and output is discontinued.
     */
    final protected function respond(Response $response, Stat $stat, Range $range = null): \Generator {
        if (empty($range)) {
            yield from $this->stream($response, $stat->handle, 0, $stat->size);
        } elseif (empty($range->ranges[1])) {
            list($startPos, $endPos) = $range->ranges[0];
            yield from $this->stream($response, $stat->handle, $startPos, ($endPos - $startPos));
        } else {
            yield from $this->streamMulti($stat, $response, $range);
        }
    }

    private function stream(Response $response, $fh, int $pos, int $len): \Generator {
        do {
            $len = ($len > 32768) ? 32768 : $len;
            $chunk = yield $this->readChunk($fh, $pos, $len);
            $pos += $len;
            $response->stream($chunk);
        } while ($pos < $len);

        $response->end();
    }

    private function readChunk($fh, int $pos, int $len): Promise {
        $promisor = new Deferred;
        uv_fs_read($this->loop, $fh, $pos, $len, function($fh, $nread, $buffer) use ($promisor) {
            if ($nread < 0) {
                $promisor->fail(new \RuntimeException(
                    "Failed reading from open file handle"
                ));
            } else {
                $promisor->succeed($buffer);
            }
        });

        return $promisor->promise();
    }

    private function streamMulti(UvStat $stat, Response $response, Range $range): \Generator {
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
            yield from $this->stream($response, $stat->handle, $startPos, $endPos);
            $response->stream("\r\n");
        }
        $response->stream("--{$range->boundary}--");
    }
}
