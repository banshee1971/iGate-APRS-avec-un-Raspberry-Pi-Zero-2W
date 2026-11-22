<?php
// =====================================================================
//  /admin — Configuration de l'iGate APRS depuis le navigateur
//  (le compte est créé par install.sh ; oublié ? sudo aprs-admin-passwd)
// =====================================================================
require __DIR__ . '/../lib/admin.php';
aprs_require_login();
aprs_csrf_check();

$sections = aprs_sections_for('config');
$settings = aprs_load_settings();
$errors   = [];
$flash    = $_GET['msg'] ?? '';

// ---------------------------------------------------------------- POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';
    if ($op === 'save') {
        [$settings, $errors] = aprs_handle_save($sections, './');
    } elseif (in_array($op, APRS_ACTIONS, true) && $op !== 'apply') {
        $labels = [
            'restart'    => 'Redémarrage des services demandé.',
            'backup'     => 'Sauvegarde demandée.',
            'usb-backup' => 'Copie vers la clé USB demandée.',
            'tiles'      => 'Pré-téléchargement des tuiles demandé (5 à 15 minutes).',
            'clear-tiles'=> 'Vidage du cache des tuiles demandé.',
            'gain-test'  => 'Test des gains demandé (environ 6 minutes).',
            'reboot'     => 'Redémarrage du Pi demandé. La page reviendra dans 1 à 2 minutes.',
        ];
        $msg = aprs_queue_action($op) ? $labels[$op] : 'Impossible de déposer la demande.';
        header('Location: ./?msg=' . urlencode($msg));
        exit;
    }
}

// ---------------------------------------------------------------- État
$services = [
    'aprs-igate.service'  => t('iGate (rtl_fm + Direwolf)'),
    'aprs-parser.service' => t('Parser (base de données)'),
    'nginx.service'       => t('Serveur web'),
    'aprs-admin.path'     => t('Application des réglages'),
];
function tile_cache_mb() {
    if (!is_dir(APRS_TILE_DIR)) return null;
    $out = aprs_shell('du -sm ' . escapeshellarg(APRS_TILE_DIR));
    return $out === null ? null : intval($out);
}

$dongle  = aprs_dongle_present();
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

