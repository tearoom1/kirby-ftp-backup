<?php

/**
 * Simple test runner for FTP Backup plugin - runs PHPUnit tests properly
 * Run this from the command line: php test-runner.php
 */

// Check if PHPUnit is available
if (!file_exists(__DIR__ . '/vendor/bin/phpunit')) {
    echo "❌ PHPUnit not found. Please run 'composer install' first.\n";
    exit(1);
}

echo "=== FTP Backup Plugin - Retention Strategy Tests ===\n\n";
echo "Running PHPUnit tests...\n\n";

// Run PHPUnit with our configuration
$phpunitPath = __DIR__ . '/vendor/bin/phpunit';
$configPath = __DIR__ . '/phpunit.xml';

$command = "php \"$phpunitPath\" --configuration=\"$configPath\" --testdox";

// Execute PHPUnit
passthru($command, $exitCode);

echo "\n";
if ($exitCode === 0) {
    echo "🎉 All tests passed!\n";
} else {
    echo "❌ Some tests failed.\n";
}

exit($exitCode);
