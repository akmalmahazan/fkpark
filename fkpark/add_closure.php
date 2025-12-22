<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Security: Admin only
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?timeout=1");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $area_id = isset($_POST['area_id']) ? (int)$_POST['area_id'] : 0;
    $closure_reason = isset($_POST['closure_reason']) ? trim($_POST['closure_reason']) : '';
    $closed_from = isset($_POST['closed_from']) ? $_POST['closed_from'] : '';
    $closed_to = isset($_POST['closed_to']) && !empty($_POST['closed_to']) ? $_POST['closed_to'] : null;
    
    // Validation
    if ($area_id <= 0) {
        header("Location: area_closure.php?error=invalid_area");
        exit;
    }
    
    if (empty($closure_reason)) {
        header("Location: area_closure.php?error=missing_reason");
        exit;
    }
    
    if (empty($closed_from)) {
        header("Location: area_closure.php?error=missing_date");
        exit;
    }
    
    // Convert datetime-local format to MySQL datetime format
    $closed_from_formatted = date('Y-m-d H:i:s', strtotime($closed_from));
    $closed_to_formatted = $closed_to ? date('Y-m-d H:i:s', strtotime($closed_to)) : null;
    
    // Insert into database
    $sql = "INSERT INTO area_closure (area_ID, closure_reason, closed_from, closed_to, admin_id) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    // Get admin_id from session (assuming you have it stored)
    $admin_id = $_SESSION['username']; // Adjust based on your session structure
    
    if ($closed_to_formatted) {
        mysqli_stmt_bind_param($stmt, "issss", $area_id, $closure_reason, $closed_from_formatted, $closed_to_formatted, $admin_id);
    } else {
        // If closed_to is null, we need to handle it differently
        $sql = "INSERT INTO area_closure (area_ID, closure_reason, closed_from, admin_id) 
                VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "isss", $area_id, $closure_reason, $closed_from_formatted, $admin_id);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        header("Location: area_closure.php?added=1");
        exit;
    } else {
        header("Location: area_closure.php?error=database_error");
        exit;
    }
} else {
    // If not POST request, redirect back
    header("Location: area_closure.php");
    exit;
}
?>