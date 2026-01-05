<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Prevent caching so Back/Forward won't show stale page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Only Student can access (using username instead of login_id)
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header("Location: login.php?timeout=1");
    exit;
}

$studentUsername = $_SESSION['username'];
$studentName = $studentUsername;

// Get student name from database
$sqlStudent = "SELECT name FROM student WHERE student_id = ?";
$stmtStudent = mysqli_prepare($conn, $sqlStudent);
if ($stmtStudent) {
    mysqli_stmt_bind_param($stmtStudent, "s", $studentUsername);
    mysqli_stmt_execute($stmtStudent);
    $result = mysqli_stmt_get_result($stmtStudent);
    if ($row = mysqli_fetch_assoc($result)) {
        $studentName = $row['name'];
    }
    mysqli_stmt_close($stmtStudent);
}

// Count registered vehicles
$registeredVehicles = 0;
$sqlVehicles = "SELECT COUNT(*) as count FROM vehicle WHERE student_id = ?";
$stmtVehicles = mysqli_prepare($conn, $sqlVehicles);
if ($stmtVehicles) {
    mysqli_stmt_bind_param($stmtVehicles, "s", $studentUsername);
    mysqli_stmt_execute($stmtVehicles);
    $result = mysqli_stmt_get_result($stmtVehicles);
    if ($row = mysqli_fetch_assoc($result)) {
        $registeredVehicles = (int)$row['count'];
    }
    mysqli_stmt_close($stmtVehicles);
}

// SAMPLE DATA - these would come from actual booking and summons tables
$totalBookings = 0;
// Get booking statistics
$bookingStats = [
    'Pending' => 0,
    'Active' => 0,
    'Completed' => 0,
    'Cancelled' => 0
];

$sqlStats = "
    SELECT status, COUNT(*) as count 
    FROM booking 
    WHERE student_id = ? 
    GROUP BY status
";
$stmtStats = mysqli_prepare($conn, $sqlStats);
if ($stmtStats) {
    mysqli_stmt_bind_param($stmtStats, "s", $studentUsername);
    mysqli_stmt_execute($stmtStats);
    $resultStats = mysqli_stmt_get_result($stmtStats);
    while ($row = mysqli_fetch_assoc($resultStats)) {
        $bookingStats[$row['status']] = (int)$row['count'];
    }
    mysqli_stmt_close($stmtStats);
}

$totalBookings = array_sum($bookingStats);
// Demerit points will be calculated from summons + violation types

// Get latest approved vehicle info
$latestVehicle = null;
$sqlLatest = "
    SELECT v.plate_num, v.type, v.brand, 
           COALESCE(va.status, 'Pending') as approval_status,
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
$stmtLatest = mysqli_prepare($conn, $sqlLatest);
if ($stmtLatest) {
    mysqli_stmt_bind_param($stmtLatest, "s", $studentUsername);
    mysqli_stmt_execute($stmtLatest);
    $result = mysqli_stmt_get_result($stmtLatest);
    $latestVehicle = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmtLatest);
}

// Default values if no vehicle
if ($latestVehicle) {
    $plateNumber = $latestVehicle['plate_num'];
    $vehicleType = $latestVehicle['type'];
    $vehicleModel = $latestVehicle['brand'];
    $approvalStatus = $latestVehicle['approval_status'];
    $approvedBy = $latestVehicle['approve_by'] ?? "—";
    $approvalDate = $latestVehicle['approval_date'] ? date('d/m/Y', strtotime($latestVehicle['approval_date'])) : "—";
} else {
    $plateNumber = "No Vehicle";
    $vehicleType = "—";
    $vehicleModel = "—";
    $approvalStatus = "No Vehicle";
    $approvedBy = "—";
    $approvalDate = "—";
}

// Total demerit points (always correct)
$totalDemerit = 0;
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(vt.Point), 0) AS total_demerit
    FROM traffic_summon ts
    JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
    WHERE ts.student_id = ?
");
$stmt->bind_param("s", $studentUsername);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$totalDemerit = (int)($row['total_demerit'] ?? 0);
$stmt->close();


// Keep legacy variable name used in the HTML below
$demeritPoints = $totalDemerit;

