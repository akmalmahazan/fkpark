<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Only Student can access
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header("Location: login.php?timeout=1");
    exit;
}

$studentUsername = $_SESSION['username'];
$studentName = $studentUsername;

// Get student name
$sqlStudent = "SELECT name FROM student WHERE student_id = ?";
$stmtStudent = mysqli_prepare($conn, $sqlStudent);
if ($stmtStudent) {
    mysqli_stmt_bind_param($stmtStudent, "s", $studentUsername);
    mysqli_stmt_execute($stmtStudent);
    $result = mysqli_stmt_get_result($stmtStudent);
    if ($row = mysqli_fetch_assoc($result)) {
        $studentName = $row['name'];
    }
}

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$flash = ['ok' => null, 'err' => null];

// Check if student has approved vehicle
$hasApprovedVehicle = false;
$approvedVehicle = null;

$sqlVehicle = "
    SELECT v.vehicle_id, v.plate_num, v.type, v.brand
    FROM vehicle v
    LEFT JOIN vehicle_approval va ON va.vehicle_id = v.vehicle_id
        AND va.approval_id = (
            SELECT MAX(approval_id) 
            FROM vehicle_approval 
            WHERE vehicle_id = v.vehicle_id
        )
    WHERE v.student_id = ? AND va.status = 'Approved'
    LIMIT 1
";
$stmtVehicle = mysqli_prepare($conn, $sqlVehicle);
if ($stmtVehicle) {
    mysqli_stmt_bind_param($stmtVehicle, "s", $studentUsername);
    mysqli_stmt_execute($stmtVehicle);
    $result = mysqli_stmt_get_result($stmtVehicle);
    if ($row = mysqli_fetch_assoc($result)) {
        $hasApprovedVehicle = true;
        $approvedVehicle = $row;
    }
}

