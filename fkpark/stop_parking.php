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

// Get booking_id from POST or GET
$bookingId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
} else {
    $bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
}

if ($bookingId <= 0) {
    header("Location: my_bookings.php?error=invalid_booking");
    exit;
}

// Verify booking belongs to this student and is Active
$sqlCheckBooking = "
    SELECT b.booking_id, b.space_id, b.student_id, b.status
    FROM booking b
    WHERE b.booking_id = ?
    AND b.student_id = ?
";

$stmtCheck = mysqli_prepare($conn, $sqlCheckBooking);
mysqli_stmt_bind_param($stmtCheck, "is", $bookingId, $studentUsername);
mysqli_stmt_execute($stmtCheck);
$resultCheck = mysqli_stmt_get_result($stmtCheck);
$booking = mysqli_fetch_assoc($resultCheck);

if (!$booking) {
    header("Location: my_bookings.php?error=booking_not_found");
    exit;
}

if ($booking['status'] !== 'Active') {
    header("Location: my_bookings.php?error=booking_not_active");
    exit;
}

// CRITICAL FIX: Stop parking must update BOTH booking status AND parking_space status
mysqli_begin_transaction($conn);

try {
    // 1. Update booking status to Completed
    $sqlUpdateBooking = "UPDATE booking SET status = 'Completed' WHERE booking_id = ?";
    $stmtUpdateBooking = mysqli_prepare($conn, $sqlUpdateBooking);
    mysqli_stmt_bind_param($stmtUpdateBooking, "i", $bookingId);
    
    if (!mysqli_stmt_execute($stmtUpdateBooking)) {
        throw new Exception("Failed to update booking status");
    }
    
    // 2. CRITICAL: Update parking_space status back to Available
    $sqlUpdateSpace = "UPDATE parking_space SET status = 'Available' WHERE space_id = ?";
    $stmtUpdateSpace = mysqli_prepare($conn, $sqlUpdateSpace);
    mysqli_stmt_bind_param($stmtUpdateSpace, "i", $booking['space_id']);
    
    if (!mysqli_stmt_execute($stmtUpdateSpace)) {
        throw new Exception("Failed to update space status");
    }
    
    // Commit the transaction
    mysqli_commit($conn);
    
    // Redirect with success message
    header("Location: my_bookings.php?stopped=1");
    exit;
    
} catch (Exception $e) {
    // Rollback on error
    mysqli_rollback($conn);
    
    error_log("Stop Parking Error: " . $e->getMessage());
    header("Location: my_bookings.php?error=stop_failed");
    exit;
}
?>