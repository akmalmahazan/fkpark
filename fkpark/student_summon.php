<?php
// student_summons.php
// Student view for summons + notification-style "unread" tracking (optional table).

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header("Location: login.php?timeout=1");
    exit;
}

$studentId = $_SESSION['username'];

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Fetch student display info
$student = ['name' => $studentId];
$stmt = $conn->prepare("SELECT name FROM student WHERE student_id = ?");
$stmt->bind_param('s', $studentId);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $student['name'] = $row['name'];
}
$stmt->close();

// Optional: check if notification table exists
$hasNotifTable = false;
$chk = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'summon_notification'");
$chk->execute();
$c = $chk->get_result()->fetch_assoc();
$hasNotifTable = ((int)($c['c'] ?? 0) > 0);
$chk->close();

// Mark as read action (only works if summon_notification table exists)
$flash = ['ok' => null, 'err' => null];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read']) && $hasNotifTable) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash['err'] = 'Invalid request token.';
    } else {
        $summonId = (int)($_POST['traffic_summonID'] ?? 0);
        if ($summonId > 0) {
            // Upsert read status
            $stmt = $conn->prepare(
                "INSERT INTO summon_notification (traffic_summonID, student_id, is_read, read_at)
                 VALUES (?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE is_read=1, read_at=NOW()"
            );
            $stmt->bind_param('is', $summonId, $studentId);
            if ($stmt->execute()) {
                $flash['ok'] = 'Marked as read.';
            } else {
                $flash['err'] = 'Failed to mark as read.';
            }
            $stmt->close();
        }
    }
}

// Filters
$status = $_GET['status'] ?? 'all'; // all | recent | unread
$q = trim($_GET['q'] ?? '');

// Build query
$where = "ts.student_id = ?";
$types = 's';
$params = [$studentId];

// "recent" = last 30 days (simple notification-like view)
if ($status === 'recent') {
    $where .= " AND ts.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
}

