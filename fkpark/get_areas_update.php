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

// Available spaces - REAL-TIME calculation
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

// ===== GET DETAILED AREA DATA =====
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
                AND b.status IN ('Active', 'Pending')
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
        $areas[] = [
            'Area_ID' => (int)$row['Area_ID'],
            'area_name' => $row['area_name'],
            'area_type' => $row['area_type'],
            'total_spaces' => (int)$row['total_spaces'],
            'available_spaces' => (int)$row['available_spaces'],
            'status' => $row['status']
        ];
    }
}

// Return the response
echo json_encode([
    'success' => true,
    'stats' => $stats,
    'areas' => $areas,
    'timestamp' => time(),
    'server_time' => date('n/j/Y, g:i:s A')
]);
?>