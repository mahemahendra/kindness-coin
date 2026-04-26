<?php
/**
 * Returns the sorted list of countries from the local CA map as JSON.
 * Used by the story submission form to populate the country dropdown.
 *
 * GET /forms/get-countries.php
 * Response: { "success": true, "countries": ["Afghanistan", "Albania", ...] }
 */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=86400'); // cache for 1 day

$mapPath = __DIR__ . '/ca-map.php';

if (!file_exists($mapPath)) {
    // Map not yet generated — return empty list so the form still works
    echo json_encode(['success' => true, 'countries' => []]);
    exit;
}

$caMap = require $mapPath;

// Extract display names, sort them, and re-index
$countries = [];
foreach ($caMap as $entry) {
    if (is_array($entry) && isset($entry['display']) && $entry['display'] !== '') {
        $countries[] = $entry['display'];
    }
}

usort($countries, 'strcasecmp');

echo json_encode(['success' => true, 'countries' => array_values($countries)]);
