<?php

function myRouteFunction($request) {
    $body = '<html><body><h1>myRouteFunction</h1>';
    $body.= '<hr/>';
    $body.= BasicRouting::generateLinkList($request['REQUEST_URI_PATH']);
    $body.= '<hr/><p>';
    $body.= 'This example demonstrates using a global function to respond to requests.';
    $body.= '</p></body></html>';

    return $body;
}

$myRouteClosure = function($request) {
    $body = '<html><body><h1>$ex007_closure</h1>';
    $body.= '<hr/>';
    $body.= BasicRouting::generateLinkList($request['REQUEST_URI_PATH']);
    $body.= '<hr/><p>';
    $body.= 'This example demonstrates using a closure to respond to requests.';
    $body.= '</p></body></html>';

    return $body;
};

class ClassDependency {}

class BasicRouting {

    private static $links = [
        '/' => 'Hello World',
        '/info' => 'ASGI Environment',
        '/123/456/anything' => 'URI Arguments',
        '/static' => 'Static Class Method',
        '/function' => 'Global Function',
        '/closure' => 'Closure'
    ];

    function __construct(ClassDependency $dep) {}

    function hello($request) {
        $body = '<html><body><h1>BasicRouting::hello</h1>';
        $body.= '<hr/>';
        $body.= $this->generateLinkList($request['REQUEST_URI_PATH']);
        $body.= '<hr/>';
        $body.= '<h2>Hello, World.</h2>';
        $body.= '</body></html>';

        return $body;
    }

    static function generateLinkList($uriPath) {
        $html = '<ul>';
        foreach (self::$links as $path => $description) {
            $link = ($path === $uriPath) ? $description : "<a href=\"{$path}\">{$description}</a>";
            $html .= "<li>{$link}</li>";
        }
        $html .= '</ul>';

        return $html;
    }

    function info($request) {
        $body = '<html><body><h1>BasicRouting::info</h1>';
        $body.= '<hr/>';
        $body.= $this->generateLinkList($request['REQUEST_URI_PATH']);
        $body.= '<hr/>';
        $body.= '<p>Every request endpoint handler is passed an <i>$request</i> environment ';
        $body.= 'array. This array tells you everying you need to know about the request that ';
        $body.= 'led to the handler\'s invocation:</p>';
        $body.= '<pre>' . print_r($request, TRUE) . '</pre>';
        $body.= '</body></html>';

        return $body;
    }

    function args($request) {
        $body = '<html><body><h1>BasicRouting::args</h1>';
        $body.= '<hr/>';
        $body.= $this->generateLinkList($request['REQUEST_URI_PATH']);
        $body.= '<hr/>';
        $body.= "<h3>\$request['URI_ROUTE_ARGS']</h3>";
        $body.= '<pre>'. print_r($request['URI_ROUTE_ARGS'], TRUE) .'</pre>';
        $body.= '</body></html>';

        return $body;
    }

    function myStaticHandler($request) {
        $body = '<html><body><h1>Ex202_MoreRouting::myStaticHandler</h1>';
        $body.= '<hr/>';
        $body.= self::generateLinkList($request['REQUEST_URI_PATH']);
        $body.= '<hr/><p>';
        $body.= 'Aerys doesn\'t limit you to class instance methods when defining URI endpoints. ';
        $body.= 'This example utilizes a static class method to handle requests.';
        $body.= '</p></body></html>';

        return $body;
    }

}
