<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Return JSON response
header('Content-Type: application/json');

// Check if session is valid - only check for what we actually set
$isValid = isset($_SESSION['username']) && isset($_SESSION['role']);

echo json_encode(['valid' => $isValid]);
exit;
?>