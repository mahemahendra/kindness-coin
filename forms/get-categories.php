<?php
/**
 * Categories Endpoint
 * Returns the configured story categories as JSON.
 * No DB query — reads config only.
 */
header('Content-Type: application/json');
header('Cache-Control: max-age=3600');

$config = require __DIR__ . '/config.php';

$categories = isset($config['categories']) && is_array($config['categories'])
    ? array_values(array_filter(array_map('strval', $config['categories'])))
    : [];

echo json_encode(['success' => true, 'categories' => $categories]);
