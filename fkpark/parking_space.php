<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SET MALAYSIA TIMEZONE
date_default_timezone_set('Asia/Kuala_Lumpur');

session_start();
require 'db.php';
// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Only Admin can access
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?timeout=1");
    exit;
}

$adminName = $_SESSION['username'] ?? 'Admin User';

// Get area_id from URL
$area_id = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;

if ($area_id <= 0) {
    header("Location: parking_area.php");
    exit;
}

// Get area details
$areaInfo = null;
$sqlArea = "SELECT Area_ID, area_name, area_type FROM parking_area WHERE Area_ID = ?";
$stmtArea = mysqli_prepare($conn, $sqlArea);
mysqli_stmt_bind_param($stmtArea, "i", $area_id);
mysqli_stmt_execute($stmtArea);
$resultArea = mysqli_stmt_get_result($stmtArea);
if ($row = mysqli_fetch_assoc($resultArea)) {
    $areaInfo = $row;
} else {
    header("Location: parking_area.php");
    exit;
}

// AUTO-GENERATE QR CODES for spaces that don't have them
$sqlCheckQR = "SELECT space_id FROM parking_space WHERE area_ID = ? AND (QrCode IS NULL OR QrCode = '')";
$stmtCheckQR = mysqli_prepare($conn, $sqlCheckQR);
mysqli_stmt_bind_param($stmtCheckQR, "i", $area_id);
mysqli_stmt_execute($stmtCheckQR);
$resultCheckQR = mysqli_stmt_get_result($stmtCheckQR);

while ($row = mysqli_fetch_assoc($resultCheckQR)) {
    $space_id = $row['space_id'];
    $qrData = base64_encode("FKPARK_SPACE_" . $space_id);
    
    $sqlUpdateQR = "UPDATE parking_space SET QrCode = ? WHERE space_id = ?";
    $stmtUpdateQR = mysqli_prepare($conn, $sqlUpdateQR);
    mysqli_stmt_bind_param($stmtUpdateQR, "si", $qrData, $space_id);
    mysqli_stmt_execute($stmtUpdateQR);
}

// Check area status
$areaStatus = 'open';
$sqlCheckClosure = "SELECT closure_ID FROM area_closure 
                    WHERE area_ID = ? 
                    AND (closed_to IS NULL OR closed_to >= NOW())
                    AND closed_from <= NOW()
                    LIMIT 1";
$stmtCheck = mysqli_prepare($conn, $sqlCheckClosure);
mysqli_stmt_bind_param($stmtCheck, "i", $area_id);
mysqli_stmt_execute($stmtCheck);
$resultCheck = mysqli_stmt_get_result($stmtCheck);
if (mysqli_fetch_assoc($resultCheck)) {
    $areaStatus = 'closed';
}

// Count total capacity
$totalCapacity = 0;
$sqlCapacity = "SELECT COUNT(*) as total FROM parking_space WHERE area_ID = ?";
$stmtCapacity = mysqli_prepare($conn, $sqlCapacity);
mysqli_stmt_bind_param($stmtCapacity, "i", $area_id);
mysqli_stmt_execute($stmtCapacity);
$resultCapacity = mysqli_stmt_get_result($stmtCapacity);
if ($row = mysqli_fetch_assoc($resultCapacity)) {
    $totalCapacity = $row['total'];
}

$flash = '';

// REMOVED: Handle TOGGLE status functionality has been removed

// Flash messages
if (isset($_GET['toggled'])) {
    $flash = 'Space status updated.';
}

// Get parking spaces
$filterStatus = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$spaces = [];

$sqlSpaces = "SELECT space_id, space_num, status, QrCode FROM parking_space WHERE area_ID = ?";
if ($filterStatus !== 'all') {
    $sqlSpaces .= " AND status = ?";
}
$sqlSpaces .= " ORDER BY space_num ASC";

$stmtSpaces = mysqli_prepare($conn, $sqlSpaces);
if ($filterStatus !== 'all') {
    mysqli_stmt_bind_param($stmtSpaces, "is", $area_id, $filterStatus);
} else {
    mysqli_stmt_bind_param($stmtSpaces, "i", $area_id);
}
mysqli_stmt_execute($stmtSpaces);
$resultSpaces = mysqli_stmt_get_result($stmtSpaces);
while ($row = mysqli_fetch_assoc($resultSpaces)) {
    $spaces[] = $row;
}

