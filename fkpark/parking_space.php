<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SET MALAYSIA TIMEZONE
date_default_timezone_set('Asia/Kuala_Lumpur');

session_start();
require 'db.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Only Admin can access
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?timeout=1");
    exit;
}

$adminName = $_SESSION['username'] ?? 'Admin User';

// Get area_id from URL
$area_id = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;

if ($area_id <= 0) {
    header("Location: parking_area.php");
    exit;
}

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$flash = '';
$flashType = 'success';

// Get area details
$areaInfo = null;
$sqlArea = "SELECT Area_ID, area_name, area_type FROM parking_area WHERE Area_ID = ?";
$stmtArea = mysqli_prepare($conn, $sqlArea);
mysqli_stmt_bind_param($stmtArea, "i", $area_id);
mysqli_stmt_execute($stmtArea);
$resultArea = mysqli_stmt_get_result($stmtArea);
if ($row = mysqli_fetch_assoc($resultArea)) {
    $areaInfo = $row;
} else {
    header("Location: parking_area.php");
    exit;
}

// ===== HANDLE ADD PARKING SPACE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_space'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash = "Invalid security token.";
        $flashType = 'error';
    } else {
        $spaceNum = strtoupper(trim($_POST['space_num'] ?? ''));
        
        if (empty($spaceNum)) {
            $flash = "Please provide a space number.";
            $flashType = 'error';
        } else {
            // Check if space number already exists in this area
            $sqlCheck = "SELECT COUNT(*) as count FROM parking_space WHERE area_ID = ? AND space_num = ?";
            $stmtCheck = mysqli_prepare($conn, $sqlCheck);
            mysqli_stmt_bind_param($stmtCheck, "is", $area_id, $spaceNum);
            mysqli_stmt_execute($stmtCheck);
            $resultCheck = mysqli_stmt_get_result($stmtCheck);
            $rowCheck = mysqli_fetch_assoc($resultCheck);
            
            if ($rowCheck['count'] > 0) {
                $flash = "Space number already exists in this area.";
                $flashType = 'error';
            } else {
                // Generate QR code
                $qrData = base64_encode("FKPARK_SPACE_" . $area_id . "_" . $spaceNum . "_" . time());
                
                // Insert new space
                $sqlInsert = "INSERT INTO parking_space (space_num, status, QrCode, area_ID) VALUES (?, 'Available', ?, ?)";
                $stmtInsert = mysqli_prepare($conn, $sqlInsert);
                mysqli_stmt_bind_param($stmtInsert, "ssi", $spaceNum, $qrData, $area_id);
                
                if (mysqli_stmt_execute($stmtInsert)) {
                    $flash = "Parking space '$spaceNum' added successfully!";
                    $flashType = 'success';
                } else {
                    $flash = "Failed to add parking space: " . mysqli_error($conn);
                    $flashType = 'error';
                }
            }
        }
    }
}

// ===== HANDLE EDIT PARKING SPACE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_space'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash = "Invalid security token.";
        $flashType = 'error';
    } else {
        $spaceId = (int)($_POST['space_id'] ?? 0);
        $newSpaceNum = strtoupper(trim($_POST['space_num'] ?? ''));
        
        if ($spaceId <= 0 || empty($newSpaceNum)) {
            $flash = "Invalid data provided.";
            $flashType = 'error';
        } else {
            // Check if new space number conflicts with existing (excluding current space)
            $sqlCheck = "SELECT COUNT(*) as count FROM parking_space WHERE area_ID = ? AND space_num = ? AND space_id != ?";
            $stmtCheck = mysqli_prepare($conn, $sqlCheck);
            mysqli_stmt_bind_param($stmtCheck, "isi", $area_id, $newSpaceNum, $spaceId);
            mysqli_stmt_execute($stmtCheck);
            $resultCheck = mysqli_stmt_get_result($stmtCheck);
            $rowCheck = mysqli_fetch_assoc($resultCheck);
            
            if ($rowCheck['count'] > 0) {
                $flash = "Space number already exists in this area.";
                $flashType = 'error';
            } else {
                // Update space
                $sqlUpdate = "UPDATE parking_space SET space_num = ? WHERE space_id = ?";
                $stmtUpdate = mysqli_prepare($conn, $sqlUpdate);
                mysqli_stmt_bind_param($stmtUpdate, "si", $newSpaceNum, $spaceId);
                
                if (mysqli_stmt_execute($stmtUpdate)) {
                    $flash = "Parking space updated successfully!";
                    $flashType = 'success';
                } else {
                    $flash = "Failed to update parking space: " . mysqli_error($conn);
                    $flashType = 'error';
                }
            }
        }
    }
}

