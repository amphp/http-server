<?php

namespace Aerys\Test;

use Aerys\CommandClient;
use Aerys\Console;
use Aerys\Logger;
use Aerys\WatcherProcess;
use Amp\Coroutine;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Socket\Server;
use Amp\Socket\Socket;
use Psr\Log\LoggerInterface as PsrLogger;

class WatcherProcessTest extends TestCase {
    const DUMMY_WORKER = __DIR__."/dummyWorker.php";

    public function init($cb) {
        Loop::run(function () use ($cb) {
            $logger = new class extends Logger {
                protected function output(string $message) {
                    // do nothing
                }
            };

            $proc = new class($logger) extends WatcherProcess {
                public $_workerCommand;
                public $_sock;
                public $_ipcAddress;

                public function __construct(PsrLogger $logger) {
                    parent::__construct($logger);
                }
                public function __set($prop, $val) {
                    if ($prop === "workerCommand") {
                        $this->_workerCommand = $val;
                        return;
                    }
                    $this->$prop = $val;
                }
                public function __get($prop) {
                    if ($prop == "workerCommand") {
                        $address = stream_socket_get_name($this->_sock, $wantPeer = false);
                        return \PHP_BINARY . (strpos(\PHP_BINARY, "phpdbg") !== false ? " -qrr" : "") . " ".escapeshellarg(WatcherProcessTest::DUMMY_WORKER)." ".escapeshellarg("tcp://$address")." ".escapeshellarg($this->_ipcAddress);
                    }
                    throw new \Error("Invalid property access");
                }
                public function stop(): \Generator {
                    return parent::doStop();
                }
            };
            if (!$proc->_sock = stream_socket_server("tcp://127.0.0.1:*")) {
                $this->markTestSkipped("Couldn't get a free port from the local ephemeral port range");
            }
            return (function ($test) use ($cb) {
                unset($this->workerCommand);
                $this->_ipcAddress = &$this->ipcServerUri;
                return $cb->bindTo($this, WatcherProcess::class)($test);
            })->bindTo($proc, WatcherProcess::class)($this);
        });
    }

    public function getConsole($args, &$outputCb = null) {
        return new class($args, $outputCb) extends Console {
            const ARGS = [
                "config" => WatcherProcessTest::DUMMY_WORKER,
                "workers" => 1,
                "color" => "foo",
                "log" => "baz",
            ];
            public $args;
            public $outputCb;
            public function __construct($args, &$outputCb) {
                $this->args = $args + self::ARGS;
                $this->outputCb = &$outputCb;
            }
            public function isArgDefined(string $arg) {
                return isset($this->args[$arg]);
            }
            public function getArg(string $arg) {
                return $this->args[$arg];
            }
            public function output(string $msg) {
                ($this->outputCb)($msg);
            }
        };
    }

    public function assertStopSequence(Socket $cli) {
        $buf = "";
        do {
            $buf .= $data = yield $cli->read();
        } while ($data !== null);
        $this->assertEquals(3, substr($buf, 0, 1));
        $this->assertSame(WatcherProcess::STOP_SEQUENCE, substr($buf, 1));
    }

    public function testWatcherLifecycle() {
        $this->init(function ($test) {
            $server = new Server($this->_sock);

            $console = $test->getConsole([], $outputCb);
            Promise\rethrow(new Coroutine($this->doStart($console)));

            $cli = yield $server->accept();
            $outputCb = function ($msg) use ($test) {
                $test->assertSame("warning: testmessage", strstr($msg, "warning: "));
            };
            yield $cli->write(1);
            $test->assertEquals(1, yield $cli->read());
            $outputCb = null;

            $cli = yield $server->accept();
            yield $cli->write(2);
            $test->assertEquals(2, yield $cli->read());

            $cli = yield $server->accept();
            yield $cli->write(3);

            Promise\rethrow(new Coroutine($this->doStop()));

            yield from $test->assertStopSequence($cli);
        });
    }

    public function testSocketTransfer() {
        if (stripos(PHP_OS, "WIN") === 0) {
            $this->markTestSkipped("Socket transfer only works on POSIX systems");
        }
        if (!\extension_loaded("sockets") || PHP_VERSION_ID < 70007) {
            $this->markTestSkipped("Socket transfer needs sockets extension");
        }

        $this->init(function ($test) {
            if (!$socket = stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr, STREAM_SERVER_BIND, stream_context_create(["socket" => ["so_reuseport" => true]]))) {
                $this->markTestSkipped("Couldn't get a free port from the local ephemeral port range");
            }
            $address = "tcp://" . stream_socket_get_name($socket, $want_peer = false);
            $ctxs = [$address => ["socket" => ["so_reuseport" => true, "so_reuseaddr" => true], "ssl" => ["random_option" => true]]];

            $server = new Server($this->_sock);

            $this->useSocketTransfer = true;
            $console = $test->getConsole(["workers" => 2]);
            Promise\rethrow(new Coroutine($this->doStart($console)));

            $cli[] = yield $server->accept();
            $cli[] = yield $server->accept();

            for ($i = 0; $i < 2; $i++) {
                $ipcCli[$i] = stream_socket_client($this->ipcServerUri);
                $socketPromises[$i] = (new CommandClient(WatcherProcessTest::DUMMY_WORKER))->importServerSockets($ctxs);
            }

            $socketsArray = yield $socketPromises;

            Promise\rethrow(new Coroutine($this->doStop()));

            foreach ($socketsArray as $i => $sockets) {
                $test->assertSame(WatcherProcess::STOP_SEQUENCE, fread($ipcCli[$i], \strlen(WatcherProcess::STOP_SEQUENCE)));

                $test->assertSame(1, count($sockets));
                $socket = $sockets[$address];
                $socket_address = "tcp://" . stream_socket_get_name($socket, $want_peer = false);
                $test->assertSame($address, $socket_address);
                $test->assertSame(true, stream_context_get_options($socket)["ssl"]["random_option"]);

                yield $cli[$i]->write(2); // shutdown worker
            }
        });
    }

    public function testCommands() {
        $this->init(function ($test) use (&$end) {
            $server = new Server($this->_sock);

            $console = $test->getConsole([]);
            Promise\rethrow(new Coroutine($this->doStart($console)));

            $commandClient = new CommandClient($console->args["config"]);

            $firstcli = yield $server->accept();
            yield $firstcli->write(3);

            $console = $test->getConsole(["restart" => true]);
            Promise\rethrow(new Coroutine($this->doStart($console)));

            // when restarting FIRST the new worker is spawned, THEN the old worker is shut down
            $nextcli = yield $server->accept();
            yield $nextcli->write(3);

            yield from $test->assertStopSequence($firstcli);


            $stopPromise = $commandClient->stop();

            yield from $test->assertStopSequence($nextcli);

            yield $stopPromise;
            $end = true;
        });
        $this->assertTrue($end);
    }
}
