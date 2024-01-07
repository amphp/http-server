<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

use Amp\Http\Http2\Http2Parser;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Quic\QuicConnection;
use Amp\Quic\QuicSocket;
use Revolt\EventLoop;

class Http3Parser
{
    private ?QuicSocket $qpackDecodeStream = null;
    private ?QuicSocket $qpackEncodeStream = null;
    private Queue $queue;

    private static function decodeVarint(string $string, int &$off): int
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
                if (\strlen($string) < --$off + 7) {
                    return -1;
                }
                $int = \unpack("J", $string, $off)[1] & 0x3FFFFFFFFFFFFFFF;
                $off += 8;
                return $int;
        }
    }

    private static function decodeVarintFromStream(QuicSocket $stream, string &$buf, int &$off): int
    {
        while (-1 === $int = self::decodeVarint($buf, $off)) {
            if (null === $chunk = $stream->read()) {
                return -1;
            }
            $buf .= $chunk;
        }
        return $int;
    }

    public function __construct(private QuicConnection $connection, private int $headerSizeLimit, private QPack $qpack)
    {
        $this->queue = new Queue;
    }

    public static function decodeFrameTypeFromStream(QuicSocket $stream, string &$buf, int &$off): ?Http3Frame
    {
        $frametype = self::decodeVarintFromStream($stream, $buf, $off);
        $maxPadding = 0x1000;
        while ($frametype >= 0x21 && $frametype % 0x1f === 2) {
            $length = self::decodeVarintFromStream($stream, $buf, $off);
            if ($length > $maxPadding) {
                return null;
            }
            $maxPadding -= $length;
            $off += $length;
            $frametype = self::decodeVarintFromStream($stream, $buf, $off);
        }
        return Http3Frame::tryFrom($frametype);
    }

    public static function readFullFrame(QuicSocket $stream, string &$buf, int &$off, $maxSize): ?array
    {
        $type = self::decodeFrameTypeFromStream($stream, $buf, $off);
        if (null === $frame = self::readFrameWithoutType($stream, $buf, $off, $maxSize)) {
            return null;
        }
        return [$type, $frame];
    }

    public static function readFrameWithoutType(QuicSocket $stream, string &$buf, int &$off, $maxSize): ?string
    {
        $length = self::decodeVarintFromStream($stream, $buf, $off);
        if ($length < 0) {
            return null;
        }
        if ($length > $maxSize) {
            return null;
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
                return null;
            }
            $buf .= $chunk;
        }
        $off = $length;
        return \substr($buf, 0, $length);
    }

    private function parseSettings(string $contents)
    {
        $off = 0;
        $settings = [];
        while ((-1 !== $key = self::decodeVarint($contents, $off)) && (-1 !== $value = self::decodeVarint($contents, $off))) {
            if ($key = Http3Settings::tryFrom($key)) {
                $settings[] = [$key, $value];
            }
        }
        $this->queue->push([Http3Frame::SETTINGS, $settings]);
    }

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
                $this->queue->complete();
                return;
            }
            if ($frame === Http3Frame::PUSH_PROMISE) {
                yield Http3Frame::PUSH_PROMISE => $this->parsePushPromise($contents);
            } else {
                break;
            }
        }
        if ($frame !== Http3Frame::HEADERS) {
            return;
        }
        $headerOff = 0;
        yield Http3Frame::HEADERS => self::processHeaders($this->qpack->decode($contents, $headerOff));
        $hadData = false;
        while (null !== $type = self::decodeFrameTypeFromStream($stream, $buf, $off)) {
            switch ($type) {
                // At most one trailing header
                case Http3Frame::HEADERS:
                    $headers = self::readFrameWithoutType($stream, $buf, $off, $this->headerSizeLimit);
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
                        yield \substr($buf, $off);
                        $length -= \strlen($buf);
                        $buf = "";
                        $off = 0;
                        while (true) {
                            if (null === $buf = $stream->read()) {
                                return;
                            }
                            if (\strlen($buf) < $length) {
                                yield Http3Frame::DATA => $buf;
                                $length -= \strlen($buf);
                            } else {
                                yield Http3Frame::DATA => \substr($buf, $length);
                                $off = $length;
                                break;
                            }
                        }
                    }
                    // no break
                case Http3Frame::PUSH_PROMISE:
                    $headers = self::readFrameWithoutType($stream, $buf, $off, $this->headerSizeLimit);
                    yield Http3Frame::PUSH_PROMISE => $this->parsePushPromise($headers);
                    break;

                default:
                    $this->queue->complete();
            }
        }
        if (![$frame, $contents] = self::readFullFrame($stream, $buf, $off, $this->headerSizeLimit)) {
            $this->queue->complete();
            return;
        }
        if ($frame === Http3Frame::PUSH_PROMISE) {
            yield Http3Frame::PUSH_PROMISE => $this->parsePushPromise($contents);
        } else {
            $this->queue->complete();
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

    public function process(): ConcurrentIterator
    {
        EventLoop::queue(function () {
            while ($stream = $this->connection->accept()) {
                EventLoop::queue(function () use ($stream) {
                    $off = 0;
                    $buf = $stream->read();
                    if ($stream->isWritable()) {
                        // client-initiated bidirectional stream
                        $messageGenerator = $this->readHttpMessage($stream, $buf, $off);
                        if (!$messageGenerator->valid()) {
                            return;
                        }
                        if ($messageGenerator->key() !== Http3Frame::HEADERS) {
                            $this->queue->complete();
                            return;
                        }
                        $this->queue->push([Http3Frame::HEADERS, $stream, $messageGenerator]);
                    } else {
                        // unidirectional stream
                        $type = self::decodeVarintFromStream($stream, $buf, $off);
                        switch (Http3StreamType::tryFrom($type)) {
                            case Http3StreamType::Control:
                                if (![$frame, $contents] = $this->readFullFrame($stream, $buf, $off, 0x1000)) {
                                    $this->queue->complete();
                                    return;
                                }
                                if ($frame !== Http3Frame::SETTINGS) {
                                    $this->queue->complete();
                                    return;
                                }
                                $this->parseSettings($contents);

                                while (true) {
                                    if (![$frame, $contents] = $this->readFullFrame($stream, $buf, $off, 0x100)) {
                                        $this->queue->complete();
                                        return;
                                    }

                                    if ($frame !== Http3Frame::GOAWAY || $frame !== Http3Frame::MAX_PUSH_ID || $frame !== Http3Frame::CANCEL_PUSH) {
                                        $this->queue->complete();
                                        return;
                                    }

                                    $tmpOff = 0;
                                    if (null === $id = self::decodeVarint($contents, $tmpOff)) {
                                        $this->queue->complete();
                                        return;
                                    }
                                    $this->queue->push([$frame, $id]);
                                }

                                // no break
                            case Http3StreamType::Push:
                                $pushId = self::decodeVarintFromStream($stream, $buf, $off);
                                $this->queue->push([Http3StreamType::Push, $pushId, fn () => $this->readHttpMessage($stream, $buf, $off)]);
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
                                return;
                        }
                    }
                });
            }
        });

        return $this->queue->iterate();
    }
}
