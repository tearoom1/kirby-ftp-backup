<?php
/**
 * Direct download script for FTP backups
 * Bypasses Panel authentication for direct access
 */

// Initialize Kirby environment
require __DIR__ . '/../../../kirby/bootstrap.php';
$kirby = kirby();

// Check if a file parameter was provided
if (empty($_GET['file'])) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Error: No file specified';
    exit;
}

// Sanitize the filename
$filename = basename($_GET['file']);

// Get backup directory from config
$backupDir = $kirby->option('tearoom1.ftp-backup.backupDirectory', $kirby->root('content') . '/.backups');

// Handle relative paths if needed
if (strpos($backupDir, '/') !== 0) {
    $backupDir = $kirby->root('base') . '/' . $backupDir;
}

// Create the full file path
$filepath = $backupDir . '/' . $filename;

// Check if file exists
if (!file_exists($filepath)) {
    header('HTTP/1.1 404 Not Found');
    echo 'Error: File not found';
    exit;
}

// Verify file extension is zip for security
if (pathinfo($filepath, PATHINFO_EXTENSION) !== 'zip') {
    header('HTTP/1.1 403 Forbidden');
    echo 'Error: Invalid file type';
    exit;
}

// Set headers for file download
header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Pragma: public');

// Clean output buffer
ob_clean();
flush();

// Output file
readfile($filepath);
exit;
