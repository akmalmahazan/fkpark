<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Only Student can access (using username instead of login_id)
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header("Location: login.php?timeout=1");
    exit;
}

$studentUsername = $_SESSION['username'];
$studentName = $studentUsername;

// Get student name from database
$sqlStudent = "SELECT name FROM student WHERE student_id = ?";
$stmtStudent = mysqli_prepare($conn, $sqlStudent);
if ($stmtStudent) {
    mysqli_stmt_bind_param($stmtStudent, "s", $studentUsername);
    mysqli_stmt_execute($stmtStudent);
    $result = mysqli_stmt_get_result($stmtStudent);
    if ($row = mysqli_fetch_assoc($result)) {
        $studentName = $row['name'];
    }
}

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$flash = ['ok' => null, 'err' => null];

// Handle create vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_vehicle'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash['err'] = "Invalid request token.";
    } else {
        $plate = strtoupper(trim($_POST['plate_num'] ?? ''));
        $type  = trim($_POST['type'] ?? '');
        $brand = trim($_POST['brand'] ?? '');

        // Validate
        if (!preg_match('/^[A-Z0-9\-]{3,12}$/', $plate)) {
            $flash['err'] = "Invalid plate format (3-12 characters, letters and numbers only).";
        } elseif (!in_array($type, ['Car', 'Motorcycle', 'Van', 'Other'], true)) {
            $flash['err'] = "Please choose a valid vehicle type.";
        } elseif ($brand === '') {
            $flash['err'] = "Please enter a brand/model.";
        } else {
            // Upload grant (PDF/JPG/PNG), max 5MB
            $grantPath = null;
            if (!empty($_FILES['grant']['name'])) {
                $dir = __DIR__ . '/uploads/grants';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $okTypes = ['application/pdf', 'image/jpeg', 'image/png'];
                $f = $_FILES['grant'];

                if ($f['error'] !== UPLOAD_ERR_OK) {
                    $flash['err'] = "Failed to upload grant (error code: {$f['error']}).";
                } elseif ($f['size'] > 5 * 1024 * 1024) {
                    $flash['err'] = "Grant file too large (maximum 5MB).";
                } else {
                    $mime = mime_content_type($f['tmp_name']);
                    if (!in_array($mime, $okTypes, true)) {
                        $flash['err'] = "Grant must be PDF, JPG or PNG.";
                    } else {
                        $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
                        $newName = 'grant_' . str_replace(['@', '.'], '_', $studentUsername) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $dest = $dir . '/' . $newName;
                        if (!move_uploaded_file($f['tmp_name'], $dest)) {
                            $flash['err'] = "Unable to save grant file.";
                        } else {
                            $grantPath = 'uploads/grants/' . $newName;
                        }
                    }
                }
            } else {
                $flash['err'] = "Please upload your vehicle grant.";
            }

            // Insert rows
            if (!$flash['err']) {
                mysqli_begin_transaction($conn);
                try {
                    $sql = "INSERT INTO vehicle (plate_num, type, brand, status, registration_date, student_id)
                            VALUES (?, ?, ?, 'Pending', CURDATE(), ?)";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "ssss", $plate, $type, $brand, $studentUsername);
                    mysqli_stmt_execute($stmt);
                    $vehicleId = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);

                    // FIXED: Set approval_date to NULL - it will be set when security approves/rejects
                    $sql2 = "INSERT INTO vehicle_approval (vehicle_id, approve_by, status, approval_date, `grant_file`)
                             VALUES (?, NULL, 'Pending', NULL, ?)";
                    $stmt2 = mysqli_prepare($conn, $sql2);
                    mysqli_stmt_bind_param($stmt2, "is", $vehicleId, $grantPath);
                    mysqli_stmt_execute($stmt2);
                    mysqli_stmt_close($stmt2);

                    mysqli_commit($conn);
                    $flash['ok'] = "Vehicle submitted for approval successfully.";
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $flash['err'] = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// Load vehicle list
$stmt = mysqli_prepare($conn, "
  SELECT v.vehicle_id, v.plate_num, v.type, v.brand, v.registration_date,
         COALESCE(va.status,'Pending') status
  FROM vehicle v
  LEFT JOIN vehicle_approval va
    ON va.vehicle_id = v.vehicle_id
   AND va.approval_id = (SELECT MAX(approval_id) FROM vehicle_approval WHERE vehicle_id = v.vehicle_id)
  WHERE v.student_id = ?
  ORDER BY v.registration_date DESC
");
mysqli_stmt_bind_param($stmt, "s", $studentUsername);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$vehicles = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

function esc($v) {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Vehicles - FKPark</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            background-color: #f3f4f6;
            color: #111827;
        }

        .layout {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 260px 1fr;
        }

        /* Sidebar */
        .sidebar {
            background-color: #ffffff;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            padding: 18px 18px 24px;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        .sidebar-logo {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.sidebar-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;  /* Ensures logo keeps its proportions */
}

        .sidebar-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .nav {
            margin-top: 24px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 999px;
            color: #374151;
            text-decoration: none;
            font-size: 14px;
        }

        .nav-link span.icon {
            width: 18px;
            text-align: center;
        }

        .nav-link:hover {
            background-color: #eff6ff;
            color: #111827;
        }

        .nav-link.active {
            background-color: #0066ff;
            color: #ffffff;
        }

        .nav-link.active span.icon {
            color: #ffffff;
        }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #ef4444;
            text-decoration: none;
        }

        /* Main area */
        .main {
            display: flex;
            flex-direction: column;
        }

        .topbar {
            height: 64px;
            background-color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 32px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06);
        }

        .topbar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .topbar-user-info {
            text-align: right;
        }

        .topbar-role {
            font-size: 12px;
            color: #6b7280;
        }

        .avatar {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            background-color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-weight: 600;
        }

        .content {
            padding: 26px 40px 36px;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 26px;
            font-weight: 600;
            margin: 0;
        }

        .page-subtitle {
            margin-top: 6px;
            font-size: 14px;
            color: #6b7280;
        }

        .btn-primary {
            padding: 10px 20px;
            border-radius: 999px;
            border: none;
            background-color: #0066ff;
            color: #ffffff;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-primary:hover {
            background-color: #0050c7;
        }

        .alert {
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            margin-bottom: 16px;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .card {
            background-color: #ffffff;
            border-radius: 24px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background-color: #f9fafb;
        }

        th, td {
            padding: 14px 18px;
            font-size: 14px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            font-weight: 600;
            color: #4b5563;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .status {
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
        }

        .status.Pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status.Approved {
            background-color: #dcfce7;
            color: #166534;
        }

        .status.Rejected {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        /* Modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background-color: rgba(15, 23, 42, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 50;
        }

        .modal-backdrop.show {
            display: flex;
        }

        .modal {
            background-color: #ffffff;
            border-radius: 24px;
            width: 600px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
        }

        .modal-close {
            font-size: 22px;
            border: none;
            background: none;
            cursor: pointer;
            color: #6b7280;
        }

        .modal-close:hover {
            color: #111827;
        }

        .modal-body {
            padding: 20px 24px;
            overflow-y: auto;
        }

        .form-field {
            margin-bottom: 16px;
        }

        .form-field label {
            display: block;
            font-size: 13px;
            margin-bottom: 4px;
            color: #374151;
        }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            font-size: 14px;
            outline: none;
        }

        .form-input:focus {
            border-color: #0066ff;
            box-shadow: 0 0 0 1px rgba(0, 102, 255, 0.18);
        }

        .modal-footer {
            padding: 14px 24px 18px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .btn-secondary {
            padding: 8px 18px;
            border-radius: 999px;
            border: 1px solid #d1d5db;
            background-color: #ffffff;
            font-size: 14px;
            color: #111827;
            cursor: pointer;
        }

        .btn-secondary:hover {
            background-color: #f3f4f6;
        }

        .btn-modal-primary {
            padding: 8px 26px;
            border-radius: 999px;
            border: none;
            background-color: #0066ff;
            color: #ffffff;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-modal-primary:hover {
            background-color: #0050c7;
        }

        @media (max-width: 768px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none;
            }
        }
    </style>
</head>
<body>
<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="images/logo-removebg.png" alt="FKPark Logo">
            </div>
            <div class="sidebar-title">FKPark</div>
        </div>

        <nav class="nav">
            <a href="dashboard_stud.php" class="nav-link">
                <span class="icon">☰</span>
                <span>Dashboard</span>
            </a>
            <a href="student_vehicles.php" class="nav-link active">
                <span class="icon">🚘</span>
                <span>My Vehicles</span>
            </a>
            <a href="book_parking.php" class="nav-link">
                <span class="icon">📅</span>
                <span>Book Parking</span>
            </a>
            <a href="my_bookings.php" class="nav-link">
                <span class="icon">📄</span>
                <span>My Bookings</span>
            </a>
            <a href="student_summon.php" class="nav-link">
                <span class="icon">⚠️</span>
                <span>Summons &amp; Demerit</span>
            </a>
            <a href="student_profile.php" class="nav-link">
                <span class="icon">👤</span>
                <span>Profile</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">
                <span>⟵</span>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- Main area -->
    <div class="main">
        <!-- Topbar -->
        <header class="topbar">
            <div class="topbar-user">
                <div class="topbar-user-info">
                    <div>Welcome, <?php echo esc($studentName); ?></div>
                    <div class="topbar-role">Student</div>
                </div>
                <div class="avatar">
                    <?php echo strtoupper(substr($studentName, 0, 1)); ?>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <div class="content-header">
                <div>
                    <h1 class="page-title">My Vehicles</h1>
                    <p class="page-subtitle">Register a vehicle and upload your grant for approval</p>
                </div>
                <button class="btn-primary" onclick="openModal()">+ Add Vehicle</button>
            </div>

            <?php if ($flash['ok']): ?>
                <div class="alert alert-success"><?php echo esc($flash['ok']); ?></div>
            <?php elseif ($flash['err']): ?>
                <div class="alert alert-danger"><?php echo esc($flash['err']); ?></div>
            <?php endif; ?>

            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Plate Number</th>
                            <th>Type</th>
                            <th>Brand/Model</th>
                            <th>Registered Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vehicles)): ?>
                            <tr>
                                <td colspan="5" style="color: #6b7280; text-align: center;">No vehicles registered yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($vehicles as $v): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo esc($v['plate_num']); ?></td>
                                    <td><?php echo esc($v['type']); ?></td>
                                    <td><?php echo esc($v['brand']); ?></td>
                                    <td><?php echo esc(date('d/m/Y', strtotime($v['registration_date']))); ?></td>
                                    <td><span class="status <?php echo esc($v['status']); ?>"><?php echo esc($v['status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Modal: Register Vehicle -->
<div class="modal-backdrop" id="addVehicleModal">
    <div class="modal">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
            <div class="modal-header">
                <div class="modal-title">Register New Vehicle</div>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-field">
                    <label>Plate Number</label>
                    <input name="plate_num" class="form-input" placeholder="e.g., ABC1234" required>
                </div>
                <div class="form-field">
                    <label>Vehicle Type</label>
                    <select name="type" class="form-input" required>
                        <option value="Car">Car</option>
                        <option value="Motorcycle">Motorcycle</option>
                        <option value="Van">Van</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-field">
                    <label>Brand / Model</label>
                    <input name="brand" class="form-input" placeholder="e.g., Toyota Vios" required>
                </div>
                <div class="form-field">
                    <label>Upload Vehicle Grant (PDF/JPG/PNG, max 5MB)</label>
                    <input type="file" name="grant" class="form-input" accept=".pdf,.jpg,.jpeg,.png" required>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" type="button" onclick="closeModal()">Cancel</button>
                <button class="btn-modal-primary" type="submit" name="create_vehicle">Submit for Approval</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('addVehicleModal').classList.add('show');
}

function closeModal() {
    document.getElementById('addVehicleModal').classList.remove('show');
}

// Close modal when clicking outside
document.getElementById('addVehicleModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>
</body>
</html>