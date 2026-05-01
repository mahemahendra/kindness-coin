<?php
/**
 * Admin image proxy – serves uploaded story images only to authenticated admins.
 * Bypasses the forms/data/.htaccess deny-all rule safely.
 */
session_start();

if (empty($_SESSION['admin_auth'])) {
    http_response_code(403);
    exit;
}

$file = $_GET['file'] ?? '';

// Only allow safe filenames: hex chars, underscores, dashes, ending in .jpg
if (!preg_match('/^[a-zA-Z0-9_\-]+\.jpg$/D', $file)) {
    http_response_code(400);
    exit;
}

$uploadsDir = realpath(__DIR__ . '/../forms/data/uploads');
$imagePath  = realpath($uploadsDir . '/' . $file);

// Ensure the resolved path is inside the uploads directory (no traversal)
if ($uploadsDir === false || $imagePath === false
    || !str_starts_with($imagePath, $uploadsDir . DIRECTORY_SEPARATOR)
    || !is_file($imagePath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($imagePath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($imagePath);
