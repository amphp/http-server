<?php

use Aerys\Handlers\DocRoot\DocRootHandler,
    Alert\NativeReactor;

class StaticFileRelativePathAscensionTest extends PHPUnit_Framework_TestCase {
    
    function provideDangerousRelativeRequestUris() {
        return [
            ['/../test.txt'],
            ['/../'],
            ['/../empty_dir/'],
            ['/../static_handler_root2/']
        ];
    }
    
    /**
     * @dataProvider provideDangerousRelativeRequestUris
     */
    function testRelativePathCantAscendPastDocumentRoot($requestUri) {
        $this->markTestSkipped();
        $docRoot = FIXTURE_DIR . '/vfs/static_handler_root';
        $reactor = new NativeReactor;
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

