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

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Get security staff name (fallback to username)
$securityUsername = $_SESSION['username'];
$securityName = $securityUsername;
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

$studentId = trim($_GET['student_id'] ?? '');

$studentName = null;
$latestVehicle = null;
$demeritPoints = 0;
$enforcementLabel = '—';
$enforcementTone = 'info';

if ($studentId !== '') {
    // Student name
    $stmt = $conn->prepare("SELECT name FROM student WHERE student_id = ?");
    if ($stmt) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $studentName = $row['name'] ?? null;
        $stmt->close();
    }

    // Latest vehicle + latest approval
    $sqlLatest = "
        SELECT v.vehicle_id, v.plate_num, v.type, v.brand,
               COALESCE(va.status, 'Pending') AS approval_status,
               va.approve_by, va.approval_date
        FROM vehicle v
        LEFT JOIN vehicle_approval va ON va.vehicle_id = v.vehicle_id
            AND va.approval_id = (
                SELECT MAX(approval_id)
                FROM vehicle_approval
                WHERE vehicle_id = v.vehicle_id
            )
        WHERE v.student_id = ?
        ORDER BY v.registration_date DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare($sqlLatest);
    if ($stmt) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $latestVehicle = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    // Total demerit points
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(vt.Point), 0) AS total_demerit
        FROM traffic_summon ts
        JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
        WHERE ts.student_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $demeritPoints = (int)($row['total_demerit'] ?? 0);
        $stmt->close();
    }

    // Enforcement status (Table A)
    if ($demeritPoints < 20) {
        $enforcementLabel = 'Warning given';
        $enforcementTone = 'info';
    } elseif ($demeritPoints < 50) {
        $enforcementLabel = 'Revoke in campus vehicle permission for 1 semester';
        $enforcementTone = 'warn';
    } elseif ($demeritPoints < 80) {
        $enforcementLabel = 'Revoke in campus vehicle permission for 2 semesters';
        $enforcementTone = 'danger';
    } else {
        $enforcementLabel = 'Revoke in campus vehicle permission for the entire study duration';
        $enforcementTone = 'danger';
    }
}

// Derived display values
$plateNumber = $latestVehicle['plate_num'] ?? '—';
$vehicleType = $latestVehicle['type'] ?? '—';
$vehicleBrand = $latestVehicle['brand'] ?? '—';
$approvalStatus = $latestVehicle['approval_status'] ?? ($latestVehicle ? 'Pending' : 'No Vehicle');
$approvedBy = $latestVehicle['approve_by'] ?? '—';
$approvalDate = !empty($latestVehicle['approval_date']) ? date('d/m/Y', strtotime($latestVehicle['approval_date'])) : '—';

$vehiclePermission = 'No Vehicle';
if ($latestVehicle) {
    if ($demeritPoints >= 20) {
        $vehiclePermission = 'Revoked';
    } else {
        if (strcasecmp((string)$approvalStatus, 'Approved') === 0) {
            $vehiclePermission = 'Active';
        } elseif (strcasecmp((string)$approvalStatus, 'Rejected') === 0) {
            $vehiclePermission = 'Rejected';
        } else {
            $vehiclePermission = 'Pending';
        }
    }
}

