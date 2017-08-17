<?php

namespace Aerys\Test;

use Aerys\CommandClient;
use Amp\Loop;
use PHPUnit\Framework\TestCase;
use function Aerys\selectConfigFile;

class CommandClientTest extends TestCase {
    public function testSendRestart() {
        Loop::run(function () {
            $path = CommandClient::socketPath(selectConfigFile(__FILE__));
            $unix = in_array("unix", \stream_get_transports(), true);

            if ($unix) {
                $socketAddr = "unix://$path.sock";
            } else {
                $socketAddr = "tcp://127.0.0.1:0";
            }

            if (!$commandServer = stream_socket_server($socketAddr, $errno, $errstr)) {
                var_dump($commandServer);
                $this->markTestSkipped(sprintf(
                    "Failed binding socket server on $socketAddr: [%d] %s",
                    $errno,
                    $errstr
                ));
                return;
            }

            try {
                if (!$unix) {
                    file_put_contents($path, stream_socket_get_name($commandServer, $wantPeer = false));
                }

                $client = new CommandClient(__FILE__);

                Loop::onReadable($commandServer, function ($watcher, $commandServer) use (&$clientSocket) {
                    if ($clientSocket = stream_socket_accept($commandServer, $timeout = 0)) {
                        Loop::cancel($watcher);
                        Loop::onReadable($clientSocket, function ($watcher, $socket) {
                            fwrite($socket, "[]\n");
                            Loop::cancel($watcher);
                        });
                    }
                });


                yield $client->restart();

                // delay until next tick to not have $client in the backtrace so that gc_collect_cyles() will really collect it
                $deferred = new \Amp\Deferred;
                Loop::defer([$deferred, "resolve"]);
                yield $deferred->promise();

                unset($client); // so that stream_get_contents() will read from a closed socket

                $data = stream_get_contents($clientSocket);
                $this->assertEquals("\n", substr($data, -1));

                $response = json_decode(substr($data, 0, -1), true);
                $this->assertEquals(["action" => "restart"], $response);
            } finally {
                if ($unix) {
                    fclose($commandServer);
                    @unlink("$path.sock");
                } else {
                    @unlink($path);
                }
            }
        });
    }
}
