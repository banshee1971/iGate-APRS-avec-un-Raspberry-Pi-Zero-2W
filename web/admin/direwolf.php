<?php
// /admin/direwolf.php — état de Direwolf en direct et réglages de réception
require __DIR__ . '/../lib/admin.php';
aprs_require_login();
aprs_csrf_check();

$sections = aprs_sections_for('direwolf');
$settings = aprs_load_settings();
$errors   = [];
$flash    = $_GET['msg'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';
    if ($op === 'save') {
        [$settings, $errors] = aprs_handle_save($sections, 'direwolf.php');
    } elseif ($op === 'use-gain') {
        // Bouton « Utiliser » d'un résultat du test de gain
        $raw = aprs_load_settings();
        $raw['gain'] = $_POST['gain'] ?? '';
        $raw['rtl_agc'] = false;
        [$clean, $err] = aprs_validate($raw);
        $ok = !$err && aprs_save_settings($clean) && aprs_queue_action('apply');
        $msg = $ok ? 'Gain ' . $clean['gain'] . ' appliqué.' : 'Gain invalide.';
        header('Location: direwolf.php?msg=' . urlencode($msg));
        exit;
    } elseif ($op === 'gain-test' || $op === 'restart') {
        $msg = $op === 'gain-test'
            ? "Test des gains lancé : environ 1 minute par gain. L'iGate est arrêté pendant le test."
            : 'Redémarrage des services demandé.';
        if (!aprs_queue_action($op)) $msg = 'Impossible de déposer la demande.';
        header('Location: direwolf.php?msg=' . urlencode($msg));
        exit;
    }
}

// Résultats du dernier test de gain
$gain_results = [];
$gain_header = '';
if (is_readable(APRS_GAIN_RESULTS)) {
    foreach (file(APRS_GAIN_RESULTS, FILE_IGNORE_NEW_LINES) as $line) {
        if ($line !== '' && $line[0] === '#') {
            $gain_header = ltrim($line, '# ');
        } elseif (preg_match('/gain=([\d.]+) paquets=(\d+) niveau_audio=(\S+)/', $line, $m)) {
            $gain_results[] = ['gain' => $m[1], 'packets' => intval($m[2]), 'level' => $m[3]];
        }
    }
}
$best = $gain_results ? max(array_column($gain_results, 'packets')) : 0;
$test_running = false;
foreach (@scandir(APRS_QUEUE_DIR) ?: [] as $f) {
    if (substr($f, -10) === '-gain-test') $test_running = true;
}
if (is_file('/run/aprs-igate-gain-test.lock')) $test_running = true;

