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


if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// Handle booking cancellation (Pending bookings)
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $bookingId = (int)$_GET['cancel'];
    $sqlCheck = "SELECT booking_id FROM booking WHERE booking_id = ? AND student_id = ? AND status = 'Pending'";
    $stmtCheck = mysqli_prepare($conn, $sqlCheck);
    mysqli_stmt_bind_param($stmtCheck, "is", $bookingId, $studentUsername);
    mysqli_stmt_execute($stmtCheck);
    $resultCheck = mysqli_stmt_get_result($stmtCheck);
    
    if (mysqli_fetch_assoc($resultCheck)) {
        $sqlCancel = "UPDATE booking SET status = 'Cancelled' WHERE booking_id = ?";
        $stmtCancel = mysqli_prepare($conn, $sqlCancel);
        mysqli_stmt_bind_param($stmtCancel, "i", $bookingId);
        mysqli_stmt_execute($stmtCancel);
        header("Location: my_bookings.php?cancelled=1");
        exit;
    }
}

// Handle STOP PARKING (Active bookings)
if (isset($_GET['stop']) && is_numeric($_GET['stop'])) {
    $bookingId = (int)$_GET['stop'];
    $sqlCheck = "SELECT b.booking_id, b.space_id FROM booking b WHERE b.booking_id = ? AND b.student_id = ? AND b.status = 'Active'";
    $stmtCheck = mysqli_prepare($conn, $sqlCheck);
    mysqli_stmt_bind_param($stmtCheck, "is", $bookingId, $studentUsername);
    mysqli_stmt_execute($stmtCheck);
    $resultCheck = mysqli_stmt_get_result($stmtCheck);
    
    if ($row = mysqli_fetch_assoc($resultCheck)) {
        mysqli_begin_transaction($conn);
        try {
            // Update booking to Completed
            $sqlComplete = "UPDATE booking SET status = 'Completed' WHERE booking_id = ?";
            $stmtComplete = mysqli_prepare($conn, $sqlComplete);
            mysqli_stmt_bind_param($stmtComplete, "i", $bookingId);
            mysqli_stmt_execute($stmtComplete);
            
            // Free up the parking space
            $sqlSpace = "UPDATE parking_space SET status = 'Available' WHERE space_id = ?";
            $stmtSpace = mysqli_prepare($conn, $sqlSpace);
            mysqli_stmt_bind_param($stmtSpace, "i", $row['space_id']);
            mysqli_stmt_execute($stmtSpace);
            
            mysqli_commit($conn);
            header("Location: my_bookings.php?stopped=1");
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            header("Location: my_bookings.php?error=stop_failed");
            exit;
        }
    }
}

// Handle booking deletion (Completed/Cancelled bookings)
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $bookingId = (int)$_GET['delete'];
    $sqlCheck = "SELECT booking_id FROM booking WHERE booking_id = ? AND student_id = ? AND status IN ('Completed', 'Cancelled')";
    $stmtCheck = mysqli_prepare($conn, $sqlCheck);
    mysqli_stmt_bind_param($stmtCheck, "is", $bookingId, $studentUsername);
    mysqli_stmt_execute($stmtCheck);
    $resultCheck = mysqli_stmt_get_result($stmtCheck);
    
    if (mysqli_fetch_assoc($resultCheck)) {
        mysqli_begin_transaction($conn);
        try {
            $sqlDeleteParking = "DELETE FROM parking WHERE booking_id = ?";
            $stmtDeleteParking = mysqli_prepare($conn, $sqlDeleteParking);
            mysqli_stmt_bind_param($stmtDeleteParking, "i", $bookingId);
            mysqli_stmt_execute($stmtDeleteParking);
            
            $sqlDelete = "DELETE FROM booking WHERE booking_id = ?";
            $stmtDelete = mysqli_prepare($conn, $sqlDelete);
            mysqli_stmt_bind_param($stmtDelete, "i", $bookingId);
            mysqli_stmt_execute($stmtDelete);
            
            mysqli_commit($conn);
            header("Location: my_bookings.php?deleted=1");
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            header("Location: my_bookings.php?error=delete_failed");
            exit;
        }
    }
}