// Enforcement status (Table A) based on accumulated demerit points
if ($demeritPoints < 20) {
    $enforcementLabel = "Warning given";
    $enforcementTone = "info";
} elseif ($demeritPoints < 50) {
    $enforcementLabel = "Revoke in campus vehicle permission for 1 semester";
    $enforcementTone = "warn";
} elseif ($demeritPoints < 80) {
    $enforcementLabel = "Revoke in campus vehicle permission for 2 semesters";
    $enforcementTone = "danger";
} else {
    $enforcementLabel = "Revoke in campus vehicle permission for the entire study duration";
    $enforcementTone = "danger";
}
// --- Enforcement UI helpers (Table A) ---
$progressMax = 80; // 80+ is the highest band in Table A
$progressPct = $progressMax > 0 ? min(100, max(0, ($demeritPoints / $progressMax) * 100)) : 0;

if ($demeritPoints < 20) {
    $stageIndex = 0;
    $nextThreshold = 20;
    $nextAction = "Keep below 20 points to avoid revocation.";
    $stageShort = "Warning";
} elseif ($demeritPoints < 50) {
    $stageIndex = 1;
    $nextThreshold = 50;
    $nextAction = "Next: Revocation for 2 semesters at 50 points.";
    $stageShort = "Revoke 1 sem";
} elseif ($demeritPoints < 80) {
    $stageIndex = 2;
    $nextThreshold = 80;
    $nextAction = "Next: Revocation for entire study duration at 80 points.";
    $stageShort = "Revoke 2 sem";
} else {
    $stageIndex = 3;
    $nextThreshold = null;
    $nextAction = "Maximum enforcement band reached.";
    $stageShort = "Revoke study";
}

$pointsToNext = ($nextThreshold === null) ? 0 : max(0, $nextThreshold - $demeritPoints);




