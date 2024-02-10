<?php

namespace Amp\Http\Server\Test\Driver\Http3;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Http\Server\Driver\Internal\Http3\Http3ConnectionException;
use Amp\Http\Server\Driver\Internal\Http3\Http3Frame;
use Amp\Http\Server\Driver\Internal\Http3\Http3Parser;
use Amp\Http\Server\Driver\Internal\Http3\Http3StreamType;
use Amp\Http\Server\Driver\Internal\Http3\Http3Writer;
use Amp\Http\Server\Driver\Internal\Http3\QPack;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Quic\Pair\PairConnection;
use Amp\Quic\QuicSocket;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

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

    /** @return list{PairConnection, PairConnection, Http3Parser, Http3Writer, ConcurrentIterator, \Generator, QuicSocket, QuicSocket, QPack} */
    public function runParsingRequest($endEarly = false, $sendSingleBytes = false): array
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

        if ($endEarly) {
            $req->end();
            $generator->next();
            $this->assertFalse($generator->valid());
        }
        return [$server, $client, $parser, $writer, $processor, $generator, $req, $stream, $qpack];
    }

    public function testParsingRequest(): void
    {
        $this->runParsingRequest(endEarly: true);
    }

    public function testRequestWithData(): void
    {
        [$server, $client, $parser, $writer, $processor, $generator, $req, $stream, $qpack] = $this->runParsingRequest();

        Http3Writer::sendFrame($req, Http3Frame::PUSH_PROMISE->value, Http3Writer::encodeVarint(2) . $qpack->encode([["header", "value"]]));
        $generator->next();
        $this->assertSame(Http3Frame::PUSH_PROMISE, $generator->key());
        $this->assertSame([2, [["header" => ["value"]], []]], $generator->current());

        Http3Writer::sendFrame($req, Http3Frame::PUSH_PROMISE->value, Http3Writer::encodeVarint(3) . $qpack->encode([["header", "other"]]));
        $generator->next();
        $this->assertSame(Http3Frame::PUSH_PROMISE, $generator->key());
        $this->assertSame([3, [["header" => ["other"]], []]], $generator->current());

        $writer->sendData($req, "some");
        $generator->next();
        $this->assertSame(Http3Frame::DATA, $generator->key());
        $this->assertSame("some", $generator->current());

        Http3Writer::sendFrame($req, Http3Frame::PUSH_PROMISE->value, Http3Writer::encodeVarint(4) . $qpack->encode([[":header", "other"]]));
        $generator->next();
        $this->assertSame(Http3Frame::PUSH_PROMISE, $generator->key());
        $this->assertSame([4, [[], [":header" => "other"]]], $generator->current());

        $req->resetSending();
        $generator->next();
        $this->assertNull($generator->key());
    }

    public function testSendingSingleBytes(): void
    {
        [$server, $client, $parser, $writer, $processor, $generator, $req, $stream, $qpack] = $this->runParsingRequest(sendSingleBytes: true);

        $sendFuture = \Amp\async(function () use ($writer, $qpack, $req) {
            $this->insertPaddingFrame($req);
            $this->insertPaddingFrame($req);

            $writer->sendHeaderFrame($req, $qpack->encode([["header", "value"]]));

            $this->insertPaddingFrame($req);
            $this->insertPaddingFrame($req);

            $writer->sendData($req, "abc");

            $this->insertPaddingFrame($req);
            $writer->sendData($req, "d");
        });

        // multiple leading headers are allowed
        $generator->next();
        $this->assertSame(Http3Frame::HEADERS, $generator->key());
        $this->assertSame([["header" => ["value"]], []], $generator->current());

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

    function testTrailers() {
        [$server, $client, $parser, $writer, $processor, $generator, $req, $stream, $qpack] = $this->runParsingRequest();

        $writer->sendData($req, "abc");

        $generator->next();
        $this->assertSame(Http3Frame::DATA, $generator->key());
        $this->assertSame("abc", $generator->current());

        $writer->sendHeaderFrame($req, $qpack->encode([["header", "value"]]));

        $generator->next();
        $this->assertSame(Http3Frame::HEADERS, $generator->key());
        $this->assertSame([["header" => ["value"]], []], $generator->current());

        Http3Writer::sendFrame($req, Http3Frame::PUSH_PROMISE->value, Http3Writer::encodeVarint(2) . $qpack->encode([["header", "value"]]));
        $generator->next();
        $this->assertSame(Http3Frame::PUSH_PROMISE, $generator->key());
        $this->assertSame([2, [["header" => ["value"]], []]], $generator->current());

        Http3Writer::sendFrame($req, Http3Frame::PUSH_PROMISE->value, Http3Writer::encodeVarint(3) . $qpack->encode([["header", "other"]]));
        $generator->next();
        $this->assertSame(Http3Frame::PUSH_PROMISE, $generator->key());
        $this->assertSame([3, [["header" => ["other"]], []]], $generator->current());

        $writer->sendData($req, "abc");

        try {
            $generator->next();
        } catch (Http3ConnectionException $e) {
        }

        if (!isset($e)) {
            $this->fail("Message did not reject disallowed DATA frame after trailing headers");
        }
    }

    function testUnidirectionalStreams() {
        [$server, $client] = PairConnection::createPair();

        $parser = new Http3Parser($server, 0x1000, new QPack);

        $writer = new Http3Writer($client, $sentSettings = [10 => 20, 11 => 30]);

        $writer->sendPriorityPush(1, "foo");

        $processor = $parser->process();

        $processor->continue();
        $this->assertSame([Http3Frame::SETTINGS, $sentSettings], $processor->getValue());

        $processor->continue();
        $this->assertSame([Http3Frame::PRIORITY_UPDATE_Push, 1, "foo"], $processor->getValue());

        $writer->sendPriorityRequest(2, "bar");
        $processor->continue();
        $this->assertSame([Http3Frame::PRIORITY_UPDATE_Request, 2, "bar"], $processor->getValue());

        $writer->sendMaxPushId(3);
        $processor->continue();
        $this->assertSame([Http3Frame::MAX_PUSH_ID, 3], $processor->getValue());

        $writer->sendCancelPush(4);
        $processor->continue();
        $this->assertSame([Http3Frame::CANCEL_PUSH, 4], $processor->getValue());

        $writer->sendGoaway(5);
        $processor->continue();
        $this->assertSame([Http3Frame::GOAWAY, 5], $processor->getValue());

        // Accepted and handled internally
        $qpackEncode = $client->openStream();
        $qpackEncode->endReceiving();
        $qpackEncode->write(Http3Writer::encodeVarint(Http3StreamType::QPackEncode->value));
        $qpackDecode = $client->openStream();
        $qpackDecode->endReceiving();
        $qpackDecode->write(Http3Writer::encodeVarint(Http3StreamType::QPackDecode->value));

        $custom = $client->openStream();
        $custom->endReceiving();
        $custom->write(Http3Writer::encodeVarint(5) . "body");

        $processor->continue();
        [$type, $buf, $stream] = $processor->getValue();
        $this->assertSame(5, $type);
        $this->assertSame("body", $buf);

        $custom->write("more data");
        $this->assertSame("more data", $stream->read());

        $stream = $client->openStream();
        $stream->endReceiving();
        $stream->write(Http3Writer::encodeVarint(Http3StreamType::Control->value));
        try {
            $processor->continue();
        } catch (Http3ConnectionException $e) {
        }
        if (!isset($e)) {
            $this->fail("There must be only one control stream");
        }
    }

    public function testParsePriority() {
        $this->assertSame([7, true], Http3Parser::parsePriority("u=7, i"));
        $this->assertSame([3, true], Http3Parser::parsePriority("u=8, i"));
        $this->assertSame([3, false], Http3Parser::parsePriority("u=-1, i=1"));
        $this->assertSame([0, false], Http3Parser::parsePriority("u=0, i=foo"));
    }

    public function testDatagram() {
        [$server, $client] = PairConnection::createPair();

        $clientStream = $client->openStream();
        $clientStream->write(""); // force open
        $serverStream = $server->accept();

        $clientStream2 = $client->openStream();
        $clientStream2->write(""); // force open
        $serverStream2 = $server->accept();

        $parser = new Http3Parser($server, 0x1000, new QPack);
        $writer = new Http3Writer($client, []);

        $writer->writeDatagram($clientStream, "some data");
        $writer->writeDatagram($clientStream, "more data");
        $this->assertSame("some data", $parser->receiveDatagram($serverStream));
        $this->assertSame("more data", $parser->receiveDatagram($serverStream));

        EventLoop::queue(fn() => $writer->writeDatagram($clientStream2, "second"));
        $this->assertSame("second", $parser->receiveDatagram($serverStream2));

        $cancel = new DeferredCancellation;
        EventLoop::queue($cancel->cancel(...));
        try {
            $parser->receiveDatagram($serverStream2, $cancel->getCancellation());
        } catch (CancelledException $e) {
        }
        if (!isset($e)) {
            $this->fail("The datagram wasn't cancelled");
        }

        EventLoop::queue(fn() => $clientStream->close());
        $this->assertNull($parser->receiveDatagram($serverStream));
        $this->assertNull($parser->receiveDatagram($serverStream));
    }
}