// Handle EDIT DURATION (Pending bookings only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_duration'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        header("Location: my_bookings.php?error=invalid_token");
        exit;
    }
    
    $bookingId = (int)$_POST['booking_id'];
    $newDate = $_POST['booking_date'];
    $newTime = $_POST['booking_time'];
    
    $sqlCheck = "SELECT booking_id FROM booking WHERE booking_id = ? AND student_id = ? AND status = 'Pending'";
    $stmtCheck = mysqli_prepare($conn, $sqlCheck);
    mysqli_stmt_bind_param($stmtCheck, "is", $bookingId, $studentUsername);
    mysqli_stmt_execute($stmtCheck);
    $resultCheck = mysqli_stmt_get_result($stmtCheck);
    
    if (mysqli_fetch_assoc($resultCheck)) {
        $bookingDateTime = strtotime("$newDate $newTime");
        if ($bookingDateTime < time()) {
            header("Location: my_bookings.php?error=past_datetime");
            exit;
        }
        
        $sqlUpdate = "UPDATE booking SET date = ?, time = ? WHERE booking_id = ?";
        $stmtUpdate = mysqli_prepare($conn, $sqlUpdate);
        mysqli_stmt_bind_param($stmtUpdate, "ssi", $newDate, $newTime, $bookingId);
        mysqli_stmt_execute($stmtUpdate);
        
        header("Location: my_bookings.php?edited=1");
        exit;
    }
}

// Flash messages
$flash = '';
if (isset($_GET['cancelled'])) $flash = '<div class="alert alert-success">✅ Booking cancelled successfully.</div>';
elseif (isset($_GET['stopped'])) $flash = '<div class="alert alert-success">🛑 Parking stopped! Space is now available.</div>';
elseif (isset($_GET['deleted'])) $flash = '<div class="alert alert-success">🗑️ Booking deleted successfully.</div>';
elseif (isset($_GET['edited'])) $flash = '<div class="alert alert-success">✏️ Booking updated successfully.</div>';
elseif (isset($_GET['error'])) {
    $errorMsg = 'An error occurred.';
    if ($_GET['error'] === 'stop_failed') $errorMsg = 'Failed to stop parking.';
    elseif ($_GET['error'] === 'delete_failed') $errorMsg = 'Failed to delete booking.';
    elseif ($_GET['error'] === 'past_datetime') $errorMsg = 'Cannot set booking to past date/time.';
    elseif ($_GET['error'] === 'invalid_token') $errorMsg = 'Invalid request token.';
    $flash = '<div class="alert alert-danger">' . $errorMsg . '</div>';
}

// Load bookings (Active first, then Pending, then rest)
$sqlBookings = "
    SELECT b.booking_id, b.date, b.time, b.status, b.QrCode,
           ps.space_num, ps.space_id, pa.area_name, pa.area_type
    FROM booking b
    JOIN parking_space ps ON ps.space_id = b.space_id
    JOIN parking_area pa ON pa.Area_ID = ps.area_ID
    WHERE b.student_id = ?
    ORDER BY 
        CASE b.status
            WHEN 'Active' THEN 1
            WHEN 'Pending' THEN 2
            WHEN 'Completed' THEN 3
            WHEN 'Cancelled' THEN 4
        END,
        b.date DESC, b.time DESC
";
$stmtBookings = mysqli_prepare($conn, $sqlBookings);
mysqli_stmt_bind_param($stmtBookings, "s", $studentUsername);
mysqli_stmt_execute($stmtBookings);
$resultBookings = mysqli_stmt_get_result($stmtBookings);
$bookings = [];
while ($row = mysqli_fetch_assoc($resultBookings)) {
    $bookings[] = $row;
}

