<?php
/**
 * Map Analytics Endpoint
 * Returns JSON with story statistics for the world map section.
 */
header('Content-Type: application/json');

// Prevent caching of stats
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$config = require __DIR__ . '/config.php';

try {
    $db  = $config['db'];
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $total     = (int) $pdo->query('SELECT COUNT(*) FROM stories')->fetchColumn();
    $casReached = (int) $pdo->query("SELECT COUNT(DISTINCT constitutional_area) FROM stories WHERE approved = 1 AND constitutional_area != ''")->fetchColumn();
    $thisMonth = (int) $pdo->query(
        'SELECT COUNT(*) FROM stories
         WHERE YEAR(submitted_at) = YEAR(NOW())
           AND MONTH(submitted_at) = MONTH(NOW())'
    )->fetchColumn();

    $markersStmt = $pdo->query(
        "SELECT constitutional_area, COUNT(*) AS story_count FROM stories WHERE approved = 1 AND constitutional_area != '' GROUP BY constitutional_area ORDER BY constitutional_area ASC"
    );
    $markers = array_map(function ($row) {
        return ['ca' => $row['constitutional_area'], 'count' => (int) $row['story_count']];
    }, $markersStmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success'             => true,
        'total_stories'       => $total,
        'countries_travelled' => $casReached,
        'this_month'          => $thisMonth,
        'markers'             => $markers,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'total_stories' => 0, 'countries_travelled' => 0, 'this_month' => 0]);
}
