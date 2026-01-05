<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Security: Admin only
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?timeout=1");
    exit;
}

$adminName = $_SESSION['username'] ?? 'Admin';

// ===== SUMMARY STATS =====
$totalAreas = 0;
$totalSpaces = 0;
$closedAreas = 0;
$availableSpaces = 0;

// Total areas
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM parking_area");
$totalAreas = mysqli_fetch_assoc($res)['total'] ?? 0;

// Total spaces
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM parking_space");
$totalSpaces = mysqli_fetch_assoc($res)['total'] ?? 0;

// Closed areas
$res = mysqli_query($conn, "
    SELECT COUNT(DISTINCT area_ID) AS total
    FROM area_closure
    WHERE closed_from <= NOW()
    AND (closed_to IS NULL OR closed_to >= NOW())
");
$closedAreas = mysqli_fetch_assoc($res)['total'] ?? 0;

// Available spaces
$res = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM parking_space ps
    WHERE ps.status = 'Available'
    AND NOT EXISTS (
        SELECT 1 FROM booking b
        WHERE b.space_id = ps.space_id
        AND b.date = CURDATE()
        AND b.status != 'Cancelled'
    )
    AND NOT EXISTS (
        SELECT 1 FROM area_closure ac
        WHERE ac.area_ID = ps.area_ID
        AND ac.closed_from <= NOW()
        AND (ac.closed_to IS NULL OR ac.closed_to >= NOW())
    )
");
$availableSpaces = mysqli_fetch_assoc($res)['total'] ?? 0;

// ===== HANDLE DELETE/CANCEL =====
if (isset($_GET['delete_closure'])) {
    $closure_id = (int)$_GET['delete_closure'];
    $sqlDelete = "DELETE FROM area_closure WHERE closure_ID = ?";
    $stmtDelete = mysqli_prepare($conn, $sqlDelete);
    mysqli_stmt_bind_param($stmtDelete, "i", $closure_id);
    if (mysqli_stmt_execute($stmtDelete)) {
        header("Location: area_closure.php?deleted=1");
        exit;
    }
}

// ===== HANDLE CANCEL (Update closed_to to NOW) =====
if (isset($_GET['cancel_closure'])) {
    $closure_id = (int)$_GET['cancel_closure'];
    $sqlCancel = "UPDATE area_closure SET closed_to = NOW() WHERE closure_ID = ?";
    $stmtCancel = mysqli_prepare($conn, $sqlCancel);
    mysqli_stmt_bind_param($stmtCancel, "i", $closure_id);
    if (mysqli_stmt_execute($stmtCancel)) {
        header("Location: area_closure.php?cancelled=1");
        exit;
    }
}

// ===== HANDLE EDIT CLOSURE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_closure'])) {
    $closure_id = (int)$_POST['closure_id'];
    $area_id = (int)$_POST['area_id'];
    $closure_reason = trim($_POST['closure_reason']);
    $closed_from = $_POST['closed_from'];
    $closed_to = !empty($_POST['closed_to']) ? $_POST['closed_to'] : null;
    
    // Validation
    if ($closure_id <= 0 || $area_id <= 0 || empty($closure_reason) || empty($closed_from)) {
        header("Location: area_closure.php?error=missing_fields");
        exit;
    }
    
    // Convert datetime-local format to MySQL datetime format
    $closed_from_formatted = date('Y-m-d H:i:s', strtotime($closed_from));
    $closed_to_formatted = $closed_to ? date('Y-m-d H:i:s', strtotime($closed_to)) : null;
    
    // Update database
    if ($closed_to_formatted) {
        $sqlUpdate = "UPDATE area_closure SET area_ID = ?, closure_reason = ?, closed_from = ?, closed_to = ? WHERE closure_ID = ?";
        $stmtUpdate = mysqli_prepare($conn, $sqlUpdate);
        mysqli_stmt_bind_param($stmtUpdate, "isssi", $area_id, $closure_reason, $closed_from_formatted, $closed_to_formatted, $closure_id);
    } else {
        $sqlUpdate = "UPDATE area_closure SET area_ID = ?, closure_reason = ?, closed_from = ?, closed_to = NULL WHERE closure_ID = ?";
        $stmtUpdate = mysqli_prepare($conn, $sqlUpdate);
        mysqli_stmt_bind_param($stmtUpdate, "issi", $area_id, $closure_reason, $closed_from_formatted, $closure_id);
    }
    
    if (mysqli_stmt_execute($stmtUpdate)) {
        header("Location: area_closure.php?edited=1");
        exit;
    } else {
        header("Location: area_closure.php?error=update_failed");
        exit;
    }
}

