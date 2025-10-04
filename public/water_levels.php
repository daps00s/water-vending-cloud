<?php
$pageTitle = 'Water Levels';
require_once __DIR__ . '/../includes/header.php';

// Handle notification from refill action
$notification = isset($_GET['notification']) ? htmlspecialchars($_GET['notification']) : '';

// Update operational_status in dispenserstatus based on water level and machine status
try {
    $pdo->beginTransaction();
    $stmt = $pdo->query("
        SELECT 
            d.dispenser_id,
            COALESCE(ds.water_level, 0) as water_level,
            COALESCE(dl.Status, 0) as machine_status
        FROM dispenser d
        LEFT JOIN dispenserstatus ds ON d.dispenser_id = ds.dispenser_id
        LEFT JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
    ");
    $machines = $stmt->fetchAll();

    foreach ($machines as $machine) {
        $status = 'Normal';
        $waterLevel = (float)$machine['water_level'];
        if ($machine['machine_status'] == 1) {
            if ($waterLevel < 1) {
                $status = 'Critical';
            } elseif ($waterLevel < 2) {
                $status = 'Low';
            }
        } elseif ($machine['machine_status'] == 0) {
            $status = 'Disabled';
        }
        $stmt = $pdo->prepare("UPDATE dispenserstatus SET operational_status = ? WHERE dispenser_id = ?");
        $stmt->execute([$status, $machine['dispenser_id']]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    $notification = 'error|Failed to update machine statuses: ' . $e->getMessage();
}

// Get water level data with machine information, including status
$waterLevels = $pdo->query("
    SELECT 
        d.dispenser_id,
        d.Description as machine_name,
        d.Capacity,
        COALESCE(ds.water_level, 0) as water_level,
        COALESCE(ds.operational_status, 'Normal') as operational_status,
        l.location_name,
        COALESCE(dl.Status, 0) as machine_status
    FROM dispenser d
    LEFT JOIN dispenserstatus ds ON d.dispenser_id = ds.dispenser_id
    LEFT JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
    LEFT JOIN location l ON dl.location_id = l.location_id
    ORDER BY COALESCE(ds.water_level, 0) ASC
")->fetchAll();

// Count alerts
$lowWaterCount = 0;
$issueCount = 0;
foreach ($waterLevels as $level) {
    if ($level['machine_status'] == 1 && $level['water_level'] < 2) $lowWaterCount++;
    if ($level['machine_status'] == 1 && $level['operational_status'] != 'Normal') $issueCount++;
}
?>
<div class="content-area">
    <div class="content-wrapper">
        <!-- Notification Toast -->
        <?php if ($notification): ?>
        <div class="notification-toast <?= explode('|', $notification)[0] ?>">
            <?= explode('|', $notification)[1] ?>
        </div>
        <?php endif; ?>
        
        <div class="content-header">
            <h1 class="content-title">Water Level Monitoring</h1>
            <div class="content-actions">
                <button class="btn-primary" id="refreshLevels">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card warning">
                <div class="stat-title">Low Water Alerts</div>
                <div class="stat-value"><?= $lowWaterCount ?></div>
                <div class="stat-change warning">
                    <i class="fas fa-exclamation-triangle"></i> Needs Refill
                </div>
            </div>
            <div class="stat-card danger">
                <div class="stat-title">Operational Issues</div>
                <div class="stat-value"><?= $issueCount ?></div>
                <div class="stat-change danger">
                    <i class="fas fa-tools"></i> Needs Maintenance
                </div>
            </div>
        </div>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Machine</th>
                        <th>Location</th>
                        <th>Water Level</th>
                        <th>Capacity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($waterLevels as $level): 
                        $waterPercent = ($level['water_level'] / $level['Capacity']) * 100;
                        $statusClass = '';
                        if ($level['machine_status'] == 0) {
                            $statusClass = 'disabled';
                        } elseif ($level['water_level'] < 1) {
                            $statusClass = 'danger';
                        } elseif ($level['water_level'] < 2) {
                            $statusClass = 'warning';
                        } else {
                            $statusClass = 'success';
                        }
                        $badgeClass = '';
                        switch ($level['operational_status']) {
                            case 'Normal':
                                $badgeClass = 'active';
                                break;
                            case 'Low':
                                $badgeClass = 'warning';
                                break;
                            case 'Critical':
                                $badgeClass = 'inactive';
                                break;
                            case 'Disabled':
                                $badgeClass = 'disabled';
                                break;
                            default:
                                $badgeClass = 'active';
                        }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($level['machine_name']) ?></td>
                        <td><?= htmlspecialchars($level['location_name'] ?? 'Not Deployed') ?></td>
                        <td>
                            <div class="water-level-bar">
                                <div class="water-level-fill <?= $statusClass ?>" 
                                     style="width: <?= $statusClass == 'disabled' ? 100 : $waterPercent ?>%">
                                    <?= $statusClass == 'disabled' ? 'Disabled' : $level['water_level'] . 'L / ' . $level['Capacity'] . 'L' ?>
                                </div>
                            </div>
                        </td>
                        <td><?= $level['Capacity'] ?>L</td>
                        <td>
                            <span class="status-badge <?= $badgeClass ?>">
                                <?= htmlspecialchars($level['operational_status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.water-level-bar {
    width: 100%;
    height: 24px;
    background-color: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}

.water-level-fill {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
    font-weight: bold;
    transition: width 0.5s ease;
}

.water-level-fill.success {
    background-color: #2ecc71;
}

.water-level-fill.warning {
    background-color: #f39c12;
    animation: blink 1s infinite;
}

.water-level-fill.danger {
    background-color: #e74c3c;
}

.water-level-fill.disabled {
    background-color: #6c757d;
    animation: blink 1s infinite;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8em;
    font-weight: 500;
    color: white;
    min-width: 60px;
    text-align: center;
    display: inline-block;
    line-height: normal;
    vertical-align: middle;
}

.status-badge.active {
    background-color: #2ecc71;
    color: white;
}

.status-badge.warning {
    background-color: #f39c12;
    color: white;
}

.status-badge.inactive {
    background-color: #e74c3c;
    color: white;
}

.status-badge.disabled {
    background-color: #6c757d;
    color: white;
}

.notification-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 25px;
    border-radius: 4px;
    color: white;
    font-weight: 500;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    z-index: 1100;
    animation: slideIn 0.3s, fadeOut 0.5s 2.5s forwards;
}

.notification-toast.success {
    background-color: #2ecc71;
}

.notification-toast.error {
    background-color: #e74c3c;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}

@keyframes blink {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.stat-card {
    padding: 20px;
    border-radius: 8px;
    background-color: #fff;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.stat-card.warning {
    border-left: 5px solid #f39c12;
}

.stat-card.danger {
    border-left: 5px solid #e74c3c;
}

.stat-title {
    font-size: 0.9em;
    color: #7f8c8d;
    text-transform: uppercase;
}

.stat-value {
    font-size: 2em;
    font-weight: bold;
    margin: 5px 0;
    color: #2c3e50;
}

.stat-change {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.9em;
}

.stat-change.warning {
    color: #f39c12;
}

.stat-change.danger {
    color: #e74c3c;
}

.content-area {
    padding: 20px 0;
    background-color: #f8f9fa;
    width: 100%;
}

.content-wrapper {
    padding: 0 30px;
    max-width: 100%;
    margin: 0 auto;
}

.content-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.content-title {
    font-size: 24px;
    color: #2c3e50;
}

.content-actions {
    display: flex;
    gap: 10px;
}

.btn-primary {
    color: #fff;
    background-color: #3498db;
    border: none;
    padding: 10px 20px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: background-color 0.2s;
}

.btn-primary:hover {
    background-color: #2980b9;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.data-table th,
.data-table td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.data-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #2c3e50;
}

.data-table tr:hover {
    background-color: #f1f3f5;
}
</style>

<script>
// Refresh button
document.getElementById('refreshLevels').addEventListener('click', function() {
    window.location.reload();
});

// Auto-hide notification toast
document.addEventListener('DOMContentLoaded', function() {
    const toast = document.querySelector('.notification-toast');
    if (toast) {
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>