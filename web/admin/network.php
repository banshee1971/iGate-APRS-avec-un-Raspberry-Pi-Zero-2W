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
            ? "Activation du profil « {$p['name']} » demandée."
            : 'Impossible de déposer la demande.');
    } elseif ($op === 'confirm' && $pending) {
        @file_put_contents(APRS_NET_CONFIRM, $pending['token']);
        aprs_queue_action('network-confirm');
        net_redirect('Configuration réseau confirmée.');
    } elseif ($op === 'delete' && $p && empty($p['builtin']) && $id !== $active) {
        $data['profiles'] = array_values(array_filter($data['profiles'], function ($x) use ($id) { return $x['id'] !== $id; }));
        if ($data['fallback'] === $id) $data['fallback'] = '';
        net_redirect(aprs_net_save($data) ? "Profil « {$p['name']} » supprimé." : 'Écriture impossible.');
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
            $msg = "Profil « {$form['name']} » enregistré.";
            if ($id === $active) $msg .= ' Cliquez sur « Activer » pour appliquer les changements.';
            net_redirect(aprs_net_save($data) ? $msg : 'Écriture impossible (' . APRS_NET_PROFILES . ').');
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

aprs_admin_header('🌐 Réseau', 'network', ['refresh' => $queued ? 4 : 0]);
if ($flash) echo '<div class="flash">' . h($flash) . '</div>';
?>
    <?php if ($pending): ?>
        <div class="warn net-pending">
            <div>
                <strong>⏳ Nouvelle configuration réseau active : « <?php echo h(aprs_net_find($data, $pending['new'])['name'] ?? $pending['new']); ?> »</strong><br>
                Si vous voyez cette page, la connexion fonctionne : gardez-la. Sans confirmation, le Pi reviendra
                au profil « <?php echo h(aprs_net_find($data, $pending['previous'])['name'] ?? $pending['previous']); ?> »
                dans <strong id="net-countdown" data-deadline="<?php echo intval($pending['deadline']); ?>">…</strong>.
            </div>
            <form method="POST"><?php echo aprs_csrf_field(); ?><input type="hidden" name="op" value="confirm">
                <button class="btn btn-ok">✅ Garder cette configuration</button></form>
        </div>
    <?php endif; ?>
    <?php if ($queued): ?>
        <div class="flash">⏳ Changement de réseau en cours… Si la page ne revient pas, reconnectez-vous au nouveau réseau
            puis ouvrez <strong>http://<?php echo h(gethostname()); ?>.local/admin</strong> (ou <strong>http://10.42.0.1/admin</strong> pour une borne Wi-Fi).</div>
    <?php endif; ?>

    <!-- État actuel -->
    <div class="card">
        <h2>📶 État actuel</h2>
        <?php if (!$devices): ?>
            <p class="muted">Impossible de lire l'état réseau (NetworkManager absent ?).</p>
        <?php else: ?>
            <table class="simple">
                <tr><th>Carte</th><th>Type</th><th>État</th><th>Connexion</th><th>Adresse IP</th></tr>
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
        <p class="muted" style="margin-top: 10px;">Profil actif : <strong><?php echo h(aprs_net_find($data, $active)['name'] ?? $active); ?></strong>
            <?php if (!empty($state['applied'])): ?>(depuis le <?php echo h($state['applied']); ?>)<?php endif; ?>
            · Nom du Pi sur le réseau : <strong><?php echo h(gethostname()); ?>.local</strong></p>
    </div>

    <!-- Profils -->
    <div class="card">
        <h2>🗂️ Profils enregistrés</h2>
        <div class="net-profiles">
            <?php foreach ($data['profiles'] as $p): $is_active = $p['id'] === $active; ?>
                <div class="net-profile <?php echo $is_active ? 'active' : ''; ?>">
                    <div class="net-head">
                        <strong><?php echo h($p['name']); ?></strong>
                        <?php if ($is_active): ?><span class="pill ok">actif</span><?php endif; ?>
                        <?php if ($data['fallback'] === $p['id']): ?><span class="pill mid">secours</span><?php endif; ?>
                    </div>
                    <div class="muted"><?php echo h(aprs_net_summary($p)); ?></div>
                    <div class="actions" style="margin-top: 10px;">
                        <form method="POST" onsubmit="return netConfirm(this, <?php echo h(json_encode($p['name'])); ?>, <?php echo $p['wifi']['mode'] === 'ap' ? 'true' : 'false'; ?>);">
                            <?php echo aprs_csrf_field(); ?>
                            <input type="hidden" name="op" value="activate"><input type="hidden" name="id" value="<?php echo h($p['id']); ?>">
                            <input type="hidden" name="no_confirm" value="">
                            <button class="btn btn-small"><?php echo $is_active ? '🔄 Réappliquer' : '▶ Activer'; ?></button>
                        </form>
                        <?php if (empty($p['builtin'])): ?>
                            <a class="btn btn-small btn-secondary" href="network.php?edit=<?php echo h(urlencode($p['id'])); ?>#form">✏️ Modifier</a>
                            <?php if (!$is_active): ?>
                                <form method="POST" onsubmit="return confirm('Supprimer ce profil ?');">
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
        <p style="margin-top: 15px;"><a class="btn" href="network.php?new=1#form">➕ Nouveau profil</a></p>
        <p class="muted" style="margin-top: 10px;">
            « Origine » remet le réseau comme à l'installation : Ethernet en DHCP et le Wi-Fi entré dans Raspberry Pi Imager.
            Il ne peut être ni modifié ni supprimé.
        </p>
    </div>

    <?php if ($form !== null): $w = $form['wifi']; $e = $form['eth']; ?>
    <!-- Formulaire -->
    <form method="POST" class="card" id="form">
        <?php echo aprs_csrf_field(); ?>
        <input type="hidden" name="op" value="save">
        <input type="hidden" name="id" value="<?php echo h($form['id']); ?>">
        <h2><?php echo $form['id'] === '' ? '➕ Nouveau profil' : '✏️ Modifier « ' . h($form['name']) . ' »'; ?></h2>
        <?php if ($errors): ?><div class="error-box">Corrigez les champs en rouge.</div><?php endif; ?>
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
        $keep = ' (vide = garder le mot de passe actuel)';
        ?>
        <div class="fields"><?php $fld('name', 'Nom du profil', $form['name'], 'text', '', 'Ex. : Club radio'); ?></div>

        <h3 class="net-h3">🔌 Ethernet (carte intégrée ou adaptateur USB)</h3>
        <div class="fields">
            <?php $sel('eth_mode', 'Adressage', $e['mode'], ['dhcp' => 'Automatique (DHCP)', 'static' => 'Adresse fixe', 'off' => 'Désactivé']); ?>
        </div>
        <div class="fields net-sub" data-show="eth_mode=static">
            <?php $fld('eth_address', 'Adresse IP / masque', $e['address'] ?? '', 'text', 'Format CIDR : /24 = 255.255.255.0', '192.168.1.50/24');
                  $fld('eth_gateway', 'Passerelle (routeur)', $e['gateway'] ?? '', 'text', '', '192.168.1.1');
                  $fld('eth_dns', 'Serveurs DNS', $e['dns'] ?? '', 'text', 'Séparés par des espaces', '1.1.1.1 8.8.8.8'); ?>
        </div>

        <h3 class="net-h3">📶 Wi-Fi</h3>
        <div class="fields">
            <?php $sel('wifi_mode', 'Mode', $w['mode'], ['existing' => 'Réseau configuré à l\'installation', 'client' => 'Se connecter à un autre réseau',
                                                          'ap' => 'Borne Wi-Fi (le Pi crée son propre réseau)', 'off' => 'Désactivé']); ?>
            <?php if (isset($errors['wifi_mode'])): ?><div class="err">⚠️ <?php echo h($errors['wifi_mode']); ?></div><?php endif; ?>
        </div>
        <div class="fields net-sub" data-show="wifi_mode=client">
            <?php $fld('wifi_ssid', 'Nom du réseau (SSID)', $w['mode'] === 'client' ? ($w['ssid'] ?? '') : '');
                  $fld('wifi_password', 'Mot de passe', '', 'password', '8 à 63 caractères ; vide = réseau ouvert' . (($form['id'] !== '' && $w['mode'] === 'client') ? $keep : ''));
                  $sel('wifi_ip', 'Adressage', $w['ip'] ?? 'dhcp', ['dhcp' => 'Automatique (DHCP)', 'static' => 'Adresse fixe']); ?>
        </div>
        <div class="fields net-sub" data-show="wifi_mode=client&wifi_ip=static">
            <?php $fld('wifi_address', 'Adresse IP / masque', $w['address'] ?? '', 'text', '', '192.168.1.51/24');
                  $fld('wifi_gateway', 'Passerelle', $w['gateway'] ?? '', 'text', '', '192.168.1.1');
                  $fld('wifi_dns', 'Serveurs DNS', $w['dns'] ?? '', 'text', '', '1.1.1.1'); ?>
        </div>
        <div class="fields net-sub" data-show="wifi_mode=ap">
            <?php $fld('ap_ssid', 'Nom de la borne (SSID)', $w['mode'] === 'ap' ? ($w['ssid'] ?? '') : '', 'text', 'Le nom que verront les téléphones et portables.');
                  $fld('ap_password', 'Mot de passe de la borne', '', 'password', '8 à 63 caractères (obligatoire)' . (($form['id'] !== '' && $w['mode'] === 'ap') ? $keep : ''));
                  $sel('ap_channel', 'Canal', $w['channel'] ?? 6, [1 => 'Canal 1', 6 => 'Canal 6', 11 => 'Canal 11']); ?>
            <div class="help" style="grid-column: 1 / -1;">
                Connectez-vous à la borne puis ouvrez <strong>http://10.42.0.1</strong>. Si un câble Ethernet relié à Internet est branché,
                les appareils connectés à la borne ont aussi Internet. Sans câble, l'iGate reçoit et affiche les paquets localement
                (carte en mode Offline), mais ne peut pas les envoyer à aprs.fi.
            </div>
        </div>
        <p style="margin-top: 20px;">
            <button class="btn">💾 Enregistrer le profil</button>
            <a class="btn btn-secondary" href="network.php">Annuler</a>
        </p>
        <p class="muted" style="margin-top: 8px;">Enregistrer ne change rien tout de suite : cliquez ensuite sur « Activer ».</p>
    </form>
    <?php endif; ?>

    <!-- Secours -->
    <form method="POST" class="card">
        <?php echo aprs_csrf_field(); ?>
        <input type="hidden" name="op" value="fallback">
        <h2>🛟 Profil de secours</h2>
        <p style="margin-bottom: 12px;">Si le Pi n'est connecté à <strong>aucun</strong> réseau 3 minutes après le démarrage
            (par exemple : vous êtes au club et le Wi-Fi de la maison n'est pas là), il active ce profil.
            Choisissez une <strong>borne Wi-Fi</strong> pour toujours pouvoir le rejoindre.</p>
        <div class="fields">
            <div class="field">
                <select name="fallback">
                    <option value="">Aucun</option>
                    <?php foreach ($data['profiles'] as $p): ?>
                        <option value="<?php echo h($p['id']); ?>" <?php echo $data['fallback'] === $p['id'] ? 'selected' : ''; ?>><?php echo h($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button class="btn btn-secondary">Enregistrer</button></div>
        </div>
    </form>

<script>
function netConfirm(form, name, isAp) {
    var msg = 'Activer le profil « ' + name + ' » ?\n\nLa connexion à cette page peut être coupée.';
    msg += isAp ? '\nConnectez-vous ensuite à la borne Wi-Fi et ouvrez http://10.42.0.1/admin.'
                : '\nReconnectez-vous ensuite au même réseau que le Pi.';
    msg += '\n\nVous aurez 5 minutes pour confirmer depuis le nouveau réseau, sinon le Pi reviendra tout seul à la configuration actuelle.';
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