$vpTone = ($vehiclePermission === 'Active') ? 'ok' : (($vehiclePermission === 'Revoked') ? 'danger' : 'warn');
$asTone = (strcasecmp((string)$approvalStatus, 'Approved') === 0) ? 'ok' : ((strcasecmp((string)$approvalStatus, 'Rejected') === 0) ? 'danger' : 'warn');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Status</title>
    <style>
        *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        body{margin:0;background:#f3f4f6;color:#111827}
        .layout{min-height:100vh;display:grid;grid-template-columns:260px 1fr}
        .sidebar{background:#fff;border-right:1px solid #e5e7eb;display:flex;flex-direction:column;padding:18px 18px 24px}
        .sidebar-header{display:flex;align-items:center;gap:10px;padding-bottom:20px;border-bottom:1px solid #e5e7eb}
        .sidebar-logo{width:40px;;height:40px;border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center}
        .sidebar-logo img{max-width:100%;max-height:100%}
        .sidebar-title{font-size:18px;font-weight:600;color: #111827;}
        .nav{margin-top:24px;display:flex;flex-direction:column;gap:6px}
        .nav-link{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:999px;color:#374151;text-decoration:none;font-size:14px}
        .nav-link:hover{background:#fee2e2;color:#111827}
        .nav-link.active {background-color: #f97373;color: #ffffff;}
        .sidebar-footer{margin-top:auto;padding-top:18px;border-top:1px solid #e5e7eb}
        .logout-btn{display:flex;align-items:center;gap:10px;font-size:14px;color:#ef4444;text-decoration:none}
        .main{display:flex;flex-direction:column}
        .topbar{height:64px;background:#fff;display:flex;align-items:center;justify-content:flex-end;padding:0 32px;box-shadow:0 1px 4px rgba(15,23,42,.06)}
        .topbar-user{display:flex;align-items:center;gap:14px}
        .topbar-user-info{text-align:right;font-size:13px}
        .topbar-role{color:#6b7280;margin-top:2px}
        .avatar{width:42px;height:42px;border-radius:50%;background:#111827;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}
        .content{padding:28px 32px 36px}
        .page-title{font-size:26px;margin:0 0 6px}
        .page-subtitle{margin:0 0 18px;color:#6b7280}
        .card{background:#fff;border-radius:24px;padding:18px 20px 20px;box-shadow:0 16px 40px rgba(15,23,42,.08)}
        .filter{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
        .filter input{padding:8px 10px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;width:260px}
        .filter button{padding:8px 14px;border:none;border-radius:10px;background:#2563eb;color:#fff;cursor:pointer;font-weight:800}
        .filter a{padding:8px 14px;border-radius:10px;background:#eee;text-decoration:none;color:#111;font-weight:800}
        .status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px}
        .status-item{background:#f9fafb;border:1px solid #e5e7eb;border-radius:14px;padding:12px 14px}
        .status-k{font-size:12px;color:#6b7280;margin-bottom:4px}
        .status-v{font-size:14px;font-weight:800;color:#111827}
        .pill{display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;border:1px solid #e5e7eb;background:#fff}
        .pill.ok{border-color:#bbf7d0;background:#f0fdf4}
        .pill.warn{border-color:#fde68a;background:#fffbeb}
        .pill.danger{border-color:#fecaca;background:#fef2f2}
        .note{margin-top:12px;font-size:13px;color:#6b7280}
        @media (max-width: 1100px){.layout{grid-template-columns:1fr}.sidebar{display:none}.content{padding:20px 22px 28px}.status-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo"><img src="images/logo.png" alt="FKPark Logo"></div>
            <div class="sidebar-title">FKPark</div>
        </div>
        <nav class="nav">
            <a href="dashboard_security.php" class="nav-link"><span>☰</span><span>Dashboard</span></a>
            <a href="vehicle_approval.php" class="nav-link"><span>✅</span><span>Vehicle Approvals</span></a>
            <a href="create_summon.php" class="nav-link"><span>⚠️</span><span>Create Summon</span></a>
            <a href="summon_list.php" class="nav-link"><span>📄</span><span>Summons List</span></a>
            <a href="student_status_security.php" class="nav-link active"><span>👤</span><span>Student Status</span></a>
            <a href="security_profile.php" class="nav-link"><span>🙍</span><span>Profile</span></a>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn"><span>⟵</span><span>Logout</span></a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar-user">
                <div class="topbar-user-info">
                    <div>Welcome, <?= h($securityName) ?></div>
                    <div class="topbar-role">Security Staff</div>
                </div>
                <div class="avatar"><?= strtoupper(substr($securityName, 0, 1)) ?></div>
            </div>
        </header>

        <main class="content">
            <h1 class="page-title">Student Status</h1>
            <p class="page-subtitle">View a student's vehicle status and enforcement status.</p>

            <div class="card">
                <form method="GET" class="filter">
                    <input type="text" name="student_id" placeholder="Enter Student ID" value="<?= h($studentId) ?>">
                    <button type="submit">Search</button>
                    <a href="student_status_security.php">Reset</a>
                </form>

                <?php if ($studentId === ''): ?>
                    <div class="note">Enter a Student ID above to view the latest registered vehicle and enforcement status.</div>
                <?php else: ?>
                    <div class="note">
                        Student ID: <b><?= h($studentId) ?></b>
                        <?php if ($studentName): ?>
                            — <?= h($studentName) ?>
                        <?php else: ?>
                            — <span style="color:#ef4444;font-weight:800;">Student not found</span>
                        <?php endif; ?>
                    </div>

                    <div class="status-grid">
                        <div class="status-item">
                            <div class="status-k">Vehicle Permission</div>
                            <div class="status-v"><span class="pill <?= h($vpTone) ?>"><?= h($vehiclePermission) ?></span></div>
                        </div>
                        <div class="status-item">
                            <div class="status-k">Latest Vehicle</div>
                            <div class="status-v"><?= h($plateNumber) ?></div>
                            <div class="status-k" style="margin-top:6px;">Type / Brand</div>
                            <div class="status-v" style="font-weight:700;"><?= h($vehicleType) ?> — <?= h($vehicleBrand) ?></div>
                        </div>
                        <div class="status-item">
                            <div class="status-k">Approval Status</div>
                            <div class="status-v"><span class="pill <?= h($asTone) ?>"><?= h($approvalStatus) ?></span></div>
                            <div class="status-k" style="margin-top:6px;">Approved By / Date</div>
                            <div class="status-v" style="font-weight:700;"><?= h($approvedBy) ?> — <?= h($approvalDate) ?></div>
                        </div>
                        <div class="status-item">
                            <div class="status-k">Demerit Points (Accumulated)</div>
                            <div class="status-v"><?= (int)$demeritPoints ?></div>
                        </div>
                        <div class="status-item" style="grid-column: span 2;">
                            <div class="status-k">Enforcement Status (Table A)</div>
                            <div class="status-v"><span class="pill <?= h($enforcementTone) ?>"><?= h($enforcementLabel) ?></span></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
</body>
</html>