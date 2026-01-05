<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Security: Admin only - FIXED: Changed from login_id to username
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?timeout=1");
    exit;
}

$adminName = $_SESSION['username'] ?? 'Admin';

// ===== SUMMARY STATS =====
$totalAreas = 0;
$totalSpaces = 0;
$closedAreas = 0;
$availableSpaces = 0;

// Total areas
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM parking_area");
$totalAreas = mysqli_fetch_assoc($res)['total'] ?? 0;

// Total spaces
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM parking_space");
$totalSpaces = mysqli_fetch_assoc($res)['total'] ?? 0;

// Closed areas - Fixed column name
$res = mysqli_query($conn, "
    SELECT COUNT(DISTINCT area_ID) AS total
    FROM area_closure
    WHERE closed_from <= NOW()
    AND (closed_to IS NULL OR closed_to >= NOW())
");
$closedAreas = mysqli_fetch_assoc($res)['total'] ?? 0;

// Available spaces - FIXED: Count spaces not in closed areas and truly available
$res = mysqli_query($conn, "
    SELECT COUNT(*) AS total
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
    )
");
$availableSpaces = mysqli_fetch_assoc($res)['total'] ?? 0;

// ===== GET PARKING AREAS WITH SPACE COUNTS - Fixed column name & closure check =====
$areas = [];
$sqlAreas = "
    SELECT 
        pa.Area_ID,
        pa.area_name,
        pa.area_type,
        COUNT(ps.space_id) as total_spaces,
        SUM(CASE 
            WHEN ps.status = 'Available' 
            AND NOT EXISTS (
                SELECT 1 FROM booking b
                WHERE b.space_id = ps.space_id
                AND b.date = CURDATE()
                AND b.status != 'Cancelled'
            )
            AND NOT EXISTS (
                SELECT 1 FROM area_closure ac2
                WHERE ac2.area_ID = ps.area_ID
                AND ac2.closed_from <= NOW()
                AND (ac2.closed_to IS NULL OR ac2.closed_to >= NOW())
            )
            THEN 1 
            ELSE 0 
        END) as available_spaces,
        CASE 
            WHEN EXISTS (
                SELECT 1 FROM area_closure ac
                WHERE ac.area_ID = pa.Area_ID
                AND ac.closed_from <= NOW()
                AND (ac.closed_to IS NULL OR ac.closed_to >= NOW())
            ) THEN 'closed'
            ELSE 'open'
        END AS status
    FROM parking_area pa
    LEFT JOIN parking_space ps ON ps.area_ID = pa.Area_ID
    GROUP BY pa.Area_ID, pa.area_name, pa.area_type
    ORDER BY pa.area_name ASC
";

$result = mysqli_query($conn, $sqlAreas);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $areas[] = $row;
    }
} else {
    echo "Error: " . mysqli_error($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parking Spaces - FKPark</title>


    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">


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

        /* Main */
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

        /* Content */
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
        }

        /* Stats cards */
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

        /* Areas grid */
        .areas-section {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 20px;
        }

        .section-subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-top: -12px;
            margin-bottom: 20px;
        }

        .areas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
        }

        .area-card {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .area-card:hover {
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
            transform: translateY(-2px);
        }

        .area-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
        }

        .area-name {
            font-size: 20px;
            font-weight: 600;
            color: #111827;
        }

        .area-type {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
        }

        .area-type.staff {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .area-type.student {
            background-color: #dcfce7;
            color: #166534;
        }

        .area-stats {
            display: flex;
            gap: 16px;
            margin-bottom: 12px;
        }

        .area-stat {
            flex: 1;
        }

        .area-stat-label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .area-stat-value {
            font-size: 20px;
            font-weight: 600;
            color: #111827;
        }

        .area-status {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .area-status.open {
            background-color: #dcfce7;
            color: #166534;
        }

        .area-status.closed {
            background-color: #fee2e2;
            color: #991b1b;
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
            .areas-grid {
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
            <div>
                <div class="sidebar-title">FKPark</div>
                <div class="sidebar-subtitle">Admin — Manage Parking</div>
            </div>
        </div>

        <nav class="nav">
    <a href="parking_area.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'parking_area.php') ? 'active' : ''; ?>">
        <i class="bi bi-calendar-check"></i> Parking Area
    </a>
    <a href="parking_space_list.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'parking_space_list.php' || basename($_SERVER['PHP_SELF']) == 'parking_space.php') ? 'active' : ''; ?>">
        <i class="bi bi-grid-3x3-gap"></i> Parking Spaces
    </a>
    <a href="area_closure.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'area_closure.php') ? 'active' : ''; ?>">
        <i class="bi bi-sign-stop"></i> Area Closure
    </a>
</nav>

        <div class="sidebar-footer">
            <a href="dashboard_admin.php" class="back-link">
                ← Back to Dashboard
            </a>
        </div>
    </aside>

    <!-- Main -->
    <div class="main">
        <!-- Top bar -->
        <header class="topbar">
            <div class="search-box">
    <i class="bi bi-search"></i>
    <input type="text" placeholder="Search..." id="searchInput">
</div>
            <a href="logout.php" class="sign-out-link">Log out</a>
        </header>

        <!-- Content -->
        <main class="content">
            <div class="breadcrumb">
                Admin Dashboard » Manage Parking
            </div>

            <div class="page-header">
                <h1 class="page-title">Manage Parking Workspace</h1>
                <div class="sync-info">
                    Last sync: <?php echo date('n/j/Y, g:i:s A'); ?>
                </div>
            </div>

            <!-- Stats cards -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Total Areas</div>
                    <div class="stat-value"><?php echo $totalAreas; ?></div>
                    <div class="stat-sublabel"></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Spaces</div>
                    <div class="stat-value"><?php echo $totalSpaces; ?></div>
                    <div class="stat-sublabel">Total capacity</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Closed Areas</div>
                    <div class="stat-value"><?php echo $closedAreas; ?></div>
                    <div class="stat-sublabel">Under maintenance</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Available Spaces (Now)</div>
                    <div class="stat-value"><?php echo $availableSpaces; ?></div>
                    <div class="stat-sublabel">Currently free</div>
                </div>
            </div>

            <!-- Parking Areas Section -->
            <div class="areas-section">
                <div class="section-title">Parking Spaces by Area</div>
                <div class="section-subtitle">Select an area to manage its parking spaces</div>

                <div class="areas-grid">
                    <?php if (empty($areas)): ?>
                        <div style="grid-column: 1/-1; text-align: center; color: #6b7280; padding: 40px;">
                            No parking areas found.
                        </div>
                    <?php else: ?>
                        <?php foreach ($areas as $area): ?>
                            <a href="parking_space.php?area_id=<?php echo $area['Area_ID']; ?>" class="area-card">
                                <div class="area-header">
                                    <div class="area-name"><?php echo htmlspecialchars($area['area_name']); ?></div>
                                    <span class="area-type <?php echo strtolower(htmlspecialchars($area['area_type'])); ?>">
                                        <?php echo htmlspecialchars($area['area_type']); ?>
                                    </span>
                                </div>
                                
                                <div class="area-stats">
                                    <div class="area-stat">
                                        <div class="area-stat-label">Total</div>
                                        <div class="area-stat-value"><?php echo $area['total_spaces']; ?></div>
                                    </div>
                                    <div class="area-stat">
                                        <div class="area-stat-label">Available</div>
                                        <div class="area-stat-value"><?php echo $area['available_spaces']; ?></div>
                                    </div>
                                </div>

                                <span class="area-status <?php echo $area['status']; ?>">
                                    <?php echo ucfirst($area['status']); ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
// Auto-refresh stats AND area cards every 3 seconds
setInterval(autoRefreshAll, 3000);

// Also refresh when page becomes visible
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        console.log('Page became visible, refreshing...');
        autoRefreshAll();
    }
});

function autoRefreshAll() {
    fetch('get_areas_update.php?t=' + Date.now())
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Refresh response:', data);
            
            if (data.success) {
                // Update top stats
                updateStats(data.stats);
                
                // Update area cards
                updateAreaCards(data.areas);
                
                // Update sync time
                if (data.server_time) {
                    const syncInfo = document.querySelector('.sync-info');
                    if (syncInfo) {
                        syncInfo.textContent = 'Last sync: ' + data.server_time;
                    }
                }
                
                console.log('✓ Page updated successfully');
            } else {
                console.error('Server returned success=false:', data.message);
            }
        })
        .catch(error => {
            console.error('Refresh error:', error);
        });
}

