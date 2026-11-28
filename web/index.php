<?php
// Configuration (réglages modifiables dans /admin)
require __DIR__ . '/lib/settings.php';
require __DIR__ . '/lib/packets.php';
$S = aprs_load_settings();
aprs_lang();   // choisit la langue (et pose le cookie ?lang=) avant toute sortie
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
}
// Onglet Carte : les positions sont chargées par la page via map-data.php
?>
<!DOCTYPE html>
<html lang="<?php echo h(aprs_lang()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h(aprs_title($S)); ?></title>
    <?php echo aprs_leaflet_tags(); ?>
    <?php echo aprs_i18n_js(); ?>
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

        .header-links {
            display: flex;
            align-items: center;
            gap: 15px;
            white-space: nowrap;
        }

        .lang-switch {
            font-size: 0.85em;
            color: #888;
        }

        .lang-switch a {
            color: inherit;
            text-decoration: none;
        }

        .lang-switch a:hover {
            text-decoration: underline;
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
        
        /* Suivi des stations */
        .map-tools { background: #f8f9fa; border-radius: 8px; padding: 12px 15px; margin-bottom: 12px; display: flex; flex-direction: column; gap: 10px; }
        .mt-row { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; }
        .mt-row label { display: flex; gap: 6px; align-items: center; font-weight: 500; color: #444; }
        .mt-row select, .mt-row input[type=search] { padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 0.95em; }
        .mt-row input[type=search] { flex: 1; min-width: 220px; }
        .mt-check { font-weight: normal !important; }
        .mt-btn { padding: 6px 12px; border: 1px solid #c5cae9; background: white; color: #3f51b5; border-radius: 5px; cursor: pointer; font-size: 0.9em; }
        .mt-btn:hover { background: #e8eaf6; }
        .mt-danger { color: #c62828; border-color: #ef9a9a; }
        .mt-status { color: #888; font-size: 0.85em; margin-left: auto; }
        .mt-picker { border-top: 1px solid #e0e0e0; padding-top: 10px; display: flex; flex-direction: column; gap: 8px; }
        .mt-picker[hidden] { display: none; }
        .mt-hint { color: #777; font-size: 0.85em; }
        .mt-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 4px 12px; max-height: 220px; overflow-y: auto; }
        .mt-item { display: flex; gap: 6px; align-items: center; font-size: 0.9em; cursor: pointer; }
        .mt-item span { color: #888; font-size: 0.85em; margin-left: auto; }
        .mt-item.age-fresh b { color: #2e7d32; } .mt-item.age-mid b { color: #e65100; } .mt-item.age-old b, .mt-item.age-none b { color: #777; }
        .leaflet-tooltip.age-label { padding: 1px 6px; font-size: 11px; font-weight: 600; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.3); white-space: nowrap; }
        .leaflet-tooltip.age-label.age-fresh { background: #e8f5e9; border-color: #2e7d32; color: #1b5e20; }
        .leaflet-tooltip.age-label.age-mid { background: #fff3e0; border-color: #e65100; color: #bf360c; }
        .leaflet-tooltip.age-label.age-old { background: #f5f5f5; border-color: #9e9e9e; color: #616161; }
        .leaflet-tooltip.age-label .age { font-weight: normal; }
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
                <div class="header-links">
                    <?php echo aprs_lang_switch(); ?>
                    <a href="/admin/" class="admin-link">⚙️ <?php echo th('Configuration'); ?></a>
                </div>
            </div>
            <div class="subtitle"><?php echo h(t($S['web_subtitle'])); ?></div>
        </div>

        <?php if (!aprs_is_configured($S)): ?>
        <div class="setup-banner">
            👋 <?php echo th('Votre iGate n\'est pas encore configuré.'); ?> <a href="/admin/"><?php echo th('Ouvrez la page de configuration'); ?></a> <?php echo th('pour entrer votre indicatif et votre position.'); ?>
        </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="stats">
            <div class="stat-card">
                <div class="value"><?php echo number_format($stats['total']); ?></div>
                <div class="label"><?php echo th('Paquets totaux'); ?></div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo number_format($stats['unique_calls']); ?></div>
                <div class="label"><?php echo th('Indicatifs uniques'); ?></div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo number_format($stats['with_position']); ?></div>
                <div class="label"><?php echo th('Avec position'); ?></div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo $stats['last_packet']; ?></div>
                <div class="label"><?php echo th('Dernier paquet'); ?></div>
            </div>
        </div>

        <!-- Onglets -->
        <div class="tabs">
            <div class="tab-buttons">
                <a href="?tab=packets" class="tab-button <?php echo $tab === 'packets' ? 'active' : ''; ?>">
                    📦 <?php echo th('Paquets'); ?>
                </a>
                <a href="?tab=map" class="tab-button <?php echo $tab === 'map' ? 'active' : ''; ?>">
                    <?php echo th('🗺️ Carte'); /* clé avec emoji : « Carte » seul = carte réseau ailleurs */ ?>
                </a>
            </div>
            
            <div class="tab-content">
                <?php if ($tab === 'packets'): ?>
                    <!-- Onglet Paquets -->
                    <div class="filters">
                        <h2>🔍 <?php echo th('Filtres'); ?></h2>
                        <form method="GET" action="">
                            <input type="hidden" name="tab" value="packets">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="date">Date:</label>
                                    <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="callsign"><?php echo th('Indicatif d\'appel:'); ?></label>
                                    <input type="text" id="callsign" name="callsign" placeholder="<?php echo th('Ex: VE2XXX'); ?>" value="<?php echo htmlspecialchars($filter_callsign); ?>">
                                </div>

                                <div class="filter-group">
                                    <label for="limit"><?php echo th('Affichage:'); ?></label>
                                    <select id="limit" name="limit">
                                        <option value="all" <?php echo $limit === 'all' ? 'selected' : ''; ?>><?php echo th('Tous les paquets'); ?></option>
                                        <option value="10" <?php echo $limit === '10' ? 'selected' : ''; ?>><?php echo th('10 derniers'); ?></option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <button type="submit" class="btn"><?php echo th('Filtrer'); ?></button>
                                    <a href="?tab=packets" class="btn btn-secondary" style="margin-left: 10px;"><?php echo th('Réinitialiser'); ?></a>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <div class="packet-count">
                        <?php 
                        if ($filter_callsign || $filter_date != date('Y-m-d')) {
                            echo th('Affichage des paquets filtrés - Total: %s', number_format($total_packets));
                        } else {
                            echo th('Affichage des paquets d\'aujourd\'hui - Total: %s', number_format($total_packets));
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
                            echo '<div class="no-packets">' . th('Aucun paquet trouvé pour les critères sélectionnés.') . '</div>';
                        }
                        ?>
                    </div>
                    
                <?php elseif ($tab === 'map'): ?>
                    <!-- Onglet Carte -->
                    <div class="filters">
                        <h2>🗺️ <?php echo th('Carte des stations APRS'); ?></h2>
                        <form method="GET" action="">
                            <input type="hidden" name="tab" value="map">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="hours"><?php echo th('Période:'); ?></label>
                                    <select id="hours" name="hours" onchange="this.form.submit()">
                                        <option value="1" <?php echo $map_hours === 1 ? 'selected' : ''; ?>><?php echo th('Dernière heure'); ?></option>
                                        <option value="6" <?php echo $map_hours === 6 ? 'selected' : ''; ?>><?php echo h(ucfirst(t('%d dernières heures', 6))); ?></option>
                                        <option value="12" <?php echo $map_hours === 12 ? 'selected' : ''; ?>><?php echo h(ucfirst(t('%d dernières heures', 12))); ?></option>
                                        <option value="24" <?php echo $map_hours === 24 ? 'selected' : ''; ?>><?php echo h(ucfirst(t('%d dernières heures', 24))); ?></option>
                                        <option value="48" <?php echo $map_hours === 48 ? 'selected' : ''; ?>><?php echo h(ucfirst(t('%d dernières heures', 48))); ?></option>
                                        <option value="168" <?php echo $map_hours === 168 ? 'selected' : ''; ?>><?php echo h(ucfirst(t('%d derniers jours', 7))); ?></option>
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Bouton mode offline -->
                    <div class="offline-mode-btn">
                        <a href="map-offline.php?hours=<?php echo $map_hours; ?>" class="btn btn-secondary">
                            📴 <?php echo th('Mode Offline (sans Internet)'); ?>
                        </a>
                    </div>

                    <!-- Avertissement offline (caché par défaut) -->
                    <div id="offline-warning">
                        <strong>⚠️ <?php echo th('Mode dégradé:'); ?></strong> <?php echo th('Certaines tuiles ne sont ni dans le cache du Pi ni accessibles sur Internet (pas d\'Internet?).'); ?>
                        <a href="map-offline.php?hours=<?php echo $map_hours; ?>">
                            <?php echo th('Cliquez ici pour la carte offline (tuiles en cache seulement)'); ?>
                        </a>
                    </div>

                    <!-- Suivi des stations : filtre, groupes de favoris, trajets, âge des positions -->
                    <div class="map-tools">
                        <div class="mt-row">
                            <label><?php echo th('Trajets'); ?>
                                <select id="mt-trail">
                                    <option value="0"><?php echo th('aucun trajet'); ?></option>
                                    <option value="15">15 min</option>
                                    <option value="30">30 min</option>
                                    <option value="60">1 h</option>
                                    <option value="120">2 h</option>
                                    <option value="360">6 h</option>
                                    <option value="1440">24 h</option>
                                </select>
                            </label>
                            <label class="mt-check"><input type="checkbox" id="mt-ages" checked> <?php echo th('Âge des positions'); ?></label>
                            <label class="mt-check"><input type="checkbox" id="mt-follow"> <?php echo th('Suivre (recentrer la carte)'); ?></label>
                            <span class="mt-status" id="mt-status"></span>
                        </div>
                        <div class="mt-row">
                            <label><?php echo th('Stations'); ?>
                                <select id="mt-group"></select>
                            </label>
                            <button type="button" class="mt-btn" id="mt-new"><?php echo th('＋ Nouveau groupe'); ?></button>
                            <button type="button" class="mt-btn" id="mt-rename"><?php echo th('Renommer'); ?></button>
                            <button type="button" class="mt-btn mt-danger" id="mt-delete"><?php echo th('Supprimer'); ?></button>
                            <button type="button" class="mt-btn" id="mt-toggle-list"><?php echo th('Choisir les stations ▾'); ?></button>
                        </div>
                        <div id="mt-picker" class="mt-picker" hidden>
                            <div class="mt-row">
                                <input type="search" id="mt-search" placeholder="<?php echo th('Rechercher ou ajouter un indicatif (ex. VE2ABC-9)'); ?>">
                                <button type="button" class="mt-btn" id="mt-add"><?php echo th('Ajouter'); ?></button>
                                <button type="button" class="mt-btn" id="mt-all"><?php echo th('Tout cocher'); ?></button>
                                <button type="button" class="mt-btn" id="mt-none"><?php echo th('Tout décocher'); ?></button>
                            </div>
                            <div class="mt-hint" id="mt-hint"></div>
                            <div class="mt-list" id="mt-list"></div>
                        </div>
                    </div>

                    <div class="map-info" id="map-info">…</div>

                    <div id="map"></div>

                    <script>
                    (function () {
                        var HOURS = <?php echo intval($map_hours); ?>;
                        var REFRESH_MS = 30000;
                        var map = L.map('map').setView([<?php echo json_encode($S['lat']); ?>, <?php echo json_encode($S['lon']); ?>], 10);

                        // Tuiles servies par le Pi : depuis son cache (/var/www/aprs/tiles) si
                        // déjà vues, sinon nginx les récupère sur OpenStreetMap et les garde.
                        // Erreurs = pas d'Internet ET tuile absente du cache.
                        var offlineMode = false, tileErrorCount = 0;
                        var tileLayer = L.tileLayer('/tiles/{z}/{x}/{y}.png', {
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                            maxZoom: 18,
                            errorTileUrl: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
                        });
                        tileLayer.on('tileerror', function () {
                            tileErrorCount++;
                            if (tileErrorCount > 3 && !offlineMode) {
                                offlineMode = true;
                                document.getElementById('offline-warning').style.display = 'block';
                            }
                        });
                        tileLayer.addTo(map);

                        var $ = function (id) { return document.getElementById(id); };
                        var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) {
                            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };

                        // ---------- préférences et groupes (gardés dans ce navigateur)
                        var store = {
                            get: function (k, d) { try { var v = localStorage.getItem('aprs_map_' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
                            set: function (k, v) { try { localStorage.setItem('aprs_map_' + k, JSON.stringify(v)); } catch (e) {} }
                        };
                        var groups = store.get('groups', {});          // { "Club": ["VE2ABC-9", "VE2XYZ*"] }
                        var active = store.get('active', '');          // '' = toutes les stations
                        if (active && !groups[active]) active = '';
                        $('mt-trail').value = String(store.get('trail', 30));
                        $('mt-ages').checked = store.get('ages', true);
                        $('mt-follow').checked = store.get('follow', false);

                        function norm(c) { return String(c || '').toUpperCase().replace(/\s+/g, ''); }
                        function members() { return active ? groups[active] : null; }
                        // « VE2ABC* » = toutes les stations de VE2ABC (VE2ABC, VE2ABC-9, ...)
                        function matches(call, list) {
                            for (var i = 0; i < list.length; i++) {
                                var m = list[i];
                                if (m.slice(-1) === '*' ? call.indexOf(m.slice(0, -1)) === 0 : call === m) return true;
                            }
                            return false;
                        }
                        function visible(call) { var l = members(); return !l || matches(call, l); }
                        function saveGroups() { store.set('groups', groups); store.set('active', active); }

                        function fillGroups() {
                            var sel = $('mt-group');
                            sel.innerHTML = '<option value="">' + esc(t('Toutes les stations')) + '</option>' +
                                Object.keys(groups).sort().map(function (g) {
                                    return '<option value="' + esc(g) + '">⭐ ' + esc(g) + ' (' + groups[g].length + ')</option>';
                                }).join('');
                            sel.value = active;
                            $('mt-rename').hidden = $('mt-delete').hidden = !active;
                            $('mt-hint').textContent = active
                                ? t('Groupe « %s » : cochez ou décochez pour ajouter ou retirer une station. Les changements sont enregistrés tout de suite.', active)
                                : t('Créez un groupe pour ne suivre que certaines stations.');
                            $('mt-all').hidden = $('mt-none').hidden = $('mt-add').hidden = !active;
                        }

                        // ---------- carte
                        var data = {now: 0, stations: []}, fetchedAt = 0, firstLoad = true;
                        var layer = L.layerGroup().addTo(map);
                        var COLORS = ['#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#008080', '#f032e6', '#9a6324', '#800000', '#000075'];
                        function color(call) { var h = 0; for (var i = 0; i < call.length; i++) h = (h * 31 + call.charCodeAt(i)) >>> 0; return COLORS[h % COLORS.length]; }
                        function nowServer() { return data.now + (Date.now() - fetchedAt) / 1000; }
                        function age(ts) {
                            var s = Math.max(0, nowServer() - ts), m = Math.floor(s / 60);
                            if (m < 1) return t('à l\'instant');
                            if (m < 60) return t('%d min', m);
                            var h = Math.floor(m / 60);
                            if (h < 24) return t('%d h %s', h, (m % 60 < 10 ? '0' : '') + (m % 60));
                            return t('%d j', Math.floor(h / 24));
                        }
                        function ageClass(ts) { var m = (nowServer() - ts) / 60; return m < 10 ? 'age-fresh' : (m < 60 ? 'age-mid' : 'age-old'); }
                        function when(ts) { return new Date(ts * 1000).toLocaleString(window.APRS_LANG === 'en' ? 'en-CA' : 'fr-CA', {hour12: false}); }

                        var markers = {};
                        function draw() {
                            // garder ouverte la bulle que l'utilisateur lit
                            var openCall = null;
                            Object.keys(markers).forEach(function (c) { if (markers[c].mk.isPopupOpen()) openCall = c; });
                            layer.clearLayers();
                            markers = {};
                            var bounds = [], shown = 0, showAges = $('mt-ages').checked;
                            data.stations.forEach(function (s) {
                                if (!visible(s.call)) return;
                                shown++;
                                var col = color(s.call);
                                // trajet : ligne entre les positions + un point par position
                                if (s.track.length > 1) {
                                    var pts = s.track.map(function (p) { return [p[0], p[1]]; });
                                    L.polyline(pts, {color: col, weight: 3, opacity: 0.75}).addTo(layer);
                                    s.track.slice(0, -1).forEach(function (p) {
                                        L.circleMarker([p[0], p[1]], {radius: 3, color: col, weight: 1, fillOpacity: 0.8})
                                            .bindTooltip(esc(s.call) + ' · ' + esc(age(p[2])) + '<br>' + esc(when(p[2])))
                                            .addTo(layer);
                                        bounds.push([p[0], p[1]]);
                                    });
                                }
                                var icon = L.divIcon({className: 'station-icon', html: s.icon, iconSize: [24, 24], iconAnchor: [12, 12], popupAnchor: [0, -12]});
                                var mk = L.marker([s.lat, s.lon], {icon: icon, title: s.call, zIndexOffset: 1000}).addTo(layer);
                                mk.bindPopup('<div style="font-family: Arial; min-width: 200px;">' +
                                    '<strong style="font-size: 1.2em; color: ' + col + ';">' + esc(s.call) + '</strong>' +
                                    (s.sym ? ' <span style="color:#666">· ' + esc(s.sym) + '</span>' : '') + '<br>' +
                                    (s.comment ? '<em>' + esc(s.comment) + '</em><br>' : '') +
                                    '<hr style="margin: 8px 0;">' +
                                    '📍 <strong>' + esc(t('Position:')) + '</strong> ' + s.lat.toFixed(4) + '°, ' + s.lon.toFixed(4) + '°<br>' +
                                    '🕐 <strong>' + esc(t('Dernière réception:')) + '</strong> ' + esc(when(s.ts)) + ' (' + esc(age(s.ts)) + ')<br>' +
                                    '📦 <strong>' + esc(t('Paquets reçus:')) + '</strong> ' + s.n +
                                    (s.track.length > 1 ? '<br>〰️ ' + esc(t('%d positions sur le trajet', s.track.length)) : '') +
                                    '</div>', {autoPan: false});
                                if (showAges) {
                                    mk.bindTooltip(esc(s.call) + ' · <span class="age">' + esc(age(s.ts)) + '</span>',
                                        {permanent: true, direction: 'right', offset: [12, 0], className: 'age-label ' + ageClass(s.ts)});
                                }
                                markers[s.call] = {mk: mk, s: s};
                                bounds.push([s.lat, s.lon]);
                            });
                            var info = t('%d station(s)', shown) + ' · ' + t('entendues dans les %d dernières heures', HOURS);
                            if (members()) info = '⭐ ' + esc(active) + ' · ' + info;
                            $('map-info').innerHTML = '<strong>📍 ' + info + '</strong>';
                            if (openCall && markers[openCall]) markers[openCall].mk.openPopup();
                            return bounds;
                        }
                        // l'âge affiché avance entre deux mises à jour, sans tout redessiner
                        function updateAges() {
                            Object.keys(markers).forEach(function (c) {
                                var tip = markers[c].mk.getTooltip(), s = markers[c].s;
                                if (!tip) return;
                                tip.setContent(esc(s.call) + ' · <span class="age">' + esc(age(s.ts)) + '</span>');
                                var el = tip.getElement();
                                if (el) { el.classList.remove('age-fresh', 'age-mid', 'age-old'); el.classList.add(ageClass(s.ts)); }
                            });
                            if (!$('mt-picker').hidden) drawList();
                        }
                        function fit(bounds) {
                            if (bounds.length === 1) map.setView(bounds[0], Math.max(map.getZoom(), 13));
                            else if (bounds.length > 1) map.fitBounds(bounds, {padding: [50, 50], maxZoom: 15});
                        }

                        // ---------- liste des stations (cases à cocher)
                        function drawList() {
                            var q = norm($('mt-search').value), list = members(), rows = [], seen = {};
                            data.stations.forEach(function (s) {
                                seen[s.call] = true;
                                if (q && s.call.indexOf(q) < 0) return;
                                var on = list ? matches(s.call, list) : true;
                                rows.push('<label class="mt-item ' + ageClass(s.ts) + '"><input type="checkbox" data-call="' + esc(s.call) + '" ' +
                                    (on ? 'checked' : '') + (list ? '' : ' disabled') + '> <b>' + esc(s.call) + '</b> <span>' + esc(age(s.ts)) + '</span></label>');
                            });
                            // membres du groupe pas entendus pendant la période
                            (list || []).forEach(function (m) {
                                if (seen[m] || (q && m.indexOf(q) < 0)) return;
                                rows.push('<label class="mt-item age-none"><input type="checkbox" data-call="' + esc(m) + '" checked> <b>' + esc(m) + '</b> <span>' +
                                    esc(m.slice(-1) === '*' ? t('toutes ses stations') : t('pas entendue')) + '</span></label>');
                            });
                            $('mt-list').innerHTML = rows.join('') || '<span class="mt-hint">' + esc(t('Aucune station.')) + '</span>';
                        }
                        $('mt-list').addEventListener('change', function (e) {
                            var call = e.target.getAttribute('data-call'), list = members();
                            if (!call || !list) return;
                            var i = list.indexOf(call);
                            if (e.target.checked && i < 0) list.push(call);
                            if (!e.target.checked && i >= 0) list.splice(i, 1);
                            saveGroups(); fillGroups(); refreshView(true);
                        });

                        function refreshView(refit) {
                            var b = draw();
                            if (refit || $('mt-follow').checked) fit(b);
                            if (!$('mt-picker').hidden) drawList();
                        }

                        // ---------- boutons
                        $('mt-group').addEventListener('change', function () { active = this.value; saveGroups(); fillGroups(); refreshView(true); });
                        $('mt-new').addEventListener('click', function () {
                            var name = (prompt(t('Nom du nouveau groupe :'), '') || '').trim().slice(0, 30);
                            if (!name) return;
                            if (!groups[name]) groups[name] = [];
                            active = name; saveGroups(); fillGroups();
                            $('mt-picker').hidden = false; refreshView(false);
                        });
                        $('mt-rename').addEventListener('click', function () {
                            var name = (prompt(t('Nouveau nom du groupe :'), active) || '').trim().slice(0, 30);
                            if (!name || name === active || groups[name]) return;
                            groups[name] = groups[active]; delete groups[active]; active = name;
                            saveGroups(); fillGroups(); refreshView(false);
                        });
                        $('mt-delete').addEventListener('click', function () {
                            if (!active || !confirm(t('Supprimer le groupe « %s » ?', active))) return;
                            delete groups[active]; active = ''; saveGroups(); fillGroups(); refreshView(true);
                        });
                        $('mt-toggle-list').addEventListener('click', function () {
                            $('mt-picker').hidden = !$('mt-picker').hidden;
                            if (!$('mt-picker').hidden) drawList();
                        });
                        $('mt-search').addEventListener('input', drawList);
                        function addTyped() {
                            var c = norm($('mt-search').value), list = members();
                            if (!list || !/^[A-Z0-9]{1,9}(-[A-Z0-9]{1,2})?\*?$/.test(c)) return;
                            if (list.indexOf(c) < 0) list.push(c);
                            $('mt-search').value = ''; saveGroups(); fillGroups(); refreshView(true);
                        }
                        $('mt-add').addEventListener('click', addTyped);
                        $('mt-search').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addTyped(); } });
                        $('mt-all').addEventListener('click', function () {
                            var list = members(); if (!list) return;
                            data.stations.forEach(function (s) { if (list.indexOf(s.call) < 0 && (!$('mt-search').value || s.call.indexOf(norm($('mt-search').value)) >= 0)) list.push(s.call); });
                            saveGroups(); fillGroups(); refreshView(true);
                        });
                        $('mt-none').addEventListener('click', function () {
                            if (!active) return;
                            groups[active] = []; saveGroups(); fillGroups(); refreshView(false);
                        });
                        $('mt-trail').addEventListener('change', function () { store.set('trail', parseInt(this.value, 10)); load(); });
                        $('mt-ages').addEventListener('change', function () { store.set('ages', this.checked); refreshView(false); });
                        $('mt-follow').addEventListener('change', function () { store.set('follow', this.checked); refreshView(this.checked); });

                        // ---------- chargement et mise à jour automatique
                        var busy = false;
                        function load() {
                            if (busy) return;
                            busy = true;
                            fetch('map-data.php?hours=' + HOURS + '&trail=' + encodeURIComponent($('mt-trail').value), {cache: 'no-store'})
                                .then(function (r) { return r.json(); })
                                .then(function (d) {
                                    if (!d || !d.stations) throw 0;
                                    data = d; fetchedAt = Date.now();
                                    var b = draw();
                                    if (firstLoad) { firstLoad = false; if (!focusOn()) fit(b); }
                                    else if ($('mt-follow').checked) fit(b);
                                    if (!$('mt-picker').hidden) drawList();
                                    $('mt-status').textContent = t('Mise à jour automatique · %s', new Date().toLocaleTimeString(window.APRS_LANG === 'en' ? 'en-CA' : 'fr-CA', {hour12: false}));
                                })
                                .catch(function () { $('mt-status').textContent = t('Mise à jour impossible (nouvel essai dans 30 s)'); })
                                .then(function () { busy = false; });
                        }

                        // Lien « voir sur la carte » d'un paquet : centrer sur cette position
                        var focus = <?php echo json_encode(isset($_GET['lat'], $_GET['lon']) && is_numeric($_GET['lat']) && is_numeric($_GET['lon'])
                            ? ['lat' => (float)$_GET['lat'], 'lon' => (float)$_GET['lon'], 'label' => substr((string)($_GET['focus'] ?? ''), 0, 20)] : null); ?>;
                        function focusOn() {
                            if (!focus) return false;
                            map.setView([focus.lat, focus.lon], 13);
                            var m = markers[focus.label] && markers[focus.label].mk;
                            if (m && m.getLatLng().distanceTo([focus.lat, focus.lon]) < 50) m.openPopup();
                            else L.circleMarker([focus.lat, focus.lon], {radius: 9, color: '#e65100', weight: 3, fillOpacity: 0.2})
                                    .addTo(map).bindPopup('<strong>' + esc(focus.label) + '</strong>').openPopup();
                            return true;
                        }

                        fillGroups();
                        load();
                        setInterval(function () { if (!document.hidden) load(); }, REFRESH_MS);
                        setInterval(function () { if (!document.hidden) updateAges(); }, 15000);
                    })();
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
