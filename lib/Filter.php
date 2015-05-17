<?php

namespace Aerys;

class Filter {
    const END = null;
    const FLUSH = false;
    const NEEDS_MORE_DATA = null;

    private $generatorFilters = [];

    /**
     * @param array[\Generator] $generatorFilters An array of generator filters
     */
    public function __construct(array $generatorFilters) {
        $this->generatorFilters = $generatorFilters;
    }

    /**
     * Sink new data into the filter chain
     *
     * @param string $data The data to filter
     * @throws \Aerys\FilterException If a filter error occurs
     * @return string Returns a filtered string (possibly empty)
     */
    public function sink(string $data): string {
        try {
            foreach ($this->generatorFilters as $key => $filter) {
                $yielded = $filter->send($data);
                if (!isset($yielded)) {
                    if ($filter->valid()) {
                        return "";
                    } elseif (($return = $filter->getReturn()) === null) {
                        unset($this->generatorFilters[$key]);
                    } elseif (is_string($return)) {
                        $data = $return;
                        unset($this->generatorFilters[$key]);
                    } else {
                        $this->throwTypeError($key, $return);
                    }
                } elseif (is_string($yielded)) {
                    $data = $yielded;
                } else {
                    $this->throwTypeError($key, $yielded);
                }
            }
            return $data;
        } catch (\BaseException $e) {
            throw new FilterException($e, $key);
        }
    }

    /**
     * Request flushing of any currently-buffered filter data
     *
     * @throws \DomainException If a filter returns anything other than a string
     * @return string
     */
    public function flush(): string {
        try {
            $data = false;
            $needsMoreData = false;
            foreach ($this->generatorFilters as $key => $filter) {
                $sentString = isset($data);
                $yielded = $filter->send($data);
                if (!isset($yielded)) {
                    if ($sentString) {
                        $flushYield = $filter->send(false);
                        if (isset($flushYield) && !is_string($flushYield)) {
                            $this->throwTypeError($key, $flushYield);
                        } elseif (isset($flushYield)) {
                            $data = $flushYield;
                        } elseif ($filter->valid()) {
                            $data = $flushYielde;
                        } elseif (($return = $filter->getReturn()) === null) {
                            unset($this->generatorFilters[$key]);
                            $data = $flushYield;
                        } elseif (is_string($return)) {
                            unset($this->generatorFilters[$key]);
                            $data = $return;
                        } else {
                            $this->throwTypeError($key, $return);
                        }
                    } elseif ($filter->valid()) {
                        return "";
                    } elseif (($return = $filter->getReturn()) === null) {
                        unset($this->generatorFilters[$key]);
                        $data = $yielded;
                    } elseif (is_string($return)) {
                        unset($this->generatorFilters[$key]);
                        $data = $return;
                    } else {
                        $this->throwTypeError($key, $return);
                    }
                } elseif (is_string($yielded)) {
                    if ($sentString) {
                        $flushYield = $filter->send(false);
                        if (isset($flushYield)) {
                            if (is_string($flushYield)) {
                                $data = $yielded . $flushYield;
                            } else {
                                $this->throwTypeError($key, $flushYield);
                            }
                        } elseif ($filter->valid()) {
                            $data = $yielded;
                        } elseif (($return = $filter->getReturn()) === null) {
                            unset($this->generatorFilters[$key]);
                            $data = $yielded;
                        } elseif (is_string($return)) {
                            unset($this->generatorFilters[$key]);
                            $data = $yielded . $return;
                        } else {
                            $this->throwTypeError($key, $return);
                        }
                    } else {
                        $data = $yielded;
                    }
                } else {
                    $this->throwTypeError($key, $yielded);
                }
            }

            return (string) $data;

        } catch (\BaseException $e) {
            throw new FilterException($e, $key);
        }
    }

    /**
     * End filtering with an optional final data chunk
     *
     * @param string $data An optional final data chunk to filter
     * @throws \Aerys\FilterException If a filter error occurs
     * @return string Returns a filtered string (possibly empty)
     */
    public function end(string $data = null): string {
        try {
            foreach ($this->generatorFilters as $key => $filter) {
                $sentString = isset($data);
                $yielded = $filter->send($data);
                if (!isset($yielded)) {
                    if ($sentString) {
                        $finalYield = $filter->send(null);
                        if (isset($finalYield)) {
                            if (is_string($finalYield)) {
                                $data = $finalYield;
                            } else {
                                $this->throwTypeError($key, $finalYield);
                            }
                        } elseif ($filter->valid() || ($return = $filter->getReturn()) === null) {
                            // If the filter returned null to the END signal then
                            // there's nothing else we can do.
                            return "";
                        } elseif (is_string($return)) {
                            $data = $return;
                        } else {
                            $this->throwTypeError($key, $return);
                        }
                    } elseif ($filter->valid() || ($return = $filter->getReturn()) === null) {
                        // If the filter returned null to the END signal then
                        // there's nothing else we can do.
                        return "";
                    } elseif (is_string($return)) {
                        $data = $return;
                    } else {
                        $this->throwTypeError($key, $return);
                    }
                } elseif (is_string($yielded)) {
                    if ($sentString) {
                        $finalYield = $filter->send(null);
                        if (isset($finalYield)) {
                            if (is_string($finalYield)) {
                                $data = $yielded . $finalYield;
                            } else {
                                $this->throwTypeError($key, $finalYield);
                            }
                        } elseif ($filter->valid() || ($return = $filter->getReturn()) === null) {
                            $data = $yielded;
                        } elseif (is_string($return)) {
                            $data = $yielded . $return;
                        } else {
                            $this->throwTypeError($key, $return);
                        }
                    } else {
                        $data = $yielded;
                    }
                } else {
                    $this->throwTypeError($key, $yielded);
                }
            }

            // use (string) cast in case of empty filter array because $data can === null
            return (string) $data;

        } catch (\BaseException $e) {
            throw new FilterException($e, $key);
        }
    }

    private function throwTypeError($filterIndex, $badValue) {
        throw new \DomainException(
            sprintf(
                "Invalid filter response at index %s: expected string|null, received %s",
                $filterIndex,
                (is_object($badValue) ? get_class($badValue) : gettype($badValue))
            )
        );
    }
}
