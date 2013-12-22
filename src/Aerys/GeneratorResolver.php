<?php

namespace Aerys;

class GeneratorResolver {

    function resolve(\Generator $generator, callable $onResult, $userData = NULL) {
        $key = $generator->key();
        $value = $generator->current();

        $isArrayValue = is_array($value);

        if ($value instanceof \Generator) {
            $this->resolve($value, function($error, $result, $userData) use ($onResult) {
                $onResult($error, $result, $userData);
            }, $userData);
        } elseif ($isArrayValue && is_callable($key)) {
            array_push($value, function($result) use ($generator, $onResult, $userData) {
                $this->sendResult($generator, $result, $onResult, $userData);
            });
            $this->trigger($generator, $key, $value, $onResult, $userData);
        } elseif ($value && $isArrayValue && ($group = $this->buildGroup($generator, $value))) {
            $this->triggerGroup($generator, $group, $onResult, $userData);
        } else {
            $onResult($error = NULL, $value, $userData);
        }
    }

    private function trigger(\Generator $generator, callable $action, array $args, callable $onResult, $userData) {
        try {
            call_user_func_array($action, $args);
        } catch (\Exception $error) {
            $this->resolveError($generator, $onResult, $userData, $error);
        }
    }

    private function sendResult(\Generator $generator, $result, callable $onResult, $userData) {
        try {
            $generator->send($result);
            $this->resolve($generator, $onResult, $userData);
        } catch (\Exception $e) {
            $this->resolveError($generator, $onResult, $userData, $error);
        }
    }

    private function resolveError($generator, $onResult, $userData, $error) {
        try {
            $generator->throw($error);
        } catch (\Exception $error) {
            if ($generator->valid()) {
                $this->resolveError($generator, $onResult, $userData, $error);
            } else {
                $onResult($error, $result = NULL, $userData);
            }
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

    private function sendGroupResult(\Generator $generator, $result, $groupIndex, $onResult, $userData, \StdClass $state) {
        $state->groupResults[$groupIndex] = $result;

        if (count($state->groupResults) === $state->count) {
            $this->sendResult($generator, $state->groupResults, $onResult, $userData);
        }
    }

    private function triggerGroup(\Generator $generator, array $group, callable $onResult, $userData) {
        $state = new \StdClass;
        $state->count = count($group);
        $state->groupResults = [];

        foreach ($group as $groupIndex => $groupArr) {
            list($callable, $args) = $groupArr;
            $relayer = function($result) use ($generator, $groupIndex, $onResult, $userData, $state) {
                $this->sendGroupResult($generator, $result, $groupIndex, $onResult, $userData, $state);
            };
            array_push($args, $relayer);
            $result = call_user_func_array($callable, $args);

            if ($result instanceof \Generator) {
                $this->resolve($result, function($result) use ($relayer, $userData) {
                    $relayer($result, $userData);
                });
            }
        }
    }

}
