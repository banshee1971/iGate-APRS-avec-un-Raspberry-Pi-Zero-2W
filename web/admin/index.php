<?php
// =====================================================================
//  /admin — Configuration de l'iGate APRS depuis le navigateur
//  Aucun mot de passe pour l'instant : à n'exposer que sur un réseau de
//  confiance (maison, AREDN).
// =====================================================================
require __DIR__ . '/../lib/settings.php';

$fields   = aprs_fields();
$settings = aprs_load_settings();
$errors   = [];
$flash    = $_GET['msg'] ?? '';

// ---------------------------------------------------------------- POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';
    if ($op === 'save') {
        $raw = [];
        foreach ($fields as $key => $f) {
            // Une case à cocher non cochée n'est pas envoyée par le navigateur
            $raw[$key] = $f['type'] === 'bool' ? isset($_POST[$key]) : ($_POST[$key] ?? $f['default']);
        }
        [$clean, $errors] = aprs_validate($raw);
        if (!$errors) {
            if (aprs_save_settings($clean) && aprs_queue_action('apply')) {
                header('Location: ./?msg=' . urlencode('Réglages enregistrés. Application en cours...'));
                exit;
            }
            $errors['_'] = "Impossible d'écrire dans " . APRS_STATE_DIR . ' (droits ?).';
        }
        // En cas d'erreur, réafficher ce que l'usager a tapé
        foreach ($raw as $k => $v) {
            $settings[$k] = $v;
        }
    } elseif (in_array($op, APRS_ACTIONS, true) && $op !== 'apply') {
        $labels = [
            'restart'    => 'Redémarrage des services demandé.',
            'backup'     => 'Sauvegarde demandée.',
            'usb-backup' => 'Copie vers la clé USB demandée.',
            'tiles'      => 'Pré-téléchargement des tuiles demandé (5 à 15 minutes).',
            'clear-tiles'=> 'Vidage du cache des tuiles demandé.',
            'reboot'     => 'Redémarrage du Pi demandé. La page reviendra dans 1 à 2 minutes.',
        ];
        $msg = aprs_queue_action($op) ? $labels[$op] : "Impossible de déposer la demande.";
        header('Location: ./?msg=' . urlencode($msg));
        exit;
    }
}

// ---------------------------------------------------------------- État
function svc_state($unit) {
    if (!function_exists('shell_exec')) return '?';
    $out = @shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null');
    return $out === null ? '?' : trim($out);
}
function dongle_present() {
    if (!function_exists('shell_exec')) return null;
    $out = @shell_exec('lsusb 2>/dev/null');
    return $out === null ? null : (strpos($out, '0bda:2838') !== false);
}

$services = [
    'aprs-igate.service'  => 'iGate (rtl_fm + Direwolf)',
    'aprs-parser.service' => 'Parser (base de données)',
    'nginx.service'       => 'Serveur web',
    'aprs-admin.path'     => 'Application des réglages',
];
function tile_cache_mb() {
    if (!is_dir(APRS_TILE_DIR) || !function_exists('shell_exec')) return null;
    $out = @shell_exec('du -sm ' . escapeshellarg(APRS_TILE_DIR) . ' 2>/dev/null');
    return $out === null ? null : intval($out);
}

$dongle  = dongle_present();
$tile_mb = tile_cache_mb();
$status  = aprs_status();
$pending = aprs_pending_actions();
$log     = is_readable(APRS_LOG_FILE) ? array_slice(file(APRS_LOG_FILE), -25) : [];

