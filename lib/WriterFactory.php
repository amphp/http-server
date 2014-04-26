<?php

namespace Aerys;

use Alert\Reactor;

class WriterFactory {
    private $reactor;

    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
    }

    public function make($bodyType, $mustClose) {
        if ($bodyType === Writer::BODY_STRING) {
            return new StringWriter($this->reactor);
        } elseif ($bodyType === Writer::BODY_GENERATOR) {
            return $mustClose
                ? new GeneratorWriter($this->reactor)
                : new GeneratorWriterChunked($this->reactor);
        } else {
            throw new \UnexpectedValueException(
                sprintf('Unexpected writer body type: %s', $bodyType)
            );
        }
    }
}
