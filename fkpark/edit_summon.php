<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ---------- Security-only ----------
if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Security') {
    header('Location: login.php');
    exit;
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$securityUsername = $_SESSION['username'];

// Get security staff name
$securityName = $securityUsername;
$stmtSec = $conn->prepare("SELECT name FROM security WHERE staff_id = ? LIMIT 1");
$stmtSec->bind_param("s", $securityUsername);
$stmtSec->execute();
$resSec = $stmtSec->get_result()->fetch_assoc();
$stmtSec->close();
if ($resSec && !empty($resSec['name'])) {
    $securityName = $resSec['name'];
}

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// ---------- Get summon ID ----------
$summonId = (int)($_GET['id'] ?? 0);
if ($summonId <= 0) {
    die('Invalid summon id.');
}

// ---------- Load violation types ----------
$violationTypes = [];
$resVT = $conn->query("SELECT violation_typeID, Description, Point FROM violation_type ORDER BY violation_typeID ASC");
if ($resVT) {
    while ($r = $resVT->fetch_assoc()) {
        $violationTypes[] = $r;
    }
}

// ---------- Load summon ----------
$stmt = $conn->prepare("
    SELECT ts.traffic_summonID, ts.student_id, ts.staff_id, ts.date, ts.violation_typeID,
           s.name AS student_name,
           vt.Description AS violation_desc, vt.Point AS violation_point
    FROM traffic_summon ts
    LEFT JOIN student s ON s.student_id = ts.student_id
    LEFT JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
    WHERE ts.traffic_summonID = ?
    LIMIT 1
");
$stmt->bind_param('i', $summonId);
$stmt->execute();
$summon = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$summon) {
    die('Summon not found.');
}

$errors = [];
$success = '';

// ---------- Handle update ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $token)) {
        $errors[] = 'Invalid session token. Please refresh and try again.';
    }

    $newDate = trim($_POST['date'] ?? '');
    $newViolationType = (int)($_POST['violation_typeID'] ?? 0);

    if ($newDate === '') {
        $errors[] = 'Please select a date.';
    }
    if ($newViolationType <= 0) {
        $errors[] = 'Please select a violation type.';
    }

    if (empty($errors)) {
        // Fetch new violation info
        $stmtV = $conn->prepare("SELECT Description, Point FROM violation_type WHERE violation_typeID = ? LIMIT 1");
        $stmtV->bind_param('i', $newViolationType);
        $stmtV->execute();
        $vinfo = $stmtV->get_result()->fetch_assoc();
        $stmtV->close();

        if (!$vinfo) {
            $errors[] = 'Selected violation type does not exist.';
        } else {
            $conn->begin_transaction();
            try {
                // Update traffic_summon
                $stmtU = $conn->prepare("UPDATE traffic_summon SET date = ?, violation_typeID = ? WHERE traffic_summonID = ?");
                $stmtU->bind_param('sii', $newDate, $newViolationType, $summonId);
                $stmtU->execute();
                $stmtU->close();

                // Update linked demerit_point row if exists (keeps legacy table consistent)
                $newDesc = $vinfo['Description'];
                $newPoints = (int)$vinfo['Point'];
                $stmtDP = $conn->prepare("UPDATE demerit_point SET description = ?, date = ?, total = ? WHERE traffic_summonID = ?");
                $stmtDP->bind_param('ssii', $newDesc, $newDate, $newPoints, $summonId);
                $stmtDP->execute();
                $stmtDP->close();

                $conn->commit();
                $success = 'Summon updated successfully.';

                // Reload summon data
                $stmt = $conn->prepare("
                    SELECT ts.traffic_summonID, ts.student_id, ts.staff_id, ts.date, ts.violation_typeID,
                           s.name AS student_name,
                           vt.Description AS violation_desc, vt.Point AS violation_point
                    FROM traffic_summon ts
                    LEFT JOIN student s ON s.student_id = ts.student_id
                    LEFT JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
                    WHERE ts.traffic_summonID = ?
                    LIMIT 1
                ");
                $stmt->bind_param('i', $summonId);
                $stmt->execute();
                $summon = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = 'Update failed: ' . $e->getMessage();
            }
        }
    }
}

