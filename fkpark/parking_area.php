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

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$flash = '';
$flashType = 'success'; // success | error

// ===== HANDLE ADD PARKING AREA =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_area'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash = "Invalid security token.";
        $flashType = 'error';
    } else {
        $areaName = strtoupper(trim($_POST['area_name'] ?? ''));
        $areaType = trim($_POST['area_type'] ?? '');
        $numSpaces = (int)($_POST['num_spaces'] ?? 0);

        // Validation
        if (empty($areaName) || !in_array($areaType, ['staff', 'student'])) {
            $flash = "Please provide valid area name and type.";
            $flashType = 'error';
        } elseif ($numSpaces < 1 || $numSpaces > 100) {
            $flash = "Number of spaces must be between 1 and 100.";
            $flashType = 'error';
        } else {
            // Check if area name already exists
            $sqlCheck = "SELECT COUNT(*) as count FROM parking_area WHERE area_name = ?";
            $stmtCheck = mysqli_prepare($conn, $sqlCheck);
            mysqli_stmt_bind_param($stmtCheck, "s", $areaName);
            mysqli_stmt_execute($stmtCheck);
            $resultCheck = mysqli_stmt_get_result($stmtCheck);
            $rowCheck = mysqli_fetch_assoc($resultCheck);

            if ($rowCheck['count'] > 0) {
                $flash = "Area name already exists. Please use a different name.";
                $flashType = 'error';
            } else {
                mysqli_begin_transaction($conn);
                try {
                    // 1. Insert parking area
                    $sqlInsertArea = "INSERT INTO parking_area (area_name, area_type, admin_id) VALUES (?, ?, ?)";
                    $stmtInsertArea = mysqli_prepare($conn, $sqlInsertArea);
                    mysqli_stmt_bind_param($stmtInsertArea, "sss", $areaName, $areaType, $adminName);
                    mysqli_stmt_execute($stmtInsertArea);
                    $areaId = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmtInsertArea);

                    // 2. Generate parking spaces with QR codes
                    $sqlInsertSpace = "INSERT INTO parking_space (space_num, status, QrCode, area_ID) VALUES (?, 'Available', ?, ?)";
                    $stmtInsertSpace = mysqli_prepare($conn, $sqlInsertSpace);

                    for ($i = 1; $i <= $numSpaces; $i++) {
                        $spaceNum = $areaName . '-' . str_pad($i, 2, '0', STR_PAD_LEFT);
                        
                        // Generate unique QR code data
                        $qrData = base64_encode("FKPARK_SPACE_" . $areaId . "_" . $i . "_" . time());
                        
                        mysqli_stmt_bind_param($stmtInsertSpace, "ssi", $spaceNum, $qrData, $areaId);
                        mysqli_stmt_execute($stmtInsertSpace);
                    }
                    mysqli_stmt_close($stmtInsertSpace);

                    mysqli_commit($conn);
                    $flash = "Parking area '{$areaName}' with {$numSpaces} spaces created successfully!";
                    $flashType = 'success';

                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $flash = "Failed to create parking area: " . $e->getMessage();
                    $flashType = 'error';
                }
            }
        }
    }
}

// ===== HANDLE EDIT PARKING AREA =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_area'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash = "Invalid security token.";
        $flashType = 'error';
    } else {
        $areaId = (int)($_POST['area_id'] ?? 0);
        $areaName = strtoupper(trim($_POST['area_name'] ?? ''));
        $areaType = trim($_POST['area_type'] ?? '');

        if ($areaId <= 0 || empty($areaName) || !in_array($areaType, ['staff', 'student'])) {
            $flash = "Invalid data provided.";
            $flashType = 'error';
        } else {
            $sqlUpdate = "UPDATE parking_area SET area_name = ?, area_type = ? WHERE Area_ID = ?";
            $stmtUpdate = mysqli_prepare($conn, $sqlUpdate);
            mysqli_stmt_bind_param($stmtUpdate, "ssi", $areaName, $areaType, $areaId);
            
            if (mysqli_stmt_execute($stmtUpdate)) {
                $flash = "Parking area updated successfully!";
                $flashType = 'success';
            } else {
                $flash = "Failed to update parking area.";
                $flashType = 'error';
            }
            mysqli_stmt_close($stmtUpdate);
        }
    }
}

