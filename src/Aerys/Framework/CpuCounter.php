<?php

namespace Aerys\Framework;

class CpuCounter implements \Countable {

    function count() {
        $os = $this->getOperatingSystem();

        switch ($os) {
            case "win":
                $cmd = "wmic cpu get NumberOfCores";
                break;
            case "linux":
                $cmd = "cat /proc/cpuinfo | grep processor | wc -l";
                break;
            case "freebsd":
                $cmd = "sysctl -a | grep 'hw.ncpu' | cut -d ':' -f2";
                break;
            default:
                $cmd = NULL;
        }

        $execResult = $cmd ? shell_exec($cmd) : 1;

        if ($os === 'win') {
            $execResult = explode("\n", $execResult)[1];
        }

        $cores = intval(trim($execResult));

        return $cores;
    }

    private function getOperatingSystem() {
        if (stripos(PHP_OS, "WIN") === 0) {
            $os = "win";
        } else {
            $os = strtolower(trim(shell_exec("uname")));
        }

        return $os;
    }

}
