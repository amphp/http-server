<?php

namespace Aerys;

class GeneratorResolver {

    function resolve(\Generator $generator, callable $onResult, $userId = NULL) {
        $key = $generator->key();
        $value = $generator->current();

        if ($value instanceof \Generator) {
            $this->resolve($value, function($result) use ($onResult, $userId) {
                $onResult($result, $userId);
            });
        } elseif (is_callable($key)) {
            $value = is_array($value) ? $value : [$value];
            array_push($value, function($result) use ($generator, $onResult, $userId) {
                $this->sendResult($generator, $result, $onResult, $userId);
            });
            $this->trigger($generator, $key, $value, $onResult);
        } elseif ($value && is_array($value) && ($group = $this->buildGroup($generator, $value))) {
            $this->triggerGroup($generator, $group, $onResult, $userId);
        } else {
            $onResult($value, $userId);
        }
    }

    private function trigger(\Generator $generator, callable $action, array $args, callable $onResult) {
        try {
            call_user_func_array($action, $args);
        } catch (\Exception $e) {
            $generator->throw($e);
            $this->resolve($generator, $onResult, $userId);
        }
    }

    private function sendResult(\Generator $generator, $result, callable $onResult, $userId) {
        try {
            $generator->send($result);
        } catch (\Exception $e) {
            $generator->throw($e);
        } finally {
            $this->resolve($generator, $onResult, $userId);
        }
    }

    private function buildGroup(\Generator $generator, array $groupCandidate) {
        $yieldGroup = [];

        foreach ($groupCandidate as $groupIndex => $definitionArr) {
            if (!($definitionArr && is_array($definitionArr))) {
                return FALSE;
            }

            $key = array_shift($definitionArr);
            if (is_callable($key)) {
                $yieldGroup[$groupIndex] = [$key, $definitionArr];
            } else {
                return FALSE;
            }
        }

        return $yieldGroup;
    }

    private function sendGroupResult(\Generator $generator, $result, $groupIndex, $onResult, $userId, \StdClass $state) {
        $state->groupResults[$groupIndex] = $result;

        if (count($state->groupResults) === $state->count) {
            $this->sendResult($generator, $state->groupResults, $onResult, $userId);
        }
    }

    private function triggerGroup(\Generator $generator, array $group, callable $onResult, $userId) {
        try {
            $state = new \StdClass;
            $state->count = count($group);
            $state->groupResults = [];

            foreach ($group as $groupIndex => $groupArr) {
                list($callable, $args) = $groupArr;
                $relayer = function($result) use ($generator, $groupIndex, $onResult, $userId, $state) {
                    $this->sendGroupResult($generator, $result, $groupIndex, $onResult, $userId, $state);
                };
                array_push($args, $relayer);
                $result = call_user_func_array($callable, $args);

                if ($result instanceof \Generator) {
                    $this->resolve($result, function($result) use ($relayer, $userId) {
                        $relayer($result, $userId);
                    });
                }
            }
        } catch (\Exception $e) {
            $generator->throw($e);
            $this->resolve($generator, $onResult, $userId);
        }
    }

}
