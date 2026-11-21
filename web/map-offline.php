<?php
// Carte « Offline » : n'utilise QUE les tuiles déjà présentes dans le cache
// du Pi (/var/www/aprs/tiles) — celles pré-téléchargées et celles vues en
// naviguant sur la carte normale. Aucune requête vers Internet.
require __DIR__ . '/lib/settings.php';
$S = aprs_load_settings();
$db = new SQLite3(APRS_DB_PATH, SQLITE3_OPEN_READONLY);

$map_hours = isset($_GET['hours']) ? intval($_GET['hours']) : intval($S['map_default_hours']);

// Récupérer les positions
$sql = "SELECT
            callsign,
            latitude,
            longitude,
            datetime(MAX(timestamp), 'localtime') as last_seen,
            COUNT(*) as packet_count
        FROM packets
        WHERE latitude IS NOT NULL
            AND longitude IS NOT NULL
            AND timestamp > datetime('now', '-' || ? || ' hours')
        GROUP BY callsign, latitude, longitude
        ORDER BY last_seen DESC";

$stmt = $db->prepare($sql);
$stmt->bindValue(1, $map_hours);
$result = $stmt->execute();

$map_data = array();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $map_data[] = $row;
}
$db->close();

$hours_choices = [1 => 'Dernière heure', 6 => '6 dernières heures', 12 => '12 dernières heures',
                  24 => '24 dernières heures', 48 => '48 dernières heures', 168 => '7 derniers jours'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h(aprs_title($S)); ?> - Carte Offline</title>
    <?php echo aprs_leaflet_tags(); ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header, .content {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .header h1 { color: #333; font-size: 2em; margin-bottom: 5px; }
        .back-link { display: inline-block; margin-top: 10px; color: #667eea; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
        .filters { margin-bottom: 20px; }
        .filter-row { display: flex; gap: 15px; align-items: center; }
        .filter-group label { margin-right: 10px; font-weight: 500; }
        select { padding: 8px 15px; border: 2px solid #ddd; border-radius: 5px; font-size: 1em; }
        .map-info {
            background: #e7f3ff; padding: 15px; border-radius: 5px; margin-bottom: 20px;
            border-left: 4px solid #2196F3;
        }
        .offline-notice {
            background: #fff3cd; padding: 15px; border-radius: 5px; margin-bottom: 20px;
            border-left: 4px solid #ffc107; color: #856404;
        }
        #map {
            width: 100%;
            height: 650px;
            border: 2px solid #ddd;
            border-radius: 5px;
            /* Zones sans tuile en cache : quadrillage gris clair */
            background-color: #eef1f5;
            background-image:
                linear-gradient(#dde2e8 1px, transparent 1px),
                linear-gradient(90deg, #dde2e8 1px, transparent 1px);
            background-size: 32px 32px;
        }
        .legend { margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; }
        .legend h3 { margin-bottom: 10px; color: #333; }
        .legend-item { display: inline-block; margin-right: 20px; margin-bottom: 10px; }
        .station-list { margin-top: 20px; max-height: 400px; overflow-y: auto; }
        .station-item {
            padding: 10px; margin-bottom: 5px; background: #f8f9fa;
            border-left: 4px solid #667eea; border-radius: 3px; cursor: pointer; transition: background 0.2s;
        }
        .station-item:hover { background: #e9ecef; }
        .station-callsign { font-weight: bold; color: #667eea; font-size: 1.1em; }
        .coords { font-family: 'Courier New', monospace; font-size: 0.9em; color: #666; }
        .no-leaflet { padding: 40px; text-align: center; color: #666; }
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header, .content { padding: 15px; }
            #map { height: 450px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗺️ <?php echo h(aprs_title($S)); ?> - Carte Offline</h1>
            <a href="index.php?tab=map&hours=<?php echo $map_hours; ?>" class="back-link">← Retour à la carte en ligne</a>
        </div>

        <div class="content">
            <div class="offline-notice">
                <strong>📴 Mode Offline :</strong> cette carte n'utilise que les tuiles gardées en cache sur le Pi
                (celles déjà consultées sur la carte en ligne, ou pré-téléchargées depuis <a href="/admin/">la configuration</a>).
                Les zones jamais visitées apparaissent en quadrillage gris.
            </div>

            <div class="filters">
                <form method="GET" action="">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="hours">Période:</label>
                            <select id="hours" name="hours" onchange="this.form.submit()">
                                <?php foreach ($hours_choices as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $map_hours === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>
            </div>

            <div class="map-info">
                <strong>📍 <?php echo count($map_data); ?> station(s)</strong> avec position dans les <strong><?php echo $map_hours; ?> dernières heures</strong>
            </div>

            <div id="map"></div>

            <div class="legend">
                <h3>Légende:</h3>
                <div class="legend-item">🔴 = Station APRS</div>
                <div class="legend-item">🟢 = Votre iGate (<?php echo h(aprs_station($S)); ?>)</div>
                <div class="legend-item">Cliquez sur une station pour voir les détails</div>
            </div>

            <div class="station-list">
                <h3>Liste des stations:</h3>
                <?php foreach ($map_data as $i => $station): ?>
                    <div class="station-item" onclick="focusStation(<?php echo $i; ?>)">
                        <span class="station-callsign"><?php echo h($station['callsign']); ?></span><br>
                        <span class="coords">
                            Lat: <?php echo number_format($station['latitude'], 4); ?>°
                            Lon: <?php echo number_format($station['longitude'], 4); ?>°
                        </span> |
                        Vu: <?php echo h($station['last_seen']); ?> |
                        Paquets: <?php echo intval($station['packet_count']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        var stations = <?php echo json_encode($map_data); ?>;
        var igate = {
            name: <?php echo json_encode(aprs_station($S)); ?>,
            lat: <?php echo json_encode($S['lat']); ?>,
            lon: <?php echo json_encode($S['lon']); ?>
        };
        var markers = [];

        function esc(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
            });
        }

        if (typeof L === 'undefined') {
            document.getElementById('map').innerHTML =
                '<div class="no-leaflet">La bibliothèque de carte (Leaflet) n\'est pas installée sur le Pi. ' +
                'Relancez <code>sudo ./install.sh</code> avec Internet pour l\'installer.</div>';
        } else {
            var map = L.map('map').setView([igate.lat, igate.lon], 10);

            // /tiles-cache/ = tuiles du cache du Pi SEULEMENT (jamais Internet).
            // Une tuile absente laisse voir le quadrillage gris.
            L.tileLayer('/tiles-cache/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors (cache local)',
                maxZoom: 18,
                errorTileUrl: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
            }).addTo(map);

            // Votre iGate
            L.circleMarker([igate.lat, igate.lon], {
                radius: 10, color: '#ffffff', weight: 2, fillColor: '#4CAF50', fillOpacity: 1
            }).addTo(map).bindPopup('<strong>' + esc(igate.name) + '</strong><br>Votre iGate');

            // Stations
            var bounds = [[igate.lat, igate.lon]];
            stations.forEach(function (station) {
                var m = L.circleMarker([station.latitude, station.longitude], {
                    radius: 8, color: '#ffffff', weight: 2, fillColor: '#ff5722', fillOpacity: 1
                }).addTo(map);
                m.bindTooltip(esc(station.callsign), {permanent: true, direction: 'top', offset: [0, -8]});
                m.bindPopup(
                    '<div style="font-family: Arial; min-width: 200px;">' +
                    '<strong style="font-size: 1.2em; color: #667eea;">' + esc(station.callsign) + '</strong><br>' +
                    '<hr style="margin: 8px 0;">' +
                    '📍 <strong>Position:</strong><br>' +
                    'Lat: ' + station.latitude.toFixed(4) + '°<br>' +
                    'Lon: ' + station.longitude.toFixed(4) + '°<br><br>' +
                    '🕐 <strong>Dernière réception:</strong><br>' + esc(station.last_seen) + '<br><br>' +
                    '📦 <strong>Paquets reçus:</strong> ' + station.packet_count +
                    '</div>'
                );
                markers.push(m);
                bounds.push([station.latitude, station.longitude]);
            });

            if (bounds.length > 1) {
                map.fitBounds(bounds, {padding: [50, 50]});
            }
        }

        // Focus sur une station depuis la liste
        function focusStation(i) {
            if (typeof L === 'undefined' || !markers[i]) return;
            map.setView(markers[i].getLatLng(), Math.max(map.getZoom(), 12));
            markers[i].openPopup();
            document.getElementById('map').scrollIntoView({behavior: 'smooth'});
        }
    </script>
</body>
</html>
