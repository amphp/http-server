#!/usr/bin/env php
<?php
require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use function Amp\Http\Server\handleFunc;
use function Amp\Http\Server\html;
use function Amp\Http\Server\listenAndServe;

function helloWorld(Request $request): Response
{
    return html('Hello World!');
}

Amp\Loop::run(static function () {
    yield listenAndServe('0.0.0.0:8000', handleFunc('helloWorld'));
});