// ===== HANDLE DELETE PARKING SPACE =====
if (isset($_GET['delete_space'])) {
    $spaceId = (int)$_GET['delete_space'];
    
    if ($spaceId > 0) {
        // Check if there are active bookings for this space
        $sqlCheckBookings = "SELECT COUNT(*) as count FROM booking WHERE space_id = ? AND status IN ('Active', 'Pending')";
        $stmtCheckBookings = mysqli_prepare($conn, $sqlCheckBookings);
        mysqli_stmt_bind_param($stmtCheckBookings, "i", $spaceId);
        mysqli_stmt_execute($stmtCheckBookings);
        $resultCheckBookings = mysqli_stmt_get_result($stmtCheckBookings);
        $rowCheckBookings = mysqli_fetch_assoc($resultCheckBookings);
        
        if ($rowCheckBookings['count'] > 0) {
            $flash = "Cannot delete space with active bookings. Cancel all bookings first.";
            $flashType = 'error';
        } else {
            mysqli_begin_transaction($conn);
            try {
                // Delete associated parking records first
                $sqlDeleteParking = "DELETE p FROM parking p 
                                    JOIN booking b ON b.booking_id = p.booking_id 
                                    WHERE b.space_id = ?";
                $stmtDeleteParking = mysqli_prepare($conn, $sqlDeleteParking);
                mysqli_stmt_bind_param($stmtDeleteParking, "i", $spaceId);
                mysqli_stmt_execute($stmtDeleteParking);
                
                // Delete completed/cancelled bookings
                $sqlDeleteBookings = "DELETE FROM booking WHERE space_id = ? AND status IN ('Completed', 'Cancelled')";
                $stmtDeleteBookings = mysqli_prepare($conn, $sqlDeleteBookings);
                mysqli_stmt_bind_param($stmtDeleteBookings, "i", $spaceId);
                mysqli_stmt_execute($stmtDeleteBookings);
                
                // Delete the parking space
                $sqlDelete = "DELETE FROM parking_space WHERE space_id = ?";
                $stmtDelete = mysqli_prepare($conn, $sqlDelete);
                mysqli_stmt_bind_param($stmtDelete, "i", $spaceId);
                
                if (mysqli_stmt_execute($stmtDelete)) {
                    mysqli_commit($conn);
                    header("Location: parking_space.php?area_id=$area_id&deleted=1");
                    exit;
                } else {
                    throw new Exception("Failed to delete parking space");
                }
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $flash = "Failed to delete parking space: " . $e->getMessage();
                $flashType = 'error';
            }
        }
    }
}

