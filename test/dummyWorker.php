<?php

require __DIR__ . "/../vendor/autoload.php";

$testSock = stream_socket_client($argv[1]);
switch (fread($testSock, 1)) {
    case 1:
        $ipcSock = stream_socket_client($argv[2]);
        $ipcSock = new Amp\Socket\ClientSocket($ipcSock);

        $climate = new League\CLImate\CLImate;
        $climate->arguments->add([
            "log" => [
                "prefix"       => "l",
                "defaultValue" => "warning",
            ],
            "color" => [
                "longPrefix"   => "color",
                "defaultValue" => "off",
            ],
        ]);

        $console = new Aerys\Console($climate);
        $ipcLogger = new Aerys\IpcLogger($console, $ipcSock);

        Amp\Loop::run(function () use ($ipcLogger) {
            $ipcLogger->warning("testmessage");
        });

        $ipcSock->close();
        usleep(2e4);
        fwrite($testSock, 1);
        exit;
    case 2:
        fwrite($testSock, 2);
        exit;
    case 3:
        $ipcSock = stream_socket_client($argv[2]);
        $data = stream_get_contents($ipcSock, strlen(Aerys\WatcherProcess::STOP_SEQUENCE));
        fwrite($testSock, 3);
        fwrite($testSock, $data);
        exit;
}