$today = null;
if (is_readable(APRS_DB_PATH)) {
    try {
        $db = new SQLite3(APRS_DB_PATH, SQLITE3_OPEN_READONLY);
        $today = $db->querySingle("SELECT COUNT(*) FROM packets WHERE date(timestamp, 'localtime') = date('now', 'localtime')");
        $db->close();
    } catch (Exception $e) {
        $today = null;
    }
}
$station = aprs_station($settings);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($pending > 0): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
    <title>Configuration - <?php echo h(aprs_title($settings)); ?></title>
    <?php echo aprs_leaflet_tags(); ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh; padding: 20px; color: #333;
        }
        .container { max-width: 1100px; margin: 0 auto; }
        .card {
            background: white; padding: 20px 30px; border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 20px;
        }
        h1 { font-size: 1.8em; margin-bottom: 5px; }
        h2 { font-size: 1.25em; margin-bottom: 15px; color: #333; }
        .subtitle { color: #666; }
        .subtitle a, .back { color: #667eea; font-weight: 500; text-decoration: none; }
        .flash { background: #e7f3ff; border-left: 4px solid #2196F3; padding: 12px 15px; border-radius: 5px; margin-bottom: 20px; }
        .error-box { background: #fdecea; border-left: 4px solid #e53935; padding: 12px 15px; border-radius: 5px; margin-bottom: 20px; color: #b71c1c; }
        .warn { background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px 15px; border-radius: 5px; margin-bottom: 20px; color: #856404; }
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
        .status-item { background: #f8f9fa; border-radius: 5px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .pill { padding: 2px 10px; border-radius: 10px; font-size: 0.85em; font-weight: 600; white-space: nowrap; }
        .ok { background: #d4edda; color: #155724; }
        .bad { background: #f8d7da; color: #721c24; }
        .unk { background: #e2e3e5; color: #383d41; }
        .fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px 25px; }
        .field label { display: block; font-weight: 500; margin-bottom: 5px; color: #555; }
        .field input[type=text], .field input[type=number], .field select {
            width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 5px; font-size: 1em;
        }
        .field input:focus, .field select:focus { outline: none; border-color: #667eea; }
        .field.check label { display: flex; gap: 8px; align-items: center; }
        .field.check input { width: 18px; height: 18px; }
        .field .help { font-size: 0.85em; color: #777; margin-top: 4px; }
        .field .err { font-size: 0.85em; color: #c62828; margin-top: 4px; font-weight: 500; }
        .field.has-error input, .field.has-error select { border-color: #e53935; }
        #pickmap { height: 280px; border-radius: 5px; margin-top: 15px; }
        .btn {
            padding: 10px 22px; background: #667eea; color: white; border: none; border-radius: 5px;
            font-size: 1em; cursor: pointer; text-decoration: none; display: inline-block;
        }
        .btn:hover { background: #5568d3; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-danger { background: #c62828; }
        .btn-danger:hover { background: #a61f1f; }
        .save-bar { position: sticky; bottom: 0; background: white; padding: 15px 30px; border-radius: 10px; box-shadow: 0 -2px 8px rgba(0,0,0,0.1); display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .actions form { display: inline; }
        pre.log { background: #1e1e2e; color: #e0e0e0; padding: 12px; border-radius: 5px; font-size: 0.8em; max-height: 260px; overflow: auto; white-space: pre-wrap; }
        .muted { color: #777; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>⚙️ Configuration de l'iGate</h1>
        <div class="subtitle"><?php echo h($station); ?> · <a href="/">← Retour à l'interface</a></div>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo h($flash); ?></div><?php endif; ?>
    <?php if ($errors): ?>
        <div class="error-box">
            <strong>Les réglages n'ont pas été enregistrés.</strong> Corrigez les champs en rouge.
            <?php if (isset($errors['_'])) echo '<br>' . h($errors['_']); ?>
        </div>
    <?php endif; ?>
    <?php if (!aprs_is_configured($settings)): ?>
        <div class="warn"><strong>👋 Bienvenue !</strong> Entrez au minimum votre <strong>indicatif d'appel</strong> et la <strong>position</strong> de l'iGate, puis cliquez sur « Enregistrer et appliquer ». L'iGate démarrera automatiquement.</div>
    <?php endif; ?>

    <!-- État du système -->
    <div class="card">
        <h2>🩺 État du système</h2>
        <div class="status-grid">
            <?php foreach ($services as $unit => $label):
                $st = svc_state($unit);
                $cls = $st === 'active' ? 'ok' : ($st === '?' ? 'unk' : 'bad'); ?>
                <div class="status-item"><span><?php echo h($label); ?></span><span class="pill <?php echo $cls; ?>"><?php echo h($st); ?></span></div>
            <?php endforeach; ?>
            <div class="status-item"><span>Dongle RTL-SDR</span>
                <?php if ($dongle === null): ?><span class="pill unk">?</span>
                <?php elseif ($dongle): ?><span class="pill ok">détecté</span>
                <?php else: ?><span class="pill bad">absent</span><?php endif; ?>
            </div>
            <div class="status-item"><span>Cache des tuiles</span><span class="pill unk"><?php echo $tile_mb === null ? '?' : $tile_mb . ' / ' . intval($settings['tile_cache_max_mb']) . ' Mo'; ?></span></div>
            <div class="status-item"><span>Paquets aujourd'hui</span><span class="pill unk"><?php echo $today === null ? '?' : number_format($today); ?></span></div>
        </div>
        <p class="muted" style="margin-top: 12px;">
            <?php if ($pending > 0): ?>
                ⏳ <?php echo $pending; ?> demande(s) en cours de traitement... (la page se rafraîchit toute seule)
            <?php elseif ($status): ?>
                Dernière action (<?php echo h($status['time']); ?>) :
                <?php echo $status['ok'] ? '✅' : '❌'; ?> <?php echo h($status['message']); ?>
            <?php endif; ?>
            <?php if (aprs_is_configured($settings)): ?>
                · <a class="back" href="https://aprs.fi/#!call=a%2F<?php echo urlencode($station); ?>" target="_blank" rel="noopener">Voir <?php echo h($station); ?> sur aprs.fi ↗</a>
            <?php endif; ?>
        </p>
    </div>

    <!-- Réglages -->
    <form method="POST" action="./">
        <input type="hidden" name="op" value="save">
        <?php foreach (aprs_schema()['sections'] as $section): ?>
            <div class="card">
                <h2><?php echo h($section['title']); ?></h2>
                <div class="fields">
                <?php foreach ($section['fields'] as $f):
                    $key = $f['key'];
                    $val = $settings[$key];
                    $err = $errors[$key] ?? null;
                    $cls = 'field' . ($f['type'] === 'bool' ? ' check' : '') . ($err ? ' has-error' : ''); ?>
                    <div class="<?php echo $cls; ?>">
                        <?php if ($f['type'] === 'bool'): ?>
                            <label><input type="checkbox" name="<?php echo h($key); ?>" <?php echo $val ? 'checked' : ''; ?>> <?php echo h($f['label']); ?></label>
                        <?php else: ?>
                            <label for="f_<?php echo h($key); ?>"><?php echo h($f['label']); ?></label>
                            <?php if ($f['type'] === 'choice'): ?>
                                <select id="f_<?php echo h($key); ?>" name="<?php echo h($key); ?>">
                                    <?php foreach ($f['choices'] as $c): ?>
                                        <option value="<?php echo h($c['value']); ?>" <?php echo (string)$val === (string)$c['value'] ? 'selected' : ''; ?>><?php echo h($c['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($f['type'] === 'int' || $f['type'] === 'float'): ?>
                                <input type="number" id="f_<?php echo h($key); ?>" name="<?php echo h($key); ?>"
                                       value="<?php echo h($val); ?>" min="<?php echo h($f['min']); ?>" max="<?php echo h($f['max']); ?>"
                                       step="<?php echo $f['type'] === 'int' ? '1' : h(pow(10, -($f['decimals'] ?? 4))); ?>">
                            <?php else: ?>
                                <input type="text" id="f_<?php echo h($key); ?>" name="<?php echo h($key); ?>"
                                       value="<?php echo h($val); ?>" placeholder="<?php echo h($f['placeholder'] ?? ''); ?>">
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($err): ?><div class="err">⚠️ <?php echo h($err); ?></div><?php endif; ?>
                        <?php if ($key === 'passcode' && $settings['passcode'] === '' && aprs_is_configured($settings)): ?>
                            <div class="help">Passcode calculé pour <?php echo h($settings['callsign']); ?> : <strong><?php echo aprs_passcode($settings['callsign']); ?></strong></div>
                        <?php elseif (!empty($f['help'])): ?>
                            <div class="help"><?php echo h($f['help']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
                <?php if ($section['id'] === 'position'): ?>
                    <div id="pickmap"></div>
                    <div class="help muted" style="margin-top: 5px;">Cliquez sur la carte pour placer l'iGate (nécessite Internet ou des tuiles hors ligne).</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="save-bar">
            <button type="submit" class="btn">💾 Enregistrer et appliquer</button>
            <span class="muted">Direwolf, les services et les tâches planifiées seront régénérés et redémarrés au besoin.</span>
        </div>
    </form>

    <!-- Actions -->
    <div class="card" style="margin-top: 20px;">
        <h2>🧰 Actions</h2>
        <div class="actions">
            <form method="POST" action="./"><input type="hidden" name="op" value="restart"><button class="btn btn-secondary">🔄 Redémarrer les services</button></form>
            <form method="POST" action="./"><input type="hidden" name="op" value="backup"><button class="btn btn-secondary">💾 Sauvegarder maintenant</button></form>
            <form method="POST" action="./"><input type="hidden" name="op" value="usb-backup"><button class="btn btn-secondary">🔌 Copier les sauvegardes sur clé USB</button></form>
            <form method="POST" action="./"><input type="hidden" name="op" value="tiles"><button class="btn btn-secondary">🗺️ Pré-télécharger les tuiles autour de l'iGate</button></form>
            <form method="POST" action="./" onsubmit="return confirm('Effacer toutes les tuiles gardées sur le Pi ? La carte Offline sera vide.');"><input type="hidden" name="op" value="clear-tiles"><button class="btn btn-secondary">🧹 Vider le cache des tuiles</button></form>
            <form method="POST" action="./" onsubmit="return confirm('Redémarrer le Raspberry Pi ?');"><input type="hidden" name="op" value="reboot"><button class="btn btn-danger">⏻ Redémarrer le Pi</button></form>
        </div>
    </div>

    <!-- Journal -->
    <div class="card">
        <h2>📜 Journal des dernières actions</h2>
        <?php if ($log): ?>
            <pre class="log"><?php echo h(implode('', $log)); ?></pre>
        <?php else: ?>
            <p class="muted">Aucune action pour l'instant.</p>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    if (typeof L === 'undefined') return;
    var latInput = document.getElementById('f_lat');
    var lonInput = document.getElementById('f_lon');
    var lat = parseFloat(latInput.value) || 45.5017;
    var lon = parseFloat(lonInput.value) || -73.5673;
    var map = L.map('pickmap').setView([lat, lon], 11);
    L.tileLayer('/tiles/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 18
    }).addTo(map);
    var marker = L.marker([lat, lon], {draggable: true}).addTo(map);
    function setPos(ll) {
        latInput.value = ll.lat.toFixed(4);
        lonInput.value = ll.lng.toFixed(4);
        marker.setLatLng(ll);
    }
    map.on('click', function (e) { setPos(e.latlng); });
    marker.on('dragend', function () { setPos(marker.getLatLng()); });
    function fromInputs() {
        var a = parseFloat(latInput.value), b = parseFloat(lonInput.value);
        if (!isNaN(a) && !isNaN(b)) { marker.setLatLng([a, b]); map.panTo([a, b]); }
    }
    latInput.addEventListener('change', fromInputs);
    lonInput.addEventListener('change', fromInputs);
})();
</script>
</body>
</html>