// ===== HANDLE BULK DELETE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash = "Invalid security token.";
        $flashType = 'error';
    } else {
        $selectedSpaces = isset($_POST['selected_spaces']) ? explode(',', $_POST['selected_spaces']) : [];
        $selectedSpaces = array_filter(array_map('intval', $selectedSpaces));
        
        if (empty($selectedSpaces)) {
            $flash = "No spaces selected for deletion.";
            $flashType = 'error';
        } else {
            $deletedCount = 0;
            $skippedCount = 0;
            
            mysqli_begin_transaction($conn);
            try {
                foreach ($selectedSpaces as $spaceId) {
                    // Check if space has active bookings
                    $sqlCheck = "SELECT COUNT(*) as count FROM booking WHERE space_id = ? AND status IN ('Active', 'Pending')";
                    $stmtCheck = mysqli_prepare($conn, $sqlCheck);
                    mysqli_stmt_bind_param($stmtCheck, "i", $spaceId);
                    mysqli_stmt_execute($stmtCheck);
                    $resultCheck = mysqli_stmt_get_result($stmtCheck);
                    $rowCheck = mysqli_fetch_assoc($resultCheck);
                    
                    if ($rowCheck['count'] > 0) {
                        $skippedCount++;
                        continue;
                    }
                    
                    // Delete associated parking records
                    $sqlDeleteParking = "DELETE p FROM parking p 
                                        JOIN booking b ON b.booking_id = p.booking_id 
                                        WHERE b.space_id = ?";
                    $stmtDeleteParking = mysqli_prepare($conn, $sqlDeleteParking);
                    mysqli_stmt_bind_param($stmtDeleteParking, "i", $spaceId);
                    mysqli_stmt_execute($stmtDeleteParking);
                    
                    // Delete completed/cancelled bookings
                    $sqlDeleteBookings = "DELETE FROM booking WHERE space_id = ? AND status IN ('Completed', 'Cancelled')";
                    $stmtDeleteBookings = mysqli_prepare($conn, $sqlDeleteBookings);
                    mysqli_stmt_bind_param($stmtDeleteBookings, "i", $spaceId);
                    mysqli_stmt_execute($stmtDeleteBookings);
                    
                    // Delete the parking space
                    $sqlDelete = "DELETE FROM parking_space WHERE space_id = ?";
                    $stmtDelete = mysqli_prepare($conn, $sqlDelete);
                    mysqli_stmt_bind_param($stmtDelete, "i", $spaceId);
                    
                    if (mysqli_stmt_execute($stmtDelete)) {
                        $deletedCount++;
                    }
                }
                
                mysqli_commit($conn);
                
                if ($deletedCount > 0 && $skippedCount > 0) {
                    $flash = "Deleted $deletedCount space(s). Skipped $skippedCount space(s) with active bookings.";
                    $flashType = 'success';
                } elseif ($deletedCount > 0) {
                    $flash = "Successfully deleted $deletedCount parking space(s)!";
                    $flashType = 'success';
                } else {
                    $flash = "No spaces were deleted. All selected spaces have active bookings.";
                    $flashType = 'error';
                }
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $flash = "Failed to delete spaces: " . $e->getMessage();
                $flashType = 'error';
            }
        }
    }
}

// Check for flash messages from redirect
if (isset($_GET['deleted'])) {
    $flash = "Parking space deleted successfully!";
    $flashType = 'success';
}

// AUTO-GENERATE QR CODES for spaces that don't have them
$sqlCheckQR = "SELECT space_id FROM parking_space WHERE area_ID = ? AND (QrCode IS NULL OR QrCode = '')";
$stmtCheckQR = mysqli_prepare($conn, $sqlCheckQR);
mysqli_stmt_bind_param($stmtCheckQR, "i", $area_id);
mysqli_stmt_execute($stmtCheckQR);
$resultCheckQR = mysqli_stmt_get_result($stmtCheckQR);

while ($row = mysqli_fetch_assoc($resultCheckQR)) {
    $space_id = $row['space_id'];
    $qrData = base64_encode("FKPARK_SPACE_" . $space_id);
    
    $sqlUpdateQR = "UPDATE parking_space SET QrCode = ? WHERE space_id = ?";
    $stmtUpdateQR = mysqli_prepare($conn, $sqlUpdateQR);
    mysqli_stmt_bind_param($stmtUpdateQR, "si", $qrData, $space_id);
    mysqli_stmt_execute($stmtUpdateQR);
}

// Check area status
$areaStatus = 'open';
$sqlCheckClosure = "SELECT closure_ID FROM area_closure 
                    WHERE area_ID = ? 
                    AND (closed_to IS NULL OR closed_to >= NOW())
                    AND closed_from <= NOW()
                    LIMIT 1";
$stmtCheck = mysqli_prepare($conn, $sqlCheckClosure);
mysqli_stmt_bind_param($stmtCheck, "i", $area_id);
mysqli_stmt_execute($stmtCheck);
$resultCheck = mysqli_stmt_get_result($stmtCheck);
if (mysqli_fetch_assoc($resultCheck)) {
    $areaStatus = 'closed';
}

// Count total capacity
$totalCapacity = 0;
$sqlCapacity = "SELECT COUNT(*) as total FROM parking_space WHERE area_ID = ?";
$stmtCapacity = mysqli_prepare($conn, $sqlCapacity);
mysqli_stmt_bind_param($stmtCapacity, "i", $area_id);
mysqli_stmt_execute($stmtCapacity);
$resultCapacity = mysqli_stmt_get_result($stmtCapacity);
if ($row = mysqli_fetch_assoc($resultCapacity)) {
    $totalCapacity = $row['total'];
}

