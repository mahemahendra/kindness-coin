<?php
session_start();

// Generate CSRF token once per session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$config = require __DIR__ . '/../forms/config.php';

// --- Logout ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    session_destroy();
    header('Location: stories.php');
    exit;
}

// --- Auth ---
$loginError    = '';
$adminPassword = $config['admin_password'] ?? 'kindness2026';
$authenticated = !empty($_SESSION['admin_auth']);

if (!$authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals($adminPassword, $_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // rotate on privilege change
        $_SESSION['admin_auth'] = true;
        header('Location: stories.php');
        exit;
    }
    $loginError = 'Incorrect password.';
}

// --- Approve / Reject action (AJAX POST) ---
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    header('Content-Type: application/json');
    // CSRF validation
    $csrfToken = $_POST['csrf_token'] ?? '';
    if ($csrfToken === '' || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    $id     = (int)$_POST['id'];
    $action = $_POST['action'];
    if ($id > 0 && in_array($action, ['approve', 'reject'], true)) {
        try {
            $db  = $config['db'];
            $pdo = new PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4",
                $db['username'], $db['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $approved   = $action === 'approve' ? 1 : 0;
            $approvedAt = $action === 'approve' ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('UPDATE stories SET approved = :a, approved_at = :at WHERE id = :id')
                ->execute([':a' => $approved, ':at' => $approvedAt, ':id' => $id]);
            echo json_encode(['success' => true, 'approved' => $approved, 'approved_at' => $approvedAt]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
      } elseif ($id > 0 && $action === 'delete') {
        try {
          $db  = $config['db'];
          $pdo = new PDO(
            "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4",
            $db['username'], $db['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
          );

          $select = $pdo->prepare('SELECT image_path FROM stories WHERE id = :id LIMIT 1');
          $select->execute([':id' => $id]);
          $story = $select->fetch(PDO::FETCH_ASSOC);

          if (!$story) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Story not found.']);
            exit;
          }

          $pdo->prepare('DELETE FROM stories WHERE id = :id')->execute([':id' => $id]);

          if (!empty($story['image_path'])) {
            $relativePath = ltrim((string)$story['image_path'], '/\\');
            $uploadBase   = realpath(__DIR__ . '/../forms/data/uploads');
            $imageFull    = realpath(__DIR__ . '/../' . $relativePath);

            // Delete only if the image resolves inside the uploads directory.
            if ($uploadBase !== false && $imageFull !== false && str_starts_with($imageFull, $uploadBase) && is_file($imageFull)) {
              @unlink($imageFull);
            }
          }

          echo json_encode(['success' => true]);
        } catch (PDOException $e) {
          http_response_code(500);
          echo json_encode(['success' => false]);
        }
    } elseif ($id > 0 && $action === 'assign_category') {
        $allowed  = array_merge($config['categories'] ?? [], ['']);
        $category = isset($_POST['category']) && in_array($_POST['category'], $allowed, true)
                    ? $_POST['category'] : null;
        if ($category !== null) {
            try {
                $db  = $config['db'];
                $pdo = new PDO(
                    "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4",
                    $db['username'], $db['password'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $pdo->prepare('UPDATE stories SET category = :cat WHERE id = :id')
                    ->execute([':cat' => $category, ':id' => $id]);
                echo json_encode(['success' => true, 'category' => $category]);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['success' => false]);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false]);
    }
    exit;
}

// --- Data ---
$rows = []; $totalRows = 0; $totalPages = 1; $dbError = '';
$countries = []; $categories = []; $constitutionalAreas = [];
$search = $filterCountry = $filterCategory = $filterDateFrom = $filterDateTo = $filterStatus = $filterCA = '';
$perPage = 20; $currentPage = 1;

if ($authenticated) {
    try {
        $db  = $config['db'];
        $pdo = new PDO(
            "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4",
            $db['username'], $db['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );

        $countries           = $pdo->query("SELECT DISTINCT country             FROM stories WHERE country             <> '' ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);
        $categories          = $pdo->query("SELECT DISTINCT category            FROM stories WHERE category            <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
        $constitutionalAreas = $pdo->query("SELECT DISTINCT constitutional_area FROM stories WHERE constitutional_area <> '' ORDER BY constitutional_area")->fetchAll(PDO::FETCH_COLUMN);

        $search         = trim($_GET['search']    ?? '');
        $filterCountry  = trim($_GET['country']   ?? '');
        $filterCA       = trim($_GET['ca']         ?? '');
        $filterCategory = trim($_GET['category']  ?? '');
        $filterDateFrom = trim($_GET['date_from'] ?? '');
        $filterDateTo   = trim($_GET['date_to']   ?? '');
        $filterStatus   = trim($_GET['status']    ?? '');
        $perPage        = in_array((int)($_GET['per_page'] ?? 20), [10, 25, 50, 100]) ? (int)$_GET['per_page'] : 20;
        $currentPage    = max(1, (int)($_GET['page'] ?? 1));

        $where = []; $params = [];

        if ($search !== '') {
            $like    = '%' . $search . '%';
            $where[] = '(full_name LIKE :s1 OR email LIKE :s2 OR club_name LIKE :s3 OR story LIKE :s4)';
            $params  = array_merge($params, [':s1' => $like, ':s2' => $like, ':s3' => $like, ':s4' => $like]);
        }
        if ($filterCountry  !== '') { $where[] = 'country              = :country';  $params[':country']  = $filterCountry; }
        if ($filterCA       !== '') { $where[] = 'constitutional_area  = :ca';       $params[':ca']       = $filterCA; }
        if ($filterCategory !== '') { $where[] = 'category             = :category'; $params[':category'] = $filterCategory; }
        if ($filterDateFrom !== '') { $where[] = 'DATE(submitted_at) >= :df'; $params[':df'] = $filterDateFrom; }
        if ($filterDateTo   !== '') { $where[] = 'DATE(submitted_at) <= :dt'; $params[':dt'] = $filterDateTo; }
        if ($filterStatus === 'approved') { $where[] = 'approved = 1'; }
        elseif ($filterStatus === 'pending') { $where[] = 'approved = 0'; }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stories $whereSql");
        $stmt->execute($params);
        $totalRows   = (int)$stmt->fetchColumn();
        $totalPages  = max(1, (int)ceil($totalRows / $perPage));
        $currentPage = min($currentPage, $totalPages);

        $stmt = $pdo->prepare("SELECT * FROM stories $whereSql ORDER BY submitted_at DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($currentPage - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Counts for badges
        $pendingCount  = (int)$pdo->query("SELECT COUNT(*) FROM stories WHERE approved = 0")->fetchColumn();
        $approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM stories WHERE approved = 1")->fetchColumn();

    } catch (PDOException $e) {
        $dbError = 'Database connection failed. Please check configuration.';
    }
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function pageUrl(int $p): string {
    return '?' . http_build_query(array_merge($_GET, ['page' => $p]));
}

$fromEntry = $totalRows > 0 ? (($currentPage - 1) * $perPage + 1) : 0;
$toEntry   = min($currentPage * $perPage, $totalRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Stories Admin – Kindness Coin</title>
  <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css">
  <style>
    body { background: #f0f2f5; font-size: .9rem; }
    .topbar { background: #0d1b2a; padding: .65rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
    .topbar-brand { color: #fff; font-weight: 700; font-size: 1.05rem; letter-spacing: .02em; }
    .topbar-brand .accent { color: #ffc107; }
    .filter-wrap { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.07); padding: 1rem 1.25rem; }
    .table-wrap  { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.07); overflow: hidden; }
    .table thead th { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: #6c757d; background: #f8f9fa; border-bottom-width: 1px; white-space: nowrap; padding: .65rem .75rem; }
    .table tbody td { vertical-align: middle; padding: .55rem .75rem; }
    .table tbody tr:hover td { background: #f5f7ff; }
    .story-excerpt { font-size: .82rem; color: #444; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; max-width: 260px; line-height: 1.5; }
    .stacked small { display: block; color: #888; font-size: .78rem; margin-top: 1px; }
    .login-screen { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f0f2f5; }
    .login-card { width: 360px; }
    .modal-meta-box { border: 1px solid #dee2e6; border-radius: 6px; padding: .75rem 1rem; }
    .modal-meta-box h6 { font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; color: #6c757d; margin-bottom: .5rem; }
    .btn-approve { min-width: 90px; }
    tr.approved-row td { background: #f0fff4; }
  </style>
</head>
<body>

<?php if (!$authenticated): ?>
<!-- ================================================================
     LOGIN
     ================================================================ -->
<div class="login-screen">
  <div class="login-card card shadow-sm p-4">
    <div class="text-center mb-4">
      <i class="bi bi-shield-lock-fill text-primary" style="font-size:2.5rem;"></i>
      <h4 class="mt-2 mb-0 fw-bold">Stories Admin</h4>
      <small class="text-muted">Kindness Coin</small>
    </div>
    <?php if ($loginError !== ''): ?>
      <div class="alert alert-danger py-2 small"><?= esc($loginError) ?></div>
    <?php endif; ?>
    <form method="POST" action="">
      <div class="mb-3">
        <label class="form-label fw-semibold small">Password</label>
        <input type="password" name="password" class="form-control" autofocus required>
      </div>
      <button class="btn btn-primary w-100">
        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
      </button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ================================================================
     ADMIN MAIN
     ================================================================ -->
<div class="topbar">
  <div class="topbar-brand">
    <i class="bi bi-coin me-1 text-warning"></i>
    Kindness Coin <span class="accent">· Stories Admin</span>
  </div>
  <div class="d-flex align-items-center gap-3">
    <span class="badge bg-warning text-dark">
      <i class="bi bi-hourglass-split me-1"></i><span id="pendingCountText"><?= (int)($pendingCount ?? 0) ?></span> Pending
    </span>
    <span class="badge bg-success">
      <i class="bi bi-check-circle me-1"></i><span id="approvedCountText"><?= (int)($approvedCount ?? 0) ?></span> Approved
    </span>
    <form method="POST" action="" class="mb-0">
      <button name="logout" class="btn btn-sm btn-outline-light">
        <i class="bi bi-box-arrow-right me-1"></i>Logout
      </button>
    </form>
  </div>
</div>

<div class="container-fluid py-3 px-4">

  <?php if ($dbError !== ''): ?>
    <div class="alert alert-danger small"><?= esc($dbError) ?></div>
  <?php endif; ?>

  <!-- ==== Filters ==== -->
  <div class="filter-wrap mb-3">
    <form method="GET" action="">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label mb-1 small fw-semibold">Search</label>
          <input type="text" name="search" class="form-control form-control-sm"
                 placeholder="Name, email, club, story…"
                 value="<?= esc($search) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label mb-1 small fw-semibold">Status</label>
          <select name="status" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="pending"  <?= $filterStatus === 'pending'  ? 'selected' : '' ?>>Pending</option>
            <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label mb-1 small fw-semibold">Country</label>
          <select name="country" class="form-select form-select-sm">
            <option value="">All Countries</option>
            <?php foreach ($countries as $c): ?>
              <option value="<?= esc($c) ?>" <?= $filterCountry === $c ? 'selected' : '' ?>><?= esc($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label mb-1 small fw-semibold">Constitutional Area</label>
          <select name="ca" class="form-select form-select-sm">
            <option value="">All Areas</option>
            <?php foreach ($constitutionalAreas as $ca): ?>
              <option value="<?= esc($ca) ?>" <?= $filterCA === $ca ? 'selected' : '' ?>><?= esc($ca) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label mb-1 small fw-semibold">Category</label>
          <select name="category" class="form-select form-select-sm">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= esc($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= esc($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1">
          <label class="form-label mb-1 small fw-semibold">From</label>
          <input type="date" name="date_from" class="form-control form-control-sm"
                 value="<?= esc($filterDateFrom) ?>">
        </div>
        <div class="col-md-1">
          <label class="form-label mb-1 small fw-semibold">To</label>
          <input type="date" name="date_to" class="form-control form-control-sm"
                 value="<?= esc($filterDateTo) ?>">
        </div>
        <div class="col-auto">
          <label class="form-label mb-1 small fw-semibold">Per page</label>
          <select name="per_page" class="form-select form-select-sm">
            <?php foreach ([10, 25, 50, 100] as $pp): ?>
              <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm px-3">
            <i class="bi bi-funnel me-1"></i>Apply
          </button>
          <a href="stories.php" class="btn btn-outline-secondary btn-sm px-3">
            <i class="bi bi-x-circle me-1"></i>Reset
          </a>
        </div>
      </div>
    </form>
  </div>

  <!-- ==== Table ==== -->
  <div class="table-wrap mb-3">
    <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom bg-white">
      <span class="text-muted small" id="tableSummaryText" data-from-entry="<?= (int)$fromEntry ?>" data-total="<?= (int)$totalRows ?>">
        <?php if ($totalRows > 0): ?>
          Showing <strong><?= $fromEntry ?>–<?= $toEntry ?></strong> of <strong><?= number_format($totalRows) ?></strong> stories
        <?php else: ?>
          No stories found
        <?php endif; ?>
      </span>
      <span class="badge bg-secondary"><span id="tableTotalText"><?= number_format($totalRows) ?></span> total</span>
    </div>

    <?php if (count($rows) > 0): ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Status</th>
            <th>Submitted</th>
            <th>Approved At</th>
            <th>Submitter</th>
            <th>Club</th>
            <th>Country / State</th>
            <th>Const. Area</th>
            <th>Category</th>
            <th>Story</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <?php $isApproved = (int)$row['approved'] === 1; ?>
          <tr class="<?= $isApproved ? 'approved-row' : '' ?>" id="row-<?= (int)$row['id'] ?>">
            <td class="text-muted fw-bold"><?= (int)$row['id'] ?></td>
            <td>
              <?php if ($isApproved): ?>
                <span class="badge bg-success status-badge">Approved</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark status-badge">Pending</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;" class="stacked">
              <?= esc(date('d M Y', strtotime($row['submitted_at']))) ?>
              <small><?= esc(date('H:i', strtotime($row['submitted_at']))) ?></small>
            </td>
            <td style="white-space:nowrap;" class="stacked" id="approved-at-<?= (int)$row['id'] ?>">
              <?php if (!empty($row['approved_at'])): ?>
                <?= esc(date('d M Y', strtotime($row['approved_at']))) ?>
                <small><?= esc(date('H:i', strtotime($row['approved_at']))) ?></small>
              <?php else: ?>
                <span class="text-muted">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="stacked">
              <span class="fw-semibold"><?= esc($row['full_name']) ?></span>
              <small><?= esc($row['email']) ?></small>
            </td>
            <td class="stacked">
              <?= esc($row['club_name']) ?>
              <?php if ($row['club_location'] !== ''): ?>
                <small><?= esc($row['club_location']) ?></small>
              <?php endif; ?>
            </td>
            <td class="stacked">
              <?= $row['country'] !== '' ? esc($row['country']) : '<span class="text-muted">–</span>' ?>
              <?php if ($row['state_county'] !== ''): ?>
                <small><?= esc($row['state_county']) ?></small>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <?= $row['constitutional_area'] !== '' ? esc($row['constitutional_area']) : '<span class="text-muted">&mdash;</span>' ?>
            </td>
            <td id="category-cell-<?= (int)$row['id'] ?>">
              <?php if ($row['category'] !== ''): ?>
                <span class="badge bg-info text-dark"><?= esc($row['category']) ?></span>
              <?php else: ?>
                <span class="text-muted">&mdash;</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="story-excerpt"><?= esc($row['story']) ?></div>
            </td>
            <td style="white-space:nowrap;">
              <button class="btn btn-sm btn-outline-primary btn-view me-1"
                data-row="<?= esc(json_encode($row, JSON_HEX_TAG | JSON_HEX_AMP)) ?>"
                data-bs-toggle="modal" data-bs-target="#detailModal" title="View">
                <i class="bi bi-eye"></i>
              </button>
              <?php if ($isApproved): ?>
                <button class="btn btn-sm btn-outline-warning btn-toggle-approve btn-approve"
                  data-id="<?= (int)$row['id'] ?>" data-approved="1" title="Revoke approval">
                  <i class="bi bi-x-circle me-1"></i>Revoke
                </button>
              <?php else: ?>
                <button class="btn btn-sm btn-success btn-toggle-approve btn-approve"
                  data-id="<?= (int)$row['id'] ?>" data-approved="0" title="Approve">
                  <i class="bi bi-check-circle me-1"></i>Approve
                </button>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-danger btn-delete-story ms-1"
                data-id="<?= (int)$row['id'] ?>" title="Delete story">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-inbox" style="font-size:3rem; opacity:.4;"></i>
      <p class="mt-2 mb-0">No stories match your filters.</p>
    </div>
    <?php endif; ?>
  </div>

  <!-- ==== Pagination ==== -->
  <?php if ($totalPages > 1): ?>
  <div class="d-flex align-items-center justify-content-between mb-4">
    <small class="text-muted">Page <?= $currentPage ?> of <?= $totalPages ?></small>
    <nav>
      <ul class="pagination pagination-sm mb-0">

        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $currentPage > 1 ? esc(pageUrl($currentPage - 1)) : '#' ?>">
            <i class="bi bi-chevron-left"></i>
          </a>
        </li>

        <?php
        $pageList = [];
        if ($totalPages <= 7) {
            $pageList = range(1, $totalPages);
        } else {
            $pageList[] = 1;
            if ($currentPage > 3) $pageList[] = '…';
            $start = max(2, $currentPage - 1);
            $end   = min($totalPages - 1, $currentPage + 1);
            for ($i = $start; $i <= $end; $i++) $pageList[] = $i;
            if ($currentPage < $totalPages - 2) $pageList[] = '…';
            $pageList[] = $totalPages;
        }
        foreach ($pageList as $pg):
            if ($pg === '…'): ?>
              <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
            <?php elseif ((int)$pg === $currentPage): ?>
              <li class="page-item active"><span class="page-link"><?= (int)$pg ?></span></li>
            <?php else: ?>
              <li class="page-item"><a class="page-link" href="<?= esc(pageUrl((int)$pg)) ?>"><?= (int)$pg ?></a></li>
            <?php endif;
        endforeach; ?>

        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $currentPage < $totalPages ? esc(pageUrl($currentPage + 1)) : '#' ?>">
            <i class="bi bi-chevron-right"></i>
          </a>
        </li>

      </ul>
    </nav>
  </div>
  <?php endif; ?>

</div><!-- /container-fluid -->

<!-- ================================================================
     DETAIL MODAL
     ================================================================ -->
<div class="modal fade" id="detailModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-person-lines-fill me-2 text-primary"></i>
          <span id="mTitle"></span>
          <span id="mStatusBadge" class="ms-2"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 mb-3">
          <div class="col-sm-6">
            <div class="modal-meta-box h-100">
              <h6>Contact</h6>
              <p class="mb-1"><strong>Name:</strong> <span id="mName"></span></p>
              <p class="mb-0"><strong>Email:</strong> <span id="mEmail"></span></p>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="modal-meta-box h-100">
              <h6>Club</h6>
              <p class="mb-1"><strong>Name:</strong> <span id="mClub"></span></p>
              <p class="mb-0"><strong>Location:</strong> <span id="mClubLoc"></span></p>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="modal-meta-box h-100">
              <h6>Location</h6>
              <p class="mb-1"><strong>Country:</strong> <span id="mCountry"></span></p>
              <p class="mb-1"><strong>Constitutional Area:</strong> <span id="mCA"></span></p>
              <p class="mb-0"><strong>State / County:</strong> <span id="mState"></span></p>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="modal-meta-box h-100">
              <h6>Meta</h6>
              <p class="mb-1"><strong>Category:</strong> <span id="mCategory"></span></p>
              <div class="d-flex align-items-center gap-2 mb-2">
                <select id="mCategorySelect" class="form-select form-select-sm">
                  <option value="">&mdash; Unassigned &mdash;</option>
                  <?php foreach ($config['categories'] ?? [] as $cat): ?>
                  <option value="<?= esc($cat) ?>"><?= esc($cat) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-primary btn-sm" id="mAssignCategoryBtn" style="white-space:nowrap;">
                  <i class="bi bi-tag-fill me-1"></i>Assign
                </button>
              </div>
              <p class="mb-1"><strong>Submitted:</strong> <span id="mDate"></span></p>
              <p class="mb-0"><strong>Approved At:</strong> <span id="mApprovedAt">&mdash;</span></p>
            </div>
          </div>
        </div>
        <div class="modal-meta-box mb-3">
          <h6>Story</h6>
          <p id="mStory" class="mb-0" style="white-space: pre-wrap; line-height: 1.75;"></p>
        </div>
        <div id="mImgWrap" class="d-none">
          <div class="modal-meta-box">
            <h6>Attached Image</h6>
            <img id="mImg" src="" alt="Attached image" class="img-fluid rounded" style="max-height: 320px;">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-outline-danger btn-sm me-auto" id="mDeleteBtn">
          <i class="bi bi-trash me-1"></i>Delete
        </button>
        <button type="button" class="btn btn-success btn-sm" id="mApproveBtn">
          <i class="bi bi-check-circle me-1"></i>Approve
        </button>
        <button type="button" class="btn btn-outline-warning btn-sm d-none" id="mRevokeBtn">
          <i class="bi bi-x-circle me-1"></i>Revoke Approval
        </button>
      </div>
    </div>
  </div>
</div>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>var _csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;</script>
<script>
(function () {
  'use strict';

  var currentId       = null;
  var currentApproved = 0;

  // ---- Populate modal on view ----
  document.querySelectorAll('.btn-view').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = JSON.parse(this.dataset.row);
      currentId       = row.id;
      currentApproved = parseInt(row.approved, 10);

      document.getElementById('mTitle').textContent    = row.full_name;
      document.getElementById('mName').textContent     = row.full_name;
      document.getElementById('mEmail').textContent    = row.email;
      document.getElementById('mClub').textContent     = row.club_name;
      document.getElementById('mClubLoc').textContent  = row.club_location  || '–';
      document.getElementById('mCountry').textContent  = row.country              || '–';
      document.getElementById('mCA').textContent       = row.constitutional_area  || '–';
      document.getElementById('mState').textContent    = row.state_county         || '–';
      document.getElementById('mCategory').textContent = row.category || '\u2014';
      document.getElementById('mCategorySelect').value  = row.category || '';
      document.getElementById('mDate').textContent       = row.submitted_at;
      document.getElementById('mApprovedAt').textContent  = row.approved_at || '\u2014';
      document.getElementById('mStory').textContent       = row.story;

      var imgWrap = document.getElementById('mImgWrap');
      var imgEl   = document.getElementById('mImg');
      if (row.image_path && row.image_path !== '') {
        imgEl.src = '../' + row.image_path;
        imgWrap.classList.remove('d-none');
      } else {
        imgWrap.classList.add('d-none');
        imgEl.src = '';
      }

      updateModalApprovalUI(currentApproved);
    });
  });

  // ---- Assign category from modal ----
  function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  document.getElementById('mAssignCategoryBtn').addEventListener('click', function () {
    if (!currentId) return;
    var category = document.getElementById('mCategorySelect').value;
    var body = new FormData();
    body.append('action', 'assign_category');
    body.append('id', currentId);
    body.append('category', category);
    body.append('csrf_token', _csrfToken);
    fetch('stories.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          document.getElementById('mCategory').textContent = data.category || '\u2014';
          var cell = document.getElementById('category-cell-' + currentId);
          if (cell) {
            cell.innerHTML = data.category
              ? '<span class="badge bg-info text-dark">' + escHtml(data.category) + '</span>'
              : '<span class="text-muted">\u2014</span>';
          }
        } else {
          alert('Failed to assign category. Please try again.');
        }
      })
      .catch(function () { alert('Network error. Please try again.'); });
  });

  function updateModalApprovalUI(approved) {
    var badge      = document.getElementById('mStatusBadge');
    var approveBtn = document.getElementById('mApproveBtn');
    var revokeBtn  = document.getElementById('mRevokeBtn');
    if (approved) {
      badge.innerHTML = '<span class="badge bg-success">Approved</span>';
      approveBtn.classList.add('d-none');
      revokeBtn.classList.remove('d-none');
    } else {
      badge.innerHTML = '<span class="badge bg-warning text-dark">Pending</span>';
      approveBtn.classList.remove('d-none');
      revokeBtn.classList.add('d-none');
    }
  }

  function deleteStory(id, onSuccess) {
    var body = new FormData();
    body.append('action', 'delete');
    body.append('id', id);
    body.append('csrf_token', _csrfToken);

    fetch('stories.php', { method: 'POST', body: body })
      .then(function (r) {
        return r.json().then(function (data) {
          return { ok: r.ok, data: data };
        });
      })
      .then(function (result) {
        if (result.ok && result.data.success) {
          onSuccess();
        } else {
          var msg = (result.data && result.data.message) ? result.data.message : 'Failed to delete story. Please try again.';
          alert(msg);
        }
      })
      .catch(function () { alert('Network error. Please try again.'); });
  }

  function formatNumber(n) {
    return Number(n).toLocaleString('en-US');
  }

  function updateTopCountsAfterDelete(wasApproved) {
    var pendingEl = document.getElementById('pendingCountText');
    var approvedEl = document.getElementById('approvedCountText');
    if (!pendingEl || !approvedEl) return;

    var pending = parseInt(pendingEl.textContent, 10) || 0;
    var approved = parseInt(approvedEl.textContent, 10) || 0;

    if (wasApproved) {
      approved = Math.max(0, approved - 1);
    } else {
      pending = Math.max(0, pending - 1);
    }

    pendingEl.textContent = String(pending);
    approvedEl.textContent = String(approved);
  }

  function updateTableCountsAfterDelete() {
    var summaryEl = document.getElementById('tableSummaryText');
    if (!summaryEl) return;

    var total = parseInt(summaryEl.dataset.total, 10) || 0;
    total = Math.max(0, total - 1);
    summaryEl.dataset.total = String(total);

    var fromEntry = parseInt(summaryEl.dataset.fromEntry, 10) || 0;
    var rowsShown = document.querySelectorAll('.table tbody tr').length;

    if (total === 0 || rowsShown === 0) {
      summaryEl.textContent = 'No stories found';
    } else {
      var start = fromEntry > 0 ? Math.min(fromEntry, total) : 1;
      var end = start + rowsShown - 1;
      summaryEl.innerHTML = 'Showing <strong>' + start + '\u2013' + end + '</strong> of <strong>' + formatNumber(total) + '</strong> stories';
    }

    var totalEl = document.getElementById('tableTotalText');
    if (totalEl) totalEl.textContent = formatNumber(total);
  }

  function applyDeleteUiUpdate(id, wasApproved) {
    removeRowFromDom(id);
    updateTopCountsAfterDelete(wasApproved);
    updateTableCountsAfterDelete();
  }

  function removeRowFromDom(id) {
    var row = document.getElementById('row-' + id);
    if (row) row.remove();
  }

  // ---- Shared toggle function ----
  function toggleApproval(id, currentApprovedState, onSuccess) {
    var action = currentApprovedState ? 'reject' : 'approve';
    var body   = new FormData();
    body.append('action', action);
    body.append('id', id);
    body.append('csrf_token', _csrfToken);

    fetch('stories.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          onSuccess(data.approved, data);
        } else {
          alert('Failed to update status. Please try again.');
        }
      })
      .catch(function () { alert('Network error. Please try again.'); });
  }

  // ---- Row inline approve buttons ----
  document.addEventListener('click', function (e) {
    var deleteBtn = e.target.closest('.btn-delete-story');
    if (deleteBtn) {
      var deleteId = parseInt(deleteBtn.dataset.id, 10);
      if (!deleteId) return;
      var deleteRow = document.getElementById('row-' + deleteId);
      var wasApproved = !!(deleteRow && deleteRow.classList.contains('approved-row'));
      if (!confirm('Delete this story permanently? This cannot be undone.')) return;
      deleteStory(deleteId, function () {
        applyDeleteUiUpdate(deleteId, wasApproved);
        if (currentId === deleteId) {
          var modalEl = document.getElementById('detailModal');
          var modalInstance = bootstrap.Modal.getInstance(modalEl);
          if (modalInstance) modalInstance.hide();
          currentId = null;
          currentApproved = 0;
        }
      });
      return;
    }

    var btn = e.target.closest('.btn-toggle-approve');
    if (!btn) return;
    var id       = parseInt(btn.dataset.id, 10);
    var approved = parseInt(btn.dataset.approved, 10);

    toggleApproval(id, approved, function (newApproved, data) {
      var row            = document.getElementById('row-' + id);
      var badge          = row.querySelector('.status-badge');
      var actionBtn      = row.querySelector('.btn-approve');
      var approvedAtCell = document.getElementById('approved-at-' + id);

      if (newApproved) {
        badge.className     = 'badge bg-success status-badge';
        badge.textContent   = 'Approved';
        row.classList.add('approved-row');
        actionBtn.className = 'btn btn-sm btn-outline-warning btn-toggle-approve btn-approve';
        actionBtn.title     = 'Revoke approval';
        actionBtn.dataset.approved = '1';
        actionBtn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Revoke';
        if (approvedAtCell && data.approved_at) {
          var d = new Date(data.approved_at.replace(' ', 'T'));
          approvedAtCell.innerHTML = d.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) +
            '<small>' + d.toLocaleTimeString('en-GB', {hour:'2-digit', minute:'2-digit'}) + '</small>';
        }
      } else {
        badge.className     = 'badge bg-warning text-dark status-badge';
        badge.textContent   = 'Pending';
        row.classList.remove('approved-row');
        actionBtn.className = 'btn btn-sm btn-success btn-toggle-approve btn-approve';
        actionBtn.title     = 'Approve';
        actionBtn.dataset.approved = '0';
        actionBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Approve';
        if (approvedAtCell) approvedAtCell.innerHTML = '<span class="text-muted">&mdash;</span>';
      }

      // Sync modal if open for same row
      if (currentId == id) {
        currentApproved = newApproved;
        updateModalApprovalUI(newApproved);
        document.getElementById('mApprovedAt').textContent = data.approved_at || '\u2014';
      }
    });
  });

  // ---- Modal approve / revoke buttons ----
  document.getElementById('mApproveBtn').addEventListener('click', function () {
    if (!currentId) return;
    toggleApproval(currentId, 0, function (newApproved, data) {
      currentApproved = newApproved;
      updateModalApprovalUI(newApproved);
      document.getElementById('mApprovedAt').textContent = data.approved_at || '\u2014';
      syncRowFromModal(currentId, newApproved, data);
    });
  });

  document.getElementById('mRevokeBtn').addEventListener('click', function () {
    if (!currentId) return;
    toggleApproval(currentId, 1, function (newApproved, data) {
      currentApproved = newApproved;
      updateModalApprovalUI(newApproved);
      document.getElementById('mApprovedAt').textContent = data.approved_at || '\u2014';
      syncRowFromModal(currentId, newApproved, data);
    });
  });

  document.getElementById('mDeleteBtn').addEventListener('click', function () {
    if (!currentId) return;
    var id = currentId;
    var row = document.getElementById('row-' + id);
    var wasApproved = !!(row && row.classList.contains('approved-row'));
    if (!confirm('Delete this story permanently? This cannot be undone.')) return;
    deleteStory(id, function () {
      applyDeleteUiUpdate(id, wasApproved);
      var modalEl = document.getElementById('detailModal');
      var modalInstance = bootstrap.Modal.getInstance(modalEl);
      if (modalInstance) modalInstance.hide();
      currentId = null;
      currentApproved = 0;
    });
  });

  function syncRowFromModal(id, newApproved, data) {
    var row = document.getElementById('row-' + id);
    if (!row) return;
    var badge          = row.querySelector('.status-badge');
    var actionBtn      = row.querySelector('.btn-approve');
    var approvedAtCell = document.getElementById('approved-at-' + id);
    if (newApproved) {
      badge.className   = 'badge bg-success status-badge';
      badge.textContent = 'Approved';
      row.classList.add('approved-row');
      actionBtn.className        = 'btn btn-sm btn-outline-warning btn-toggle-approve btn-approve';
      actionBtn.title            = 'Revoke approval';
      actionBtn.dataset.approved = '1';
      actionBtn.innerHTML        = '<i class="bi bi-x-circle me-1"></i>Revoke';
      if (approvedAtCell && data && data.approved_at) {
        var d = new Date(data.approved_at.replace(' ', 'T'));
        approvedAtCell.innerHTML = d.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) +
          '<small>' + d.toLocaleTimeString('en-GB', {hour:'2-digit', minute:'2-digit'}) + '</small>';
      }
    } else {
      badge.className   = 'badge bg-warning text-dark status-badge';
      badge.textContent = 'Pending';
      row.classList.remove('approved-row');
      actionBtn.className        = 'btn btn-sm btn-success btn-toggle-approve btn-approve';
      actionBtn.title            = 'Approve';
      actionBtn.dataset.approved = '0';
      actionBtn.innerHTML        = '<i class="bi bi-check-circle me-1"></i>Approve';
      if (approvedAtCell) approvedAtCell.innerHTML = '<span class="text-muted">&mdash;</span>';
    }
  }

}());
</script>

<?php endif; ?>
</body>
</html>
