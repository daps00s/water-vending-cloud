<?php
$pageTitle = 'Locations';
require_once __DIR__ . '/../includes/header.php';

// Handle form submissions
$notification = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_location'])) {
        // Add new location
        $stmt = $pdo->prepare("INSERT INTO location (location_name, address, latitude, longitude) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$_POST['location_name'], $_POST['address'], $_POST['latitude'], $_POST['longitude']])) {
            $notification = 'success|Location successfully added!';
        } else {
            $notification = 'error|Failed to add location.';
        }
    } elseif (isset($_POST['edit_location'])) {
        // Edit existing location
        $stmt = $pdo->prepare("UPDATE location SET location_name = ?, address = ?, latitude = ?, longitude = ? WHERE location_id = ?");
        if ($stmt->execute([$_POST['location_name'], $_POST['address'], $_POST['latitude'], $_POST['longitude'], $_POST['location_id']])) {
            $notification = 'success|Location successfully updated!';
        } else {
            $notification = 'error|Failed to update location.';
        }
    } elseif (isset($_POST['confirm_delete'])) {
        // Delete location - PostgreSQL compatible
        try {
            $pdo->beginTransaction();
            
            // Update dispenserlocation to set status = 0 and location_id to NULL
            $stmt = $pdo->prepare("UPDATE dispenserlocation SET status = 0, location_id = NULL WHERE location_id = ?");
            $stmt->execute([$_POST['location_id']]);
            
            // Delete the location
            $stmt = $pdo->prepare("DELETE FROM location WHERE location_id = ?");
            if ($stmt->execute([$_POST['location_id']])) {
                $pdo->commit();
                $notification = 'success|Location successfully deleted!';
            } else {
                throw new PDOException("Failed to delete location.");
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $notification = 'error|Failed to delete location: ' . $e->getMessage();
        }
    }
}

// Insert additional transaction data for forecast calculation (simulating multiple days)
$additional_transactions = [
    ['amount_dispensed' => 2.5, 'DateAndTime' => '2025-07-15 10:00:00', 'coin_type' => '5 Peso', 'dispenser_id' => 27],
    ['amount_dispensed' => 5.0, 'DateAndTime' => '2025-07-15 12:00:00', 'coin_type' => '10 Peso', 'dispenser_id' => 27],
    ['amount_dispensed' => 0.5, 'DateAndTime' => '2025-07-16 09:00:00', 'coin_type' => '1 Peso', 'dispenser_id' => 27],
    // ... rest of your transaction data
];

// Insert additional transactions into the database
$stmt = $pdo->prepare("INSERT INTO transaction (amount_dispensed, DateAndTime, coin_type, dispenser_id) VALUES (?, ?, ?, ?)");
foreach ($additional_transactions as $trans) {
    $stmt->execute([$trans['amount_dispensed'], $trans['DateAndTime'], $trans['coin_type'], $trans['dispenser_id']]);
}

