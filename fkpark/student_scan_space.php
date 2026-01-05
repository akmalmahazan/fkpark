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

// Get QR code from URL
$qrCode = isset($_GET['qr']) ? trim($_GET['qr']) : '';

if (empty($qrCode)) {
    die("Invalid QR code");
}

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$flash = ['ok' => null, 'err' => null];

// Find the parking space from QR code
$sqlSpace = "
    SELECT ps.space_id, ps.space_num, ps.status,
           pa.area_name, pa.area_type, pa.Area_ID
    FROM parking_space ps
    JOIN parking_area pa ON pa.Area_ID = ps.area_ID
    WHERE ps.QrCode = ?
";

$stmtSpace = mysqli_prepare($conn, $sqlSpace);
mysqli_stmt_bind_param($stmtSpace, "s", $qrCode);
mysqli_stmt_execute($stmtSpace);
$resultSpace = mysqli_stmt_get_result($stmtSpace);
$space = mysqli_fetch_assoc($resultSpace);

if (!$space) {
    die("Invalid or expired QR code");
}

// Check if student has a booking for this space TODAY
$todayDate = date('Y-m-d');
$sqlCheckBooking = "
    SELECT b.booking_id, b.date, b.time, b.status
    FROM booking b
    WHERE b.student_id = ?
    AND b.space_id = ?
    AND b.date = ?
    AND b.status IN ('Pending', 'Active')
    LIMIT 1
";

$stmtCheckBooking = mysqli_prepare($conn, $sqlCheckBooking);
mysqli_stmt_bind_param($stmtCheckBooking, "sis", $studentUsername, $space['space_id'], $todayDate);
mysqli_stmt_execute($stmtCheckBooking);
$resultCheckBooking = mysqli_stmt_get_result($stmtCheckBooking);
$existingBooking = mysqli_fetch_assoc($resultCheckBooking);

// Handle parking start
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_parking'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash['err'] = "Invalid request token.";
    } elseif (!$existingBooking) {
        $flash['err'] = "You don't have a valid booking for this space today.";
    } elseif ($existingBooking['status'] !== 'Pending') {
        $flash['err'] = "This booking is already " . $existingBooking['status'] . ".";
    } else {
        $duration = (int)($_POST['duration'] ?? 0);
        
        if ($duration < 1 || $duration > 24) {
            $flash['err'] = "Please enter a valid duration (1-24 hours).";
        } else {
            mysqli_begin_transaction($conn);
try {
    // 1. Update booking status to Active
    $sqlUpdateBooking = "UPDATE booking SET status = 'Active' WHERE booking_id = ?";
    $stmtUpdateBooking = mysqli_prepare($conn, $sqlUpdateBooking);
    mysqli_stmt_bind_param($stmtUpdateBooking, "i", $existingBooking['booking_id']);
    mysqli_stmt_execute($stmtUpdateBooking);
    
    // 2. Update space status to Occupied
    $sqlUpdateSpace = "UPDATE parking_space SET status = 'Occupied' WHERE space_id = ?";
    $stmtUpdateSpace = mysqli_prepare($conn, $sqlUpdateSpace);
    mysqli_stmt_bind_param($stmtUpdateSpace, "i", $space['space_id']);
    mysqli_stmt_execute($stmtUpdateSpace);
    
    // 3. Create parking record with real-time using MySQL NOW()
    $sqlParking = "INSERT INTO parking (date, time, booking_id) 
                   SELECT CURDATE(), CURTIME(), ?";
    $stmtParking = mysqli_prepare($conn, $sqlParking);
    mysqli_stmt_bind_param($stmtParking, "i", $existingBooking['booking_id']);
    mysqli_stmt_execute($stmtParking);
    
    mysqli_commit($conn);
    
    $flash['ok'] = "Parking started successfully! Duration: $duration hour(s). Enjoy your parking!";
    
    // Reload booking data
    mysqli_stmt_execute($stmtCheckBooking);
    $resultCheckBooking = mysqli_stmt_get_result($stmtCheckBooking);
    $existingBooking = mysqli_fetch_assoc($resultCheckBooking);
    
    // Update space status for display
    $space['status'] = 'Occupied';
    
} catch (Exception $e) {
    mysqli_rollback($conn);
    $flash['err'] = "Failed to start parking: " . $e->getMessage();
}
        }
    }
}

