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
$alerts = $pdo->query("
    SELECT 
        d.dispenser_id,
        d.Description as machine_name,
        COALESCE(ds.water_level, 0) as water_level,
        d.Capacity,
        l.location_name
    FROM dispenser d
    JOIN dispenserstatus ds ON d.dispenser_id = ds.dispenser_id
    JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
    LEFT JOIN location l ON dl.location_id = l.location_id
    WHERE ds.water_level < 2 AND dl.Status = 1
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
    SELECT t.transaction_id, t.amount_dispensed, t.DateAndTime, d.Description 
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
                            <br>Water Level: <?php echo $alert['water_level']; ?>L / <?php echo $alert['Capacity']; ?>L
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
                            <div class="transaction-desc"><?php echo htmlspecialchars($transaction['Description']); ?></div>
                            <div class="transaction-time"><?php echo date('M j, h:i A', strtotime($transaction['DateAndTime'])); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
.content-area {
    padding: 20px 0 0 0;
    background-color: #f8f9fa;
    width: 100%;
    margin-left: 0;
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

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.stat-card {
    display: flex;
    align-items: center;
    padding: 20px;
    border-radius: 8px;
    background: white;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
    text-decoration: none;
    color: inherit;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.15);
}

.stat-icon {
    font-size: 2em;
    margin-right: 15px;
    color: white;
    background: linear-gradient(135deg, rgba(0,0,0,0.2), rgba(0,0,0,0.1));
    border-radius: 50%;
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-content {
    flex: 1;
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

.stat-change.success {
    color: #2ecc71;
}

.stat-change.danger {
    color: #e74c3c;
}

.stat-card.machines {
    background: linear-gradient(135deg, #0099ff, #1a5a85);
    color: white;
}

.stat-card.machines .stat-title,
.stat-card.machines .stat-value {
    color: white;
}

.stat-card.locations {
    background: linear-gradient(135deg, #2ecc71, #2b6443);
    color: white;
}

.stat-card.locations .stat-title,
.stat-card.locations .stat-value {
    color: white;
}

.stat-card.transactions {
    background: linear-gradient(135deg, #f39c12, rgb(94, 44, 0));
    color: white;
}

.stat-card.transactions .stat-title,
.stat-card.transactions .stat-value {
    color: white;
}

.stat-card.coins {
    background: linear-gradient(135deg, #f1c40f, #6a4d02);
    color: white;
}

.stat-card.coins .stat-title,
.stat-card.coins .stat-value {
    color: white;
}

.stat-card.water-level {
    background: linear-gradient(135deg, #1abc9c, #00483a);
    color: white;
}

.stat-card.water-level .stat-title,
.stat-card.water-level .stat-value {
    color: white;
}

.stat-card.add-machine {
    background: linear-gradient(135deg, #9b59b6, #8e44ad);
    color: white;
}

.stat-card.add-machine .stat-title,
.stat-card.add-machine .stat-value {
    color: white;
}

.water-level-bar {
    width: 100%;
    height: 24px;
    background-color: rgba(255,255,255,0.2);
    border-radius: 4px;
    overflow: hidden;
    position: relative;
    margin: 10px 0;
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
    animation: blink 1s infinite;
}

@keyframes blink {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.transactions-scroll-container {
    max-height: 300px;
    overflow-y: auto;
    padding-right: 8px;
}

.transactions-scroll-container::-webkit-scrollbar {
    width: 6px;
}

.transactions-scroll-container::-webkit-scrollbar-track {
    background: #f8f9fa;
    border-radius: 3px;
}

.transactions-scroll-container::-webkit-scrollbar-thumb {
    background: #3498db;
    border-radius: 3px;
}

.transactions-scroll-container::-webkit-scrollbar-thumb:hover {
    background: #2980b9;
}

.transactions-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.transaction-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-radius: 6px;
    background: #f8f9fa;
    transition: all 0.2s;
    min-height: 60px;
}

.transaction-item:hover {
    background: #e8ecef;
    transform: scale(1.02);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.transaction-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0099ff, #1a5a85);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    color: white;
    font-size: 16px;
    flex-shrink: 0;
}

.transaction-details {
    flex: 1;
    min-width: 0;
}

.transaction-amount {
    font-weight: bold;
    font-size: 16px;
    color: #2c3e50;
}

.transaction-desc {
    font-size: 14px;
    color: #7f8c8d;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.transaction-time {
    font-size: 12px;
    color: #95a5a6;
    margin-top: 4px;
}

.btn-primary {
    color: #fff;
    background-color: #3498db;
    border-color: #3498db;
    padding: 8px 16px;
    font-size: 14px;
    border-radius: 4px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.btn-primary:hover {
    background-color: #2980b9;
    border-color: #2980b9;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: white;
    margin: 10% auto;
    padding: 20px;
    border-radius: 8px;
    width: 80%;
    max-width: 400px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.close-modal {
    position: absolute;
    right: 20px;
    top: 10px;
    font-size: 24px;
    cursor: pointer;
    color: #2c3e50;
}

.close-modal:hover {
    color: #e74c3c;
}

.alert-list {
    margin-bottom: 20px;
}

.alert-list p {
    margin: 0 0 10px 0;
    color: #2c3e50;
}

.alert-list ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.alert-list li {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 10px;
    font-size: 14px;
    color: #2c3e50;
}

.alert-list li strong {
    color: #3498db;
}

.input-group {
    margin-bottom: 15px;
}

.input-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #2c3e50;
}

.input-group input,
.input-group select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8em;
    font-weight: 500;
}

.status-badge.warning {
    background-color: #f39c12;
    color: white;
}

.status-badge.inactive {
    background-color: #e74c3c;
    color: white;
}

.okay-modal {
    margin-top: 15px;
    width: 100%;
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

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .modal-content {
        width: 90%;
        margin: 20% auto;
    }
}
</style>

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