aprs_admin_header(t('⚙️ Configuration de l\'iGate'), 'config', ['leaflet' => true, 'refresh' => $pending > 0 ? 3 : 0]);
aprs_flash_box($flash, $errors);
?>
    <?php if (!aprs_is_configured($settings)): ?>
        <div class="warn"><strong><?php echo th('👋 Bienvenue !'); ?></strong> <?php echo t('1) Entrez votre <strong>indicatif d\'appel</strong> dans l\'onglet %s ; 2) placez l\'iGate sur la carte ci-dessous et cliquez sur « Enregistrer et appliquer ». L\'iGate démarrera automatiquement. Les réglages radio et de décodage sont dans l\'onglet %s.',
            '<a class="back" href="reporting.php">' . th('📡 Reporting aprs.fi') . '</a>',
            '<a class="back" href="direwolf.php">' . th('🐺 Direwolf') . '</a>'); ?></div>
    <?php endif; ?>

    <!-- État du système -->
    <div class="card">
        <h2><?php echo th('🩺 État du système'); ?></h2>
        <div class="status-grid">
            <?php foreach ($services as $unit => $label):
                ?>
                <div class="status-item"><span><?php echo h($label); ?></span><?php echo aprs_svc_pill(aprs_svc_state($unit)); ?></div>
            <?php endforeach; ?>
            <div class="status-item"><span><?php echo th('Dongle SDR'); ?></span>
                <?php if ($dongle === null): ?><span class="pill unk">?</span>
                <?php else: [$dongle_ok, $dongle_name] = aprs_dongle_label(); ?>
                    <span class="pill <?php echo $dongle_ok ? 'ok' : 'bad'; ?>" title="<?php echo th('Détails : onglet Direwolf'); ?>"><?php echo h($dongle_name); ?></span>
                <?php endif; ?>
            </div>
            <div class="status-item"><span><?php echo th('Cache des tuiles'); ?></span><span class="pill unk"><?php echo $tile_mb === null ? '?' : h(t('%d / %d Mo', $tile_mb, intval($settings['tile_cache_max_mb']))); ?></span></div>
            <div class="status-item"><span><?php echo th('Paquets aujourd\'hui'); ?></span><span class="pill unk"><?php echo $today === null ? '?' : number_format($today); ?></span></div>
        </div>
        <p class="muted" style="margin-top: 12px;">
            <?php if ($pending > 0): ?>
                ⏳ <?php echo th('%d demande(s) en cours de traitement... (la page se rafraîchit toute seule)', $pending); ?>
            <?php elseif ($status): ?>
                <?php echo th('Dernière action (%s) :', $status['time']); ?>
                <?php echo $status['ok'] ? '✅' : '❌'; ?> <?php echo h(t_msg($status['message'])); ?>
            <?php endif; ?>
            <?php if (aprs_is_configured($settings)): ?>
                · <a class="back" href="https://aprs.fi/#!call=a%2F<?php echo urlencode($station); ?>" target="_blank" rel="noopener"><?php echo th('Voir %s sur aprs.fi ↗', $station); ?></a>
            <?php endif; ?>
        </p>
    </div>

    <!-- Réglages -->
    <form method="POST" action="./">
        <?php echo aprs_csrf_field(); ?>
        <input type="hidden" name="op" value="save">
        <?php aprs_render_sections($sections, $settings, $errors, [
            'position' => '<div id="pickmap"></div><div class="help muted" style="margin-top: 5px;">' . th('Cliquez sur la carte pour placer l’iGate (nécessite Internet ou des tuiles en cache).') . '</div>',
        ]); ?>

        <div class="save-bar">
            <button type="submit" class="btn"><?php echo th('💾 Enregistrer et appliquer'); ?></button>
            <span class="muted"><?php echo th('Direwolf, les services et les tâches planifiées seront régénérés et redémarrés au besoin.'); ?></span>
        </div>
    </form>

    <!-- Actions -->
    <div class="card" style="margin-top: 20px;">
        <h2><?php echo th('🧰 Actions'); ?></h2>
        <div class="actions">
            <form method="POST" action="./"><?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="restart"><button class="btn btn-secondary"><?php echo th('🔄 Redémarrer les services'); ?></button></form>
            <form method="POST" action="./"><?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="backup"><button class="btn btn-secondary"><?php echo th('💾 Sauvegarder maintenant'); ?></button></form>
            <form method="POST" action="./"><?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="usb-backup"><button class="btn btn-secondary"><?php echo th('🔌 Copier les sauvegardes sur clé USB'); ?></button></form>
            <form method="POST" action="./"><?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="tiles"><button class="btn btn-secondary"><?php echo th('🗺️ Pré-télécharger les tuiles autour de l\'iGate'); ?></button></form>
            <form method="POST" action="./" onsubmit="return confirm(t('Effacer toutes les tuiles gardées sur le Pi ? La carte Offline sera vide.'));"><?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="clear-tiles"><button class="btn btn-secondary"><?php echo th('🧹 Vider le cache des tuiles'); ?></button></form>
            <form method="POST" action="./" onsubmit="return confirm(t('Redémarrer le Raspberry Pi ?'));"><?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="reboot"><button class="btn btn-danger"><?php echo th('⏻ Redémarrer le Pi'); ?></button></form>
        </div>
    </div>

    <!-- Journal -->
    <div class="card">
        <h2><?php echo th('📜 Journal des dernières actions'); ?></h2>
        <?php if ($log): ?>
            <pre class="log"><?php echo h(implode('', $log)); ?></pre>
        <?php else: ?>
            <p class="muted"><?php echo th('Aucune action pour l\'instant.'); ?></p>
        <?php endif; ?>
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
<?php aprs_admin_footer();
