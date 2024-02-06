<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Http\StructuredFields\Boolean;
use Amp\Http\StructuredFields\Number;
use Amp\Http\StructuredFields\Rfc8941;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Quic\QuicConnection;
use Amp\Quic\QuicSocket;
use Amp\Socket\PendingReceiveError;
use Revolt\EventLoop;

class Http3Parser
{
    private ?QuicSocket $qpackDecodeStream = null;
    private ?QuicSocket $qpackEncodeStream = null;
    private Queue $queue;
    /** @var array<int, EventLoop\Suspension> */
    private array $datagramReceivers = [];
    /** @psalm-suppress PropertyNotSetInConstructor */
    private DeferredCancellation $datagramReceiveEmpty;
    /** @var array<int, true> */
    private array $datagramCloseHandlerInstalled = [];

    public static function decodeVarint(string $string, int &$off): int
    {
        if (!isset($string[$off])) {
            return -1;
        }

        $int = \ord($string[$off++]);
        switch ($int & 0xC0) {
            case 0x00:
                return $int;
            case 0x40:
                if (\strlen($string) < $off + 1) {
                    --$off;
                    return -1;
                }
                return ($int << 8) + \ord($string[$off++]);
            case 0x80:
                if (\strlen($string) < $off + 3) {
                    --$off;
                    return -1;
                }
                return ($int << 24) + (\ord($string[$off++]) << 16) + (\ord($string[$off++]) << 8) + \ord($string[$off++]);
            default:
                if (\strlen($string) < $off-- + 7) {
                    return -1;
                }
                $int = \unpack("J", $string, $off)[1] & 0x3FFFFFFFFFFFFFFF;
                $off += 8;
                return $int;
        }
    }

    public static function decodeVarintFromStream(ReadableStream $stream, string &$buf, int &$off): int
    {
        while (-1 === $int = self::decodeVarint($buf, $off)) {
            if (null === $chunk = $stream->read()) {
                return -1;
            }
            $buf .= $chunk;
        }
        /** @psalm-suppress PossiblyUndefinedVariable https://github.com/vimeo/psalm/issues/10548 */
        return $int;
    }

    /** @param positive-int $headerSizeLimit */
    public function __construct(private QuicConnection $connection, private int $headerSizeLimit, private QPack $qpack)
    {
        $this->queue = new Queue;
    }

    public static function decodeFrameTypeFromStream(QuicSocket $stream, string &$buf, int &$off): ?Http3Frame
    {
        $frametype = self::decodeVarintFromStream($stream, $buf, $off);
        $maxPadding = 0x1000;
        while (null === $frame = Http3Frame::tryFrom($frametype)) {
            // RFC 9114 Section 9 explicitly requires all known frames to be skipped
            if ($frametype >= 0 && $frametype <= 0x09) {
                throw new Http3ConnectionException("Encountered reserved frame type $frametype", Http3Error::H3_FRAME_UNEXPECTED);
            }
            $length = self::decodeVarintFromStream($stream, $buf, $off);
            if ($length === -1) {
                return null;
            }
            if ($length > $maxPadding) {
                throw new Http3ConnectionException("An excessively large unknown frame of type $frametype was received", Http3Error::H3_EXCESSIVE_LOAD);
            }
            $maxPadding -= $length;
            $off += $length;
            $frametype = self::decodeVarintFromStream($stream, $buf, $off);
        }
        /** @psalm-suppress PossiblyUndefinedVariable https://github.com/vimeo/psalm/issues/10548 */
        return $frame;
    }

    /**
     * @param positive-int $maxSize
     * @return list{Http3Frame, string}|null
     */
    public static function readFullFrame(QuicSocket $stream, string &$buf, int &$off, int $maxSize): ?array
    {
        if (null === $type = self::decodeFrameTypeFromStream($stream, $buf, $off)) {
            return null;
        }
        if (null === $frame = self::readFrameWithoutType($stream, $buf, $off, $maxSize)) {
            return null;
        }
        return [$type, $frame];
    }