// Get parking spaces with booking info
$filterStatus = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$spaces = [];

$sqlSpaces = "
    SELECT 
        ps.space_id, 
        ps.space_num, 
        ps.status as db_status,
        ps.QrCode,
        CASE 
            WHEN EXISTS (
                SELECT 1 FROM area_closure ac
                WHERE ac.area_ID = ps.area_ID
                AND ac.closed_from <= NOW()
                AND (ac.closed_to IS NULL OR ac.closed_to >= NOW())
            ) THEN 'Occupied'
            WHEN EXISTS (
                SELECT 1 FROM booking b
                WHERE b.space_id = ps.space_id
                AND b.date = CURDATE()
                AND b.status IN ('Active', 'Pending')
            ) THEN 'Occupied'
            ELSE ps.status
        END as actual_status,
        (SELECT COUNT(*) FROM booking b 
         WHERE b.space_id = ps.space_id 
         AND b.status IN ('Active', 'Pending')) as active_bookings
    FROM parking_space ps
    WHERE ps.area_ID = ?
";

if ($filterStatus !== 'all') {
    $sqlSpaces .= " HAVING actual_status = ?";
}
$sqlSpaces .= " ORDER BY CAST(REGEXP_REPLACE(ps.space_num, '[^0-9]', '') AS UNSIGNED), ps.space_num ASC";

$stmtSpaces = mysqli_prepare($conn, $sqlSpaces);
if ($filterStatus !== 'all') {
    mysqli_stmt_bind_param($stmtSpaces, "is", $area_id, $filterStatus);
} else {
    mysqli_stmt_bind_param($stmtSpaces, "i", $area_id);
}
mysqli_stmt_execute($stmtSpaces);
$resultSpaces = mysqli_stmt_get_result($stmtSpaces);
while ($row = mysqli_fetch_assoc($resultSpaces)) {
    $spaces[] = $row;
}

