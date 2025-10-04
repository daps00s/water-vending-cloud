<?php
// cron_backup.php
require_once __DIR__ . '/../includes/db_connect.php';

// Backup directory
$backupDir = 'backups/';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// Settings file
$settingsFile = 'config/backup_settings.json';
if (!file_exists($settingsFile)) {
    $defaultSettings = [
        'backup_hour' => 2,
        'backup_minute' => 0,
        'last_backup' => null
    ];
    file_put_contents($settingsFile, json_encode($defaultSettings, JSON_PRETTY_PRINT));
}
$settings = json_decode(file_get_contents($settingsFile), true);

// Encryption key (same as in backup.php)
$encryptionKey = 'your_secure_key_32_bytes_long_123'; // Must be 32 bytes for AES-256

// Logging function
function logBackupEvent($message, $type = 'INFO') {
    $logDir = 'logs/';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . 'backup_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] [$type] $message\n", FILE_APPEND);
}

// Function to encrypt file
function encryptFile($source, $dest, $key) {
    try {
        $cipher = "aes-256-cbc";
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $content = file_get_contents($source);
        if ($content === false) {
            throw new Exception("Failed to read source file: $source");
        }
        $encrypted = openssl_encrypt($content, $cipher, $key, 0, $iv);
        if ($encrypted === false) {
            throw new Exception("Encryption failed");
        }
        $result = file_put_contents($dest, $iv . $encrypted);
        if ($result === false) {
            throw new Exception("Failed to write encrypted file: $dest");
        }
        logBackupEvent("File encrypted successfully: $dest");
        return true;
    } catch (Exception $e) {
        logBackupEvent("Encryption error: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// Function to create database backup
function createDatabaseBackup($pdo, $backupDir, $encryptionKey, $isAuto = false) {
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        
        $output = "-- Database Backup for water_dispenser_system\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Automatic: " . ($isAuto ? 'Yes' : 'No') . "\n\n";

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

        $tempFile = $backupDir . 'temp_backup_' . time() . '.sql';
        $prefix = $isAuto ? 'auto_db_backup_' : 'manual_db_backup_';
        $filename = $backupDir . $prefix . date('Y-m-d_H-i-s') . '.sql.enc';
        
        if (!file_put_contents($tempFile, $output)) {
            throw new Exception("Failed to write temporary backup file");
        }
        
        if (!encryptFile($tempFile, $filename, $encryptionKey)) {
            throw new Exception("Failed to encrypt backup file");
        }
        
        unlink($tempFile);
        logBackupEvent("Backup created successfully: $filename", 'SUCCESS');
        return $filename;
    } catch (Exception $e) {
        logBackupEvent("Backup creation failed: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

// Get settings
$backup_hour = $settings['backup_hour'] ?? 2;
$backup_minute = $settings['backup_minute'] ?? 0;
$last_backup = $settings['last_backup'] ?? null;

// Check if it's time to backup (within a 5-minute window)
$current_hour = date('H');
$current_minute = date('i');
$current_date = date('Y-m-d');
$last_backup_date = $last_backup ? date('Y-m-d', strtotime($last_backup)) : null;

$time_match = ($current_hour == $backup_hour && abs($current_minute - $backup_minute) <= 5);
$backup_needed = ($last_backup_date === null || $last_backup_date < $current_date);

if ($time_match && $backup_needed) {
    try {
        $backupFile = createDatabaseBackup($pdo, $backupDir, $encryptionKey, true);
        
        // Update last_backup in settings
        $settings['last_backup'] = date('Y-m-d H:i:s');
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
        
        logBackupEvent("Automatic backup created: $backupFile", 'SUCCESS');
        echo "Automatic backup created successfully: $backupFile\n";
    } catch (Exception $e) {
        logBackupEvent("Automatic backup failed: " . $e->getMessage(), 'ERROR');
        echo "Failed to create automatic backup: " . $e->getMessage() . "\n";
    }
} else {
    $reason = !$time_match ? "Not within backup time window" : "Backup already created today";
    logBackupEvent($reason);
    echo "$reason\n";
}

// Enforce retention policy
$backups = array_diff(scandir($backupDir), array('..', '.'));
foreach ($backups as $backup) {
    $filePath = $backupDir . $backup;
    $fileTime = strtotime(substr($backup, strpos($backup, '_') + 1, 19));
    if (time() - $fileTime > 30 * 24 * 60 * 60) { // 30 days
        unlink($filePath);
        logBackupEvent("Retention policy: Deleted old backup $backup", 'SUCCESS');
    }
}