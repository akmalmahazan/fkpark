<?php
session_start();
require 'db.php';

// Security-only access
if (!isset($_SESSION['username'], $_SESSION['role']) || $_SESSION['role'] !== 'Security') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: summon_list.php");
    exit;
}

// CSRF check
$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    die("Invalid token.");
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    die("Invalid summon id.");
}

$conn->begin_transaction();
try {
    // 1) Delete child rows first (because of FK constraint)
    $stmt = $conn->prepare("DELETE FROM demerit_point WHERE traffic_summonID = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 2) Delete the summon
    $stmt = $conn->prepare("DELETE FROM traffic_summon WHERE traffic_summonID = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    header("Location: summon_list.php?msg=deleted");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    die("Delete failed: " . $e->getMessage());
}
