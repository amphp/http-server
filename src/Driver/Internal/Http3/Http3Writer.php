<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

use Amp\Quic\QuicConnection;
use Amp\Quic\QuicSocket;

class Http3Writer
{
    private QuicSocket $controlStream;

    public function __construct(private QuicConnection $connection, private array $settings)
    {
        $this->startControlStream();
    }

    public static function encodeVarint(int $int): string
    {
        if ($int <= 0x3F) {
            return \chr($int);
        }
        if ($int <= 0x3FFF) {
            return \chr(($int >> 8) | 0x40) . \chr($int & 0xFF);
        }
        if ($int <= 0x3FFFFFFF) {
            return \pack("N", 0x80000000 | $int);
        }
        return \pack("J", -0x4000000000000000 | $int);
    }

    public static function sendFrame(QuicSocket $stream, int $type, string $payload): void
    {
        $stream->write(self::encodeVarint($type) . self::encodeVarint(\strlen($payload)) . $payload);
    }

    private static function sendKnownFrame(QuicSocket $stream, Http3Frame $type, string $payload): void
    {
        self::sendFrame($stream, $type->value, $payload);
    }

    public function sendHeaderFrame(QuicSocket $stream, string $payload): void
    {
        self::sendKnownFrame($stream, Http3Frame::HEADERS, $payload);
    }

    public function sendData(QuicSocket $stream, string $payload): void
    {
        self::sendKnownFrame($stream, Http3Frame::DATA, $payload);
    }

    public function sendPriorityRequest(int $streamId, string $structuredPriorityData): void
    {
        self::sendKnownFrame($this->controlStream, Http3Frame::PRIORITY_UPDATE_Request, self::encodeVarint($streamId) . $structuredPriorityData);
    }

    public function sendPriorityPush(int $streamId, string $structuredPriorityData): void
    {
        self::sendKnownFrame($this->controlStream, Http3Frame::PRIORITY_UPDATE_Push, self::encodeVarint($streamId) . $structuredPriorityData);
    }

    public function sendMaxPushId(int $pushId): void
    {
        self::sendKnownFrame($this->controlStream, Http3Frame::MAX_PUSH_ID, self::encodeVarint($pushId));
    }

    public function sendCancelPush(int $pushId): void
    {
        self::sendKnownFrame($this->controlStream, Http3Frame::CANCEL_PUSH, self::encodeVarint($pushId));
    }

    public function sendGoaway(int $highestStreamId): void
    {
        self::sendKnownFrame($this->controlStream, Http3Frame::GOAWAY, self::encodeVarint($highestStreamId));
    }

    public function initiateUnidirectionalStream(int $streamType): QuicSocket
    {
        $stream = $this->connection->openStream();
        $stream->endReceiving(); // unidirectional please
        $stream->write(self::encodeVarint($streamType));
        return $stream;
    }

    private function startControlStream(): void
    {
        $this->controlStream = $this->initiateUnidirectionalStream(Http3StreamType::Control->value);

        $ints = [];
        foreach ($this->settings as $setting => $value) {
            $ints[] = self::encodeVarint($setting);
            $ints[] = self::encodeVarint($value);
        }
        self::sendKnownFrame($this->controlStream, Http3Frame::SETTINGS, \implode($ints));
    }

    public function maxDatagramSize(): int
    {
        return $this->connection->maxDatagramSize() - 8; // to include the longest quarter stream id varint
    }

    public function writeDatagram(QuicSocket $stream, string $buf): void
    {
        $this->connection->send(self::encodeVarint($stream->getId() >> 2) . $buf);
    }

    public function close(): void
    {
        $this->connection->close(Http3Error::H3_NO_ERROR->value);
    }
}
