<?php

namespace Aerys\Responders\Websocket;

class FrameStreamResource extends FrameStream {

    private $resource;
    private $keyCache;
    private $currentCache;

    protected function setDataSource($dataSource) {
        $this->resource = $dataSource;
    }

    function count() {
        $currentPos = $this->key();
        $this->seek(0, SEEK_END);
        $endPos = $this->key();
        $this->seek($currentPos);

        return $endPos;
    }

    function seek($position, $whence = SEEK_SET) {
        if (@fseek($this->resource, $position, $whence)) {
            throw new FrameStreamException(
                'Failed seeking on frame resource'
            );
        } elseif (FALSE === ($this->keyCache = ftell($this->resource))) {
            throw new FrameStreamException(
                'Failed stat on frame resource'
            );
        } else {
            $this->currentCache = NULL;
        }
    }

    function rewind() {
        if (!@rewind($this->resource)) {
            throw new FrameStreamException(
                'Failed seeking on frame resource'
            );
        }
    }

    function valid() {
        return !@feof($this->resource);
    }

    function key() {
        if (isset($this->keyCache)) {
            return $this->keyCache;
        } elseif (FALSE !== ($this->keyCache = @ftell($this->resource))) {
            return $this->keyCache;
        } else {
            throw new FrameStreamException(
                'Failed stat check on frame resource'
            );
        }
    }

    function current() {
        if (isset($this->currentCache)) {
            return $this->currentCache;
        }

        $this->currentCache = $this->frameSize
            ? @fread($this->resource, $this->frameSize)
            : @stream_get_contents($this->resource);

        if (FALSE !== $this->currentCache) {
            return $this->currentCache;
        } else {
            $this->currentCache = NULL;
            throw new FrameStreamException(
                'Failed reading from frame resource'
            );
        }
    }

    function next() {
        $this->currentCache = NULL;
        $this->keyCache = NULL;
    }

}
