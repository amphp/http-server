<?php

namespace Aerys\Framework;

class ServerOptions implements \ArrayAccess {

    private $options = [
        'maxconnections'        => NULL,
        'maxrequests'           => NULL,
        'keepalivetimeout'      => NULL,
        'disablekeepalive'      => NULL,
        'maxheaderbytes'        => NULL,
        'maxbodybytes'          => NULL,
        'defaultcontenttype'    => NULL,
        'defaulttextcharset'    => NULL,
        'autoreasonphrase'      => NULL,
        'logerrorsto'           => NULL,
        'sendservertoken'       => NULL,
        'normalizemethodcase'   => NULL,
        'requirebodylength'     => NULL,
        'socketsolingerzero'    => NULL,
        'socketbacklogsize'     => NULL,
        'allowedmethods'        => NULL,
        'defaulthost'           => NULL,
        'showerrors'            => NULL
    ];

    /**
     * Set an individual server option
     *
     * @param string $option
     * @param mixed $value
     * @throws \DomainException On invalid option key
     * @return \Aerys\Framework\ServerOptions Returns the current object instance
     */
    function setOption($option, $value) {
        $this->offsetSet($option, $value);

        return $this;
    }

    /**
     * Set multiple server options
     *
     * @param array $options
     * @throws \DomainException On invalid option key
     * @return \Aerys\Framework\ServerOptions Returns the current object instance
     */
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->offsetSet($key, $value);
        }

        return $this;
    }

    /**
     * Retrieve a server option value
     *
     * @param string $option
     * @throws \DomainException on invalid option key
     * @return mixed Returns the value of the specified option key
     */
    function getOption($option) {
        return $this->offsetGet($option);
    }

    /**
     * Retrieve an array of all options that have been assigned a value
     *
     * @return array
     */
    function getAllOptions() {
        $opts = [];

        foreach ($this->options as $key => $value) {
            if ($value !== NULL) {
                $opts[$key] = $value;
            }
        }

        return $opts;
    }

    function offsetSet($offset, $value) {
        $lowOffset = strtolower($offset);

        if (array_key_exists($lowOffset, $this->options)) {
            $this->options[$lowOffset] = $value;
        } else {
            throw new \DomainException(
                "Invalid server option key: {$offset}"
            );
        }
    }

    function offsetExists($offset) {
        return array_key_exists(strtolower($offset), $this->options);
    }

    function offsetUnset($offset) {
        $lowOffset = strtolower($offset);

        if (array_key_exists($lowOffset, $this->options)) {
            $this->options[$lowOffset] = NULL;
        }
    }

    function offsetGet($offset) {
        $lowOffset = strtolower($offset);

        if (array_key_exists($lowOffset, $this->options)) {
            return $this->options[$lowOffset];
        } else {
            throw new \DomainException(
                "Invalid server option key: {$offset}"
            );
        }
    }

}
