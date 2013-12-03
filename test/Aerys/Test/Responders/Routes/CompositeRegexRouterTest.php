<?php
 
namespace Aerys\Test\Responders\Routes;
 
use Aerys\Responders\Routes\CompositeRegexRouter;
 
class CompositeRegexRouterTest extends RouteMatcherTest {
 
    function makeRouter() {
        return new CompositeRegexRouter();
    }
 
}
