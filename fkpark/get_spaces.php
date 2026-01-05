<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

header('Content-Type: application/json');

// Only authenticated students can access
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$areaId = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;
$bookingDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if ($areaId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid area ID']);
    exit;
}

// UPDATED: Get ALL spaces and mark which ones are occupied
$sqlSpaces = "
    SELECT 
        ps.space_id, 
        ps.space_num, 
        ps.status, 
        ps.QrCode,
        CASE 
            WHEN EXISTS (
                SELECT 1 FROM booking b 
                WHERE b.space_id = ps.space_id 
                AND b.date = ?
                AND b.status IN ('Pending', 'Active')
            ) THEN 'Occupied'
            ELSE ps.status
        END as display_status,
        (SELECT CONCAT(s.name, ' (', b.student_id, ')') 
         FROM booking b
         JOIN student s ON s.student_id = b.student_id
         WHERE b.space_id = ps.space_id 
         AND b.date = ?
         AND b.status IN ('Pending', 'Active')
         LIMIT 1
        ) as booked_by
    FROM parking_space ps
    WHERE ps.area_ID = ?
    AND NOT EXISTS (
        SELECT 1 FROM area_closure ac
        WHERE ac.area_ID = ps.area_ID
        AND ac.closed_from <= NOW()
        AND (ac.closed_to IS NULL OR ac.closed_to >= NOW())
    )
    ORDER BY CAST(REGEXP_REPLACE(ps.space_num, '[^0-9]', '') AS UNSIGNED), ps.space_num
";

$stmtSpaces = mysqli_prepare($conn, $sqlSpaces);
mysqli_stmt_bind_param($stmtSpaces, "ssi", $bookingDate, $bookingDate, $areaId);
mysqli_stmt_execute($stmtSpaces);
$resultSpaces = mysqli_stmt_get_result($stmtSpaces);

$spaces = [];
while ($row = mysqli_fetch_assoc($resultSpaces)) {
    $spaces[] = [
        'space_id' => (int)$row['space_id'],
        'space_num' => $row['space_num'],
        'status' => $row['display_status'],
        'is_occupied' => ($row['display_status'] === 'Occupied'),
        'booked_by' => $row['booked_by']
    ];
}

echo json_encode([
    'success' => true,
    'spaces' => $spaces,
    'date_checked' => $bookingDate
]);
?>