// Summary stats
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parking Spaces - <?php echo htmlspecialchars($areaInfo['area_name']); ?> - FKPark</title>

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
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sync-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #10b981;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
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

        .spaces-section {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .section-meta {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            gap: 12px;
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

        .toolbar-right {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: #0066ff;
            color: #ffffff;
        }

        .btn-primary:hover {
            background-color: #163a6f;
        }

        .btn-success {
            background-color: #10b981;
            color: #ffffff;
        }

        .btn-success:hover {
            background-color: #059669;
        }

        .space-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 450px;
            overflow-y: auto;
            margin-bottom: 20px;
        }

        .space-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background-color: #ffffff;
            transition: all 0.3s ease;
        }

        .space-item:hover {
            background-color: #f9fafb;
        }

        .space-item.updating {
            animation: highlight 0.5s ease;
        }

        @keyframes highlight {
            0%, 100% { background-color: #ffffff; }
            50% { background-color: #fef3c7; }
        }

        .space-name {
            font-weight: 600;
            min-width: 100px;
            color: #111827;
        }

        .space-status {
            flex: 1;
            font-size: 14px;
            color: #6b7280;
        }

        .space-status.available {
            color: #059669;
            font-weight: 500;
        }

        .space-status.occupied {
            color: #dc2626;
            font-weight: 500;
        }

        .space-booking-count {
            font-size: 12px;
            color: #6b7280;
            padding: 4px 8px;
            background-color: #f3f4f6;
            border-radius: 6px;
        }

        .space-actions {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .action-btn.qr {
            background-color: #ffffff;
            color: #0066ff;
            border: 1px solid #e5e7eb;
        }

        .action-btn.qr:hover {
            background-color: #f9fafb;
        }

        .action-btn.edit {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .action-btn.edit:hover {
            background-color: #bfdbfe;
        }

        .action-btn.delete {
            background-color: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .action-btn.delete:hover {
            background-color: #fecaca;
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

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        .form-input:focus {
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

        .space-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #0066ff;
        }

        .space-checkbox:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }

        .bulk-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background-color: #f9fafb;
            border-radius: 8px;
            margin-top: 16px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
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
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
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

    <div class="main">
        <header class="topbar">
            <div class="search-box">
    <i class="bi bi-search"></i>
    <input type="text" placeholder="Search..." id="searchInput">
</div>
            <a href="logout.php" class="sign-out-link">Log out</a>
        </header>

        <main class="content">
            <div class="breadcrumb">
                Admin Dashboard » Manage Parking » <?php echo htmlspecialchars($areaInfo['area_name']); ?>
            </div>

            <div class="page-header">
                <h1 class="page-title">Manage Parking Workspace</h1>
                <div class="sync-info">
                    <span class="sync-indicator"></span>
                    <span id="lastSync">Last sync: <?php echo date('n/j/Y, g:i:s A'); ?></span>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert <?php echo $flashType; ?>"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>

            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Total Areas</div>
                    <div class="stat-value" id="totalAreas"><?php echo $totalAreas; ?></div>
                    
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Spaces</div>
                    <div class="stat-value" id="totalSpaces"><?php echo $totalSpaces; ?></div>
                    <div class="stat-sublabel">Total capacity</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Closed Areas</div>
                    <div class="stat-value" id="closedAreas"><?php echo $closedAreas; ?></div>
                    <div class="stat-sublabel">Under maintenance</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Available Spaces (Now)</div>
                    <div class="stat-value" id="availableSpaces"><?php echo $availableSpaces; ?></div>
                    <div class="stat-sublabel">Currently free</div>
                </div>
            </div>

            <div class="spaces-section">
                <div class="section-header">
                    <div>
                        <div class="section-title">
                            Parking Spaces — <?php echo htmlspecialchars($areaInfo['area_name']); ?>
                        </div>
                        <div class="section-meta">
                            Capacity: <?php echo $totalCapacity; ?> | Status: <?php echo $areaStatus; ?>
                        </div>
                    </div>
                </div>

                <div class="toolbar">
                    <select class="filter-select" name="filter" id="filterSelect" onchange="window.location.href='parking_space.php?area_id=<?php echo $area_id; ?>&filter='+this.value">
                        <option value="all" <?php echo ($filterStatus === 'all') ? 'selected' : ''; ?>>All statuses</option>
                        <option value="Available" <?php echo ($filterStatus === 'Available') ? 'selected' : ''; ?>>Available</option>
                        <option value="Occupied" <?php echo ($filterStatus === 'Occupied') ? 'selected' : ''; ?>>Occupied</option>
                    </select>
                    <div class="toolbar-right">
                        <button type="button" class="btn btn-success" onclick="openAddModal()">+ Add Space</button>
                        <button type="button" class="btn btn-primary" onclick="manualRefresh()"> Refresh</button>
                    </div>
                </div>

                <form method="post" id="bulkDeleteForm">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="bulk_delete" value="1">
                    <input type="hidden" name="selected_spaces" id="selectedSpacesInput">
                    
                    <div class="space-list" id="spaceList">
                        <?php if (empty($spaces)): ?>
                            <div class="empty-state">
                                No parking spaces found in this area.
                            </div>
                        <?php else: ?>
                            <?php foreach ($spaces as $space): ?>
                                <div class="space-item" data-space-id="<?php echo $space['space_id']; ?>" data-has-bookings="<?php echo $space['active_bookings']; ?>">
                                    <input type="checkbox" class="space-checkbox" value="<?php echo $space['space_id']; ?>" 
                                           data-has-bookings="<?php echo $space['active_bookings']; ?>"
                                           onchange="updateBulkButtons()"
                                           <?php echo ($space['active_bookings'] > 0) ? 'disabled title="Cannot select space with active bookings"' : ''; ?>>
                                    <div class="space-name"><?php echo htmlspecialchars($space['space_num']); ?></div>
                                    <div class="space-status <?php echo strtolower($space['actual_status']); ?>"><?php echo strtolower(htmlspecialchars($space['actual_status'])); ?></div>
                                    <?php if ($space['active_bookings'] > 0): ?>
                                        <div class="space-booking-count"><?php echo $space['active_bookings']; ?> booking(s)</div>
                                    <?php endif; ?>
                                    <div class="space-actions">
                                        <a href="view_space_qr.php?space_id=<?php echo $space['space_id']; ?>" class="action-btn qr" target="_blank">QR</a>
                                        <button type="button" class="action-btn edit" onclick='openEditModal(<?php echo json_encode($space); ?>)'>Edit</button>
                                        <?php if ($space['active_bookings'] == 0): ?>
                                            <a href="parking_space.php?area_id=<?php echo $area_id; ?>&delete_space=<?php echo $space['space_id']; ?>" 
                                               class="action-btn delete" 
                                               onclick="return confirm('Delete parking space <?php echo htmlspecialchars($space['space_num']); ?>? This cannot be undone.')">Delete</a>
                                        <?php else: ?>
                                            <button type="button" class="action-btn delete" disabled title="Cannot delete space with active bookings">Delete</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="bulk-actions" id="bulkActions">
                        <button type="button" class="btn btn-primary" onclick="selectAllSpaces()">Select All</button>
                        <button type="button" class="btn" onclick="deselectAllSpaces()" style="background-color: #6b7280; color: #ffffff;">Deselect All</button>
                        <button type="button" class="btn" onclick="confirmBulkDelete()" id="bulkDeleteBtn" style="background-color: #dc2626; color: #ffffff;" disabled>Delete Selected</button>
                        <span id="selectedCount" style="margin-left: 16px; color: #6b7280; font-size: 14px;">0 selected</span>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Add Parking Space</h2>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <div class="form-field">
                <label class="form-label">Space Number *</label>
                <input type="text" name="space_num" class="form-input" placeholder="e.g., <?php echo htmlspecialchars($areaInfo['area_name']); ?>-01" required>
            </div>
            <p style="color: #6b7280; font-size: 13px; margin: 10px 0 0;">
                New spaces are created with 'Available' status and a unique QR code automatically.
            </p>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeAddModal()">Cancel</button>
                <button type="submit" name="add_space" class="btn-modal-submit">Create Space</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Edit Parking Space</h2>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="space_id" id="edit_space_id">
            <div class="form-field">
                <label class="form-label">Space Number *</label>
                <input type="text" name="space_num" id="edit_space_num" class="form-input" required>
            </div>
            <p style="color: #6b7280; font-size: 13px; margin: 10px 0 0;">
                Note: Status and QR code cannot be edited. To change status, the system automatically updates based on bookings.
            </p>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" name="edit_space" class="btn-modal-submit">Update Space</button>
            </div>
        </form>
    </div>
</div>

<script>
const AREA_ID = <?php echo $area_id; ?>;
const FILTER_STATUS = '<?php echo $filterStatus; ?>';
let isUpdating = false;

// ===== MODAL FUNCTIONS =====
function openAddModal() {
    document.getElementById('addModal').classList.add('active');
}

function closeAddModal() {
    document.getElementById('addModal').classList.remove('active');
}

function openEditModal(space) {
    document.getElementById('edit_space_id').value = space.space_id;
    document.getElementById('edit_space_num').value = space.space_num;
    document.getElementById('editModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
    }
}

// ===== AUTO-REFRESH FUNCTIONS =====
setInterval(function() {
    autoRefreshStats();
    autoRefreshSpaces();
}, 3000);

document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        console.log('Page became visible, refreshing...');
        autoRefreshStats();
        autoRefreshSpaces();
    }
});

function autoRefreshStats() {
    fetch('get_stats_update.php?t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStats(data.stats);
                if (data.server_time) {
                    updateSyncTime(data.server_time);
                }
            }
        })
        .catch(error => console.error('Stats refresh error:', error));
}