// ===== HANDLE DELETE PARKING AREA =====
if (isset($_GET['delete_area'])) {
    $areaId = (int)$_GET['delete_area'];
    
    if ($areaId > 0) {
        mysqli_begin_transaction($conn);
        try {
            // Check if there are active bookings
            $sqlCheckBookings = "SELECT COUNT(*) as count FROM booking b 
                                 JOIN parking_space ps ON ps.space_id = b.space_id 
                                 WHERE ps.area_ID = ? AND b.status IN ('Active', 'Pending')";
            $stmtCheck = mysqli_prepare($conn, $sqlCheckBookings);
            mysqli_stmt_bind_param($stmtCheck, "i", $areaId);
            mysqli_stmt_execute($stmtCheck);
            $resultCheck = mysqli_stmt_get_result($stmtCheck);
            $rowCheck = mysqli_fetch_assoc($resultCheck);

            if ($rowCheck['count'] > 0) {
                $flash = "Cannot delete area with active bookings. The booking must be clear first.";
                $flashType = 'error';
            } else {
                // Delete area closures first
                $sqlDeleteClosures = "DELETE FROM area_closure WHERE area_ID = ?";
                $stmtDeleteClosures = mysqli_prepare($conn, $sqlDeleteClosures);
                mysqli_stmt_bind_param($stmtDeleteClosures, "i", $areaId);
                mysqli_stmt_execute($stmtDeleteClosures);

                // Delete parking spaces
                $sqlDeleteSpaces = "DELETE FROM parking_space WHERE area_ID = ?";
                $stmtDeleteSpaces = mysqli_prepare($conn, $sqlDeleteSpaces);
                mysqli_stmt_bind_param($stmtDeleteSpaces, "i", $areaId);
                mysqli_stmt_execute($stmtDeleteSpaces);

                // Delete parking area
                $sqlDeleteArea = "DELETE FROM parking_area WHERE Area_ID = ?";
                $stmtDeleteArea = mysqli_prepare($conn, $sqlDeleteArea);
                mysqli_stmt_bind_param($stmtDeleteArea, "i", $areaId);
                mysqli_stmt_execute($stmtDeleteArea);

                mysqli_commit($conn);
                $flash = "Parking area deleted successfully!";
                $flashType = 'success';
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $flash = "Failed to delete parking area: " . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// ===== SUMMARY STATS =====
$totalAreas = 0;
$totalSpaces = 0;
$closedAreas = 0;
$availableSpaces = 0;

$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM parking_area");
$totalAreas = mysqli_fetch_assoc($res)['total'] ?? 0;

$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM parking_space");
$totalSpaces = mysqli_fetch_assoc($res)['total'] ?? 0;

$res = mysqli_query($conn, "
    SELECT COUNT(DISTINCT area_ID) AS total
    FROM area_closure
    WHERE closed_from <= NOW()
    AND (closed_to IS NULL OR closed_to >= NOW())
");
$closedAreas = mysqli_fetch_assoc($res)['total'] ?? 0;

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

// ===== GET PARKING AREAS =====
$areas = [];
$sqlAreas = "
    SELECT 
        pa.Area_ID,
        pa.area_name,
        pa.area_type,
        COUNT(ps.space_id) as total_spaces,
        SUM(CASE 
            WHEN ps.status = 'Available' 
            AND NOT EXISTS (
                SELECT 1 FROM booking b
                WHERE b.space_id = ps.space_id
                AND b.date = CURDATE()
                AND b.status != 'Cancelled'
            )
            AND NOT EXISTS (
                SELECT 1 FROM area_closure ac2
                WHERE ac2.area_ID = ps.area_ID
                AND ac2.closed_from <= NOW()
                AND (ac2.closed_to IS NULL OR ac2.closed_to >= NOW())
            )
            THEN 1 
            ELSE 0 
        END) as available_spaces,
        CASE 
            WHEN EXISTS (
                SELECT 1 FROM area_closure ac
                WHERE ac.area_ID = pa.Area_ID
                AND ac.closed_from <= NOW()
                AND (ac.closed_to IS NULL OR ac.closed_to >= NOW())
            ) THEN 'closed'
            ELSE 'open'
        END AS status
    FROM parking_area pa
    LEFT JOIN parking_space ps ON ps.area_ID = pa.Area_ID
    GROUP BY pa.Area_ID, pa.area_name, pa.area_type
    ORDER BY pa.area_name ASC
";

$result = mysqli_query($conn, $sqlAreas);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $areas[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>


    

    <meta charset="UTF-8">
    <title>Manage Parking - FKPark</title>


    <!-- Add this in the <head> section -->
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
        .main {
            display: flex;
            flex-direction: column;
        }
        .topbar {
            height: 64px;
            background-color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
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
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert.success {
            background-color: #dcfce7;
            color: #166534;
        }
        .alert.error {
            background-color: #fee2e2;
            color: #b91c1c;
        }
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
        .parking-section {
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
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background-color: #f9fafb;
        }
        th, td {
            padding: 14px 16px;
            font-size: 14px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }
        tbody tr:hover {
            background-color: #f9fafb;
        }
        tr:last-child td {
            border-bottom: none;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-badge.open {
            background-color: #dcfce7;
            color: #166534;
        }
        .status-badge.closed {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .action-links {
            display: flex;
            gap: 12px;
        }
        .action-link {
            color: #0066ff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }
        .action-link:hover {
            text-decoration: underline;
        }
        .action-link.danger {
            color: #dc2626;
        }
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
        .form-field {
            margin-bottom: 16px;
        }
        .form-label {
            display: block;
            font-size: 13px;
            margin-bottom: 6px;
            color: #374151;
            font-weight: 500;
        }
        .form-input, .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        .form-input:focus, .form-select:focus {
            border-color: #0066ff;
            box-shadow: 0 0 0 3px rgba(30, 74, 140, 0.1);
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
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo"><img src="images/logo.png" alt="FKPark Logo"></div>
            <div><div class="sidebar-title">FKPark</div><div class="sidebar-subtitle">Admin — Manage Parking</div></div>
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
</nav>
        <div class="sidebar-footer"><a href="dashboard_admin.php" class="back-link">← Back to Dashboard</a></div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="search-box">
    <i class="bi bi-search"></i>
    <input type="text" placeholder="Search..." id="searchInput">
</div>
            <a href="logout.php" class="sign-out-link">Log out</a>
        </header>

        <main class="content">
            <div class="breadcrumb">Admin Dashboard » Manage Parking</div>
            <div class="page-header">
                <h1 class="page-title">Manage Parking Workspace</h1>
                <div class="sync-info">Last sync: <?php echo date('n/j/Y, g:i:s A'); ?></div>
            </div>

            <?php if ($flash): ?>
                <div class="alert <?php echo $flashType; ?>"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>

            <div class="stats-row">
                <div class="stat-card"><div class="stat-label">Total Areas</div><div class="stat-value"><?php echo $totalAreas; ?></div><div class="stat-sublabel"></div></div>
                <div class="stat-card"><div class="stat-label">Total Spaces</div><div class="stat-value"><?php echo $totalSpaces; ?></div><div class="stat-sublabel">Total capacity</div></div>
                <div class="stat-card"><div class="stat-label">Closed Areas</div><div class="stat-value"><?php echo $closedAreas; ?></div><div class="stat-sublabel">Under maintenance</div></div>
                <div class="stat-card"><div class="stat-label">Available Spaces (Now)</div><div class="stat-value"><?php echo $availableSpaces; ?></div><div class="stat-sublabel">Currently free</div></div>
            </div>

            <div class="parking-section">
                <div class="section-header">
                    <div class="section-title">Parking Area</div>
                    <button class="btn-add" onclick="openAddModal()">+ Add Parking Area</button>
                </div>

                <table>
                    <thead><tr><th>Area</th><th>Type</th><th>Status</th><th>Capacity</th><th>Available</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($areas)): ?>
                            <tr><td colspan="6" style="text-align: center; color: #6b7280; padding: 40px;">No parking areas found. Click "Add Parking Area" to create one.</td></tr>
                        <?php else: ?>
                            <?php foreach ($areas as $area): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($area['area_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($area['area_type']); ?></td>
                                    <td><span class="status-badge <?php echo $area['status']; ?>"><?php echo ucfirst($area['status']); ?></span></td>
                                    <td><?php echo $area['total_spaces']; ?></td>
                                    <td><?php echo $area['available_spaces']; ?></td>
                                    <td>
                                        <div class="action-links">
                                            <a href="parking_space.php?area_id=<?php echo $area['Area_ID']; ?>" class="action-link">Manage</a>
                                            <a href="#" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($area)); ?>)" class="action-link">Edit</a>
                                            <a href="parking_area.php?delete_area=<?php echo $area['Area_ID']; ?>" class="action-link danger" onclick="return confirm('Delete this parking area and all its spaces? This cannot be undone.')">Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2 class="modal-title">Add Parking Area</h2></div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <div class="form-field"><label class="form-label">Area Name</label><input type="text" name="area_name" class="form-input" placeholder="e.g., A1, B1, C1" required></div>
            <div class="form-field"><label class="form-label">Area Type</label><select name="area_type" class="form-select" required><option value="">Select type</option><option value="staff">Staff</option><option value="student">Student</option></select></div>
            <div class="form-field"><label class="form-label">Number of Spaces</label><input type="number" name="num_spaces" class="form-input" min="1" max="100" value="25" required></div>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeAddModal()">Cancel</button>
                <button type="submit" name="add_area" class="btn-modal-submit">Create Area</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2 class="modal-title">Edit Parking Area</h2></div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="area_id" id="edit_area_id">
            <div class="form-field"><label class="form-label">Area Name</label><input type="text" name="area_name" id="edit_area_name" class="form-input" required></div>
            <div class="form-field"><label class="form-label">Area Type</label><select name="area_type" id="edit_area_type" class="form-select" required><option value="staff">Staff</option><option value="student">Student</option></select></div>
            <p style="color: #6b7280; font-size: 13px; margin-top: 10px;">Note: Number of spaces cannot be edited. To change capacity, delete and recreate the area.</p>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" name="edit_area" class="btn-modal-submit">Update Area</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() { document.getElementById('addModal').classList.add('active'); }
function closeAddModal() { document.getElementById('addModal').classList.remove('active'); }
function openEditModal(area) {
    document.getElementById('edit_area_id').value = area.Area_ID;
    document.getElementById('edit_area_name').value = area.area_name;
    document.getElementById('edit_area_type').value = area.area_type;
    document.getElementById('editModal').classList.add('active');
}
function closeEditModal() { document.getElementById('editModal').classList.remove('active'); }
window.onclick = function(e) { if (e.target.classList.contains('modal')) { e.target.classList.remove('active'); } }
document.getElementById('searchInput').addEventListener('keyup', function() {
    const term = this.value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
});
</script>
</body>
</html>