// "unread" requires notification table; if not available, we approximate by last 7 days
if ($status === 'unread') {
    if ($hasNotifTable) {
        $where .= " AND (sn.is_read IS NULL OR sn.is_read = 0)";
    } else {
        $where .= " AND ts.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    }
}

if ($q !== '') {
    $where .= " AND (vt.Description LIKE CONCAT('%', ?, '%') OR dp.description LIKE CONCAT('%', ?, '%') OR ts.traffic_summonID = ?)";
    $types .= 'ssi';
    $params[] = $q;
    $params[] = $q;
    $params[] = (int)$q;
}

// Main list
$sql = "
    SELECT
        ts.traffic_summonID,
        ts.date AS summon_date,
        ts.staff_id,
        vt.Description AS violation_desc,
        vt.Point AS violation_point,
        dp.demerit_id,
        dp.total AS demerit_total,
        dp.description AS demerit_desc,
        IFNULL(sn.is_read, 0) AS is_read
    FROM traffic_summon ts
    JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
    LEFT JOIN demerit_point dp ON dp.traffic_summonID = ts.traffic_summonID AND dp.student_id = ts.student_id
    " . ($hasNotifTable ? "LEFT JOIN summon_notification sn ON sn.traffic_summonID = ts.traffic_summonID AND sn.student_id = ts.student_id" : "LEFT JOIN (SELECT 0 AS is_read, 0 AS traffic_summonID, '' AS student_id) sn ON 1=0") . "
    WHERE $where
    ORDER BY ts.traffic_summonID DESC
";

$stmt = $conn->prepare($sql);
// bind params dynamically
$stmt->bind_param($types, ...$params);
$stmt->execute();
$list = $stmt->get_result();
$stmt->close();

// Summary counts
$countAll = 0;
$countRecent = 0;
$countUnread = 0;

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM traffic_summon WHERE student_id = ?");
$stmt->bind_param('s', $studentId);
$stmt->execute();
$countAll = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM traffic_summon WHERE student_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$stmt->bind_param('s', $studentId);
$stmt->execute();
$countRecent = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

if ($hasNotifTable) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c
         FROM traffic_summon ts
         LEFT JOIN summon_notification sn
           ON sn.traffic_summonID = ts.traffic_summonID AND sn.student_id = ts.student_id
         WHERE ts.student_id = ? AND (sn.is_read IS NULL OR sn.is_read = 0)"
    );
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $countUnread = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
} else {
    // fallback approximation
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM traffic_summon WHERE student_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $countUnread = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

// helper for active nav
function navActive($target){
    $current = basename($_SERVER['PHP_SELF']);
    return $current === $target ? 'active' : '';
}

// ===== Merit system (no DB changes) =====
// Merit starts at 100 and is deducted by violation points.
function getMeritStatus(mysqli $conn, string $studentId): array
{
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(vt.Point), 0) AS total_deducted
        FROM traffic_summon ts
        JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
        WHERE ts.student_id = ?
    ");
    $stmt->bind_param("s", $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $totalDeducted = (int)($row['total_deducted'] ?? 0);
    $currentMerit = max(0, 100 - $totalDeducted);

    // Enforcement rules (Table A) based on accumulated deducted points
    if ($totalDeducted < 20) {
        $enforcementStatus = "Warning given";
    } elseif ($totalDeducted < 50) {
        $enforcementStatus = "Revoke of in campus vehicle permission for 1 semester";
    } elseif ($totalDeducted < 80) {
        $enforcementStatus = "Revoke of in campus vehicle permission for 2 semesters";
    } else { // >= 80
        $enforcementStatus = "Revoke of in campus vehicle permission for the entire study duration";
    }

    return [
        'currentMerit' => $currentMerit,
        'totalDeducted' => $totalDeducted,
        'enforcementStatus' => $enforcementStatus
    ];
}

$meritInfo = getMeritStatus($conn, $studentId);
$currentMerit = (int)$meritInfo['currentMerit'];
$totalDeducted = (int)$meritInfo['totalDeducted'];
$enforcementStatus = (string)$meritInfo['enforcementStatus'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Summons & Merit - FKPark</title>
    <style>
        *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        body{margin:0;background:#f3f4f6;color:#111827}
        .layout{min-height:100vh;display:grid;grid-template-columns:260px 1fr}
        .sidebar{background:#fff;border-right:1px solid #e5e7eb;display:flex;flex-direction:column;padding:18px 18px 24px}
        .sidebar-header{display:flex;align-items:center;gap:10px;padding-bottom:20px;border-bottom:1px solid #e5e7eb}
        .sidebar-logo { width: 40px; height: 40px; border-radius: 12px; background-color: #ffffffff; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .sidebar-logo img { max-width: 100%; max-height: 100%; }
        .sidebar-title{font-size:18px;font-weight:600;color:#111827}
        .nav{margin-top:24px;display:flex;flex-direction:column;gap:6px}
        .nav-link{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:999px;color:#374151;text-decoration:none;font-size:14px}
        .nav-link span.icon{width:18px;text-align:center}
        .nav-link:hover{background:#eff6ff;color:#111827}
        .nav-link.active{background:#0066ff;color:#fff}
        .nav-link.active:hover{background:#0053d6}
        .logout{margin-top:auto;padding-top:18px;border-top:1px solid #e5e7eb}
        .logout a{display:flex;align-items:center;gap:10px;color:#ef4444;text-decoration:none;font-size:14px;padding:10px 12px;border-radius:10px}
        .logout a:hover{background:#fef2f2}

        .main{display:flex;flex-direction:column}
        .topbar{height:64px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:flex-end;padding:0 22px;gap:14px}
        .user{display:flex;align-items:center;gap:12px}
        .user-meta{display:flex;flex-direction:column;line-height:1.1;text-align:right}
        .user-meta .welcome{font-size:14px;color:#111827}
        .user-meta .role{font-size:12px;color:#6b7280}
        .avatar{width:34px;height:34px;border-radius:999px;background:#0ea5e9;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}

        .content{padding:28px 28px 40px}
        h1{margin:0 0 6px;font-size:28px}
        .subtitle{color:#6b7280;margin-bottom:18px}

        .cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 10px 30px rgba(17,24,39,.06)}
        .card .label{color:#6b7280;font-size:12px}
        .card .value{font-size:22px;font-weight:800;margin-top:4px}
        .pill{display:inline-flex;align-items:center;gap:8px;border:1px solid #e5e7eb;background:#f9fafb;color:#111827;padding:8px 12px;border-radius:999px;text-decoration:none;font-size:13px}
        .pill.active{background:#0066ff;color:#fff;border-color:#0066ff}

        .panel{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(17,24,39,.06)}
        .panel-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
        .search{display:flex;align-items:center;gap:10px}
        .search input{width:280px;max-width:65vw;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;outline:none}
        .btn{border:0;border-radius:999px;padding:10px 14px;font-weight:700;cursor:pointer}
        .btn-primary{background:#0066ff;color:#fff}
        .btn-primary:hover{background:#0053d6}
        .btn-ghost{background:#f3f4f6;color:#111827}

        table{width:100%;border-collapse:separate;border-spacing:0 10px}
        th{font-size:12px;color:#6b7280;text-align:left;padding:0 10px}
        td{background:#f9fafb;border:1px solid #e5e7eb;padding:12px 10px;vertical-align:middle}
        tr td:first-child{border-top-left-radius:14px;border-bottom-left-radius:14px}
        tr td:last-child{border-top-right-radius:14px;border-bottom-right-radius:14px}
        .tag{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;font-weight:700;font-size:12px}
        .tag-new{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
        .tag-read{background:#ecfeff;color:#155e75;border:1px solid #a5f3fc}
        .qr{width:86px;height:86px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden}
        .qr img{width:100%;height:100%;object-fit:cover}
        .actions{display:flex;gap:8px;flex-wrap:wrap}
        .link{color:#0066ff;text-decoration:none;font-weight:700}
        .link:hover{text-decoration:underline}
        .flash{margin-bottom:12px;padding:12px 14px;border-radius:14px;border:1px solid}
        .ok{background:#ecfdf5;border-color:#10b98133;color:#065f46}
        .err{background:#fef2f2;border-color:#ef444433;color:#7f1d1d}

        @media (max-width: 980px){
            .layout{grid-template-columns:1fr}
            .sidebar{display:none}
            .cards{grid-template-columns:1fr}
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo"><img src="images/logo-removebg.png" alt="FKPark Logo"></div>
            <div class="sidebar-title">FKPark</div>
        </div>
        <nav class="nav">
            <a class="nav-link" href="dashboard_stud.php"><span class="icon">☰</span><span>Dashboard</span></a>
            <a class="nav-link" href="student_vehicles.php"><span class="icon">🚘</span><span>My Vehicles</span></a>
            <a class="nav-link" href="book_parking.php"><span class="icon">📅</span><span>Book Parking</span></a>
            <a class="nav-link" href="my_bookings.php"><span class="icon">📄</span><span>My Bookings</span></a>
            <a class="nav-link active" href="student_summon.php"><span class="icon">⚠️</span><span>Summons &amp; Demerit</span></a>
            <a class="nav-link" href="student_profile.php"><span class="icon">👤</span><span>Profile</span></a>
        </nav>
        <div class="logout">
            <a href="logout.php">⟵ Logout</a>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div class="user">
                <div class="user-meta">
                    <div class="welcome">Welcome, <?php echo esc($student['name']); ?></div>
                    <div class="role">Student</div>
                </div>
                <div class="avatar"><?php echo esc(strtoupper(substr($student['name'], 0, 1))); ?></div>
            </div>
        </div>

        <div class="content">
            <h1>Summons &amp; Demerit</h1>
            <div class="subtitle">View your traffic summons and scan/print the QR receipt.</div>

            <?php if ($flash['ok']): ?><div class="flash ok"><?php echo esc($flash['ok']); ?></div><?php endif; ?>
            <?php if ($flash['err']): ?><div class="flash err"><?php echo esc($flash['err']); ?></div><?php endif; ?>

            <div class="cards">
                <div class="card"><div><div class="label">Total Summons</div><div class="value"><?php echo $countAll; ?></div></div><a class="pill <?php echo $status==='all'?'active':''; ?>" href="?status=all">All</a></div>
                <div class="card"><div><div class="label">Recent (30 days)</div><div class="value"><?php echo $countRecent; ?></div></div><a class="pill <?php echo $status==='recent'?'active':''; ?>" href="?status=recent">Recent</a></div>
                <div class="card"><div><div class="label"><?php echo $hasNotifTable ? 'Unread' : 'New (7 days)'; ?></div><div class="value"><?php echo $countUnread; ?></div></div><a class="pill <?php echo $status==='unread'?'active':''; ?>" href="?status=unread"><?php echo $hasNotifTable ? 'Unread' : 'New'; ?></a></div>
            </div>

            <section class="panel">
                <div class="panel-header">
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <a class="pill <?php echo $status==='all'?'active':''; ?>" href="?status=all<?php echo $q!==''?'&q='.urlencode($q):''; ?>">All</a>
                        <a class="pill <?php echo $status==='recent'?'active':''; ?>" href="?status=recent<?php echo $q!==''?'&q='.urlencode($q):''; ?>">Recent</a>
                        <a class="pill <?php echo $status==='unread'?'active':''; ?>" href="?status=unread<?php echo $q!==''?'&q='.urlencode($q):''; ?>"><?php echo $hasNotifTable ? 'Unread' : 'New'; ?></a>
                    </div>

                    <form class="search" method="get">
                        <input type="hidden" name="status" value="<?php echo esc($status); ?>">
                        <input type="text" name="q" value="<?php echo esc($q); ?>" placeholder="Search summon id / violation / description">
                        <button class="btn btn-primary" type="submit">Search</button>
                        <a class="btn btn-ghost" href="student_summon.php">Clear</a>
                    </form>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>QR Receipt</th>
                            <th>Summon</th>
                            <th>Violation</th>
                            <th>Demerit</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($list->num_rows === 0): ?>
                        <tr><td colspan="6" style="background:#fff;border:0;color:#6b7280;padding:18px 6px;">No summons found.</td></tr>
                    <?php else: ?>
                        <?php while ($r = $list->fetch_assoc()):
                            $id = (int)$r['traffic_summonID'];
                            $qrUrl = 'summon_view.php?id=' . urlencode((string)$id);
                            $qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrUrl);
                            $isRead = ((int)$r['is_read'] === 1);
                        ?>
                        <tr>
                            <td>
                                <div class="qr" title="Scan to open summon view">
                                    <a href="<?php echo esc($qrUrl); ?>" target="_blank" rel="noopener">
                                        <img src="<?php echo esc($qrImg); ?>" alt="QR">
                                    </a>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight:800">#<?php echo $id; ?></div>
                                <div style="color:#6b7280;font-size:12px">Date: <?php echo esc($r['summon_date']); ?> • Staff: <?php echo esc($r['staff_id']); ?></div>
                            </td>
                            <td>
                                <div style="font-weight:700"><?php echo esc($r['violation_desc']); ?></div>
                                <div style="color:#6b7280;font-size:12px">Points: <?php echo (int)$r['violation_point']; ?></div>
                            </td>
                            <td>
                                <div style="font-weight:700"><?php echo esc($r['demerit_desc'] ?? $r['violation_desc']); ?></div>
                                <div style="color:#6b7280;font-size:12px">Total: <?php echo (int)($r['demerit_total'] ?? $r['violation_point']); ?></div>
                            </td>
                            <td>
                                <?php if ($hasNotifTable): ?>
                                    <span class="tag <?php echo $isRead ? 'tag-read' : 'tag-new'; ?>"><?php echo $isRead ? 'Read' : 'New'; ?></span>
                                <?php else: ?>
                                    <span class="tag tag-read">Recorded</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <a class="link" href="<?php echo esc($qrUrl); ?>" target="_blank" rel="noopener">View</a>
                                    <a class="link" href="<?php echo esc($qrImg); ?>" target="_blank" rel="noopener">Open QR</a>
                                    <?php if ($hasNotifTable && !$isRead): ?>
                                        <form method="post" style="margin:0">
                                            <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                                            <input type="hidden" name="traffic_summonID" value="<?php echo $id; ?>">
                                            <button class="btn btn-ghost" type="submit" name="mark_read">Mark read</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if (!$hasNotifTable): ?>
                    <div style="margin-top:12px;color:#6b7280;font-size:12px;line-height:1.45">
                        <strong>Optional:</strong> If you want true unread/read notifications, create the <code>summon_notification</code> table (SQL provided in my message). Without it, the page shows "New" as summons created in the last 7 days.
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
