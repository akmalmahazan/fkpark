<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
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

$securityUsername = $_SESSION['username']; // this is staff_id in your schema
$securityName = $securityUsername;

// Get security name
$sqlSecurity = "SELECT name FROM security WHERE staff_id = ?";
$stmtSecurity = mysqli_prepare($conn, $sqlSecurity);
if ($stmtSecurity) {
    mysqli_stmt_bind_param($stmtSecurity, "s", $securityUsername);
    mysqli_stmt_execute($stmtSecurity);
    $result = mysqli_stmt_get_result($stmtSecurity);
    if ($row = mysqli_fetch_assoc($result)) {
        $securityName = $row['name'];
    }
    mysqli_stmt_close($stmtSecurity);
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$flash = "";
$flashType = "success"; // success | error

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $postedToken)) {
        $flash = "Security token mismatch. Please try again.";
        $flashType = "error";
    } else {
        $approvalId = (int)($_POST['approval_id'] ?? 0);
        $action = $_POST['action'] ?? '';

        if ($approvalId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
            $flash = "Invalid request.";
            $flashType = "error";
        } else {
            $newStatus = $action === 'approve' ? 'Approved' : 'Rejected';

            mysqli_begin_transaction($conn);
            try {
                // 1) Update vehicle_approval row
                $sqlUpdateApproval = "UPDATE vehicle_approval
                                      SET status = ?, approve_by = ?, approval_date = NOW()
                                      WHERE approval_id = ?";
                $stmt = mysqli_prepare($conn, $sqlUpdateApproval);
                if (!$stmt) {
                    throw new Exception("Failed to prepare approval update.");
                }
                mysqli_stmt_bind_param($stmt, "ssi", $newStatus, $securityUsername, $approvalId);
                mysqli_stmt_execute($stmt);
                $affected = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($affected <= 0) {
                    throw new Exception("Approval record not updated (maybe already processed?).");
                }

                // 2) Find vehicle_id from approval
                $sqlGetVehicleId = "SELECT vehicle_id FROM vehicle_approval WHERE approval_id = ?";
                $stmt = mysqli_prepare($conn, $sqlGetVehicleId);
                if (!$stmt) {
                    throw new Exception("Failed to prepare vehicle lookup.");
                }
                mysqli_stmt_bind_param($stmt, "i", $approvalId);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $vehRow = mysqli_fetch_assoc($res);
                mysqli_stmt_close($stmt);

                if (!$vehRow) {
                    throw new Exception("Vehicle not found for this approval.");
                }

                $vehicleId = (int)$vehRow['vehicle_id'];

                // 3) Update vehicle.status so student dashboard reflects it
                $sqlUpdateVehicle = "UPDATE vehicle SET status = ? WHERE vehicle_id = ?";
                $stmt = mysqli_prepare($conn, $sqlUpdateVehicle);
                if (!$stmt) {
                    throw new Exception("Failed to prepare vehicle status update.");
                }
                mysqli_stmt_bind_param($stmt, "si", $newStatus, $vehicleId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);

                $flash = $newStatus === 'Approved'
                    ? "Vehicle approved successfully."
                    : "Vehicle rejected successfully.";
                $flashType = "success";

            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $flash = "Action failed: " . $e->getMessage();
                $flashType = "error";
            }
        }
    }
}

// Filters
$statusFilter = $_GET['status'] ?? 'Pending';
if (!in_array($statusFilter, ['Pending', 'Approved', 'Rejected', 'All'], true)) {
    $statusFilter = 'Pending';
}
$search = trim($_GET['q'] ?? '');

// Summary counts
$counts = [
    'Pending' => 0,
    'Approved' => 0,
    'Rejected' => 0
];

$sqlCounts = "SELECT status, COUNT(*) AS c FROM vehicle_approval GROUP BY status";
$resCounts = mysqli_query($conn, $sqlCounts);
if ($resCounts) {
    while ($r = mysqli_fetch_assoc($resCounts)) {
        $st = $r['status'];
        if (isset($counts[$st])) {
            $counts[$st] = (int)$r['c'];
        }
    }
}

// Build list query
$where = [];
$params = [];
$types = "";

