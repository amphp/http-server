<?php

namespace Aerys;

use Amp\Promise;

/**
 * An interface for classes that react to Server state changes
 *
 * Upon a Server state change the subject (Server) notifies observers. These
 * observers may then invoke Server::state() to determine the new server
 * state. The following state are available:
 *
 *  - Server::STARTING
 *  - Server::STARTED
 *  - Server::STOPPING
 *  - Server::STOPPED
 *
 * ServerObserver instances have the option of returning an Amp\Promise when
 * their ServerObserver::update() methods are invoked. The Server will not
 * complete its current state until any returned observer promises resolve.
 */
interface ServerObserver extends \SplObserver {
    public function update(\SplSubject $subject): Promise;
}
