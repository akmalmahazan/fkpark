<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Prevent using Back to see protected pages after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$error          = "";
$timeoutMessage = "";

/*
 * SESSION HANDLING LOGIC
 */

// Case 1: explicit timeout (from logout or a protected page)
if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $_SESSION = [];
    session_unset();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    session_start();

    $timeoutMessage = "Your session has timed out. Please log in again.";
} else {
    // Case 2: user came here while still logged in
    if (isset($_SESSION['username']) || isset($_SESSION['role'])) {
        $_SESSION = [];
        session_unset();

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        session_start();
    }
}

// ===================== LOGIN HANDLING ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = trim($_POST['role'] ?? '');

    if ($username === '' || $password === '' || $role === '') {
        $error = "Please fill in all fields.";
    } else {
        $sql = "SELECT username, password, role_type
                FROM login
                WHERE username = ? AND role_type = ?";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ss", $username, $role);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($row = mysqli_fetch_assoc($result)) {

                $stored  = $row['password'];
                $loginOk = false;

                // CASE 1: password already hashed
                if (strpos($stored, '$2y$') === 0) {
                    if (password_verify($password, $stored)) {
                        $loginOk = true;
                    }
                } else {
                    // CASE 2: old plain-text password in DB
                    if (hash_equals($stored, $password)) {
                        $loginOk = true;

                        // upgrade the stored password to a hash
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $sqlUpdate = "UPDATE login SET password = ? WHERE username = ?";
                        $stmtUpdate = mysqli_prepare($conn, $sqlUpdate);
                        if ($stmtUpdate) {
                            mysqli_stmt_bind_param($stmtUpdate, "ss", $newHash, $row['username']);
                            mysqli_stmt_execute($stmtUpdate);
                        }
                    }
                }

                if ($loginOk) {
                    session_regenerate_id(true);

                    $_SESSION['username']   = $row['username'];
                    $_SESSION['role']       = $row['role_type'];
                    $_SESSION['login_time'] = time();

                    if ($row['role_type'] === 'Admin') {
                        header("Location: dashboard_admin.php");
                    } elseif ($row['role_type'] === 'Security') {
                        header("Location: dashboard_security.php");
                    } else {
                        header("Location: dashboard_stud.php");
                    }
                    exit;
                } else {
                    $error = "Invalid password.";
                }
            } else {
                $error = "User not found for this role.";
            }
        } else {
            $error = "Database error: " . mysqli_error($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>FKPark Login</title>
    <script>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
                window.location.reload();
            }
        });

        if (performance.navigation && performance.navigation.type === 2) {
            window.location.reload();
        }
    </script>
    <style>
        * {
            box-sizing: border-box;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background-image: url("images/fk.png");
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
        }

        .overlay {
            position: fixed;
            inset: 0;
            background: linear-gradient(
                to right,
                rgba(15, 23, 42, 0.7),
                rgba(15, 23, 42, 0.35)
            );
        }

        .popup-container {
            position: fixed;
            inset: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .card-wrapper {
            width: 100%;
            max-width: 460px;
            background: rgba(255, 255, 255, 0.96);
            border-radius: 24px;
            padding: 26px 32px 24px;
            box-shadow: 0 20px 55px rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
        }

        .branding {
            text-align: center;
            margin-bottom: 18px;
        }

        .logo-box {
            width: 110px;
            margin: 0 auto 6px;
        }

        .logo-img {
            max-width: 100%;
            height: auto;
        }

        .system-name {
            font-size: 20px;
            color: #0066ff;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .subtitle {
            font-size: 13px;
            color: #4b5563;
        }

        .field {
            margin-bottom: 16px;
        }

        .field-label {
            font-size: 14px;
            margin-bottom: 6px;
            color: #111827;
        }

        .input, .select {
            width: 100%;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            font-size: 14px;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            background-color: #ffffff;
        }

        .input:focus, .select:focus {
            border-color: #0066ff;
            box-shadow: 0 0 0 1px rgba(0, 102, 255, 0.18);
        }

        .btn {
            width: 100%;
            border: none;
            border-radius: 999px;
            background-color: #0066ff;
            color: #ffffff;
            padding: 11px 0;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 6px;
        }

        .btn:hover {
            background-color: #0050c7;
        }

        .link {
            margin-top: 14px;
            font-size: 13px;
            text-align: center;
        }

        .link a {
            color: #0066ff;
            text-decoration: none;
        }

        .error, .timeout {
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
            margin-bottom: 12px;
            text-align: center;
        }

        .error {
            color: #b91c1c;
            background-color: #fee2e2;
        }

        .timeout {
            color: #92400e;
            background-color: #fef3c7;
        }

        @media (max-width: 600px) {
            .card-wrapper {
                padding: 22px 20px 20px;
                border-radius: 20px;
            }
        }
    </style>
</head>
<body>
<div class="overlay"></div>

<div class="popup-container">
    <div class="card-wrapper">
        <div class="branding">
            <div class="logo-box">
                <img src="images/logo-removebg.png" alt="FKPark Logo" class="logo-img">
            </div>
            <div class="system-name">FKPark</div>
            <div class="subtitle">Smart Parking Reservation System</div>
        </div>

        <?php if ($timeoutMessage): ?>
            <div class="timeout"><?php echo htmlspecialchars($timeoutMessage); ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="field">
                <div class="field-label">Username</div>
                <input class="input" type="text" name="username"
                       placeholder="Enter your email or username" required>
            </div>

            <div class="field">
                <div class="field-label">Password</div>
                <input class="input" type="password" name="password"
                       placeholder="Enter your password" required>
            </div>

            <div class="field">
                <div class="field-label">Role</div>
                <select class="select" name="role" required>
                    <option value="Student">Student</option>
                    <option value="Admin">Admin</option>
                    <option value="Security">Security Staff</option>
                </select>
            </div>

            <button type="submit" class="btn">Login</button>

            <div class="link">
                <a href="#">Forgot password?</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>