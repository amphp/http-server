<?php

namespace Aerys;

class HostBinder {
    private $socketBacklogSize = 128;

    /**
     * Bind server sockets for all hosts in the collection
     *
     * We allow an optional array of existing sockets to reuse when binding to avoid bind errors
     * when reloading configuration in environments without access to SO_REUSEPORT.
     *
     * @param \Aerys\HostCollection $hosts
     * @param array $existingSockets An array of previously bound sockets to reuse
     * @throws \LogicException if no hosts exist in the specified collection
     * @throws \RuntimeException on socket bind failure
     * @return array Returns an array of bound sockets mapped by address
     */
    public function bindHosts(HostCollection $hosts, array $existingSockets = []) {
        if (!$hosts->count()) {
            throw new \LogicException(
                'Cannot bind sockets: no hosts added'
            );
        }

        return $this->doAddressBind($hosts->getBindableAddresses(), $existingSockets);
    }

    private function doAddressBind(array $bindAddresses, array $existingSockets) {
        $bindAddresses = array_unique($bindAddresses);
        $boundAddresses = [];
        $context = stream_context_create(['socket' => ['backlog' => $this->socketBacklogSize]]);

        foreach ($bindAddresses as $address) {
            if (!isset($existingSockets[$address])) {
                $existingSockets[$address] = $this->bindSocket($address, $context);
            }
            $boundAddresses[] = $address;
        }

        return array_intersect_key($existingSockets, array_flip($boundAddresses));
    }

    private function bindSocket($address, $context) {
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $server = @stream_socket_server($address, $errno, $errstr, $flags, $context);

        if (!$server) {
            throw new \RuntimeException(
                sprintf('Failed binding socket on %s: [Err# %s] %s', $address, $errno, $errstr)
            );
        }

        return $server;
    }

    /**
     * Bind server sockets for each address
     *
     * @param array $bindAddresses An array of bind URIs of the form "tcp://ip:port"
     * @param array $existingSockets An array of previously bound sockets to reuse
     * @return array Returns an array of bound sockets mapped by address
     */
    public function bindAddresses(array $bindAddresses, array $existingSockets = []) {
        if (!$bindAddresses) {
            throw new \LogicException(
                'Cannot bind sockets: no addresses specified'
            );
        }

        return $this->doAddressBind($bindAddresses, $existingSockets);
    }

    /**
     * How many pending client connections may be queued for acceptance before we start rejecting new clients?
     *
     * @return int
     */
    public function getSocketBacklogSize() {
        return $this->socketBacklogSize;
    }

    /**
     * Define how many pending client connections may be queued before we start rejecting new ones
     *
     * @param int $size The number of allowed clients awaiting acceptance in the queue
     * @return int Returns the assigned backlog size
     */
    public function setSocketBacklogSize($size) {
        $size = filter_var($size, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 128
        ]]);

        $this->socketBacklogSize = $size;

        return $size;
    }
}
