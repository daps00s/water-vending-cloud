<?php
// authenticate.php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';

$input_username = $_POST['username'] ?? '';
$input_password = $_POST['password'] ?? '';

// Check against userlogin table
$stmt = $pdo->prepare("SELECT * FROM userlogin WHERE username = ?");
$stmt->execute([$input_username]);
$user = $stmt->fetch();

if ($user) {
    // Verify password
    if (password_verify($input_password, $user['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $user['user_id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_email'] = $user['email'];
        $_SESSION['admin_role'] = $user['role'];
        
        header("Location: dashboard.php");
        exit;
    }
}

// If user doesn't exist or password is incorrect
header("Location: index.php?error=Invalid username or password");
exit;
?>