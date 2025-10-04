<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';

// Initialize session flag for low water modal
if (!isset($_SESSION['low_water_modal_shown'])) {
    $_SESSION['low_water_modal_shown'] = false;
}

// Get statistics - POSTGRESQL COMPATIBLE QUERIES
$dispensers = $pdo->query("SELECT COUNT(*) as total FROM dispenser")->fetch();
$active_locations = $pdo->query("SELECT COUNT(DISTINCT location_id) as total FROM dispenserlocation WHERE Status = 1")->fetch();
$recent_transactions = $pdo->query("SELECT COUNT(*) as total FROM transaction WHERE DateAndTime >= NOW() - INTERVAL '7 days'")->fetch();

// Fixed alerts query - PostgreSQL compatible
$alerts = $pdo->query("
    SELECT 
        d.dispenser_id,
        d.description as machine_name,
        COALESCE(ds.water_level, 0) as water_level,
        d.capacity,
        l.location_name
    FROM dispenser d
    JOIN dispenserstatus ds ON d.dispenser_id = ds.dispenser_id
    JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
    LEFT JOIN location l ON dl.location_id = l.location_id
    WHERE ds.water_level < 2 AND dl.status = 1
")->fetchAll();

// PostgreSQL compatible coin calculation
$total_coins = $pdo->query("
    SELECT COALESCE(SUM(
        CASE 
            WHEN coin_type LIKE '%1 Peso%' THEN 1
            WHEN coin_type LIKE '%5 Peso%' THEN 5
            WHEN coin_type LIKE '%10 Peso%' THEN 10
            ELSE 0 
        END
    ), 0) as total 
    FROM transaction 
    WHERE DateAndTime >= NOW() - INTERVAL '7 days'
")->fetch();

// Get recent transactions
$transactions = $pdo->query("
    SELECT t.transaction_id, t.amount_dispensed, t.DateAndTime, d.description 
    FROM transaction t
    JOIN dispenser d ON t.dispenser_id = d.dispenser_id
    ORDER BY t.DateAndTime DESC LIMIT 10
")->fetchAll();
?>
<div class="content-area">
    <div class="content-wrapper">
        <div class="content-header">
            <h1 class="content-title">Dashboard Overview</h1>
            <div class="content-actions">
                <!-- Add Machine moved to stat card -->
            </div>
        </div>
        
        <div class="stats-grid">
            <a href="machines.php" class="stat-card machines">
                <div class="stat-icon"><i class="fas fa-cogs"></i></div>
                <div class="stat-content">
                    <div class="stat-title">Total Machines</div>
                    <div class="stat-value"><?php echo $dispensers['total']; ?></div>
                    <div class="stat-change success">
                        <i class="fas fa-arrow-up"></i> 12% from last month
                    </div>
                </div>
            </a>
            <a href="locations.php" class="stat-card locations">
                <div class="stat-icon"><i class="fas fa-map-marker-alt"></i></div>
                <div class="stat-content">
                    <div class="stat-title">Active Locations</div>
                    <div class="stat-value"><?php echo $active_locations['total']; ?></div>
                    <div class="stat-change success">
                        <i class="fas fa-arrow-up"></i> 5% from last month
                    </div>
                </div>
            </a>
            <a href="transactions.php" class="stat-card transactions" id="showTransactions">
                <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                <div class="stat-content">
                    <div class="stat-title">Recent Transactions</div>
                    <div class="stat-value"><?php echo $recent_transactions['total']; ?></div>
                    <div class="stat-change danger">
                        <i class="fas fa-arrow-down"></i> 8% from last week
                    </div>
                </div>
            </a>
            <a href="coin_collections.php" class="stat-card coins">
                <div class="stat-icon"><i class="fas fa-coins"></i></div>
                <div class="stat-content">
                    <div class="stat-title">Coin Collections</div>
                    <div class="stat-value"><?php echo number_format($total_coins['total'] ?? 0); ?> PHP</div>
                    <div class="stat-change success">
                        <i class="fas fa-arrow-up"></i> Weekly Total
                    </div>
                </div>
            </a>
            <a href="water_levels.php" class="stat-card water-level">
                <div class="stat-icon"><i class="fas fa-tint"></i></div>
                <div class="stat-content">
                    <div class="stat-title">Active Alerts</div>
                    <div class="water-level-bar">
                        <div class="water-level-fill <?php echo count($alerts) < 1 ? 'success' : (count($alerts) < 2 ? 'warning' : 'danger'); ?>" 
                             style="width: <?php echo min(count($alerts) * 20, 100); ?>%">
                            <?php echo count($alerts); ?> Low
                        </div>
                    </div>
                    <div class="stat-change">
                        <i class="fas fa-bell"></i> Needs attention
                    </div>
                </div>
            </a>
            <a href="machines.php?showAddModal=true" class="stat-card add-machine">
                <div class="stat-icon"><i class="fas fa-plus-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-title">Add New Machine</div>
                    <div class="stat-value">Create</div>
                    <div class="stat-change"><i class="fas fa-arrow-right"></i> Go to Form</div>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Low Water Level Modal -->
<div class="modal" id="lowWaterModal">
    <div class="modal-content">
        <span class="close-modal">×</span>
        <h2>Low Water Level Alerts</h2>
        <div class="alert-list">
            <?php if (empty($alerts)): ?>
                <p>No low water level alerts at this time.</p>
            <?php else: ?>
                <p>The following machines have low water levels:</p>
                <ul>
                    <?php foreach ($alerts as $alert): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($alert['machine_name']); ?></strong>
                            <br>Location: <?php echo htmlspecialchars($alert['location_name'] ?? 'Not Deployed'); ?>
                            <br>Water Level: <?php echo $alert['water_level']; ?>L / <?php echo $alert['capacity']; ?>L
                            <br>Status: <span class="status-badge <?php echo $alert['water_level'] < 1 ? 'inactive' : 'warning'; ?>">
                                <?php echo $alert['water_level'] < 1 ? 'Critical' : 'Low'; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php if (!empty($alerts) && isset($_SESSION['admin_role']) && $_SESSION['admin_role'] == 'admin'): ?>
            <h3>Record Water Refill</h3>
            <form id="refillForm">
                <div class="input-group">
                    <label for="refillDispenserId">Select Machine</label>
                    <select name="dispenser_id" id="refillDispenserId" required>
                        <?php foreach ($alerts as $alert): ?>
                            <option value="<?php echo $alert['dispenser_id']; ?>">
                                <?php echo htmlspecialchars($alert['machine_name']) . ' (' . htmlspecialchars($alert['location_name'] ?? 'Not Deployed') . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label for="refillAmount">Amount Added (Liters)</label>
                    <input type="number" id="refillAmount" name="amount" step="0.1" min="0.1" required>
                </div>
                <button type="submit" class="btn-primary">Submit Refill</button>
            </form>
        <?php endif; ?>
        <button type="button" class="btn-primary okay-modal">Okay</button>
    </div>
</div>

<!-- Recent Transactions Modal -->
<div class="modal" id="recentTransactionsModal">
    <div class="modal-content">
        <span class="close-modal">×</span>
        <h2>Recent Transactions</h2>
        <div class="transactions-scroll-container">
            <div class="transactions-list">
                <?php foreach($transactions as $transaction): ?>
                    <div class="transaction-item">
                        <div class="transaction-icon">
                            <i class="fas fa-tint"></i>
                        </div>
                        <div class="transaction-details">
                            <div class="transaction-amount"><?php echo $transaction['amount_dispensed']; ?>L</div>
                            <div class="transaction-desc"><?php echo htmlspecialchars($transaction['description']); ?></div>
                            <div class="transaction-time"><?php echo date('M j, h:i A', strtotime($transaction['DateAndTime'])); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Low Water Modal
    const lowWaterModal = document.getElementById('lowWaterModal');
    <?php if (!empty($alerts) && !$_SESSION['low_water_modal_shown']): ?>
        lowWaterModal.style.display = 'block';
    <?php endif; ?>

    // Recent Transactions Modal
    const transactionsModal = document.getElementById('recentTransactionsModal');
    const showTransactions = document.querySelector('.stat-card.transactions');

    showTransactions.addEventListener('click', function(e) {
        e.preventDefault();
        transactionsModal.style.display = 'block';
    });

    // Close modals
    document.querySelectorAll('.close-modal').forEach(button => {
        button.addEventListener('click', function() {
            this.closest('.modal').style.display = 'none';
            if (this.closest('.modal').id === 'lowWaterModal') {
                fetch('set_modal_flag.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ low_water_modal_shown: true })
                });
            }
        });
    });

    // Okay button for low water modal
    const okayModal = document.querySelector('.okay-modal');
    if (okayModal) {
        okayModal.addEventListener('click', function() {
            lowWaterModal.style.display = 'none';
            fetch('set_modal_flag.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ low_water_modal_shown: true })
            });
        });
    }

    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target.className === 'modal') {
            event.target.style.display = 'none';
            if (event.target.id === 'lowWaterModal') {
                fetch('set_modal_flag.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ low_water_modal_shown: true })
                });
            }
        }
    });

    // Refill form submission
    const refillForm = document.getElementById('refillForm');
    if (refillForm) {
        refillForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const amount = parseFloat(document.getElementById('refillAmount').value);
            
            if (isNaN(amount) || amount <= 0) {
                showNotification('error', 'Please enter a valid amount greater than 0.');
                return;
            }

            const formData = new FormData(this);
            
            fetch('update_water_level.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', data.message);
                    lowWaterModal.style.display = 'none';
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showNotification('error', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('error', 'An error occurred while updating the water level');
            });
        });
    }

    // Show notification function
    function showNotification(type, message) {
        const toast = document.createElement('div');
        toast.className = `notification-toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'fadeOut 0.5s forwards';
            setTimeout(() => toast.remove(), 500);
        }, 2500);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>