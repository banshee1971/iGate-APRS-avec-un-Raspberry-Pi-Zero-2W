<?php
// /admin/network.php — profils réseau : Ethernet (DHCP / fixe), Wi-Fi client ou borne Wi-Fi
require __DIR__ . '/../lib/admin.php';
require __DIR__ . '/../lib/network.php';
aprs_require_login();
aprs_csrf_check();

$data    = aprs_net_load();
$state   = aprs_net_state();
$active  = $state['active'] ?? 'origine';
$pending = aprs_net_pending();
$flash   = $_GET['msg'] ?? '';
$errors  = [];
$form    = null;     // profil en cours d'édition (affiché dans le formulaire)
$edit_id = $_GET['edit'] ?? (isset($_GET['new']) ? '' : null);

function net_redirect($msg) {
    header('Location: network.php?msg=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';
    $id = (string)($_POST['id'] ?? '');
    $p  = aprs_net_find($data, $id);

    if ($op === 'activate' && $p) {
        $payload = $id . (!empty($_POST['no_confirm']) ? ' --no-confirm' : '');
        net_redirect(aprs_queue_action('network', $payload)
            ? t('Activation du profil « %s » demandée.', aprs_net_name($p))
            : 'Impossible de déposer la demande.');
    } elseif ($op === 'confirm' && $pending) {
        @file_put_contents(APRS_NET_CONFIRM, $pending['token']);
        aprs_queue_action('network-confirm');
        net_redirect('Configuration réseau confirmée.');
    } elseif ($op === 'delete' && $p && empty($p['builtin']) && $id !== $active) {
        $data['profiles'] = array_values(array_filter($data['profiles'], function ($x) use ($id) { return $x['id'] !== $id; }));
        if ($data['fallback'] === $id) $data['fallback'] = '';
        net_redirect(aprs_net_save($data) ? t('Profil « %s » supprimé.', aprs_net_name($p)) : 'Écriture impossible.');
    } elseif ($op === 'fallback') {
        $fb = (string)($_POST['fallback'] ?? '');
        $data['fallback'] = ($fb === '' || aprs_net_find($data, $fb)) ? $fb : '';
        net_redirect(aprs_net_save($data) ? 'Profil de secours enregistré.' : 'Écriture impossible.');
    } elseif ($op === 'save') {
        $is_new = $id === '';
        if (!$is_new && (!$p || !empty($p['builtin']))) net_redirect('Ce profil ne peut pas être modifié.');
        // Jamais de mot de passe renvoyé au navigateur : champ vide = garder l'ancien
        $in = array_filter($_POST, function ($k) { return $k[0] !== '_'; }, ARRAY_FILTER_USE_KEY);
        if ($p && ($p['wifi']['mode'] ?? '') === 'client') $in['_keep_wifi_password'] = $p['wifi']['password'] ?? '';
        if ($p && ($p['wifi']['mode'] ?? '') === 'ap') $in['_keep_ap_password'] = $p['wifi']['password'] ?? '';
        $new_id = $is_new ? 'p' . bin2hex(random_bytes(4)) : $id;
        [$form, $errors] = aprs_net_from_post($in, $new_id);
        if (!$errors) {
            if ($is_new) {
                $data['profiles'][] = $form;
            } else {
                foreach ($data['profiles'] as $i => $x) {
                    if ($x['id'] === $id) $data['profiles'][$i] = $form;
                }
            }
            $msg = t('Profil « %s » enregistré.', $form['name']);
            if ($id === $active) $msg .= ' ' . t('Cliquez sur « Activer » pour appliquer les changements.');
            net_redirect(aprs_net_save($data) ? $msg : t('Écriture impossible (%s).', APRS_NET_PROFILES));
        }
        $edit_id = $is_new ? '' : $id;
    }
}

if ($edit_id !== null && $form === null) {
    $form = $edit_id === '' ? ['id' => '', 'name' => '', 'eth' => ['mode' => 'dhcp'], 'wifi' => ['mode' => 'ap', 'channel' => 6, 'ssid' => 'APRS-' . preg_replace('/[^A-Z0-9-]/', '', aprs_station(aprs_load_settings()))]]
                            : aprs_net_find($data, $edit_id);
    if ($form && !empty($form['builtin'])) $form = null;
}

$devices = aprs_net_devices();
$queued = false;
foreach (@scandir(APRS_QUEUE_DIR) ?: [] as $f) {
    if (preg_match('/-network(-confirm)?$/', $f)) $queued = true;
}

aprs_admin_header('🌐 ' . t('Réseau'), 'network', ['refresh' => $queued ? 4 : 0]);
if ($flash) echo '<div class="flash">' . h(t($flash)) . '</div>';
?>
    <?php if ($pending): ?>
        <div class="warn net-pending">
            <div>
                <strong>⏳ <?php echo th('Nouvelle configuration réseau active : « %s »', aprs_net_label($data, $pending['new'])); ?></strong><br>
                <?php echo th('Si vous voyez cette page, la connexion fonctionne : gardez-la. Sans confirmation, le Pi reviendra au profil « %s » dans', aprs_net_label($data, $pending['previous'])); ?>
                <strong id="net-countdown" data-deadline="<?php echo intval($pending['deadline']); ?>">…</strong>.
            </div>
            <form method="POST"><?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="confirm">
                <button class="btn btn-ok">✅ <?php echo th('Garder cette configuration'); ?></button></form>
        </div>
    <?php endif; ?>
    <?php if ($queued): ?>
        <div class="flash">⏳ <?php echo th('Changement de réseau en cours… Si la page ne revient pas, reconnectez-vous au nouveau réseau puis ouvrez'); ?>
            <strong>http://<?php echo h(gethostname()); ?>.local/admin</strong> <?php echo sprintf(h(t('(ou %s pour une borne Wi-Fi).')), '<strong>http://10.42.0.1/admin</strong>'); ?></div>
    <?php endif; ?>

    <!-- État actuel -->
    <div class="card">
        <h2>📶 <?php echo th('État actuel'); ?></h2>
        <?php if (!$devices): ?>
            <p class="muted"><?php echo th('Impossible de lire l\'état réseau (NetworkManager absent ?).'); ?></p>
        <?php else: ?>
            <table class="simple">
                <tr><th><?php echo th('Carte'); ?></th><th><?php echo th('Type'); ?></th><th><?php echo th('État'); ?></th><th><?php echo th('Connexion réseau'); ?></th><th><?php echo th('Adresse IP'); ?></th></tr>
                <?php foreach ($devices as $d): ?>
                    <tr>
                        <td><strong><?php echo h($d['device']); ?></strong></td>
                        <td><?php echo h($d['type'] === 'ethernet' ? '🔌 Ethernet' : ($d['type'] === 'wifi' ? '📶 Wi-Fi' : $d['type'])); ?></td>
                        <td><?php echo aprs_svc_pill($d['state'] === 'connected' ? 'active' : $d['state']); ?></td>
                        <td><?php echo h($d['connection'] === '--' ? '–' : $d['connection']); ?></td>
                        <td><?php echo h($d['ip'] ?: '–'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <p class="muted" style="margin-top: 10px;"><?php echo th('Profil actif :'); ?> <strong><?php echo h(aprs_net_label($data, $active)); ?></strong>
            <?php if (!empty($state['applied'])): ?><?php echo th('(depuis le %s)', $state['applied']); ?><?php endif; ?>
            · <?php echo th('Nom du Pi sur le réseau :'); ?> <strong><?php echo h(gethostname()); ?>.local</strong></p>
    </div>

    <!-- Profils -->
    <div class="card">
        <h2>🗂️ <?php echo th('Profils enregistrés'); ?></h2>
        <div class="net-profiles">
            <?php foreach ($data['profiles'] as $p): $is_active = $p['id'] === $active; ?>
                <div class="net-profile <?php echo $is_active ? 'active' : ''; ?>">
                    <div class="net-head">
                        <strong><?php echo h(aprs_net_name($p)); ?></strong>
                        <?php if ($is_active): ?><span class="pill ok"><?php echo th('actif'); ?></span><?php endif; ?>
                        <?php if ($data['fallback'] === $p['id']): ?><span class="pill mid"><?php echo th('secours'); ?></span><?php endif; ?>
                    </div>
                    <div class="muted"><?php echo h(aprs_net_summary($p)); ?></div>
                    <div class="actions" style="margin-top: 10px;">
                        <form method="POST" onsubmit="return netConfirm(this, <?php echo h(json_encode(aprs_net_name($p))); ?>, <?php echo $p['wifi']['mode'] === 'ap' ? 'true' : 'false'; ?>);">
                            <?php echo aprs_csrf_field(); ?>
                            <input type="hidden" name="op" value="activate"><input type="hidden" name="id" value="<?php echo h($p['id']); ?>">
                            <input type="hidden" name="no_confirm" value="">
                            <button class="btn btn-small"><?php echo $is_active ? '🔄 ' . th('Réappliquer') : '▶ ' . th('Activer'); ?></button>
                        </form>
                        <?php if (empty($p['builtin'])): ?>
                            <a class="btn btn-small btn-secondary" href="network.php?edit=<?php echo h(urlencode($p['id'])); ?>#form">✏️ <?php echo th('Modifier'); ?></a>
                            <?php if (!$is_active): ?>
                                <form method="POST" onsubmit="return confirm(<?php echo h(json_encode(t('Supprimer ce profil ?'))); ?>);">
                                    <?php echo aprs_csrf_field(); ?>
                                    <input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?php echo h($p['id']); ?>">
                                    <button class="btn btn-small btn-danger">🗑️</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p style="margin-top: 15px;"><a class="btn" href="network.php?new=1#form">➕ <?php echo th('Nouveau profil'); ?></a></p>
        <p class="muted" style="margin-top: 10px;">
            <?php echo th('« Origine » remet le réseau comme à l\'installation : Ethernet en DHCP et le Wi-Fi entré dans Raspberry Pi Imager. Il ne peut être ni modifié ni supprimé.'); ?>
        </p>
    </div>

    <?php if ($form !== null): $w = $form['wifi']; $e = $form['eth']; ?>
    <!-- Formulaire -->
    <form method="POST" class="card" id="form">
        <?php echo aprs_csrf_field(); ?>
        <input type="hidden" name="op" value="save">
        <input type="hidden" name="id" value="<?php echo h($form['id']); ?>">
        <h2><?php echo $form['id'] === '' ? '➕ ' . th('Nouveau profil') : '✏️ ' . th('Modifier « %s »', $form['name']); ?></h2>
        <?php if ($errors): ?><div class="error-box"><?php echo th('Corrigez les champs en rouge.'); ?></div><?php endif; ?>
        <?php
        $fld = function ($name, $label, $value, $type = 'text', $help = '', $ph = '') use ($errors) {
            echo '<div class="field' . (isset($errors[$name]) ? ' has-error' : '') . '"><label for="' . $name . '">' . h($label) . '</label>'
               . '<input type="' . $type . '" id="' . $name . '" name="' . $name . '" value="' . h($value) . '" placeholder="' . h($ph) . '"'
               . ($type === 'password' ? ' autocomplete="new-password"' : '') . '>'
               . (isset($errors[$name]) ? '<div class="err">⚠️ ' . h($errors[$name]) . '</div>' : '')
               . ($help ? '<div class="help">' . h($help) . '</div>' : '') . '</div>';
        };
        $sel = function ($name, $label, $value, array $opts) {
            echo '<div class="field"><label for="' . $name . '">' . h($label) . '</label><select id="' . $name . '" name="' . $name . '">';
            foreach ($opts as $v => $l) echo '<option value="' . h($v) . '"' . ((string)$value === (string)$v ? ' selected' : '') . '>' . h($l) . '</option>';
            echo '</select></div>';
        };
        $keep = ' ' . t('(vide = garder le mot de passe actuel)');
        ?>
        <div class="fields"><?php $fld('name', t('Nom du profil'), $form['name'], 'text', '', t('Ex. : Club radio')); ?></div>

        <h3 class="net-h3">🔌 <?php echo th('Ethernet (carte intégrée ou adaptateur USB)'); ?></h3>
        <div class="fields">
            <?php $sel('eth_mode', t('Adressage'), $e['mode'], ['dhcp' => t('Automatique (DHCP)'), 'static' => t('Adresse fixe'), 'off' => t('Désactivé')]); ?>
        </div>
        <div class="fields net-sub" data-show="eth_mode=static">
            <?php $fld('eth_address', t('Adresse IP / masque'), $e['address'] ?? '', 'text', t('Format CIDR : /24 = 255.255.255.0'), '192.168.1.50/24');
                  $fld('eth_gateway', t('Passerelle (routeur)'), $e['gateway'] ?? '', 'text', '', '192.168.1.1');
                  $fld('eth_dns', t('Serveurs DNS'), $e['dns'] ?? '', 'text', t('Séparés par des espaces'), '1.1.1.1 8.8.8.8'); ?>
        </div>

        <h3 class="net-h3">📶 Wi-Fi</h3>
        <div class="fields">
            <?php $sel('wifi_mode', t('Mode'), $w['mode'], ['existing' => t('Réseau configuré à l\'installation'), 'client' => t('Se connecter à un autre réseau'),
                                                          'ap' => t('Borne Wi-Fi (le Pi crée son propre réseau)'), 'off' => t('Désactivé')]); ?>
            <?php if (isset($errors['wifi_mode'])): ?><div class="err">⚠️ <?php echo h($errors['wifi_mode']); ?></div><?php endif; ?>
        </div>
        <div class="fields net-sub" data-show="wifi_mode=client">
            <?php $fld('wifi_ssid', t('Nom du réseau (SSID)'), $w['mode'] === 'client' ? ($w['ssid'] ?? '') : '');
                  $fld('wifi_password', t('Mot de passe'), '', 'password', t('8 à 63 caractères ; vide = réseau ouvert') . (($form['id'] !== '' && $w['mode'] === 'client') ? $keep : ''));
                  $sel('wifi_ip', t('Adressage'), $w['ip'] ?? 'dhcp', ['dhcp' => t('Automatique (DHCP)'), 'static' => t('Adresse fixe')]); ?>
        </div>
        <div class="fields net-sub" data-show="wifi_mode=client&wifi_ip=static">
            <?php $fld('wifi_address', t('Adresse IP / masque'), $w['address'] ?? '', 'text', '', '192.168.1.51/24');
                  $fld('wifi_gateway', t('Passerelle'), $w['gateway'] ?? '', 'text', '', '192.168.1.1');
                  $fld('wifi_dns', t('Serveurs DNS'), $w['dns'] ?? '', 'text', '', '1.1.1.1'); ?>
        </div>
        <div class="fields net-sub" data-show="wifi_mode=ap">
            <?php $fld('ap_ssid', t('Nom de la borne (SSID)'), $w['mode'] === 'ap' ? ($w['ssid'] ?? '') : '', 'text', t('Le nom que verront les téléphones et portables.'));
                  $fld('ap_password', t('Mot de passe de la borne'), '', 'password', t('8 à 63 caractères (obligatoire)') . (($form['id'] !== '' && $w['mode'] === 'ap') ? $keep : ''));
                  $sel('ap_channel', t('Canal'), $w['channel'] ?? 6, [1 => t('Canal %d', 1), 6 => t('Canal %d', 6), 11 => t('Canal %d', 11)]); ?>
            <div class="help" style="grid-column: 1 / -1;">
                <?php echo sprintf(h(t('Connectez-vous à la borne puis ouvrez %s. Si un câble Ethernet relié à Internet est branché, les appareils connectés à la borne ont aussi Internet. Sans câble, l\'iGate reçoit et affiche les paquets localement (carte en mode Offline), mais ne peut pas les envoyer à aprs.fi.')), '<strong>http://10.42.0.1</strong>'); ?>
            </div>
        </div>
        <p style="margin-top: 20px;">
            <button class="btn">💾 <?php echo th('Enregistrer le profil'); ?></button>
            <a class="btn btn-secondary" href="network.php"><?php echo th('Annuler'); ?></a>
        </p>
        <p class="muted" style="margin-top: 8px;"><?php echo th('Enregistrer ne change rien tout de suite : cliquez ensuite sur « Activer ».'); ?></p>
    </form>
    <?php endif; ?>

    <!-- Secours -->
    <form method="POST" class="card">
        <?php echo aprs_csrf_field(); ?>
        <input type="hidden" name="op" value="fallback">
        <h2>🛟 <?php echo th('Profil de secours'); ?></h2>
        <p style="margin-bottom: 12px;"><?php echo sprintf(h(t('Si le Pi n\'est connecté à %s réseau 3 minutes après le démarrage (par exemple : vous êtes au club et le Wi-Fi de la maison n\'est pas là), il active ce profil. Choisissez une %s pour toujours pouvoir le rejoindre.')),
                '<strong>' . th('aucun') . '</strong>', '<strong>' . th('borne Wi-Fi') . '</strong>'); ?></p>
        <div class="fields">
            <div class="field">
                <select name="fallback">
                    <option value=""><?php echo th('Aucun'); ?></option>
                    <?php foreach ($data['profiles'] as $p): ?>
                        <option value="<?php echo h($p['id']); ?>" <?php echo $data['fallback'] === $p['id'] ? 'selected' : ''; ?>><?php echo h(aprs_net_name($p)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button class="btn btn-secondary"><?php echo th('Enregistrer'); ?></button></div>
        </div>
    </form>

<script>
function netConfirm(form, name, isAp) {
    var msg = t('Activer le profil « %s » ?', name) + '\n\n' + t('La connexion à cette page peut être coupée.');
    msg += '\n' + (isAp ? t('Connectez-vous ensuite à la borne Wi-Fi et ouvrez %s.', 'http://10.42.0.1/admin')
                        : t('Reconnectez-vous ensuite au même réseau que le Pi.'));
    msg += '\n\n' + t('Vous aurez 5 minutes pour confirmer depuis le nouveau réseau, sinon le Pi reviendra tout seul à la configuration actuelle.');
    return confirm(msg);
}
(function () {
    // Afficher seulement les champs utiles selon les modes choisis
    function refresh() {
        document.querySelectorAll('.net-sub').forEach(function (el) {
            var ok = el.getAttribute('data-show').split('&').every(function (cond) {
                var kv = cond.split('='), f = document.getElementById(kv[0]);
                return f && f.value === kv[1];
            });
            el.style.display = ok ? '' : 'none';
        });
    }
    ['eth_mode', 'wifi_mode', 'wifi_ip'].forEach(function (id) {
        var f = document.getElementById(id);
        if (f) f.addEventListener('change', refresh);
    });
    refresh();

    var cd = document.getElementById('net-countdown');
    if (cd) {
        var deadline = parseInt(cd.getAttribute('data-deadline'), 10) * 1000;
        (function tick() {
            var s = Math.max(0, Math.round((deadline - Date.now()) / 1000));
            cd.textContent = Math.floor(s / 60) + ' min ' + (s % 60 < 10 ? '0' : '') + (s % 60) + ' s';
            if (s > 0) setTimeout(tick, 1000); else setTimeout(function () { location.reload(); }, 5000);
        })();
    }
})();
</script>
<?php aprs_admin_footer();
