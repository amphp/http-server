<?php

namespace Aerys\Pipeline;

interface Reader {
    function read();
    function inProgress();
}

