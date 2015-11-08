<?php

namespace Aerys\Test;

use Aerys\Bootstrapper;
use Aerys\CommandClient;

class CommandClientTest extends \PHPUnit_Framework_TestCase {
    public function testSendRestart() {
        \Amp\run(function () {
            if (!$commandServer = @stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr)) {
                $this->markTestSkipped(sprintf(
                    "Failed binding socket server on tcp://127.0.0.1:*: [%d] %s",
                    $errno,
                    $errstr
                ));
                return;
            }

            try {
                $path = CommandClient::socketPath(Bootstrapper::selectConfigFile(__FILE__));
                file_put_contents($path, stream_socket_get_name($commandServer, $wantPeer = false));

                $client = new CommandClient(__FILE__);

                \Amp\onReadable($commandServer, function ($watcher, $commandServer) use (&$clientSocket) {
                    if ($clientSocket = stream_socket_accept($commandServer, $timeout = 0)) {
                        \Amp\cancel($watcher);
                    }
                });

                yield $client->restart();

                yield; // implicit immediate to not have $client in the backtrace so that gc_collect_cyles() will really collect it
                unset($client); // force freeing of client and the socket
                gc_collect_cycles();

                $data = stream_get_contents($clientSocket);
                $this->assertEquals(\strlen($data) - 4, unpack("Nlength", $data)["length"]);

                $response = json_decode(substr($data, 4), true);
                $this->assertEquals(["action" => "restart"], $response);
            } finally {
                @unlink($path);
            }
        });
    }
}