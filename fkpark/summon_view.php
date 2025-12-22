<?php
// summon_view.php
// Public / student-accessible summon receipt page (used by QR scans).

// NOTE: This page is the *result page* when a QR is scanned.
// Security staff should generate/print the QR after creating a summon.
// Therefore, the receipt page must NOT render the QR again.
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
require 'db.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$summonId = (int)($_GET['id'] ?? 0);
if ($summonId <= 0) {
    http_response_code(400);
    echo "Invalid summon id.";
    exit;
}

// Load summon details
$sql = "
    SELECT
        ts.traffic_summonID,
        ts.date AS summon_date,
        ts.staff_id,
        s.student_id,
        s.name AS student_name,
        s.email AS student_email,
        v.plate_num,
        v.type AS vehicle_type,
        v.brand AS vehicle_brand,
        vt.Description AS violation_desc,
        vt.Point AS violation_point,
        dp.total AS demerit_total,
        dp.description AS demerit_desc
    FROM traffic_summon ts
    JOIN student s ON s.student_id = ts.student_id
    JOIN violation_type vt ON vt.violation_typeID = ts.violation_typeID
    LEFT JOIN demerit_point dp ON dp.traffic_summonID = ts.traffic_summonID AND dp.student_id = ts.student_id
    LEFT JOIN vehicle v ON v.student_id = ts.student_id
    WHERE ts.traffic_summonID = ?
    ORDER BY v.vehicle_id DESC
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $summonId);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

if (!$data) {
    http_response_code(404);
    echo "Summon not found.";
    exit;
}

// If logged in as student, prevent viewing other students' summons
if (isset($_SESSION['username']) && ($_SESSION['role'] ?? '') === 'Student') {
    if ($_SESSION['username'] !== $data['student_id']) {
        http_response_code(403);
        echo "Forbidden.";
        exit;
    }
}

// Optional: mark as read if notification table exists and the viewer is the student
if (isset($_SESSION['username']) && ($_SESSION['role'] ?? '') === 'Student' && $_SESSION['username'] === $data['student_id']) {
    $chk = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'summon_notification'");
    $chk->execute();
    $c = $chk->get_result()->fetch_assoc();
    $hasNotifTable = ((int)($c['c'] ?? 0) > 0);
    $chk->close();

    if ($hasNotifTable) {
        $sid = $_SESSION['username'];
        $stmt = $conn->prepare(
            "INSERT INTO summon_notification (traffic_summonID, student_id, is_read, read_at)
             VALUES (?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE is_read=1, read_at=NOW()"
        );
        $stmt->bind_param('is', $summonId, $sid);
        $stmt->execute();
        $stmt->close();
    }
}

// QR is intentionally NOT generated on this receipt page.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Summon Receipt #<?php echo esc($data['traffic_summonID']); ?> - FKPark</title>
    <style>
        *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
        body{margin:0;background:#f3f4f6;color:#111827;}
        .wrap{max-width:920px;margin:32px auto;padding:0 16px;}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 10px 30px rgba(17,24,39,.06);padding:22px;}
        .header{display:flex;align-items:flex-start;justify-content:flex-start;gap:18px;}
        h1{margin:0 0 6px;font-size:26px;}
        .muted{color:#6b7280;font-size:14px;}
        .pill{display:inline-flex;align-items:center;gap:8px;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:6px 12px;font-size:13px;font-weight:600;}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:18px;}
        .item{border:1px solid #e5e7eb;border-radius:14px;padding:12px 14px;background:#fafafa;}
        .k{font-size:12px;color:#6b7280;margin-bottom:4px;}
        .v{font-size:15px;font-weight:600;}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;}
        .btn{border:0;border-radius:999px;padding:10px 14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px;}
        .btn.primary{background:#0066ff;color:#fff;}
        .btn.ghost{background:#eef2ff;color:#111827;}
        @media (max-width: 820px){.grid{grid-template-columns:1fr;}.header{flex-direction:column;}}
        @media print{.btn{display:none;} body{background:#fff;} .card{box-shadow:none;}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="header">
            <div>
                <div class="pill">Traffic Summon Receipt</div>
                <h1>Summon #<?php echo esc($data['traffic_summonID']); ?></h1>
                <div class="muted">Issued on <b><?php echo esc($data['summon_date']); ?></b> by Staff <b><?php echo esc($data['staff_id']); ?></b></div>
                <div class="muted" style="margin-top:6px;">This receipt is opened by scanning the QR issued by Security staff.</div>
            </div>
        </div>

        <div class="grid">
            <div class="item">
                <div class="k">Student</div>
                <div class="v"><?php echo esc($data['student_name']); ?> (<?php echo esc($data['student_id']); ?>)</div>
                <div class="muted"><?php echo esc($data['student_email']); ?></div>
            </div>
            <div class="item">
                <div class="k">Vehicle</div>
                <div class="v"><?php echo esc($data['plate_num'] ?? '-'); ?></div>
                <div class="muted"><?php echo esc(($data['vehicle_type'] ?? '-') . ' • ' . ($data['vehicle_brand'] ?? '-')); ?></div>
            </div>

            <div class="item">
                <div class="k">Violation</div>
                <div class="v"><?php echo esc($data['violation_desc']); ?></div>
                <div class="muted">Demerit points (violation): <b><?php echo esc($data['violation_point']); ?></b></div>
            </div>
            <div class="item">
                <div class="k">Demerit Record</div>
                <div class="v"><?php echo esc($data['demerit_total'] ?? $data['violation_point']); ?> points</div>
                <div class="muted"><?php echo esc($data['demerit_desc'] ?? $data['violation_desc']); ?></div>
            </div>
        </div>

        <div class="actions">
            <a class="btn primary" href="#" onclick="window.print();return false;">🖨️ Print / Save</a>
            <?php if (isset($_SESSION['username']) && ($_SESSION['role'] ?? '') === 'Student'): ?>
                <a class="btn ghost" href="student_summon.php">⬅ Back to My Summons</a>
            <?php else: ?>
                <a class="btn ghost" href="summon_list.php"> Back to Summon List</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
