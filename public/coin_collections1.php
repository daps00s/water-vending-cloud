<?php
$pageTitle = 'Coin Collections';
require_once __DIR__ . '/../includes/header.php';

// Get all machines for filter dropdown
$machines = $pdo->query("
    SELECT d.dispenser_id, d.Description, l.location_name 
    FROM dispenser d
    LEFT JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id
    LEFT JOIN location l ON dl.location_id = l.location_id
    ORDER BY d.Description
")->fetchAll();

// Initial data will be loaded via AJAX
?>
<div class="content-area">
    <div class="content-wrapper">
        <div class="content-header">
            <h1 class="content-title">Coin Collections</h1>
            <div class="content-actions">
                <button class="btn-primary" id="collectCoinsBtn">
                    <i class="fas fa-coins"></i> Collect Coins
                </button>
            </div>
        </div>

        <div class="filters-container">
            <div class="machine-filter">
                <select id="machineFilter" onchange="applyFilters()">
                    <option value="">All Machines</option>
                    <?php foreach($machines as $machine): ?>
                    <option value="<?php echo $machine['dispenser_id']; ?>">
                        <?php echo htmlspecialchars($machine['Description']) . ' (' . htmlspecialchars($machine['location_name'] ?? 'N/A') . ')'; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="date-filter">
                <label for="startDate">Start Date:</label>
                <input type="date" id="startDate" onchange="applyFilters()">
                <label for="endDate">End Date:</label>
                <input type="date" id="endDate" onchange="applyFilters()">
            </div>
        </div>

        <div class="coin-display-container">
            <div class="coin-animation">
                <div class="coin">
                    <div class="coin-front">
                        <div class="coin-text">COINS</div>
                        <div class="coin-value" id="totalCoins">0</div>
                    </div>
                    <div class="coin-back">
                        <div class="coin-text">TOTAL</div>
                        <div class="coin-value" id="totalCoinsBack">0</div>
                    </div>
                </div>
                <div class="coin-shadow"></div>
            </div>
            
            <div class="collection-info" id="collectionInfo">
                <h2 id="machineTitle">All Machines</h2>
                <p class="location" id="machineLocation">Combined Collection Data</p>
                <p class="last-collection" id="lastCollection">No collections recorded</p>
                
                <div class="stats">
                    <div class="stat">
                        <span class="stat-value" id="totalCollections">0</span>
                        <span class="stat-label">Transactions</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value" id="totalCoinsStat">₱0 Pesos</span>
                        <span class="stat-label">Total Coins</span>
                    </div>
                </div>
                
                <div class="coin-types">
                    <h3>Coin Types Breakdown</h3>
                    <div class="coin-type-list" id="coinTypesList"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.content-area {
    padding: 30px 0 0 0;
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
    font-weight: 600;
    color: #2c3e50;
}

.content-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.filters-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 10px;
}

.machine-filter select {
    padding: 8px 12px;
    border-radius: 4px;
    border: 1px solid #ddd;
    font-size: 14px;
    width: 100%;
    max-width: 300px;
    background: white;
    cursor: pointer;
}

.date-filter {
    display: flex;
    align-items: center;
    gap: 10px;
}

.date-filter label {
    font-size: 14px;
    color: #2c3e50;
}

.date-filter input {
    padding: 8px;
    border-radius: 4px;
    border: 1px solid #ddd;
    font-size: 14px;
}