if ($statusFilter !== 'All') {
    $where[] = "va.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($search !== '') {
    // Search plate number, student id, student name, vehicle type/brand
    $where[] = "(v.plate_num LIKE ? OR v.type LIKE ? OR v.brand LIKE ? OR v.student_id LIKE ? OR s.name LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like);
    $types .= "sssss";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sqlList = "SELECT
                va.approval_id,
                va.status AS approval_status,
                va.approval_date,
                va.approve_by,
                va.grant_file,
                v.vehicle_id,
                v.plate_num,
                v.type,
                v.brand,
                v.status AS vehicle_status,
                v.registration_date,
                v.student_id,
                s.name AS student_name
            FROM vehicle_approval va
            INNER JOIN vehicle v ON v.vehicle_id = va.vehicle_id
            INNER JOIN student s ON s.student_id = v.student_id
            $whereSql
            ORDER BY
                CASE va.status
                    WHEN 'Pending' THEN 0
                    WHEN 'Approved' THEN 1
                    WHEN 'Rejected' THEN 2
                    ELSE 3
                END,
                va.approval_id DESC";

$stmtList = mysqli_prepare($conn, $sqlList);
$rows = [];
if ($stmtList) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmtList, $types, ...$params);
    }
    mysqli_stmt_execute($stmtList);
    $result = mysqli_stmt_get_result($stmtList);
    while ($r = mysqli_fetch_assoc($result)) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmtList);
} else {
    $flash = "Failed to load approvals list. Check SQL / schema.";
    $flashType = "error";
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function badgeClass($status) {
    switch ($status) {
        case 'Approved': return 'badge green';
        case 'Rejected': return 'badge red';
        default: return 'badge yellow';
    }
}

// grant_file is a MEDIUMBLOB containing a path string in your dump.
function grantPathFromBlob($blob) {
    if ($blob === null) return '';
    // mysqli returns BLOB as a PHP string of bytes
    $s = (string)$blob;
    $s = trim($s);
    // Basic hardening: block obvious protocol injections
    if (preg_match('/^(https?:|javascript:|data:)/i', $s)) {
        return '';
    }
    return $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vehicle Approvals - FKPark</title>
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
        .page-subtitle { margin-top: 6px; font-size: 14px; color: #6b7280; }

        .summary-row { margin-top: 20px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
        .summary-card { background-color: #ffffff; border-radius: 22px; padding: 16px 18px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06); display: flex; align-items: center; justify-content: space-between; }
        .summary-left { display: flex; align-items: center; gap: 12px; }
        .summary-icon { width: 46px; height: 46px; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .summary-icon.yellow { background-color: #fef3c7; color: #92400e; }
        .summary-icon.green { background-color: #dcfce7; color: #166534; }
        .summary-icon.red { background-color: #fee2e2; color: #b91c1c; }
        .summary-label { font-size: 13px; color: #6b7280; }
        .summary-value { font-size: 24px; font-weight: 600; margin-top: 2px; }

        .card { margin-top: 22px; background-color: #ffffff; border-radius: 24px; padding: 18px 20px 20px; box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08); }
        .card-title { font-size: 16px; font-weight: 600; margin: 0 0 10px; }
        .toolbar { display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 12px; }
        .filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

        select, input[type="text"] {
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            outline: none;
            font-size: 14px;
            background: #fff;
        }

        .btn { padding: 10px 14px; border: 0; border-radius: 999px; cursor: pointer; font-size: 13px; font-weight: 600; }
        .btn.primary { background-color: #2563eb; color: #fff; }
        .btn.ghost { background-color: #f3f4f6; color: #111827; }
        .btn.approve { background-color: #16a34a; color: #fff; }
        .btn.reject { background-color: #dc2626; color: #fff; }

        .flash { margin-top: 16px; padding: 12px 14px; border-radius: 14px; font-size: 14px; }
        .flash.success { background: #dcfce7; color: #166534; }
        .flash.error { background: #fee2e2; color: #991b1b; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px 10px; text-align: left; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { font-weight: 700; color: #6b7280; }
        tr:last-child td { border-bottom: none; }

        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 999px; font-weight: 700; font-size: 12px; }
        .badge.yellow { background: #fef3c7; color: #92400e; }
        .badge.green { background: #dcfce7; color: #166534; }
        .badge.red { background: #fee2e2; color: #991b1b; }

        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .muted { color: #6b7280; }
        .link { color: #2563eb; text-decoration: none; font-weight: 600; }
        .link:hover { text-decoration: underline; }

        @media (max-width: 1100px) {
            .summary-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .content { padding: 20px 22px 28px; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="images/logo.png" alt="FKPark Logo">
            </div>
            <div class="sidebar-title">FKPark</div>
        </div>

        <nav class="nav">
            <a href="dashboard_security.php" class="nav-link">
                <span class="icon">☰</span>
                <span>Dashboard</span>
            </a>
            <a href="vehicle_approval.php" class="nav-link active">
                <span class="icon">✅</span>
                <span>Vehicle Approvals</span>
            </a>
            <a href="create_summon.php" class="nav-link">
                <span class="icon">⚠️</span>
                <span>Create Summon</span>
            </a>
            <a href="summon_list.php" class="nav-link">
                <span class="icon">📄</span>
                <span>Summons List</span>
            </a>
            <a href="security_profile.php" class="nav-link">
                <span class="icon">👤</span>
                <span>Profile</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">
                <span>⟵</span>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar-user">
                <div class="topbar-user-info">
                    <div>Welcome, <?php echo h($securityName); ?></div>
                    <div class="topbar-role">Security Staff</div>
                </div>
                <div class="avatar"><?php echo strtoupper(substr($securityName, 0, 1)); ?></div>
            </div>
        </header>

        <main class="content">
            <h1 class="page-title">Vehicle Approvals</h1>
            <p class="page-subtitle">Approve or reject pending vehicle registrations (student dashboard status will update).</p>

            <?php if ($flash !== ''): ?>
                <div class="flash <?php echo h($flashType); ?>"><?php echo h($flash); ?></div>
            <?php endif; ?>

            <div class="summary-row">
                <div class="summary-card">
                    <div class="summary-left">
                        <div class="summary-icon yellow">⏳</div>
                        <div>
                            <div class="summary-label">Pending</div>
                            <div class="summary-value"><?php echo (int)$counts['Pending']; ?></div>
                        </div>
                    </div>
                    <a class="link" href="?status=Pending">View</a>
                </div>
                <div class="summary-card">
                    <div class="summary-left">
                        <div class="summary-icon green">✅</div>
                        <div>
                            <div class="summary-label">Approved</div>
                            <div class="summary-value"><?php echo (int)$counts['Approved']; ?></div>
                        </div>
                    </div>
                    <a class="link" href="?status=Approved">View</a>
                </div>
                <div class="summary-card">
                    <div class="summary-left">
                        <div class="summary-icon red">⛔</div>
                        <div>
                            <div class="summary-label">Rejected</div>
                            <div class="summary-value"><?php echo (int)$counts['Rejected']; ?></div>
                        </div>
                    </div>
                    <a class="link" href="?status=Rejected">View</a>
                </div>
            </div>

            <section class="card">
                <h2 class="card-title">Requests</h2>

                <form class="toolbar" method="GET" action="">
                    <div class="filters">
                        <label class="muted" for="status">Status</label>
                        <select id="status" name="status">
                            <option value="Pending" <?php echo $statusFilter==='Pending'?'selected':''; ?>>Pending</option>
                            <option value="Approved" <?php echo $statusFilter==='Approved'?'selected':''; ?>>Approved</option>
                            <option value="Rejected" <?php echo $statusFilter==='Rejected'?'selected':''; ?>>Rejected</option>
                            <option value="All" <?php echo $statusFilter==='All'?'selected':''; ?>>All</option>
                        </select>

                        <input type="text" name="q" placeholder="Search plate / student / brand" value="<?php echo h($search); ?>">
                        <button class="btn primary" type="submit">Filter</button>
                        <a class="btn ghost" href="vehicle_approval.php">Reset</a>
                    </div>
                </form>

                <div style="overflow:auto;">
                    <table>
                        <thead>
                        <tr>
                            <th>Approval</th>
                            <th>Plate</th>
                            <th>Vehicle</th>
                            <th>Student</th>
                            <th>Registered</th>
                            <th>Grant File</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="8" class="muted">No records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $grantPath = grantPathFromBlob($r['grant_file']);
                                    $status = $r['approval_status'] ?? 'Pending';
                                ?>
                                <tr>
                                    <td>
                                        <div><strong>#<?php echo (int)$r['approval_id']; ?></strong></div>
                                        <?php if (!empty($r['approval_date'])): ?>
                                            <div class="muted"><?php echo h($r['approval_date']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['approve_by'])): ?>
                                            <div class="muted">By: <?php echo h($r['approve_by']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo h($r['plate_num']); ?></strong></td>
                                    <td>
                                        <div><?php echo h($r['type']); ?></div>
                                        <div class="muted"><?php echo h($r['brand']); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo h($r['student_name']); ?></div>
                                        <div class="muted"><?php echo h($r['student_id']); ?></div>
                                    </td>
                                    <td><?php echo h($r['registration_date']); ?></td>
                                    <td>
                                        <?php if ($grantPath !== ''): ?>
                                            <a class="link" href="<?php echo h($grantPath); ?>" target="_blank" rel="noopener">View</a>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="<?php echo h(badgeClass($status)); ?>"><?php echo h($status); ?></span></td>
                                    <td>
                                        <?php if ($status === 'Pending'): ?>
                                            <div class="actions">
                                                <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Approve this vehicle?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                    <input type="hidden" name="approval_id" value="<?php echo (int)$r['approval_id']; ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <button class="btn approve" type="submit">Approve</button>
                                                </form>
                                                <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Reject this vehicle?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                    <input type="hidden" name="approval_id" value="<?php echo (int)$r['approval_id']; ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <button class="btn reject" type="submit">Reject</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</div>
</body>
</html>
