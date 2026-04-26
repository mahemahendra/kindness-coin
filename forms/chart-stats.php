<?php
/**
 * Chart Statistics Endpoint
 * Returns JSON with category counts and story trend data for dashboard charts.
 */
header('Content-Type: application/json');
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

    // ── Category counts (approved stories only) ────────────────────────────
    $catStmt = $pdo->query(
        "SELECT category, COUNT(*) AS cnt
         FROM stories
         WHERE approved = 1 AND category != ''
         GROUP BY category
         ORDER BY cnt DESC"
    );
    $categoryCounts = [];
    foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $categoryCounts[] = [
            'category' => $row['category'],
            'count'    => (int) $row['cnt'],
        ];
    }

    // ── Trend: last 7 days (one point per day) ────────────────────────────
    $weekData = [];
    for ($i = 6; $i >= 0; $i--) {
        $date  = date('Y-m-d', strtotime("-{$i} days"));
        $label = date('D', strtotime($date));   // Mon, Tue, …
        $weekData[$date] = ['label' => $label, 'count' => 0];
    }
    $weekStmt = $pdo->prepare(
        "SELECT DATE(submitted_at) AS day, COUNT(*) AS cnt
         FROM stories
         WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
           AND submitted_at  < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
         GROUP BY day"
    );
    $weekStmt->execute();
    foreach ($weekStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($weekData[$row['day']])) {
            $weekData[$row['day']]['count'] = (int) $row['cnt'];
        }
    }

    // ── Trend: last 30 days grouped by ISO week ───────────────────────────
    $monthData = [];
    // Build ordered week buckets (ISO year-week string → label)
    $weekBuckets = [];
    for ($i = 29; $i >= 0; $i--) {
        $ts  = strtotime("-{$i} days");
        $key = date('oW', $ts);   // ISO year + week number, e.g. 202517
        if (!array_key_exists($key, $weekBuckets)) {
            $weekBuckets[$key] = 'Wk ' . (string)(int) date('W', $ts);
        }
    }
    // Init counts to 0
    foreach ($weekBuckets as $key => $label) {
        $monthData[$key] = ['label' => $label, 'count' => 0];
    }
    $monthStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(submitted_at, '%x%v') AS yw, COUNT(*) AS cnt
         FROM stories
         WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
           AND submitted_at  < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
         GROUP BY yw"
    );
    $monthStmt->execute();
    foreach ($monthStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthData[$row['yw']])) {
            $monthData[$row['yw']]['count'] += (int) $row['cnt'];
        }
    }

    // ── Trend: last 6 calendar months (one point per month) ──────────────
    $sixMonthData = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts    = mktime(0, 0, 0, (int)date('n') - $i, 1, (int)date('Y'));
        $ym    = date('Y-m', $ts);
        $label = date('M Y', $ts);
        $sixMonthData[$ym] = ['label' => $label, 'count' => 0];
    }
    $sixMonthStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS ym, COUNT(*) AS cnt
         FROM stories
         WHERE submitted_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY ym"
    );
    $sixMonthStmt->execute();
    foreach ($sixMonthStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($sixMonthData[$row['ym']])) {
            $sixMonthData[$row['ym']]['count'] = (int) $row['cnt'];
        }
    }

    echo json_encode([
        'success'         => true,
        'category_counts' => $categoryCounts,
        'trend'           => [
            'week'    => array_values($weekData),
            'month'   => array_values($monthData),
            '6months' => array_values($sixMonthData),
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'category_counts' => [], 'trend' => []]);
}
