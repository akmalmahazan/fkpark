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

$area_id = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

if ($area_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid area ID']);
    exit;
}

// Get updated stats
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

// FIXED: Available spaces - count only truly available spaces
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

// ===== GET PARKING SPACES - WITH REAL-TIME STATUS INCLUDING CLOSED AREAS =====
$spaces = [];

// CRITICAL FIX: Check database status, active bookings, AND area closures
$sqlSpaces = "
    SELECT 
        ps.space_id, 
        ps.space_num, 
        ps.status as db_status,
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
        END as actual_status
    FROM parking_space ps
    WHERE ps.area_ID = ?
";

// Add filter if specified
if ($filter !== 'all') {
    // Filter based on ACTUAL status (including closed area check)
    $sqlSpaces .= " HAVING actual_status = ?";
}
$sqlSpaces .= " ORDER BY ps.space_num ASC";

$stmtSpaces = mysqli_prepare($conn, $sqlSpaces);

if (!$stmtSpaces) {
    echo json_encode(['success' => false, 'message' => 'Query preparation failed: ' . mysqli_error($conn)]);
    exit;
}

// Bind parameters
if ($filter !== 'all') {
    mysqli_stmt_bind_param($stmtSpaces, "is", $area_id, $filter);
} else {
    mysqli_stmt_bind_param($stmtSpaces, "i", $area_id);
}

mysqli_stmt_execute($stmtSpaces);
$resultSpaces = mysqli_stmt_get_result($stmtSpaces);

if (!$resultSpaces) {
    echo json_encode(['success' => false, 'message' => 'Query execution failed: ' . mysqli_error($conn)]);
    exit;
}

// Fetch all spaces with their REAL-TIME status (including closed area status)
while ($row = mysqli_fetch_assoc($resultSpaces)) {
    $spaces[] = [
        'space_id' => (int)$row['space_id'],
        'space_num' => $row['space_num'],
        'status' => $row['actual_status']  // Use actual_status which includes closed area check
    ];
}

mysqli_stmt_close($stmtSpaces);

// Return the response
echo json_encode([
    'success' => true,
    'stats' => $stats,
    'spaces' => $spaces,
    'timestamp' => time(),
    'server_time' => date('d/m/Y, H:i:s'),
    'debug' => [
        'area_id' => $area_id,
        'filter' => $filter,
        'total_spaces_returned' => count($spaces)
    ]
]);
?>