<?php

namespace Aerys;

/**
 * ~~~~~~~~~~~~~~~ WARNING ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
 * This class is strictly for internal Aerys use!
 * Do NOT throw it in userspace code or you risk breaking things.
 * ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
 */
final class FilterException extends \Exception {
    private $filterKey;
    public function __construct(\BaseException $previous, $filterKey) {
        $this->filterKey = $filterKey;
        parent::__construct("", 0, $previous);
    }
    public function getFilterKey() {
        return $this->filterKey;
    }
}
