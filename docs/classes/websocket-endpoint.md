---
title: Websocket\Endpoint
permalink: /classes/websocket-endpoint
---

* Table of Contents
{:toc}

The `Websocket\Endpoint` interface is the door to communicating with the client. `$clientId` is here in every case the client identifier passed in via [`Websocket` interface functions](websocket.html).

## `send(string $data, int $clientId): Promise`

Sends UTF-8 compatible data to a given client.

The Promise will be fulfilled when the internal buffers aren't too saturated. Yielding these promises is a good way to prevent too much data pending in memory.

## `sendBinary(string $data, int $clientId): Promise`

Similar to `send()`, except for sending binary data.

## `broadcast(string $data, array $exceptIds = []): Promise`

Sends UTF-8 compatible data to all clients except those given as second argument.

The Promise will be fulfilled when the internal buffers aren't too saturated. Yielding these promises is a good way to prevent too much data pending in memory.

## `boardcastBinary(string $data, array $exceptIds = []): Promise`

Similar to `broadcast()`, except for sending binary data.

## `multicast(string $data, array $clientIds): Promise`

Sends UTF-8 compatible data to a given set of clients.

The Promise will be fulfilled when the internal buffers aren't too saturated. Yielding these promises is a good way to prevent too much data pending in memory.

## `sendMulticast(string $data, int $clientId): Promise`

Similar to `multicast()`, except for sending binary data.

## `close(int $clientId, int $code = Websocket\Code::NORMAL_CLOSE, string $reason = "")`

Closes the websocket connection to a `$clientId` with a `$code` and a `$reason`.

## `getInfo(int $clientId): array`

This returns an array with the following (self-explaining) keys:

- `bytes_read`
- `bytes_sent`
- `frames_read`
- `frames_sent`
- `messages_read`
- `messages_sent`
- `connected_at`
- `closed_at`
- `last_read_at`
- `last_sent_at`
- `last_data_read_at`
- `last_data_sent_at`

The values are all integers. Keys ending in `_at` all have an UNIX timestamp as value.

## `getClients(): array<int>`

Gets an array with all the client identifiers.