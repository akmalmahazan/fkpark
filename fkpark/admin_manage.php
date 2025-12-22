<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

/* --- No cache --- */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* --- Only Admin can access --- */
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?timeout=1");
    exit;
}

$adminName = $_SESSION['username'] ?? 'Admin User';

$flashError   = "";
$flashSuccess = "";
$editUser     = null;

/* =========================================================
   1. HANDLE CREATE USER (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ---------- CREATE USER ---------- */
    if (isset($_POST['create_user'])) {

        $username        = trim($_POST['username'] ?? '');
        $name            = trim($_POST['name'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $phone           = trim($_POST['phone'] ?? '');
        $role            = trim($_POST['role'] ?? '');
        $password        = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($username === '' || $name === '' || $email === '' || $phone === '' || $role === '' || $password === '') {
            $flashError = "Please fill in all required fields.";
        } elseif ($password !== $confirmPassword) {
            $flashError = "Password and Confirm Password do not match.";
        } elseif (!in_array($role, ['Student', 'Security', 'Admin'])) {
            $flashError = "Invalid role selected.";
        } else {
            try {
                mysqli_begin_transaction($conn);

                // 1) Insert into LOGIN (username is PRIMARY KEY)
                $sqlLogin = "INSERT INTO login (username, password, role_type)
                            VALUES (?, ?, ?)";
                $stmtLogin = mysqli_prepare($conn, $sqlLogin);

                // HASH the password before storing
                $hash = password_hash($password, PASSWORD_DEFAULT);

                mysqli_stmt_bind_param($stmtLogin, "sss", $username, $hash, $role);

                if (!mysqli_stmt_execute($stmtLogin)) {
                    throw new Exception("Login insert failed: " . mysqli_stmt_error($stmtLogin));
                }

                // 2) Insert into profile table (using username as the ID)
                if ($role === 'Student') {
                    $sqlProfile = "INSERT INTO student (student_id, name, email, phone)
                                   VALUES (?, ?, ?, ?)";
                } elseif ($role === 'Security') {
                    $sqlProfile = "INSERT INTO security (staff_id, name, email, phone)
                                   VALUES (?, ?, ?, ?)";
                } else { // Admin
                    $sqlProfile = "INSERT INTO admin (admin_id, name, email, phone)
                                   VALUES (?, ?, ?, ?)";
                }

                $stmtProfile = mysqli_prepare($conn, $sqlProfile);
                if (!$stmtProfile) {
                    throw new Exception("Profile prepare failed: " . mysqli_error($conn));
                }
                mysqli_stmt_bind_param($stmtProfile, "ssss",
                    $username, $name, $email, $phone
                );

                if (!mysqli_stmt_execute($stmtProfile)) {
                    throw new Exception("Profile insert failed: " . mysqli_stmt_error($stmtProfile));
                }

                mysqli_commit($conn);
                header("Location: admin_manage.php?created=1");
                exit;

            } catch (Exception $e) {
                mysqli_rollback($conn);
                $flashError = "Error creating user: " . $e->getMessage();
            }
        }
    }

    /* ---------- UPDATE USER ---------- */
    if (isset($_POST['update_user'])) {

        $oldUsername = trim($_POST['old_username'] ?? '');
        $newUsername = trim($_POST['username'] ?? '');
        $role        = trim($_POST['role_type'] ?? '');
        $name        = trim($_POST['name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');

        if ($oldUsername === '' || $newUsername === '' || $name === '' || $email === '' || $phone === '' || $role === '') {
            $flashError = "Please fill in all fields for update.";
        } else {
            try {
                mysqli_begin_transaction($conn);

                // 1) Update LOGIN table
                $sqlUpLogin = "UPDATE login SET username = ? WHERE username = ?";
                $stmtUpLogin = mysqli_prepare($conn, $sqlUpLogin);
                if (!$stmtUpLogin) {
                    throw new Exception("Login update prepare failed: " . mysqli_error($conn));
                }
                mysqli_stmt_bind_param($stmtUpLogin, "ss", $newUsername, $oldUsername);
                if (!mysqli_stmt_execute($stmtUpLogin)) {
                    throw new Exception("Login update failed: " . mysqli_stmt_error($stmtUpLogin));
                }

                // 2) Update profile table according to role
                if ($role === 'Student') {
                    $sqlProf = "UPDATE student SET student_id = ?, name = ?, email = ?, phone = ?
                                WHERE student_id = ?";
                } elseif ($role === 'Security') {
                    $sqlProf = "UPDATE security SET staff_id = ?, name = ?, email = ?, phone = ?
                                WHERE staff_id = ?";
                } else { // Admin
                    $sqlProf = "UPDATE admin SET admin_id = ?, name = ?, email = ?, phone = ?
                                WHERE admin_id = ?";
                }

                $stmtProf = mysqli_prepare($conn, $sqlProf);
                if (!$stmtProf) {
                    throw new Exception("Profile update prepare failed: " . mysqli_error($conn));
                }
                mysqli_stmt_bind_param($stmtProf, "sssss", $newUsername, $name, $email, $phone, $oldUsername);
                if (!mysqli_stmt_execute($stmtProf)) {
                    throw new Exception("Profile update failed: " . mysqli_stmt_error($stmtProf));
                }

                mysqli_commit($conn);
                header("Location: admin_manage.php?updated=1");
                exit;

            } catch (Exception $e) {
                mysqli_rollback($conn);
                $flashError = "Error updating user: " . $e->getMessage();
            }
        }
    }
}

/* =========================================================
   2. HANDLE DELETE (GET ?delete_username=)
   ========================================================= */
if (isset($_GET['delete_username'])) {
    $deleteUsername = trim($_GET['delete_username']);

    if ($deleteUsername === $_SESSION['username']) {
        $flashError = "You cannot delete your own account.";
    } elseif ($deleteUsername !== '') {
        try {
            mysqli_begin_transaction($conn);
            
            // Get role type first to know which table to delete from
            $sqlGetRole = "SELECT role_type FROM login WHERE username = ?";
            $stmtGetRole = mysqli_prepare($conn, $sqlGetRole);
            mysqli_stmt_bind_param($stmtGetRole, "s", $deleteUsername);
            mysqli_stmt_execute($stmtGetRole);
            $resultRole = mysqli_stmt_get_result($stmtGetRole);
            
            if ($rowRole = mysqli_fetch_assoc($resultRole)) {
                $roleType = $rowRole['role_type'];
                
                // Delete from profile table first (foreign key constraint)
                if ($roleType === 'Student') {
                    $sqlDelProfile = "DELETE FROM student WHERE student_id = ?";
                } elseif ($roleType === 'Security') {
                    $sqlDelProfile = "DELETE FROM security WHERE staff_id = ?";
                } else {
                    $sqlDelProfile = "DELETE FROM admin WHERE admin_id = ?";
                }
                
                $stmtDelProfile = mysqli_prepare($conn, $sqlDelProfile);
                mysqli_stmt_bind_param($stmtDelProfile, "s", $deleteUsername);
                mysqli_stmt_execute($stmtDelProfile);
                
                // Then delete from login table
                $sqlDel = "DELETE FROM login WHERE username = ?";
                $stmtDel = mysqli_prepare($conn, $sqlDel);
                mysqli_stmt_bind_param($stmtDel, "s", $deleteUsername);
                
                if (mysqli_stmt_execute($stmtDel)) {
                    mysqli_commit($conn);
                    header("Location: admin_manage.php?deleted=1");
                    exit;
                } else {
                    throw new Exception("Delete failed: " . mysqli_stmt_error($stmtDel));
                }
            } else {
                throw new Exception("User not found");
            }
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $flashError = "Delete failed: " . $e->getMessage();
        }
    }
}

/* =========================================================
   3. FLASH MESSAGES FROM REDIRECT
   ========================================================= */
if (isset($_GET['created']) && $_GET['created'] == '1') {
    $flashSuccess = "User account has been created successfully.";
}
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $flashSuccess = "User account has been updated successfully.";
}
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $flashSuccess = "User account has been deleted.";
}

