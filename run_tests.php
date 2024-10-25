<?php
ini_set('memory_limit', '1024M');
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/tests/ViaDialogClientTest.php';

spl_autoload_register(function ($class) {
    echo "Trying to load: $class\n";
});

use Tests\ViaDialogClientTest;

$test = new ViaDialogClientTest();
$test->runTests();