function autoRefreshSpaces() {
    if (isUpdating) return;
    
    isUpdating = true;
    
    fetch('get_spaces_update.php?area_id=' + AREA_ID + '&filter=' + FILTER_STATUS + '&t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateSpacesList(data.spaces);
            }
            isUpdating = false;
        })
        .catch(error => {
            console.error('Spaces refresh error:', error);
            isUpdating = false;
        });
}

function updateStats(stats) {
    const elements = {
        totalAreas: document.getElementById('totalAreas'),
        totalSpaces: document.getElementById('totalSpaces'),
        closedAreas: document.getElementById('closedAreas'),
        availableSpaces: document.getElementById('availableSpaces')
    };
    
    Object.keys(elements).forEach(key => {
        if (elements[key] && stats[key] !== undefined) {
            const oldValue = elements[key].textContent;
            const newValue = stats[key].toString();
            
            if (oldValue !== newValue) {
                console.log(`${key} changed: ${oldValue} → ${newValue}`);
                elements[key].textContent = newValue;
                
                elements[key].style.transition = 'all 0.3s ease';
                elements[key].style.backgroundColor = '#fef3c7';
                setTimeout(() => {
                    elements[key].style.backgroundColor = '';
                }, 500);
            }
        }
    });
}

function updateSpacesList(spaces) {
    let changesDetected = 0;
    
    spaces.forEach(space => {
        const spaceItem = document.querySelector('[data-space-id="' + space.space_id + '"]');
        
        if (spaceItem) {
            const statusDiv = spaceItem.querySelector('.space-status');
            const oldStatus = statusDiv.textContent.trim().toLowerCase();
            const newStatus = space.status.toLowerCase();
            
            if (oldStatus !== newStatus) {
                changesDetected++;
                console.log(`🔄 Space ${space.space_num}: ${oldStatus} → ${newStatus}`);
                
                spaceItem.classList.add('updating');
                setTimeout(() => spaceItem.classList.remove('updating'), 500);
                
                statusDiv.textContent = newStatus;
                statusDiv.className = 'space-status ' + newStatus;
            }
        }
    });
    
    if (changesDetected > 0) {
        console.log(`✓ ${changesDetected} space(s) updated`);
    }
}