$receiptUrl = 'summon_view.php?id=' . (int)$summon['traffic_summonID'];
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . urlencode($receiptUrl);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Summon</title>
    <style>
        * { box-sizing: border-box; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; background-color: #f3f4f6; color: #111827; }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 260px 1fr; }

        /* Sidebar */
        .sidebar { background-color: #ffffff; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column; padding: 18px 18px 24px; }
        .sidebar-header { display: flex; align-items: center; gap: 10px; padding-bottom: 20px; border-bottom: 1px solid #e5e7eb; }
        .sidebar-logo { width: 40px; height: 40px; border-radius: 12px; background-color: #0066ff; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .sidebar-logo img { max-width: 100%; max-height: 100%; }
        .sidebar-title { font-size: 18px; font-weight: 600; color: #111827; }
        .nav { margin-top: 24px; display: flex; flex-direction: column; gap: 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 999px; color: #374151; text-decoration: none; font-size: 14px; }
        .nav-link span.icon { width: 18px; text-align: center; }
        .nav-link:hover { background-color: #fee2e2; color: #111827; }
        .nav-link.active { background-color: #f97373; color: #ffffff; }
        .nav-link.active span.icon { color: #ffffff; }
        .sidebar-footer { margin-top: auto; padding-top: 18px; border-top: 1px solid #e5e7eb; }
        .logout-btn { display: flex; align-items: center; gap: 10px; font-size: 14px; color: #ef4444; text-decoration: none; }

        /* Main */
        .main { display: flex; flex-direction: column; }
        .topbar { height: 64px; background-color: #ffffff; display: flex; align-items: center; justify-content: flex-end; padding: 0 32px; box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06); }
        .topbar-user { display: flex; align-items: center; gap: 10px; font-size: 14px; }
        .topbar-user-info { text-align: right; }
        .topbar-role { font-size: 12px; color: #6b7280; }
        .avatar { width: 34px; height: 34px; border-radius: 999px; background-color: #f97316; display: flex; align-items: center; justify-content: center; color: #ffffff; font-weight: 600; }
        .content { padding: 26px 40px 36px; }
        .page-title { font-size: 30px; font-weight: 700; margin: 0; }
        .page-subtitle { margin-top: 6px; color: #6b7280; font-size: 14px; }

        /* Panel */
        .panel { margin-top: 18px; background: #fff; border-radius: 22px; padding: 18px; box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08); border: 1px solid rgba(229, 231, 235, 0.9); }

        .alert { border-radius: 16px; padding: 12px 14px; margin-bottom: 14px; }
        .alert.error { background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; }
        .alert.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }

        /* Form */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        label { font-size: 12px; color: #6b7280; }
        input, select { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid #e5e7eb; background: #fff; font-size: 14px; outline: none; }
        input:focus, select:focus { border-color: #93c5fd; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.10); }
        input[readonly] { background: #f9fafb; }

        .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; }
        .btn { border: none; border-radius: 999px; padding: 10px 14px; font-size: 14px; cursor: pointer; font-weight: 700; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-ghost { background: #f3f4f6; color: #111827; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }

        .divider { border: 0; border-top: 1px solid #eef2f7; margin: 18px 0; }

        /* QR row */
        .qr-row { display: grid; grid-template-columns: 1fr auto; gap: 14px; align-items: center; }
        .qr-box { display: flex; align-items: center; gap: 14px; }
        .qr-img { width: 160px; height: 160px; border-radius: 16px; border: 1px solid #e5e7eb; background: #fff; }
        .link { color: #2563eb; text-decoration: none; font-weight: 700; }
        .link:hover { text-decoration: underline; }

        @media (max-width: 1100px) {
            .form-grid { grid-template-columns: 1fr; }
            .qr-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 740px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .content { padding: 18px; }
        }
    </style>
</head>
<body>
<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="images/logo.png" alt="FKPark" onerror="this.style.display='none'">
            </div>
            <div class="sidebar-title">FKPark</div>
        </div>

        <nav class="nav">
            <a href="dashboard_security.php" class="nav-link"><span class="icon">☰</span><span>Dashboard</span></a>
            <a href="vehicle_approval.php" class="nav-link"><span class="icon">✅</span><span>Vehicle Approvals</span></a>
            <a href="create_summon.php" class="nav-link"><span class="icon">⚠️</span><span>Create Summon</span></a>
            <a href="summon_list.php" class="nav-link active"><span class="icon">📄</span><span>Summons List</span></a>
            <a href="security_profile.php" class="nav-link"><span class="icon">👤</span><span>Profile</span></a>
        </nav>

        <div class="sidebar-footer">
            <a href="login.php?timeout=1" class="logout-btn">← Logout</a>
        </div>
    </aside>

    <!-- Main -->
    <main class="main">
        <div class="topbar">
            <div class="topbar-user">
                <div class="topbar-user-info">
                    <div>Welcome, <?= h($securityName) ?></div>
                    <div class="topbar-role">Security Staff</div>
                </div>
                <div class="avatar"><?= h(strtoupper(substr($securityName, 0, 1))) ?></div>
            </div>
        </div>

        <div class="content">
            <h1 class="page-title">Edit Summon #<?= (int)$summon['traffic_summonID'] ?></h1>
            <div class="page-subtitle">Correct summon information if wrong details were entered. QR receipt will reflect the updated data.</div>

            <section class="panel">
                <?php if (!empty($errors)): ?>
                    <div class="alert error">
                        <strong>Fix the following:</strong>
                        <ul style="margin:8px 0 0 18px;">
                            <?php foreach ($errors as $e): ?>
                                <li><?= h($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php elseif ($success): ?>
                    <div class="alert success"><strong><?= h($success) ?></strong></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>" />

                    <div class="form-grid">
                        <div class="field">
                            <label>Student</label>
                            <input type="text" readonly value="<?= h(($summon['student_id'] ?? '') . ' - ' . ($summon['student_name'] ?? '')) ?>" />
                        </div>

                        <div class="field">
                            <label>Date of Violation</label>
                            <input type="date" name="date" value="<?= h($summon['date'] ?? '') ?>" />
                        </div>

                        <div class="field">
                            <label>Issued By (Staff)</label>
                            <input type="text" readonly value="<?= h($summon['staff_id'] ?? '') ?>" />
                        </div>

                        <div class="field">
                            <label>Violation Type</label>
                            <select name="violation_typeID">
                                <option value="0">-- Select violation --</option>
                                <?php foreach ($violationTypes as $vt): ?>
                                    <option value="<?= (int)$vt['violation_typeID'] ?>" <?= ((int)$summon['violation_typeID'] === (int)$vt['violation_typeID']) ? 'selected' : '' ?>>
                                        <?= h($vt['Description']) ?> (<?= (int)$vt['Point'] ?> pts)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a class="btn btn-ghost" href="summon_list.php">Back</a>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>

                <hr class="divider" />

                <div class="qr-row">
                    <div>
                        <div style="font-weight:800;">Current Receipt (QR)</div>
                        <div style="color:#6b7280; font-size:13px;">Scanning opens the updated summon receipt.</div>
                    </div>
                    <div class="qr-box">
                        <img class="qr-img" src="<?= h($qrUrl) ?>" alt="Summon QR" />
                        <div>
                            <div style="font-weight:800; margin-bottom:6px;">Open receipt</div>
                            <a class="link" href="<?= h($receiptUrl) ?>" target="_blank" rel="noopener">View Receipt</a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