// Fetch locations with machine count and total liters for table
$locations = $pdo->query("SELECT l.*, COUNT(dl.dispenser_id) as machine_count, 
                          COALESCE(SUM(t.amount_dispensed), 0) as total_liters
                          FROM location l
                          LEFT JOIN dispenserlocation dl ON l.location_id = dl.location_id AND dl.status = 1
                          LEFT JOIN transaction t ON dl.dispenser_id = t.dispenser_id
                          GROUP BY l.location_id")->fetchAll();

// PostgreSQL compatible queries using STRING_AGG instead of GROUP_CONCAT
// Fetch all locations for map (only active dispensers)
$all_locations = $pdo->query("SELECT l.location_id, l.location_name, l.latitude, l.longitude, 
                              COALESCE(SUM(t.amount_dispensed), 0) as total_liters,
                              STRING_AGG(DISTINCT d.description, ', ') as descriptions
                              FROM location l
                              LEFT JOIN dispenserlocation dl ON l.location_id = dl.location_id AND dl.status = 1
                              LEFT JOIN dispenser d ON dl.dispenser_id = d.dispenser_id
                              LEFT JOIN transaction t ON dl.dispenser_id = t.dispenser_id
                              WHERE l.latitude IS NOT NULL AND l.longitude IS NOT NULL
                              GROUP BY l.location_id")->fetchAll();

// Fetch ITC-specific dispensers (at CET - ITC coordinates)
$itc_dispensers = $pdo->query("SELECT l.location_id, l.location_name, l.latitude, l.longitude, 
                               COALESCE(SUM(t.amount_dispensed), 0) as total_liters,
                               STRING_AGG(DISTINCT d.description, ', ') as descriptions
                               FROM location l
                               LEFT JOIN dispenserlocation dl ON l.location_id = dl.location_id AND dl.status = 1
                               LEFT JOIN dispenser d ON dl.dispenser_id = d.dispenser_id
                               LEFT JOIN transaction t ON dl.dispenser_id = t.dispenser_id
                               WHERE l.latitude = 15.63954742 AND l.longitude = 120.41917920
                               GROUP BY l.location_id")->fetchAll();

// Fetch top 5 locations (highest total liters dispensed)
$top_locations = $pdo->query("SELECT l.location_id, l.location_name, l.latitude, l.longitude, 
                              COALESCE(SUM(t.amount_dispensed), 0) as total_liters,
                              STRING_AGG(DISTINCT d.description, ', ') as descriptions
                              FROM location l
                              LEFT JOIN dispenserlocation dl ON l.location_id = dl.location_id AND dl.status = 1
                              LEFT JOIN dispenser d ON dl.dispenser_id = d.dispenser_id
                              LEFT JOIN transaction t ON dl.dispenser_id = t.dispenser_id
                              WHERE l.latitude IS NOT NULL AND l.longitude IS NOT NULL
                              GROUP BY l.location_id
                              ORDER BY total_liters DESC
                              LIMIT 5")->fetchAll();

// Fetch top 5 machines (highest total liters dispensed)
$top_machines = $pdo->query("SELECT d.dispenser_id, d.description, l.location_name, 
                             COALESCE(SUM(t.amount_dispensed), 0) as total_liters
                             FROM dispenser d
                             LEFT JOIN dispenserlocation dl ON d.dispenser_id = dl.dispenser_id AND dl.status = 1
                             LEFT JOIN location l ON dl.location_id = l.location_id
                             LEFT JOIN transaction t ON d.dispenser_id = t.dispenser_id
                             GROUP BY d.dispenser_id, d.description, l.location_name
                             ORDER BY total_liters DESC
                             LIMIT 5")->fetchAll();

// Determine trend data based on GET parameters - PostgreSQL compatible
$trend_data = [];
$interval = 30; // Default
$is_custom = false;
if (isset($_GET['period'])) {
    $period = $_GET['period'];
    if ($period === '7') {
        $interval = 7;
    } elseif ($period === 'custom' && isset($_GET['start']) && isset($_GET['end'])) {
        $is_custom = true;
        $stmt = $pdo->prepare("SELECT DATE(t.DateAndTime) as date, COALESCE(SUM(t.amount_dispensed), 0) as total_liters
                               FROM transaction t
                               LEFT JOIN dispenserlocation dl ON t.dispenser_id = dl.dispenser_id
                               WHERE dl.status = 1 AND t.DateAndTime IS NOT NULL
                               AND DATE(t.DateAndTime) BETWEEN :start AND :end
                               GROUP BY DATE(t.DateAndTime)
                               ORDER BY date");
        $stmt->execute(['start' => $_GET['start'], 'end' => $_GET['end']]);
        $trend_data = $stmt->fetchAll();
    }
}
if (!$is_custom) {
    $stmt = $pdo->prepare("SELECT DATE(t.DateAndTime) as date, COALESCE(SUM(t.amount_dispensed), 0) as total_liters
                           FROM transaction t
                           LEFT JOIN dispenserlocation dl ON t.dispenser_id = dl.dispenser_id
                           WHERE dl.status = 1 AND t.DateAndTime IS NOT NULL
                           AND t.DateAndTime >= CURRENT_DATE - INTERVAL ':interval days'
                           GROUP BY DATE(t.DateAndTime)
                           ORDER BY date");
    $stmt->execute(['interval' => $interval]);
    $trend_data = $stmt->fetchAll();
}
if (!$trend_data) {
    $trend_data = [];
}

// Calculate forecast for 30 days from now
$forecast = null;
$forecast_date = date('Y-m-d', strtotime('+30 days'));
$first_date = $trend_data && !empty($trend_data) ? reset($trend_data)['date'] : null;
if ($trend_data && count($trend_data) >= 2 && $first_date) {
    $dates = array_map(function($row) use ($first_date) {
        return (strtotime($row['date']) - strtotime($first_date)) / 86400; // Days since first date
    }, $trend_data);
    $liters = array_column($trend_data, 'total_liters');
    $n = count($dates);
    $sum_x = array_sum($dates);
    $sum_y = array_sum($liters);
    $sum_xy = 0;
    $sum_xx = 0;
    for ($i = 0; $i < $n; $i++) {
        $sum_xy += $dates[$i] * $liters[$i];
        $sum_xx += $dates[$i] * $dates[$i];
    }
    $slope = ($n * $sum_xy - $sum_x * $sum_y) / ($n * $sum_xx - $sum_x * $sum_x);
    $intercept = ($sum_y - $slope * $sum_x) / $n;
    $forecast_days = (strtotime($forecast_date) - strtotime($first_date)) / 86400;
    $forecast = max(0, $slope * $forecast_days + $intercept);
}

// Fetch max total liters for color scaling
$max_total_liters = $top_locations ? $top_locations[0]['total_liters'] : 1; // Avoid division by zero
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
            <h1 class="content-title">Location Management</h1>
            <div class="content-actions">
                <div class="search-group">
                    <label for="searchInput">Search:</label>
                    <input type="text" id="searchInput" placeholder="Search locations...">
                </div>
                <div class="rows-per-page">
                    <label for="rowsPerPage">Rows per page:</label>
                    <select id="rowsPerPage">
                        <option value="5">5</option>
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="30">30</option>
                    </select>
                </div>
                <div>
                    <button class="btn-primary" id="addLocationBtn">
                        <i class="fas fa-plus"></i> Add New Location
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Table Section -->
        <div class="table-container">
            <table class="data-table" id="locationsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Location Name</th>
                        <th>Address</th>
                        <th>Latitude</th>
                        <th>Longitude</th>
                        <th>Total Dispensed (L)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($locations as $location): ?>
                    <tr>
                        <td><?php echo $location['location_id']; ?></td>
                        <td><?php echo htmlspecialchars($location['location_name']); ?></td>
                        <td><?php echo htmlspecialchars($location['address']); ?></td>
                        <td><?php echo number_format($location['latitude'], 6); ?></td>
                        <td><?php echo number_format($location['longitude'], 6); ?></td>
                        <td><?php echo number_format($location['total_liters'], 2); ?></td>
                        <td>
                            <button class="btn-action edit" onclick="showEditModal(
                                <?= $location['location_id'] ?>, 
                                '<?= addslashes($location['location_name']) ?>', 
                                '<?= addslashes($location['address']) ?>',
                                '<?= addslashes($location['latitude'] ?? '') ?>',
                                '<?= addslashes($location['longitude'] ?? '') ?>'
                            )">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-action delete" onclick="showDeleteModal(<?= $location['location_id'] ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                            <button class="btn-action view-machines" onclick="showMachinesModal(<?= $location['location_id'] ?>, '<?= addslashes($location['location_name']) ?>')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="pagination" id="pagination"></div>
        </div>
        
        <!-- Map Section -->
        <div class="map-container">
            <h2>Water Dispenser Distribution Map</h2>
            <div class="map-controls">
                <label><input type="checkbox" id="toggleHeatmap" checked> Show Heatmap</label>
                <label><input type="checkbox" id="toggleMarkers" checked> Show Markers</label>
            </div>
            <div id="map" style="height: 400px; margin-bottom: 20px; z-index: 1; position: relative;"></div>
            <!-- Heatmap Legend -->
            <div class="heatmap-legend">
                <h3>Heatmap Intensity</h3>
                <div class="legend-scale">
                    <div class="legend-color" style="background: linear-gradient(to right, #0000ff, #00ff00, #ffff00, #ff0000);"></div>
                    <div class="legend-labels">
                        <span>Low Usage</span>
                        <span>High Usage</span>
                    </div>
                </div>
            </div>
            <!-- Top Locations and Machines Charts -->
            <div class="map-info">
                <h3>Top 5 Locations by Usage</h3>
                <canvas id="topLocationsChart" style="max-height: 200px;"></canvas>
                <h3>Top 5 Machines by Usage</h3>
                <canvas id="topMachinesChart" style="max-height: 200px;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Add Location Modal -->
<div class="modal" id="addLocationModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Location</h2>
            <span class="close-modal">×</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="input-group">
                    <label for="add_location_name">Location Name</label>
                    <input type="text" id="add_location_name" name="location_name" required>
                </div>
                <div class="input-group">
                    <label for="add_address">Address</label>
                    <textarea id="add_address" name="address" rows="3" required></textarea>
                </div>
                <div class="input-group">
                    <label for="add_latitude">Latitude</label>
                    <input type="number" step="any" id="add_latitude" name="latitude" required>
                </div>
                <div class="input-group">
                    <label for="add_longitude">Longitude</label>
                    <input type="number" step="any" id="add_longitude" name="longitude" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="add_location" class="btn-primary">Save Location</button>
                <button type="button" class="btn-secondary" onclick="closeModal('addLocationModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Location Modal -->
<div class="modal" id="editLocationModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit Location</h2>
            <span class="close-modal">×</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="location_id" id="edit_location_id">
                <div class="input-group">
                    <label for="edit_location_name">Location Name</label>
                    <input type="text" id="edit_location_name" name="location_name" required>
                </div>
                <div class="input-group">
                    <label for="edit_address">Address</label>
                    <textarea id="edit_address" name="address" rows="3" required></textarea>
                </div>
                <div class="input-group">
                    <label for="edit_latitude">Latitude</label>
                    <input type="number" step="any" id="edit_latitude" name="latitude" required>
                </div>
                <div class="input-group">
                    <label for="edit_longitude">Longitude</label>
                    <input type="number" step="any" id="edit_longitude" name="longitude" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="edit_location" class="btn-primary">Save Changes</button>
                <button type="button" class="btn-secondary" onclick="closeModal('editLocationModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteLocationModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Confirm Deletion</h2>
            <span class="close-modal">×</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <p>Are you sure you want to delete this location? This action cannot be undone.</p>
                <input type="hidden" name="location_id" id="delete_location_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteLocationModal')">Cancel</button>
                <button type="submit" name="confirm_delete" class="btn-danger">Delete Location</button>
            </div>
        </form>
    </div>
</div>

<!-- View Machines Modal -->
<div class="modal" id="viewMachinesModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="view_machines_title">Dispensers</h2>
            <span class="close-modal">×</span>
        </div>
        <div class="modal-body">
            <p id="view_machines_list">Loading dispensers...</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeModal('viewMachinesModal')">Close</button>
        </div>
    </div>
</div>

<!-- Custom Date Modal -->
<div class="modal" id="customDateModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Select Custom Dates</h2>
            <span class="close-modal">×</span>
        </div>
        <div class="modal-body">
            <div class="input-group">
                <label for="modal_start_date">Start Date</label>
                <input type="date" id="modal_start_date" value="<?= isset($_GET['start']) ? htmlspecialchars($_GET['start']) : '' ?>">
            </div>
            <div class="input-group">
                <label for="modal_end_date">End Date</label>
                <input type="date" id="modal_end_date" value="<?= isset($_GET['end']) ? htmlspecialchars($_GET['end']) : '' ?>">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-primary" id="applyCustomDates">Apply</button>
            <button type="button" class="btn-secondary" onclick="closeModal('customDateModal')">Cancel</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<script>
// Marker data from PHP
const allLocations = [
    <?php foreach ($all_locations as $data): ?>
    {
        lat: <?= $data['latitude'] ?>,
        lng: <?= $data['longitude'] ?>,
        name: '<?= addslashes($data['location_name']) ?>',
        total_liters: <?= $data['total_liters'] ?>,
        location_id: <?= $data['location_id'] ?>,
        descriptions: '<?= addslashes($data['descriptions'] ?? 'No descriptions available') ?>'
    },
    <?php endforeach; ?>
];

const itcDispensers = [
    <?php foreach ($itc_dispensers as $data): ?>
    {
        lat: <?= $data['latitude'] ?>,
        lng: <?= $data['longitude'] ?>,
        total_liters: <?= $data['total_liters'] ?>,
        name: '<?= addslashes($data['location_name']) ?>',
        location_id: <?= $data['location_id'] ?>,
        descriptions: '<?= addslashes($data['descriptions'] ?? 'No descriptions available') ?>'
    },
    <?php endforeach; ?>
];

const maxTotalLiters = <?= $max_total_liters ?>;

const topLocations = [
    <?php foreach ($top_locations as $loc): ?>
    { name: '<?= addslashes($loc['location_name']) ?>', liters: <?= $loc['total_liters'] ?> },
    <?php endforeach; ?>
];

const topMachines = [
    <?php foreach ($top_machines as $machine): ?>
    { description: '<?= addslashes($machine['Description']) ?>', location: '<?= addslashes($machine['location_name']) ?>', liters: <?= $machine['total_liters'] ?> },
    <?php endforeach; ?>
];

// Function to interpolate color based on usage
function getMarkerColor(totalLiters) {
    const ratio = Math.min(totalLiters / maxTotalLiters, 1);
    const colors = [
        { ratio: 0.0, color: [0, 0, 255] },   // Blue
        { ratio: 0.33, color: [0, 255, 0] }, // Green
        { ratio: 0.66, color: [255, 255, 0] }, // Yellow
        { ratio: 1.0, color: [255, 0, 0] }   // Red
    ];
    
    let lower = colors[0], upper = colors[colors.length - 1];
    for (let i = 0; i < colors.length - 1; i++) {
        if (ratio >= colors[i].ratio && ratio <= colors[i + 1].ratio) {
            lower = colors[i];
            upper = colors[i + 1];
            break;
        }
    }
    
    const t = (ratio - lower.ratio) / (upper.ratio - lower.ratio || 1);
    const r = Math.round(lower.color[0] + t * (upper.color[0] - lower.color[0]));
    const g = Math.round(lower.color[1] + t * (upper.color[1] - lower.color[1]));
    const b = Math.round(lower.color[2] + t * (upper.color[2] - lower.color[2]));
    
    return `rgb(${r}, ${g}, ${b})`;
}

// Initialize Leaflet Map
let map, heatLayer, markersLayer;
function initMap() {
    map = L.map('map', {
        center: [15.63954742, 120.41917920],
        zoom: 15,
        minZoom: 12,
        maxZoom: 19,
        maxBounds: [
            [15.62954742, 120.40917920], // Southwest corner
            [15.64954742, 120.42917920]  // Northeast corner
        ],
        maxBoundsViscosity: 1.0,
        layers: [
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            })
        ]
    });

    const baseLayers = {
        "Street Map": L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }),
        "Topographic": L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.opentopomap.org/copyright">OpenTopoMap</a>'
        }),
        "Satellite": L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: '&copy; <a href="https://www.esri.com/">Esri</a>'
        })
    };

    L.control.layers(baseLayers).addTo(map);

    const heatPoints = allLocations.map(loc => [loc.lat, loc.lng, loc.total_liters / maxTotalLiters]);
    heatLayer = L.heatLayer(heatPoints, {
        radius: 35,
        blur: 20,
        maxZoom: 17,
        gradient: {
            0.0: '#0000ff',  // Blue for low usage
            0.33: '#00ff00', // Green
            0.66: '#ffff00', // Yellow
            1.0: '#ff0000'   // Red for high usage
        }
    }).addTo(map);

    markersLayer = L.layerGroup().addTo(map);
    allLocations.forEach(location => {
        const color = getMarkerColor(location.total_liters);
        const icon = L.divIcon({
            className: 'custom-marker',
            html: `<div style="background-color: ${color}; width: 20px; height: 20px; border-radius: 50%; border: 2px solid #fff;"></div>`,
            iconSize: [20, 20],
            iconAnchor: [10, 10],
            popupAnchor: [0, -10]
        });

        const marker = L.marker([location.lat, location.lng], { icon: icon }).addTo(markersLayer);
        const descriptionList = location.descriptions !== 'No descriptions available' 
            ? location.descriptions 
            : 'No descriptions available';
        const popupText = `${location.name}<br>` +
                          `Total Dispensed: ${location.total_liters.toFixed(1)}L<br>` +
                          `Dispensers: ${descriptionList}`;
        marker.bindPopup(popupText);
        marker.on('mouseover', function() {
            this.openPopup();
        });
        marker.on('mouseout', function() {
            this.closePopup();
        });
    });

    map.on('click', function(e) {
        const lat = e.latlng.lat.toFixed(6);
        const lng = e.latlng.lng.toFixed(6);
        const popupContent = `
            Latitude: ${lat}<br>
            Longitude: ${lng}<br>
            <button class="btn-primary" onclick="openAddLocationModal(${lat}, ${lng})">Create Here</button>
        `;
        L.popup()
            .setLatLng(e.latlng)
            .setContent(popupContent)
            .openOn(map);
    });
}

