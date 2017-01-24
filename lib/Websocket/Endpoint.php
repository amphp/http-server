<?php

namespace Aerys\Websocket;

use AsyncInterop\Promise;

interface Endpoint {
    /**
     * Send a UTF-8 text message to the given client(s).
     *
     * @param int|array|null $clientId Single client ID to send data to, an array of client IDs, or null for all clients.
     * @param string $data Data to send.
     *
     * @return \AsyncInterop\Promise<int>
     */
    public function send(/* int|null|array */ $clientId, string $data): Promise;

    /**
     * Send a binary message to the given client(s).
     *
     * @param int|array|null $clientId Single client ID to send data to, an array of client IDs, or null for all clients.
     * @param string $data Data to send.
     *
     * @return \AsyncInterop\Promise<int>
     */
    public function sendBinary(/* int|null|array */ $clientId, string $data): Promise;

    /**
     * Close the client connection with a code and UTF-8 string reason.
     *
     * @param int $clientId
     * @param int $code
     * @param string $reason
     *
     * @return Promise
     */
    public function close(int $clientId, int $code = Code::NORMAL_CLOSE, string $reason = ""): Promise;

    /**
     * @param int $clientId
     *
     * @return array [
     *     'bytes_read'        => int,
     *     'bytes_sent'        => int,
     *     'frames_read'       => int,
     *     'frames_sent'       => int,
     *     'messages_read'     => int,
     *     'messages_sent'     => int,
     *     'connected_at'      => int,
     *     'closed_at'         => int,
     *     'last_read_at'      => int,
     *     'last_send_at'      => int,
     *     'last_data_read_at' => int,
     *     'last_data_sent_at' => int,
     * ]
     */
    public function getInfo(int $clientId): array;
    
    /**
     * @return int[] Array of client IDs.
     */
    public function getClients(): array;
}
