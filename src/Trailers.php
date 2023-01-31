<?php declare(strict_types=1);

namespace Amp\Http\Server;

use Amp\Cancellation;
use Amp\Future;
use Amp\Http\HttpMessage;
use Amp\Http\InvalidHeaderException;
use function Amp\async;

final class Trailers
{
    /** @see https://tools.ietf.org/html/rfc7230#section-4.1.2 */
    public const DISALLOWED_TRAILERS = [
        "authorization" => true,
        "content-encoding" => true,
        "content-length" => true,
        "content-range" => true,
        "content-type" => true,
        "cookie" => true,
        "expect" => true,
        "host" => true,
        "pragma" => true,
        "proxy-authenticate" => true,
        "proxy-authorization" => true,
        "range" => true,
        "te" => true,
        "trailer" => true,
        "transfer-encoding" => true,
        "www-authenticate" => true,
    ];

    /** @var list<string> */
    private readonly array $fields;

    /** @var Future<HttpMessage> */
    private readonly Future $messageFuture;

    /**
     * @param Future<array<non-empty-string, string|array<string>>> $future Resolved with the trailer values.
     * @param string[] $fields Expected header fields. May be empty, but if provided, the array of
     *     headers used to complete the given future must contain exactly the fields given in this array.
     *
     * @throws InvalidHeaderException If the fields list contains a disallowed field.
     */
    public function __construct(Future $future, array $fields = [])
    {
        $this->fields = $fields = \array_map('strtolower', \array_values($fields));

        foreach ($this->fields as $field) {
            if (isset(self::DISALLOWED_TRAILERS[$field])) {
                throw new InvalidHeaderException(\sprintf("Field '%s' is not allowed in trailers", $field));
            }
        }

        $this->messageFuture = async(static function () use ($future, $fields): HttpMessage {
            return new class($future->await(), $fields) extends HttpMessage {
                public function __construct(array $headers, array $fields)
                {
                    $this->setHeaders($headers);

                    $keys = \array_keys($this->getHeaders());

                    if (!empty($fields)) {
                        // Note that the Trailer header does not need to be set for the message to include trailers.
                        // @see https://tools.ietf.org/html/rfc7230#section-4.4

                        if (\array_diff($fields, $keys)) {
                            throw new InvalidHeaderException("Trailers do not contain the expected fields");
                        }

                        return; // Check below unnecessary if fields list is set.
                    }

                    foreach ($keys as $field) {
                        if (isset(Trailers::DISALLOWED_TRAILERS[$field])) {
                            throw new InvalidHeaderException(\sprintf("Field '%s' is not allowed in trailers", $field));
                        }
                    }
                }
            };
        });

        // Future may fail due to client disconnect or error, but we don't want to force awaiting.
        $this->messageFuture->ignore();
    }

    /**
     * @return list<string> List of expected trailer fields. May be empty, but still receive trailers.
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function await(?Cancellation $cancellation = null): HttpMessage
    {
        return $this->messageFuture->await($cancellation);
    }
}
