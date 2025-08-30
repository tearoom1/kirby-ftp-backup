<?php


// Load Kirby
$bootstrapFile = realpath('kirby/bootstrap.php');
if (!file_exists($bootstrapFile)) {
    die('Could not find bootstrap file: ' . $bootstrapFile);
}
require $bootstrapFile;

// Initialize Kirby
$kirby = new Kirby\Cms\App();

