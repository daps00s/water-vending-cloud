<?php
// backup.php
$pageTitle = 'System Backup';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Set timezone to Philippines (Tarlac)
date_default_timezone_set('Asia/Manila');

// Backup directory
$backupDir = 'backups/';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// Settings file
$settingsFile = 'config/backup_settings.json';
if (!is_dir('config')) {
    mkdir('config', 0777, true);
}

// Load or initialize settings
if (!file_exists($settingsFile)) {
    $defaultSettings = [
        'frequency' => 'daily',
        'last_backup' => null
    ];
    file_put_contents($settingsFile, json_encode($defaultSettings, JSON_PRETTY_PRINT));
}
$settings = json_decode(file_get_contents($settingsFile), true);
$frequency = $settings['frequency'] ?? 'daily';
$last_backup = $settings['last_backup'] ?? null;

// Fixed backup time at 2:00 AM
$backup_hour = 2;
$backup_minute = 0;

// Function to create database backup
function createBackup($pdo, $backupDir, $isAuto = false) {
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        
        $output = "-- Database Backup for water_dispenser_system\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Type: " . ($isAuto ? 'Automatic' : 'Manual') . "\n\n";

        foreach ($tables as $table) {
            $output .= "-- Table structure for $table\n\n";
            $createTable = $pdo->query("SHOW CREATE TABLE $table")->fetch();
            $output .= $createTable['Create Table'] . ";\n\n";

            $rows = $pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $output .= "-- Data for $table\n";
                foreach ($rows as $row) {
                    $values = array_map(function($value) use ($pdo) {
                        return $value === null ? 'NULL' : $pdo->quote($value);
                    }, array_values($row));
                    $output .= "INSERT INTO `$table` VALUES (" . implode(',', $values) . ");\n";
                }
                $output .= "\n";
            }
        }

        $prefix = $isAuto ? 'auto_backup_' : 'manual_backup_';
        $filename = $backupDir . $prefix . date('Y-m-d_H-i-s') . '.sql';
        
        if (!file_put_contents($filename, $output)) {
            throw new Exception("Failed to write backup file");
        }
        
        // Implement retention policy: keep last 7 days of backups
        $retentionPeriod = 7 * 24 * 60 * 60;
        $backupFiles = glob($backupDir . '*.sql');
        foreach ($backupFiles as $file) {
            if (filemtime($file) < time() - $retentionPeriod) {
                unlink($file);
            }
        }
        
        return $filename;
    } catch (Exception $e) {
        error_log("Backup creation failed: " . $e->getMessage());
        throw $e;
    }
}

// Handle automatic backup check
if (isset($_GET['check_auto_backup']) && $_GET['check_auto_backup'] == '1') {
    try {
        $now = new DateTime();
        $lastBackupTime = $last_backup ? new DateTime($last_backup) : null;
        
        // Check if backup should run based on frequency
        $shouldRun = false;
        $currentHourMin = $now->format('H:i');
        $scheduledHourMin = sprintf('%02d:%02d', $backup_hour, $backup_minute);
        
        if ($frequency === 'daily') {
            $shouldRun = ($currentHourMin >= $scheduledHourMin);
        } elseif ($frequency === 'every_other_day' && $lastBackupTime) {
            $daysDiff = $now->diff($lastBackupTime)->days;
            $shouldRun = ($daysDiff >= 2 && $currentHourMin >= $scheduledHourMin);
        } elseif ($frequency === 'every_other_day' && !$lastBackupTime) {
            $shouldRun = ($currentHourMin >= $scheduledHourMin);
        } elseif ($frequency === 'every_month') {
            $currentDay = $now->format('j');
            $shouldRun = ($currentDay == 1 && $currentHourMin >= $scheduledHourMin);
        }

        if ($shouldRun) {
            $backupFile = createBackup($pdo, $backupDir, true);
            $settings['last_backup'] = $now->format('Y-m-d H:i:s');
            file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
            // Trigger download
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($backupFile) . '"');
            readfile($backupFile);
            exit;
        } else {
            echo json_encode(['status' => 'skipped', 'message' => 'Not time for backup yet']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Automatic backup failed: ' . $e->getMessage()]);
        exit;
    }
}

// Handle manual backup creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup'])) {
    try {
        $backupFile = createBackup($pdo, $backupDir, false);
        // Return JSON response to trigger client-side handling
        echo json_encode(['status' => 'success', 'filename' => $backupFile]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Backup failed: ' . $e->getMessage()]);
        exit;
    }
}

// Handle update backup settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $frequency = $_POST['frequency'];

    // Validate inputs
    $valid = true;
    $errorMsg = '';
    if (!in_array($frequency, ['daily', 'every_other_day', 'every_month'])) {
        $valid = false;
        $errorMsg = 'Invalid frequency selection.';
    }

    if (!$valid) {
        $notification = 'error|' . $errorMsg;
    } else {
        $settings['frequency'] = $frequency;
        $settings['last_backup'] = null; // Reset last backup to allow new backup
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
        $notification = 'success|Backup settings successfully updated!';
    }
}