    /** @param positive-int $maxSize */
    public static function readFrameWithoutType(QuicSocket $stream, string &$buf, int &$off, int $maxSize): ?string
    {
        $length = self::decodeVarintFromStream($stream, $buf, $off);
        if ($length < 0) {
            return null;
        }
        if ($length > $maxSize) {
            throw new Http3ConnectionException("An excessively large message was received", Http3Error::H3_FRAME_ERROR);
        }
        if (\strlen($buf) >= $off + $length) {
            $frame = \substr($buf, $off, $length);
            $off += $length;
            return $frame;
        }

        $buf = \substr($buf, $off);
        $off = 0;
        while (\strlen($buf) < $length) {
            if (null === $chunk = $stream->read()) {
                if (!$stream->wasReset()) {
                    throw new Http3ConnectionException("Received an incomplete frame", Http3Error::H3_FRAME_ERROR);
                }
                return null;
            }
            $buf .= $chunk;
        }
        $off = $length;
        return \substr($buf, 0, $length);
    }

    private function parseSettings(string $contents): void
    {
        $off = 0;
        $settings = [];
        while ((-1 !== $key = self::decodeVarint($contents, $off)) && (-1 !== $value = self::decodeVarint($contents, $off))) {
            $settings[$key] = $value;
        }
        $this->queue->push([Http3Frame::SETTINGS, $settings]);
    }

    // Function to be used by a client
    public function awaitHttpResponse(QuicSocket $stream): \Generator
    {
        $off = 0;
        $buf = "";
        return $this->readHttpMessage($stream, $buf, $off);
    }

    private function parsePushPromise(string $contents): array
    {
        $pushOff = 0;
        $pushId = self::decodeVarint($contents, $pushOff);
        return [$pushId, self::processHeaders($this->qpack->decode($contents, $pushOff))];
    }

    private function readHttpMessage(QuicSocket $stream, string &$buf, int &$off): \Generator
    {
        while (true) {
            if (![$frame, $contents] = self::readFullFrame($stream, $buf, $off, $this->headerSizeLimit)) {
                return;
            }
            if ($frame === Http3Frame::PUSH_PROMISE) {
                /** @psalm-suppress PossiblyNullArgument https://github.com/vimeo/psalm/issues/10668 */
                yield Http3Frame::PUSH_PROMISE => $this->parsePushPromise($contents);
            } else {
                break;
            }
        }
        if ($frame !== Http3Frame::HEADERS) {
            throw new Http3ConnectionException("A request or response stream may not start with any other frame than HEADERS", Http3Error::H3_FRAME_UNEXPECTED);
        }
        $headerOff = 0;
        /** @psalm-suppress PossiblyNullArgument https://github.com/vimeo/psalm/issues/10668 */
        yield Http3Frame::HEADERS => self::processHeaders($this->qpack->decode($contents, $headerOff));
        $hadData = false;
        while (null !== $type = self::decodeFrameTypeFromStream($stream, $buf, $off)) {
            switch ($type) {
                // At most one trailing header
                case Http3Frame::HEADERS:
                    if (!$headers = self::readFrameWithoutType($stream, $buf, $off, $this->headerSizeLimit)) {
                        return;
                    }
                    $headerOff = 0;
                    yield Http3Frame::HEADERS => self::processHeaders($this->qpack->decode($headers, $headerOff));
                    if ($hadData) {
                        break 2;
                    }
                    break;

                case Http3Frame::DATA:
                    $hadData = true;
                    $length = self::decodeVarintFromStream($stream, $buf, $off);

                    if ($length <= \strlen($buf) - $off) {
                        yield Http3Frame::DATA => \substr($buf, $off, $length);
                        $off += $length;
                    } else {
                        if (\strlen($buf) > $off) {
                            yield Http3Frame::DATA => \substr($buf, $off);
                        }
                        $length -= \strlen($buf) - $off;
                        $buf = "";
                        $off = 0;
                        while (true) {
                            if (null === $chunk = $stream->read()) {
                                if ($stream->wasReset()) {
                                    yield null => null;
                                } else {
                                    throw new Http3ConnectionException("Received an incomplete data frame", Http3Error::H3_FRAME_ERROR);
                                }
                                return;
                            }
                            if (\strlen($chunk) < $length) {
                                yield Http3Frame::DATA => $chunk;
                                $length -= \strlen($chunk);
                            } else {
                                yield Http3Frame::DATA => \substr($chunk, 0, $length);
                                $buf = $chunk;
                                $off = $length;
                                break;
                            }
                        }
                    }
                    break;

                case Http3Frame::PUSH_PROMISE:
                    if (!$headers = self::readFrameWithoutType($stream, $buf, $off, $this->headerSizeLimit)) {
                        return;
                    }
                    yield Http3Frame::PUSH_PROMISE => $this->parsePushPromise($headers);
                    break;

                default:
                    throw new Http3ConnectionException("Found unexpected frame {$type->name} on message frame", Http3Error::H3_FRAME_UNEXPECTED);
            }
        }
        if ($stream->wasReset()) {
            yield null => null;
        }
        while ([$frame, $contents] = self::readFullFrame($stream, $buf, $off, $this->headerSizeLimit)) {
            if ($frame === Http3Frame::PUSH_PROMISE) {
                /** @psalm-suppress PossiblyNullArgument https://github.com/vimeo/psalm/issues/10668 */
                yield Http3Frame::PUSH_PROMISE => $this->parsePushPromise($contents);
            } else {
                /** @psalm-suppress PossiblyNullPropertyFetch https://github.com/vimeo/psalm/issues/10668 */
                throw new Http3ConnectionException("Expecting only push promises after a message frame, found {$frame->name}", Http3Error::H3_FRAME_UNEXPECTED);
            }
        }
    }

