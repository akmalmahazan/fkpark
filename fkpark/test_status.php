<?php
// TEST SCRIPT: test_status.php
// Place this file in your root directory and access it to check space statuses

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';

echo "<h1>Parking Space Status Checker</h1>";
echo "<p style='color: #666;'>This shows the ACTUAL status in your database</p>";

// Get all spaces with their current status
$sql = "
    SELECT 
        ps.space_id,
        ps.space_num,
        ps.status as space_status,
        pa.area_name,
        b.booking_id,
        b.status as booking_status,
        b.date as booking_date,
        s.name as student_name
    FROM parking_space ps
    JOIN parking_area pa ON pa.Area_ID = ps.area_ID
    LEFT JOIN booking b ON b.space_id = ps.space_id 
        AND b.date = CURDATE() 
        AND b.status IN ('Active', 'Pending')
    LEFT JOIN student s ON s.student_id = b.student_id
    ORDER BY pa.area_name, ps.space_num
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Query failed: " . mysqli_error($conn));
}

echo "<table border='1' cellpadding='10' style='border-collapse: collapse; margin-top: 20px;'>";
echo "<thead style='background: #f0f0f0;'>";
echo "<tr>";
echo "<th>Space</th>";
echo "<th>Area</th>";
echo "<th>DB Status</th>";
echo "<th>Today's Booking</th>";
echo "<th>Student</th>";
echo "<th>Action Needed?</th>";
echo "</tr>";
echo "</thead>";
echo "<tbody>";

while ($row = mysqli_fetch_assoc($result)) {
    $statusColor = $row['space_status'] === 'Available' ? '#28a745' : '#dc3545';
    $hasBooking = !empty($row['booking_id']);
    $bookingInfo = $hasBooking ? $row['booking_status'] . " (ID: {$row['booking_id']})" : "No booking";
    
    // Check for inconsistency
    $actionNeeded = "";
    if ($hasBooking && $row['booking_status'] === 'Active' && $row['space_status'] === 'Available') {
        $actionNeeded = "⚠️ Should be Occupied!";
    }
    
    echo "<tr>";
    echo "<td><strong>{$row['space_num']}</strong></td>";
    echo "<td>{$row['area_name']}</td>";
    echo "<td style='color: {$statusColor}; font-weight: bold;'>{$row['space_status']}</td>";
    echo "<td>{$bookingInfo}</td>";
    echo "<td>" . ($row['student_name'] ?? '-') . "</td>";
    echo "<td style='color: red; font-weight: bold;'>{$actionNeeded}</td>";
    echo "</tr>";
}

echo "</tbody>";
echo "</table>";

echo "<hr>";
echo "<h2>Summary</h2>";

// Count statuses
$sqlSummary = "
    SELECT 
        status, 
        COUNT(*) as count 
    FROM parking_space 
    GROUP BY status
";
$resultSummary = mysqli_query($conn, $sqlSummary);

echo "<ul>";
while ($row = mysqli_fetch_assoc($resultSummary)) {
    echo "<li><strong>{$row['status']}:</strong> {$row['count']} spaces</li>";
}
echo "</ul>";

echo "<hr>";
echo "<p style='color: #666; font-size: 12px;'>Last checked: " . date('Y-m-d H:i:s') . "</p>";
echo "<p><a href='test_status.php'>🔄 Refresh</a></p>";
?>