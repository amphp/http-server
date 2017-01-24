<?php

namespace Aerys\Websocket;

use Amp\Promise;

interface Endpoint {
    /**
     * Send a UTF-8 text message to the given client.
     *
     * @param string $data Data to send.
     * @param int $clientId
     *
     * @return Promise<int>
     */
    public function send(string $data, int $clientId): Promise;

    /**
     * Broadcast a UTF-8 text message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[] $exceptIds List of IDs to exclude from the broadcast.
     *
     * @return Promise<int>
     */
    public function broadcast(string $data, array $exceptIds = []): Promise;

    /**
     * Send a UTF-8 text message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[]|null $clientIds Array of client IDs.
     *
     * @return Promise<int>
     */
    public function multicast(string $data, array $clientIds): Promise;

    /**
     * Send a binary message to the given client(s).
     *
     * @param string $data Data to send.
     * @param int $clientId
     *
     * @return Promise<int>
     */
    public function sendBinary(string $data, int $clientId): Promise;

    /**
     * Send a binary message to all clients (except those given in the optional array).
     *
     * @param string $data Data to send.
     * @param int[] $exceptIds List of IDs to exclude from the broadcast.
     *
     * @return Promise<int>
     */
    public function broadcastBinary(string $data, array $exceptIds = []): Promise;

    /**
     * Send a binary message to a set of clients.
     *
     * @param string $data Data to send.
     * @param int[]|null $clientIds Array of client IDs.
     *
     * @return Promise<int>
     */
    public function multicastBinary(string $data, array $clientIds): Promise;

    /**
     * Close the client connection with a code and UTF-8 string reason.
     *
     * @param int $clientId
     * @param int $code
     * @param string $reason
     */
    public function close(int $clientId, int $code = Code::NORMAL_CLOSE, string $reason = "");

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

    public function getClients(): array;
}