    // TODO extracted from Http2Parser::parseHeaderBuffer. Same rules, make common method?
    private static function processHeaders(array $decoded): ?array
    {
        $headers = [];
        $pseudo = [];

        foreach ($decoded as [$name, $value]) {
            if (!\preg_match('/^[\x21-\x40\x5b-\x7e]+$/'/* Http2Parser::HEADER_NAME_REGEX */, $name)) {
                return null;
            }

            if ($name[0] === ':') {
                if (!empty($headers)) {
                    return null;
                }

                if (isset($pseudo[$name])) {
                    return null;
                }

                $pseudo[$name] = $value;
                continue;
            }

            $headers[$name][] = $value;
        }

        return [$headers, $pseudo];
    }

    // I'm unable to suppress https://github.com/vimeo/psalm/issues/10669
    /* @return ConcurrentIterator<list{Http3Frame::HEADERS, QuicSocket, \Generator}|list{Http3Frame::GOAWAY|Http3Frame::MAX_PUSH_ID|Http3Frame::CANCEL_PUSH, int}|list{Http3Frame::PRIORITY_UPDATE_Push|Http3Frame::PRIORITY_UPDATE_Request, int, string}|list{Http3Frame::PUSH_PROMISE, int, callable(): \Generator}|list{int, string, QuicSocket}> */
    public function process(): ConcurrentIterator
    {
        EventLoop::queue(function () {
            while ($stream = $this->connection->accept()) {
                EventLoop::queue(function () use ($stream) {
                    try {
                        $off = 0;
                        $buf = $stream->read();
                        if ($buf === null) {
                            return; // Nothing happens. That's allowed. Just bye then.
                        }

                        $type = self::decodeVarintFromStream($stream, $buf, $off);
                        if ($stream->isWritable()) {
                            // client-initiated bidirectional stream
                            if ($type > 0x0d /* bigger than any default frame */ && $type % 0x1f !== 0x2 /* and not a padding frame */) {
                                // Unknown frame type. Users may handle it (e.g. WebTransport).
                                $this->queue->push([$type, \substr($buf, $off), $stream]);
                                return;
                            }
                            $off = 0;
                            $messageGenerator = $this->readHttpMessage($stream, $buf, $off);
                            if (!$messageGenerator->valid()) {
                                return; // Nothing happens. That's allowed. Just bye then.
                            }
                            if ($messageGenerator->key() !== Http3Frame::HEADERS) {
                                throw new Http3ConnectionException("Bi-directional message streams must start with a HEADERS frame", Http3Error::H3_FRAME_UNEXPECTED);
                            }
                            $this->queue->push([Http3Frame::HEADERS, $stream, $messageGenerator]);
                        } else {
                            // unidirectional stream
                            switch (Http3StreamType::tryFrom($type)) {
                                case Http3StreamType::Control:
                                    if (![$frame, $contents] = $this->readFullFrame($stream, $buf, $off, 0x1000)) {
                                        if (!$stream->getConnection()->isClosed()) {
                                            throw new Http3ConnectionException("The control stream was closed", Http3Error::H3_CLOSED_CRITICAL_STREAM);
                                        }
                                        return;
                                    }
                                    if ($frame !== Http3Frame::SETTINGS) {
                                        throw new Http3ConnectionException("A settings frame must be the first frame on the control stream", Http3Error::H3_MISSING_SETTINGS);
                                    }
                                    /** @psalm-suppress PossiblyNullArgument https://github.com/vimeo/psalm/issues/10668 */
                                    $this->parseSettings($contents);

                                    while (true) {
                                        if (![$frame, $contents] = $this->readFullFrame($stream, $buf, $off, 0x100)) {
                                            if (!$stream->getConnection()->isClosed()) {
                                                throw new Http3ConnectionException("The control stream was closed", Http3Error::H3_CLOSED_CRITICAL_STREAM);
                                            }
                                            return;
                                        }

                                        if ($frame !== Http3Frame::GOAWAY && $frame !== Http3Frame::MAX_PUSH_ID && $frame !== Http3Frame::CANCEL_PUSH && $frame !== Http3Frame::PRIORITY_UPDATE_Request && $frame !== Http3Frame::PRIORITY_UPDATE_Push) {
                                            throw new Http3ConnectionException("An unexpected frame was received on the control stream", Http3Error::H3_FRAME_UNEXPECTED);
                                        }

                                        $tmpOff = 0;
                                        /** @psalm-suppress PossiblyNullArgument https://github.com/vimeo/psalm/issues/10668 */
                                        if (0 > $id = self::decodeVarint($contents, $tmpOff)) {
                                            if (!$stream->getConnection()->isClosed()) {
                                                throw new Http3ConnectionException("The control stream was closed", Http3Error::H3_CLOSED_CRITICAL_STREAM);
                                            }
                                            return;
                                        }
                                        if ($frame === Http3Frame::PRIORITY_UPDATE_Request || $frame === Http3Frame::PRIORITY_UPDATE_Push) {
                                            $this->queue->push([$frame, $id, \substr($contents, $tmpOff)]);
                                        } else {
                                            $this->queue->push([$frame, $id]);
                                        }
                                    }

                                    // no break
                                case Http3StreamType::Push:
                                    $pushId = self::decodeVarintFromStream($stream, $buf, $off);
                                    if ($pushId < 0) {
                                        if (!$stream->wasReset()) {
                                            throw new Http3ConnectionException("The push stream was closed too early", Http3Error::H3_FRAME_ERROR);
                                        }
                                    }
                                    $this->queue->push([Http3Frame::PUSH_PROMISE, $pushId, fn () => $this->readHttpMessage($stream, $buf, $off)]);
                                    break;

                                    // We don't do anything with these streams yet, but we must not close them according to RFC 9204 Section 4.2
                                case Http3StreamType::QPackEncode:
                                    if ($this->qpackEncodeStream) {
                                        return;
                                    }
                                    $this->qpackEncodeStream = $stream;
                                    break;

                                case Http3StreamType::QPackDecode:
                                    if ($this->qpackDecodeStream) {
                                        return;
                                    }
                                    $this->qpackDecodeStream = $stream;
                                    break;

                                default:
                                    // Unknown stream type. Users may handle it (e.g. WebTransport).
                                    $this->queue->push([$type, \substr($buf, $off), $stream]);
                                    return;
                            }
                        }
                    } catch (Http3ConnectionException $e) {
                        $this->abort($e);
                    }
                });
            }
            if (!$this->queue->isComplete()) {
                $this->queue->complete();
            }
        });

        return $this->queue->iterate();
    }

