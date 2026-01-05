<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';


function runFilteredQuery($conn, $sql, $types, $params) {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    return $stmt->get_result();
}

// Student quick-status for Security (vehicle status + enforcement)
function getStudentVehicleAndEnforcement(mysqli $conn, string $studentId): array
{
    // Student name
    $studentName = null;
    $stmt = $conn->prepare("SELECT name FROM student WHERE student_id = ?");
    if ($stmt) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $studentName = $row['name'] ?? null;
        $stmt->close();
    }

    // Latest vehicle + latest approval
    $latestVehicle = null;
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

    // Total demerit points (accumulated)
    $demeritPoints = 0;
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(vt.Point), 0) AS total_demerit
        FROM traffic_summon ts
        JOIN violation_type vt ON vt.violation_typeID = ts.violati
$studentStatus = null;
if ($student_id_filter !== '') {
    $studentStatus = getStudentVehicleAndEnforcement($conn, $student_id_filter);
}

on_typeID
        WHERE ts.student_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $demeritPoints = (int)($row['total_demerit'] ?? 0);
        $stmt->close();
    }

    // Enforcement status (Table A) based on accumulated deducted points
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

    // Vehicle permission + default values
    $vehiclePermission = 'No Vehicle';
    $approvalStatus = 'No Vehicle';
    $plateNumber = '—';
    $vehicleType = '—';
    $vehicleBrand = '—';
    $approvedBy = '—';
    $approvalDate = '—';

    if ($latestVehicle) {
        $plateNumber = $latestVehicle['plate_num'] ?? '—';
        $vehicleType = $latestVehicle['type'] ?? '—';
        $vehicleBrand = $latestVehicle['brand'] ?? '—';
        $approvalStatus = $latestVehicle['approval_status'] ?? 'Pending';
        $approvedBy = $latestVehicle['approve_by'] ?? '—';
        $approvalDate = !empty($latestVehicle['approval_date']) ? date('d/m/Y', strtotime($latestVehicle['approval_date'])) : '—';

        if ($demeritPoints >= 20) {
            $vehiclePermission = 'Revoked';
        } else {
            if (strcasecmp($approvalStatus, 'Approved') === 0) {
                $vehiclePermission = 'Active';
            } elseif (strcasecmp($approvalStatus, 'Rejected') === 0) {
                $vehiclePermission = 'Rejected';
            } else {
                $vehiclePermission = 'Pending';
            }
        }
    }

    return [
        'student_id' => $studentId,
        'student_name' => $studentName,
        'plate_num' => $plateNumber,
        'vehicle_type' => $vehicleType,
        'vehicle_brand' => $vehicleBrand,
        'approval_status' => $approvalStatus,
        'approve_by' => $approvedBy,
        'approval_date' => $approvalDate,
        'demerit_points' => $demeritPoints,
        'enforcement_label' => $enforcementLabel,
        'enforcement_tone' => $enforcementTone,
        'vehicle_permission' => $vehiclePermission,
    ];
}


// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Only Security can access (using username instead of login_id)
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Security') {
    header("Location: login.php?timeout=1");
    exit;
}

// ===================== Dashboard Filters (Search) =====================
$student_id_filter = trim($_GET['student_id'] ?? '');
$month_filter = $_GET['month'] ?? '';
$year_filter  = $_GET['year'] ?? '';

$where = " WHERE 1=1 ";
$params = [];
$types  = "";

// Filter by Student ID
if ($student_id_filter !== '') {
    $where .= " AND ts.student_id = ? ";
    $params[] = $student_id_filter;
    $types .= "s";
}

// Filter by Month
if ($month_filter !== '') {
    $where .= " AND MONTH(ts.date) = ? ";
    $params[] = (int)$month_filter;
    $types .= "i";
}

// Filter by Year
if ($year_filter !== '') {
    $where .= " AND YEAR(ts.date) = ? ";
    $params[] = (int)$year_filter;
    $types .= "i";
}



$securityUsername = $_SESSION['username'];
$securityName = $securityUsername; // Default to username

