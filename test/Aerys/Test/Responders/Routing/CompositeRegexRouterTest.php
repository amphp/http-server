<?php
 
namespace Aerys\Test\Responders\Routing;
 
use Aerys\Responders\Routing\CompositeRegexRouter;
 
class CompositeRegexRouterTest extends RouterTest {
 
    function makeRouter() {
        return new CompositeRegexRouter();
    }
 
}
