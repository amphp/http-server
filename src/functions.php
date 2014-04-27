<?php

namespace Aerys;

function countCpuCores() {
    $os = (stripos(PHP_OS, "WIN") === 0) ? "win" : strtolower(trim(shell_exec("uname")));

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
        case "darwin":
        $cmd = "sysctl -a | grep 'hw.ncpu:' | awk '{ print $2 }'";
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
