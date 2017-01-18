<?php

namespace Aerys\Internal;

use Amp\InvalidYieldError;

function validateFilterHeaders(\Generator $generator, array $headers) {
    if (!isset($headers[":status"])) {
        throw new InvalidYieldError(
            $generator,
            "Missing :status key in yielded filter array"
        );
    }
    if (!is_int($headers[":status"])) {
        throw new InvalidYieldError(
            $generator,
            "Non-integer :status key in yielded filter array"
        );
    }
    if ($headers[":status"] < 100 || $headers[":status"] > 599) {
        throw new InvalidYieldError(
            $generator,
            ":status value must be in the range 100..599 in yielded filter array"
        );
    }
    if (isset($headers[":reason"]) && !is_string($headers[":reason"])) {
        throw new InvalidYieldError(
            $generator,
            "Non-string :reason value in yielded filter array"
        );
    }

    foreach ($headers as $headerField => $headerArray) {
        if (!is_string($headerField)) {
            throw new InvalidYieldError(
                $generator,
                "Invalid numeric header field index in yielded filter array"
            );
        }
        if ($headerField[0] === ":") {
            continue;
        }
        if (!is_array($headerArray)) {
            throw new InvalidYieldError(
                $generator,
                "Invalid non-array header entry at key {$headerField} in yielded filter array"
            );
        }
        foreach ($headerArray as $key => $headerValue) {
            if (!is_scalar($headerValue)) {
                throw new InvalidYieldError(
                    $generator,
                    "Invalid non-scalar header value at index {$key} of " .
                    "{$headerField} array in yielded filter array"
                );
            }
        }
    }

    return true;
}