function openAddLocationModal(lat, lng) {
    const modal = document.getElementById('addLocationModal');
    const latInput = document.getElementById('add_latitude');
    const lngInput = document.getElementById('add_longitude');
    
    document.getElementById('add_location_name').value = '';
    document.getElementById('add_address').value = '';
    
    latInput.value = lat;
    lngInput.value = lng;
    
    modal.style.display = 'block';
    
    map.closePopup();
}

function toggleMapLayers() {
    const heatmapCheckbox = document.getElementById('toggleHeatmap');
    const markersCheckbox = document.getElementById('toggleMarkers');

    if (heatmapCheckbox.checked) {
        if (!map.hasLayer(heatLayer)) map.addLayer(heatLayer);
    } else {
        if (map.hasLayer(heatLayer)) map.removeLayer(heatLayer);
    }

    if (markersCheckbox.checked) {
        if (!map.hasLayer(markersLayer)) map.addLayer(markersLayer);
    } else {
        if (map.hasLayer(markersLayer)) map.removeLayer(markersLayer);
    }
}

// Initialize top locations chart
function initTopLocationsChart() {
    if (topLocations.length > 0) {
        const ctx = document.getElementById('topLocationsChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: topLocations.map(l => l.name),
                datasets: [{
                    label: 'Total Liters Dispensed',
                    data: topLocations.map(l => l.liters),
                    backgroundColor: '#3498db',
                    borderColor: '#2980b9',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Liters Dispensed'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Location'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${context.parsed.y.toFixed(2)}L`;
                            }
                        }
                    }
                }
            }
        });
    }
}

// Initialize top machines chart
function initTopMachinesChart() {
    if (topMachines.length > 0) {
        const ctx = document.getElementById('topMachinesChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: topMachines.map(m => `${m.description} at ${m.location}`),
                datasets: [{
                    label: 'Total Liters Dispensed',
                    data: topMachines.map(m => m.liters),
                    backgroundColor: '#2ecc71',
                    borderColor: '#27ae60',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Liters Dispensed'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Machine'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${context.parsed.y.toFixed(2)}L`;
                            }
                        }
                    }
                }
            }
        });
    }
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    initMap();
    initTopLocationsChart();
    initTopMachinesChart();
    filterAndPaginate();
    
    document.getElementById('toggleHeatmap').addEventListener('change', toggleMapLayers);
    document.getElementById('toggleMarkers').addEventListener('change', toggleMapLayers);
    
    document.getElementById('searchInput').addEventListener('input', function() {
        searchTerm = this.value;
        currentPage = 1;
        filterAndPaginate();
    });
    
    document.getElementById('rowsPerPage').addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        filterAndPaginate();
    });
    
    document.getElementById('addLocationBtn').addEventListener('click', function() {
        document.getElementById('add_location_name').value = '';
        document.getElementById('add_address').value = '';
        document.getElementById('add_latitude').value = '';
        document.getElementById('add_longitude').value = '';
        document.getElementById('addLocationModal').style.display = 'block';
    });
    
    const toast = document.querySelector('.notification-toast');
    if (toast) {
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }

    document.getElementById('period')?.addEventListener('change', function() {
        const value = this.value;
        if (value === 'custom') {
            document.getElementById('customDateModal').style.display = 'block';
        } else {
            window.location.href = window.location.pathname + '?period=' + value;
        }
    });

    document.getElementById('applyCustomDates').addEventListener('click', function() {
        const start = document.getElementById('modal_start_date').value;
        const end = document.getElementById('modal_end_date').value;
        if (start && end) {
            window.location.href = window.location.pathname + '?period=custom&start=' + start + '&end=' + end;
        }
        closeModal('customDateModal');
    });
});