.coin-display-container {
    display: flex;
    gap: 20px;
    align-items: flex-start;
    margin-top: 20px;
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.coin-animation {
    position: relative;
    width: 150px;
    height: 150px;
    flex-shrink: 0;
    perspective: 1000px;
}

.coin {
    width: 100%;
    height: 100%;
    position: relative;
    transform-style: preserve-3d;
    animation: spin 4s linear infinite;
    transition: transform 0.5s;
}

.coin-front, .coin-back {
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    backface-visibility: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
}

.coin-front {
    background: linear-gradient(135deg, #f1c40f, #d4a017);
    transform: rotateY(0deg);
}

.coin-back {
    background: linear-gradient(135deg, #d4a017, #f1c40f);
    transform: rotateY(180deg);
}

.coin-text {
    font-size: 20px;
    font-weight: bold;
    color: white;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
    margin-bottom: 5px;
}

.coin-value {
    font-size: 24px;
    font-weight: bold;
    color: white;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}

.coin-shadow {
    position: absolute;
    bottom: -10px;
    left: 50%;
    transform: translateX(-50%);
    width: 60%;
    height: 10px;
    background: rgba(0,0,0,0.1);
    border-radius: 50%;
    filter: blur(5px);
}

@keyframes spin {
    0% { transform: rotateY(0deg); }
    100% { transform: rotateY(360deg); }
}

.collection-info {
    flex-grow: 1;
    padding: 15px;
}

.collection-info h2 {
    margin: 10px 0 5px 0;
    font-size: 20px;
    font-weight: 600;
    color: #2c3e50;
}

.collection-info .location {
    color: #7f8c8d;
    font-size: 14px;
    margin: 0 0 10px 0;
}

.collection-info .last-collection {
    color: #3498db;
    font-size: 14px;
    margin: 0 0 10px 0;
    font-weight: 500;
}

.stats {
    display: flex;
    gap: 20px;
    margin: 20px 0;
    align-items: center;
}

.stat {
    text-align: center;
}

.stat-value {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #3498db;
}

.stat-label {
    display: block;
    color: #7f8c8d;
    font-size: 12px;
}

.btn-primary {
    background-color: #3498db;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}

.btn-primary:hover {
    background-color: #2980b9;
    transform: translateY(-2px);
}

.coin-types h3 {
    margin: 0 0 10px 0;
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
    border-bottom: 1px solid #ecf0f1;
    padding-bottom: 8px;
}

.coin-type-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 10px;
}

.coin-type-item {
    background: #f8f9fa;
    padding: 10px 12px;
    border-radius: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.coin-type-name {
    font-weight: 500;
    font-size: 14px;
    color: #2c3e50;
}

.coin-type-amount {
    color: #3498db;
    font-weight: bold;
    font-size: 14px;
}

@media (max-width: 768px) {
    .coin-display-container {
        flex-direction: column;
        align-items: center;
    }
    
    .coin-animation {
        width: 120px;
        height: 120px;
    }
    
    .stats {
        flex-direction: column;
        gap: 15px;
    }
    
    .coin-text {
        font-size: 16px;
    }
    
    .coin-value {
        font-size: 20px;
    }
    
    .filters-container {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .date-filter {
        flex-wrap: wrap;
    }
}
</style>

<script>
let autoRefreshInterval;

function fetchCoinData() {
    const machineId = document.getElementById('machineFilter').value;
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    
    const params = new URLSearchParams();
    if (machineId) params.append('machine_id', machineId);
    if (startDate) params.append('start_date', startDate);
    if (endDate) params.append('end_date', endDate);
    
    fetch('fetch_coins.php?' + params.toString(), {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' }
    })
        .then(response => response.json())
        .then(data => {
            updateCoinDisplay(data);
        })
        .catch(error => {
            console.error('Error fetching coin data:', error);
        });
}

function updateCoinDisplay(data) {
    // Update total coins
    document.getElementById('totalCoins').textContent = data.totalCoins.toLocaleString();
    document.getElementById('totalCoinsBack').textContent = data.totalCoins.toLocaleString();
    document.getElementById('totalCoinsStat').textContent = '₱' + data.totalCoins.toLocaleString() + ' Pesos';
    
    // Update collection info
    document.getElementById('machineTitle').textContent = data.currentMachine ? data.currentMachine.Description : 'All Machines';
    document.getElementById('machineLocation').textContent = data.currentMachine ? (data.currentMachine.location_name || 'N/A') : 'Combined Collection Data';
    document.getElementById('lastCollection').textContent = data.lastCollection ? new Date(data.lastCollection).toLocaleString('en-US', {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    }) : 'No collections recorded';
    
    // Update stats
    document.getElementById('totalCollections').textContent = data.totalCollections;
    
    // Update coin types
    const coinTypesList = document.getElementById('coinTypesList');
    coinTypesList.innerHTML = '';
    Object.entries(data.coinTypes)
        .sort((a, b) => b[1] - a[1])
        .forEach(([type, value], index, array) => {
            if (type === '1Peso' && index === array.length - 1) return; // Skip 1Peso if last
            const item = document.createElement('div');
            item.className = 'coin-type-item';
            item.innerHTML = `
                <span class="coin-type-name">${type}</span>
                <span class="coin-type-amount">${value.toLocaleString()}</span>
            `;
            coinTypesList.appendChild(item);
        });
}

function applyFilters() {
    clearInterval(autoRefreshInterval);
    fetchCoinData();
    autoRefreshInterval = setInterval(fetchCoinData, 5000); // Refresh every 5 seconds
}

document.getElementById('collectCoinsBtn').addEventListener('click', function() {
    const btn = this;
    const originalText = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Collecting...';
    btn.disabled = true;
    
    setTimeout(() => {
        alert('Coins collected successfully!');
        btn.innerHTML = originalText;
        btn.disabled = false;
        fetchCoinData();
    }, 1500);
});

const coin = document.querySelector('.coin');
coin.addEventListener('mouseenter', () => {
    coin.style.animationPlayState = 'paused';
});
coin.addEventListener('mouseleave', () => {
    coin.style.animationPlayState = 'running';
});

// Initial fetch and start auto-refresh
fetchCoinData();
autoRefreshInterval = setInterval(fetchCoinData, 5000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>