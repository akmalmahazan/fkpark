<?php
// admin_profile.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?timeout=1");
    exit;
}

$adminUsername = $_SESSION['username'];

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$flash = ['ok' => null, 'err' => null];

// Fetch profile from admin table
$admin = ['name'=>'', 'email'=>'', 'phone'=>''];
$stmt = $conn->prepare("SELECT name, email, phone FROM admin WHERE admin_id = ?");
$stmt->bind_param("s", $adminUsername);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $admin = $row;
}
$stmt->close();

function esc($v){ return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash['err'] = "Invalid request token.";
    } else {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '' || $email === '' || $phone === '') {
            $flash['err'] = "Please fill in all profile fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash['err'] = "Invalid email address.";
        } else {
            $stmt = $conn->prepare("UPDATE admin SET name=?, email=?, phone=? WHERE admin_id=?");
            $stmt->bind_param("ssss", $name, $email, $phone, $adminUsername);
            if ($stmt->execute()) {
                $flash['ok'] = "Profile updated successfully.";
                $admin = ['name'=>$name, 'email'=>$email, 'phone'=>$phone];
            } else {
                $flash['err'] = "Failed to update profile.";
            }
            $stmt->close();
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash['err'] = "Invalid request token.";
    } else {
        $current = $_POST['current_password'] ?? '';
        $new1    = $_POST['new_password'] ?? '';
        $new2    = $_POST['new_password2'] ?? '';

        if ($new1 !== $new2) {
            $flash['err'] = "New passwords do not match.";
        } elseif (strlen($new1) < 8) {
            $flash['err'] = "New password must be at least 8 characters.";
        } else {
            // Fetch current password hash from login table
            $stmt = $conn->prepare("SELECT password FROM login WHERE username=?");
            $stmt->bind_param("s", $adminUsername);
            $stmt->execute();
            $stmt->bind_result($hash);
            $stmt->fetch();
            $stmt->close();

            if (!$hash || !password_verify($current, $hash)) {
                $flash['err'] = "Current password is incorrect.";
            } else {
                $newHash = password_hash($new1, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE login SET password=? WHERE username=?");
                $stmt->bind_param("ss", $newHash, $adminUsername);
                if ($stmt->execute()) {
                    $flash['ok'] = "Password changed successfully.";
                } else {
                    $flash['err'] = "Failed to change password.";
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile - FKPark</title>
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
            object-fit: contain;
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

        .alert {
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            margin-top: 20px;
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

        .profile-header {
            margin-top: 24px;
            background-color: #ffffff;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.08);
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .profile-avatar {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1d4ed8, #3b82f6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 36px;
            font-weight: 600;
        }

        .profile-info h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }

        .profile-info p {
            margin: 4px 0 0;
            color: #6b7280;
            font-size: 14px;
        }

        .card {
            margin-top: 20px;
            background-color: #ffffff;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.08);
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-field {
            margin-bottom: 16px;
        }

        .form-field.full {
            grid-column: 1 / -1;
        }

        .form-field label {
            display: block;
            font-size: 13px;
            margin-bottom: 4px;
            color: #374151;
            font-weight: 500;
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

        .btn-primary {
            padding: 10px 24px;
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

        .btn-outline {
            padding: 10px 24px;
            border-radius: 999px;
            border: 1px solid #d1d5db;
            background-color: #ffffff;
            color: #111827;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-outline:hover {
            background-color: #f3f4f6;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .note {
            margin-top: 12px;
            font-size: 12px;
            color: #6b7280;
        }

        @media (max-width: 768px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none;
            }
            .form-row {
                grid-template-columns: 1fr;
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
            <a href="dashboard_admin.php" class="nav-link">
                <span class="icon">☰</span>
                <span>Dashboard</span>
            </a>
            <a href="parking_area.php" class="nav-link">
                <span class="icon">🅿</span>
                <span>Manage Parking</span>
            </a>
            <a href="admin_manage.php" class="nav-link">
                <span class="icon">👥</span>
                <span>Manage Users</span>
            </a>
            <a href="admin_profile.php" class="nav-link active">
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
                    <div>Welcome, <?php echo esc($admin['name'] ?: $adminUsername); ?></div>
                    <div class="topbar-role">Admin</div>
                </div>
                <div class="avatar">
                    <?php echo strtoupper(substr($admin['name'] ?: $adminUsername, 0, 1)); ?>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <h1 class="page-title">My Profile</h1>
            <p class="page-subtitle">Manage your personal information and account settings</p>

            <?php if ($flash['ok']): ?>
                <div class="alert alert-success"><?php echo esc($flash['ok']); ?></div>
            <?php elseif ($flash['err']): ?>
                <div class="alert alert-danger"><?php echo esc($flash['err']); ?></div>
            <?php endif; ?>

            <div class="profile-header">
                <div class="profile-avatar">
                    <?php echo esc(strtoupper(substr($admin['name'] ?: $adminUsername, 0, 1))); ?>
                </div>
                <div class="profile-info">
                    <h2><?php echo esc($admin['name'] ?: $adminUsername); ?></h2>
                    <p>Admin • ID: <?php echo esc($adminUsername); ?></p>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Personal Information</div>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">

                    <div class="form-field full">
                        <label>Full Name</label>
                        <input name="name" class="form-input" value="<?php echo esc($admin['name']); ?>" required>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label>Email Address</label>
                            <input name="email" type="email" class="form-input" value="<?php echo esc($admin['email']); ?>" required>
                        </div>
                        <div class="form-field">
                            <label>Phone Number</label>
                            <input name="phone" class="form-input" value="<?php echo esc($admin['phone']); ?>" required>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="btn-primary" type="submit" name="update_profile">Save Changes</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-title">Change Password</div>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">

                    <div class="form-field">
                        <label>Current Password</label>
                        <input name="current_password" type="password" class="form-input" required>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label>New Password</label>
                            <input name="new_password" type="password" class="form-input" minlength="8" required>
                        </div>
                        <div class="form-field">
                            <label>Confirm New Password</label>
                            <input name="new_password2" type="password" class="form-input" minlength="8" required>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="btn-outline" type="submit" name="change_password">Update Password</button>
                    </div>

                    <p class="note">Password must be at least 8 characters long. Passwords are securely hashed using password_hash().</p>
                </form>
            </div>
        </main>
    </div>
</div>
</body>
</html>
