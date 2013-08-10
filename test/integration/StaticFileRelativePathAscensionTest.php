<?php

use Aerys\Handlers\DocRoot\DocRootHandler,
    Alert\ReactorFactory;

class StaticFileRelativePathAscensionTest extends PHPUnit_Framework_TestCase {
    
    function provideDangerousRelativeRequestUris() {
        $return = [];
        
        $return[] = ['/../test.txt'];
        $return[] = ['/../'];
        $return[] = ['/../empty_dir/'];
        $return[] = ['/../static_handler_root2/'];
        
        return $return;
    }
    
    /**
     * @dataProvider provideDangerousRelativeRequestUris
     */
    function testRelativePathCantAscendPastDocumentRoot($requestUri) {
        $docRoot = FIXTURE_DIR . '/vfs/static_handler_root';
        $reactor = (new ReactorFactory)->select();
        $handler = new DocRootHandler($reactor, $docRoot);
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $requestUri
        ];
        $expectedCode = 404;
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $statusCode = $asgiResponse[0];
        
        $this->assertEquals($expectedCode, $statusCode);
    }
    
}

