<?php

class Ex201_Dependency {}

class Ex201_BasicRouting {

    private $links = [
        '/' => 'Hello World',
        '/info' => 'ASGI Environment',
        '/123/456/anything' => 'URI Arguments'
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

    private function generateLinkList($uriPath) {
        $html = '<ul>';
        foreach ($this->links as $path => $description) {
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

}