// Handle delete
$notification = isset($notification) ? $notification : '';
if (isset($_GET['delete'])) {
    $file = $backupDir . basename($_GET['delete']);
    if (file_exists($file)) {
        if (unlink($file)) {
            $notification = 'success|Backup successfully deleted!';
            // Return JSON response for AJAX handling
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Backup successfully deleted!', 'filename' => basename($file)]);
            exit;
        } else {
            $notification = 'error|Failed to delete backup: ' . basename($file);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete backup: ' . basename($file)]);
            exit;
        }
    } else {
        $notification = 'error|Backup file not found: ' . basename($file);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Backup file not found: ' . basename($file)]);
        exit;
    }
}

// Check for message in URL
if (isset($_GET['message'])) {
    $notification = urldecode($_GET['message']);
}

// Get list of backups (sorted newest first)
$backupFiles = glob($backupDir . '*.sql');
rsort($backupFiles);

// Calculate next backup time for display
$next_backup = new DateTime();
$next_backup->setTime($backup_hour, $backup_minute);
$now = new DateTime();
if ($frequency === 'daily') {
    if ($next_backup <= $now) {
        $next_backup->modify('+1 day');
    }
} elseif ($frequency === 'every_other_day') {
    if ($last_backup) {
        $lastBackupTime = new DateTime($last_backup);
        $next_backup = clone $lastBackupTime;
        $next_backup->modify('+2 days');
        $next_backup->setTime($backup_hour, $backup_minute);
        while ($next_backup <= $now) {
            $next_backup->modify('+2 days');
        }
    } else {
        if ($next_backup <= $now) {
            $next_backup->modify('+2 days');
        }
    }
} elseif ($frequency === 'every_month') {
    $year = $now->format('Y');
    $month = $now->format('m');
    $next_backup->setDate($year, $month, 1);
    $next_backup->setTime($backup_hour, $backup_minute);
    if ($next_backup <= $now) {
        $next_backup->modify('+1 month');
        $next_backup->setDate($next_backup->format('Y'), $next_backup->format('m'), 1);
        $next_backup->setTime($backup_hour, $backup_minute);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
<div class="content-area">
    <div class="content-wrapper">
        <!-- Notification Toast -->
        <?php if ($notification): ?>
        <div class="notification-toast <?= explode('|', $notification)[0] ?>">
            <span><?= explode('|', $notification)[1] ?></span>
            <div class="notification-progress"></div>
        </div>
        <?php endif; ?>

        <!-- Refresh Loading Modal -->
        <div id="refreshLoadingModal" class="modal" style="display: none;">
            <div class="modal-content">
                <div class="loading-spinner"></div>
                <p id="refreshLoadingMessage">Refreshing backup list...</p>
            </div>
        </div>

        <!-- Confirmation Modal -->
        <div id="confirmModal" class="modal" style="display: none;">
            <div class="modal-content">
                <h3 id="modalTitle"></h3>
                <p id="modalMessage"></p>
                <div class="input-group" id="confirmInputGroup" style="display: none;">
                    <label for="confirmInput">Type CONFIRM to verify:</label>
                    <input type="text" id="confirmInput" class="form-control" placeholder="CONFIRM">
                </div>
                <div class="modal-buttons">
                    <button id="modalConfirm" class="btn btn-primary">Confirm</button>
                    <button id="modalCancel" class="btn btn-secondary">Cancel</button>
                </div>
            </div>
        </div>

        <div class="content-header card">
            <h3 class="content-title">Database Backup System</h3>
            <span class="content-subtitle">Philippines - Tarlac Time (Asia/Manila)</span>
        </div>

        <!-- Current Time Display -->
        <div class="card current-time">
            <h3><i class="fas fa-clock"></i> Current Time</h3>
            <p id="currentTime"><?php echo date('Y-m-d h:i:s A'); ?></p>
            <p>Next Scheduled Backup: <span id="nextBackup"><?php echo $next_backup->format('Y-m-d h:i A'); ?></span></p>
        </div>

        <!-- Manual Backup Form -->
        <div class="card manual-backup">
            <h3><i class="fas fa-plus-circle"></i> Manual Backup</h3>
            <form method="POST" id="backupForm">
                <button type="button" onclick="showModal('Create Backup', 'Are you sure you want to create a new backup?', handleManualBackup, false)" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create Manual Backup
                </button>
                <input type="hidden" name="backup" value="1">
            </form>
        </div>

        <!-- Automatic Backup Settings (Setup Menu) -->
        <div class="card backup-settings">
            <h3><i class="fas fa-cog"></i> Automatic Backup Setup</h3>
            <form method="POST" id="settingsForm">
                <div class="input-group">
                    <label for="frequency"><i class="fas fa-calendar-alt"></i> Backup Frequency</label>
                    <select id="frequency" name="frequency" required>
                        <option value="daily" <?php echo $frequency == 'daily' ? 'selected' : ''; ?>>Daily</option>
                        <option value="every_other_day" <?php echo $frequency == 'every_other_day' ? 'selected' : ''; ?>>Every Other Day</option>
                        <option value="every_month" <?php echo $frequency == 'every_month' ? 'selected' : ''; ?>>Every Month (1st)</option>
                    </select>
                </div>
                <button type="button" onclick="showModal('Update Settings', 'Are you sure you want to update the automatic backup settings?', () => document.getElementById('settingsForm').submit(), false)" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Settings
                </button>
                <input type="hidden" name="update_settings" value="1">
            </form>
        </div>

        <!-- List Existing Backups -->
        <div class="card backup-list">
            <h3><i class="fas fa-file-archive"></i> Existing Backups</h3>
            <?php if (empty($backupFiles)): ?>
                <p class="no-backups">No backups found.</p>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table" id="backupsTable">
                        <thead>
                            <tr>
                                <th>Filename</th>
                                <th>Type</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="backupsTableBody">
                            <?php foreach ($backupFiles as $file): ?>
                                <?php
                                    $filename = basename($file);
                                    $isAuto = strpos($filename, 'auto_backup_') === 0;
                                ?>
                                <tr data-filename="<?php echo htmlspecialchars($filename); ?>">
                                    <td><?php echo htmlspecialchars($filename); ?></td>
                                    <td class="backup-type-<?php echo $isAuto ? 'auto' : 'manual'; ?>">
                                        <?php echo $isAuto ? 'Automatic' : 'Manual'; ?>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i:s', filemtime($file)); ?></td>
                                    <td>
                                        <button class="btn-action download" onclick="showModal('Download Backup', 'Do you want to download <?php echo htmlspecialchars($filename); ?>?', () => handleDownload('<?php echo htmlspecialchars($file); ?>'), false)" title="Download">
                                            <i class="fas fa-download"></i>
                                        </button>
                                        <button class="btn-action delete" onclick="showModal('Delete Backup', 'Are you sure you want to delete <?php echo htmlspecialchars($filename); ?>?', () => handleDelete('<?php echo urlencode($filename); ?>', <?php echo $isAuto ? "'Automatic'" : "'Manual'"; ?>), true)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
body {
    font-family: 'Inter', sans-serif;
    background-color: #F4F6F9;
    margin: 0;
    padding: 0;
}

.content-area {
    padding: 40px 0;
    min-height: 100vh;
}

.content-wrapper {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

.card {
    background: #FFFFFF;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    padding: 24px;
    margin-bottom: 24px;
    animation: fadeIn 0.5s ease-in;
}

.content-header {
    text-align:  center;
}

.content-title {
    font-size: 28px;
    font-weight: 700;
    color: #1A3C5E;
    margin: 0 0 8px;
}

.content-subtitle {
    font-size: 16px;
    color: #627D98;
}

.current-time h3, .manual-backup h3, .backup-settings h3, .backup-list h3 {
    font-size: 20px;
    font-weight: 600;
    color: #1A3C5E;
    margin: 0 0 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.current-time p, .no-backups {
    font-size: 16px;
    color: #2D4B73;
    margin: 8px 0;
}

.notification-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 25px;
    border-radius: 6px;
    color: white;
    font-weight: 500;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    z-index: 1100;
    display: flex;
    flex-direction: column;
    gap: 8px;
    animation: slideIn 0.3s, fadeOut 0.5s 2.5s forwards;
}

.notification-toast.success {
    background: #2ecc71;
}

.notification-toast.error {
    background: #e74c3c;
}

.notification-toast.loading {
    background: #1E88E5;
}

.notification-progress {
    height: 4px;
    background: rgba(255, 255, 255, 0.3);
    border-radius: 2px;
    animation: progress 3s linear forwards;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 500;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    max-width: 200px;
}

.btn-primary {
    background: linear-gradient(135deg, #1E88E5, #42A5F5);
    color: #FFFFFF;
    border: none;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(30, 136, 229, 0.3);
}

.btn-primary:active {
    transform: translateY(0);
}

.btn-secondary {
    background: #627D98;
    color: #FFFFFF;
    border: none;
}

.btn-secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(98, 125, 152, 0.3);
}

.btn-secondary:active {
    transform: translateY(0);
}

.backup-settings form {
    display: grid;
    gap: 20px;
}

.input-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.input-group label {
    font-size: 15px;
    font-weight: 500;
    color: #1A3C5E;
    display: flex;
    align-items: center;
    gap: 6px;
}

.input-group select, .input-group input.form-control {
    padding: 12px;
    border: 1px solid #D1D9E6;
    border-radius: 8px;
    font-size: 15px;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.input-group select:focus, .input-group input.form-control:focus {
    outline: none;
    border-color: #1E88E5;
    box-shadow: 0 0 0 3px rgba(30, 136, 229, 0.1);
}

.input-group select, .input-group input.form-control {
    width: 200px;
}

.backup-list .table-container {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
}

.data-table th, .data-table td {
    padding: 14px;
    text-align: left;
    font-size: 15px;
    border-bottom: 1px solid #E8ECEF;
}

.data-table th {
    background: #F8FAFC;
    font-weight: 600;
    color: #1A3C5E;
}

.data-table tr:nth-child(even) {
    background: #F9FBFD;
}

.data-table tr:hover {
    background: #E3F2FD;
}

.backup-type-auto {
    color: #4CAF50;
    font-weight: 500;
}

.backup-type-manual {
    color: #1E88E5;
    font-weight: 500;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    margin-right: 8px;
    transition: background-color 0.2s, transform 0.2s;
    position: relative;
}

.btn-action:hover::after {
    content: attr(title);
    position: absolute;
    top: -30px;
    background: #2D4B73;
    color: #FFFFFF;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
}

.btn-action.download {
    background: rgba(30, 136, 229, 0.1);
    color: #1E88E5;
}

.btn-action.download:hover {
    background: #1E88E5;
    color: #FFFFFF;
}

.btn-action.delete {
    background: rgba(229, 57, 53, 0.1);
    color: #E53935;
}

.btn-action.delete:hover {
    background: #E53935;
    color: #FFFFFF;
}

.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
}

.modal-content {
    background: #FFFFFF;
    border-radius: 12px;
    padding: 24px;
    max-width: 400px;
    width: 90%;
    text-align: center;
}

.modal-content h3 {
    font-size: 20px;
    font-weight: 600;
    color: #1A3C5E;
    margin: 0 0 16px;
}

.modal-content p {
    font-size: 16px;
    color: #2D4B73;
    margin: 0 0 24px;
}

.modal-buttons {
    display: flex;
    justify-content: center;
    gap: 16px;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #1E88E5;
    border-top: 4px solid transparent;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 16px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}

@keyframes progress {
    from { width: 100%; }
    to { width: 0; }
}

@media (max-width: 768px) {
    .content-wrapper {
        padding: 0 15px;
    }

    .input-group select,
    .input-group input.form-control,
    .btn-primary {
        width: 100%;
    }

    .btn-primary {
        max-width: none;
    }

    .data-table th, .data-table td {
        font-size: 14px;
        padding: 10px;
    }

    .modal-content {
        width: 95%;
    }
}
</style>

<script>
// Update current time every second
function updateTime() {
    const timeElement = document.getElementById('currentTime');
    if (timeElement) {
        const now = new Date();
        const options = {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        };
        timeElement.textContent = now.toLocaleString('en-PH', options);
    }
}

// Store scheduled time and frequency
let scheduledTime = null;
let frequency = '<?php echo $frequency; ?>';
let lastBackup = '<?php echo $last_backup ?: ''; ?>';

function setScheduledTime() {
    const hour = 2; // Fixed at 2 AM
    const minute = 0;
    frequency = document.getElementById('frequency').value;

    const now = new Date();
    let scheduledDateTime = new Date();
    
    if (frequency === 'daily') {
        scheduledDateTime.setHours(hour, minute, 0, 0);
        if (scheduledDateTime <= now) {
            scheduledDateTime.setDate(scheduledDateTime.getDate() + 1);
        }
    } else if (frequency === 'every_other_day') {
        if (lastBackup) {
            let lastBackupDate = new Date(lastBackup);
            scheduledDateTime = new Date(lastBackupDate);
            scheduledDateTime.setDate(lastBackupDate.getDate() + 2);
            scheduledDateTime.setHours(hour, minute, 0, 0);
            while (scheduledDateTime <= now) {
                scheduledDateTime.setDate(scheduledDateTime.getDate() + 2);
            }
        } else {
            scheduledDateTime.setHours(hour, minute, 0, 0);
            if (scheduledDateTime <= now) {
                scheduledDateTime.setDate(scheduledDateTime.getDate() + 2);
            }
        }
    } else if (frequency === 'every_month') {
        const year = now.getFullYear();
        const month = now.getMonth();
        scheduledDateTime.setDate(1);
        scheduledDateTime.setHours(hour, minute, 0, 0);
        if (scheduledDateTime <= now) {
            scheduledDateTime.setMonth(month + 1);
            scheduledDateTime.setDate(1);
            scheduledDateTime.setHours(hour, minute, 0, 0);
        }
    }
    
    scheduledTime = scheduledDateTime;
    
    // Update next backup display
    const nextBackup = document.getElementById('nextBackup');
    nextBackup.textContent = scheduledDateTime.toLocaleString('en-PH', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

function checkSchedule() {
    if (scheduledTime) {
        const now = new Date();
        const nowInManila = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
        if (nowInManila.getTime() >= scheduledTime.getTime()) {
            const loadingIndicator = document.getElementById('loadingIndicator');
            loadingIndicator.style.display = 'flex';
            
            fetch('backup.php?check_auto_backup=1')
                .then(response => {
                    if (response.headers.get('content-type')?.includes('application/octet-stream')) {
                        return response.blob().then(blob => {
                            const url = window.URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = response.headers.get('content-disposition')?.split('filename=')[1]?.replace(/"/g, '') || 'backup.sql';
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            window.URL.revokeObjectURL(url);
                            showRefreshLoading('Refreshing backup list...');
                            return { status: 'success', message: 'Automatic backup successfully created!' };
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    loadingIndicator.style.display = 'none';
                    if (data.status !== 'skipped') {
                        const toast = document.createElement('div');
                        toast.className = `notification-toast ${data.status}`;
                        toast.innerHTML = `<span>${data.message}</span><div class="notification-progress"></div>`;
                        document.body.appendChild(toast);
                        setTimeout(() => {
                            toast.remove();
                        }, 3000);
                        
                        if (data.status === 'success') {
                            // Refresh backup list
                            fetchBackupList();
                            // Update last backup and scheduled time
                            lastBackup = new Date().toISOString();
                            if (frequency === 'daily') {
                                scheduledTime.setDate(scheduledTime.getDate() + 1);
                            } else if (frequency === 'every_other_day') {
                                scheduledTime.setDate(scheduledTime.getDate() + 2);
                            } else if (frequency === 'every_month') {
                                scheduledTime.setMonth(scheduledTime.getMonth() + 1);
                                scheduledDateTime.setDate(1);
                            }
                            const nextBackup = document.getElementById('nextBackup');
                            nextBackup.textContent = scheduledTime.toLocaleString('en-PH', {
                                timeZone: 'Asia/Manila',
                                year: 'numeric',
                                month: 'numeric',
                                day: 'numeric',
                                hour: 'numeric',
                                minute: '2-digit',
                                hour12: true
                            });
                        }
                    }
                })
                .catch(error => {
                    loadingIndicator.style.display = 'none';
                    const toast = document.createElement('div');
                    toast.className = 'notification-toast error';
                    toast.innerHTML = `<span>Error checking automatic backup: ${error.message}</span><div class="notification-progress"></div>`;
                    document.body.appendChild(toast);
                    setTimeout(() => {
                        toast.remove();
                    }, 3000);
                });
        }
    }
}

function fetchBackupList() {
    fetch('backup.php')
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTableBody = doc.querySelector('#backupsTableBody');
            if (newTableBody) {
                const currentTableBody = document.querySelector('#backupsTableBody');
                currentTableBody.innerHTML = newTableBody.innerHTML;
            }
            const refreshModal = document.getElementById('refreshLoadingModal');
            refreshModal.style.display = 'none';
            const noBackups = document.querySelector('.no-backups');
            if (noBackups && newTableBody.children.length > 0) {
                noBackups.remove();
            } else if (!noBackups && newTableBody.children.length === 0) {
                const backupList = document.querySelector('.backup-list');
                const noBackupsMessage = document.createElement('p');
                noBackupsMessage.className = 'no-backups';
                noBackupsMessage.textContent = 'No backups found.';
                backupList.appendChild(noBackupsMessage);
            }
        })
        .catch(error => {
            const refreshModal = document.getElementById('refreshLoadingModal');
            refreshModal.style.display = 'none';
            const toast = document.createElement('div');
            toast.className = 'notification-toast error';
            toast.innerHTML = `<span>Error refreshing backup list: ${error.message}</span><div class="notification-progress"></div>`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.remove();
            }, 3000);
        });
}

function showModal(title, message, onConfirm, requireConfirmText = false, backupType = '') {
    const modal = document.getElementById('confirmModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalMessage = document.getElementById('modalMessage');
    const modalConfirm = document.getElementById('modalConfirm');
    const modalCancel = document.getElementById('modalCancel');
    const confirmInputGroup = document.getElementById('confirmInputGroup');
    const confirmInput = document.getElementById('confirmInput');

    modalTitle.textContent = title;
    modalMessage.textContent = message;
    confirmInputGroup.style.display = requireConfirmText ? 'block' : 'none';
    confirmInput.value = '';
    modal.style.display = 'flex';

    modalConfirm.onclick = () => {
        if (requireConfirmText && confirmInput.value !== 'CONFIRM') {
            const toast = document.createElement('div');
            toast.className = 'notification-toast error';
            toast.innerHTML = `<span>Please type CONFIRM to verify deletion</span><div class="notification-progress"></div>`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.remove();
            }, 3000);
            return;
        }
        onConfirm();
        modal.style.display = 'none';
    };

    modalCancel.onclick = () => {
        modal.style.display = 'none';
    };
}

function showRefreshLoading(message) {
    const refreshModal = document.getElementById('refreshLoadingModal');
    const refreshMessage = document.getElementById('refreshLoadingMessage');
    refreshMessage.textContent = message;
    refreshModal.style.display = 'flex';
}

function handleDownload(url) {
    const a = document.createElement('a');
    a.href = url;
    a.download = url.split('/').pop();
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    
    // Show success notification
    const toast = document.createElement('div');
    toast.className = 'notification-toast success';
    toast.innerHTML = `<span>Backup successfully downloaded!</span><div class="notification-progress"></div>`;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.remove();
    }, 3000);
    
    showRefreshLoading('Refreshing backup list...');
    setTimeout(fetchBackupList, 1000); // Refresh table after download
}

function handleDelete(filename, backupType) {
    const refreshModal = document.getElementById('refreshLoadingModal');
    refreshModal.querySelector('p').textContent = `Deleting ${backupType} backup...`;
    refreshModal.style.display = 'flex';
    
    fetch(`backup.php?delete=${filename}`)
        .then(response => response.json())
        .then(data => {
            refreshModal.style.display = 'none';
            const toast = document.createElement('div');
            toast.className = `notification-toast ${data.status}`;
            toast.innerHTML = `<span>${data.message}</span><div class="notification-progress"></div>`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.remove();
            }, 3000);

            if (data.status === 'success') {
                // Remove the deleted row from the table
                const row = document.querySelector(`tr[data-filename="${decodeURIComponent(filename)}"]`);
                if (row) {
                    row.remove();
                }
                // Check if table is empty and update UI accordingly
                const tableBody = document.querySelector('#backupsTableBody');
                if (tableBody.children.length === 0) {
                    const backupList = document.querySelector('.backup-list');
                    const noBackupsMessage = document.createElement('p');
                    noBackupsMessage.className = 'no-backups';
                    noBackupsMessage.textContent = 'No backups found.';
                    backupList.appendChild(noBackupsMessage);
                }
            }
        })
        .catch(error => {
            refreshModal.style.display = 'none';
            const toast = document.createElement('div');
            toast.className = 'notification-toast error';
            toast.innerHTML = `<span>Error deleting backup: ${error.message}</span><div class="notification-progress"></div>`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.remove();
            }, 3000);
        });
}

