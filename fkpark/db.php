<?php
// db.php - Database connection for FKPark

// SET MALAYSIA TIMEZONE GLOBALLY
date_default_timezone_set('Asia/Kuala_Lumpur');

$host   = "localhost";
$user   = "root";
$pass   = "";
$dbname = "fkpark";

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// OPTIONAL: Set MySQL timezone to match PHP
mysqli_query($conn, "SET time_zone = '+08:00'");
?>