    // Note: format is shared with HTTP/2
    /** @return list{int<0, 7>, bool}|null */
    public static function parsePriority(array|string $headers): ?array
    {
        $urgency = 3;
        $incremental = false;
        if ($priority = Rfc8941::parseDictionary($headers)) {
            if (isset($priority["u"])) {
                $number = $priority["u"];
                if ($number instanceof Number) {
                    $value = $number->item;
                    if (\is_int($value) && $value >= 0 && $value <= 7) {
                        $urgency = $value;
                    }
                }
            }
            if (isset($priority["i"])) {
                $bool = $priority["i"];
                if ($bool instanceof Boolean) {
                    $incremental = $bool->item;
                }
            }
        }
        return [$urgency, $incremental];
    }

    public function abort(Http3ConnectionException $exception): void
    {
        if (!$this->queue->isComplete()) {
            $this->connection->close($exception->getCode(), $exception->getMessage());
            $this->queue->error($exception);
        }
    }

    private function datagramReceiver(): void
    {
        $this->datagramReceiveEmpty = new DeferredCancellation;
        $cancellation = $this->datagramReceiveEmpty->getCancellation();
        EventLoop::queue(function () use ($cancellation) {
            while (null !== $buf = $this->connection->receive($cancellation)) {
                $off = 0;
                $quarterStreamId = self::decodeVarint($buf, $off);
                if (isset($this->datagramReceivers[$quarterStreamId])) {
                    $this->datagramReceivers[$quarterStreamId]->resume(\substr($buf, $off));
                    unset($this->datagramReceivers[$quarterStreamId]);

                    if (!$this->datagramReceivers) {
                        return;
                    }

                    // We need to await a tick to allow datagram receivers to request a new datagram to avoid needlessly discarding datagram frames
                    $suspension = EventLoop::getSuspension();
                    EventLoop::queue($suspension->resume(...));
                    $suspension->suspend();
                }
            }
        });
    }

