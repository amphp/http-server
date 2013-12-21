<?php

use Aerys\GeneratorResolver;

function resolver_throws1($onCompletion) {
    throw new Exception('resolver_throws1');
}

function resolver_throws2($onCompletion) {
    yield (yield 'resolver_throws1' => []);
}

function resolver_throws3($onCompletion) {
    throw new Exception('resolver_throws3');
}

function resolver_do_throw1() {
    yield (yield 'resolver_throws1' => []);
}

function resolver_do_throw2() {
    yield (yield 'resolver_throws2' => []);
}

function resolver_do_throw3() {
    try {
        $result = (yield 'resolver_throws1' => []);
    } catch (\Exception $e) {
        $result = (yield 'resolver_throws3' => []);
    }
}

function resolver_do_multiply($x, $y, callable $onCompletion) {
    $multiplicationResult = $x*$y;
    $onCompletion($multiplicationResult);
}

function resolver_do_subtract($x, $y, callable $onCompletion) {
    $subtractionResult = $x - $y;
    $onCompletion($subtractionResult);
}

function resolver_multiply($x, $y) {
    $multiplicationResult = (yield 'resolver_do_multiply' => [$x, $y]);

    yield $multiplicationResult;
}

function resolver_multi_yield($x, $y) {
    $result1 = (yield 'resolver_do_multiply' => [$x, $y]);
    $result2 = (yield 'resolver_do_subtract' => [$result1, 25]);
    $result3 = (yield 'resolver_do_multiply' => [$result2, 2]);

    yield $result3;
}

function resolver_group() {
    yield (yield [
        'result1' => ['resolver_do_multiply', 6, 7],
        'result2' => ['resolver_do_subtract', 5, 3],
        'result3' => ['resolver_do_multiply', 5, 5]
    ]);
}

class GeneratorResolverTest extends PHPUnit_Framework_TestCase {

    /**
     * @dataProvider provideResolutionExpectations
     */
    function testRunExpectations($callable, $args, $expectedResult, $expectedData) {
        $resolver = new GeneratorResolver;
        $generator = call_user_func_array($callable, $args);
        $resolver->resolve($generator, function($error, $result, $data) use ($expectedResult, $expectedData) {
            $this->assertEquals($expectedResult, $result);
            $this->assertEquals($expectedData, $data);
        }, $expectedData);
    }

    function provideResolutionExpectations() {
        $return = [];

        // 0 -------------------------------------------------------------------------------------->

        $return[] = [
            $callable = 'resolver_multiply',
            $args = [$x = 6, $y = 7],
            $expectedResult = $x*$y,
            $expectedData = 'zanzibar'
        ];

        // 1 -------------------------------------------------------------------------------------->

        $return[] = [
            $callable = 'resolver_multi_yield',
            $args = [$x = 6, $y = 7],
            $expectedResult = 34,
            $expectedData = 'zanzibar'
        ];

        // 2 -------------------------------------------------------------------------------------->

        $return[] = [
            $callable = 'resolver_group',
            $args = [],
            $expectedResult = [
                'result1' => 42,
                'result2' => 2,
                'result3' => 25,
            ],
            $expectedData = 'zanzibar'
        ];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }

    /**
     * @dataProvider provideErrorExpectations
     */
    function testErrorExpectations($callable, $args, $expectedError, $expectedData) {
        $resolver = new GeneratorResolver;
        $generator = call_user_func_array($callable, $args);
        $resolver->resolve($generator, function($error, $result, $data) use ($expectedError, $expectedData) {
            $this->assertEquals($expectedError, $error->getMessage());
            $this->assertEquals($expectedData, $data);
        }, $expectedData);
    }

    function provideErrorExpectations() {
        $return = [];

        // 0 -------------------------------------------------------------------------------------->

        $return[] = [
            $callable = 'resolver_do_throw1',
            $args = [],
            $expectedError = 'resolver_throws1',
            $expectedData = 42
        ];
        
        // 1 -------------------------------------------------------------------------------------->

        $return[] = [
            $callable = 'resolver_do_throw2',
            $args = [],
            $expectedError = 'resolver_throws1',
            $expectedData = 42
        ];
        
        // 2 -------------------------------------------------------------------------------------->

        $return[] = [
            $callable = 'resolver_do_throw3',
            $args = [],
            $expectedError = 'resolver_throws3',
            $expectedData = 42
        ];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }
}