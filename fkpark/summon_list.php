<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

require 'db.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Only Security can access
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Security') {
    header("Location: login.php?timeout=1");
    exit;
}

$securityUsername = $_SESSION['username'];
$securityName = $securityUsername;

// Get security name
$stmtSecurity = mysqli_prepare($conn, "SELECT name FROM security WHERE staff_id = ?");
if ($stmtSecurity) {
    mysqli_stmt_bind_param($stmtSecurity, "s", $securityUsername);
    mysqli_stmt_execute($stmtSecurity);
    $res = mysqli_stmt_get_result($stmtSecurity);
    if ($row = mysqli_fetch_assoc($res)) {
        $securityName = $row['name'];
    }
    mysqli_stmt_close($stmtSecurity);
}

// Helper: bind params dynamically for mysqli
function bind_params(mysqli_stmt $stmt, string $types, array $params): void {
    $refs = [];
    $refs[] = &$types;
    foreach ($params as $k => $v) {
        $refs[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

// Load violation types for filter dropdown
$violationTypes = [];
$vtRes = mysqli_query($conn, "SELECT violation_typeID, Description, Point FROM violation_type ORDER BY violation_typeID");
if ($vtRes) {
    while ($r = mysqli_fetch_assoc($vtRes)) {
        $violationTypes[] = $r;
    }
}

// Filters
$q = trim($_GET['q'] ?? '');
$filterViolation = (int)($_GET['violation_typeID'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$filterStaff = trim($_GET['staff_id'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build WHERE clause
$where = [];
$types = '';
$params = [];

if ($q !== '') {
    // Search by summon ID, student id, student name, staff id
    $where[] = "(CAST(ts.traffic_summonID AS CHAR) LIKE ? OR ts.student_id LIKE ? OR s.name LIKE ? OR ts.staff_id LIKE ?)";
    $types .= 'ssss';
    $like = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($filterViolation > 0) {
    $where[] = "ts.violation_typeID = ?";
    $types .= 'i';
    $params[] = $filterViolation;
}

if ($filterStaff !== '') {
    $where[] = "ts.staff_id = ?";
    $types .= 's';
    $params[] = $filterStaff;
}

if ($dateFrom !== '') {
    $where[] = "ts.date >= ?";
    $types .= 's';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = "ts.date <= ?";
    $types .= 's';
    $params[] = $dateTo;
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

// Summary cards (global, not filtered)
$summary = [
    'total' => 0,
    'this_month' => 0,
    'last7' => 0,
    'points' => 0,
    'avg_points' => 0.0,
];

// Total summons
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM traffic_summon");
if ($r && ($row = mysqli_fetch_assoc($r))) $summary['total'] = (int)$row['c'];

// This month
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM traffic_summon WHERE DATE_FORMAT(date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
if ($r && ($row = mysqli_fetch_assoc($r))) $summary['this_month'] = (int)$row['c'];

// Last 7 days
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM traffic_summon WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
if ($r && ($row = mysqli_fetch_assoc($r))) $summary['last7'] = (int)$row['c'];

// Total points issued + avg points per student
$r = mysqli_query($conn, "
    SELECT
        COALESCE(SUM(vt.Point), 0) AS total_points,
        COUNT(DISTINCT ts.student_id) AS students
    FROM traffic_summon ts
    JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
");
if ($r && ($row = mysqli_fetch_assoc($r))) {
    $summary['points'] = (int)$row['total_points'];
    $students = (int)$row['students'];
    $summary['avg_points'] = $students > 0 ? round($summary['points'] / $students, 1) : 0.0;
}

// Count for pagination (filtered)
$countSql = "
    SELECT COUNT(*) AS c
    FROM traffic_summon ts
    JOIN student s ON s.student_id = ts.student_id
    JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
    $whereSql
";
$stmtCount = mysqli_prepare($conn, $countSql);
$totalRows = 0;
if ($stmtCount) {
    if ($types !== '') bind_params($stmtCount, $types, $params);
    mysqli_stmt_execute($stmtCount);
    $res = mysqli_stmt_get_result($stmtCount);
    if ($row = mysqli_fetch_assoc($res)) $totalRows = (int)$row['c'];
    mysqli_stmt_close($stmtCount);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

// Fetch rows (filtered)
$listSql = "
    SELECT
        ts.traffic_summonID,
        ts.staff_id,
        sec.name AS staff_name,
        ts.student_id,
        s.name AS student_name,
        ts.date,
        ts.violation_typeID,
        vt.Description AS violation_desc,
        vt.Point AS points
    FROM traffic_summon ts
    JOIN student s ON s.student_id = ts.student_id
    JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
    LEFT JOIN security sec ON sec.staff_id = ts.staff_id
    $whereSql
    ORDER BY ts.traffic_summonID DESC
    LIMIT ? OFFSET ?
";

$stmtList = mysqli_prepare($conn, $listSql);
$rows = [];
if ($stmtList) {
    $typesList = $types . 'ii';
    $paramsList = $params;
    $paramsList[] = $perPage;
    $paramsList[] = $offset;
    bind_params($stmtList, $typesList, $paramsList);
    mysqli_stmt_execute($stmtList);
    $res = mysqli_stmt_get_result($stmtList);
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmtList);
}

// Build base query for pagination links
$qs = $_GET;
unset($qs['page']);
$baseQuery = http_build_query($qs);
$baseQuery = $baseQuery ? ($baseQuery . '&') : '';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Summons List - FKPark</title>
    <style>
        * { box-sizing: border-box; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; background-color: #f3f4f6; color: #111827; }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 260px 1fr; }

        /* Sidebar */
        .sidebar { background-color: #ffffff; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column; padding: 18px 18px 24px; }
        .sidebar-header { display: flex; align-items: center; gap: 10px; padding-bottom: 20px; border-bottom: 1px solid #e5e7eb; }
        .sidebar-logo { width: 40px; height: 40px; border-radius: 12px; background-color: #ffffffff; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .sidebar-logo img { max-width: 100%; max-height: 100%; }
        .sidebar-title { font-size: 18px; font-weight: 600; color: #111827; }
        .nav { margin-top: 24px; display: flex; flex-direction: column; gap: 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 999px; color: #374151; text-decoration: none; font-size: 14px; }
        .nav-link span.icon { width: 18px; text-align: center; }
        .nav-link:hover { background-color: #fee2e2; color: #111827; }
        .nav-link.active { background-color: #f97373; color: #ffffff; }
        .nav-link.active span.icon { color: #ffffff; }
        .sidebar-footer { margin-top: auto; padding-top: 18px; border-top: 1px solid #e5e7eb; }
        .logout-btn { display: flex; align-items: center; gap: 10px; font-size: 14px; color: #ef4444; text-decoration: none; }

        /* Main */
        .main { display: flex; flex-direction: column; }
        .topbar { height: 64px; background-color: #ffffff; display: flex; align-items: center; justify-content: flex-end; padding: 0 32px; box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06); }
        .topbar-user { display: flex; align-items: center; gap: 10px; font-size: 14px; }
        .topbar-user-info { text-align: right; }
        .topbar-role { font-size: 12px; color: #6b7280; }
        .avatar { width: 34px; height: 34px; border-radius: 999px; background-color: #f97316; display: flex; align-items: center; justify-content: center; color: #ffffff; font-weight: 600; }
        .content { padding: 26px 40px 36px; }
        .page-title { font-size: 26px; font-weight: 600; margin: 0; }
        .page-subtitle { margin-top: 6px; color: #6b7280; font-size: 14px; }

        /* Cards */
        .cards { margin-top: 18px; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
        .card { background: #fff; border-radius: 18px; padding: 18px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06); border: 1px solid rgba(229, 231, 235, 0.9); }
        .card-label { color: #6b7280; font-size: 13px; margin-bottom: 10px; }
        .card-value { font-size: 26px; font-weight: 700; }
        .card-hint { margin-top: 6px; font-size: 12px; color: #9ca3af; }

        /* Panel */
        .panel { margin-top: 18px; background: #fff; border-radius: 22px; padding: 18px; box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08); border: 1px solid rgba(229, 231, 235, 0.9); }
        .panel-title { font-size: 16px; font-weight: 700; margin: 0; }
        .filters { margin-top: 14px; display: grid; grid-template-columns: 1.3fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; }
        label { display: block; font-size: 12px; color: #6b7280; margin-bottom: 6px; }
        input, select { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid #e5e7eb; background: #fff; font-size: 14px; outline: none; }
        input:focus, select:focus { border-color: #93c5fd; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.10); }
        .btn { border: none; border-radius: 999px; padding: 10px 14px; font-size: 14px; cursor: pointer; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-ghost { background: #f3f4f6; color: #111827; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }

        /* Table */
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid #eef2f7; font-size: 14px; }
        th { color: #6b7280; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 6px 10px; font-size: 12px; font-weight: 700; }
        .pill-points { background: #eef2ff; color: #4338ca; }
        .pill-violation { background: #fef3c7; color: #92400e; }
        .actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .link { color: #2563eb; text-decoration: none; font-weight: 600; }
        .link:hover { text-decoration: underline; }

        /* Actions: nicer pill buttons for row actions */
        .actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            color: #111827;
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            transition: transform .08s ease, box-shadow .12s ease, background .12s ease;
            box-shadow: 0 1px 0 rgba(0,0,0,0.02);
            line-height: 1;
            white-space: nowrap;
        }
        .btn-action:hover {
            background: #f3f4f6;
            box-shadow: 0 10px 18px rgba(0,0,0,0.06);
            transform: translateY(-1px);
        }
        .btn-action:active { transform: translateY(0px); box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .btn-action.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
        .btn-action.primary:hover { background: #1d4ed8; }
        .btn-action.danger { background: #fff1f2; border-color: #fecdd3; color: #9f1239; }
        .btn-action.danger:hover { background: #ffe4e6; }
        .actions form { display:inline; margin:0; }
        .actions form button { font-family: inherit; }
        td.actions-col { width: 340px; }

        .qr { width: 54px; height: 54px; border-radius: 10px; border: 1px solid #e5e7eb; background: #fff; }

        /* Pagination */
        .pagination { margin-top: 14px; display: flex; gap: 8px; align-items: center; justify-content: flex-end; }
        .page-chip { padding: 8px 12px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; font-size: 13px; color: #111827; text-decoration: none; }
        .page-chip.active { background: #111827; color: #fff; border-color: #111827; }
        .page-chip.disabled { opacity: .4; pointer-events: none; }

        @media (max-width: 1100px) {
            .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .filters { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 740px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .content { padding: 18px; }
        }
    </style>
</head>
<body>
<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <!-- keep same logo style; replace with your own file if needed -->
                <img src="images/logo.png" alt="FKPark" onerror="this.style.display='none'">
            </div>
            <div class="sidebar-title">FKPark</div>
        </div>

        <nav class="nav">
            <a href="dashboard_security.php" class="nav-link"><span class="icon">☰</span><span>Dashboard</span></a>
            <a href="vehicle_approval.php" class="nav-link"><span class="icon">✅</span><span>Vehicle Approvals</span></a>
            <a href="create_summon.php" class="nav-link"><span class="icon">⚠️</span><span>Create Summon</span></a>
            <a href="summon_list.php" class="nav-link active"><span class="icon">📄</span><span>Summons List</span></a>
            <a href="security_profile.php" class="nav-link"><span class="icon">👤</span><span>Profile</span></a>
        </nav>

        <div class="sidebar-footer">
            <a href="login.php?timeout=1" class="logout-btn">← Logout</a>
        </div>
    </aside>

    <!-- Main -->
    <main class="main">
        <div class="topbar">
            <div class="topbar-user">
                <div class="topbar-user-info">
                    <div>Welcome, <?= h($securityName) ?></div>
                    <div class="topbar-role">Security Staff</div>
                </div>
                <div class="avatar"><?= h(strtoupper(substr($securityName, 0, 1))) ?></div>
            </div>
        </div>

        <div class="content">
            <h1 class="page-title">Summons List</h1>
            <div class="page-subtitle">Search, filter, and open summon receipts (QR) for FKPark traffic summons.</div>

            <div class="cards">
                <div class="card">
                    <div class="card-label">Total Summons</div>
                    <div class="card-value"><?= (int)$summary['total'] ?></div>
                    <div class="card-hint">All-time</div>
                </div>
                <div class="card">
                    <div class="card-label">Summons This Month</div>
                    <div class="card-value"><?= (int)$summary['this_month'] ?></div>
                    <div class="card-hint">Current month</div>
                </div>
                <div class="card">
                    <div class="card-label">Last 7 Days</div>
                    <div class="card-value"><?= (int)$summary['last7'] ?></div>
                    <div class="card-hint">Recent activity</div>
                </div>
                <div class="card">
                    <div class="card-label">Total Points Issued</div>
                    <div class="card-value"><?= (int)$summary['points'] ?></div>
                    <div class="card-hint">Avg per student: <?= h($summary['avg_points']) ?></div>
                </div>
            </div>

            <section class="panel">
                <h2 class="panel-title">Filter & Results</h2>

                <form method="get" class="filters">
                    <div>
                        <label>Search (Summon ID / Student / Staff)</label>
                        <input type="text" name="q" value="<?= h($q) ?>" placeholder="e.g. 12, s23015, Ali Hassan">
                    </div>
                    <div>
                        <label>Violation Type</label>
                        <select name="violation_typeID">
                            <option value="0">All</option>
                            <?php foreach ($violationTypes as $vt): ?>
                                <option value="<?= (int)$vt['violation_typeID'] ?>" <?= $filterViolation === (int)$vt['violation_typeID'] ? 'selected' : '' ?>>
                                    <?= h($vt['Description']) ?> (<?= (int)$vt['Point'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Date From</label>
                        <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
                    </div>
                    <div>
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?= h($dateTo) ?>">
                    </div>
                    <div style="display:flex; gap:10px;">
                        <button class="btn btn-primary" type="submit">Apply</button>
                        <a class="btn btn-ghost" href="summon_list.php">Reset</a>
                    </div>
                </form>

                <div style="margin-top:12px; color:#6b7280; font-size:13px;">
                    Showing <b><?= (int)$totalRows ?></b> result(s). Page <?= (int)$page ?> of <?= (int)$totalPages ?>.
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>QR</th>
                            <th>Summon</th>
                            <th>Student</th>
                            <th>Violation</th>
                            <th>Date</th>
                            <th>Issued By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7" style="padding: 18px; color:#6b7280;">No summons found for the selected filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r):
                            $id = (int)$r['traffic_summonID'];
                            $receiptUrl = "summon_view.php?id={$id}";
                            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" . urlencode($receiptUrl);
                        ?>
                        <tr>
                            <td>
                                <img class="qr" src="<?= h($qrUrl) ?>" alt="QR">
                            </td>
                            <td>
                                <div style="font-weight:800;">#<?= $id ?></div>
                                <div style="color:#6b7280; font-size:12px;">Type ID: <?= (int)$r['violation_typeID'] ?></div>
                            </td>
                            <td>
                                <div style="font-weight:700;"><?= h($r['student_name']) ?></div>
                                <div style="color:#6b7280; font-size:12px;"><?= h($r['student_id']) ?></div>
                            </td>
                            <td>
                                <span class="pill pill-violation"><?= h($r['violation_desc']) ?></span>
                                <span class="pill pill-points"><?= (int)$r['points'] ?> pts</span>
                            </td>
                            <td><?= h($r['date']) ?></td>
                            <td>
                                <div style="font-weight:700;"><?= h($r['staff_name'] ?: $r['staff_id']) ?></div>
                                <div style="color:#6b7280; font-size:12px;"><?= h($r['staff_id']) ?></div>
                            </td>
                            <td class="actions-col">
                                <div class="actions">
                                    <a class="btn-action" href="<?= h($receiptUrl) ?>" target="_blank" rel="noopener">View Receipt</a>
                                    <a class="btn-action" href="<?= h($qrUrl) ?>" target="_blank" rel="noopener">Open QR</a>
                                    <a class="btn-action primary" href="edit_summon.php?id=<?= (int)$id ?>">Edit</a>

                                    <form method="post" action="delete_summon.php" style="display:inline;">
                                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                                        <button type="submit" class="btn-action danger"
                                        onclick="return confirm('Delete this summon permanently? This cannot be undone.');">
                                        Delete
                                        </button>
                                    </form>
                                </div>

                            </td>

                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <div class="pagination">
                    <?php
                        $prev = max(1, $page - 1);
                        $next = min($totalPages, $page + 1);
                    ?>
                    <a class="page-chip <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= h($baseQuery) ?>page=<?= $prev ?>">Prev</a>

                    <?php
                        // show up to 5 page chips around current page
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        if ($end - $start < 4) {
                            $start = max(1, $end - 4);
                            $end = min($totalPages, $start + 4);
                        }
                        for ($p = $start; $p <= $end; $p++):
                    ?>
                        <a class="page-chip <?= $p === $page ? 'active' : '' ?>" href="?<?= h($baseQuery) ?>page=<?= $p ?>"><?= $p ?></a>
                    <?php endfor; ?>

                    <a class="page-chip <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= h($baseQuery) ?>page=<?= $next ?>">Next</a>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