function updateStats(stats) {
    const statCards = document.querySelectorAll('.stat-card');
    
    statCards.forEach(card => {
        const label = card.querySelector('.stat-label');
        const valueEl = card.querySelector('.stat-value');
        
        if (!label || !valueEl) return;
        
        const labelText = label.textContent.toLowerCase();
        let newValue = null;
        let statKey = null;
        
        if (labelText.includes('total areas')) {
            newValue = stats.totalAreas;
            statKey = 'totalAreas';
        } else if (labelText.includes('total spaces')) {
            newValue = stats.totalSpaces;
            statKey = 'totalSpaces';
        } else if (labelText.includes('closed areas')) {
            newValue = stats.closedAreas;
            statKey = 'closedAreas';
        } else if (labelText.includes('available')) {
            newValue = stats.availableSpaces;
            statKey = 'availableSpaces';
        }
        
        if (newValue !== null) {
            const oldValue = valueEl.textContent.trim();
            const newValueStr = newValue.toString();
            
            if (oldValue !== newValueStr) {
                console.log(`${statKey} changed: ${oldValue} → ${newValueStr}`);
                valueEl.textContent = newValueStr;
                
                // Flash animation
                valueEl.style.transition = 'all 0.3s ease';
                valueEl.style.backgroundColor = '#fef3c7';
                setTimeout(() => {
                    valueEl.style.backgroundColor = '';
                }, 500);
            }
        }
    });
}

