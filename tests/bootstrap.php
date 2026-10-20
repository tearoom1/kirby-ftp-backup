<?php

// Load Kirby (installed into ./kirby as a dev dependency)
$bootstrapFile = dirname(__DIR__) . '/kirby/bootstrap.php';
if (!file_exists($bootstrapFile)) {
    die('Could not find bootstrap file: ' . $bootstrapFile . PHP_EOL);
}
require $bootstrapFile;

require __DIR__ . '/PluginTestCase.php';
require __DIR__ . '/FakeFtpClient.php';