// Get security name from database
$sqlSecurity = "SELECT name FROM security WHERE staff_id = ?";
$stmtSecurity = mysqli_prepare($conn, $sqlSecurity);
if ($stmtSecurity) {
    mysqli_stmt_bind_param($stmtSecurity, "s", $securityUsername);
    mysqli_stmt_execute($stmtSecurity);
    $result = mysqli_stmt_get_result($stmtSecurity);
    if ($row = mysqli_fetch_assoc($result)) {
        $securityName = $row['name'];
    }
}

// REAL DATA (traffic_summon + violation_type) 

// Total summons (all-time)
$totalSummons = 0;
$res = runFilteredQuery($conn, "SELECT COUNT(*) AS c FROM traffic_summon ts $where", $types, $params);
if ($res && ($r = $res->fetch_assoc())) {
    $totalSummons = (int)($r['c'] ?? 0);
}
// Total points issued (sum of violation points for all summons)
$totalPointsIssued = 0;
$res = runFilteredQuery($conn, "SELECT COALESCE(SUM(vt.Point),0) AS pts FROM traffic_summon ts JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID $where", $types, $params);
if ($res && ($r = $res->fetch_assoc())) {
    $totalPointsIssued = (int)($r['pts'] ?? 0);
}
// Avg points per student (only students who have at least 1 summon)
$distinctStudents = 0;
$res = runFilteredQuery($conn, "SELECT COUNT(DISTINCT ts.student_id) AS c FROM traffic_summon ts $where", $types, $params);
if ($res && ($r = $res->fetch_assoc())) {
    $distinctStudents = (int)($r['c'] ?? 0);
}
$avgPointsPerStudent = ($distinctStudents > 0) ? round($totalPointsIssued / $distinctStudents, 1) : 0;

// Students with summons (distinct)
$studentsWithSummons = $distinctStudents;

// Summons by violation type
$summonsByType = [];
$res = runFilteredQuery($conn, "
    SELECT vt.Description AS type, COUNT(*) AS count
    FROM traffic_summon ts
    JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
    $where
    GROUP BY vt.violation_typeID, vt.Description
    ORDER BY count DESC, vt.Description ASC
", $types, $params);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $summonsByType[] = $row;
    }
}

