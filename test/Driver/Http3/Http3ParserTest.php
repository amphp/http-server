<?php

namespace Amp\Http\Server\Test\Driver\Http3;

use Amp\Http\Server\Driver\Internal\Http3\Http3Frame;
use Amp\Http\Server\Driver\Internal\Http3\Http3Parser;
use Amp\Http\Server\Driver\Internal\Http3\Http3Writer;
use Amp\Http\Server\Driver\Internal\Http3\QPack;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Quic\Pair\PairConnection;
use Amp\Quic\QuicSocket;
use PHPUnit\Framework\TestCase;

class Http3ParserTest extends TestCase {
    private int $paddingIndex = 0;
    private const PADDING_INDICES = [0x21, 0x10000, 0xfffffffe, 0x3ffffffffffffffe];

    public function setUp(): void
    {
        $this->paddingIndex = 0;
    }

    public function insertPaddingFrame(QuicSocket $stream, $size = 1): void
    {
        Http3Writer::sendFrame($stream, self::PADDING_INDICES[$this->paddingIndex++ % count(self::PADDING_INDICES)], str_repeat("a", $size));
    }

    /** @return list{PairConnection, PairConnection, Http3Parser, Http3Writer, ConcurrentIterator, \Generator, QuicSocket, QuicSocket} */
    public function runParsingRequest($endEarly = false, $sendSingleBytes = false)
    {
        [$server, $client] = PairConnection::createPair();

        if ($sendSingleBytes) {
            $client->config = $client->config
                ->withMaxLocalBidirectionalData(1)
                ->withMaxRemoteBidirectionalData(1)
                ->withMaxUnidirectionalData(1);
            $server->config = $server->config->withMaxUnidirectionalData(1);
        }

        $parser = new Http3Parser($server, 0x1000, new QPack);
        if ($sendSingleBytes) {
            $processor = $parser->process();
        }

        $writer = new Http3Writer($client, $sentSettings = [10 => 20, 11 => 30]);

        $qpack = new QPack;

        $req = $client->openStream();

        if ($sendSingleBytes) {
            $this->insertPaddingFrame($req);
            $this->insertPaddingFrame($req);
        }

        $writer->sendHeaderFrame($req, $qpack->encode([[":method", "GET"], ["header1", "a"], ["header1", "b"]]));

        if (!$sendSingleBytes) {
            $processor = $parser->process();
        }
        $processor->continue();
        [$type, $settings] = $processor->getValue();
        $this->assertSame(Http3Frame::SETTINGS, $type);
        $this->assertSame($sentSettings, $settings);

        $processor->continue();
        [$type, $stream, $generator] = $processor->getValue();
        $this->assertSame(Http3Frame::HEADERS, $type);

        [$headers, $pseudo] = $generator->current();
        $this->assertSame(["header1" => ["a", "b"]], $headers);
        $this->assertSame([":method" => "GET"], $pseudo);

        var_dump("!");

        if ($endEarly) {
            $req->end();
            $generator->next();
            $this->assertFalse($generator->valid());
        }
        return [$server, $client, $parser, $writer, $processor, $generator, $req, $stream];
    }

    public function testParsingRequest()
    {
        $this->runParsingRequest(endEarly: true);
    }

    public function testRequestWithData()
    {
        [$server, $client, $parser, $writer, $processor, $generator, $req, $stream] = $this->runParsingRequest();
        $writer->sendData($req, "some");
        $generator->next();
        $this->assertSame(Http3Frame::DATA, $generator->key());
        $this->assertSame("some", $generator->current());

        $req->resetSending();
        $generator->next();
        $this->assertNull($generator->key());
    }

    public function testSendingSingleBytes()
    {
        [$server, $client, $parser, $writer, $processor, $generator, $req, $stream] = $this->runParsingRequest(sendSingleBytes: true);

        $sendFuture = \Amp\async(function () use ($writer, $req) {
            $this->insertPaddingFrame($req);
            $this->insertPaddingFrame($req);

            $writer->sendData($req, "abc");

            $this->insertPaddingFrame($req);
            $writer->sendData($req, "d");
        });

        $generator->next();
        $this->assertSame(Http3Frame::DATA, $generator->key());
        $this->assertSame("a", $generator->current());
        $generator->next();
        $this->assertSame(Http3Frame::DATA, $generator->key());
        $this->assertSame("b", $generator->current());
        $generator->next();
        $this->assertSame(Http3Frame::DATA, $generator->key());
        $this->assertSame("c", $generator->current());
        $generator->next();
        $this->assertSame(Http3Frame::DATA, $generator->key());
        $this->assertSame("d", $generator->current());
        $this->assertNull($sendFuture->await());

        $sendFuture = \Amp\async(function () use ($req) {
            $req->write(Http3Writer::encodeVarint(Http3Frame::DATA->value) . Http3Writer::encodeVarint(2) . "e"); // incomplete data frame
            $req->resetSending();
        });

        $generator->next();
        $this->assertSame(Http3Frame::DATA, $generator->key());
        $this->assertSame("e", $generator->current());
        $generator->next();
        $this->assertNull($generator->key());
        $this->assertNull($sendFuture->await());
    }
}