    public function receiveDatagram(QuicSocket $stream, ?Cancellation $cancellation = null): ?string
    {
        $quarterStreamId = $stream->getId() >> 2;
        if (isset($this->datagramReceivers[$quarterStreamId])) {
            throw new PendingReceiveError;
        }

        if (!$stream->isReadable()) {
            return null;
        }

        if (!isset($this->datagramCloseHandlerInstalled[$quarterStreamId])) {
            $this->datagramCloseHandlerInstalled[$quarterStreamId] = true;
            $stream->onClose(function () use ($quarterStreamId) {
                $this->datagramReceivers[$quarterStreamId]->resume();
                unset($this->datagramReceivers[$quarterStreamId], $this->datagramCloseHandlerInstalled[$quarterStreamId]);
                if (!$this->datagramReceivers) {
                    $this->datagramReceiveEmpty->cancel();
                }
            });
        }

        if (!$this->datagramReceivers) {
            $this->datagramReceiver();
        }

        $suspension = EventLoop::getSuspension();
        $this->datagramReceivers[$quarterStreamId] = $suspension;

        $cancellationId = $cancellation?->subscribe(function ($e) use ($suspension, $quarterStreamId) {
            unset($this->datagramReceivers[$quarterStreamId]);
            if (!$this->datagramReceivers) {
                $this->datagramReceiveEmpty->cancel($e);
            }
            $suspension->throw($e);
        });

        try {
            return $suspension->suspend();
        } finally {
            /** @psalm-suppress PossiblyNullArgument https://github.com/vimeo/psalm/issues/10553 */
            $cancellation?->unsubscribe($cancellationId);
        }
    }
}
