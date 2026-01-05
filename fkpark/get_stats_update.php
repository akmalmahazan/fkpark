<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SET MALAYSIA TIMEZONE
date_default_timezone_set('Asia/Kuala_Lumpur');

session_start();
require 'db.php';

// IMPORTANT: Return JSON and disable any caching
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Only Admin can access
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ===== CALCULATE ALL STATS =====
$stats = [
    'totalAreas' => 0,
    'totalSpaces' => 0,
    'closedAreas' => 0,
    'availableSpaces' => 0
];

// Total areas
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM parking_area");
$stats['totalAreas'] = mysqli_fetch_assoc($res)['total'] ?? 0;

// Total spaces
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM parking_space");
$stats['totalSpaces'] = mysqli_fetch_assoc($res)['total'] ?? 0;

// Closed areas
$res = mysqli_query($conn, "
    SELECT COUNT(DISTINCT area_ID) AS total
    FROM area_closure
    WHERE closed_from <= NOW()
    AND (closed_to IS NULL OR closed_to >= NOW())
");
$stats['closedAreas'] = mysqli_fetch_assoc($res)['total'] ?? 0;

// CRITICAL: Available spaces - count only truly available spaces
// This matches the exact logic from parking_space_list.php
$res = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM parking_space ps
    WHERE ps.status = 'Available'
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
");
$stats['availableSpaces'] = mysqli_fetch_assoc($res)['total'] ?? 0;

// Return the response
echo json_encode([
    'success' => true,
    'stats' => $stats,
    'timestamp' => time(),
    'server_time' => date('n/j/Y, g:i:s A')
]);
?>