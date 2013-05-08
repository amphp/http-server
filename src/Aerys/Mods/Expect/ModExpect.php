<?php

namespace Aerys\Mods\Expect;

use Aerys\Server,
    Aerys\Status,
    Aerys\Reason,
    Aerys\Mods\OnHeadersMod;

/**
 * By default Aerys will send a `100 Continue` response immediately to any requests specifying the
 * `Expect: 100-continue` header. While handlers may be optionally invoked to respond on their
 * own, it can be easier to specify the URI-callback key-value pairs using mod.expect to validate
 * headers without involving the handler.
 * 
 * The configuration array MUST be a single associative array in which the keys are the URI paths
 * for which custom callbacks should be invoked if they contain the relevant `Expect:` header.
 * The values are valid PHP callables that will be invoked using the ASGI request environment
 * variable. If the callable returns a *thruthy* value the mod will assign a `100 Continue` response.
 * If a *falsy* value is returned the appropriate `417 Expectation Failed` message is sent to
 * the client.
 * 
 * An example configuration would look something like the following in which a 417 response is
 * returned if the requests specified no `CONTENT_LENGTH` header or the length specified is greater
 * than 1Kb:
 * 
 * ```
 * $expectationValidator = function(array $asgiEnv) {
 *     if (empty($asgiEnv['CONTENT_LENGTH']) || $asgiEnv['CONTENT-LENGTH'] > 1024) {
 *         return FALSE;
 *     } else {
 *         return TRUE;
 *     }
 * };
 * 
 * // ... AND IN YOUR CONFIG:
 * 
 * 'mods' => [
 *     'expect' => [
 *         '/resource/accepting/uploaded/data' => $expectationValidator,
 *         '/some/other/resource' => $someOtherCallable
 *     ]
 * ]
 * ```
 */
class ModExpect implements OnHeadersMod {
    
    private $httpServer;
    private $callbacks = [];
    private $onHeadersPriority = 50;
    
    private $response100 = [
        Status::CONTINUE_100,
        Reason::HTTP_100,
        [],
        NULL
    ];
    
    private $response417 = [
        Status::EXPECTATION_FAILED,
        Reason::HTTP_417,
        [],
        NULL
    ];
    
    function __construct(Server $httpServer, array $config) {
        $this->httpServer = $httpServer;
        
        if (empty($config)) {
            throw new \UnexpectedValueException(
                'No expectation callbacks specified'
            );
        }
        
        foreach ($config as $uri => $callback) {
            if (!is_callable($callback)) {
                throw new \InvalidArgumentException(
                    "Value specified at index $uri must be callable"
                );
            }
            
            $this->callbacks[$uri] = $callback;
        }
    }
    
    function getOnHeadersPriority() {
        return $this->onHeadersPriority;
    }
    
    /**
     * Assign an appropriate response for `Expect: 100-continue` requests based on the boolean
     * return value of a user-specified callback
     * 
     * @param int $requestId
     * @return void
     */
    function onHeaders($requestId) {
        $asgiEnv = $this->httpServer->getRequest($requestId);
        
        if (!isset($asgiEnv['HTTP_EXPECT'])) {
            return;
        }
        
        if (strcasecmp($asgiEnv['HTTP_EXPECT'], '100-continue')) {
            return;
        }
        
        $requestUriPath = str_replace($asgiEnv['QUERY_STRING'], '', $asgiEnv['REQUEST_URI']);
        
        if (!isset($this->callbacks[$requestUriPath])) {
            return $this->httpServer->setResponse($requestId, $this->response100);
        }
        
        $userCallback = $this->callbacks[$requestUriPath];
        
        if ($userCallback($asgiEnv)) {
            $this->httpServer->setResponse($requestId, $this->response100);
        } else {
            $this->httpServer->setResponse($requestId, $this->response417);
        }
    }
    
}