function handleManualBackup() {
    const form = document.getElementById('backupForm');
    const formData = new FormData(form);
    
    const refreshModal = document.getElementById('refreshLoadingModal');
    refreshModal.querySelector('p').textContent = 'Creating Manual backup...';
    refreshModal.style.display = 'flex';
    
    fetch('backup.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const a = document.createElement('a');
                a.href = data.filename;
                a.download = data.filename.split('/').pop();
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                
                const toast = document.createElement('div');
                toast.className = 'notification-toast success';
                toast.innerHTML = `<span>Backup successfully created!</span><div class="notification-progress"></div>`;
                document.body.appendChild(toast);
                setTimeout(() => {
                    toast.remove();
                }, 3000);
                
                showRefreshLoading('Refreshing backup list...');
                setTimeout(fetchBackupList, 1000); // Refresh table after creation
            } else {
                refreshModal.style.display = 'none';
                const toast = document.createElement('div');
                toast.className = 'notification-toast error';
                toast.innerHTML = `<span>${data.message}</span><div class="notification-progress"></div>`;
                document.body.appendChild(toast);
                setTimeout(() => {
                    toast.remove();
                }, 3000);
            }
        })
        .catch(error => {
            refreshModal.style.display = 'none';
            const toast = document.createElement('div');
            toast.className = 'notification-toast error';
            toast.innerHTML = `<span>Error creating backup: ${error.message}</span><div class="notification-progress"></div>`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.remove();
            }, 3000);
        });
}

document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide notification toast
    const toast = document.querySelector('.notification-toast');
    if (toast) {
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }
    
    // Initialize scheduled time
    setScheduledTime();
    
    // Update time every second
    updateTime();
    setInterval(updateTime, 1000);
    
    // Check schedule every second
    setInterval(checkSchedule, 1000);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>