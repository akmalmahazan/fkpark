<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Only Admin can access
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?timeout=1");
    exit;
}

$space_id = isset($_GET['space_id']) ? (int)$_GET['space_id'] : 0;

if ($space_id <= 0) {
    die("Invalid space ID");
}

// Get space details
$sqlSpace = "
    SELECT ps.space_id, ps.space_num, ps.status, ps.QrCode,
           pa.area_name, pa.area_type
    FROM parking_space ps
    JOIN parking_area pa ON pa.Area_ID = ps.area_ID
    WHERE ps.space_id = ?
";

$stmtSpace = mysqli_prepare($conn, $sqlSpace);
mysqli_stmt_bind_param($stmtSpace, "i", $space_id);
mysqli_stmt_execute($stmtSpace);
$resultSpace = mysqli_stmt_get_result($stmtSpace);
$space = mysqli_fetch_assoc($resultSpace);

if (!$space) {
    die("Space not found");
}

// Generate QR URL - this will be the URL students scan
$baseURL = "https://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
$scanURL = $baseURL . "/student_scan_space.php?qr=" . urlencode($space['QrCode']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR Code - <?php echo htmlspecialchars($space['space_num']); ?> - FKPark</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 500px;
        }

        .card {
            background-color: #ffffff;
            border-radius: 28px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
        }

        .header {
            margin-bottom: 24px;
        }

        .logo {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 36px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px;
            color: #111827;
        }

        .header p {
            font-size: 14px;
            color: #6b7280;
            margin: 0;
        }

        .info-badge {
            background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 28px;
        }

        .info-badge h3 {
            margin: 0 0 8px;
            font-size: 24px;
            font-weight: 700;
            color: #1e40af;
        }

        .info-badge p {
            margin: 4px 0;
            font-size: 14px;
            color: #4b5563;
        }

        .qr-container {
            background-color: #ffffff;
            border: 3px solid #e5e7eb;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            display: inline-block;
        }

        .qr-container img {
            width: 280px;
            height: 280px;
            display: block;
        }

        .instructions {
            background-color: #f9fafb;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 20px;
            text-align: left;
        }

        .instructions h4 {
            margin: 0 0 12px;
            font-size: 16px;
            font-weight: 600;
            color: #111827;
        }

        .instructions ol {
            margin: 0;
            padding-left: 20px;
        }

        .instructions li {
            margin-bottom: 8px;
            font-size: 14px;
            color: #4b5563;
        }

        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .btn {
            flex: 1;
            padding: 12px;
            border-radius: 14px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background-color: #f3f4f6;
            color: #374151;
        }

        .btn-secondary:hover {
            background-color: #e5e7eb;
        }

        @media print {
            body {
                background: white;
            }
            .btn-group {
                display: none;
            }
            .instructions {
                display: none;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header">
            <div class="logo">🅿️</div>
            <h1>Parking Space QR Code</h1>
            <p>Admin View</p>
        </div>

        <div class="info-badge">
            <h3><?php echo htmlspecialchars($space['space_num']); ?></h3>
            <p><?php echo htmlspecialchars($space['area_name']); ?> • <?php echo htmlspecialchars($space['area_type']); ?></p>
            <p style="margin-top: 8px;">
                <strong>Status:</strong> 
                <span style="color: <?php echo $space['status'] === 'Available' ? '#16a34a' : '#dc2626'; ?>">
                    <?php echo htmlspecialchars($space['status']); ?>
                </span>
            </p>
        </div>

        <div class="qr-container">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=<?php echo urlencode($scanURL); ?>" 
                 alt="QR Code for Space <?php echo htmlspecialchars($space['space_num']); ?>">
        </div>

        <div class="instructions">
            <h4>How Students Use This QR Code:</h4>
            <ol>
                <li>Student makes a booking for this space</li>
                <li>Student arrives at the parking space</li>
                <li>Student scans this QR code with their phone</li>
                <li>System verifies their booking and starts parking session</li>
            </ol>
        </div>

        <div class="btn-group">
            <button class="btn btn-primary" onclick="window.print()">
                🖨️ Print QR Code
            </button>
            <button class="btn btn-secondary" onclick="window.close()">
                ← Back
            </button>
        </div>
    </div>
</div>
</body>
</html>