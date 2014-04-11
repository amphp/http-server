<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/bootstrap.php';
$ct = new Aerys\Start\ConfigTester;
echo $ct->configure();
exit($ct->getExitCode());