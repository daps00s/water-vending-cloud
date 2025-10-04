<?php

// Add this to the TOP of test_coin.php, get_transactions.php, simulate_coin.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Debug mode
$debug = $_GET['debug'] ?? false;
if ($debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Absolute path to database config
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/WVM/');
require_once ROOT_PATH . 'includes/db_connect.php';

// Simple ping endpoint
if (isset($_GET['ping'])) {
    echo json_encode([
        'status' => 'success',
        'message' => 'API is working',
        'database' => isset($pdo) ? 'Connected' : 'Not connected',
        'server_time' => date('Y-m-d H:i:s'),
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'client_ip' => $_SERVER['REMOTE_ADDR']
    ]);
    exit;
}

// Process transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    // Debug logging
    if ($debug) {
        file_put_contents(ROOT_PATH . 'logs/transactions.log', 
            date('Y-m-d H:i:s') . " - " . $json . "\n", 
            FILE_APPEND);
    }

    // Validate input
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Invalid JSON data', [
            'received' => $json,
            'json_error' => json_last_error_msg()
        ]);
    }

    // Required fields
    $required = ['coin', 'coin_type', 'machine_id', 'water_type', 'amount_dispensed'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            sendError("Missing required field: $field", $data);
        }
    }

    // Process transaction
    try {
        $coin = (float)$data['coin'];
        $coinType = trim($data['coin_type']);
        $machine_id = (int)$data['machine_id'];
        $water_type = trim($data['water_type']);
        $amount_dispensed = (float)$data['amount_dispensed'];
        
        // Validate values
        if ($coin <= 0) {
            sendError('Coin value must be positive', ['value' => $coin]);
        }
        if (!in_array($water_type, ['HOT', 'COLD'])) {
            sendError('Invalid water type', ['value' => $water_type]);
        }
        if ($amount_dispensed <= 0) {
            sendError('Amount dispensed must be positive', ['value' => $amount_dispensed]);
        }

        // Insert transaction
        $stmt = $pdo->prepare("INSERT INTO transaction 
                             (DateAndTime, dispenser_id, amount_dispensed, coin_type, water_type) 
                             VALUES (NOW(), :machine_id, :amount, :coin_type, :water_type)");
        
        $success = $stmt->execute([
            'machine_id' => $machine_id,
            'amount' => $amount_dispensed,
            'coin_type' => $coinType,
            'water_type' => $water_type
        ]);

        if ($success) {
            echo json_encode([
                'status' => 'success',
                'transaction_id' => $pdo->lastInsertId(),
                'coin_value' => $coin,
                'coin_type' => $coinType,
                'water_type' => $water_type,
                'amount_dispensed' => $amount_dispensed,
                'server_time' => date('Y-m-d H:i:s')
            ]);
        } else {
            sendError('Database operation failed', [
                'error_info' => $stmt->errorInfo()
            ]);
        }
    } catch (PDOException $e) {
        sendError('Database error', [
            'error_message' => $e->getMessage(),
            'error_code' => $e->getCode()
        ]);
    }
} else {
    sendError('Invalid request method', [
        'allowed_methods' => 'POST'
    ]);
}

function sendError($message, $details = []) {
    $response = [
        'status' => 'error',
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (!empty($details)) {
        $response['error_details'] = $details;
    }
    
    echo json_encode($response);
    exit;
}