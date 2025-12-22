<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Only Security can access
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Security') {
    header("Location: login.php?timeout=1");
    exit;
}

$securityUsername = $_SESSION['username'];
$securityName = $securityUsername;

// Fetch security name
$sqlSecurity = "SELECT name FROM security WHERE staff_id = ?";
if ($stmt = mysqli_prepare($conn, $sqlSecurity)) {
    mysqli_stmt_bind_param($stmt, "s", $securityUsername);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $securityName = $row['name'];
    }
    mysqli_stmt_close($stmt);
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$errors = [];
$success = null;
$createdSummon = null; // array with details

// Load violation types
$violationTypes = [];
$vtSql = "SELECT violation_typeID, Description, Point FROM violation_type ORDER BY Description";
$vtRes = mysqli_query($conn, $vtSql);
if ($vtRes) {
    while ($r = mysqli_fetch_assoc($vtRes)) {
        $violationTypes[] = $r;
    }
}



// AJAX lookup for plate suggestions (Security typing plate number)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'plate') {
    header('Content-Type: application/json; charset=utf-8');
    $q = strtoupper(trim($_GET['q'] ?? ''));
    $out = [];
    if ($q !== '' && strlen($q) >= 2) {
        $like = $q . '%';
        $sql = "SELECT v.plate_num, v.type, v.brand, v.student_id, s.name AS student_name
                FROM vehicle v
                JOIN student s ON s.student_id = v.student_id
                WHERE v.status='Approved' AND UPPER(v.plate_num) LIKE ?
                ORDER BY v.plate_num
                LIMIT 10";
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $like);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($r = mysqli_fetch_assoc($res)) {
                $out[] = $r;
            }
            mysqli_stmt_close($stmt);
        }
    }
    echo json_encode($out);
    exit;
}

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $token)) {
        $errors[] = 'Invalid session token. Please refresh and try again.';
    } else {
        $plate_input = strtoupper(trim($_POST['plate_num'] ?? ''));
        $violation_typeID = (int)($_POST['violation_typeID'] ?? 0);
        $date = trim($_POST['date'] ?? '');

        if ($plate_input === '') $errors[] = 'Please enter a vehicle plate number.';
        if ($violation_typeID <= 0) $errors[] = 'Please select a violation type.';
        if ($date === '') $errors[] = 'Please select a date.';

        // Basic date validation (YYYY-MM-DD)
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = 'Invalid date format.';
        }

        if (!$errors) {
            // Lookup vehicle + student for selected vehicle (ensure Approved)
            $vehicleRow = null;
            $vehicleSql = "SELECT v.vehicle_id, v.plate_num, v.type, v.brand, v.status, v.student_id, s.name AS student_name
                          FROM vehicle v
                          JOIN student s ON s.student_id = v.student_id
                          WHERE UPPER(v.plate_num) = ? AND v.status = 'Approved'";
            if ($stmt = mysqli_prepare($conn, $vehicleSql)) {
                mysqli_stmt_bind_param($stmt, "s", $plate_input);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $vehicleRow = mysqli_fetch_assoc($res) ?: null;
                mysqli_stmt_close($stmt);
            }

            if (!$vehicleRow) {
                $errors[] = 'Selected vehicle not found or not approved.';
            }

            // Lookup violation
            $violationRow = null;
            $violSql = "SELECT violation_typeID, Description, Point FROM violation_type WHERE violation_typeID = ?";
            if ($stmt = mysqli_prepare($conn, $violSql)) {
                mysqli_stmt_bind_param($stmt, "i", $violation_typeID);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $violationRow = mysqli_fetch_assoc($res) ?: null;
                mysqli_stmt_close($stmt);
            }

            if (!$violationRow) {
                $errors[] = 'Selected violation type not found.';
            }

            if (!$errors) {
                mysqli_begin_transaction($conn);
                try {
                    // Insert traffic_summon
                    $insertSummon = "INSERT INTO traffic_summon (staff_id, student_id, date, violation_typeID)
                                     VALUES (?, ?, ?, ?)";
                    if (!($stmt = mysqli_prepare($conn, $insertSummon))) {
                        throw new Exception('Failed to prepare summon insert.');
                    }
                    $student_id = $vehicleRow['student_id'];
                    mysqli_stmt_bind_param($stmt, "sssi", $securityUsername, $student_id, $date, $violation_typeID);
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception('Failed to insert summon: ' . mysqli_error($conn));
                    }
                    $traffic_summonID = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);

                    // Insert demerit_point
                    $dpDesc = $violationRow['Description'];
                    $dpTotal = (int)$violationRow['Point'];
                    $insertDp = "INSERT INTO demerit_point (student_id, description, date, total, traffic_summonID)
                                 VALUES (?, ?, ?, ?, ?)";
                    if (!($stmt = mysqli_prepare($conn, $insertDp))) {
                        throw new Exception('Failed to prepare demerit insert.');
                    }
                    mysqli_stmt_bind_param($stmt, "sssii", $student_id, $dpDesc, $date, $dpTotal, $traffic_summonID);
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception('Failed to insert demerit points: ' . mysqli_error($conn));
                    }
                    mysqli_stmt_close($stmt);

                    mysqli_commit($conn);

                    $success = 'Summon created successfully.';
                    $createdSummon = [
                        'traffic_summonID' => $traffic_summonID,
                        'date' => $date,
                        'student_id' => $student_id,
                        'student_name' => $vehicleRow['student_name'],
                        'plate_num' => $vehicleRow['plate_num'],
                        'vehicle_type' => $vehicleRow['type'],
                        'brand' => $vehicleRow['brand'],
                        'violation' => $violationRow['Description'],
                        'points' => $dpTotal
                    ];

                } catch (Throwable $e) {
                    mysqli_rollback($conn);
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

// Build QR URL (works best if your server has internet access)
// When printed or sent to the vehicle owner, scanning opens summon_view.php?id=...
$qrDataUrl = null;
$summonViewUrl = null;
if ($createdSummon) {
    // If your deployment uses HTTPS or a specific domain, you can hardcode it here.
    // For local/dev, we build a relative URL. QR providers usually require a full URL, but most scanners handle relative only if same domain.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

    $summonViewUrl = $scheme . '://' . $host . $path . '/summon_view.php?id=' . urlencode($createdSummon['traffic_summonID']);

    // Using goqr.me / qrserver API (external). If you prefer offline generation, tell me and I can give you a self-hosted QR library.
    $qrDataUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($summonViewUrl);
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Summon - FKPark</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; background-color: #f3f4f6; color: #111827; }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 260px 1fr; }

        /* Sidebar */
        .sidebar { background-color: #ffffff; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column; padding: 18px 18px 24px; }
        .sidebar-header { display: flex; align-items: center; gap: 10px; padding-bottom: 20px; border-bottom: 1px solid #e5e7eb; }
        .sidebar-logo { width: 40px; height: 40px; border-radius: 12px; background-color: #ffffffff; display: flex; align-items: center; justify-content: center; overflow: hidden; }
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
        .hint { font-size: 12px; color:#6b7280; margin-top:6px; }

        /* Main */
        .main { display: flex; flex-direction: column; }
        .topbar { height: 64px; background-color: #ffffff; display: flex; align-items: center; justify-content: flex-end; padding: 0 32px; box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06); }
        .topbar-user { display: flex; align-items: center; gap: 10px; font-size: 14px; }
        .topbar-user-info { text-align: right; }
        .topbar-role { font-size: 12px; color: #6b7280; }
        .avatar { width: 34px; height: 34px; border-radius: 999px; background-color: #f97316; display: flex; align-items: center; justify-content: center; color: #ffffff; font-weight: 600; }
        .content { padding: 26px 40px 36px; }
        .page-title { font-size: 26px; font-weight: 600; margin: 0 0 6px 0; }
        .page-subtitle { margin: 0 0 18px 0; color: #6b7280; font-size: 14px; }

        .grid { display: grid; grid-template-columns: 1fr; gap: 18px; max-width: 980px; }
        .card { background: #fff; border-radius: 18px; padding: 18px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06); }
        .card h3 { margin: 0 0 12px 0; font-size: 16px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 860px) { .layout { grid-template-columns: 1fr; } .sidebar { display: none; } .form-row { grid-template-columns: 1fr; } }

        label { display: block; font-size: 13px; color: #374151; margin-bottom: 6px; }
        /* Match FKPark input styling across all controls */
        select, input[type="date"], input[type="text"], textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            font-size: 14px;
            outline: none;
            background: #fff;
            color: #111827;
        }
        textarea { min-height: 92px; resize: vertical; }
        select:focus, input[type="date"]:focus, input[type="text"]:focus, textarea:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 4px rgba(147, 197, 253, 0.35);
        }

        /* Plate input: uppercase value but normal placeholder */
        #plateInput { text-transform: uppercase; }
        #plateInput::placeholder { text-transform: none; }

        .actions { display: flex; gap: 10px; align-items: center; justify-content: flex-end; margin-top: 14px; }
        .btn { border: 0; border-radius: 999px; padding: 10px 14px; font-size: 14px; cursor: pointer; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { filter: brightness(0.95); }
        .btn-ghost { background: #f3f4f6; color: #111827; }
        .btn-ghost:hover { background: #e5e7eb; }

        .alert { border-radius: 14px; padding: 12px 14px; font-size: 14px; margin-bottom: 14px; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }

        .qr-wrap { display: grid; grid-template-columns: 1fr 280px; gap: 16px; align-items: start; }
        @media (max-width: 860px) { .qr-wrap { grid-template-columns: 1fr; } }
        .pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; background: #f3f4f6; color: #111827; font-size: 13px; }
        .muted { color: #6b7280; font-size: 13px; }
        .kv { margin: 10px 0 0 0; display: grid; grid-template-columns: 140px 1fr; gap: 8px 10px; font-size: 14px; }
        .kv div.k { color: #6b7280; }
        .kv div.v { color: #111827; font-weight: 600; }
        .qr-box { background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 16px; padding: 14px; display: flex; flex-direction: column; gap: 10px; align-items: center; }
        .qr-box img { width: 220px; height: 220px; }
        .link { color: #2563eb; text-decoration: none; }
        .link:hover { text-decoration: underline; }

        .hint { margin-top: 10px; font-size: 12px; color: #6b7280; }
    
/* Plate suggestions dropdown */
.suggestions{
    position: relative;
    margin-top: 6px;
}
.suggestions .item{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:10px 12px;
    margin-top:6px;
    cursor:pointer;
    transition: background .12s ease, transform .08s ease;
}
.suggestions .item:hover{
    background:#f3f4f6;
    transform: translateY(-1px);
}
.suggestions .plate{
    font-weight:800;
}
.suggestions .meta{
    color:#6b7280;
    font-size:13px;
    margin-top:2px;
}
</style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo"><img src="images/logo.png" alt="FKPark"></div>
            <div class="sidebar-title">FKPark</div>
        </div>

        <nav class="nav">
            <a href="dashboard_security.php" class="nav-link"><span class="icon">☰</span><span>Dashboard</span></a>
            <a href="vehicle_approval.php" class="nav-link"><span class="icon">✅</span><span>Vehicle Approvals</span></a>
            <a href="create_summon.php" class="nav-link active"><span class="icon">⚠️</span><span>Create Summon</span></a>
            <a href="summon_list.php" class="nav-link"><span class="icon">📄</span><span>Summons List</span></a>
            <a href="security_profile.php" class="nav-link"><span class="icon">👤</span><span>Profile</span></a>
        </nav>

        <div class="sidebar-footer">
            <a class="logout-btn" href="logout.php">← Logout</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="topbar-user">
                <div class="topbar-user-info">
                    <div>Welcome, <?php echo htmlspecialchars($securityName); ?></div>
                    <div class="topbar-role">Security Staff</div>
                </div>
                <div class="avatar"><?php echo strtoupper(substr($securityName, 0, 1)); ?></div>
            </div>
        </header>

        <section class="content">
            <h1 class="page-title">Create Traffic Summon</h1>
            <p class="page-subtitle">Record a traffic violation and generate a QR code summon receipt.</p>

            <div class="grid">
                <div class="card">
                    <h3>Summon Details</h3>

                    <?php if ($errors): ?>
                        <div class="alert alert-error">
                            <strong>Fix the following:</strong>
                            <ul style="margin: 8px 0 0 18px;">
                                <?php foreach ($errors as $e): ?>
                                    <li><?php echo htmlspecialchars($e); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($success && $createdSummon): ?>
                        <div class="alert alert-success">
                            <?php echo htmlspecialchars($success); ?>
                        </div>

                        <div class="qr-wrap">
                            <div>
                                <div class="pill">🧾 Summon ID: <strong><?php echo (int)$createdSummon['traffic_summonID']; ?></strong></div>
                                <div class="kv">
                                    <div class="k">Date</div><div class="v"><?php echo htmlspecialchars($createdSummon['date']); ?></div>
                                    <div class="k">Student</div><div class="v"><?php echo htmlspecialchars($createdSummon['student_name'] . ' (' . $createdSummon['student_id'] . ')'); ?></div>
                                    <div class="k">Vehicle</div><div class="v"><?php echo htmlspecialchars($createdSummon['plate_num'] . ' — ' . $createdSummon['vehicle_type'] . ' • ' . $createdSummon['brand']); ?></div>
                                    <div class="k">Violation</div><div class="v"><?php echo htmlspecialchars($createdSummon['violation']); ?></div>
                                    <div class="k">Demerit Points</div><div class="v"><?php echo (int)$createdSummon['points']; ?></div>
                                </div>

                                <p class="muted" style="margin-top: 12px;">
                                    Share or print this QR code. When scanned, it will open the summon details page.
                                </p>

                                <?php if ($summonViewUrl): ?>
                                    <p class="muted">Summon link: <a class="link" href="<?php echo htmlspecialchars($summonViewUrl); ?>" target="_blank" rel="noopener">Open summon details</a></p>
                                <?php endif; ?>

                                <div class="actions" style="justify-content:flex-start; margin-top: 10px;">
                                    <button class="btn btn-ghost" onclick="window.print()">Print</button>
                                    <a class="btn btn-primary" style="text-decoration:none;" href="create_summon.php">Create Another</a>
                                </div>

                                <div class="hint">
                                    Note: QR image uses an external QR service. If your server has no internet access, tell me and I’ll switch to an offline PHP QR generator.
                                </div>
                            </div>

                            <div class="qr-box">
                                <div class="muted">Summon QR (issue to vehicle owner)</div>
                                <?php if ($qrDataUrl): ?>
                                    <img src="<?php echo htmlspecialchars($qrDataUrl); ?>" alt="QR Code">
                                <?php else: ?>
                                    <div class="muted">QR not available.</div>
                                <?php endif; ?>
                                <div class="muted" style="text-align:center;">Scan to open summon receipt (QR is issued by Security)</div>
                            </div>
                        </div>

                    <?php else: ?>
                        <form method="POST" action="create_summon.php">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

                            <div class="form-row">
                                <div>
                                    <label for="plateInput">Vehicle Plate Number (Approved only)</label>
<input
    id="plateInput"
    type="text"
    name="plate_num"
    placeholder="e.g. ABC1234"
    value="<?php echo htmlspecialchars($_POST['plate_num'] ?? ''); ?>"
    autocomplete="off"
    required
>
<div id="plateSuggestions" class="suggestions"></div>
<div id="plateInfo" class="hint"></div>
</div>

<div>
<label for="date">Date of Violation</label>
                                    <input id="date" type="date" name="date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                                </div>
                            </div>

                            <div class="form-row" style="margin-top: 14px;">
                                <div>
                                    <label for="violation_typeID">Violation Type</label>
                                    <select id="violation_typeID" name="violation_typeID" required>
                                        <option value="">-- Select violation --</option>
                                        <?php foreach ($violationTypes as $vt): ?>
                                            <option value="<?php echo (int)$vt['violation_typeID']; ?>">
                                                <?php echo htmlspecialchars($vt['Description'] . ' (Points: ' . $vt['Point'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!$violationTypes): ?>
                                        <div class="hint">No violation types found. Add rows to table <code>violation_type</code>.</div>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <label for="note">Remarks (optional)</label>
                                    <textarea id="note" name="note" placeholder="Extra notes about the incident (optional)."></textarea>
                                </div>
                            </div>

                            <div class="actions">
                                <button type="reset" class="btn btn-ghost">Clear</button>
                                <button type="submit" class="btn btn-primary" <?php echo !$violationTypes ? 'disabled' : ''; ?>>Generate Summon QR</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

        </section>
    </main>
</div>

<script>
(function(){
  const input = document.getElementById('plateInput');
  const list = document.getElementById('plateSuggestions');
  const info = document.getElementById('plateInfo');
  if(!input || !list) return;

  let timer = null;

  function clearList(){
    while (list.firstChild) list.removeChild(list.firstChild);
  }

  function setInfo(r){
    if(!info) return;
    info.textContent = r ? `${r.plate_num} — ${r.type} • ${r.brand} | ${r.student_name} (${r.student_id})` : '';
  }

  async function lookup(q){
    const res = await fetch(`create_summon.php?ajax=plate&q=${encodeURIComponent(q)}`, { credentials: 'same-origin' });
    if(!res.ok) return [];
    return await res.json();
  }

  input.addEventListener('input', function(){
    const q = input.value.trim().toUpperCase();
    input.value = q;

    if(timer) clearTimeout(timer);
    if(q.length < 2){
      clearList(); setInfo(null);
      return;
    }

    timer = setTimeout(async () => {
      const rows = await lookup(q);
      clearList();
      if(rows && rows.length){
        rows.forEach(r => {
          const opt = document.createElement('option');
          opt.value = r.plate_num;
          list.appendChild(opt);
        });
        setInfo(rows[0]);
      } else {
        setInfo(null);
      }
    }, 200);
  });
})();
</script>

</body>
</html>
