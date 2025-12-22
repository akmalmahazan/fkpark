<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// prevent caching so Back/Forward cache won't serve stale content
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Only Admin can access (using username instead of login_id)
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?timeout=1");
    exit;
}

$adminUsername = $_SESSION['username'];
$adminName = $adminUsername; // Default to username

// Get admin name from database
$sqlAdmin = "SELECT name FROM admin WHERE admin_id = ?";
$stmtAdmin = mysqli_prepare($conn, $sqlAdmin);
if ($stmtAdmin) {
    mysqli_stmt_bind_param($stmtAdmin, "s", $adminUsername);
    mysqli_stmt_execute($stmtAdmin);
    $result = mysqli_stmt_get_result($stmtAdmin);
    if ($row = mysqli_fetch_assoc($result)) {
        $adminName = $row['name'];
    }
}

// Count statistics from database
$totalSpaces = 0;
$activeBookings = 0;
$registeredUsers = 0;

// Count total parking spaces
$sqlSpaces = "SELECT COUNT(*) as count FROM parking_space";
$resultSpaces = mysqli_query($conn, $sqlSpaces);
if ($resultSpaces && $row = mysqli_fetch_assoc($resultSpaces)) {
    $totalSpaces = (int)$row['count'];
}

// Count active bookings
$sqlBookings = "SELECT COUNT(*) as count FROM booking WHERE status = 'Active'";
$resultBookings = mysqli_query($conn, $sqlBookings);
if ($resultBookings && $row = mysqli_fetch_assoc($resultBookings)) {
    $activeBookings = (int)$row['count'];
}

// Count registered users (students, security, admin)
$sqlUsers = "SELECT COUNT(*) as count FROM login";
$resultUsers = mysqli_query($conn, $sqlUsers);
if ($resultUsers && $row = mysqli_fetch_assoc($resultUsers)) {
    $registeredUsers = (int)$row['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - FKPark</title>

    <script>
        // Minimal session check using check_session.php
        function checkSession() {
            fetch('check_session.php', { cache: 'no-store' })
                .then(response => response.json())
                .then(data => {
                    if (!data.valid) {
                        window.location.href = 'login.php?timeout=1';
                    }
                })
                .catch(err => {
                    console.log('Session check failed', err);
                });
        }

        window.addEventListener('pageshow', function (event) {
            if (
                event.persisted ||
                (performance.getEntriesByType("navigation")[0]?.type === "back_forward")
            ) {
                checkSession();
            }
        });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                checkSession();
            }
        });

        document.addEventListener('DOMContentLoaded', checkSession);
    </script>

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

        /* Main / topbar */
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

        .content {
            padding: 24px 40px 32px;
        }

        /* Dashboard cards */
        .hero-card {
            background-color: #0066ff;
            color: #ffffff;
            border-radius: 24px;
            padding: 22px 28px;
            margin-bottom: 22px;
            box-shadow: 0 18px 40px rgba(37, 99, 235, 0.6);
        }

        .hero-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .hero-text {
            font-size: 15px;
            opacity: 0.95;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background-color: #ffffff;
            border-radius: 24px;
            padding: 18px 22px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.08);
        }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 600;
        }

        .actions-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .action-card {
            background-color: #ffffff;
            border-radius: 24px;
            padding: 18px 22px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.08);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .action-icon {
            width: 36px;
            height: 36px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 10px;
        }

        .action-icon.blue {
            background-color: #e0edff;
        }

        .action-icon.purple {
            background-color: #f3e8ff;
        }

        .action-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .action-text {
            font-size: 13px;
            color: #6b7280;
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
            .stats-row,
            .actions-row {
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
            <a href="dashboard_admin.php" class="nav-link active">
                <span class="nav-icon">☰</span>
                <span>Dashboard</span>
            </a>
            <a href="parking_area.php" class="nav-link">
                <span class="nav-icon">🅿</span>
                <span>Manage Parking</span>
            </a>
            <a href="admin_manage.php" class="nav-link">
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
            <div class="hero-card">
                <div class="hero-title">Admin Dashboard</div>
                <div class="hero-text">
                    Welcome back, <?php echo htmlspecialchars($adminName); ?>! Manage the parking system from here.
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Total Spaces</div>
                    <div class="stat-value"><?php echo $totalSpaces; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Bookings</div>
                    <div class="stat-value"><?php echo $activeBookings; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Registered Users</div>
                    <div class="stat-value"><?php echo $registeredUsers; ?></div>
                </div>
            </div>

            <div class="actions-row">
                <a href="parking_area.php" class="action-card">
                    <div class="action-icon blue">🅿</div>
                    <div class="action-title">Manage Parking</div>
                    <div class="action-text">Add, edit and manage parking areas and spaces.</div>
                </a>
                <a href="admin_manage.php" class="action-card">
                    <div class="action-icon purple">👥</div>
                    <div class="action-title">Manage Users</div>
                    <div class="action-text">View and manage registered users.</div>
                </a>
                <a href="admin_profile.php" class="action-card">
                    <div class="action-icon blue">👤</div>
                    <div class="action-title">My Profile</div>
                    <div class="action-text">Update your personal info and password.</div>
                </a>
 
            </div>
        </main>
    </div>
</div>
</body>
</html>