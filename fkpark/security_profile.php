<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

/* --- No cache so Back/Forward won't show stale page --- */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* --- Only Security can access --- */
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Security') {
    header("Location: login.php?timeout=1");
    exit;
}

$staffId   = $_SESSION['username']; // security.staff_id is the same as login.username
$staffName = $_SESSION['username'] ?? 'Security Staff';

$flashSuccess = "";
$flashError   = "";

/* =========================================================
   LOAD CURRENT PROFILE DATA (from security table)
   ========================================================= */
$name  = "";
$email = "";
$phone = "";

$sqlLoad = "SELECT name, email, phone FROM security WHERE staff_id = ?";
$stmtLoad = mysqli_prepare($conn, $sqlLoad);
if ($stmtLoad) {
    mysqli_stmt_bind_param($stmtLoad, "s", $staffId);
    mysqli_stmt_execute($stmtLoad);
    $res = mysqli_stmt_get_result($stmtLoad);
    if ($row = mysqli_fetch_assoc($res)) {
        $name  = $row['name'] ?? '';
        $email = $row['email'] ?? '';
        $phone = $row['phone'] ?? '';
        if ($name !== '') $staffName = $name; // show nicer name in UI
    }
}

/* =========================================================
   HANDLE POST ACTIONS
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ---------- SAVE PERSONAL INFO ---------- */
    if (isset($_POST['save_profile'])) {

        $newName  = trim($_POST['full_name'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');
        $newPhone = trim($_POST['phone'] ?? '');

        if ($newName === '' || $newEmail === '') {
            $flashError = "Full Name and Email are required.";
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $flashError = "Please enter a valid email address.";
        } else {
            // If row exists -> UPDATE, else -> INSERT
            $sqlCheck = "SELECT staff_id FROM security WHERE staff_id = ?";
            $stmtCheck = mysqli_prepare($conn, $sqlCheck);
            if ($stmtCheck) {
                mysqli_stmt_bind_param($stmtCheck, "s", $staffId);
                mysqli_stmt_execute($stmtCheck);
                $r = mysqli_stmt_get_result($stmtCheck);

                if (mysqli_fetch_assoc($r)) {
                    $sqlUpd = "UPDATE security SET name = ?, email = ?, phone = ? WHERE staff_id = ?";
                    $stmtUpd = mysqli_prepare($conn, $sqlUpd);
                    if ($stmtUpd) {
                        mysqli_stmt_bind_param($stmtUpd, "ssss", $newName, $newEmail, $newPhone, $staffId);
                        if (mysqli_stmt_execute($stmtUpd)) {
                            $flashSuccess = "Profile updated successfully.";
                            $name  = $newName;
                            $email = $newEmail;
                            $phone = $newPhone;
                            $staffName = $newName;
                        } else {
                            $flashError = "Failed to update profile: " . mysqli_error($conn);
                        }
                    } else {
                        $flashError = "Database error: " . mysqli_error($conn);
                    }
                } else {
                    $sqlIns = "INSERT INTO security (staff_id, name, email, phone) VALUES (?, ?, ?, ?)";
                    $stmtIns = mysqli_prepare($conn, $sqlIns);
                    if ($stmtIns) {
                        mysqli_stmt_bind_param($stmtIns, "ssss", $staffId, $newName, $newEmail, $newPhone);
                        if (mysqli_stmt_execute($stmtIns)) {
                            $flashSuccess = "Profile created successfully.";
                            $name  = $newName;
                            $email = $newEmail;
                            $phone = $newPhone;
                            $staffName = $newName;
                        } else {
                            $flashError = "Failed to create profile: " . mysqli_error($conn);
                        }
                    } else {
                        $flashError = "Database error: " . mysqli_error($conn);
                    }
                }
            } else {
                $flashError = "Database error: " . mysqli_error($conn);
            }
        }
    }

    /* ---------- UPDATE PASSWORD ---------- */
    if (isset($_POST['update_password'])) {

        $current = trim($_POST['current_password'] ?? '');
        $newPass = trim($_POST['new_password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');

        if ($current === '' || $newPass === '' || $confirm === '') {
            $flashError = "Please fill in all password fields.";
        } elseif (strlen($newPass) < 8) {
            $flashError = "New password must be at least 8 characters long.";
        } elseif ($newPass !== $confirm) {
            $flashError = "New password and confirmation do not match.";
        } else {
            // Load current stored password from login
            $sqlLogin = "SELECT password FROM login WHERE username = ? AND role_type = 'Security' LIMIT 1";
            $stmtLogin = mysqli_prepare($conn, $sqlLogin);
            if ($stmtLogin) {
                mysqli_stmt_bind_param($stmtLogin, "s", $staffId);
                mysqli_stmt_execute($stmtLogin);
                $resLogin = mysqli_stmt_get_result($stmtLogin);

                if ($lr = mysqli_fetch_assoc($resLogin)) {
                    $stored = $lr['password'];
                    $ok = false;

                    // hashed?
                    if (strpos($stored, '$2y$') === 0 || strpos($stored, '$argon2') === 0) {
                        if (password_verify($current, $stored)) {
                            $ok = true;
                        }
                    } else {
                        // plain text fallback
                        if (hash_equals($stored, $current)) {
                            $ok = true;
                        }
                    }

                    if (!$ok) {
                        $flashError = "Current password is incorrect.";
                    } else {
                        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                        $sqlUpdPass = "UPDATE login SET password = ? WHERE username = ? AND role_type = 'Security'";
                        $stmtUpdPass = mysqli_prepare($conn, $sqlUpdPass);
                        if ($stmtUpdPass) {
                            mysqli_stmt_bind_param($stmtUpdPass, "ss", $newHash, $staffId);
                            if (mysqli_stmt_execute($stmtUpdPass)) {
                                $flashSuccess = "Password updated successfully.";
                            } else {
                                $flashError = "Failed to update password: " . mysqli_error($conn);
                            }
                        } else {
                            $flashError = "Database error: " . mysqli_error($conn);
                        }
                    }
                } else {
                    $flashError = "Login record not found for Security role.";
                }
            } else {
                $flashError = "Database error: " . mysqli_error($conn);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Security Profile - FKPark</title>
    <style>
        * { box-sizing: border-box; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }

        body { margin: 0; background-color: #f3f4f6; color: #111827; }

        .layout { min-height: 100vh; display: grid; grid-template-columns: 260px 1fr; }

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

        .sidebar-title { font-size: 18px; font-weight: 600; color: #111827; }

        .nav { margin-top: 24px; display: flex; flex-direction: column; gap: 6px; }

        .nav-link {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px;
            border-radius: 999px;
            color: #374151;
            text-decoration: none;
            font-size: 14px;
        }

        .nav-link span.icon { width: 18px; text-align: center; }
        .nav-link:hover { background-color: #fee2e2; color: #111827; }

        .nav-link.active { background-color: #f97373; color: #ffffff; }
        .nav-link.active span.icon { color: #ffffff; }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
        }

        .logout-btn {
            display: flex; align-items: center; gap: 10px;
            font-size: 14px;
            color: #ef4444;
            text-decoration: none;
        }

        /* Main */
        .main { display: flex; flex-direction: column; }

        .topbar {
            height: 64px;
            background-color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 32px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06);
        }

        .topbar-user { display: flex; align-items: center; gap: 10px; font-size: 14px; }
        .topbar-user-info { text-align: right; }
        .topbar-role { font-size: 12px; color: #6b7280; }

        .avatar {
            width: 34px; height: 34px;
            border-radius: 999px;
            background-color: #f97316;
            display: flex; align-items: center; justify-content: center;
            color: #ffffff;
            font-weight: 600;
        }

        .content { padding: 26px 40px 36px; }

        .page-title { font-size: 34px; font-weight: 700; margin: 0; }
        .page-subtitle { margin-top: 8px; font-size: 14px; color: #6b7280; }

        .flash {
            margin-top: 18px;
            border-radius: 14px;
            padding: 10px 14px;
            font-size: 14px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
        }
        .flash.success { border: 1px solid #bbf7d0; background-color: #ecfdf5; color: #065f46; }
        .flash.error { border: 1px solid #fecaca; background-color: #fef2f2; color: #991b1b; }

        .card {
            margin-top: 22px;
            background-color: #ffffff;
            border-radius: 26px;
            padding: 22px 24px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .profile-big-avatar {
            width: 92px; height: 92px;
            border-radius: 999px;
            background-color: #f97316;
            display: flex; align-items: center; justify-content: center;
            color: #ffffff;
            font-size: 40px;
            font-weight: 700;
        }

        .profile-name { font-size: 28px; font-weight: 700; margin: 0; }
        .profile-meta { margin-top: 4px; color: #6b7280; font-size: 14px; }

        .section-title { font-size: 18px; font-weight: 700; margin: 0 0 14px; }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .field { margin-bottom: 14px; }
        .label { font-size: 13px; font-weight: 600; color: #111827; margin-bottom: 6px; }

        .input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            outline: none;
            font-size: 14px;
            background: #fff;
        }
        .input:focus { border-color: #f97373; box-shadow: 0 0 0 3px rgba(249, 115, 115, 0.15); }

        .actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 10px;
        }

        .btn {
            border: none;
            border-radius: 999px;
            padding: 12px 18px;
            font-weight: 700;
            cursor: pointer;
            font-size: 14px;
        }

        .btn.primary { background-color: #0066ff; color: #fff; }
        .btn.primary:hover { background-color: #0050c7; }

        .btn.outline {
            background: #fff;
            border: 1px solid #d1d5db;
            color: #111827;
        }

        .note { margin-top: 12px; font-size: 12px; color: #6b7280; }

        @media (max-width: 1100px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .content { padding: 20px 22px 28px; }
        }
    </style>
</head>
<body>
<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="images/logo.png" alt="FKPark Logo">
            </div>
            <div class="sidebar-title">FKPark</div>
        </div>

        <nav class="nav">
            <a href="dashboard_security.php" class="nav-link">
                <span class="icon">☰</span>
                <span>Dashboard</span>
            </a>
            <a href="vehicle_approval.php" class="nav-link">
                <span class="icon">✅</span>
                <span>Vehicle Approvals</span>
            </a>
            <a href="create_summon.php" class="nav-link">
                <span class="icon">⚠️</span>
                <span>Create Summon</span>
            </a>
            <a href="summon_list.php" class="nav-link">
                <span class="icon">📄</span>
                <span>Summons List</span>
            </a>
            <a href="security_profile.php" class="nav-link active">
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

    <!-- Main -->
    <div class="main">
        <!-- Topbar -->
        <header class="topbar">
            <div class="topbar-user">
                <div class="topbar-user-info">
                    <div>Welcome, <?php echo htmlspecialchars($staffName); ?></div>
                    <div class="topbar-role">Security Staff</div>
                </div>
                <div class="avatar">
                    <?php echo strtoupper(substr($staffName, 0, 1)); ?>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <h1 class="page-title">My Profile</h1>
            <div class="page-subtitle">Manage your personal information and account settings</div>

            <?php if ($flashSuccess): ?>
                <div class="flash success"><?php echo htmlspecialchars($flashSuccess); ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="flash error"><?php echo htmlspecialchars($flashError); ?></div>
            <?php endif; ?>

            <!-- Profile header card -->
            <section class="card">
                <div class="profile-header">
                    <div class="profile-big-avatar">
                        <?php echo strtoupper(substr($staffName, 0, 1)); ?>
                    </div>
                    <div>
                        <p class="profile-name"><?php echo htmlspecialchars($staffId); ?></p>
                        <div class="profile-meta">Security Staff • ID: <?php echo htmlspecialchars($staffId); ?></div>
                    </div>
                </div>
            </section>

            <!-- Personal info -->
            <section class="card">
                <h2 class="section-title">Personal Information</h2>

                <form method="post" action="">
                    <div class="field">
                        <div class="label">Full Name</div>
                        <input class="input" type="text" name="full_name" value="<?php echo htmlspecialchars($name); ?>">
                    </div>

                    <div class="grid-2">
                        <div class="field">
                            <div class="label">Email Address</div>
                            <input class="input" type="email" name="email" value="<?php echo htmlspecialchars($email); ?>">
                        </div>

                        <div class="field">
                            <div class="label">Phone Number</div>
                            <input class="input" type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" name="save_profile" class="btn primary">Save Changes</button>
                    </div>
                </form>
            </section>

            <!-- Change password -->
            <section class="card">
                <h2 class="section-title">Change Password</h2>

                <form method="post" action="">
                    <div class="field">
                        <div class="label">Current Password</div>
                        <input class="input" type="password" name="current_password">
                    </div>

                    <div class="grid-2">
                        <div class="field">
                            <div class="label">New Password</div>
                            <input class="input" type="password" name="new_password">
                        </div>

                        <div class="field">
                            <div class="label">Confirm New Password</div>
                            <input class="input" type="password" name="confirm_password">
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" name="update_password" class="btn outline">Update Password</button>
                    </div>

                    <div class="note">
                        Password must be at least 8 characters long. Passwords are securely hashed using password_hash().
                    </div>
                </form>
            </section>

        </main>
    </div>
</div>
</body>
</html>