// Summary stats
$totalAreas = 0;
$totalSpaces = 0;
$closedAreas = 0;
$availableSpaces = 0;

$sqlTotalAreas = "SELECT COUNT(*) as total FROM parking_area";
$resultAreas = mysqli_query($conn, $sqlTotalAreas);
if ($resultAreas) {
    $row = mysqli_fetch_assoc($resultAreas);
    $totalAreas = $row['total'];
}

$sqlClosedAreas = "SELECT COUNT(DISTINCT area_ID) as total 
                   FROM area_closure 
                   WHERE (closed_to IS NULL OR closed_to >= NOW()) 
                   AND closed_from <= NOW()";
$resultClosed = mysqli_query($conn, $sqlClosedAreas);
if ($resultClosed) {
    $row = mysqli_fetch_assoc($resultClosed);
    $closedAreas = $row['total'];
}

$sqlTotalSpaces = "SELECT COUNT(*) as total FROM parking_space";
$resultTotalSpaces = mysqli_query($conn, $sqlTotalSpaces);
if ($resultTotalSpaces) {
    $row = mysqli_fetch_assoc($resultTotalSpaces);
    $totalSpaces = $row['total'];
}

$sqlAvailable = "SELECT COUNT(*) as total 
                 FROM parking_space ps
                 WHERE ps.status = 'Available'
                 AND NOT EXISTS (
                     SELECT 1 FROM booking b
                     WHERE b.space_id = ps.space_id
                     AND b.date = CURDATE()
                     AND b.status != 'Cancelled'
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM area_closure ac
                     WHERE ac.area_ID = ps.area_ID
                     AND ac.closed_from <= NOW()
                     AND (ac.closed_to IS NULL OR ac.closed_to >= NOW())
                 )";
