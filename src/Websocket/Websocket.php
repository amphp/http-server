<?php

namespace Amp\Http\Server\Websocket;

use Amp\Http\Server\Request;
use Amp\Http\Server\Responder;
use Amp\Http\Server\Server;
use Amp\Http\Server\ServerObserver;
use Amp\Promise;

class Websocket implements Responder, ServerObserver {
    /** @var \Amp\Http\Server\Websocket\Internal\Rfc6455Gateway */
    private $gateway;

    /**
     * Creates a responder that accepts websocket connections.
     *
     * @param \Amp\Http\Server\Websocket\Application $application
     */
    public function __construct(Application $application) {
        $this->gateway = new Internal\Rfc6455Gateway($application);
    }

    /** {@inheritdoc} */
    public function respond(Request $request): Promise {
        return $this->gateway->respond($request);
    }

    /**
     * @param int $size The maximum size a single message may be in bytes. Default is 2097152 (2MB).
     *
     * @throws \Error If the size is less than 1.
     */
    public function setMessageSizeLimit(int $size) {
        $this->gateway->setOption("maxMessageSize", $size);
    }

    /**
     * @param int $bytes Maximum number of bytes per minute the endpoint can receive from the client.
     *     Default is 8388608 (8MB).
     *
     * @throws \Error If the number of bytes is less than 1.
     */
    public function setMaxBytesPerMinute(int $bytes) {
        $this->gateway->setOption("maxBytesPerMinute", $bytes);
    }

    /**
     * @param int $size The maximum size a single frame may be in bytes. Default is 2097152 (2MB).
     *
     * @throws \Error If the size is less than 1.
     */
    public function setMaxFrameSize(int $size) {
        $this->gateway->setOption("maxFrameSize", $size);
    }

    /**
     * @param int $count The maximum number of frames that can be received per second. Default is 100.
     *
     * @throws \Error If the count is less than 1.
     */
    public function setMaxFramesPerSecond(int $count) {
        $this->gateway->setOption("maxFramesPerSecond", $count);
    }

    /**
     * @param int $bytes The number of bytes in outgoing message that will cause the endpoint to break the message into
     *     multiple frames. Default is 65527 (64k - 9 for frame overhead).
     *
     * @throws \Error
     */
    public function setFrameSplitThreshold(int $bytes) {
        $this->gateway->setOption("autoFrameSize", $bytes);
    }

    /**
     * @param int $period The number of seconds a connection may be idle before a ping is sent to client. Default is 10.
     *
     * @throws \Error If the period is less than 1.
     */
    public function setHeartbeatPeriod(int $period) {
        $this->gateway->setOption("heartbeatPeriod", $period);
    }

    /**
     * @param int $period The number of seconds to wait after sending a close frame to wait for the client to send
     *     the acknowledging close frame before being disconnected. Default is 3.
     *
     * @throws \Error If the period is less than 1.
     */
    public function setClosePeriod(int $period) {
        $this->gateway->setOption("closePeriod", $period);
    }

    /**
     * @param int $limit The number of unanswered pings allowed before a client is disconnected. Default is 3.
     *
     * @throws \Error If the limit is less than 1.
     */
    public function setQueuedPingLimit(int $limit) {
        $this->gateway->setOption("queuedPingLimit", $limit);
    }

    /**
     * @param bool $validate True to validate text frame data as UTF-8, false to skip validation. Default is true.
     */
    public function validateUtf8(bool $validate) {
        $this->gateway->setOption("validateUtf8", $validate);
    }

    /**
     * @param bool $textOnly True to allow only text frames (no binary).
     */
    public function textOnly(bool $textOnly) {
        $this->gateway->setOption("textOnly", $textOnly);
    }

    /** {@inheritdoc} */
    public function onStart(Server $server): Promise {
        return $this->gateway->onStart($server);
    }

    /** {@inheritdoc} */
    public function onStop(Server $server): Promise {
        return $this->gateway->onStop($server);
    }
}
