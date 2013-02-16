<?php

namespace Aerys\Mods;

use Aerys\Server;

/**
 * mod.expect
 * 
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
 *     'mod.expect' => [
 *         '/resource/accepting/uploaded/data' => $expectationValidator,
 *         '/some/other/resource' => $someOtherCallable
 *     ]
 * ]
 * ```
 */
class Expect implements OnRequestMod {
    
    private $callbacks = [];
    private static $response100 = [100, 'Continue', [], NULL];
    private static $response417 = [417, 'Expectation Failed', [], NULL];
    
    /**
     * Invoked at server instantiation with the relevant host's `mod.expect` configuration
     * 
     * @param array $config The mod.expect configuration array
     * @return void
     */
    function configure(array $config) {
        if (empty($config)) {
            throw new \DomainException(
                'No rate expectation validation callbacks specified'
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
    
    /**
     * Assigns an appropriate response for requests providing a 100-continue expectation
     * 
     * @param \Aerys\Server $server
     * @param int $requestId
     * @return void
     */
    function onRequest(Server $server, $requestId) {
        $asgiEnv = $server->getRequest($requestId);
        
        if (!isset($asgiEnv['HTTP_EXPECT'])) {
            return;
        }
        
        if (strcasecmp($asgiEnv['HTTP_EXPECT'], '100-continue')) {
            return;
        }
        
        // Use this concatenation because the REQUEST_URI key may have query parameters
        if (!$requestUri = ($asgiEnv['PATH_INFO'] . $asgiEnv['SCRIPT_NAME'])) {
            $requestUri = '/';
        }
        
        if (!isset($this->callbacks[$requestUri])) {
            return $server->setResponse($requestId, self::$response100);
        }
        
        $userCallback = $this->callbacks[$requestUri];
        
        if ($userCallback($asgiEnv)) {
            $server->setResponse($requestId, self::$response100);
        } else {
            $server->setResponse($requestId, self::$response417);
        }
    }
    
}