function updateSyncTime(serverTime) {
    const syncElement = document.getElementById('lastSync');
    if (syncElement) {
        syncElement.textContent = 'Last sync: ' + serverTime;
    }
}

function manualRefresh() {
    console.log('Manual refresh triggered');
    autoRefreshStats();
    autoRefreshSpaces();
}

// ===== SEARCH FUNCTIONALITY =====
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const spaceItems = document.querySelectorAll('.space-item');
        
        spaceItems.forEach(item => {
            const text = item.textContent.toLowerCase();
            item.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });
}

// ===== BULK SELECTION FUNCTIONS =====
function updateBulkButtons() {
    const checkboxes = document.querySelectorAll('.space-checkbox:not(:disabled)');
    const checkedBoxes = document.querySelectorAll('.space-checkbox:checked');
    const deleteBtn = document.getElementById('bulkDeleteBtn');
    const downloadBtn = document.getElementById('downloadQRBtn');
    const countDisplay = document.getElementById('selectedCount');
    
    const checkedCount = checkedBoxes.length;
    countDisplay.textContent = `${checkedCount} selected`;
    
    // Enable/disable delete button
    if (deleteBtn) {
        deleteBtn.disabled = checkedCount === 0;
    }
    
    // Enable/disable download button
    if (downloadBtn) {
        downloadBtn.disabled = checkedCount === 0;
    }
}

function selectAllSpaces() {
    const checkboxes = document.querySelectorAll('.space-checkbox:not(:disabled)');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    updateBulkButtons();
}

function deselectAllSpaces() {
    const checkboxes = document.querySelectorAll('.space-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    updateBulkButtons();
}

function confirmBulkDelete() {
    const checkedBoxes = document.querySelectorAll('.space-checkbox:checked');
    const count = checkedBoxes.length;
    
    if (count === 0) {
        alert('Please select at least one parking space to delete.');
        return;
    }
    
    if (!confirm(`Are you sure you want to delete ${count} parking space(s)? This cannot be undone.`)) {
        return;
    }
    
    // Collect selected space IDs
    const spaceIds = Array.from(checkedBoxes).map(cb => cb.value);
    
    // Set hidden input value
    document.getElementById('selectedSpacesInput').value = spaceIds.join(',');
    
    // Submit form
    document.getElementById('bulkDeleteForm').submit();
}

function downloadSelectedQR() {
    const checkedBoxes = document.querySelectorAll('.space-checkbox:checked');
    const count = checkedBoxes.length;
    
    if (count === 0) {
        alert('Please select at least one parking space to download QR codes.');
        return;
    }
    
    // Get selected space IDs
    const spaceIds = Array.from(checkedBoxes).map(cb => cb.value);
    
    // Open download page with selected IDs
    const url = `download_qr_codes.php?area_id=${AREA_ID}&space_ids=${spaceIds.join(',')}`;
    window.open(url, '_blank');
}

console.log('Parking Space Complete Management System Initialized');
console.log('Area ID:', AREA_ID);
console.log('Filter:', FILTER_STATUS);
console.log('Refresh interval: 3 seconds');
console.log('Features: Add, Edit, Delete, Auto-Refresh');

// Run first refresh after 1 second
setTimeout(function() {
    autoRefreshStats();
    autoRefreshSpaces();
}, 1000);
</script>
</body>
</html>