function updateAreaCards(areas) {
    // Find all area cards
    areas.forEach(area => {
        // Find the card for this area by looking for the area name
        const areaCards = document.querySelectorAll('.area-card');
        
        areaCards.forEach(card => {
            const areaNameEl = card.querySelector('.area-name');
            if (areaNameEl && areaNameEl.textContent.trim() === area.area_name) {
                // Found the matching card, now update the available count
                const statValues = card.querySelectorAll('.area-stat-value');
                
                // Second stat value is the "Available" count
                if (statValues.length >= 2) {
                    const availableEl = statValues[1];
                    const oldValue = availableEl.textContent.trim();
                    const newValue = area.available_spaces.toString();
                    
                    if (oldValue !== newValue) {
                        console.log(`Area ${area.area_name} available changed: ${oldValue} → ${newValue}`);
                        availableEl.textContent = newValue;
                        
                        // Flash animation
                        availableEl.style.transition = 'all 0.3s ease';
                        availableEl.style.backgroundColor = '#fef3c7';
                        setTimeout(() => {
                            availableEl.style.backgroundColor = '';
                        }, 500);
                    }
                }
            }
        });
    });
}

// Search functionality (existing)
document.getElementById('searchInput').addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const areaCards = document.querySelectorAll('.area-card');
    
    areaCards.forEach(card => {
        const text = card.textContent.toLowerCase();
        card.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// Initialize
console.log('Parking Space List Complete Auto-Refresh System Initialized');
console.log('Refresh interval: 3 seconds');
console.log('Refreshing: Top stats + Area cards');
console.log('---');

// Run first refresh after 1 second
setTimeout(autoRefreshAll, 1000);
</script>
</body>
</html>