// Flash messages
$flash = '';
if (isset($_GET['deleted'])) {
    $flash = 'Closure deleted successfully.';
} elseif (isset($_GET['cancelled'])) {
    $flash = 'Closure cancelled successfully.';
} elseif (isset($_GET['added'])) {
    $flash = 'Area closure added successfully.';
} elseif (isset($_GET['edited'])) {
    $flash = 'Closure updated successfully.';
} elseif (isset($_GET['error'])) {
    $flash = 'Error: ' . htmlspecialchars($_GET['error']);
}

// ===== GET CLOSURES =====
$closures = [];
$sql = "
    SELECT 
        ac.closure_ID,
        ac.area_ID,
        pa.area_name,
        ac.closure_reason,
        ac.closed_from,
        ac.closed_to,
        CASE 
            WHEN ac.closed_from <= NOW() 
            AND (ac.closed_to IS NULL OR ac.closed_to >= NOW())
            THEN 'Active'
            WHEN ac.closed_from > NOW()
            THEN 'Scheduled'
            ELSE 'Expired'
        END AS status
    FROM area_closure ac
    JOIN parking_area pa ON pa.Area_ID = ac.area_ID
    ORDER BY ac.closed_from DESC
";

$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $closures[] = $row;
    }
}

// ===== GET PARKING AREAS FOR DROPDOWN =====
$parkingAreas = [];
$sqlAreas = "SELECT Area_ID, area_name FROM parking_area ORDER BY area_name ASC";
$resultAreas = mysqli_query($conn, $sqlAreas);
if ($resultAreas) {
    while ($row = mysqli_fetch_assoc($resultAreas)) {
        $parkingAreas[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Area Closure - FKPark</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

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
            padding: 16px 16px 24px;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 8px 16px;
        }

        .sidebar-logo {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .sidebar-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .sidebar-subtitle {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }

        .nav {
            margin-top: 24px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .nav-link {
            display: block;
            padding: 12px 16px;
            border-radius: 8px;
            color: #374151;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .nav-link:hover {
            background-color: #f3f4f6;
        }

        .nav-link.active {
            background-color: #0066ff;
            color: #ffffff;
        }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 16px;
        }

        .back-link {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6b7280;
            text-decoration: none;
            font-size: 14px;
            padding: 8px 12px;
        }

        .back-link:hover {
            color: #374151;
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
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background-color: #ffffff;
            min-width: 300px;
            margin-right: auto;
        }

        .search-box input {
            border: none;
            background: transparent;
            outline: none;
            flex: 1;
            font-size: 14px;
        }

        .sign-out-link {
            color: #0066ff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .sign-out-link:hover {
            text-decoration: underline;
        }

        /* Content */
        .content {
            padding: 24px 40px 32px;
        }

        .breadcrumb {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 26px;
            font-weight: 600;
            margin: 0;
        }

        .sync-info {
            font-size: 13px;
            color: #6b7280;
        }

        .alert {
            background-color: #dcfce7;
            color: #166534;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        /* Stats cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
        }

        .stat-label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #111827;
        }

        .stat-sublabel {
            font-size: 12px;
            color: #9ca3af;
        }

        /* Area Closure Section */
        .closure-section {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .section-controls {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .filter-select {
            padding: 10px 16px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            font-size: 14px;
            outline: none;
            min-width: 150px;
            background-color: #ffffff;
        }

        .btn-add {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            background-color: #0066ff;
            color: #ffffff;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-add:hover {
            background-color: #163a6f;
        }

        /* Closure cards */
        .closure-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .closure-card {
            background-color: #fafafa;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px 24px;
        }

        .closure-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
        }

        .closure-info {
            flex: 1;
        }

        .closure-title {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 6px;
        }

        .closure-dates {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .closure-status-line {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 14px;
            color: #6b7280;
        }

        .closure-actions {
            display: flex;
            gap: 8px;
        }

        .btn-view {
            padding: 8px 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background-color: #ffffff;
            color: #374151;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-view:hover {
            background-color: #f9fafb;
        }

        .btn-edit {
            padding: 8px 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background-color: #dbeafe;
            color: #1e40af;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-edit:hover {
            background-color: #bfdbfe;
        }

        .btn-edit:disabled {
            background-color: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-edit:disabled:hover {
            background-color: #f3f4f6;
        }

        .btn-cancel {
            padding: 8px 20px;
            border-radius: 8px;
            border: none;
            background-color: #0066ff;
            color: #ffffff;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-cancel:hover {
            background-color: #163a6f;
        }

        .btn-delete {
            padding: 8px 20px;
            border-radius: 8px;
            border: none;
            background-color: #dc2626;
            color: #ffffff;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-delete:hover {
            background-color: #b91c1c;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
            font-size: 14px;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 32px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            margin-bottom: 24px;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #111827;
            margin: 0;
        }

        .detail-row {
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        .detail-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .detail-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .detail-value {
            font-size: 14px;
            color: #111827;
            font-weight: 500;
        }

        .status-badge-detail {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge-detail.active {
            background-color: #dcfce7;
            color: #166534;
        }

        .status-badge-detail.scheduled {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-badge-detail.expired {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            border-color: #0066ff;
            box-shadow: 0 0 0 3px rgba(30, 74, 140, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }

        .btn-modal-cancel {
            padding: 10px 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background-color: #ffffff;
            color: #374151;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-modal-cancel:hover {
            background-color: #f9fafb;
        }

        .btn-modal-submit {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            background-color: #0066ff;
            color: #ffffff;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-modal-submit:hover {
            background-color: #163a6f;
        }

        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none;
            }
            .stats-row {
                grid-template-columns: 1fr;
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
                <img src="images/logo.png" alt="FKPark Logo">
            </div>
            <div>
                <div class="sidebar-title">FKPark</div>
                <div class="sidebar-subtitle">Admin — Manage Parking</div>
            </div>
        </div>

        <nav class="nav">
    <a href="parking_area.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'parking_area.php') ? 'active' : ''; ?>">
        <i class="bi bi-calendar-check"></i> Parking Area
    </a>
    <a href="parking_space_list.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'parking_space_list.php' || basename($_SERVER['PHP_SELF']) == 'parking_space.php') ? 'active' : ''; ?>">
        <i class="bi bi-grid-3x3-gap"></i> Parking Spaces
    </a>
    <a href="area_closure.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'area_closure.php') ? 'active' : ''; ?>">
        <i class="bi bi-sign-stop"></i> Area Closure
    </a>
</nav>

        <div class="sidebar-footer">
            <a href="dashboard_admin.php" class="back-link">
                ← Back to Dashboard
            </a>
        </div>
    </aside>

    <!-- Main -->
    <div class="main">
        <!-- Top bar -->
        <header class="topbar">
            <div class="search-box">
    <i class="bi bi-search"></i>
    <input type="text" placeholder="Search..." id="searchInput">
</div>
            <a href="logout.php" class="sign-out-link">Log out</a>
        </header>

        <!-- Content -->
        <main class="content">
            <div class="breadcrumb">
                Admin Dashboard » Manage Parking
            </div>

            <div class="page-header">
                <h1 class="page-title">Manage Parking Workspace</h1>
                <div class="sync-info">
                    Last sync: <?php echo date('n/j/Y, g:i:s A'); ?>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>

            <!-- Stats cards -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Total Areas</div>
                    <div class="stat-value"><?php echo $totalAreas; ?></div>
                    <div class="stat-sublabel"></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Spaces</div>
                    <div class="stat-value"><?php echo $totalSpaces; ?></div>
                    <div class="stat-sublabel">Total capacity</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Closed Areas</div>
                    <div class="stat-value"><?php echo $closedAreas; ?></div>
                    <div class="stat-sublabel">Under maintenance</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Available Spaces (Now)</div>
                    <div class="stat-value"><?php echo $availableSpaces; ?></div>
                    <div class="stat-sublabel">Currently free</div>
                </div>
            </div>

            <!-- Area Closure Section -->
            <div class="closure-section">
                <div class="section-header">
                    <h2 class="section-title">Area Closure</h2>
                    <div class="section-controls">
    <select class="filter-select" id="areaFilter" onchange="filterByArea()">
        <option value="all">All Areas</option>
        <?php foreach ($parkingAreas as $pa): ?>
            <option value="<?php echo htmlspecialchars($pa['area_name']); ?>">
                <?php echo htmlspecialchars($pa['area_name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button class="btn-add" onclick="openModal()">Close Area</button>
</div>
                </div>

                <div class="closure-list">
                    <?php if (empty($closures)): ?>
                        <div class="empty-state">
                            <p>No closures found.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($closures as $c): ?>
                            <div class="closure-card">
                                <div class="closure-header">
                                    <div class="closure-info">
                                        <div class="closure-title">
                                            <?php echo htmlspecialchars($c['area_name']); ?> – <?php echo htmlspecialchars($c['closure_reason']); ?>
                                        </div>
                                        <div class="closure-dates">
                                            <?php echo date('n/j/Y, g:i:s A', strtotime($c['closed_from'])); ?>
                                            →
                                            <?php echo $c['closed_to'] ? date('n/j/Y, g:i:s A', strtotime($c['closed_to'])) : 'Open-ended'; ?>
                                        </div>
                                        <div class="closure-status-line">
                                            <span>Status: <?php echo $c['status']; ?></span>
                                        </div>
                                    </div>
                                    <div class="closure-actions">
                                        <button class="btn-view" onclick='viewClosure(<?php echo json_encode($c); ?>)'>View</button>
                                        <?php if ($c['status'] === 'Active'): ?>
                                            <button class="btn-edit" onclick='editClosure(<?php echo json_encode($c); ?>)'>✏️ Edit</button>
                                            <a href="area_closure.php?cancel_closure=<?php echo $c['closure_ID']; ?>" 
                                               class="btn-cancel" 
                                               onclick="return confirm('Cancel this area closure? The area will be reopened immediately.')">Cancel</a>
                                        <?php elseif ($c['status'] === 'Expired'): ?>
                                            <button class="btn-edit" disabled>✏️ Edit</button>
                                            <a href="area_closure.php?delete_closure=<?php echo $c['closure_ID']; ?>" 
                                               class="btn-delete" 
                                               onclick="return confirm('Delete this closure record?')">Delete</a>
                                        <?php else: ?>
                                            <button class="btn-edit" onclick='editClosure(<?php echo json_encode($c); ?>)'>✏️ Edit</button>
                                            <a href="area_closure.php?cancel_closure=<?php echo $c['closure_ID']; ?>" 
                                               class="btn-cancel" 
                                               onclick="return confirm('Cancel this area closure? The area will be reopened immediately.')">Cancel</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
<!-- Add Modal -->
<div id="addClosureModal" class="modal">
    <div class="modal-content">
        <h2 class="modal-title">Add Area Closure</h2>
        <form method="post" action="add_closure.php">
            <div class="form-group">
                <label class="form-label">Parking Area *</label>
                <select name="area_id" class="form-select" required>
                    <option value="">Select area</option>
                    <?php foreach ($parkingAreas as $pa): ?>
                        <option value="<?php echo $pa['Area_ID']; ?>"><?php echo htmlspecialchars($pa['area_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Closure Reason *</label>
                <input type="text" name="closure_reason" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">Start Date & Time *</label>
                <input type="datetime-local" name="closed_from" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">End Date & Time</label>
                <input type="datetime-local" name="closed_to" class="form-input">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-modal-submit">Submit</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editClosureModal" class="modal">
    <div class="modal-content">
        <h2 class="modal-title">Edit Area Closure</h2>
        <form method="post" id="editForm">
            <input type="hidden" name="edit_closure" value="1">
            <input type="hidden" name="closure_id" id="edit_closure_id">
            <div class="form-group">
                <label class="form-label">Parking Area *</label>
                <select name="area_id" id="edit_area_id" class="form-select" required>
                    <?php foreach ($parkingAreas as $pa): ?>
                        <option value="<?php echo $pa['Area_ID']; ?>"><?php echo htmlspecialchars($pa['area_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Closure Reason *</label>
                <input type="text" name="closure_reason" id="edit_closure_reason" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">Start Date & Time *</label>
                <input type="datetime-local" name="closed_from" id="edit_closed_from" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">End Date & Time</label>
                <input type="datetime-local" name="closed_to" id="edit_closed_to" class="form-input">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-modal-submit">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <h2 class="modal-title">Closure Details</h2>
        <div id="closureDetails"></div>
        <div class="modal-actions">
            <button class="btn-modal-cancel" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>
<script>
// ===== MODAL FUNCTIONS =====
function openModal() {
    document.getElementById('addClosureModal').classList.add('active');
}

function closeModal() {
    document.getElementById('addClosureModal').classList.remove('active');
}

function openEditModal() {
    document.getElementById('editClosureModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editClosureModal').classList.remove('active');
}

function viewClosure(closureData) {
    const detailsHtml = `
        <div class="detail-row">
            <div class="detail-label">Area</div>
            <div class="detail-value">${closureData.area_name}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Reason</div>
            <div class="detail-value">${closureData.closure_reason}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Start Date & Time</div>
            <div class="detail-value">${closureData.closed_from}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">End Date & Time</div>
            <div class="detail-value">${closureData.closed_to || 'Open-ended'}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Status</div>
            <div class="detail-value">
                <span class="status-badge-detail ${closureData.status.toLowerCase()}">${closureData.status}</span>
            </div>
        </div>
    `;
    
    document.getElementById('closureDetails').innerHTML = detailsHtml;
    document.getElementById('viewModal').classList.add('active');
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
}

function editClosure(closureData) {
    document.getElementById('edit_closure_id').value = closureData.closure_ID;
    document.getElementById('edit_area_id').value = closureData.area_ID;
    document.getElementById('edit_closure_reason').value = closureData.closure_reason;
    
    // Format dates for datetime-local input
    const closedFrom = new Date(closureData.closed_from);
    document.getElementById('edit_closed_from').value = formatDateTimeLocal(closedFrom);
    
    if (closureData.closed_to) {
        const closedTo = new Date(closureData.closed_to);
        document.getElementById('edit_closed_to').value = formatDateTimeLocal(closedTo);
    } else {
        document.getElementById('edit_closed_to').value = '';
    }
    
    openEditModal();
}

function formatDateTimeLocal(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
    }
}

// ===== AREA FILTER FUNCTION =====
function filterByArea() {
    const selectedArea = document.getElementById('areaFilter').value.toLowerCase();
    const closureCards = document.querySelectorAll('.closure-card');
    let visibleCount = 0;
    
    closureCards.forEach(card => {
        const areaName = card.querySelector('.closure-title').textContent.toLowerCase();
        
        if (selectedArea === 'all' || areaName.includes(selectedArea)) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
    
    // Show/hide "no results" message
    const closureList = document.querySelector('.closure-list');
    const existingNoResults = document.getElementById('noFilterResults');
    
    if (visibleCount === 0 && closureCards.length > 0) {
        if (!existingNoResults) {
            const noResults = document.createElement('div');
            noResults.id = 'noFilterResults';
            noResults.className = 'empty-state';
            noResults.innerHTML = '<p>No closures found for the selected area.</p>';
            closureList.appendChild(noResults);
        }
    } else {
        if (existingNoResults) {
            existingNoResults.remove();
        }
    }
    
    console.log(`Filter: ${selectedArea} - Showing ${visibleCount} closure(s)`);
}

// ===== AUTO-REFRESH STATS FUNCTIONS =====
setInterval(autoRefreshStats, 3000);

document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        autoRefreshStats();
    }
});

function autoRefreshStats() {
    fetch('get_stats_update.php?t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStats(data.stats);
                if (data.server_time) {
                    const syncInfo = document.querySelector('.sync-info');
                    if (syncInfo) {
                        syncInfo.textContent = 'Last sync: ' + data.server_time;
                    }
                }
            }
        })
        .catch(error => console.error('Stats refresh error:', error));
}

function updateStats(stats) {
    const statMap = {
        totalAreas: stats.totalAreas,
        totalSpaces: stats.totalSpaces,
        closedAreas: stats.closedAreas,
        availableSpaces: stats.availableSpaces
    };
    
    Object.keys(statMap).forEach(key => {
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            const label = card.querySelector('.stat-label').textContent.toLowerCase();
            const valueEl = card.querySelector('.stat-value');
            
            if ((key === 'totalAreas' && label.includes('total areas')) ||
                (key === 'totalSpaces' && label.includes('total spaces')) ||
                (key === 'closedAreas' && label.includes('closed areas')) ||
                (key === 'availableSpaces' && label.includes('available'))) {
                
                const oldValue = valueEl.textContent;
                const newValue = statMap[key].toString();
                
                if (oldValue !== newValue) {
                    console.log(`${key} changed: ${oldValue} → ${newValue}`);
                    valueEl.textContent = newValue;
                    
                    valueEl.style.transition = 'all 0.3s ease';
                    valueEl.style.backgroundColor = '#fef3c7';
                    setTimeout(() => {
                        valueEl.style.backgroundColor = '';
                    }, 500);
                }
            }
        });
    });
}

console.log('Area Closure Auto-Refresh Initialized');
setTimeout(autoRefreshStats, 1000);

// ===== SEARCH FUNCTIONALITY =====
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const closureCards = document.querySelectorAll('.closure-card');
        
        closureCards.forEach(card => {
            const text = card.textContent.toLowerCase();
            card.style.display = text.includes(searchTerm) ? '' : 'none';
        });
        
        // Check if any results are visible
        const visibleCards = document.querySelectorAll('.closure-card[style=""]');
        const emptyState = document.querySelector('.empty-state');
        
        if (closureCards.length > 0 && visibleCards.length === 0 && !emptyState) {
            // Show "no results" message
            const closureList = document.querySelector('.closure-list');
            if (!document.getElementById('noSearchResults')) {
                const noResults = document.createElement('div');
                noResults.id = 'noSearchResults';
                noResults.className = 'empty-state';
                noResults.innerHTML = '<p>No closures match your search.</p>';
                closureList.appendChild(noResults);
            }
        } else {
            // Remove "no results" message if it exists
            const noResults = document.getElementById('noSearchResults');
            if (noResults) {
                noResults.remove();
            }
        }
    });
}
</script>
</body>
</html>