/* =========================================================
   4. LOAD USER LIST
   ========================================================= */
$users = [];

$sqlList = "
    SELECT 
        l.username,
        l.role_type,
        COALESCE(s.name, se.name, a.name)   AS full_name,
        COALESCE(s.email, se.email, a.email) AS email,
        COALESCE(s.phone, se.phone, a.phone) AS phone
    FROM login l
    LEFT JOIN student  s  ON s.student_id = l.username
    LEFT JOIN security se ON se.staff_id  = l.username
    LEFT JOIN admin    a  ON a.admin_id   = l.username
    ORDER BY l.username ASC
";
$resultList = mysqli_query($conn, $sqlList);
if ($resultList) {
    while ($row = mysqli_fetch_assoc($resultList)) {
        $users[] = $row;
    }
} else {
    $flashError = "Error loading users: " . mysqli_error($conn);
}

/* =========================================================
   5. IF EDIT REQUEST, LOAD THAT USER FOR MODAL
   ========================================================= */
if (isset($_GET['edit_username'])) {
    $editUsername = trim($_GET['edit_username']);
    if ($editUsername !== '') {
        $sqlEdit = "
            SELECT 
                l.username,
                l.role_type,
                COALESCE(s.name, se.name, a.name)   AS full_name,
                COALESCE(s.email, se.email, a.email) AS email,
                COALESCE(s.phone, se.phone, a.phone) AS phone
            FROM login l
            LEFT JOIN student  s  ON s.student_id = l.username
            LEFT JOIN security se ON se.staff_id  = l.username
            LEFT JOIN admin    a  ON a.admin_id   = l.username
            WHERE l.username = ?
            LIMIT 1
        ";
        $stmtEdit = mysqli_prepare($conn, $sqlEdit);
        if ($stmtEdit) {
            mysqli_stmt_bind_param($stmtEdit, "s", $editUsername);
            mysqli_stmt_execute($stmtEdit);
            $res = mysqli_stmt_get_result($stmtEdit);
            if ($res && $row = mysqli_fetch_assoc($res)) {
                $editUser = $row;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users - FKPark Admin</title>
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
            padding: 16px 16px 24px;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 8px 16px;
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
            gap: 4px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 999px;
            color: #111827;
            text-decoration: none;
            font-size: 14px;
        }

        .nav-link:hover {
            background-color: #f3f4ff;
        }

        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .nav-link.active {
            background-color: #0066ff;
            color: #ffffff;
        }

        .nav-link.active .nav-icon {
            color: #ffffff;
        }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 16px;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #ef4444;
            text-decoration: none;
            font-size: 14px;
        }

        /* Top bar */
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
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
        }

        .user-area {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .user-role {
            font-size: 12px;
            color: #6b7280;
        }

        .user-avatar {
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

        /* Content */
        .content {
            padding: 24px 40px 32px;
        }

        .content-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .content-title {
            font-size: 26px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .content-subtitle {
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary span {
            font-size: 18px;
        }

        .btn-primary:hover {
            background-color: #0050c7;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            margin-bottom: 12px;
        }

        /* Table card */
        .table-card {
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
            vertical-align: middle;
        }

        th {
            font-weight: 600;
            color: #4b5563;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .action-icons {
            display: flex;
            gap: 10px;
            font-size: 16px;
        }

        .action-edit,
        .action-delete {
            text-decoration: none;
            cursor: pointer;
        }

        .action-edit {
            color: #2563eb;
        }

        .action-delete {
            color: #ef4444;
        }

        /* Modal (CSS :target, no JS) */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background-color: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 50;
        }

        .modal-backdrop:target {
            display: flex;
        }

        .modal {
            background-color: #ffffff;
            border-radius: 24px;
            width: 800px;
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
            text-decoration: none;
            color: #6b7280;
        }

        .modal-close:hover {
            color: #111827;
        }

        .modal-body {
            padding: 20px 24px 0;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 14px 24px 18px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px 18px;
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
            box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.18);
        }

        .btn-secondary {
            padding: 8px 18px;
            border-radius: 999px;
            border: 1px solid #d1d5db;
            background-color: #ffffff;
            font-size: 14px;
            text-decoration: none;
            color: #111827;
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

        @media (max-width: 1024px) {
            .layout {
                grid-template-columns: 220px 1fr;
            }
            .content {
                padding: 18px 20px 24px;
            }
        }

        @media (max-width: 768px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none;
            }
            .form-grid {
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
                <img src="images/logo.png" alt="FKPark Logo">
            </div>
            <div class="sidebar-title">FKPark</div>
        </div>

        <nav class="nav">
            <a href="dashboard_admin.php" class="nav-link">
                <span class="nav-icon">☰</span>
                <span>Dashboard</span>
            </a>
            <a href="parking_area.php" class="nav-link">
                <span class="nav-icon">🅿</span>
                <span>Manage Parking</span>
            </a>
            
            <a href="admin_manage.php" class="nav-link active">
                <span class="nav-icon">👥</span>
                <span>Manage Users</span>
            </a>
            <a href="admin_profile.php" class="nav-link">
                <span class="nav-icon">👤</span>
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
        <!-- Top bar -->
        <header class="topbar">
            <div class="user-area">
                <div>
                    <div>Welcome, <?php echo htmlspecialchars($adminName); ?></div>
                    <div class="user-role">Administrator</div>
                </div>
                <div class="user-avatar">
                    <?php echo strtoupper(substr($adminName, 0, 1)); ?>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <div class="content-header">
                <div>
                    <div class="content-title">Manage Users</div>
                    <div class="content-subtitle">View and manage registered users</div>
                </div>
                <a href="#addUserModal" class="btn-primary">
                    <span>＋</span> Add User Account
                </a>
            </div>

            <?php if ($flashSuccess): ?>
                <div class="alert-success"><?php echo htmlspecialchars($flashSuccess); ?></div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="alert-error"><?php echo htmlspecialchars($flashError); ?></div>
            <?php endif; ?>

            <div class="table-card">
                <table>
                    <thead>
                    <tr>
                        <th style="width: 18%">Username</th>
                        <th style="width: 18%">Name</th>
                        <th style="width: 22%">Email</th>
                        <th style="width: 16%">Phone</th>
                        <th style="width: 12%">Role</th>
                        <th style="width: 14%">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['full_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($user['email'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($user['phone'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($user['role_type']); ?></td>
                                <td>
                                    <div class="action-icons">
                                        <a
                                            class="action-edit"
                                            title="Edit"
                                            href="admin_manage.php?edit_username=<?php echo urlencode($user['username']); ?>#editUserModal"
                                        >✏️</a>
                                        <a
                                            class="action-delete"
                                            title="Delete"
                                            href="admin_manage.php?delete_username=<?php echo urlencode($user['username']); ?>"
                                            onclick="return confirm('Are you sure you want to delete this user?');"
                                        >🗑️</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal-backdrop" id="addUserModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Add User Account</div>
            <a href="admin_manage.php" class="modal-close">&times;</a>
        </div>
        <form method="post" action="admin_manage.php#addUserModal">
            <input type="hidden" name="create_user" value="1">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-field">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-input"
                               placeholder="johndoe123" required>
                    </div>
                    <div class="form-field">
                        <label for="fullName">Name</label>
                        <input type="text" id="fullName" name="name" class="form-input"
                               placeholder="John Doe" required>
                    </div>
                    <div class="form-field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" class="form-input"
                               placeholder="john.doe@student.fk.edu" required>
                    </div>
                    <div class="form-field">
                        <label for="phone">Phone</label>
                        <input type="text" id="phone" name="phone" class="form-input"
                               placeholder="+60123456789" required>
                    </div>
                    <div class="form-field">
                        <label for="role">Role</label>
                        <select id="role" name="role" class="form-input" required>
                            <option value="Student">Student</option>
                            <option value="Security">Security Staff</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password"
                               class="form-input" required>
                    </div>
                    <div class="form-field">
                        <label for="confirmPassword">Confirm Password</label>
                        <input type="password" id="confirmPassword" name="confirm_password"
                               class="form-input" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="admin_manage.php" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-modal-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal (only rendered when editUser is set) -->
<?php if ($editUser): ?>
<div class="modal-backdrop" id="editUserModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Edit User Account</div>
            <a href="admin_manage.php" class="modal-close">&times;</a>
        </div>
        <form method="post" action="admin_manage.php?edit_username=<?php echo urlencode($editUser['username']); ?>#editUserModal">
            <input type="hidden" name="update_user" value="1">
            <input type="hidden" name="old_username" value="<?php echo htmlspecialchars($editUser['username']); ?>">
            <input type="hidden" name="role_type" value="<?php echo htmlspecialchars($editUser['role_type']); ?>">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-field">
                        <label for="editUsername">Username</label>
                        <input type="text" id="editUsername" name="username" class="form-input"
                               value="<?php echo htmlspecialchars($editUser['username']); ?>" required>
                    </div>
                    <div class="form-field">
                        <label for="editName">Name</label>
                        <input type="text" id="editName" name="name" class="form-input"
                               value="<?php echo htmlspecialchars($editUser['full_name']); ?>" required>
                    </div>
                    <div class="form-field">
                        <label for="editEmail">Email</label>
                        <input type="email" id="editEmail" name="email" class="form-input"
                               value="<?php echo htmlspecialchars($editUser['email']); ?>" required>
                    </div>
                    <div class="form-field">
                        <label for="editPhone">Phone</label>
                        <input type="text" id="editPhone" name="phone" class="form-input"
                               value="<?php echo htmlspecialchars($editUser['phone']); ?>" required>
                    </div>
                    <div class="form-field">
                        <label>Role</label>
                        <input type="text" class="form-input"
                               value="<?php echo htmlspecialchars($editUser['role_type']); ?>"
                               readonly>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="admin_manage.php" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-modal-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
</body>
</html>