<?php
// /admin/updates.php — mises à jour du système (apt) et de l'iGate (dépôt GitHub)
// sans passer par la ligne de commande. Le travail est fait en root par
// aprs-apply-config ; cette page dépose les demandes et affiche le résultat.
require __DIR__ . '/../lib/admin.php';
aprs_require_login();
aprs_csrf_check();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';
    $labels = [
        'update-check' => 'Recherche des mises à jour demandée (1 à 2 minutes).',
        'os-upgrade'   => 'Mise à jour du système demandée.',
        'kit-update'   => 'Mise à jour de l\'iGate demandée.',
    ];
    $msg = (isset($labels[$op]) && aprs_queue_action($op)) ? $labels[$op] : 'Impossible de déposer la demande.';
    header('Location: updates.php?msg=' . urlencode($msg));
    exit;
}

$info    = is_readable(APRS_UPDATES_FILE) ? json_decode((string)file_get_contents(APRS_UPDATES_FILE), true) : null;
$os      = is_array($info['os'] ?? null) ? $info['os'] : null;
$kit     = is_array($info['kit'] ?? null) ? $info['kit'] : null;
$running = [];
foreach (['os' => 'aprs-os-upgrade.service', 'kit' => 'aprs-kit-update.service'] as $kind => $unit) {
    if (in_array(aprs_svc_state($unit), ['active', 'activating'], true)) $running[] = $kind;
}
$pending = aprs_pending_actions();
$status  = aprs_status();
$log     = is_readable(APRS_UPDATE_LOG) ? array_slice(file(APRS_UPDATE_LOG), -40) : [];
$busy    = $running || $pending > 0;