function esc($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Bookings - FKPark</title>
    <style>
        * { box-sizing: border-box; font-family: system-ui, -apple-system, sans-serif; }
        body { margin: 0; background-color: #f3f4f6; color: #111827; }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 260px 1fr; }
        .sidebar { background-color: #fff; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column; padding: 18px 18px 24px; }
        .sidebar-header { display: flex; align-items: center; gap: 10px; padding-bottom: 20px; border-bottom: 1px solid #e5e7eb; }
        .sidebar-logo { width: 40px; height: 40px; border-radius: 12px; background-color: rgba(255, 255, 255, 1); display: flex; align-items: center; justify-content: center; }
        .sidebar-logo img { max-width: 100%; max-height: 100%; }
        .sidebar-title { font-size: 18px; font-weight: 600; }
        .nav { margin-top: 24px; display: flex; flex-direction: column; gap: 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 999px; color: #374151; text-decoration: none; font-size: 14px; }
        .nav-link:hover { background-color: #eff6ff; }
        .nav-link.active { background-color: #0066ff; color: #fff; }
        .sidebar-footer { margin-top: auto; padding-top: 18px; border-top: 1px solid #e5e7eb; }
        .logout-btn { display: flex; align-items: center; gap: 10px; font-size: 14px; color: #ef4444; text-decoration: none; }
        .main { display: flex; flex-direction: column; }
        .topbar { height: 64px; background-color: #fff; display: flex; align-items: center; justify-content: flex-end; padding: 0 32px; box-shadow: 0 1px 4px rgba(15,23,42,0.06); }
        .topbar-user { display: flex; align-items: center; gap: 10px; font-size: 14px; }
        .topbar-user-info { text-align: right; }
        .topbar-role { font-size: 12px; color: #6b7280; }
        .avatar { width: 34px; height: 34px; border-radius: 999px; background-color: #2563eb; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; }
        .content { padding: 26px 40px 36px; }
        .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .page-title { font-size: 26px; font-weight: 600; margin: 0; }
        .page-subtitle { margin-top: 6px; font-size: 14px; color: #6b7280; }
        .btn-primary { padding: 10px 20px; border-radius: 999px; border: none; background-color: #0066ff; color: #fff; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary:hover { background-color: #0050c7; }
        .alert { border-radius: 10px; padding: 10px 14px; font-size: 14px; margin-bottom: 16px; }
        .alert-success { background-color: #dcfce7; color: #166534; }
        .alert-danger { background-color: #fee2e2; color: #b91c1c; }
        .bookings-grid { display: grid; gap: 20px; }
        .booking-card { background-color: #fff; border-radius: 24px; padding: 24px; box-shadow: 0 12px 35px rgba(15,23,42,0.08); display: grid; grid-template-columns: 1fr auto; gap: 24px; align-items: start; }
        .booking-info { display: flex; flex-direction: column; gap: 12px; }
        .booking-header { display: flex; align-items: center; gap: 12px; }
        .booking-icon { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%); display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .booking-title { font-size: 20px; font-weight: 600; margin: 0; }
        .booking-subtitle { font-size: 14px; color: #6b7280; margin: 2px 0 0; }
        .booking-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-top: 8px; }
        .detail-item { display: flex; flex-direction: column; gap: 4px; }
        .detail-label { font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500; }
        .detail-value { font-size: 14px; color: #111827; font-weight: 600; }
        .booking-qr { display: flex; flex-direction: column; align-items: center; gap: 12px; }
        .qr-code { width: 180px; height: 180px; border: 3px solid #e5e7eb; border-radius: 16px; padding: 12px; background-color: #fff; }
        .qr-code img { width: 100%; height: 100%; }
        .status-badge { padding: 6px 16px; border-radius: 999px; font-size: 13px; font-weight: 600; display: inline-block; }
        .status-badge.Pending { background-color: #fef3c7; color: #92400e; }
        .status-badge.Active { background-color: #dcfce7; color: #166534; }
        .status-badge.Completed { background-color: #e0e7ff; color: #3730a3; }
        .status-badge.Cancelled { background-color: #fee2e2; color: #b91c1c; }
        .booking-actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        .btn-small { padding: 8px 16px; border-radius: 999px; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-block; border: none; transition: all 0.2s; }
        .btn-danger { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .btn-danger:hover { background-color: #fecaca; }
        .btn-stop { background-color: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
        .btn-stop:hover { background-color: #fed7aa; }
        .btn-delete { background-color: #dc2626; color: #fff; }
        .btn-delete:hover { background-color: #b91c1c; }
        .btn-edit { background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
        .btn-edit:hover { background-color: #bfdbfe; }
        .empty-state { text-align: center; padding: 60px 20px; color: #6b7280; }
        .empty-state-icon { font-size: 64px; margin-bottom: 16px; }
        .empty-state h3 { font-size: 20px; margin: 0 0 8px; color: #111827; }
        .empty-state p { font-size: 14px; margin: 0 0 20px; }
        .modal-backdrop { position: fixed; inset: 0; background-color: rgba(15,23,42,0.5); display: none; align-items: center; justify-content: center; z-index: 50; }
        .modal-backdrop.show { display: flex; }
        .modal { background-color: #fff; border-radius: 24px; width: 500px; max-width: 90vw; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
        .modal-title { font-size: 20px; font-weight: 600; }
        .modal-close { font-size: 24px; border: none; background: none; cursor: pointer; color: #6b7280; }
        .modal-close:hover { color: #111827; }
        .modal-body { padding: 24px; overflow-y: auto; }
        .form-field { margin-bottom: 16px; }
        .form-field label { display: block; font-size: 13px; margin-bottom: 6px; color: #374151; font-weight: 500; }
        .form-input { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid #e5e7eb; font-size: 14px; outline: none; }
        .form-input:focus { border-color: #0066ff; box-shadow: 0 0 0 1px rgba(0,102,255,0.18); }
        .modal-footer { padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 12px; }
        .btn-secondary { padding: 10px 20px; border-radius: 999px; border: 1px solid #d1d5db; background-color: #fff; color: #111827; font-size: 14px; cursor: pointer; }
        .btn-secondary:hover { background-color: #f3f4f6; }
        .btn-modal-primary { padding: 10px 24px; border-radius: 999px; border: none; background-color: #0066ff; color: #fff; font-size: 14px; font-weight: 500; cursor: pointer; }
        .btn-modal-primary:hover { background-color: #0050c7; }
        /* Search Functionality */
.search-container {
    margin-bottom: 20px;
    display: flex;
    gap: 12px;
    align-items: center;
}

.search-box {
    flex: 1;
    max-width: 400px;
    position: relative;
}

.search-input {
    width: 100%;
    padding: 10px 40px 10px 14px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    font-size: 14px;
    outline: none;
}

.search-input:focus {
    border-color: #0066ff;
    box-shadow: 0 0 0 1px rgba(0, 102, 255, 0.18);
}

.search-icon {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    pointer-events: none;
}

.clear-search {
    padding: 10px 20px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    background-color: #fff;
    color: #374151;
    font-size: 14px;
    cursor: pointer;
    display: none;
}

.clear-search.show {
    display: block;
}

.clear-search:hover {
    background-color: #f3f4f6;
}

.no-results {
    text-align: center;
    padding: 40px 20px;
    color: #6b7280;
    background-color: #fff;
    border-radius: 24px;
    box-shadow: 0 12px 35px rgba(15, 23, 42, 0.08);
    margin-top: 20px;
}
        @media (max-width: 768px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .booking-card { grid-template-columns: 1fr; }
            .booking-details { grid-template-columns: 1fr; }
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
            <a href="dashboard_stud.php" class="nav-link">☰ Dashboard</a>
            <a href="student_vehicles.php" class="nav-link">🚘 My Vehicles</a>
            <a href="book_parking.php" class="nav-link">📅 Book Parking</a>
            <a href="my_bookings.php" class="nav-link active">📄 My Bookings</a>
            <a href="student_summon.php" class="nav-link">⚠️ Summons & Demerit</a>
            <a href="student_profile.php" class="nav-link">👤 Profile</a>
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
            <div class="content-header">
                <div>
                    <h1 class="page-title">My Bookings</h1>
                    <p class="page-subtitle">View your parking reservations and QR codes</p>
                </div>
                <a href="book_parking.php" class="btn-primary">+ New Booking</a>
            </div>

            <?php echo $flash; ?>

            <?php if (!empty($bookings)): ?>
    <div class="search-container">
        <div class="search-box">
            <input 
                type="text" 
                id="searchInput" 
                class="search-input" 
                placeholder="Search by area, space number, or status..."
                autocomplete="off"
            >
            <span class="search-icon">🔍</span>
        </div>
        <button type="button" id="clearSearch" class="clear-search" onclick="clearSearch()">
            Clear
        </button>
    </div>
<?php endif; ?>

            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📅</div>
                    <h3>No bookings yet</h3>
                    <p>You haven't made any parking reservations yet.</p>
                    <a href="book_parking.php" class="btn-primary">Book Your First Parking</a>
                </div>
            <?php else: ?>
                <div class="bookings-grid">
                    <?php foreach ($bookings as $booking): ?>
                        <div class="booking-card">
                            <div class="booking-info">
                                <div class="booking-header">
                                    <div class="booking-icon">🅿️</div>
                                    <div>
                                        <h2 class="booking-title"><?php echo esc($booking['area_name']); ?></h2>
                                        <p class="booking-subtitle">Space <?php echo esc($booking['space_num']); ?> • <?php echo esc($booking['area_type']); ?></p>
                                    </div>
                                </div>

                                <div class="booking-details">
                                    <div class="detail-item">
                                        <span class="detail-label">Date</span>
                                        <span class="detail-value"><?php echo date('d M Y', strtotime($booking['date'])); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Time</span>
                                        <span class="detail-value"><?php echo date('h:i A', strtotime($booking['time'])); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Status</span>
                                        <span class="status-badge <?php echo esc($booking['status']); ?>">
                                            <?php echo esc($booking['status']); ?>
                                        </span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Booking ID</span>
                                        <span class="detail-value">#<?php echo str_pad($booking['booking_id'], 6, '0', STR_PAD_LEFT); ?></span>
                                    </div>
                                </div>

                                <div class="booking-actions">
                                    <?php if ($booking['status'] === 'Pending'): ?>
                                        <button class="btn-small btn-edit" 
                                                onclick="openEditModal(<?php echo $booking['booking_id']; ?>, '<?php echo $booking['date']; ?>', '<?php echo $booking['time']; ?>')">
                                            ✏️ Edit
                                        </button>
                                        <a href="my_bookings.php?cancel=<?php echo $booking['booking_id']; ?>" 
                                           class="btn-small btn-danger"
                                           onclick="return confirm('Cancel this booking?');">
                                            ❌ Cancel
                                        </a>
                                    <?php elseif ($booking['status'] === 'Active'): ?>
                                        <a href="my_bookings.php?stop=<?php echo $booking['booking_id']; ?>" 
                                           class="btn-small btn-stop"
                                           onclick="return confirm('Stop parking and free up this space?');">
                                            🛑 Stop Parking
                                        </a>
                                    <?php elseif ($booking['status'] === 'Completed' || $booking['status'] === 'Cancelled'): ?>
                                        <a href="my_bookings.php?delete=<?php echo $booking['booking_id']; ?>" 
                                           class="btn-small btn-delete"
                                           onclick="return confirm('Delete this booking? This cannot be undone.');">
                                            🗑️ Delete
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($booking['status'] === 'Pending' || $booking['status'] === 'Active'): ?>
                                <div class="booking-qr">
                                    <div class="qr-code">
                                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php 
                                            echo urlencode('https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/scan_qr.php?booking=' . $booking['booking_id']); 
                                        ?>" alt="QR Code">
                                    </div>
                                    <p style="font-size: 12px; color: #6b7280; text-align: center; margin: 0;">
                                        Scan to <?php echo $booking['status'] === 'Pending' ? 'start' : 'view'; ?> parking
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Edit Duration Modal -->
<div class="modal-backdrop" id="editModal">
    <div class="modal">
        <form method="post" id="editForm">
            <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
            <input type="hidden" name="edit_duration" value="1">
            <input type="hidden" name="booking_id" id="edit_booking_id">
            
            <div class="modal-header">
                <div class="modal-title">✏️ Edit Booking</div>
                <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-field">
                    <label>Booking Date</label>
                    <input type="date" name="booking_date" id="edit_booking_date" class="form-input" required>
                </div>
                <div class="form-field">
                    <label>Expected Arrival Time</label>
                    <input type="time" name="booking_time" id="edit_booking_time" class="form-input" required>
                </div>
                <p style="font-size: 13px; color: #6b7280; margin-top: 12px;">
                    💡 You can change the date and time for this pending booking.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-modal-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(bookingId, date, time) {
    document.getElementById('edit_booking_id').value = bookingId;
    document.getElementById('edit_booking_date').value = date;
    document.getElementById('edit_booking_time').value = time;
    
    // Set minimum date to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('edit_booking_date').min = today;
    
    document.getElementById('editModal').classList.add('show');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
}

// Close modal when clicking outside
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

// Search functionality
const searchInput = document.getElementById('searchInput');
const clearBtn = document.getElementById('clearSearch');
const bookingCards = document.querySelectorAll('.booking-card');

if (searchInput) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        let visibleCount = 0;
        
        bookingCards.forEach(card => {
            const areaName = card.querySelector('.booking-title')?.textContent.toLowerCase() || '';
            const spaceNum = card.querySelector('.booking-subtitle')?.textContent.toLowerCase() || '';
            const status = card.querySelector('.status-badge')?.textContent.toLowerCase() || '';
            const bookingId = card.querySelector('.detail-value')?.textContent.toLowerCase() || '';
            
            const searchContent = areaName + ' ' + spaceNum + ' ' + status + ' ' + bookingId;
            
            if (searchContent.includes(searchTerm)) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        // Show/hide clear button
        if (clearBtn) {
            clearBtn.classList.toggle('show', searchTerm.length > 0);
        }
        
        // Show no results message
        const bookingsGrid = document.querySelector('.bookings-grid');
        let noResultsMsg = document.getElementById('noResultsMsg');
        
        if (visibleCount === 0 && searchTerm.length > 0) {
            if (!noResultsMsg) {
                noResultsMsg = document.createElement('div');
                noResultsMsg.id = 'noResultsMsg';
                noResultsMsg.className = 'no-results';
                noResultsMsg.innerHTML = `
                    <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
                    <h3 style="font-size: 18px; margin: 0 0 8px; color: #111827;">No bookings found</h3>
                    <p style="font-size: 14px; margin: 0;">Try searching with different keywords</p>
                `;
                bookingsGrid.appendChild(noResultsMsg);
            }
        } else if (noResultsMsg) {
            noResultsMsg.remove();
        }
    });
}

function clearSearch() {
    if (searchInput) {
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        searchInput.focus();
    }
}
</script>
</body>
</html>