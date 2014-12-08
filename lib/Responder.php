<?php

namespace Aerys;

/**
 * Responder Interface
 *
 * When the Server invokes a Host application callable it expects a Responder instance in return.
 * The Responder instance is subsequently "prepared" with all the information necessary to output
 * a response to the client subject to current Server settings. At some point after this the server
 * signals the Responder to write its response to the client via Responder::assumeSocketControl().
 * When a Responder instance completes its output it must then invoke Server::resumeSocketControl()
 * parameters indicating information about the written response and whether or not the server should
 * close the associated client socket connection.
 *
 * @package Aerys
 */
interface Responder {
    /**
     * Prepare the Responder for client output
     *
     * The Server prepares Responders by passing an environment struct with all relevant
     * data needed to write a response to the requesting client. Responders MUST NOT perform
     * any actual writes to the client socket referenced in the environment struct until
     * Responder::assumeSocketControl() is invoked. Violating this rule *WILL* break HTTP/1.1
     * response pipelining -- don't do it!
     *
     * By separating the prepare() step from assumeSocketControl() we allow responders to generate
     * multiple responses concurrently regardless of HTTP/1.1 pipelining order.
     *
     * @param ResponderEnvironment $env
     */
    public function prepare(ResponderEnvironment $env);

    /**
     * Assume control of the client socket and output the prepared response
     *
     * This method's invocation is the server's signal to the Responder that it should output its
     * response to the client referenced in the ResponderEnvironment passed to Responder::prepare().
     * Once the response is written the Responder must invoke Server::resumeSocketControl() to
     * return control of the client socket to the server for further processing.
     */
    public function assumeSocketControl();

    /**
     * Client socket IO writability watcher callback
     *
     * Socket writability watchers are bound to the current request pipeline responder's write()
     * method when new clients connect to the server. By exposing this method the server is able to
     * reuse a single IO writability watcher for all write events across the life of the connection.
     * This is vastly preferable to potentially incurring new watcher overhead each time a response
     * is written to the client socket.
     *
     * In the event of an incomplete buffer write responders need only enable the
     * ResponderEnvironment::$writeWatcher and this method will be invoked when the client socket
     * reports as writable.
     */
    public function write();
}
