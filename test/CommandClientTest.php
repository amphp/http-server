<?php

namespace Aerys\Test;

use Aerys\Bootstrapper;
use Aerys\CommandClient;

class CommandClientTest extends \PHPUnit_Framework_TestCase {
    public function testSendRestart() {
        \Amp\execute(function () {
            $path = CommandClient::socketPath(Bootstrapper::selectConfigFile(__FILE__));
            $unix = in_array("unix", \stream_get_transports(), true);

            if ($unix) {
                $socketAddr = "unix://$path.sock";
            } else {
                $socketAddr = "tcp://127.0.0.1:*";
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

                \Amp\onReadable($commandServer, function ($watcher, $commandServer) use (&$clientSocket) {
                    if ($clientSocket = stream_socket_accept($commandServer, $timeout = 0)) {
                        \Amp\cancel($watcher);
                    }
                });

                yield $client->restart();

                // delay until next tick to not have $client in the backtrace so that gc_collect_cyles() will really collect it
                $deferred = new \Amp\Deferred;
                \Amp\defer([$deferred, "resolve"]);
                yield $deferred->getAwaitable();
                unset($client); // force freeing of client and the socket
                gc_collect_cycles();

                $data = stream_get_contents($clientSocket);
                $this->assertEquals(\strlen($data) - 4, unpack("Nlength", $data)["length"]);

                $response = json_decode(substr($data, 4), true);
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