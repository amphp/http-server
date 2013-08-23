<?php

function ex302_static_and_dynamic() {

    $body = '<html><body><h1>ex302_static_and_dynamic</h1><hr/><p>';
    $body.= 'This file is served by the dynamic handler. The request matches a route URI ';
    $body.= 'so it never reaches the static file handler.</p><p>';
    $body.= 'Requests that don\'t match any of our dynamic routes pass through to the document ';
    $body.= 'root for handling. To demonstrate this behavior, click <a href="/robots.txt">this ';
    $body.= 'link pointing to the static document root\'s robots.txt</a> file.';
    $body.= '</p></body></html>';

    return [200, 'OK', $headers = [], $body];

}
