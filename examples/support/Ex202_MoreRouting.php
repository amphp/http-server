<?php

class Ex202_MoreRouting {

    private static $links = [
        '/' => 'Static Class Method',
        '/function' => 'Global Function',
        '/closure' => 'Closure'
    ];

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

    public static function generateLinkList($uriPath) {
        $html = '<ul>';
        foreach (self::$links as $path => $description) {
            $link = ($path === $uriPath) ? $description : "<a href=\"{$path}\">{$description}</a>";
            $html .= "<li>{$link}</li>";
        }
        $html .= '</ul>';

        return $html;
    }

}

function ex201_my_function($asgiEnv) {
    $body = '<html><body><h1>ex201_my_function</h1>';
    $body.= '<hr/>';
    $body.= Ex202_MoreRouting::generateLinkList($asgiEnv['REQUEST_URI_PATH']);
    $body.= '<hr/><p>';
    $body.= 'This example demonstrates using a global function to respond to requests.';
    $body.= '</p></body></html>';

    return [200, 'OK', $headers = [], $body];
}

$ex201_closure = function($asgiEnv) {
    $body = '<html><body><h1>$ex201_closure</h1>';
    $body.= '<hr/>';
    $body.= Ex202_MoreRouting::generateLinkList($asgiEnv['REQUEST_URI_PATH']);
    $body.= '<hr/><p>';
    $body.= 'This example demonstrates using a closure to respond to requests.';
    $body.= '</p></body></html>';

    return [200, 'OK', $headers = [], $body];
};