$resultAvailable = mysqli_query($conn, $sqlAvailable);
if ($resultAvailable) {
    $row = mysqli_fetch_assoc($resultAvailable);
    $availableSpaces = $row['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parking Spaces - <?php echo htmlspecialchars($areaInfo['area_name']); ?> - FKPark</title>
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
            object-fit: contain;
        }

        .sidebar-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .sidebar-subtitle {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }

        .nav {
            margin-top: 24px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .nav-link {
            display: block;
            padding: 12px 16px;
            border-radius: 8px;
            color: #374151;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .nav-link:hover {
            background-color: #f3f4f6;
        }

        .nav-link.active {
            background-color: #0066ff;
            color: #ffffff;
        }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 16px;
        }

        .back-link {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6b7280;
            text-decoration: none;
            font-size: 14px;
            padding: 8px 12px;
        }

        .back-link:hover {
            color: #374151;
        }

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

        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background-color: #ffffff;
            min-width: 300px;
            margin-right: auto;
        }

        .search-box input {
            border: none;
            background: transparent;
            outline: none;
            flex: 1;
            font-size: 14px;
        }

        .sign-out-link {
            color: #0066ff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .sign-out-link:hover {
            text-decoration: underline;
        }

        .content {
            padding: 24px 40px 32px;
        }

        .breadcrumb {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 26px;
            font-weight: 600;
            margin: 0;
        }

        .sync-info {
            font-size: 13px;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sync-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #10b981;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .alert {
            background-color: #dcfce7;
            color: #166534;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
        }

        .stat-label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #111827;
        }

        .stat-sublabel {
            font-size: 12px;
            color: #9ca3af;
        }

        .spaces-section {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .section-meta {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            gap: 12px;
        }

        .filter-select {
            padding: 10px 16px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            font-size: 14px;
            outline: none;
            min-width: 150px;
            background-color: #ffffff;
        }

        .toolbar-right {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: #0066ff;
            color: #ffffff;
        }

        .btn-primary:hover {
            background-color: #163a6f;
        }

        .space-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 450px;
            overflow-y: auto;
            margin-bottom: 20px;
        }

        .space-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background-color: #ffffff;
            transition: all 0.3s ease;
        }

        .space-item:hover {
            background-color: #f9fafb;
        }

        .space-item.updating {
            animation: highlight 0.5s ease;
        }

        @keyframes highlight {
            0%, 100% { background-color: #ffffff; }
            50% { background-color: #fef3c7; }
        }

        .space-name {
            font-weight: 600;
            min-width: 80px;
            color: #111827;
        }

        .space-status {
            flex: 1;
            font-size: 14px;
            color: #6b7280;
        }

        .space-status.available {
            color: #059669;
            font-weight: 500;
        }

        .space-status.occupied {
            color: #dc2626;
            font-weight: 500;
        }

        .space-actions {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .action-btn.qr {
            background-color: #ffffff;
            color: #0066ff;
            border: 1px solid #e5e7eb;
        }

        .action-btn.qr:hover {
            background-color: #f9fafb;
        }

        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none;
            }
            .stats-row {
                grid-template-columns: 1fr;
            }
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="images/logo.png" alt="FKPark Logo">
            </div>
            <div>
                <div class="sidebar-title">FKPark</div>
                <div class="sidebar-subtitle">Admin – Manage Parking</div>
            </div>
        </div>

        <nav class="nav">
            <a href="parking_area.php" class="nav-link">
                📆 Parking Area
            </a>
            <a href="parking_space_list.php" class="nav-link active">
                🚧 Parking Spaces
            </a>
            <a href="area_closure.php" class="nav-link">
               ⛔ Area Closure
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="dashboard_admin.php" class="back-link">
                ← Back to Dashboard
            </a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="search-box">
                <span>🔍</span>
                <input type="text" placeholder="Search areas or spaces..." id="searchInput">
            </div>
            <a href="logout.php" class="sign-out-link">Log out</a>
        </header>

        <main class="content">
            <div class="breadcrumb">
                Admin Dashboard » Manage Parking » <?php echo htmlspecialchars($areaInfo['area_name']); ?>
            </div>

            <div class="page-header">
                <h1 class="page-title">Manage Parking Workspace</h1>
                <div class="sync-info">
                    <span class="sync-indicator"></span>
                    <span id="lastSync">Last sync: <?php echo date('n/j/Y, g:i:s A'); ?></span>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>

            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Total Areas</div>
                    <div class="stat-value" id="totalAreas"><?php echo $totalAreas; ?></div>
                    <div class="stat-sublabel">A1–A4 (staff), B1–B3 (student)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Spaces</div>
                    <div class="stat-value" id="totalSpaces"><?php echo $totalSpaces; ?></div>
                    <div class="stat-sublabel">Total capacity</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Closed Areas</div>
                    <div class="stat-value" id="closedAreas"><?php echo $closedAreas; ?></div>
                    <div class="stat-sublabel">Under maintenance</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Available Spaces (Now)</div>
                    <div class="stat-value" id="availableSpaces"><?php echo $availableSpaces; ?></div>
                    <div class="stat-sublabel">Currently free</div>
                </div>
            </div>

            <div class="spaces-section">
                <div class="section-header">
                    <div>
                        <div class="section-title">
                            Parking Spaces – <?php echo htmlspecialchars($areaInfo['area_name']); ?>
                        </div>
                        <div class="section-meta">
                            Capacity: <?php echo $totalCapacity; ?> | Status: <?php echo $areaStatus; ?>
                        </div>
                    </div>
                </div>

                <form method="post" id="spaceForm">
                    <div class="toolbar">
                        <select class="filter-select" name="filter" id="filterSelect" onchange="window.location.href='parking_space.php?area_id=<?php echo $area_id; ?>&filter='+this.value">
                            <option value="all" <?php echo ($filterStatus === 'all') ? 'selected' : ''; ?>>All statuses</option>
                            <option value="Available" <?php echo ($filterStatus === 'Available') ? 'selected' : ''; ?>>Available</option>
                            <option value="Occupied" <?php echo ($filterStatus === 'Occupied') ? 'selected' : ''; ?>>Occupied</option>
                        </select>
                        <div class="toolbar-right">
                            <button type="button" class="btn btn-primary" onclick="manualRefresh()">🔄 Refresh </button>
                        </div>
                    </div>

                    <div class="space-list" id="spaceList">
                        <?php if (empty($spaces)): ?>
                            <div style="text-align: center; color: #6b7280; padding: 40px;">
                                No parking spaces found in this area.
                            </div>
                        <?php else: ?>
                            <?php foreach ($spaces as $space): ?>
                                <div class="space-item" data-space-id="<?php echo $space['space_id']; ?>">
                                    <div class="space-name"><?php echo htmlspecialchars($space['space_num']); ?></div>
                                    <div class="space-status <?php echo strtolower($space['status']); ?>"><?php echo strtolower(htmlspecialchars($space['status'])); ?></div>
                                    <div class="space-actions">
                                        <a href="view_space_qr.php?space_id=<?php echo $space['space_id']; ?>" class="action-btn qr" target="_blank">View QR</a>
                                        <!-- REMOVED: Toggle button has been removed -->
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<script>
const AREA_ID = <?php echo $area_id; ?>;
const FILTER_STATUS = '<?php echo $filterStatus; ?>';
let isUpdating = false;
let updateCount = 0;

// Auto-refresh BOTH stats AND spaces every 3 seconds
setInterval(function() {
    autoRefreshStats();
    autoRefreshSpaces();
}, 3000);

// Also refresh when page becomes visible
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        console.log('Page became visible, refreshing...');
        autoRefreshStats();
        autoRefreshSpaces();
    }
});