// State management for table
let currentPage = 1;
let rowsPerPage = 10;
let searchTerm = '';

function filterAndPaginate() {
    const rows = document.querySelectorAll('#locationsTable tbody tr');
    const filteredRows = [];
    
    rows.forEach(row => {
        const locationName = row.cells[1].textContent.toLowerCase();
        const address = row.cells[2].textContent.toLowerCase();
        const latitude = row.cells[3].textContent.toLowerCase();
        const longitude = row.cells[4].textContent.toLowerCase();
        const totalLiters = row.cells[5].textContent.toLowerCase();
        
        if (
            locationName.includes(searchTerm.toLowerCase()) ||
            address.includes(searchTerm.toLowerCase()) ||
            latitude.includes(searchTerm.toLowerCase()) ||
            longitude.includes(searchTerm.toLowerCase()) ||
            totalLiters.includes(searchTerm.toLowerCase())
        ) {
            filteredRows.push(row);
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    const totalRows = filteredRows.length;
    const totalPages = Math.ceil(totalRows / rowsPerPage);
    currentPage = Math.min(currentPage, Math.max(1, totalPages));
    
    filteredRows.forEach((row, index) => {
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        row.style.display = (index >= start && index < end) ? '' : 'none';
    });
    
    updatePagination(totalPages);
}

function updatePagination(totalPages) {
    const pagination = document.getElementById('pagination');
    pagination.innerHTML = '';
    
    const prevButton = document.createElement('button');
    prevButton.textContent = 'Previous';
    prevButton.disabled = currentPage === 1;
    prevButton.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            filterAndPaginate();
        }
    });
    pagination.appendChild(prevButton);
    
    for (let i = 1; i <= totalPages; i++) {
        const pageButton = document.createElement('button');
        pageButton.textContent = i;
        pageButton.className = i === currentPage ? 'active' : '';
        pageButton.addEventListener('click', () => {
            currentPage = i;
            filterAndPaginate();
        });
        pagination.appendChild(pageButton);
    }
    
    const nextButton = document.createElement('button');
    nextButton.textContent = 'Next';
    nextButton.disabled = currentPage === totalPages || totalPages === 0;
    nextButton.addEventListener('click', () => {
        if (currentPage < totalPages) {
            currentPage++;
            filterAndPaginate();
        }
    });
    pagination.appendChild(nextButton);
}

