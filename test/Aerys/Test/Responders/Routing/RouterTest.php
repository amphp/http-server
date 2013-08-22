<?php
 
namespace Aerys\Test\Responders\Routing;
 
use Aerys\Responders\Routing\Router;
 
abstract class RouterTest extends \PHPUnit_Framework_TestCase {
 
    function testRoute() {
        $method = 'GET';
        $route = '/resource/123/456';
        $handler = [__CLASS__, 'handler'];
 
        $router = $this->makeRouter();
        $router->addRoute($method, $route, $handler);
        $result = $router->matchRoute($method, $route);
 
        $this->assertEquals($router::MATCHED, $result[0]);
        $this->assertEquals($handler, $result[1]);
        $this->assertEquals([], $result[2]);
    }
 
    function testRuleMethodNotAllowed() {
        $router = $this->makeRouter();
 
        $method = 'GET';
        $route = '/resource/123/456';
        $rule = '/resource/$#id/456';
        $handler = [__CLASS__, 'handler'];
 
        $router->addRoute($method, $rule, $handler);
 
        $method = 'PUT';
        $handler = [__CLASS__, 'handler2'];
 
        $router->addRoute($method, $rule, $handler);
 
        $result = $router->matchRoute('POST', $route);
        $this->assertEquals([$router::METHOD_NOT_ALLOWED, ['GET', 'PUT']], $result);
    }
 
    function testRuleNotFound() {
        $method = 'GET';
        $route = '/resource/does/not/exist';
        $rule = '/resource/$#id/456';
        $handler = [__CLASS__, 'handler'];
 
        $router = $this->makeRouter();
        $router->addRoute($method, $rule, $handler);
        $result = $router->matchRoute($method, $route);
        $this->assertEquals($router::NOT_FOUND, $result[0]);
 
    }
 
    function testRuleNotFoundWithoutArgumentPattern() {
        $method = 'GET';
        $route = '/resource/does/not/exist';
        $rule = '/resource/that/actually/does/exist';
        $handler = [__CLASS__, 'handler'];
 
        $router = $this->makeRouter();
        $router->addRoute($method, $rule, $handler);
        $result = $router->matchRoute($method, $route);
        $this->assertEquals($router::NOT_FOUND, $result[0]);
 
    }
 
    function testMultipleRules() {
        $method = 'GET';
        $routeA = '/resource/1234';
        $ruleA =  '/resource/$#id';
        $routeB = '/resource/abcdef';
        $ruleB =  '/resource/$param';
        $resultA = ['id' => '1234'];
        $resultB = ['param' => 'abcdef'];
        $handler = [__CLASS__, 'handler'];
 
        $router = $this->makeRouter();
        $router->addRoute($method, $ruleA, $handler);
        $router->addRoute($method, $ruleB, $handler);
 
        $result = $router->matchRoute($method, $routeA);
        $this->assertEquals($router::MATCHED, $result[0]);
        $this->assertEquals($handler, $result[1]);
        $this->assertEquals($resultA, $result[2]);
 
        $result = $router->matchRoute($method, $routeB);
        $this->assertEquals($router::MATCHED, $result[0]);
        $this->assertEquals($handler, $result[1]);
        $this->assertEquals($resultB, $result[2]);
    }
 
    /**
     * @return Router
     */
    abstract function makeRouter();
 
    function provideCases() {
        return [
            ['GET', '/123', '/$#id', [__CLASS__, 'handler'], ['id' => '123']],
            ['GET', '/resource/123', '/resource/$#id', [__CLASS__, 'handler'], ['id' => '123']],
            ['GET', '/resource/123/456', '/resource/$#id/456', [__CLASS__, 'handler'], ['id' => '123']],
            ['GET', '/resource/123/456', '/resource/123/$#id', [__CLASS__, 'handler'], ['id' => '456']],
 
            ['GET', '/abc', '/$param', [__CLASS__, 'handler'], ['param' => 'abc']],
            ['GET', '/resource/abc', '/resource/$param', [__CLASS__, 'handler'], ['param' => 'abc']],
            ['GET', '/resource/abc/def', '/resource/$param/def', [__CLASS__, 'handler'], ['param' => 'abc']],
            ['GET', '/resource/abc/def', '/resource/abc/$param', [__CLASS__, 'handler'], ['param' => 'def']],
 
            ['GET', '/resource/123/def', '/resource/$#id/$param', [__CLASS__, 'handler'], ['id' => '123', 'param' => 'def']],
            ['GET', '/resource/123/def/extra', '/resource/$#id/$param/extra', [__CLASS__, 'handler'], ['id' => '123', 'param' => 'def']],
        ];
    }
 
    /**
     * @dataProvider provideCases
     */
    function testRules($method, $route, $rule, $handler, $params) {
        $router = $this->makeRouter();
        $router->addRoute($method, $rule, $handler);
        $result = $router->matchRoute($method, $route);
 
        $this->assertEquals($router::MATCHED, $result[0]);
        $this->assertEquals($handler, $result[1]);
        $this->assertEquals($params, $result[2]);
    }
 
    /**
     * @dataProvider provideDuplicateParameterTests
     */
    function testDuplicateParameterExceptions($method, $rule, callable $handler, $param) {
        $this->setExpectedException(
            '\Aerys\Responders\Routing\BadRouteException',
            sprintf(Router::E_DUPLICATE_PARAMETER_STR, $param),
            Router::E_DUPLICATE_PARAMETER_CODE
        );
        $router = $this->makeRouter();
        $router->addRoute($method, $rule, $handler);
    }
 
    /**
     * @dataProvider provideRequiresIdentifierTests
     */
    function testRequiresIdentifierExceptions($method, $rule, callable $handler, $param) {
        $this->setExpectedException(
            '\Aerys\Responders\Routing\BadRouteException',
            sprintf(Router::E_REQUIRES_IDENTIFIER_STR, $param),
            Router::E_REQUIRES_IDENTIFIER_CODE
        );
        $router = $this->makeRouter();
        $router->addRoute($method, $rule, $handler);
    }
 
    function provideRequiresIdentifierTests() {
        return [
            '$ requires identifier'  => ['GET',     '/$',       [__CLASS__, 'handler'], '$'],
            '$# requires identifier' => ['GET',     '/$#',      [__CLASS__, 'handler'], '$#'],
        ];
    }
    function provideDuplicateParameterTests() {
        return [
            '$p used twice'          => ['GET',     '/$p/$p',   [__CLASS__, 'handler'], 'p'],
            '$#p used twice'         => ['GET',     '/$#p/$#p', [__CLASS__, 'handler'], 'p'],
            '$p and $#p both used'   => ['GET',     '/$p/$#p',  [__CLASS__, 'handler'], 'p'],
        ];
    }
 
    static function handler() {
 
    }
 
    static function handler2() {
 
    }
 
}
