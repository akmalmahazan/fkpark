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

// Get booking ID from URL
$bookingId = isset($_GET['booking']) ? (int)$_GET['booking'] : 0;

if ($bookingId <= 0) {
    die("Invalid booking ID");
}

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$flash = ['ok' => null, 'err' => null];

// Load booking details
$sqlBooking = "
    SELECT 
        b.booking_id,
        b.date,
        b.time,
        b.status,
        b.student_id,
        ps.space_num,
        pa.area_name,
        pa.area_type,
        v.plate_num,
        v.type as vehicle_type,
        v.brand,
        s.name as student_name
    FROM booking b
    JOIN parking_space ps ON ps.space_id = b.space_id
    JOIN parking_area pa ON pa.Area_ID = ps.area_ID
    JOIN student s ON s.student_id = b.student_id
    LEFT JOIN vehicle v ON v.student_id = b.student_id
        AND v.vehicle_id = (
            SELECT vehicle_id FROM vehicle 
            WHERE student_id = b.student_id 
            AND status = 'Approved' 
            LIMIT 1
        )
    WHERE b.booking_id = ?
";

$stmtBooking = mysqli_prepare($conn, $sqlBooking);
mysqli_stmt_bind_param($stmtBooking, "i", $bookingId);
mysqli_stmt_execute($stmtBooking);
$resultBooking = mysqli_stmt_get_result($stmtBooking);
$booking = mysqli_fetch_assoc($resultBooking);

if (!$booking) {
    die("Booking not found");
}

// Verify this booking belongs to the logged-in student
if ($booking['student_id'] !== $studentUsername) {
    die("Access denied: This booking doesn't belong to you");
}

// Handle parking start
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_parking'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash['err'] = "Invalid request token.";
    } elseif ($booking['status'] !== 'Pending') {
        $flash['err'] = "This booking is not available for parking.";
    } else {
        $duration = (int)($_POST['duration'] ?? 0);
        
        if ($duration < 1 || $duration > 24) {
            $flash['err'] = "Please enter a valid duration (1-24 hours).";
        } else {
            mysqli_begin_transaction($conn);
            try {
                // Update booking status to Active
                $sqlUpdateBooking = "UPDATE booking SET status = 'Active' WHERE booking_id = ?";
                $stmtUpdateBooking = mysqli_prepare($conn, $sqlUpdateBooking);
                mysqli_stmt_bind_param($stmtUpdateBooking, "i", $bookingId);
                mysqli_stmt_execute($stmtUpdateBooking);
                
                // FIXED: Create parking record with REAL-TIME using MySQL NOW() and CURTIME()
                // This ensures the database captures the exact moment the parking starts
                $sqlParking = "INSERT INTO parking (date, time, booking_id) 
                               SELECT CURDATE(), CURTIME(), ?";
                $stmtParking = mysqli_prepare($conn, $sqlParking);
                mysqli_stmt_bind_param($stmtParking, "i", $bookingId);
                mysqli_stmt_execute($stmtParking);
                
                mysqli_commit($conn);
                
                $flash['ok'] = "Parking started successfully! Duration: $duration hour(s)";
                
                // Reload booking data
                mysqli_stmt_execute($stmtBooking);
                $resultBooking = mysqli_stmt_get_result($stmtBooking);
                $booking = mysqli_fetch_assoc($resultBooking);
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $flash['err'] = "Failed to start parking: " . $e->getMessage();
            }
        }
    }
}

