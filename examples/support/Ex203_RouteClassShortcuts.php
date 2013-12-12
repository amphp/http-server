<?php

class Ex203_RouteClassShortcuts {
    function __construct(){}
    function get() {
        return '<html><body><h1>Ex203_RouteClassShortcuts::get</h1></body></html>';
    }
    function post() {
        return '<html><body><h1>Ex203_RouteClassShortcuts::post</h1></body></html>';
    }
}

class Ex203_RouteClassShortcutsWithMap {
    function __construct(){}
    function get(){}
    function zanzibar() {
        return '<html><body><h1>Ex203_RouteClassShortcutsWithMap::zanzibar</h1></body></html>';
    }
}
