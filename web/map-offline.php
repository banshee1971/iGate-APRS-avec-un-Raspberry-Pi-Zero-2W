<?php
// Carte « Offline » : n'utilise QUE les tuiles déjà présentes dans le cache
// du Pi (/var/www/aprs/tiles) — celles pré-téléchargées et celles vues en
// naviguant sur la carte normale. Aucune requête vers Internet.
require __DIR__ . '/lib/settings.php';
require __DIR__ . '/lib/packets.php';
require __DIR__ . '/lib/map_tracker.php';
$S = aprs_load_settings();
aprs_lang();   // choisit la langue (et pose le cookie ?lang=) avant toute sortie

$map_hours = isset($_GET['hours']) ? intval($_GET['hours']) : intval($S['map_default_hours']);


$hours_choices = [1 => t('Dernière heure'), 6 => t('%d dernières heures', 6), 12 => t('%d dernières heures', 12),
                  24 => t('%d dernières heures', 24), 48 => t('%d dernières heures', 48), 168 => t('%d derniers jours', 7)];
$hours_choices = array_map('ucfirst', $hours_choices);   // « last 6 hours » -> « Last 6 hours »
?>
<!DOCTYPE html>
<html lang="<?php echo h(aprs_lang()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h(aprs_title($S)); ?> - <?php echo th('Carte Offline'); ?></title>
    <?php echo aprs_leaflet_tags(); ?>
    <?php echo aprs_i18n_js(); ?>
    <?php echo aprs_map_tracker_css(); ?>
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
        .header-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; }
        .lang-switch { font-size: 0.85em; color: #888; white-space: nowrap; }
        .lang-switch a { color: inherit; text-decoration: none; }
        .lang-switch a:hover { text-decoration: underline; }
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
            <div class="header-top">
                <h1>🗺️ <?php echo h(aprs_title($S)); ?> - <?php echo th('Carte Offline'); ?></h1>
                <?php echo aprs_lang_switch(); ?>
            </div>
            <a href="index.php?tab=map&hours=<?php echo $map_hours; ?>" class="back-link">← <?php echo th('Retour à la carte en ligne'); ?></a>
        </div>

        <div class="content">
            <div class="offline-notice">
                <strong>📴 <?php echo th('Mode Offline :'); ?></strong> <?php echo th('cette carte n\'utilise que les tuiles gardées en cache sur le Pi'); ?>
                (<?php echo th('celles déjà consultées sur la carte en ligne, ou pré-téléchargées depuis'); ?> <a href="/admin/"><?php echo th('la configuration'); ?></a>).
                <?php echo th('Les zones jamais visitées apparaissent en quadrillage gris.'); ?>
            </div>

            <div class="filters">
                <form method="GET" action="">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="hours"><?php echo th('Période:'); ?></label>
                            <select id="hours" name="hours" onchange="this.form.submit()">
                                <?php foreach ($hours_choices as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $map_hours === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>
            </div>

            <?php aprs_map_tracker($S, $map_hours, '/tiles-cache/{z}/{x}/{y}.png', false); ?>
        </div>
    </div>

</body>
</html>