// Get parking details if already active
$parkingDetails = null;
if ($existingBooking && $existingBooking['status'] === 'Active') {
    $sqlParking = "SELECT date, time FROM parking WHERE booking_id = ? ORDER BY parking_id DESC LIMIT 1";
    $stmtParking = mysqli_prepare($conn, $sqlParking);
    mysqli_stmt_bind_param($stmtParking, "i", $existingBooking['booking_id']);
    mysqli_stmt_execute($stmtParking);
    $resultParking = mysqli_stmt_get_result($stmtParking);
    $parkingDetails = mysqli_fetch_assoc($resultParking);
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
    <title>Scan to Park - FKPark</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 600px;
        }

        .card {
            background-color: #ffffff;
            border-radius: 28px;
            padding: 32px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .header {
            text-align: center;
            margin-bottom: 28px;
        }

        .logo {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 36px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px;
            color: #111827;
        }

        .header p {
            font-size: 14px;
            color: #6b7280;
            margin: 0;
        }

        .space-banner {
            background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            text-align: center;
        }

        .space-banner h2 {
            margin: 0 0 8px;
            font-size: 32px;
            font-weight: 700;
            color: #1e40af;
        }

        .space-banner p {
            margin: 4px 0;
            font-size: 14px;
            color: #4b5563;
        }

        .alert {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde047;
        }

        .status-banner {
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 24px;
            text-align: center;
            font-weight: 600;
        }

        .status-banner.no-booking {
            background: linear-gradient(135deg, #fef3c7 0%, #fde047 100%);
            color: #92400e;
        }

        .status-banner.ready {
            background: linear-gradient(135deg, #dcfce7 0%, #86efac 100%);
            color: #166534;
        }

        .status-banner.active {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #3730a3;
        }

        .form-section {
            background-color: #f9fafb;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
        }

        .form-section h3 {
            margin: 0 0 16px;
            font-size: 18px;
            font-weight: 600;
        }

        .duration-options {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }

        .duration-btn {
            padding: 12px;
            border: 2px solid #e5e7eb;
            background-color: #ffffff;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .duration-btn:hover {
            border-color: #667eea;
            background-color: #f0f9ff;
        }

        .duration-btn.selected {
            border-color: #667eea;
            background-color: #667eea;
            color: #ffffff;
        }

        .form-field {
            margin-bottom: 16px;
        }

        .form-field label {
            display: block;
            font-size: 13px;
            margin-bottom: 6px;
            color: #374151;
            font-weight: 500;
        }

        .form-input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            font-size: 16px;
            outline: none;
        }

        .form-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn-primary {
            width: 100%;
            padding: 14px;
            border-radius: 14px;
            border: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }

        .info-card {
            background-color: #f0f9ff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .info-card h4 {
            margin: 0 0 12px;
            font-size: 16px;
            font-weight: 600;
            color: #1e40af;
        }

        .info-card p {
            margin: 8px 0;
            font-size: 14px;
            color: #4b5563;
        }

        .active-parking-info {
            background: linear-gradient(135deg, #dcfce7 0%, #86efac 100%);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
        }

        .active-parking-info .icon {
            font-size: 48px;
            margin-bottom: 12px;
        }

        .active-parking-info h3 {
            margin: 0 0 8px;
            font-size: 22px;
            font-weight: 700;
            color: #166534;
        }

        .active-parking-info p {
            margin: 4px 0;
            font-size: 14px;
            color: #166534;
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #ffffff;
            text-decoration: none;
            font-weight: 500;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 640px) {
            .duration-options {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header">
            <div class="logo">🅿️</div>
            <h1>Parking Space Scan</h1>
            <p>Welcome, <?php echo esc($studentName); ?></p>
        </div>

        <div class="space-banner">
            <h2><?php echo esc($space['space_num']); ?></h2>
            <p><?php echo esc($space['area_name']); ?> • <?php echo esc($space['area_type']); ?></p>
            <p><strong>Status:</strong> <?php echo esc($space['status']); ?></p>
        </div>

        <?php if ($flash['ok']): ?>
            <div class="alert alert-success"><?php echo esc($flash['ok']); ?></div>
        <?php elseif ($flash['err']): ?>
            <div class="alert alert-danger"><?php echo esc($flash['err']); ?></div>
        <?php endif; ?>

        <?php if (!$existingBooking): ?>
            <div class="status-banner no-booking">
                ⚠️ No Booking Found
            </div>
            
            <div class="info-card">
                <h4>You don't have a booking for this space</h4>
                <p><strong>Space:</strong> <?php echo esc($space['space_num']); ?></p>
                <p><strong>Area:</strong> <?php echo esc($space['area_name']); ?></p>
                <p style="margin-top: 16px;">Please book this space first through the FKPark system before scanning.</p>
            </div>

            <a href="book_parking.php" style="display: block; text-align: center; padding: 12px; background-color: #667eea; color: white; text-decoration: none; border-radius: 12px; font-weight: 600;">
                📅 Go to Booking Page
            </a>

        <?php elseif ($existingBooking['status'] === 'Pending'): ?>
            <div class="status-banner ready">
                ✅ Booking Verified - Ready to Park
            </div>

            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                <div class="form-section">
                    <h3>Start Your Parking Session</h3>
                    <p style="color: #6b7280; font-size: 14px; margin: 0 0 16px;">
                        Select your expected parking duration
                    </p>

                    <div class="duration-options">
                        <button type="button" class="duration-btn" onclick="selectDuration(1)">1hr</button>
                        <button type="button" class="duration-btn" onclick="selectDuration(2)">2hrs</button>
                        <button type="button" class="duration-btn" onclick="selectDuration(4)">4hrs</button>
                        <button type="button" class="duration-btn" onclick="selectDuration(8)">8hrs</button>
                    </div>

                    <div class="form-field">
                        <label>Custom Duration (hours)</label>
                        <input type="number" name="duration" id="durationInput" 
                               class="form-input" min="1" max="24" value="2" required>
                    </div>
                </div>

                <button type="submit" name="start_parking" class="btn-primary">
                    🚀 Start Parking Now
                </button>
            </form>

        <?php elseif ($existingBooking['status'] === 'Active' && $parkingDetails): ?>
            <div class="status-banner active">
                🎯 Parking Active
            </div>

            <div class="active-parking-info">
                <div class="icon">✅</div>
                <h3>Parking in Progress</h3>
                <p><strong>Started:</strong> <?php echo date('d M Y, h:i A', strtotime($parkingDetails['date'] . ' ' . $parkingDetails['time'])); ?></p>
                <p style="margin-top: 16px; font-size: 13px;">
                    Enjoy your parking! Remember to move your vehicle before the end time.
                </p>
            </div>

        <?php endif; ?>
    </div>

    <a href="my_bookings.php" class="back-link">← Back to My Bookings</a>
</div>

<script>
function selectDuration(hours) {
    document.getElementById('durationInput').value = hours;
    
    document.querySelectorAll('.duration-btn').forEach(btn => {
        btn.classList.remove('selected');
    });
    event.target.classList.add('selected');
}

document.getElementById('durationInput')?.addEventListener('input', function() {
    const value = parseInt(this.value);
    document.querySelectorAll('.duration-btn').forEach(btn => {
        btn.classList.remove('selected');
    });
    
    const matchingBtn = Array.from(document.querySelectorAll('.duration-btn')).find(btn => {
        return parseInt(btn.textContent) === value;
    });
    
    if (matchingBtn) {
        matchingBtn.classList.add('selected');
    }
});
</script>
</body>
</html>