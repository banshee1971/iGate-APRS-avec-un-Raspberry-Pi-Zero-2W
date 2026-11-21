<?php
// Configuration (réglages modifiables dans /admin)
require __DIR__ . '/lib/settings.php';
require __DIR__ . '/lib/packets.php';
$S = aprs_load_settings();
$db_path = APRS_DB_PATH;
$db = new SQLite3($db_path);

// Récupération des paramètres
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'packets';
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filter_callsign = isset($_GET['callsign']) ? strtoupper(trim($_GET['callsign'])) : '';
$limit = isset($_GET['limit']) ? $_GET['limit'] : 'all';
$map_hours = isset($_GET['hours']) ? intval($_GET['hours']) : intval($S['map_default_hours']);

// Statistiques globales
$stats = $db->query("SELECT 
    COUNT(*) as total,
    COUNT(DISTINCT callsign) as unique_calls,
    COUNT(CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 1 END) as with_position,
    date(MIN(timestamp), 'localtime') as first_packet,
    date(MAX(timestamp), 'localtime') as last_packet
    FROM packets")->fetchArray();

// Fonction pour récupérer les paquets
function get_packets($db, $filter_date, $filter_callsign, $limit) {
    $sql = "SELECT id, datetime(timestamp, 'localtime') as timestamp, callsign, raw_packet, decoded_info, latitude, longitude 
            FROM packets WHERE 1=1";
    $params = array();
    
    if ($filter_date) {
        $sql .= " AND date(timestamp, 'localtime') = ?";
        $params[] = $filter_date;
    }
    
    if ($filter_callsign) {
        $sql .= " AND callsign LIKE ?";
        $params[] = '%' . $filter_callsign . '%';
    }
    
    $sql .= " ORDER BY timestamp DESC";
    
    if ($limit === '10') {
        $sql .= " LIMIT 10";
    }
    
    $stmt = $db->prepare($sql);
    foreach ($params as $i => $param) {
        $stmt->bindValue($i + 1, $param);
    }
    
    return $stmt->execute();
}

// Fonction pour récupérer les positions pour la carte
function get_map_positions($db, $hours) {
    $sql = "SELECT 
                callsign,
                latitude,
                longitude,
                datetime(MAX(timestamp), 'localtime') as last_seen,
                COUNT(*) as packet_count,
                decoded_info
            FROM packets 
            WHERE latitude IS NOT NULL 
                AND longitude IS NOT NULL
                AND timestamp > datetime('now', '-' || ? || ' hours')
            GROUP BY callsign, latitude, longitude
            ORDER BY last_seen DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(1, $hours);
    return $stmt->execute();
}

// Récupération des données selon l'onglet
if ($tab === 'packets') {
    $result = get_packets($db, $filter_date, $filter_callsign, $limit);
    
    $count_sql = "SELECT COUNT(*) as total FROM packets WHERE 1=1";
    $count_params = array();
    if ($filter_date) {
        $count_sql .= " AND date(timestamp, 'localtime') = ?";
        $count_params[] = $filter_date;
    }
    if ($filter_callsign) {
        $count_sql .= " AND callsign LIKE ?";
        $count_params[] = '%' . $filter_callsign . '%';
    }
    $count_stmt = $db->prepare($count_sql);
    foreach ($count_params as $i => $param) {
        $count_stmt->bindValue($i + 1, $param);
    }
    $total_packets = $count_stmt->execute()->fetchArray()['total'];
} elseif ($tab === 'map') {
    $map_data = array();
    $map_result = get_map_positions($db, $map_hours);
    while ($row = $map_result->fetchArray(SQLITE3_ASSOC)) {
        // Icône APRS de la station (dernier paquet) et résumé pour la bulle
        $d = $row['decoded_info'] ? (json_decode($row['decoded_info'], true) ?: []) : [];
        $row['icon_html'] = aprs_symbol_html($d['table'] ?? '/', $d['code'] ?? '/', $d['emoji'] ?? '📍', $d['symbol_name'] ?? '');
        $row['symbol_name'] = $d['symbol_name'] ?? '';
        $row['comment'] = $d['comment'] ?? '';
        unset($row['decoded_info']);
        $map_data[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h(aprs_title($S)); ?></title>
    <?php echo aprs_leaflet_tags(); ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .header h1 {
            color: #333;
            font-size: 2em;
            margin-bottom: 5px;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
        }

        .admin-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            white-space: nowrap;
        }

        .setup-banner {
            background: #fff3cd;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
            color: #856404;
        }

        .setup-banner a {
            color: #667eea;
            font-weight: bold;
        }

        .header .subtitle {
            color: #666;
            font-size: 1.1em;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-card .value {
            font-size: 2em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-card .label {
            color: #666;
            font-size: 0.9em;
        }
        
        .tabs {
            background: white;
            padding: 0;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .tab-buttons {
            display: flex;
            background: #f8f9fa;
            border-bottom: 2px solid #ddd;
        }
        
        .tab-button {
            flex: 1;
            padding: 15px 20px;
            background: transparent;
            border: none;
            font-size: 1em;
            cursor: pointer;
            transition: all 0.3s;
            color: #666;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
        }
        
        .tab-button:hover {
            background: #e9ecef;
        }
        
        .tab-button.active {
            background: white;
            color: #667eea;
            border-bottom: 3px solid #667eea;
        }
        
        .tab-content {
            padding: 20px 30px;
        }
        
        .filters {
            margin-bottom: 20px;
        }
        
        .filters h2 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 1em;
            transition: border-color 0.3s;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 10px 25px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1em;
            cursor: pointer;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #5568d3;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .packet-count {
            color: #666;
            margin-bottom: 15px;
            font-size: 0.95em;
        }
        
        .packet-list {
            max-height: 600px;
            overflow-y: auto;
        }
        
        .packet-item {
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            transition: transform 0.2s;
        }
        
        .packet-item:hover {
            transform: translateX(5px);
            background: #e9ecef;
        }
        
        .packet-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .packet-callsign {
            color: #667eea;
            font-size: 1.1em;
            font-weight: bold;
        }
        
        .packet-time {
            color: #666;
            font-size: 0.9em;
        }
        
        .packet-raw {
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            color: #333;
            background: white;
            padding: 10px;
            border-radius: 3px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .position-badge {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 0.8em;
            margin-left: 10px;
        }
        
        .packet-callsign { text-decoration: none; }
        .pk-emoji { font-size: 1.3em; margin-right: 4px; }
        .pk-tag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 0.78em; font-weight: 600; margin-left: 6px; vertical-align: middle; }
        .pk-type { background: #e8eaf6; color: #3949ab; }
        .pk-direct { background: #e8f5e9; color: #2e7d32; }
        .pk-via { background: #fff3e0; color: #e65100; }
        .pk-lines { line-height: 1.7; color: #333; margin-bottom: 6px; }
        .pk-muted { color: #888; font-size: 0.9em; }
        .pk-msg { font-weight: 600; color: #4a148c; }
        .pk-comment { font-style: italic; color: #444; }
        .pk-maplink { color: #667eea; font-weight: 500; text-decoration: none; white-space: nowrap; }
        .pk-raw summary { cursor: pointer; color: #888; font-size: 0.85em; }
        .pk-raw .packet-raw { margin-top: 6px; }
        .pk-weather { border-left-color: #29b6f6; }
        .pk-message { border-left-color: #ab47bc; }
        .pk-object { border-left-color: #ffa726; }
        .pk-status { border-left-color: #90a4ae; }
        .aprs-sym { display: inline-block; width: 24px; height: 24px; background-size: 384px 144px;
                    background-repeat: no-repeat; vertical-align: middle; position: relative; margin-right: 4px; }
        .aprs-sym i { position: absolute; inset: 0; background-image: url(/vendor/aprs-symbols/aprs-symbols-24-2@2x.png);
                      background-size: 384px 144px; background-repeat: no-repeat; }
        .station-icon .aprs-sym { margin: 0; filter: drop-shadow(0 1px 1px rgba(0,0,0,.4)); }
        .station-emoji { font-size: 22px; line-height: 22px; text-align: center; filter: drop-shadow(0 1px 1px rgba(0,0,0,.5)); }

        .no-packets {
            text-align: center;
            padding: 40px;
            color: #666;
            font-size: 1.1em;
        }
        
        #map {
            width: 100%;
            height: 600px;
            border-radius: 5px;
            margin-top: 20px;
        }
        
        .map-info {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #2196F3;
        }
        
        .map-info strong {
            color: #1976D2;
        }
        
        #offline-warning {
            display: none;
            background: #fff3cd;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        
        #offline-warning a {
            color: #667eea;
            font-weight: bold;
            text-decoration: underline;
        }
        
        .offline-mode-btn {
            text-align: center;
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .tab-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- En-tête -->
        <div class="header">
            <div class="header-top">
                <h1>📡 <?php echo h(aprs_title($S)); ?></h1>
                <a href="/admin/" class="admin-link">⚙️ Configuration</a>
            </div>
            <div class="subtitle"><?php echo h($S['web_subtitle']); ?></div>
        </div>

        <?php if (!aprs_is_configured($S)): ?>
        <div class="setup-banner">
            👋 Votre iGate n'est pas encore configuré. <a href="/admin/">Ouvrez la page de configuration</a> pour entrer votre indicatif et votre position.
        </div>
        <?php endif; ?>
        
        <!-- Statistiques -->
        <div class="stats">
            <div class="stat-card">
                <div class="value"><?php echo number_format($stats['total']); ?></div>
                <div class="label">Paquets totaux</div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo number_format($stats['unique_calls']); ?></div>
                <div class="label">Indicatifs uniques</div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo number_format($stats['with_position']); ?></div>
                <div class="label">Avec position</div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo $stats['last_packet']; ?></div>
                <div class="label">Dernier paquet</div>
            </div>
        </div>
        
        <!-- Onglets -->
        <div class="tabs">
            <div class="tab-buttons">
                <a href="?tab=packets" class="tab-button <?php echo $tab === 'packets' ? 'active' : ''; ?>">
                    📦 Paquets
                </a>
                <a href="?tab=map" class="tab-button <?php echo $tab === 'map' ? 'active' : ''; ?>">
                    🗺️ Carte
                </a>
            </div>
            
            <div class="tab-content">
                <?php if ($tab === 'packets'): ?>
                    <!-- Onglet Paquets -->
                    <div class="filters">
                        <h2>🔍 Filtres</h2>
                        <form method="GET" action="">
                            <input type="hidden" name="tab" value="packets">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="date">Date:</label>
                                    <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="callsign">Indicatif d'appel:</label>
                                    <input type="text" id="callsign" name="callsign" placeholder="Ex: VE2XXX" value="<?php echo htmlspecialchars($filter_callsign); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="limit">Affichage:</label>
                                    <select id="limit" name="limit">
                                        <option value="all" <?php echo $limit === 'all' ? 'selected' : ''; ?>>Tous les paquets</option>
                                        <option value="10" <?php echo $limit === '10' ? 'selected' : ''; ?>>10 derniers</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <button type="submit" class="btn">Filtrer</button>
                                    <a href="?tab=packets" class="btn btn-secondary" style="margin-left: 10px;">Réinitialiser</a>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <div class="packet-count">
                        <?php 
                        if ($filter_callsign || $filter_date != date('Y-m-d')) {
                            echo "Affichage des paquets filtrés - Total: " . number_format($total_packets);
                        } else {
                            echo "Affichage des paquets d'aujourd'hui - Total: " . number_format($total_packets);
                        }
                        ?>
                    </div>
                    
                    <div class="packet-list">
                        <?php
                        $packet_count = 0;
                        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                            $packet_count++;
                            echo aprs_packet_html($row, $S);
                        }
                        
                        if ($packet_count === 0) {
                            echo '<div class="no-packets">Aucun paquet trouvé pour les critères sélectionnés.</div>';
                        }
                        ?>
                    </div>
                    
                <?php elseif ($tab === 'map'): ?>
                    <!-- Onglet Carte -->
                    <div class="filters">
                        <h2>🗺️ Carte des stations APRS</h2>
                        <form method="GET" action="">
                            <input type="hidden" name="tab" value="map">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="hours">Période:</label>
                                    <select id="hours" name="hours" onchange="this.form.submit()">
                                        <option value="1" <?php echo $map_hours === 1 ? 'selected' : ''; ?>>Dernière heure</option>
                                        <option value="6" <?php echo $map_hours === 6 ? 'selected' : ''; ?>>6 dernières heures</option>
                                        <option value="12" <?php echo $map_hours === 12 ? 'selected' : ''; ?>>12 dernières heures</option>
                                        <option value="24" <?php echo $map_hours === 24 ? 'selected' : ''; ?>>24 dernières heures</option>
                                        <option value="48" <?php echo $map_hours === 48 ? 'selected' : ''; ?>>48 dernières heures</option>
                                        <option value="168" <?php echo $map_hours === 168 ? 'selected' : ''; ?>>7 derniers jours</option>
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Bouton mode offline -->
                    <div class="offline-mode-btn">
                        <a href="map-offline.php?hours=<?php echo $map_hours; ?>" class="btn btn-secondary">
                            📴 Mode Offline (sans Internet)
                        </a>
                    </div>
                    
                    <!-- Avertissement offline (caché par défaut) -->
                    <div id="offline-warning">
                        <strong>⚠️ Mode dégradé:</strong> Certaines tuiles ne sont ni dans le cache du Pi ni accessibles sur Internet (pas d'Internet?).
                        <a href="map-offline.php?hours=<?php echo $map_hours; ?>">
                            Cliquez ici pour la carte offline (tuiles en cache seulement)
                        </a>
                    </div>
                    
                    <div class="map-info">
                        <strong>📍 <?php echo count($map_data); ?> station(s)</strong> avec position reçue(s) dans les <strong><?php echo $map_hours; ?> dernières heures</strong>
                    </div>
                    
                    <div id="map"></div>
                    
                    <script>
                        // Initialiser la carte (centrée sur votre position)
                        var map = L.map('map').setView([<?php echo json_encode($S['lat']); ?>, <?php echo json_encode($S['lon']); ?>], 10);
                        
                        // Détection du mode offline
                        var offlineMode = false;
                        var tileErrorCount = 0;
                        
                        // Tuiles servies par le Pi : depuis son cache (/var/www/aprs/tiles) si
                        // déjà vues, sinon nginx les récupère sur OpenStreetMap et les garde.
                        // Erreurs = pas d'Internet ET tuile absente du cache.
                        var tileLayer = L.tileLayer('/tiles/{z}/{x}/{y}.png', {
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                            maxZoom: 18,
                            errorTileUrl: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
                        });
                        
                        // Détecter les erreurs de chargement de tuiles
                        tileLayer.on('tileerror', function() {
                            tileErrorCount++;
                            if (tileErrorCount > 3 && !offlineMode) {
                                offlineMode = true;
                                document.getElementById('offline-warning').style.display = 'block';
                            }
                        });
                        
                        tileLayer.addTo(map);
                        
                        // Données des stations
                        var stations = <?php echo json_encode($map_data); ?>;
                        
                        // Icône personnalisée pour les stations
                        var stationIcon = L.icon({
                            iconUrl: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSIjZmYwMDAwIj48cGF0aCBkPSJNMTIgMkM4LjEzIDIgNSA1LjEzIDUgOWMwIDUuMjUgNyAxMyA3IDEzczctNy43NSA3LTEzYzAtMy44Ny0zLjEzLTctNy03em0wIDkuNWMtMS4zOCAwLTIuNS0xLjEyLTIuNS0yLjVzMS4xMi0yLjUgMi41LTIuNSAyLjUgMS4xMiAyLjUgMi41LTEuMTIgMi41LTIuNSAyLjV6Ii8+PC9zdmc+',
                            iconSize: [32, 32],
                            iconAnchor: [16, 32],
                            popupAnchor: [0, -32]
                        });
                        
                        // Ajouter les marqueurs (icône APRS de chaque station)
                        var bounds = [], markersByCall = {};
                        var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) {
                            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
                        stations.forEach(function(station) {
                            var icon = station.icon_html ? L.divIcon({className: 'station-icon', html: station.icon_html,
                                                                      iconSize: [24, 24], iconAnchor: [12, 12], popupAnchor: [0, -12]})
                                                         : stationIcon;
                            var marker = L.marker([station.latitude, station.longitude], {icon: icon, title: station.callsign}).addTo(map);
                            markersByCall[station.callsign] = marker;
                            
                            var safeCall = String(station.callsign).replace(/[&<>"']/g, function (c) {
                                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
                            });
                            var popupContent = '<div style="font-family: Arial; min-width: 200px;">' +
                                '<strong style="font-size: 1.2em; color: #667eea;">' + safeCall + '</strong>' +
                                (station.symbol_name ? ' <span style="color:#666">· ' + esc(station.symbol_name) + '</span>' : '') + '<br>' +
                                (station.comment ? '<em>' + esc(station.comment) + '</em><br>' : '') +
                                '<hr style="margin: 8px 0;">' +
                                '📍 <strong>Position:</strong><br>' +
                                'Lat: ' + station.latitude.toFixed(4) + '°<br>' +
                                'Lon: ' + station.longitude.toFixed(4) + '°<br><br>' +
                                '🕐 <strong>Dernière réception:</strong><br>' + station.last_seen + '<br><br>' +
                                '📦 <strong>Paquets reçus:</strong> ' + station.packet_count +
                                '</div>';
                            
                            marker.bindPopup(popupContent);
                            bounds.push([station.latitude, station.longitude]);
                        });
                        
                        // Lien « voir sur la carte » d'un paquet : centrer sur cette position
                        var focus = <?php echo json_encode(isset($_GET['lat'], $_GET['lon']) && is_numeric($_GET['lat']) && is_numeric($_GET['lon'])
                            ? ['lat' => (float)$_GET['lat'], 'lon' => (float)$_GET['lon'], 'label' => substr((string)($_GET['focus'] ?? ''), 0, 20)] : null); ?>;
                        if (focus) {
                            map.setView([focus.lat, focus.lon], 13);
                            var m = markersByCall[focus.label];
                            if (m && m.getLatLng().distanceTo([focus.lat, focus.lon]) < 50) {
                                m.openPopup();
                            } else {
                                L.circleMarker([focus.lat, focus.lon], {radius: 9, color: '#e65100', weight: 3, fillOpacity: 0.2})
                                    .addTo(map).bindPopup('<strong>' + esc(focus.label) + '</strong>').openPopup();
                            }
                        } else if (bounds.length > 0) {
                            // Ajuster la vue pour montrer tous les marqueurs
                            map.fitBounds(bounds, {padding: [50, 50]});
                        }
                    </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
<?php
$db->close();
?>
