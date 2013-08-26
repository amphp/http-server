<?php

class Ex202_RouteArguments {
    
    private $links;
    
    function __construct() {
        $html = '<ul>';
        $html .= '<li><a href="/">Index</a></li>';
        $html .= '<li><a href="/123">Numeric URI Arg</a></li>';
        $html .= '<li><a href="/anything">Anything URI Arg</a></li>';
        $html .= '<li><a href="/123/456/anything">Mixed URI Args</a></li>';
        $html .= '</ul>';
        
        $this->links = $html;
    }
    
    function index() {
        $body = '<html><body><h1>Ex202_RouteArguments::numericArg</h1>';
        $body.= '<hr/>' . $this->links . '<hr/>';
        $body.= '</body></html>';

        return [200, 'OK', $headers = [], $body];
    }
    
    function numericArg($asgiEnv) {
        $body = '<html><body><h1>Ex202_RouteArguments::numericArg</h1>';
        $body.= '<hr/>' . $this->links . '<hr/>';
        $body.= "<h3>{$asgiEnv['REQUEST_URI']}</h3>";
        $body.= '<pre>'. print_r($asgiEnv['URI_ROUTE_ARGS'], TRUE) .'</pre>';
        $body.= '</body></html>';

        return [200, 'OK', $headers = [], $body];
    }
    
    function anythingArg($asgiEnv) {
        $body = '<html><body><h1>Ex202_RouteArguments::anythingArg</h1>';
        $body.= '<hr/>' . $this->links . '<hr/>';
        $body.= "<h3>{$asgiEnv['REQUEST_URI']}</h3>";
        $body.= '<pre>'. print_r($asgiEnv['URI_ROUTE_ARGS'], TRUE) .'</pre>';
        $body.= '</body></html>';

        return [200, 'OK', $headers = [], $body];
    }
    
    function mixedArgs($asgiEnv) {
        $body = '<html><body><h1>Ex202_RouteArguments::mixedArgs</h1>';
        $body.= '<hr/>' . $this->links . '<hr/>';
        $body.= "<h3>{$asgiEnv['REQUEST_URI']}</h3>";
        $body.= '<pre>'. print_r($asgiEnv['URI_ROUTE_ARGS'], TRUE) .'</pre>';
        $body.= '</body></html>';

        return [200, 'OK', $headers = [], $body];
    }
}