// Keep legacy variable name used in the HTML below
$demeritPoints = $totalDemerit;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard - FKPark</title>
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
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: transparent; /* remove blue box */
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .sidebar-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
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
            background-color: #eff6ff;
            color: #111827;
        }

        .nav-link.active {
            background-color: #0066ff;
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

        /* Main area */
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
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .topbar-user:hover .avatar {
            transform: scale(1.05);
            transition: transform 0.2s;
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
            background-color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-weight: 600;
            transition: transform 0.2s;
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

        .cards-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-top: 24px;
        }

        .stat-card {
            background-color: #ffffff;
            border-radius: 22px;
            padding: 18px 20px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .stat-icon-box {
            width: 52px;
            height: 52px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-icon-box.blue {
            background-color: #e0edff;
            color: #2563eb;
        }

        .stat-icon-box.green {
            background-color: #dcfce7;
            color: #16a34a;
        }

        .stat-icon-box.red {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 600;
            margin-top: 4px;
        }

        .vehicle-status-card {
            margin-top: 28px;
            background-color: #ffffff;
            border-radius: 24px;
            padding: 22px 24px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
        }

        .vehicle-status-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .vehicle-inner {
            margin-top: 4px;
            border-radius: 20px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }

        .vehicle-inner.approved {
            background-color: #dcfce7;
            border: 1px solid #bbf7d0;
        }

        .vehicle-inner.revoked {
            background: #ffe4e6;
            border: 1px solid #fecdd3;
        }
        .status-pill.revoked {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .vehicle-icon.revoked {
            background: #ef4444;
        }
        

        .vehicle-inner.pending {
            background-color: #fef3c7;
            border: 1px solid #fde047;
        }

        .vehicle-inner.none {
            background-color: #f3f4f6;
            border: 1px solid #e5e7eb;
        }

        .vehicle-main {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .vehicle-icon-box {
            width: 54px;
            height: 54px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 26px;
        }

        .vehicle-icon-box.green {
            background-color: #22c55e;
        }

        .vehicle-icon-box.yellow {
            background-color: #eab308;
        }

        .vehicle-icon-box.gray {
            background-color: #9ca3af;
        }

        .vehicle-info-title {
            font-size: 18px;
            font-weight: 600;
        }

        .vehicle-info-sub {
            font-size: 14px;
            color: #4b5563;
            margin-top: 4px;
        }

        .vehicle-meta {
            margin-top: 14px;
            font-size: 13px;
            color: #4b5563;
        }

        .status-pill {
            align-self: flex-start;
            padding: 6px 16px;
            border-radius: 999px;
            color: #ffffff;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-pill.approved {
            background-color: #22c55e;
        }

        .status-pill.pending {
            background-color: #eab308;
        }

        .status-pill.none {
            background-color: #9ca3af;
        }

        @media (max-width: 1024px) {
            .layout {
                grid-template-columns: 230px 1fr;
            }
            .content {
                padding: 20px 22px 28px;
            }
        }

        @media (max-width: 768px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none;
            }
            .cards-row {
                grid-template-columns: 1fr;
            }
        }

        /* Enforcement card (improved UI) */
        .enforce-card{
            margin-top:18px;
            background:#ffffff;
            border-radius:22px;
            padding:22px;
            box-shadow:0 18px 30px rgba(0,0,0,0.08);
            border:1px solid #eef2f7;
        }
        .enforce-header{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:16px;
            margin-bottom:14px;
        }
        .enforce-title{
            font-size:16px;
            font-weight:800;
            color:#111827;
        }
        .enforce-subtitle{
            margin-top:4px;
            color:#6b7280;
            font-size:13px;
            line-height:1.35;
        }
        .enforce-badges{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            justify-content:flex-end;
        }
        .badge{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:8px 12px;
            border-radius:999px;
            font-size:13px;
            font-weight:700;
            border:1px solid #e5e7eb;
            background:#f9fafb;
            color:#111827;
            white-space:nowrap;
        }
        .badge.info{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;}
        .badge.warn{background:#fff7ed;border-color:#fed7aa;color:#9a3412;}
        .badge.danger{background:#fef2f2;border-color:#fecaca;color:#b91c1c;}

        .enforce-grid{
            display:grid;
            grid-template-columns: 1.1fr 1fr;
            gap:14px;
        }
        @media (max-width: 1024px){
            .enforce-grid{grid-template-columns:1fr;}
        }

        .enforce-panel{
            border:1px solid #e5e7eb;
            background:#f9fafb;
            border-radius:18px;
            padding:16px;
        }
        .enforce-metrics{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:12px;
        }
        .metric-big{
            font-size:26px;
            font-weight:900;
            letter-spacing:-0.02em;
        }
        .metric-label{
            color:#6b7280;
            font-size:12px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.04em;
        }
        .metric-mini{
            color:#374151;
            font-size:13px;
            font-weight:600;
        }

        .progress{
            height:12px;
            border-radius:999px;
            background:#e5e7eb;
            overflow:hidden;
        }
        .progress > span{
            display:block;
            height:100%;
            width:0%;
            border-radius:999px;
            background: linear-gradient(90deg, #3b82f6, #22c55e);
        }
        .progress.warn > span{
            background: linear-gradient(90deg, #f59e0b, #fb7185);
        }
        .progress.danger > span{
            background: linear-gradient(90deg, #fb7185, #ef4444);
        }

        .enforce-note{
            margin-top:12px;
            display:flex;
            gap:10px;
            align-items:flex-start;
            padding:12px 14px;
            border-radius:16px;
            border:1px solid #e5e7eb;
            background:#ffffff;
        }
        .enforce-note .dot{
            width:10px;height:10px;border-radius:999px;margin-top:4px;
            background:#93c5fd;
            flex:0 0 auto;
        }
        .enforce-note.warn .dot{background:#fdba74;}
        .enforce-note.danger .dot{background:#fca5a5;}

        .stepper{
            display:grid;
            gap:10px;
        }
        .step{
            display:flex;
            gap:12px;
            align-items:flex-start;
            padding:12px 14px;
            border-radius:16px;
            border:1px solid #e5e7eb;
            background:#ffffff;
            transition:transform .12s ease;
        }
        .step:hover{transform:translateY(-1px);}
        .step .num{
            width:28px;height:28px;border-radius:10px;
            display:flex;align-items:center;justify-content:center;
            font-weight:900;font-size:13px;
            background:#eef2ff;color:#3730a3;
            flex:0 0 auto;
        }
        .step .txt{font-size:13px;color:#374151;line-height:1.35;}
        .step .title{font-weight:800;color:#111827;margin-bottom:2px;font-size:13px;}
        .step.active{
            border-color:#bfdbfe;
            box-shadow:0 10px 18px rgba(59,130,246,.12);
            background:#eff6ff;
        }
        .step.active .num{background:#1d4ed8;color:#ffffff;}
        .step.active.warn{background:#fff7ed;border-color:#fed7aa;box-shadow:0 10px 18px rgba(245,158,11,.12);}
        .step.active.warn .num{background:#9a3412;color:#fff;}
        .step.active.danger{background:#fef2f2;border-color:#fecaca;box-shadow:0 10px 18px rgba(239,68,68,.12);}
        .step.active.danger .num{background:#b91c1c;color:#fff;}

        /* Booking Bar Graph */
.stats-chart-card {
    margin-top: 28px;
    background-color: #ffffff;
    border-radius: 24px;
    padding: 28px 32px;
    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
}

.stats-chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.stats-chart-title {
    font-size: 18px;
    font-weight: 600;
}

.total-bookings-badge {
    padding: 8px 16px;
    background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%);
    border-radius: 999px;
    font-size: 14px;
    font-weight: 600;
    color: #1e40af;
}

.bar-graph-container {
    position: relative;
    padding: 20px 0;
}

.bar-graph {
    display: flex;
    align-items: flex-end;
    justify-content: space-around;
    height: 280px;
    gap: 32px;
    padding: 0 20px;
    border-bottom: 3px solid #e5e7eb;
    position: relative;
}

/* Y-axis grid lines */
.bar-graph::before {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 25%;
    height: 1px;
    background: #f3f4f6;
    z-index: 0;
}

.bar-graph::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 50%;
    height: 1px;
    background: #f3f4f6;
    z-index: 0;
}

.bar-item {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    z-index: 1;
}

.bar-column {
    width: 100%;
    max-width: 80px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}

.bar-value-label {
    font-size: 20px;
    font-weight: 700;
    color: #111827;
    min-height: 28px;
}

.bar {
    width: 100%;
    min-height: 20px;
    border-radius: 12px 12px 0 0;
    position: relative;
    transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.1);
}

.bar:hover {
    transform: translateY(-8px);
    box-shadow: 0 -8px 20px rgba(0, 0, 0, 0.15);
}

.bar.pending {
    background: linear-gradient(180deg, #fbbf24 0%, #f59e0b 100%);
}

.bar.active {
    background: linear-gradient(180deg, #34d399 0%, #10b981 100%);
}

.bar.completed {
    background: linear-gradient(180deg, #818cf8 0%, #6366f1 100%);
}

.bar.cancelled {
    background: linear-gradient(180deg, #f87171 0%, #ef4444 100%);
}

.bar-label {
    margin-top: 12px;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    text-align: center;
}

.bar-icon {
    font-size: 20px;
    margin-bottom: 4px;
}

/* Legend */
.graph-legend {
    display: flex;
    justify-content: center;
    gap: 24px;
    margin-top: 24px;
    flex-wrap: wrap;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #6b7280;
}

.legend-color {
    width: 16px;
    height: 16px;
    border-radius: 4px;
}

.legend-color.pending { background: linear-gradient(135deg, #fbbf24, #f59e0b); }
.legend-color.active { background: linear-gradient(135deg, #34d399, #10b981); }
.legend-color.completed { background: linear-gradient(135deg, #818cf8, #6366f1); }
.legend-color.cancelled { background: linear-gradient(135deg, #f87171, #ef4444); }

@media (max-width: 768px) {
    .bar-graph {
        gap: 16px;
        padding: 0 10px;
    }
    
    .bar-column {
        max-width: 60px;
    }
    
    .bar-value-label {
        font-size: 16px;
    }
    
    .bar-label {
        font-size: 12px;
    }
}

    </style>
</head>
<body>
<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="images/logo-removebg.png" alt="FKPark Logo">
            </div>
            <div class="sidebar-title">FKPark</div>
        </div>

        <nav class="nav">
            <a href="dashboard_stud.php" class="nav-link active">
                <span class="icon">☰</span>
                <span>Dashboard</span>
            </a>
            <a href="student_vehicles.php" class="nav-link">
                <span class="icon">🚘</span>
                <span>My Vehicles</span>
            </a>
            <a href="book_parking.php" class="nav-link">
                <span class="icon">📅</span>
                <span>Book Parking</span>
            </a>
            <a href="my_bookings.php" class="nav-link">
                <span class="icon">📄</span>
                <span>My Bookings</span>
            </a>
            <a href="student_summon.php" class="nav-link">
                <span class="icon">⚠️</span>
                <span>Summons &amp; Demerit</span>
            </a>
            <a href="student_profile.php" class="nav-link">
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

    <!-- Main area -->
    <div class="main">
        <!-- Topbar -->
        <header class="topbar">
            <a href="student_profile.php" class="topbar-user">
                <div class="topbar-user-info">
                    <div>Welcome, <?php echo htmlspecialchars($studentName); ?></div>
                    <div class="topbar-role">Student</div>
                </div>
                <div class="avatar">
                    <?php echo strtoupper(substr($studentName, 0, 1)); ?>
                </div>
            </a>
        </header>

        <!-- Content -->
        <main class="content">
            <h1 class="page-title">Welcome back, <?php echo htmlspecialchars($studentName); ?>!</h1>
            <p class="page-subtitle">
                Manage your vehicles, bookings and view your parking status
            </p>

            <!-- Stats cards -->
            <div class="cards-row">
                <div class="stat-card">
                    <div class="stat-icon-box blue">🚘</div>
                    <div>
                        <div class="stat-label">Registered Vehicles</div>
                        <div class="stat-value"><?php echo $registeredVehicles; ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon-box red">⚠️</div>
                    <div>
                        <div class="stat-label">Demerit Points</div>
                        <div class="stat-value"><?php echo $demeritPoints; ?></div>
                    </div>
                </div>
            </div>

            <!-- Enforcement status -->
            <section class="enforce-card">
                <div class="enforce-header">
                    <div>
                        <div class="enforce-title">Enforcement Status</div>
                        <div class="enforce-subtitle">Based on accumulated demerit points (Table A). Keep your points low to maintain campus vehicle permission.</div>
                    </div>
                    <div class="enforce-badges">
                        <span class="badge info">Points: <?php echo (int)$demeritPoints; ?> pts</span>
                        <span class="badge <?php echo htmlspecialchars($enforcementTone); ?>">
                            <?php echo htmlspecialchars($stageShort); ?>
                        </span>
                    </div>
                </div>

                <div class="enforce-grid">
                    <div class="enforce-panel">
                        <div class="enforce-metrics">
                            <div>
                                <div class="metric-label">Current status</div>
                                <div class="metric-big"><?php echo htmlspecialchars($enforcementLabel); ?></div>
                            </div>
                            <div style="text-align:right;">
                                <div class="metric-label">To next level</div>
                                <div class="metric-mini">
                                    <?php if ($nextThreshold === null): ?>
                                        — 
                                    <?php else: ?>
                                        <?php echo (int)$pointsToNext; ?> pts (to <?php echo (int)$nextThreshold; ?>)
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="progress <?php echo htmlspecialchars($enforcementTone); ?>">
                            <span style="width: <?php echo (float)$progressPct; ?>%"></span>
                        </div>

                        <div class="enforce-note <?php echo htmlspecialchars($enforcementTone); ?>">
                            <div class="dot"></div>
                            <div class="txt">
                                <div class="title">Next guidance</div>
                                <div><?php echo htmlspecialchars($nextAction); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="stepper">
                        <div class="step <?php echo $stageIndex===0 ? 'active info' : ''; ?>">
                            <div class="num">1</div>
                            <div class="txt">
                                <div class="title">Warning</div>
                                <div>Less than 20 points — warning given.</div>
                            </div>
                        </div>

                        <div class="step <?php echo $stageIndex===1 ? 'active warn' : ''; ?>">
                            <div class="num">2</div>
                            <div class="txt">
                                <div class="title">Revoke 1 semester</div>
                                <div>20–49 points — revoke in-campus vehicle permission for 1 semester.</div>
                            </div>
                        </div>

                        <div class="step <?php echo $stageIndex===2 ? 'active danger' : ''; ?>">
                            <div class="num">3</div>
                            <div class="txt">
                                <div class="title">Revoke 2 semesters</div>
                                <div>50–79 points — revoke in-campus vehicle permission for 2 semesters.</div>
                            </div>
                        </div>

                        <div class="step <?php echo $stageIndex===3 ? 'active danger' : ''; ?>">
                            <div class="num">4</div>
                            <div class="txt">
                                <div class="title">Revoke study duration</div>
                                <div>80 points and above — revoke in-campus vehicle permission for entire study duration.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

<!-- Vehicle status -->
            <section class="vehicle-status-card">
                <div class="vehicle-status-title">My Vehicle Status</div>

                <?php
                $statusClass = 'none';
                $iconClass = 'gray';
                $pillClass = 'none';
                $statusText = $approvalStatus;

                // Enforcement status overrides approval display (no DB change):
                // If accumulated demerit points reach revoke band (>= 20), treat permission as revoked.
                if ($demeritPoints >= 20) {
                    $statusClass = 'revoked';
                    $iconClass = 'revoked';
                    $pillClass = 'revoked';
                    $statusText = 'Revoked';
                } elseif ($approvalStatus === 'Approved') {
                    $statusClass = 'approved';
                    $iconClass = 'green';
                    $pillClass = 'approved';
                    $statusText = 'Approved';
                } elseif ($approvalStatus === 'Pending') {
                    $statusClass = 'pending';
                    $iconClass = 'yellow';
                    $pillClass = 'pending';
                    $statusText = 'Pending';
                }
                ?>

                <div class="vehicle-inner <?php echo $statusClass; ?>">
                    <div style="flex:1;">
                        <div class="vehicle-main">
                            <div class="vehicle-icon-box <?php echo $iconClass; ?>">🚗</div>
                            <div>
                                <div class="vehicle-info-title"><?php echo htmlspecialchars($plateNumber); ?></div>
                                <div class="vehicle-info-sub">
                                    <?php echo htmlspecialchars($vehicleType); ?> &bull;
                                    <?php echo htmlspecialchars($vehicleModel); ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($statusText === 'Approved'): ?>
                        <div class="vehicle-meta">
                            Approved by: <?php echo htmlspecialchars($approvedBy); ?><br>
                            Approval Date: <?php echo htmlspecialchars($approvalDate); ?>
                        </div>
                    <?php elseif ($statusText === 'Revoked'): ?>
                        <div class="vehicle-meta">
                            Permission revoked due to enforcement status.<br>
                            <?php echo htmlspecialchars($enforcementLabel); ?>
                        </div>
                    <?php endif; ?>
                    </div>

                    <div class="status-pill <?php echo $pillClass; ?>">
                        <?php if ($statusText === 'Revoked'): ?>
                            ⛔ <span>Revoked</span>
                        <?php elseif ($statusText === 'Approved'): ?>
                            ✓ <span>Approved</span>
                        <?php elseif ($statusText === 'Pending'): ?>
                            ⏳ <span>Pending</span>
                        <?php else: ?>
                            ℹ️ <span>No Vehicle</span>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            <!-- Booking Statistics Bar Graph -->
<section class="stats-chart-card">
    <div class="stats-chart-header">
        <div class="stats-chart-title">📊 Booking Statistics</div>
        <div class="total-bookings-badge">
            Total: <?php echo $totalBookings; ?> booking<?php echo $totalBookings != 1 ? 's' : ''; ?>
        </div>
    </div>
    
    <div class="bar-graph-container">
        <div class="bar-graph">
            <?php
            $maxCount = max($bookingStats['Pending'], $bookingStats['Active'], 
                           $bookingStats['Completed'], $bookingStats['Cancelled'], 1);
            
            $icons = [
                'Pending' => '⏳',
                'Active' => '✅',
                'Completed' => '🎉',
                'Cancelled' => '❌'
            ];
            
            foreach ($bookingStats as $status => $count):
                $heightPercentage = $maxCount > 0 ? ($count / $maxCount) * 100 : 0;
                $statusLower = strtolower($status);
            ?>
                <div class="bar-item">
                    <div class="bar-column">
                        <div class="bar-value-label"><?php echo $count; ?></div>
                        <div class="bar <?php echo $statusLower; ?>" 
                             style="height: <?php echo max($heightPercentage, 5); ?>%;"
                             title="<?php echo $status; ?>: <?php echo $count; ?> booking<?php echo $count != 1 ? 's' : ''; ?>">
                        </div>
                    </div>
                    <div class="bar-label">
                        <div class="bar-icon"><?php echo $icons[$status]; ?></div>
                        <?php echo $status; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="graph-legend">
        <div class="legend-item">
            <div class="legend-color pending"></div>
            <span>Pending - Awaiting confirmation</span>
        </div>
        <div class="legend-item">
            <div class="legend-color active"></div>
            <span>Active - Currently parking</span>
        </div>
        <div class="legend-item">
            <div class="legend-color completed"></div>
            <span>Completed - Finished</span>
        </div>
        <div class="legend-item">
            <div class="legend-color cancelled"></div>
            <span>Cancelled - Not used</span>
        </div>
    </div>
</section>
        </main>
    </div>
</div>
</body>
</html>