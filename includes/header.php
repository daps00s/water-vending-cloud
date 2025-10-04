<?php
// header.php
require_once 'includes/auth_check.php';
require_once 'includes/db_connect.php';

function getActiveClass($currentPage) {
    return basename($_SERVER['PHP_SELF']) === $currentPage ? 'active' : '';
}

$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Water Vending Admin</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <div class="header-left">
                <img src="assets/images/logo-small.png" alt="Logo" class="logo-small">
                <div class="header-title">Smart Water Dashboard</div>
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <div class="user-info">
                <div class="user-avatar" id="userAvatar"><?php echo strtoupper(substr($_SESSION['admin_username'], 0, 1)); ?></div>
                <span class="user-name" id="userName"><?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </header>
        
        <nav class="sidebar" id="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" class="menu-link <?php echo getActiveClass('dashboard.php'); ?>">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="menu-item">
                    <a href="locations.php" class="menu-link <?php echo getActiveClass('locations.php'); ?>">
                        <i class="fas fa-map-marker-alt"></i> Locations
                    </a>
                </li>
                <li class="menu-item">
                    <a href="machines.php" class="menu-link <?php echo getActiveClass('machines.php'); ?>">
                        <i class="fas fa-water"></i> Machines
                    </a>
                </li>
                <li class="menu-item">
                    <a href="<?php echo basename($_SERVER['PHP_SELF']) === 'calibration.php' ? 'calibration.php' : 'transactions.php'; ?>" 
                       class="menu-link <?php echo getActiveClass('transactions.php') . ' ' . getActiveClass('calibration.php'); ?>">
                        <i class="fas fa-exchange-alt"></i> 
                        <?php echo basename($_SERVER['PHP_SELF']) === 'calibration.php' ? 'Accounting and Calibration' : 'Transactions'; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="coin_collections.php" class="menu-link <?php echo getActiveClass('coin_collections.php'); ?>">
                        <i class="fas fa-coins"></i> Coin Collections
                    </a>
                </li>
                <li class="menu-item">
                    <a href="water_levels.php" class="menu-link <?php echo getActiveClass('water_levels.php'); ?>">
                        <i class="fas fa-tint"></i> Water Levels
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php" class="menu-link <?php echo getActiveClass('reports.php'); ?>">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </li>
                <li class="menu-item">
                    <a href="forecast.php" class="menu-link <?php echo getActiveClass('forecast.php'); ?>">
                        <i class="fas fa-chart-line"></i> Trends
                    </a>
                </li>
                <li class="menu-item">
                    <a href="backup.php" class="menu-link <?php echo getActiveClass('backup.php'); ?>">
                        <i class="fas fa-database"></i> System Backup
                    </a>
                </li>
            </ul>
        </nav>
        
        <main class="content-area">
        
        <?php require_once 'includes/user_profile_slide.php'; ?>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const userName = document.getElementById('userName');
            const profileSlide = document.getElementById('userProfileSlide');
            const closeBtn = document.getElementById('closeProfileSlide');
            
            // Open slide panel when username is clicked
            userName.addEventListener('click', function(event) {
                event.stopPropagation();
                profileSlide.classList.add('open');
            });
            
            // Close slide panel when close button is clicked
            closeBtn.addEventListener('click', function() {
                profileSlide.classList.remove('open');
            });
            
            // Close when clicking outside the slide panel
            document.addEventListener('click', function(event) {
                if (!profileSlide.contains(event.target) && event.target !== userName) {
                    profileSlide.classList.remove('open');
                }
            });
            
            // Auto-hide notification toast
            const toast = document.querySelector('.notification-toast');
            if (toast) {
                setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => {
                        toast.style.display = 'none';
                    }, 300);
                }, 3000);
            }
            
            // Menu toggle for sidebar
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });
        });
        </script>
</body>
</html>