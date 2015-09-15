<?php

namespace Aerys\Test;

use Amp\Promise;
use Amp\PromiseStream;
use Aerys\Body;

class BodyTest extends \PHPUnit_Framework_TestCase {
    public function testBuffer() {
        $constraint = new \StdClass;
        $constraint->invoked = false;

        $stub = new class($constraint) extends PromiseStream {
            private $constraint;
            function __construct($constraint) {
                $this->constraint = $constraint;
            }
            function buffer(): Promise {
                $this->constraint->invoked = true;
                return parent::buffer();
            }
        };

        $body = new Body($stub);
        $this->assertInstanceOf("Amp\\Promise", $body->buffer());
        $this->assertTrue($constraint->invoked);
    }

    public function testStream() {
        $constraint = new \StdClass;
        $constraint->invoked = false;

        $stub = new class($constraint) extends PromiseStream {
            private $constraint;
            function __construct($constraint) {
                $this->constraint = $constraint;
            }
            function stream(): \Generator {
                $this->constraint->invoked = true;
                return parent::stream();
            }
        };

        $body = new Body($stub);
        $this->assertInstanceOf("Generator", $body->stream());
        $this->assertTrue($constraint->invoked);
    }
}
