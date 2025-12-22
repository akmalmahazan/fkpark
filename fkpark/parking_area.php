<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Security: Admin only - Fixed session check
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

// Closed areas - Fixed column name from area_id to area_ID
$res = mysqli_query($conn, "
    SELECT COUNT(DISTINCT area_ID) AS total
    FROM area_closure
    WHERE closed_from <= NOW()
    AND (closed_to IS NULL OR closed_to >= NOW())
");
$closedAreas = mysqli_fetch_assoc($res)['total'] ?? 0;

// Available spaces - FIXED: Exclude spaces in closed areas
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

// ===== GET PARKING AREAS - FIXED to show accurate available counts =====
$areas = [];
$sqlAreas = "
    SELECT 
        pa.Area_ID,
        pa.area_name,
        pa.area_type,
        COUNT(ps.space_id) as capacity,
        -- Count only TRULY available spaces (excluding closed areas)
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
        END) as spaces,
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
    <title>Manage Parking - FKPark</title>
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

        /* Main parking area section */
        .parking-section {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .section-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }

        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background-color: #f9fafb;
        }

        th, td {
            padding: 14px 16px;
            font-size: 14px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }

        tbody tr:hover {
            background-color: #f9fafb;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.open {
            background-color: #dcfce7;
            color: #166534;
        }

        .status-badge.closed {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .action-links {
            display: flex;
            gap: 12px;
        }

        .action-link {
            color: #0066ff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }

        .action-link:hover {
            text-decoration: underline;
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
            <a href="parking_area.php" class="nav-link active">
                📆 Parking Area
            </a>
            <a href="parking_space_list.php" class="nav-link">
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

    <!-- Main -->
    <div class="main">
        <!-- Top bar -->
        <header class="topbar">
            <div class="search-box">
                <span>🔍</span>
                <input type="text" placeholder="Search areas or spaces..." id="searchInput">
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
                    <div class="stat-sublabel">A1–A4 (staff), B1–B3 (student)</div>
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

            <!-- Parking Area Section -->
            <div class="parking-section">
                <div class="section-header">
                    <div>
                        <div class="section-title">Parking Area</div>
                        <div class="section-subtitle">Select an area to manage spaces or view details.</div>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Area</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Capacity</th>
                            <th>Available</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($areas)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #6b7280; padding: 40px;">
                                    No parking areas found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($areas as $area): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($area['area_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($area['area_type']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $area['status']; ?>">
                                            <?php echo ucfirst($area['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $area['capacity']; ?></td>
                                    <td><?php echo $area['spaces']; ?></td>
                                    <td>
                                        <div class="action-links">
                                            <a href="parking_space.php?area_id=<?php echo $area['Area_ID']; ?>" class="action-link">Manage</a>
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

<<script>
// Auto-refresh stats AND table every 3 seconds
setInterval(autoRefreshAll, 3000);

document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        autoRefreshAll();
    }
});

function autoRefreshAll() {
    fetch('get_areas_update.php?t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStats(data.stats);
                updateTableRows(data.areas);
                if (data.server_time) {
                    const syncInfo = document.querySelector('.sync-info');
                    if (syncInfo) {
                        syncInfo.textContent = 'Last sync: ' + data.server_time;
                    }
                }
            }
        })
        .catch(error => console.error('Refresh error:', error));
}

function updateStats(stats) {
    const statMap = {
        totalAreas: stats.totalAreas,
        totalSpaces: stats.totalSpaces,
        closedAreas: stats.closedAreas,
        availableSpaces: stats.availableSpaces
    };
    
    Object.keys(statMap).forEach(key => {
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            const label = card.querySelector('.stat-label').textContent.toLowerCase();
            const valueEl = card.querySelector('.stat-value');
            
            if ((key === 'totalAreas' && label.includes('total areas')) ||
                (key === 'totalSpaces' && label.includes('total spaces')) ||
                (key === 'closedAreas' && label.includes('closed areas')) ||
                (key === 'availableSpaces' && label.includes('available'))) {
                
                const oldValue = valueEl.textContent;
                const newValue = statMap[key].toString();
                
                if (oldValue !== newValue) {
                    console.log(`${key} changed: ${oldValue} → ${newValue}`);
                    valueEl.textContent = newValue;
                    
                    // Flash animation
                    valueEl.style.transition = 'all 0.3s ease';
                    valueEl.style.backgroundColor = '#fef3c7';
                    setTimeout(() => {
                        valueEl.style.backgroundColor = '';
                    }, 500);
                }
            }
        });
    });
}

function updateTableRows(areas) {
    // Get all table rows
    const tableRows = document.querySelectorAll('tbody tr');
    
    areas.forEach(area => {
        // Find the matching row by area name
        tableRows.forEach(row => {
            const areaNameCell = row.querySelector('td:first-child strong');
            if (areaNameCell && areaNameCell.textContent.trim() === area.area_name) {
                // Update Available count (5th column)
                const availableCell = row.querySelectorAll('td')[4];
                if (availableCell) {
                    const oldValue = availableCell.textContent.trim();
                    const newValue = area.available_spaces.toString();
                    
                    if (oldValue !== newValue) {
                        console.log(`Table row ${area.area_name} available changed: ${oldValue} → ${newValue}`);
                        availableCell.textContent = newValue;
                        
                        // Flash animation
                        availableCell.style.transition = 'all 0.3s ease';
                        availableCell.style.backgroundColor = '#fef3c7';
                        setTimeout(() => {
                            availableCell.style.backgroundColor = '';
                        }, 500);
                    }
                }
                
                // Update Status badge
                const statusCell = row.querySelectorAll('td')[2];
                if (statusCell) {
                    const statusBadge = statusCell.querySelector('.status-badge');
                    if (statusBadge) {
                        const oldStatus = statusBadge.classList.contains('open') ? 'open' : 'closed';
                        const newStatus = area.status;
                        
                        if (oldStatus !== newStatus) {
                            console.log(`Table row ${area.area_name} status changed: ${oldStatus} → ${newStatus}`);
                            statusBadge.className = `status-badge ${newStatus}`;
                            statusBadge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                        }
                    }
                }
            }
        });
    });
}

// Search functionality
document.getElementById('searchInput').addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const tableRows = document.querySelectorAll('tbody tr');
    
    tableRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

console.log('Parking Area Complete Auto-Refresh Initialized');
console.log('Refreshing: Top stats + Table rows');
setTimeout(autoRefreshAll, 1000);
</script>
</body>
</html>