aprs_admin_header('🐺 Direwolf', 'direwolf', ['refresh' => $test_running ? 20 : 0]);
aprs_flash_box($flash, $errors);
?>
    <?php if ($test_running): ?>
        <div class="warn">⏳ <strong>Test des gains en cours.</strong> L'iGate est arrêté pendant le test ; la page se rafraîchit toute seule.</div>
    <?php endif; ?>

    <!-- État en direct -->
    <div class="card">
        <h2>📡 État en direct <span class="muted" id="dw-updated"></span></h2>
        <div class="status-grid">
            <div class="status-item"><span>Service iGate</span><span id="dw-service" class="pill unk">…</span></div>
            <div class="status-item"><span>Connexion APRS-IS</span><span id="dw-aprsis" class="pill unk">…</span></div>
            <div class="status-item"><span>Passcode</span><span id="dw-verified" class="pill unk">…</span></div>
            <div class="status-item"><span>En marche depuis</span><span id="dw-since" class="pill unk">…</span></div>
            <div class="status-item"><span>Redémarrages</span><span id="dw-restarts" class="pill unk">…</span></div>
            <div class="status-item"><span>Version Direwolf</span><span id="dw-version" class="pill unk">…</span></div>
            <div class="status-item"><span>Gain du tuner</span><span id="dw-gain" class="pill unk">…</span></div>
        </div>
        <p class="muted" id="dw-config" style="margin-top: 10px;"></p>
        <p class="bt-err" id="dw-journal-warn" style="display:none; margin-top: 10px; color:#c62828;">
            Le journal de Direwolf n'est pas lisible par le serveur web. Relancez <code>sudo ./install.sh --skip-upgrade</code>.
        </p>
    </div>

    <!-- Dongle SDR -->
    <div class="card">
        <h2>📻 Dongle SDR</h2>
        <div id="sdr-box" class="muted">Recherche…</div>
        <div id="sdr-hints"></div>
    </div>

    <div class="fields" style="margin-bottom: 20px;">
        <div class="card" style="margin: 0;">
            <h2>🔊 Niveau audio</h2>
            <div class="bigstat" id="dw-level">–</div>
            <div class="gauge"><div class="needle" id="dw-needle" style="left: 0%; display: none;"></div></div>
            <div class="gauge-scale"><span>0</span><span>25</span><span>50</span><span>75</span><span>100+</span></div>
            <p class="help" id="dw-level-info" style="margin-top: 10px;"></p>
            <p class="help muted" style="margin-top: 6px;">
                Direwolf affiche le niveau de chaque paquet reçu. Visez <strong>50 à 70</strong> ;
                autour de 10-20 le signal est trop faible, au-dessus de 95 il sature.
                Le niveau dépend surtout du gain du RTL-SDR.
            </p>
        </div>
        <div class="card" style="margin: 0;">
            <h2>📦 Paquets décodés</h2>
            <div class="status-grid" style="grid-template-columns: 1fr 1fr;">
                <div><div class="bigstat" id="st-15m">–</div><div class="muted">15 dernières minutes</div></div>
                <div><div class="bigstat" id="st-1h">–</div><div class="muted">dernière heure (<span id="st-st1h">–</span> stations)</div></div>
                <div><div class="bigstat" id="st-24h">–</div><div class="muted">24 heures (<span id="st-st24h">–</span> stations)</div></div>
                <div><div class="bigstat" id="st-last" style="font-size: 1.4em;">–</div><div class="muted">dernier paquet</div></div>
            </div>
            <div class="hours" id="st-hours" title="Paquets par heure, 24 dernières heures"></div>
            <div class="gauge-scale"><span>-24 h</span><span>-12 h</span><span>maintenant</span></div>
        </div>
    </div>

    <!-- Journal -->
    <div class="card">
        <h2>📜 Journal de Direwolf (en direct)</h2>
        <pre class="dwlog" id="dw-log">Chargement…</pre>
        <p class="muted" style="margin-top: 6px;">
            <span style="color:#2e7d32">■</span> paquet reçu ·
            <span style="color:#1565c0">■</span> niveau audio ·
            <span style="color:#b28704">■</span> APRS-IS ·
            <span style="color:#c62828">■</span> erreur
            · <label><input type="checkbox" id="dw-follow" checked> suivre la fin</label>
        </p>
    </div>

    <!-- Réglages radio et décodage -->
    <form method="POST" action="direwolf.php">
        <?php echo aprs_csrf_field(); ?>
        <input type="hidden" name="op" value="save">
        <?php aprs_render_sections($sections, $settings, $errors); ?>
        <div class="save-bar">
            <button type="submit" class="btn">💾 Enregistrer et appliquer</button>
            <span class="muted">Direwolf redémarre avec les nouveaux réglages (quelques secondes sans réception).</span>
        </div>
    </form>

    <!-- Test des gains -->
    <div class="card">
        <h2>🎚️ Trouver le meilleur gain</h2>
        <p style="margin-bottom: 12px;">
            Le test écoute 1 minute avec chaque gain (0, 20, 35, 40, 45 et 49) et compte les paquets décodés.
            L'iGate est arrêté pendant ce temps (environ 6 minutes), puis redémarre tout seul.
            Faites-le à une heure où il y a du trafic, et refaites-le au besoin : le trafic varie d'une minute à l'autre.
        </p>
        <?php if ($gain_results): ?>
            <p class="muted" style="margin-bottom: 8px;">Dernier test : <?php echo h($gain_header); ?></p>
            <table class="simple" style="margin-bottom: 15px;">
                <tr><th>Gain</th><th>Paquets décodés</th><th>Niveau audio moyen</th><th></th></tr>
                <?php foreach ($gain_results as $r): ?>
                    <tr class="<?php echo $best > 0 && $r['packets'] === $best ? 'best' : ''; ?>">
                        <td><?php echo h($r['gain']); ?></td>
                        <td><?php echo h($r['packets']); ?><?php echo $best > 0 && $r['packets'] === $best ? ' 🏆' : ''; ?></td>
                        <td><?php echo h($r['level']); ?></td>
                        <td>
                            <?php if ((string)$settings['gain'] === $r['gain'] || (float)$settings['gain'] === (float)$r['gain']): ?>
                                <span class="pill ok">actuel</span>
                            <?php else: ?>
                            <form method="POST" action="direwolf.php" style="display:inline">
                                <?php echo aprs_csrf_field(); ?>
                                <input type="hidden" name="op" value="use-gain">
                                <input type="hidden" name="gain" value="<?php echo h($r['gain']); ?>">
                                <button class="btn btn-small">Utiliser</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <div class="actions">
            <form method="POST" action="direwolf.php" onsubmit="return confirm('Arrêter l\'iGate environ 6 minutes pour tester les gains ?');">
                <?php echo aprs_csrf_field(); ?>
                <input type="hidden" name="op" value="gain-test">
                <button class="btn" <?php echo $test_running ? 'disabled' : ''; ?>>🎚️ Tester les gains</button>
            </form>
            <form method="POST" action="direwolf.php">
                <?php echo aprs_csrf_field(); ?>
                <input type="hidden" name="op" value="restart">
                <button class="btn btn-secondary">🔄 Redémarrer Direwolf</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>💡 Conseils pour mieux capter</h2>
        <ul style="margin-left: 20px; line-height: 1.7;">
            <li><strong>L'antenne compte plus que tout</strong> : dehors et le plus haut possible, accordée pour la fréquence APRS, avec un câble court ou de bonne qualité.</li>
            <li><strong>Gain</strong> : commencez avec le test ci-dessus. En ville, un gain trop fort fait saturer le dongle (beaucoup de signaux forts, peu de paquets).</li>
            <li><strong>Correction ppm</strong> : un vieux dongle sans TCXO peut être décalé en fréquence ; essayez des valeurs entre -60 et +60 si vous ne décodez presque rien.</li>
            <li><strong>Profil « + »</strong> et <strong>FIX_BITS</strong> : gardent quelques paquets de plus, mais surveillez le CPU dans l'onglet <a class="back" href="system.php">📊 Système</a>.</li>
            <li><strong>Alimentation</strong> : une sous-tension (visible dans Système) fait perdre des paquets et redémarrer le dongle.</li>
            <li><strong>Filtre FM</strong> : près d'une station FM ou d'un émetteur de téléavertisseurs (pagettes), un filtre passe-bande 144 MHz devant le dongle fait souvent une grosse différence.</li>
        </ul>
    </div>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    function pill(el, text, cls) { el.textContent = text; el.className = 'pill ' + cls; }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function ago(sec) {
        if (sec < 60) return sec + ' s';
        if (sec < 3600) return Math.floor(sec / 60) + ' min';
        if (sec < 86400) return Math.floor(sec / 3600) + ' h ' + Math.floor(sec % 3600 / 60) + ' min';
        return Math.floor(sec / 86400) + ' j ' + Math.floor(sec % 86400 / 3600) + ' h';
    }
    function hhmmss(t) { var d = new Date(t * 1000); return d.toLocaleTimeString('fr-CA', {hour12: false}); }

    function renderSdr(d) {
        var devs = d.sdr || [], hints = [];
        if (!devs.length) {
            $('sdr-box').innerHTML = '<span class="pill bad">Aucun dongle SDR détecté</span>';
            hints.push('Vérifiez le câble micro-USB (un câble « charge seulement » ne transmet pas de données) et l’alimentation. ' +
                       'Avec un hub USB alimenté par le Pi, activez l’option « hub USB » dans Configuration → Matériel.');
        } else {
            $('sdr-box').innerHTML = devs.map(function (x, i) {
                var rows = [
                    ['Modèle', '<strong>' + esc(x.model) + '</strong> ' + (x.supported ? '<span class="pill ok">compatible</span>' : '<span class="pill bad">non supporté par ce kit</span>')],
                    ['Chipset', esc(x.chipset)],
                    ['Tuner', i === 0 && d.tuner ? esc(d.tuner) : '<span class="muted">' + (d.service === 'active' ? 'non annoncé dans le journal' : 'connu quand l’iGate tourne') + '</span>'],
                    ['Identifiant USB', esc(x.id) + (x.speed ? ' · ' + esc(x.speed) + ' Mbit/s' : '') + ' · port ' + esc(x.port)],
                    ['Fabricant / produit', esc(x.manufacturer || '?') + ' / ' + esc(x.product || '?')],
                    ['N° de série', esc(x.serial || '–')]
                ];
                if (x.driver) rows.push(['Pilote du noyau', esc(x.driver)]);
                return '<table class="simple" style="margin-bottom:10px">' + rows.map(function (r) {
                    return '<tr><th style="width:190px">' + r[0] + '</th><td>' + r[1] + '</td></tr>';
                }).join('') + '</table>';
            }).join('');
            devs.forEach(function (x) {
                if (!x.supported) hints.push(esc(x.model) + ' n’est pas un RTL-SDR : ce kit utilise rtl_fm, qui ne fonctionne qu’avec les clés à puce RTL2832U.');
                if (x.driver === 'dvb_usb_rtl28xxu') hints.push('Le pilote TV (DVB) du noyau tient le dongle : redémarrez le Pi pour que la liste noire installée par le kit s’applique.');
                if (x.speed && parseFloat(x.speed) < 480) hints.push('Le dongle est connecté en USB lent (' + esc(x.speed) + ' Mbit/s) : essayez un autre câble ou hub.');
            });
            var v4 = devs.some(function (x) { return /V4/.test(x.model); }) || /R828D/.test(d.tuner || '');
            if (v4 && !d.blog_v4_driver && d.service === 'active') {
                hints.push('RTL-SDR Blog V4 détecté, mais le pilote rtl-sdr installé ne semble pas le reconnaître ' +
                           '(pas de message « RTL-SDR Blog V4 Detected »). La réception peut être mauvaise ou nulle : ' +
                           'il faut installer le pilote rtl-sdr-blog (voir le README, section Matériel).');
            }
            if (devs.filter(function (x) { return x.supported; }).length > 1) {
                hints.push('Plusieurs RTL-SDR sont branchés : rtl_fm utilise le premier (index 0).');
            }
        }
        if (d.sdr_error) hints.unshift(esc(d.sdr_error));
        $('sdr-hints').innerHTML = hints.map(function (h) { return '<div class="warn" style="margin: 10px 0 0">⚠️ ' + h + '</div>'; }).join('');
    }

    function render(d) {
        $('dw-updated').textContent = '· mis à jour ' + hhmmss(d.now);
        pill($('dw-service'), d.service, d.service === 'active' ? 'ok' : 'bad');
        renderSdr(d);
        var is = d.aprsis || {};
        if (is.state === 'connected') pill($('dw-aprsis'), 'connecté', 'ok');
        else if (is.state === 'disconnected') pill($('dw-aprsis'), 'déconnecté', 'bad');
        else pill($('dw-aprsis'), 'inconnu', 'unk');
        $('dw-aprsis').title = is.line || '';
        if (is.verified === true) pill($('dw-verified'), 'vérifié', 'ok');
        else if (is.verified === false) pill($('dw-verified'), 'NON vérifié', 'bad');
        else pill($('dw-verified'), '?', 'unk');
        pill($('dw-since'), d.since ? ago(d.now - d.since) : '–', d.since ? 'ok' : 'unk');
        var nr = parseInt((d.unit || {}).NRestarts || '0', 10);
        pill($('dw-restarts'), String(nr), nr > 3 ? 'mid' : 'unk');
        pill($('dw-version'), d.version || '?', 'unk');
        pill($('dw-gain'), d.tuner_gain === null ? '?' : (d.tuner_gain === 'auto' ? 'auto' : d.tuner_gain + ' dB'), 'unk');
        var c = d.settings;
        $('dw-config').textContent = 'Réglages actifs : ' + c.freq.replace('M', ' MHz') + ' · gain ' + c.gain +
            ' · ppm ' + c.ppm + ' · ' + c.rate + ' Hz · profil ' + c.profile + ' · FIX_BITS ' + c.fix_bits;
        $('dw-journal-warn').style.display = d.journal_ok || d.service !== 'active' ? 'none' : 'block';

        // Niveau audio : moyenne des 10 derniers paquets
        var lv = (d.levels || []).slice(-10);
        if (lv.length) {
            var avg = Math.round(lv.reduce(function (a, b) { return a + b.level; }, 0) / lv.length);
            var mn = Math.min.apply(null, lv.map(function (x) { return x.level; }));
            var mx = Math.max.apply(null, lv.map(function (x) { return x.level; }));
            var verdict = avg < 25 ? ' · trop faible' : (avg > 90 ? ' · saturation' : (avg >= 40 && avg <= 75 ? ' · bon' : ''));
            $('dw-level').textContent = avg + verdict;
            $('dw-needle').style.display = 'block';
            $('dw-needle').style.left = Math.min(avg, 100) + '%';
            $('dw-level-info').textContent = 'Moyenne des ' + lv.length + ' derniers paquets (min ' + mn + ', max ' + mx +
                ', le dernier il y a ' + ago(Math.max(0, d.now - lv[lv.length - 1].t)) + ').';
        } else {
            $('dw-level').textContent = 'aucun paquet récent';
        }

        var st = d.stats;
        if (st) {
            $('st-15m').textContent = st.p15m; $('st-1h').textContent = st.p1h; $('st-24h').textContent = st.p24h;
            $('st-st1h').textContent = st.st1h; $('st-st24h').textContent = st.st24h;
            $('st-last').textContent = st.last ? 'il y a ' + ago(Math.max(0, d.now - st.last)) : 'jamais';
            var max = Math.max.apply(null, st.hours.concat([1]));
            $('st-hours').innerHTML = st.hours.map(function (n, i) {
                return '<div style="height:' + Math.max(1, Math.round(n / max * 100)) + '%" title="' + n + ' paquets (il y a ' + (23 - i) + ' h)"></div>';
            }).join('');
        }

        var pre = $('dw-log');
        var html = (d.log || []).map(function (l) {
            var m = esc(l.m), cls = '';
            if (/^\[\d/.test(l.m)) cls = 'pk';
            else if (/audio level/.test(l.m)) cls = 'au';
            else if (/^\[ig|igate|aprs-?is|logresp/i.test(l.m)) cls = 'ig';
            else if (/error|fail|unable|lost|usb_claim|no supported devices/i.test(l.m)) cls = 'er';
            return '<span class="ts">' + hhmmss(l.t) + '</span> ' + (cls ? '<span class="' + cls + '">' + m + '</span>' : m);
        }).join('\n');
        pre.innerHTML = html || (d.journal_ok ? 'Journal vide.' : 'Journal non disponible.');
        if ($('dw-follow').checked) pre.scrollTop = pre.scrollHeight;
    }

    function load() {
        if (document.hidden) return;
        fetch('api.php?what=direwolf', {credentials: 'same-origin'})
            .then(function (r) { if (r.status === 401) { location.reload(); throw 0; } return r.json(); })
            .then(render)
            .catch(function (e) { if (e) $('dw-updated').textContent = '· erreur de lecture'; });
    }
    load();
    setInterval(load, 5000);
    document.addEventListener('visibilitychange', load);
})();
</script>
<?php aprs_admin_footer();