// Get parking details if already active
$parkingDetails = null;
if ($booking['status'] === 'Active' || $booking['status'] === 'Completed') {
    $sqlParking = "SELECT date, time FROM parking WHERE booking_id = ? ORDER BY parking_id DESC LIMIT 1";
    $stmtParking = mysqli_prepare($conn, $sqlParking);
    mysqli_stmt_bind_param($stmtParking, "i", $bookingId);
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
    <title>Parking Scan - FKPark</title>
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

        .status-banner {
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 24px;
            text-align: center;
            font-weight: 600;
        }

        .status-banner.pending {
            background: linear-gradient(135deg, #fef3c7 0%, #fde047 100%);
            color: #92400e;
        }

        .status-banner.active {
            background: linear-gradient(135deg, #dcfce7 0%, #86efac 100%);
            color: #166534;
        }

        .status-banner.completed {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #3730a3;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .info-item {
            background-color: #f9fafb;
            padding: 16px;
            border-radius: 14px;
        }

        .info-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .info-value {
            font-size: 16px;
            color: #111827;
            font-weight: 600;
        }

        .vehicle-card {
            background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .vehicle-icon {
            width: 60px;
            height: 60px;
            background-color: #2563eb;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
        }

        .vehicle-details h3 {
            margin: 0 0 4px;
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }

        .vehicle-details p {
            margin: 0;
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

        .parking-active-info {
            background: linear-gradient(135deg, #dcfce7 0%, #86efac 100%);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
        }

        .parking-active-info .icon {
            font-size: 48px;
            margin-bottom: 12px;
        }

        .parking-active-info h3 {
            margin: 0 0 8px;
            font-size: 22px;
            font-weight: 700;
            color: #166534;
        }

        .parking-active-info p {
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
            .info-grid {
                grid-template-columns: 1fr;
            }
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
            <h1>Parking Booking</h1>
            <p>Booking ID: #<?php echo str_pad($bookingId, 6, '0', STR_PAD_LEFT); ?></p>
        </div>

        <div class="status-banner <?php echo strtolower($booking['status']); ?>">
            <?php 
            if ($booking['status'] === 'Pending') {
                echo '⏳ Ready to Park';
            } elseif ($booking['status'] === 'Active') {
                echo '✅ Parking Active';
            } elseif ($booking['status'] === 'Completed') {
                echo '🏁 Completed';
            } else {
                echo esc($booking['status']);
            }
            ?>
        </div>

        <?php if ($flash['ok']): ?>
            <div class="alert alert-success"><?php echo esc($flash['ok']); ?></div>
        <?php elseif ($flash['err']): ?>
            <div class="alert alert-danger"><?php echo esc($flash['err']); ?></div>
        <?php endif; ?>

        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Parking Area</div>
                <div class="info-value"><?php echo esc($booking['area_name']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Space Number</div>
                <div class="info-value"><?php echo esc($booking['space_num']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Booking Date</div>
                <div class="info-value"><?php echo date('d M Y', strtotime($booking['date'])); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Arrival Time</div>
                <div class="info-value"><?php echo date('h:i A', strtotime($booking['time'])); ?></div>
            </div>
        </div>

        <?php if ($booking['plate_num']): ?>
            <div class="vehicle-card">
                <div class="vehicle-icon">🚗</div>
                <div class="vehicle-details">
                    <h3><?php echo esc($booking['plate_num']); ?></h3>
                    <p><?php echo esc($booking['vehicle_type']); ?> • <?php echo esc($booking['brand']); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($booking['status'] === 'Pending'): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                <div class="form-section">
                    <h3>Start Parking</h3>
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

        <?php elseif ($booking['status'] === 'Active' && $parkingDetails): ?>
            <div class="parking-active-info">
                <div class="icon">✅</div>
                <h3>Parking in Progress</h3>
                <p><strong>Started:</strong> <?php echo date('d M Y, h:i A', strtotime($parkingDetails['date'] . ' ' . $parkingDetails['time'])); ?></p>
                <p style="margin-top: 16px; font-size: 13px;">
                    Please ensure your vehicle is parked within the designated space.
                </p>
            </div>

        <?php elseif ($booking['status'] === 'Completed'): ?>
            <div class="parking-active-info" style="background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);">
                <div class="icon">🏁</div>
                <h3 style="color: #3730a3;">Parking Completed</h3>
                <p style="color: #3730a3;">Thank you for using FKPark!</p>
            </div>

        <?php else: ?>
            <div class="alert alert-danger">
                This booking is <?php echo strtolower($booking['status']); ?> and cannot be used for parking.
            </div>
        <?php endif; ?>
    </div>

    <a href="my_bookings.php" class="back-link">← Back to My Bookings</a>
</div>

<script>
function selectDuration(hours) {
    // Update input
    document.getElementById('durationInput').value = hours;
    
    // Update button states
    document.querySelectorAll('.duration-btn').forEach(btn => {
        btn.classList.remove('selected');
    });
    event.target.classList.add('selected');
}

// Update button selection when typing
document.getElementById('durationInput').addEventListener('input', function() {
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