function showEditModal(id, name, address, latitude, longitude) {
    document.getElementById('edit_location_id').value = id;
    document.getElementById('edit_location_name').value = name;
    document.getElementById('edit_address').value = address;
    document.getElementById('edit_latitude').value = latitude;
    document.getElementById('edit_longitude').value = longitude;
    document.getElementById('editLocationModal').style.display = 'block';
}

function showDeleteModal(id) {
    document.getElementById('delete_location_id').value = id;
    document.getElementById('deleteLocationModal').style.display = 'block';
}

function showMachinesModal(locationId, locationName) {
    const location = allLocations.find(loc => loc.location_id === locationId);
    const modal = document.getElementById('viewMachinesModal');
    const title = document.getElementById('view_machines_title');
    const list = document.getElementById('view_machines_list');
    
    title.textContent = `Dispensers at ${locationName}`;
    if (location && location.descriptions !== 'No descriptions available') {
        const content = `Total Liters Dispensed: ${location.total_liters.toFixed(1)}L<br>Descriptions: ${location.descriptions}`;
        list.innerHTML = content;
    } else {
        list.innerHTML = 'No active dispensers at this location.';
    }
    
    modal.style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

document.querySelectorAll('.close-modal').forEach(btn => {
    btn.addEventListener('click', function() {
        this.closest('.modal').style.display = 'none';
    });
});

window.addEventListener('click', function(event) {
    if (event.target.className === 'modal') {
        event.target.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>