// Handle booking creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_booking'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash['err'] = "Invalid request token.";
    } elseif (!$hasApprovedVehicle) {
        $flash['err'] = "You must have an approved vehicle to book parking.";
    } else {
        $spaceId = (int)($_POST['space_id'] ?? 0);
        $bookingDate = $_POST['booking_date'] ?? '';
        $bookingTime = $_POST['booking_time'] ?? '';

        if ($spaceId <= 0 || $bookingDate === '' || $bookingTime === '') {
            $flash['err'] = "Please select a parking space, date and time.";
        } else {
            // Validate date is not in the past
            $bookingDateTime = strtotime("$bookingDate $bookingTime");
            if ($bookingDateTime < time()) {
                $flash['err'] = "Booking date/time cannot be in the past.";
            } else {
                // Check if space is available
                $sqlCheck = "
                    SELECT COUNT(*) as count 
                    FROM booking 
                    WHERE space_id = ? 
                    AND date = ? 
                    AND status IN ('Active', 'Pending')
                ";
                $stmtCheck = mysqli_prepare($conn, $sqlCheck);
                mysqli_stmt_bind_param($stmtCheck, "is", $spaceId, $bookingDate);
                mysqli_stmt_execute($stmtCheck);
                $resultCheck = mysqli_stmt_get_result($stmtCheck);
                $rowCheck = mysqli_fetch_assoc($resultCheck);
                
                if ($rowCheck['count'] > 0) {
                    $flash['err'] = "This parking space is already booked for the selected date.";
                } else {
                    // Create booking
                    mysqli_begin_transaction($conn);
                    try {
                        $sqlInsert = "
                            INSERT INTO booking (date, time, status, student_id, space_id)
                            VALUES (?, ?, 'Pending', ?, ?)
                        ";
                        $stmtInsert = mysqli_prepare($conn, $sqlInsert);
                        mysqli_stmt_bind_param($stmtInsert, "sssi", $bookingDate, $bookingTime, $studentUsername, $spaceId);
                        mysqli_stmt_execute($stmtInsert);
                        
                        $bookingId = mysqli_insert_id($conn);
                        
                        // Generate QR code data
                        $qrData = base64_encode("FKPARK_BOOKING_$bookingId");
                        
                        // Update booking with QR code data
                        $sqlUpdate = "UPDATE booking SET QrCode = ? WHERE booking_id = ?";
                        $stmtUpdate = mysqli_prepare($conn, $sqlUpdate);
                        mysqli_stmt_bind_param($stmtUpdate, "si", $qrData, $bookingId);
                        mysqli_stmt_execute($stmtUpdate);
                        
                        mysqli_commit($conn);
                        $flash['ok'] = "Booking created successfully! View your QR code in My Bookings.";
                        
                        // Redirect to bookings page after 2 seconds
                        header("refresh:2;url=my_bookings.php");
                        
                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $flash['err'] = "Failed to create booking: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Load ALL parking areas (including staff areas) with accurate counts
$parkingAreas = [];
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
                AND b.status IN ('Active', 'Pending')
            )
            AND NOT EXISTS (
                SELECT 1 FROM area_closure ac
                WHERE ac.area_ID = ps.area_ID
                AND ac.closed_from <= NOW()
                AND (ac.closed_to IS NULL OR ac.closed_to >= NOW())
            )
            THEN 1 
            ELSE 0 
        END) as available_spaces
    FROM parking_area pa
    LEFT JOIN parking_space ps ON ps.area_ID = pa.Area_ID
    WHERE NOT EXISTS (
        SELECT 1 FROM area_closure ac
        WHERE ac.area_ID = pa.Area_ID
        AND ac.closed_from <= NOW()
        AND (ac.closed_to IS NULL OR ac.closed_to >= NOW())
    )
    GROUP BY pa.Area_ID
    HAVING total_spaces > 0
    ORDER BY pa.area_name
";
$resultAreas = mysqli_query($conn, $sqlAreas);
if ($resultAreas) {
    while ($row = mysqli_fetch_assoc($resultAreas)) {
        $parkingAreas[] = $row;
    }
}

function esc($v) {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book Parking - FKPark</title>


     <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
          crossorigin="anonymous">

    <!-- Bootstrap Icons (IMPORTANT if you want icons instead of emojis) -->
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

        .nav-link:hover {
            background-color: #eff6ff;
        }

        .nav-link.active {
            background-color: #0066ff;
            color: #fff;
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

        .main {
            display: flex;
            flex-direction: column;
        }

        .topbar {
            height: 64px;
            background-color: #fff;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 32px;
            box-shadow: 0 1px 4px rgba(15,23,42,0.06);
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
            background-color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
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

        .current-time {
            margin-top: 4px;
            font-size: 12px;
            color: #9ca3af;
        }

        .alert {
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            margin-top: 20px;
            margin-bottom: 16px;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
        }

        .card {
            margin-top: 20px;
            background-color: #ffffff;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.08);
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .vehicle-info {
            background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .vehicle-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background-color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .vehicle-details h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .vehicle-details p {
            margin: 4px 0 0;
            font-size: 14px;
            color: #4b5563;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-field {
            margin-bottom: 16px;
        }

        .form-field label {
            display: block;
            font-size: 13px;
            margin-bottom: 4px;
            color: #374151;
            font-weight: 500;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            font-size: 14px;
            outline: none;
            background-color: #ffffff;
        }

        .form-input:focus, .form-select:focus {
            border-color: #0066ff;
            box-shadow: 0 0 0 1px rgba(0, 102, 255, 0.18);
        }

        /* ✅ SEARCH BAR STYLES (NEW) */
        .search-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 240px;
        }

        .search-input {
            width: 100%;
            padding: 12px 40px 12px 14px;
            border-radius: 12px;
            border: 1px solid #d1d5db;
            font-size: 14px;
            outline: none;
        }

        .search-input:focus {
            border-color: #0066ff;
            box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.15);
        }

        .search-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            opacity: 0.7;
        }

        .clear-search {
            padding: 10px 14px;
            border: none;
            border-radius: 12px;
            background: #f3f4f6;
            cursor: pointer;
            font-size: 14px;
        }

        .clear-search:hover {
            background: #e5e7eb;
        }

        .no-results {
            text-align: center;
            padding: 20px;
            color: #6b7280;
            font-size: 14px;
        }

        .parking-areas {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .area-card {
            border: 2px solid #e5e7eb;
            border-radius: 16px;
            padding: 18px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .area-card:hover {
            border-color: #93c5fd;
            background-color: #f0f9ff;
        }

        .area-card.selected {
            border-color: #0066ff;
            background-color: #eff6ff;
        }

        .area-card.staff-only {
            opacity: 0.6;
            background-color: #fef3c7;
            border-color: #fde047;
        }

        .area-card.staff-only:hover {
            background-color: #fef3c7;
            border-color: #fde047;
        }

        .staff-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background-color: #f59e0b;
            color: white;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }

        .area-card h3 {
            margin: 0 0 8px;
            font-size: 16px;
            font-weight: 600;
        }

        .area-card p {
            margin: 4px 0;
            font-size: 13px;
            color: #6b7280;
        }

        .area-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 8px;
        }

        .area-badge.available {
            background-color: #dcfce7;
            color: #166534;
        }

        .area-badge.limited {
            background-color: #fef3c7;
            color: #92400e;
        }

        .area-badge.full {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .spaces-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .space-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .space-card:hover:not(.occupied) {
            border-color: #93c5fd;
            background-color: #f0f9ff;
        }

        .space-card.selected {
            border-color: #0066ff;
            background-color: #eff6ff;
        }

        .space-card.occupied {
            background-color: #fee2e2;
            border-color: #fca5a5;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .space-number {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .space-status {
            font-size: 12px;
            color: #6b7280;
        }

        .occupied-icon {
            position: absolute;
            top: 8px;
            right: 8px;
            font-size: 16px;
        }

        .btn-primary {
            padding: 12px 28px;
            border-radius: 999px;
            border: none;
            background-color: #0066ff;
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
        }

        .btn-primary:hover {
            background-color: #0050c7;
        }

        .btn-primary:disabled {
            background-color: #9ca3af;
            cursor: not-allowed;
        }

        /* Modal for warnings */
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

        .modal.show {
            display: flex;
        }

        .modal-content {
            background-color: #ffffff;
            border-radius: 20px;
            padding: 32px;
            max-width: 400px;
            width: 90%;
            text-align: center;
        }

        .modal-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }

        .modal-title {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .modal-message {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 24px;
        }

        .modal-btn {
            padding: 12px 32px;
            border-radius: 999px;
            border: none;
            background-color: #0066ff;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .parking-areas {
                grid-template-columns: 1fr;
            }
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
            <a href="dashboard_stud.php" class="nav-link">☰  Dashboard</a>
            <a href="student_vehicles.php" class="nav-link">🚘  My Vehicles</a>
            <a href="book_parking.php" class="nav-link active">📅  Book Parking</a>
            <a href="my_bookings.php" class="nav-link">📄  My Bookings</a>
            <a href="student_summon.php" class="nav-link">⚠️ Summons & Demerit</a>
            <a href="student_profile.php" class="nav-link">👤  Profile</a>
        </nav>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">⟵ Logout</a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar-user">
                <div class="topbar-user-info">
                    <div>Welcome, <?php echo esc($studentName); ?></div>
                    <div class="topbar-role">Student</div>
                </div>
                <div class="avatar"><?php echo strtoupper(substr($studentName, 0, 1)); ?></div>
            </div>
        </header>

        <main class="content">
            <h1 class="page-title">Book Parking Space</h1>
            <p class="page-subtitle">Reserve a parking space for your approved vehicle</p>
            <p class="current-time">Current time: <span id="currentTime"></span></p>

            <?php if ($flash['ok']): ?>
                <div class="alert alert-success"><?php echo esc($flash['ok']); ?></div>
            <?php elseif ($flash['err']): ?>
                <div class="alert alert-danger"><?php echo esc($flash['err']); ?></div>
            <?php endif; ?>

            <?php if (!$hasApprovedVehicle): ?>
                <div class="alert alert-warning">
                    ⚠️ You need to have an approved vehicle before booking parking. 
                    <a href="student_vehicles.php" style="color: #0066ff; text-decoration: underline;">Register a vehicle now</a>
                </div>
            <?php else: ?>
                
                <div class="vehicle-info">
                 <div class="vehicle-icon">
        <i class="bi bi-car-front-fill"></i>
        </div>

                    <div class="vehicle-details">
                        <h3><?php echo esc($approvedVehicle['plate_num']); ?></h3>
                        <p><?php echo esc($approvedVehicle['type']); ?> • <?php echo esc($approvedVehicle['brand']); ?></p>
                    </div>
                </div>

                <form method="post" id="bookingForm">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="create_booking" value="1">
                    <input type="hidden" name="space_id" id="selected_space_id" value="">

                    <div class="card">
                        <div class="card-title">Booking Details</div>
                        
                        <div class="form-row">
                            <div class="form-field">
                                <label>Booking Date</label>
                                <input type="date" name="booking_date" class="form-input" id="bookingDate" required>
                            </div>
                            <div class="form-field">
                                <label>Expected Arrival Time</label>
                                <input type="time" name="booking_time" class="form-input" id="bookingTime" required>
                            </div>
                        </div>
                    </div>

                    <?php if (empty($parkingAreas)): ?>
                        <div class="alert alert-warning">
                            No parking areas available. Please contact the administrator.
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <div class="card-title">Select Parking Area</div>

                            <!-- ✅ SEARCH BAR (NEW) -->
                            <div class="search-container">
                                <div class="search-box">
                                    <input 
                                        type="text" 
                                        id="areaSearchInput" 
                                        class="search-input" 
                                        placeholder="Search parking area (e.g., B1, student, available)..."
                                        autocomplete="off"
                                    >
                                    <span class="search-icon">
    <i class="bi bi-search"></i>
</span>
                                </div>
                                <button type="button" class="clear-search" onclick="clearAreaSearch()">
                                    Clear
                                </button>
                            </div>

                            <div class="parking-areas" id="parkingAreaGrid">
                                <?php foreach ($parkingAreas as $area): ?>
                                    <?php 
                                    $availableSpaces = (int)$area['available_spaces'];
                                    $totalSpaces = (int)$area['total_spaces'];
                                    $badgeClass = $availableSpaces > 5 ? 'available' : ($availableSpaces > 0 ? 'limited' : 'full');
                                    $isStaffArea = strtolower($area['area_type']) === 'staff';
                                    ?>
                                    <div class="area-card <?php echo $isStaffArea ? 'staff-only' : ''; ?>" 
                                         onclick="<?php echo $isStaffArea ? 'showStaffWarning()' : 'selectArea(' . $area['Area_ID'] . ')'; ?>" 
                                         data-area-id="<?php echo $area['Area_ID']; ?>"
                                         data-area-type="<?php echo $area['area_type']; ?>">
                                        <?php if ($isStaffArea): ?>
                                            <span class="staff-badge">🔒 STAFF ONLY</span>
                                        <?php endif; ?>
                                        <h3><?php echo esc($area['area_name']); ?></h3>
                                        <p><?php echo esc($area['area_type']); ?></p>
                                        <p>Total: <?php echo $totalSpaces; ?> spaces</p>
                                        <span class="area-badge <?php echo $badgeClass; ?>">
                                            <?php echo $availableSpaces; ?> available
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- No results message -->
                            <div id="noAreaResults" class="no-results" style="display:none;">
                                🔍 No parking areas found. Try another keyword.
                            </div>

                        </div>

                        <div class="card" id="spacesSection" style="display: none;">
                            <div class="card-title">Select Parking Space</div>
                            <div class="spaces-grid" id="spacesGrid">
                            </div>
                        </div>

                        <div style="margin-top: 24px;">
                            <button type="submit" class="btn-primary" id="submitBtn" disabled>
                                Confirm Booking & Generate QR Code
                            </button>
                        </div>
                    <?php endif; ?>
                </form>

            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Warning Modals -->
<div class="modal" id="staffWarningModal">
    <div class="modal-content">
        <div class="modal-icon">🔒</div>
        <h2 class="modal-title">Staff Area Only</h2>
        <p class="modal-message">This parking area is reserved for staff members only. Please select a student parking area (B1, B2, or B3).</p>
        <button class="modal-btn" onclick="closeModal('staffWarningModal')">Got It</button>
    </div>
</div>

<div class="modal" id="occupiedWarningModal">
    <div class="modal-content">
        <div class="modal-icon">⛔</div>
        <h2 class="modal-title">Space Already Booked</h2>
        <p class="modal-message" id="occupiedMessage">This parking space has already been booked by another student for the selected date. Please choose another available space.</p>
        <button class="modal-btn" onclick="closeModal('occupiedWarningModal')">Choose Another</button>
    </div>
</div>


<script>
// Update current time display
function updateCurrentTime() {
    const now = new Date();
    const formatted = now.toLocaleString('en-US', { 
        month: 'numeric',
        day: 'numeric', 
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true 
    });
    const timeElement = document.getElementById('currentTime');
    if (timeElement) {
        timeElement.textContent = formatted;
    }
}

// Set default date and time
document.addEventListener('DOMContentLoaded', function() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const dateInput = document.getElementById('bookingDate');
    if (dateInput) {
        dateInput.value = `${year}-${month}-${day}`;
        dateInput.min = `${year}-${month}-${day}`;
    }
    
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const timeInput = document.getElementById('bookingTime');
    if (timeInput) {
        timeInput.value = `${hours}:${minutes}`;
    }
    
    updateCurrentTime();
    
    // Add date change listener
    if (dateInput) {
        dateInput.addEventListener('change', function() {
            if (selectedAreaId) {
                loadSpaces(selectedAreaId);
            }
        });
    }

    // ✅ AREA SEARCH LISTENER (NEW)
    const areaSearchInput = document.getElementById('areaSearchInput');
    if (areaSearchInput) {
        areaSearchInput.addEventListener('input', function() {
            filterParkingAreas(this.value);
        });
    }
});

setInterval(updateCurrentTime, 1000);

let selectedAreaId = null;
let selectedSpaceId = null;

function selectArea(areaId) {
    document.querySelectorAll('.area-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    document.querySelector('[data-area-id="' + areaId + '"]').classList.add('selected');
    selectedAreaId = areaId;
    selectedSpaceId = null;
    
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('selected_space_id').value = '';
    
    loadSpaces(areaId);
}

function loadSpaces(areaId) {
    const bookingDate = document.getElementById('bookingDate').value;
    
    if (!bookingDate) {
        alert('Please select a booking date first');
        return;
    }
    
    const spacesSection = document.getElementById('spacesSection');
    const spacesGrid = document.getElementById('spacesGrid');
    
    spacesSection.style.display = 'block';
    spacesGrid.innerHTML = '<p style="text-align: center; padding: 20px; color: #6b7280;">Loading...</p>';
    
    fetch('get_spaces.php?area_id=' + areaId + '&date=' + encodeURIComponent(bookingDate))
        .then(response => response.json())
        .then(data => {
            if (data.success && data.spaces.length > 0) {
                spacesGrid.innerHTML = '';
                data.spaces.forEach(space => {
                    const spaceCard = document.createElement('div');
                    spaceCard.className = 'space-card';
                    spaceCard.setAttribute('data-space-id', space.space_id);
                    spaceCard.setAttribute('data-is-occupied', space.is_occupied ? '1' : '0');
                    spaceCard.setAttribute('data-booked-by', space.booked_by || '');
                    
                    // Add occupied class if needed
                    if (space.is_occupied) {
                        spaceCard.classList.add('occupied');
                        spaceCard.onclick = function() { showOccupiedWarning(space.space_num, space.booked_by); };
                    } else {
                        spaceCard.onclick = function() { selectSpace(space.space_id); };
                    }
                    
                    // Build HTML
                    let html = '';
                    if (space.is_occupied) {
                        html += '<div class="occupied-icon">🚫</div>';
                    }
                    html += '<div class="space-number">' + space.space_num + '</div>';
                    html += '<div class="space-status">' + (space.is_occupied ? 'Occupied' : space.status) + '</div>';
                    
                    spaceCard.innerHTML = html;
                    spacesGrid.appendChild(spaceCard);
                });
            } else {
                spacesGrid.innerHTML = '<p style="color: #6b7280; text-align: center;">No spaces found for this area.</p>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            spacesGrid.innerHTML = '<p style="color: #dc2626; text-align: center;">Failed to load spaces.</p>';
        });
}

function selectSpace(spaceId) {
    // Clear previous selections
    document.querySelectorAll('.space-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    // Select this space
    const selectedCard = document.querySelector('[data-space-id="' + spaceId + '"]');
    
    // Double-check it's not occupied
    if (selectedCard.getAttribute('data-is-occupied') === '1') {
        const spaceNum = selectedCard.querySelector('.space-number').textContent;
        const bookedBy = selectedCard.getAttribute('data-booked-by');
        showOccupiedWarning(spaceNum, bookedBy);
        return;
    }
    
    selectedCard.classList.add('selected');
    selectedSpaceId = spaceId;
    
    document.getElementById('selected_space_id').value = spaceId;
    document.getElementById('submitBtn').disabled = false;
}

function showOccupiedWarning(spaceNum, bookedBy) {
    const messageEl = document.getElementById('occupiedMessage');
    let message = 'This parking space (' + spaceNum + ') has already been booked for the selected date. Please choose another available space.';
    
    messageEl.textContent = message;
    
    document.getElementById('occupiedWarningModal').classList.add('show');
}

function showStaffWarning() {
    document.getElementById('staffWarningModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}

/* ✅ NEW: FILTER PARKING AREAS */
function filterParkingAreas(keyword) {
    keyword = keyword.toLowerCase().trim();

    const cards = document.querySelectorAll("#parkingAreaGrid .area-card");
    const noResults = document.getElementById("noAreaResults");

    let visibleCount = 0;

    cards.forEach(card => {
        const text = card.textContent.toLowerCase();
        if (text.includes(keyword)) {
            card.style.display = "block";
            visibleCount++;
        } else {
            card.style.display = "none";
        }
    });

    if (noResults) {
        noResults.style.display = (visibleCount === 0 && keyword.length > 0) ? "block" : "none";
    }
}

function clearAreaSearch() {
    const input = document.getElementById("areaSearchInput");
    if (input) input.value = "";
    filterParkingAreas("");
}
</script>
</body>
</html>
