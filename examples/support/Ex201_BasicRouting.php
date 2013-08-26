<?php

function ex201_my_function($asgiEnv) {
    $body = '<html><body><h1>ex201_my_function</h1>';
    $body.= '<hr/>';
    $body.= Ex201_BasicRouting::generateLinkList($asgiEnv['REQUEST_URI_PATH']);
    $body.= '<hr/><p>';
    $body.= 'This example demonstrates using a global function to respond to requests.';
    $body.= '</p></body></html>';

    return [200, 'OK', $headers = [], $body];
}

$ex201_closure = function($asgiEnv) {
    $body = '<html><body><h1>$ex201_closure</h1>';
    $body.= '<hr/>';
    $body.= Ex201_BasicRouting::generateLinkList($asgiEnv['REQUEST_URI_PATH']);
    $body.= '<hr/><p>';
    $body.= 'This example demonstrates using a closure to respond to requests.';
    $body.= '</p></body></html>';

    return [200, 'OK', $headers = [], $body];
};

class Ex201_Dependency {}

class Ex201_BasicRouting {

    private static $links = [
        '/' => 'Hello World',
        '/info' => 'ASGI Environment',
        '/123/456/anything' => 'URI Arguments',
        '/static' => 'Static Class Method',
        '/function' => 'Global Function',
        '/closure' => 'Closure'
    ];

    function __construct(Ex201_Dependency $dep) {}

    function hello($asgiEnv) {
        $body = '<html><body><h1>Ex201_BasicRouting::hello</h1>';
        $body.= '<hr/>';
        $body.= $this->generateLinkList($asgiEnv['REQUEST_URI_PATH']);
        $body.= '<hr/>';
        $body.= '<h2>Hello, World.</h2>';
        $body.= '</body></html>';

        return [200, 'OK', $headers = [], $body];
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

    function info($asgiEnv) {
        $body = '<html><body><h1>Ex201_BasicRouting::info</h1>';
        $body.= '<hr/>';
        $body.= $this->generateLinkList($asgiEnv['REQUEST_URI_PATH']);
        $body.= '<hr/>';
        $body.= '<p>Every request endpoint handler is passed an <i>$asgiEnv</i> environment ';
        $body.= 'array. This array tells you everying you need to know about the request that ';
        $body.= 'led to the handler\'s invocation:</p>';
        $body.= '<pre>' . print_r($asgiEnv, TRUE) . '</pre>';
        $body.= '</body></html>';

        return [200, 'OK', $headers = [], $body];
    }

    function args($asgiEnv) {
        $body = '<html><body><h1>Ex201_BasicRouting::args</h1>';
        $body.= '<hr/>';
        $body.= $this->generateLinkList($asgiEnv['REQUEST_URI_PATH']);
        $body.= '<hr/>';
        $body.= "<h3>\$asgiEnv['URI_ROUTE_ARGS']</h3>";
        $body.= '<pre>'. print_r($asgiEnv['URI_ROUTE_ARGS'], TRUE) .'</pre>';
        $body.= '</body></html>';

        return [200, 'OK', $headers = [], $body];
    }
    
    function myStaticHandler($asgiEnv) {
        $body = '<html><body><h1>Ex202_MoreRouting::myStaticHandler</h1>';
        $body.= '<hr/>';
        $body.= self::generateLinkList($asgiEnv['REQUEST_URI_PATH']);
        $body.= '<hr/><p>';
        $body.= 'Aerys doesn\'t limit you to class instance methods when defining URI endpoints. ';
        $body.= 'This example utilizes a static class method to handle requests.';
        $body.= '</p></body></html>';

        return [200, 'OK', $headers = [], $body];
    }

}