aprs_admin_header(t('🔄 Mises à jour'), 'updates', ['refresh' => $busy ? 5 : 0]);
aprs_flash_box($_GET['msg'] ?? '');
?>
    <?php if ($running): ?>
        <div class="warn">⏳ <strong><?php echo th(in_array('kit', $running, true) ? 'Mise à jour de l\'iGate en cours.' : 'Mise à jour du système en cours.'); ?></strong>
            <?php echo th('La page se rafraîchit toute seule. Pendant la mise à jour de l\'iGate, l\'interface peut être indisponible quelques secondes.'); ?></div>
    <?php elseif ($pending > 0): ?>
        <div class="warn">⏳ <?php echo th('%d demande(s) en cours de traitement... (la page se rafraîchit toute seule)', $pending); ?></div>
    <?php endif; ?>

    <?php if (!empty($info['reboot_required'])): ?>
        <div class="warn">🔁 <strong><?php echo th('Redémarrage recommandé.'); ?></strong>
            <?php echo th('Une mise à jour (noyau ou bibliothèque système) ne sera active qu\'après un redémarrage du Pi.'); ?>
            <form method="POST" action="./" style="display: inline;" onsubmit="return confirm(t('Redémarrer le Raspberry Pi ?'));">
                <?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="reboot">
                <button class="btn btn-small btn-danger"><?php echo th('⏻ Redémarrer le Pi'); ?></button>
            </form>
        </div>
    <?php endif; ?>

    <!-- Recherche -->
    <div class="card">
        <h2><?php echo th('🔎 Rechercher les mises à jour'); ?></h2>
        <div class="actions" style="align-items: center;">
            <form method="POST" action="updates.php"><?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="update-check">
                <button class="btn" <?php echo $busy ? 'disabled' : ''; ?>><?php echo th('🔎 Rechercher maintenant'); ?></button></form>
            <span class="muted">
                <?php if ($info): ?><?php echo th('Dernière recherche : %s', $info['checked'] ?? '?'); ?>
                <?php else: ?><?php echo th('Aucune recherche pour l\'instant.'); ?><?php endif; ?>
            </span>
        </div>
        <?php if ($status && in_array($status['action'] ?? '', ['update-check', 'os-upgrade', 'kit-update', 'update-os', 'update-kit'], true)): ?>
            <p class="muted" style="margin-top: 10px;"><?php echo th('Dernière action (%s) :', $status['time']); ?>
                <?php echo $status['ok'] ? '✅' : '❌'; ?> <?php echo h(t_msg($status['message'])); ?></p>
        <?php endif; ?>
    </div>

    <!-- Système -->
    <div class="card">
        <h2><?php echo th('🐧 Système (Raspberry Pi OS)'); ?></h2>
        <?php if (!$os): ?>
            <p class="muted"><?php echo th('Cliquez sur « Rechercher maintenant » pour voir les mises à jour disponibles.'); ?></p>
        <?php elseif (isset($os['error'])): ?>
            <p class="error-text">❌ <?php echo h(t_msg($os['error'])); ?></p>
        <?php elseif (intval($os['count'] ?? 0) === 0): ?>
            <p>✅ <?php echo th('Le système est à jour.'); ?></p>
        <?php else: ?>
            <p><strong><?php echo th('%d paquet(s) à mettre à jour.', intval($os['count'])); ?></strong>
               <?php echo th('Comptez 5 à 30 minutes sur un Pi Zero 2W. L\'iGate continue de fonctionner pendant la mise à jour.'); ?></p>
            <details>
                <summary><?php echo th('Voir la liste'); ?></summary>
                <table class="upd-table">
                    <thead><tr><th><?php echo th('Logiciel'); ?></th><th><?php echo th('Version installée'); ?></th><th><?php echo th('Nouvelle version'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($os['packages'] ?? [] as $p): ?>
                        <tr><td><?php echo h($p['name'] ?? ''); ?></td><td class="muted"><?php echo h($p['old'] ?? ''); ?></td><td><?php echo h($p['new'] ?? ''); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <form method="POST" action="updates.php" style="margin-top: 12px;"
                  onsubmit="return confirm(t('Installer les mises à jour du système maintenant ?'));">
                <?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="os-upgrade">
                <button class="btn" <?php echo $busy ? 'disabled' : ''; ?>><?php echo th('⬇️ Installer les mises à jour du système'); ?></button>
            </form>
        <?php endif; ?>
    </div>

    <!-- iGate (dépôt GitHub) -->
    <div class="card">
        <h2><?php echo th('📡 iGate APRS (dépôt GitHub)'); ?></h2>
        <?php if (!$kit): ?>
            <p class="muted"><?php echo th('Cliquez sur « Rechercher maintenant » pour voir les mises à jour disponibles.'); ?></p>
        <?php elseif (isset($kit['error'])): ?>
            <p class="error-text">❌ <?php echo h(t_msg($kit['error'])); ?></p>
        <?php else: ?>
            <p class="muted"><?php echo th('Version installée : %s du %s (branche %s)', $kit['local'] ?? '?', $kit['local_date'] ?? '?', $kit['branch'] ?? '?'); ?></p>
            <?php if (!empty($kit['fetch_error'])): ?>
                <p class="error-text">⚠️ <?php echo th('GitHub injoignable : la liste ci-dessous peut être incomplète.'); ?></p>
            <?php endif; ?>
            <?php if (!empty($kit['dirty'])): ?>
                <p class="error-text">⚠️ <?php echo th('Des fichiers du kit ont été modifiés sur le Pi : la mise à jour risque d\'échouer.'); ?></p>
            <?php endif; ?>
            <?php if (intval($kit['behind'] ?? 0) === 0): ?>
                <p>✅ <?php echo th('L\'iGate est à jour.'); ?></p>
            <?php else: ?>
                <p><strong><?php echo th('%d nouveauté(s) disponible(s) :', intval($kit['behind'])); ?></strong></p>
                <ul class="upd-commits">
                    <?php foreach ($kit['commits'] ?? [] as $c): ?>
                        <li><span class="muted"><?php echo h($c['date'] ?? ''); ?></span> <?php echo h($c['subject'] ?? ''); ?></li>
                    <?php endforeach; ?>
                </ul>
                <form method="POST" action="updates.php" style="margin-top: 12px;"
                      onsubmit="return confirm(t('Mettre à jour l\'iGate maintenant ? Les services redémarreront.'));">
                    <?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="kit-update">
                    <button class="btn" <?php echo $busy ? 'disabled' : ''; ?>><?php echo th('⬇️ Mettre à jour l\'iGate'); ?></button>
                </form>
                <p class="help muted"><?php echo th('Équivaut à « git pull » puis « sudo ./install.sh --skip-upgrade ». Vos réglages, votre compte et vos paquets sont conservés.'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Journal -->
    <div class="card">
        <h2><?php echo th('📜 Journal de la dernière mise à jour'); ?></h2>
        <?php if ($log): ?>
            <pre class="log" id="upd-log"><?php echo h(implode('', $log)); ?></pre>
            <script>(function () { var l = document.getElementById('upd-log'); l.scrollTop = l.scrollHeight; })();</script>
        <?php else: ?>
            <p class="muted"><?php echo th('Aucune mise à jour pour l\'instant.'); ?></p>
        <?php endif; ?>
    </div>
<?php aprs_admin_footer();
