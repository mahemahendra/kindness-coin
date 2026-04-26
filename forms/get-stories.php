<?php
/**
 * Public Stories API
 * Returns approved stories with pagination, category filter, and sort.
 *
 * GET params:
 *   page     int  >= 1        (default 1)
 *   limit    int  1–24        (default 12)
 *   category string           (default 'all'; must match a configured category)
 *   sort     string           (latest|oldest|name_asc|country_asc; default latest)
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$config = require __DIR__ . '/config.php';

// --- Whitelist maps ---
$allowedSorts = [
    'latest'      => 'submitted_at DESC',
    'oldest'      => 'submitted_at ASC',
    'name_asc'    => 'full_name ASC',
    'country_asc' => 'country ASC, full_name ASC',
];

$configuredCategories = isset($config['categories']) && is_array($config['categories'])
    ? array_map('strval', $config['categories'])
    : [];

// --- Parse & validate input ---
$page  = max(1, (int) ($_GET['page']  ?? 1));
$limit = min(24, max(1, (int) ($_GET['limit'] ?? 12)));

$rawSort = $_GET['sort'] ?? 'latest';
$orderBy = $allowedSorts[$rawSort] ?? $allowedSorts['latest'];

$rawCategory = trim((string) ($_GET['category'] ?? 'all'));
// 'all' passes through; any other value must be in the configured list
if ($rawCategory !== 'all' && !in_array($rawCategory, $configuredCategories, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid category.']);
    exit;
}

$offset = ($page - 1) * $limit;

// --- Query DB ---
try {
    $db  = $config['db'];
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Build WHERE clause
    $where  = 'WHERE approved = 1';
    $params = [];
    if ($rawCategory !== 'all') {
        $where           .= ' AND category = :category';
        $params[':category'] = $rawCategory;
    }

    // Total count for has_more calculation
    $countSql  = "SELECT COUNT(*) FROM stories $where";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch page of stories
    // NOTE: ORDER BY uses a whitelisted string — safe to interpolate
    $sql  = "SELECT id, full_name, club_name, country, constitutional_area, state_county, category,
                    story, image_path, submitted_at, approved_at
             FROM stories
             $where
             ORDER BY $orderBy
             LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $stories = $stmt->fetchAll();

    // Sanitise output — strip any HTML in text fields before sending to client
    foreach ($stories as &$s) {
        $s['full_name']           = htmlspecialchars($s['full_name'],           ENT_QUOTES, 'UTF-8');
        $s['club_name']           = htmlspecialchars($s['club_name'],           ENT_QUOTES, 'UTF-8');
        $s['country']             = htmlspecialchars($s['country'],             ENT_QUOTES, 'UTF-8');
        $s['constitutional_area'] = htmlspecialchars($s['constitutional_area'], ENT_QUOTES, 'UTF-8');
        $s['state_county']        = htmlspecialchars($s['state_county'],        ENT_QUOTES, 'UTF-8');
        $s['category']            = htmlspecialchars($s['category'],            ENT_QUOTES, 'UTF-8');
        $s['story']               = htmlspecialchars($s['story'],               ENT_QUOTES, 'UTF-8');
        // image_path is a server-generated safe filename — still escape for output
        $s['image_path']          = htmlspecialchars($s['image_path'],          ENT_QUOTES, 'UTF-8');
    }
    unset($s);

    echo json_encode([
        'success'  => true,
        'stories'  => $stories,
        'total'    => $total,
        'page'     => $page,
        'limit'    => $limit,
        'has_more' => ($offset + count($stories)) < $total,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load stories.']);
}