// Summons per month (last 6 months)
$summonsPerMonth = [];
$res = runFilteredQuery($conn, "
    SELECT DATE_FORMAT(ts.date, '%b %Y') AS month, DATE_FORMAT(ts.date, '%Y-%m') AS mkey, COUNT(*) AS count
    FROM traffic_summon ts
    $where
    GROUP BY mkey, month
    ORDER BY mkey DESC
    LIMIT 6
", $types, $params);
if ($res) {
    $tmp = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $tmp[] = $row;
    }
    $summonsPerMonth = array_reverse($tmp); // oldest -> newest
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Traffic Summons Dashboard - FKPark</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            background-color: #f3f4f6;
            color: #111827;
        }

        .layout {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 260px 1fr;
        }

        /* Sidebar */
        .sidebar {
            background-color: #ffffff;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            padding: 18px 18px 24px;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        .sidebar-logo {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            /* Let the logo image use the full box */
            background-color: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .sidebar-logo img {
            max-width: 100%;
            max-height: 100%;
        }

        .sidebar-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .nav {
            margin-top: 24px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 999px;
            color: #374151;
            text-decoration: none;
            font-size: 14px;
        }

        .nav-link span.icon {
            width: 18px;
            text-align: center;
        }

        .nav-link:hover {
            background-color: #fee2e2;
            color: #111827;
        }

        .nav-link.active {
            background-color: #f97373;
            color: #ffffff;
        }

        .nav-link.status-link.active {
            background-color: #2563eb;
            color: #ffffff;
        }

        .nav-link.active span.icon {
            color: #ffffff;
        }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #ef4444;
            text-decoration: none;
        }

        /* Main */
        .main {
            display: flex;
            flex-direction: column;
        }

        .topbar {
            height: 64px;
            background-color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 32px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06);
        }

        .topbar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .topbar-user-info {
            text-align: right;
        }

        .topbar-role {
            font-size: 12px;
            color: #6b7280;
        }

        .avatar {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            background-color: #f97316;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-weight: 600;
        }

        .content {
            padding: 26px 40px 36px;
        }

        .page-title {
            font-size: 26px;
            font-weight: 600;
            margin: 0;
        }

        .page-subtitle {
            margin-top: 6px;
            font-size: 14px;
            color: #6b7280;
        }

        .summary-row {
            margin-top: 24px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
        }

        .summary-card {
            background-color: #ffffff;
            border-radius: 22px;
            padding: 16px 18px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .summary-icon-box {
            width: 46px;
            height: 46px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .summary-icon-box.red {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .summary-icon-box.orange {
            background-color: #ffedd5;
            color: #ea580c;
        }

        .summary-icon-box.purple {
            background-color: #f3e8ff;
            color: #7c3aed;
        }

        .summary-icon-box.blue {
            background-color: #dbeafe;
            color: #2563eb;
        }

        .summary-label {
            font-size: 13px;
            color: #6b7280;
        }

        .summary-value {
            font-size: 24px;
            font-weight: 600;
            margin-top: 2px;
        }

        .summary-sub {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .charts-row {
            margin-top: 24px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .chart-card {
            background-color: #ffffff;
            border-radius: 24px;
            padding: 18px 20px 20px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .chart-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .chart-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 13px;
        }

        .chart-table th,
        .chart-table td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .chart-table th {
            font-weight: 600;
            color: #6b7280;
        }

        .chart-table tr:last-child td {
            border-bottom: none;
        }

        @media (max-width: 1100px) {
            .summary-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .charts-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none;
            }
            .content {
                padding: 20px 22px 28px;
            }
        }
    
        .chart-canvas{width:100%; padding:10px 4px 16px 4px;}
        .chart-canvas canvas{width:100% !important; max-height:220px;}
        .chart-table{margin-top:6px;}
    
        .dashboard-filter{
            margin: 14px 0 18px;
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
        }
        .dashboard-filter input, .dashboard-filter select{
            padding:8px 10px;
            border:1px solid #d1d5db;
            border-radius:10px;
            font-size:14px;
        }
        .dashboard-filter input{ width:220px; }
        .dashboard-filter button{
            padding:8px 14px;
            border:none;
            border-radius:10px;
            background:#ff6b6b;
            color:#fff;
            cursor:pointer;
            font-weight:600;
        }
        .dashboard-filter .reset-btn{
            padding:8px 14px;
            border-radius:10px;
            background:#eee;
            text-decoration:none;
            color:#111;
            font-weight:600;
        }


        /* Student status quick view */
        .status-grid{
            display:grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap:12px;
            margin-top:12px;
        }
        .status-item{
            background:#f9fafb;
            border:1px solid #e5e7eb;
            border-radius:14px;
            padding:12px 14px;
        }
        .status-k{font-size:12px; color:#6b7280; margin-bottom:4px;}
        .status-v{font-size:14px; font-weight:800; color:#111827;}
        .pill{
            display:inline-block;
            padding:6px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:800;
            border:1px solid #e5e7eb;
            background:#fff;
        }
        .pill.ok{border-color:#bbf7d0; background:#f0fdf4;}
        .pill.warn{border-color:#fde68a; background:#fffbeb;}
        .pill.danger{border-color:#fecaca; background:#fef2f2;}
        @media (max-width: 900px){
            .status-grid{grid-template-columns: 1fr;}
        }
</style>
</head>
<body>
<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="images/logo.png" alt="FKPark Logo">
            </div>
            <div class="sidebar-title">FKPark</div>
        </div>

        <nav class="nav">
            <a href="dashboard_security.php" class="nav-link active">
                <span class="icon">☰</span>
                <span>Dashboard</span>
            </a>
           
            <a href="vehicle_approval.php" class="nav-link">
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
            <a href="student_status_security.php" class="nav-link">
                <span class="icon">👤</span>
                <span>Student Status</span>
            </a>
            <a href="security_profile.php" class="nav-link">
                <span class="icon">🙍</span>
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

    <!-- Main -->
    <div class="main">
        <!-- Topbar -->
        <header class="topbar">
            <div class="topbar-user">
                <div class="topbar-user-info">
                    <div>Welcome, <?php echo htmlspecialchars($securityName); ?></div>
                    <div class="topbar-role">Security Staff</div>
                </div>
                <div class="avatar">
                    <?php echo strtoupper(substr($securityName, 0, 1)); ?>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <h1 class="page-title">Traffic Summons Dashboard</h1>
            <p class="page-subtitle">
                Monitor violations and demerit points
            </p>

            
            <!-- Dashboard filter (search) -->
            <form method="GET" class="dashboard-filter">
                <input type="text" name="student_id" placeholder="Filter by Student ID"
                       value="<?= htmlspecialchars($_GET['student_id'] ?? '') ?>">

                <select name="month">
                    <option value="">All Months</option>
                    <?php for ($m=1; $m<=12; $m++): ?>
                        <option value="<?= $m ?>" <?= (($_GET['month'] ?? '') == $m) ? 'selected' : '' ?>>
                            <?= date("F", mktime(0,0,0,$m,1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <select name="year">
                    <option value="">All Years</option>
                    <?php for ($y=date("Y"); $y>=2020; $y--): ?>
                        <option value="<?= $y ?>" <?= (($_GET['year'] ?? '') == $y) ? 'selected' : '' ?>>
                            <?= $y ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <button type="submit">Filter</button>
                <a href="dashboard_security.php" class="reset-btn">Reset</a>
            </form>

            <?php if (!empty($studentStatus)): ?>
                <section class="chart-card" style="margin: 18px 0 20px;">
                    <div class="chart-title">Student Vehicle &amp; Enforcement Status</div>
                    <div class="chart-subtitle">
                        Student ID: <b><?= htmlspecialchars($studentStatus['student_id']) ?></b>
                        <?php if (!empty($studentStatus['student_name'])): ?>
                            — <?= htmlspecialchars($studentStatus['student_name']) ?>
                        <?php else: ?>
                            — <span style="color:#ef4444; font-weight:700;">Student not found</span>
                        <?php endif; ?>
                    </div>

                    <div class="status-grid">
                        <div class="status-item">
                            <div class="status-k">Vehicle Permission</div>
                            <?php
                                $vp = $studentStatus['vehicle_permission'];
                                $vpTone = ($vp === 'Active') ? 'ok' : (($vp === 'Revoked') ? 'danger' : 'warn');
                            ?>
                            <div class="status-v"><span class="pill <?= $vpTone ?>"><?= htmlspecialchars($vp) ?></span></div>
                        </div>

                        <div class="status-item">
                            <div class="status-k">Latest Vehicle</div>
                            <div class="status-v"><?= htmlspecialchars($studentStatus['plate_num']) ?></div>
                            <div class="status-k" style="margin-top:6px;">Type / Brand</div>
                            <div class="status-v" style="font-weight:700;">
                                <?= htmlspecialchars($studentStatus['vehicle_type']) ?> — <?= htmlspecialchars($studentStatus['vehicle_brand']) ?>
                            </div>
                        </div>

                        <div class="status-item">
                            <div class="status-k">Approval Status</div>
                            <?php
                                $as = (string)$studentStatus['approval_status'];
                                $asTone = (strcasecmp($as, 'Approved') === 0) ? 'ok' : ((strcasecmp($as, 'Rejected') === 0) ? 'danger' : 'warn');
                            ?>
                            <div class="status-v"><span class="pill <?= $asTone ?>"><?= htmlspecialchars($as) ?></span></div>
                            <div class="status-k" style="margin-top:6px;">Approved By / Date</div>
                            <div class="status-v" style="font-weight:700;">
                                <?= htmlspecialchars($studentStatus['approve_by']) ?> — <?= htmlspecialchars($studentStatus['approval_date']) ?>
                            </div>
                        </div>

                        <div class="status-item">
                            <div class="status-k">Demerit Points (Accumulated)</div>
                            <div class="status-v"><?= (int)$studentStatus['demerit_points'] ?></div>
                        </div>

                        <div class="status-item" style="grid-column: span 2;">
                            <div class="status-k">Enforcement Status (Table A)</div>
                            <div class="status-v">
                                <span class="pill <?= htmlspecialchars($studentStatus['enforcement_tone']) ?>">
                                    <?= htmlspecialchars($studentStatus['enforcement_label']) ?>
                                </span>
                            </div>
                            <div style="margin-top:10px; font-size:13px; color:#6b7280;">
                                Tip: Use <b>Summons List</b> search to review individual summons for this student.
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:14px;">
                        <a href="student_status_security.php?student_id=<?= urlencode($studentStatus['student_id']) ?>" class="reset-btn" style="background:#dbeafe;">
                            Open full Student Status
                        </a>
                        <a href="summon_list.php?q=<?= urlencode($studentStatus['student_id']) ?>" class="reset-btn" style="margin-left:8px;">
                            View summons for this Student
                        </a>
                    </div>
                </section>
            <?php endif; ?>



<!-- Summary cards -->
            <div class="summary-row">
                <div class="summary-card">
                    <div class="summary-icon-box red">⚠️</div>
                    <div>
                        <div class="summary-label">Total Summons</div>
                        <div class="summary-value"><?php echo $totalSummons; ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon-box orange">!</div>
                    <div>
                        <div class="summary-label">Students with Summons</div>
                        <div class="summary-value"><?php echo $studentsWithSummons; ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon-box purple">⬈</div>
                    <div>
                        <div class="summary-label">Total Points Issued</div>
                        <div class="summary-value"><?php echo $totalPointsIssued; ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon-box blue">👥</div>
                    <div>
                        <div class="summary-label">Avg Points per Student</div>
                        <div class="summary-value"><?php echo $avgPointsPerStudent; ?></div>
                        <div class="summary-sub">based on all students</div>
                    </div>
                </div>
            </div>

            <!-- "Charts" using sample tables -->
            <div class="charts-row">
                <section class="chart-card">
                    <div class="chart-title">Summons by Violation Type</div>
                    <div class="chart-subtitle">Live data</div>
                    <div class="chart-canvas"><canvas id="byTypeChart" height="140"></canvas></div>
                    <table class="chart-table">
                        <thead>
                        <tr>
                            <th>Violation Type</th>
                            <th>Summons Count</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($summonsByType as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['type']); ?></td>
                                <td><?php echo htmlspecialchars($row['count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="chart-card">
                    <div class="chart-title">Summons per Month</div>
                    <div class="chart-subtitle">Live data</div>
                    <div class="chart-canvas"><canvas id="perMonthChart" height="140"></canvas></div>
                    <table class="chart-table">
                        <thead>
                        <tr>
                            <th>Month</th>
                            <th>Summons Count</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($summonsPerMonth as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['month']); ?></td>
                                <td><?php echo htmlspecialchars($row['count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  // Data from PHP
  const byTypeLabels = <?php echo json_encode(array_map(fn($r) => $r['type'], $summonsByType)); ?>;
  const byTypeCounts = <?php echo json_encode(array_map(fn($r) => (int)$r['count'], $summonsByType)); ?>;

  const perMonthLabels = <?php echo json_encode(array_map(fn($r) => $r['month'], $summonsPerMonth)); ?>;
  const perMonthCounts = <?php echo json_encode(array_map(fn($r) => (int)$r['count'], $summonsPerMonth)); ?>;

  // Bar chart: Summons by violation type
  const ctx1 = document.getElementById('byTypeChart');
  if (ctx1) {
    new Chart(ctx1, {
      type: 'bar',
      data: {
        labels: byTypeLabels,
        datasets: [{
          label: 'Summons',
          data: byTypeCounts,
          borderWidth: 1,
          borderRadius: 10
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { enabled: true }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { maxRotation: 0, autoSkip: false }
          },
          y: {
            beginAtZero: true,
            ticks: { precision: 0 }
          }
        }
      }
    });
  }

  // Line chart: Summons per month (trend)
  const ctx2 = document.getElementById('perMonthChart');
  if (ctx2) {
    new Chart(ctx2, {
      type: 'line',
      data: {
        labels: perMonthLabels,
        datasets: [{
          label: 'Summons',
          data: perMonthCounts,
          tension: 0.35,
          borderWidth: 2,
          pointRadius: 3,
          fill: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { enabled: true }
        },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, ticks: { precision: 0 } }
        }
      }
    });
  }
})();
</script>

</body>
</html>