// Refresh STATS at the top
function autoRefreshStats() {
    fetch('get_stats_update.php?t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStats(data.stats);
                if (data.server_time) {
                    updateSyncTime(data.server_time);
                }
            }
        })
        .catch(error => console.error('Stats refresh error:', error));
}

// Refresh SPACES list (existing function)
function autoRefreshSpaces() {
    if (isUpdating) return;
    
    isUpdating = true;
    updateCount++;
    
    fetch('get_spaces_update.php?area_id=' + AREA_ID + '&filter=' + FILTER_STATUS + '&t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateSpacesList(data.spaces);
            }
            isUpdating = false;
        })
        .catch(error => {
            console.error('Spaces refresh error:', error);
            isUpdating = false;
        });
}

function updateStats(stats) {
    const elements = {
        totalAreas: document.getElementById('totalAreas'),
        totalSpaces: document.getElementById('totalSpaces'),
        closedAreas: document.getElementById('closedAreas'),
        availableSpaces: document.getElementById('availableSpaces')
    };
    
    Object.keys(elements).forEach(key => {
        if (elements[key] && stats[key] !== undefined) {
            const oldValue = elements[key].textContent;
            const newValue = stats[key].toString();
            
            if (oldValue !== newValue) {
                console.log(`${key} changed: ${oldValue} → ${newValue}`);
                elements[key].textContent = newValue;
                
                // Flash animation
                elements[key].style.transition = 'all 0.3s ease';
                elements[key].style.backgroundColor = '#fef3c7';
                setTimeout(() => {
                    elements[key].style.backgroundColor = '';
                }, 500);
            }
        }
    });
}

function updateSpacesList(spaces) {
    let changesDetected = 0;
    
    spaces.forEach(space => {
        const spaceItem = document.querySelector('[data-space-id="' + space.space_id + '"]');
        
        if (spaceItem) {
            const statusDiv = spaceItem.querySelector('.space-status');
            const oldStatus = statusDiv.textContent.trim().toLowerCase();
            const newStatus = space.status.toLowerCase();
            
            if (oldStatus !== newStatus) {
                changesDetected++;
                console.log(`🔄 Space ${space.space_num}: ${oldStatus} → ${newStatus}`);
                
                spaceItem.classList.add('updating');
                setTimeout(() => spaceItem.classList.remove('updating'), 500);
                
                statusDiv.textContent = newStatus;
                statusDiv.className = 'space-status ' + newStatus;
            }
        }
    });
    
    if (changesDetected > 0) {
        console.log(`✓ ${changesDetected} space(s) updated`);
    }
}

function updateSyncTime(serverTime) {
    const syncElement = document.getElementById('lastSync');
    if (syncElement) {
        syncElement.textContent = 'Last sync: ' + serverTime;
    }
}

function manualRefresh() {
    console.log('Manual refresh triggered');
    autoRefreshStats();
    autoRefreshSpaces();
}

// Search functionality
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const spaceItems = document.querySelectorAll('.space-item');
        
        spaceItems.forEach(item => {
            const text = item.textContent.toLowerCase();
            item.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });
}

console.log('Parking Space Auto-Refresh System Initialized');
console.log('Area ID:', AREA_ID);
console.log('Filter:', FILTER_STATUS);
console.log('Refresh interval: 3 seconds');

// Run first refresh after 1 second
setTimeout(function() {
    autoRefreshStats();
    autoRefreshSpaces();
}, 1000);
</script>
</body>
</html>