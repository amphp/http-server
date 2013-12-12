<?php

namespace Aerys\Test\Framework;

use Aerys\Framework\App;

class AppTest extends \PHPUnit_Framework_TestCase {

    function testSetPort() {
        $app = (new App)->setPort(443);
        $arr = $app->toArray();
        $this->assertEquals(443, $arr['port']);
    }

    function testSetAddress() {
        $app = (new App)->setAddress('127.0.0.1');
        $arr = $app->toArray();
        $this->assertEquals('127.0.0.1', $arr['address']);
    }

    function testSetName() {
        $app = (new App)->setName('mysite.com');
        $arr = $app->toArray();
        $this->assertEquals('mysite.com', $arr['name']);
    }

    function testEncrypt() {
        $app = (new App)->encrypt($tlsSettings = [42]);
        $arr = $app->toArray();
        $this->assertEquals([42], $arr['encryption']);
    }

    /**
     * @dataProvider provideRoutes
     */
    function testAddRoute($input, $expectedOutput) {
        list($method, $route, $handler) = $input;

        $app = (new App)->addRoute($method, $route, $handler);
        $arr = $app->toArray();

        list($expectedMethod, $expectedRoute, $expectedHandler) = $expectedOutput;

        $routeArr = current($arr['routes']);

        $this->assertEquals($expectedMethod, $routeArr[0]);
        $this->assertEquals($expectedRoute, $routeArr[1]);
        $this->assertEquals($expectedHandler, $routeArr[2]);
    }

    function provideRoutes() {
        $handler = function(){};
        return [
            [['GET', 'no_leading_slash', $handler], ['GET', '/no_leading_slash', $handler]],
            [['GET', '/leading_slash', $handler], ['GET', '/leading_slash', $handler]],
            [['GET', '///multi_slash', $handler], ['GET', '/multi_slash', $handler]],
        ];
    }

    /**
     * @dataProvider provideWebsocketValues
     */
    function testAddWebsocket($input, $expectedOutput) {
        list($route, $endpointClass, $options) = $input;

        $app = (new App)->addWebsocket($route, $endpointClass, $options);
        $arr = $app->toArray();

        list($expectedRoute, $expectedEndpointClass, $expectedOptions) = $expectedOutput;

        $routeArr = current($arr['websockets']);

        $this->assertEquals($expectedRoute, $routeArr[0]);
        $this->assertEquals($expectedEndpointClass, $routeArr[1]);
        $this->assertEquals($expectedOptions, $routeArr[2]);
    }

    function provideWebsocketValues() {
        return [
            [['no_leading_slash', 'Endpoint', []], ['/no_leading_slash', 'Endpoint', []]],
            [['/leading_slash', 'Endpoint', [1,2,3]], ['/leading_slash', 'Endpoint', [1,2,3]]],
            [['///multi_slash', 'Endpoint', [42]], ['/multi_slash', 'Endpoint', [42]]],
        ];
    }

    function testSetDocumentRoot() {
        $app = (new App)->setDocumentRoot('/path/to/docs', $options = []);
        $arr = $app->toArray();
        $this->assertEquals(['docRoot' => '/path/to/docs'], $arr['documentRoot']);
    }

    function testSetReverseProxy() {
        $app = (new App)->reverseProxyTo(['127.0.0.1:1337'], $options = []);
        $arr = $app->toArray();
        $this->assertEquals(['backends' => ['127.0.0.1:1337']], $arr['reverseProxy']);
    }

    function testAddUserResponder() {
        $app = new App;
        $this->assertSame($app, $app->addResponder('test1'));
        $this->assertSame($app, $app->addResponder('test2'));
        $this->assertEquals(['test1', 'test2'], $app->toArray()['userResponders']);
    }

    function testOrderResponders() {
        $app = new App;
        $this->assertSame($app, $app->orderResponders(['test1', 'test2']));
        $this->assertEquals(['test1', 'test2'], $app->toArray()['responderOrder']);
    }

    /**
     * @expectedException \Aerys\Framework\ConfigException
     */
    function testAddRouteClassThrowsOnNonexistentClass() {
        $app = new App;
        $app->addRouteClass('/uri', 'SomeNonexistentClass');
    }

    /**
     * @expectedException \Aerys\Framework\ConfigException
     */
    function testAddRouteClassThrowsOnNonexistentMethodsInMap() {
        $app = new App;
        $map = [
            'GET' => 'get',
            'POST' => 'doesntExist'
        ];
        $app->addRouteClass('/uri', 'Aerys\Test\Framework\AppTestRouteClassFixture', $map);
    }

    /**
     * @expectedException \Aerys\Framework\ConfigException
     */
    function testAddRouteClassThrowsIfNoNonMagicMethodsExist() {
        $app = new App;
        $app->addRouteClass('/uri', 'Aerys\Test\Framework\AppTestRouteClassNoMethodsFixture');
    }

    function testAddRouteClassIgnoresMagicMethods() {
        $app = new App;
        $app->addRouteClass('/uri', 'Aerys\Test\Framework\AppTestRouteClassFixture');

        $routes = $app->toArray()['routes'];

        $this->assertEquals([
            ['GET', '/uri', 'Aerys\Test\Framework\AppTestRouteClassFixture::get'],
            ['POST', '/uri', 'Aerys\Test\Framework\AppTestRouteClassFixture::post'],
            ['ZANZIBAR', '/uri', 'Aerys\Test\Framework\AppTestRouteClassFixture::zanzibar'],
        ], $routes);
    }

    function testAddRouteClassUsesOnlyMappedMethodsIfSpecified() {
        $app = new App;
        $app->addRouteClass('/uri', 'Aerys\Test\Framework\AppTestRouteClassFixture', $map = [
            'GET' => 'zanzibar'
        ]);

        $routes = $app->toArray()['routes'];

        $this->assertEquals([
            ['GET', '/uri', 'Aerys\Test\Framework\AppTestRouteClassFixture::zanzibar']
        ], $routes);
    }

}
