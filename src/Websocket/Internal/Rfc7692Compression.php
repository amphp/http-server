<?php

namespace Amp\Http\Server\Websocket\Internal;

class Rfc7692Compression {
    const RSV = 0b100;
    const DEFAULT_WINDOW_SIZE = 15;
    const EMPTY_BLOCK = "\x0\x0\xff\xff";

    /** @var resource */
    private $deflate;

    /** @var resource */
    private $inflate;

    private $serverWindowSize;
    private $clientWindowSize;

    private $serverContextTakeover;
    private $clientContextTakeover;

    /**
     * @param string $headerIn Header from request.
     * @param string $headerOut Sec-Websocket-Extension response header.
     *
     * @return \Amp\Http\Server\Websocket\Internal\Rfc7692Compression|null
     */
    public static function fromHeader(string $headerIn, string &$headerOut = null) { /* : ?self */
        $headerIn = \explode(';', \strtolower($headerIn));
        $headerIn = \array_map("trim", $headerIn);

        if (!\in_array("permessage-deflate", $headerIn, true)) {
            return null;
        }

        $serverWindowSize = self::DEFAULT_WINDOW_SIZE;
        $clientWindowSize = self::DEFAULT_WINDOW_SIZE;
        $serverContextTakeover = true;
        $clientContextTakeover = true;

        $headers = [];
        $headerOut = "permessage-deflate";

        foreach ($headerIn as $param) {
            $parts = \explode('=', $param, 2);

            if (in_array($parts[0], $headers, true)) {
                return null; // Repeat params in header.
            }

            $headers[] = $parts[0];

            switch ($parts[0]) {
                case "permessage-deflate":
                    break;

                case "client_max_window_bits":
                    if (!isset($parts[1])) {
                        break; // Use default value.
                    }

                    $value = (int) $parts[1];

                    if ($value <= 8 || $value >= 15) {
                        return null; // Invalid option value.
                    }

                    $clientWindowSize = $value;
                    $headerOut .= "; client_max_window_bits=" . $value;
                    break;

                case "client_no_context_takeover":
                    $clientContextTakeover = false;
                    $headerOut .= "; client_no_context_takeover";
                    break;

                case "server_max_window_bits":
                    if (!isset($parts[1])) {
                        break; // Use default value.
                    }

                    $value = (int) $parts[1];

                    if ($value <= 9 || $value >= 15) { // Window of 8 fails deflate_init()
                        return null; // Invalid option value.
                    }

                    $serverWindowSize = $value;
                    $headerOut .= "; server_max_window_bits=" . $value;
                    break;

                case "server_no_context_takeover":
                    $serverContextTakeover = false;
                    $headerOut .= "; server_no_context_takeover";
                    break;

                default:
                    return null; // Unrecognized option; do not accept extension request.
            }
        }

        return new self($clientWindowSize, $serverWindowSize, $clientContextTakeover, $serverContextTakeover);
    }

    private function __construct(
        int $clientWindowSize,
        int $serverWindowSize,
        bool $clientContextTakeover,
        bool $serverContextTakeover
    ) {
        $this->clientWindowSize = $clientWindowSize;
        $this->serverWindowSize = $serverWindowSize;
        $this->clientContextTakeover = $clientContextTakeover;
        $this->serverContextTakeover = $serverContextTakeover;

        if (($this->inflate = \inflate_init(\ZLIB_ENCODING_RAW, ['window' => $this->clientWindowSize])) === false) {
            throw new \RuntimeException("Failed initializing inflate context");
        }

        if (($this->deflate = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => $this->serverWindowSize])) === false) {
            throw new \RuntimeException("Failed initializing deflate context");
        }
    }

    public function decompress(string $data) { /* : ?string */
        $data = \inflate_add(
            $this->inflate,
            $data . self::EMPTY_BLOCK,
            $this->clientContextTakeover ? \ZLIB_SYNC_FLUSH : \ZLIB_FULL_FLUSH
        );

        if (false === $data) {
            return null;
        }

        return $data;
    }

    public function compress(string $data): string {
        $data = \deflate_add($this->deflate, $data, $this->serverContextTakeover ? \ZLIB_SYNC_FLUSH : \ZLIB_FULL_FLUSH);
        if ($data === false) {
            throw new \RuntimeException("Failed to compress data");
        }

        // @TODO Is this always true?
        if (\substr($data, -4) === self::EMPTY_BLOCK) {
            $data = \substr($data, 0, -4);
        }

        return $data;
    }
}
