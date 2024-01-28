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
        return \pack("J", 0xC000000000000000 | $int);
    }

    private static function sendFrame(QuicSocket $stream, Http3Frame $type, string $payload)
    {
        $stream->write(self::encodeVarint($type->value) . self::encodeVarint(\strlen($payload)) . $payload);
    }

    public function sendHeaderFrame(QuicSocket $stream, string $payload)
    {
        self::sendFrame($stream, Http3Frame::HEADERS, $payload);
    }

    public function sendData(QuicSocket $stream, string $payload)
    {
        self::sendFrame($stream, Http3Frame::DATA, $payload);
    }

    public function sendGoaway(int $highestStreamId)
    {
        self::sendFrame($this->controlStream, Http3Frame::GOAWAY, self::encodeVarint($highestStreamId));
    }

    private function startControlStream()
    {
        $this->controlStream = $this->connection->openStream();
        $this->controlStream->endReceiving(); // unidirectional please

        $ints = [];
        foreach ($this->settings as $setting => $value) {
            $ints[] = self::encodeVarint($setting);
            $ints[] = self::encodeVarint($value);
        }
        self::sendFrame($this->controlStream, Http3Frame::SETTINGS, \implode($ints));
    }

    public function maxDatagramSize()
    {
        return $this->connection->maxDatagramSize() - 8; // to include the longest quarter stream id varint
    }

    public function writeDatagram(QuicSocket $stream, string $buf)
    {
        $this->connection->send(self::encodeVarint($stream->getId() >> 2) . $buf);
    }

    public function close()
    {
        $this->connection->close(Http3Error::H3_NO_ERROR->value);
    }
}
