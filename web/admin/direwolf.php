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
        $msg = $ok ? t('Gain %s appliqué.', $clean['gain']) : 'Gain invalide.';
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

aprs_admin_header(t('🐺 Direwolf'), 'direwolf', ['refresh' => $test_running ? 20 : 0]);
aprs_flash_box($flash, $errors);
?>
    <?php if ($test_running): ?>
        <div class="warn">⏳ <strong><?php echo th('Test des gains en cours.'); ?></strong> <?php echo th("L'iGate est arrêté pendant le test ; la page se rafraîchit toute seule."); ?></div>
    <?php endif; ?>

    <!-- État en direct -->
    <div class="card">
        <h2>📡 <?php echo th('État en direct'); ?> <span class="muted" id="dw-updated"></span></h2>
        <div class="status-grid">
            <div class="status-item"><span><?php echo th('Service iGate'); ?></span><span id="dw-service" class="pill unk">…</span></div>
            <div class="status-item"><span><?php echo th('Connexion APRS-IS'); ?></span><span id="dw-aprsis" class="pill unk">…</span></div>
            <div class="status-item"><span><?php echo th('Passcode'); ?></span><span id="dw-verified" class="pill unk">…</span></div>
            <div class="status-item"><span><?php echo th('En marche depuis'); ?></span><span id="dw-since" class="pill unk">…</span></div>
            <div class="status-item"><span><?php echo th('Redémarrages'); ?></span><span id="dw-restarts" class="pill unk">…</span></div>
            <div class="status-item"><span><?php echo th('Version Direwolf'); ?></span><span id="dw-version" class="pill unk">…</span></div>
            <div class="status-item"><span><?php echo th('Gain du tuner'); ?></span><span id="dw-gain" class="pill unk">…</span></div>
        </div>
        <p class="muted" id="dw-config" style="margin-top: 10px;"></p>
        <p class="bt-err" id="dw-journal-warn" style="display:none; margin-top: 10px; color:#c62828;">
            <?php echo th("Le journal de Direwolf n'est pas lisible par le serveur web. Relancez"); ?> <code>sudo ./install.sh --skip-upgrade</code>.
        </p>
    </div>

    <!-- Vu-mètre -->
    <div class="card">
        <h2>📶 <?php echo th('Signal en direct'); ?> <span class="muted" id="vu-state"></span></h2>
        <div class="vu-wrap">
            <svg id="vu" class="vu" viewBox="0 0 200 125" aria-label="<?php echo th('Indicateur de signal'); ?>">
                <g id="vu-scale"></g>
                <polygon id="vu-peak" points="100,14 96,6 104,6" fill="#c62828" class="vu-rot"></polygon>
                <line id="vu-needle" class="vu-rot" x1="100" y1="112" x2="100" y2="26" stroke="#212121" stroke-width="2.5" stroke-linecap="round"></line>
                <circle cx="100" cy="112" r="6" fill="#212121"></circle>
                <text x="100" y="98" text-anchor="middle" font-size="9" fill="#666">SIGNAL</text>
            </svg>
            <div class="vu-side">
                <div class="bigstat" id="vu-value">–</div>
                <div class="muted" id="vu-label"><?php echo th('en attente de mesures…'); ?></div>
                <div style="margin-top: 14px;"><strong><?php echo th('Niveau audio'); ?></strong> <span class="muted" id="vu-dbfs"></span></div>
                <div class="vu-bar"><i id="vu-dbfs-bar"></i></div>
                <div class="gauge-scale"><span>-60 dB</span><span>-30</span><span>0 dB</span></div>
                <div class="muted" id="vu-clip" style="margin-top: 6px;"></div>
            </div>
        </div>
        <canvas id="vu-hist" class="vu-hist" title="<?php echo th('Signal, 2 dernières minutes'); ?>"></canvas>
        <div class="gauge-scale"><span>-2 min</span><span>-1 min</span><span><?php echo th('maintenant'); ?></span></div>
        <p class="help muted" style="margin-top: 8px;">
            <?php echo th("L'aiguille mesure la présence d'un signal sur la fréquence, comme un squelch : au repos (souffle seulement) elle reste près de 0 ; un paquet APRS la fait sauter pendant environ une seconde. Sans antenne, elle bouge très peu. Le niveau audio ne doit pas toucher 0 dB (saturation)."); ?>
        </p>
    </div>

    <!-- Dongle SDR -->
    <div class="card">
        <h2>📻 <?php echo th('Dongle SDR'); ?></h2>
        <div id="sdr-box" class="muted"><?php echo th('Recherche…'); ?></div>
        <div id="sdr-hints"></div>
    </div>

    <div class="fields" style="margin-bottom: 20px;">
        <div class="card" style="margin: 0;">
            <h2>🔊 <?php echo th('Niveau audio'); ?></h2>
            <div class="bigstat" id="dw-level">–</div>
            <div class="gauge"><div class="needle" id="dw-needle" style="left: 0%; display: none;"></div></div>
            <div class="gauge-scale"><span>0</span><span>25</span><span>50</span><span>75</span><span>100+</span></div>
            <p class="help" id="dw-level-info" style="margin-top: 10px;"></p>
            <p class="help muted" style="margin-top: 6px;">
                <?php echo t('Direwolf affiche le niveau de chaque paquet reçu. Visez <strong>50 à 70</strong> ; autour de 10-20 le signal est trop faible, au-dessus de 95 il sature. Le niveau dépend surtout du gain du RTL-SDR.'); ?>
            </p>
        </div>
        <div class="card" style="margin: 0;">
            <h2>📦 <?php echo th('Paquets décodés'); ?></h2>
            <div class="status-grid" style="grid-template-columns: 1fr 1fr;">
                <div><div class="bigstat" id="st-15m">–</div><div class="muted"><?php echo th('15 dernières minutes'); ?></div></div>
                <div><div class="bigstat" id="st-1h">–</div><div class="muted"><?php echo t('dernière heure (%s stations)', '<span id="st-st1h">–</span>'); ?></div></div>
                <div><div class="bigstat" id="st-24h">–</div><div class="muted"><?php echo t('24 heures (%s stations)', '<span id="st-st24h">–</span>'); ?></div></div>
                <div><div class="bigstat" id="st-last" style="font-size: 1.4em;">–</div><div class="muted"><?php echo th('dernier paquet'); ?></div></div>
            </div>
            <div class="hours" id="st-hours" title="<?php echo th('Paquets par heure, 24 dernières heures'); ?>"></div>
            <div class="gauge-scale"><span>-24 h</span><span>-12 h</span><span><?php echo th('maintenant'); ?></span></div>
        </div>
    </div>

    <!-- Journal -->
    <div class="card">
        <h2>📜 <?php echo th('Journal de Direwolf (en direct)'); ?></h2>
        <pre class="dwlog" id="dw-log"><?php echo th('Chargement…'); ?></pre>
        <p class="muted" style="margin-top: 6px;">
            <span style="color:#2e7d32">■</span> <?php echo th('paquet reçu'); ?> ·
            <span style="color:#1565c0">■</span> <?php echo th('niveau audio'); ?> ·
            <span style="color:#b28704">■</span> APRS-IS ·
            <span style="color:#c62828">■</span> <?php echo th('erreur'); ?>
            · <label><input type="checkbox" id="dw-follow" checked> <?php echo th('suivre la fin'); ?></label>
        </p>
    </div>

    <!-- Réglages radio et décodage -->
    <form method="POST" action="direwolf.php">
        <?php echo aprs_csrf_field(); ?>
        <input type="hidden" name="op" value="save">
        <?php aprs_render_sections($sections, $settings, $errors); ?>
        <div class="save-bar">
            <button type="submit" class="btn">💾 <?php echo th('Enregistrer et appliquer'); ?></button>
            <span class="muted"><?php echo th('Direwolf redémarre avec les nouveaux réglages (quelques secondes sans réception).'); ?></span>
        </div>
    </form>

    <!-- Test des gains -->
    <div class="card">
        <h2>🎚️ <?php echo th('Trouver le meilleur gain'); ?></h2>
        <p style="margin-bottom: 12px;">
            <?php echo th("Le test écoute 1 minute avec chaque gain (0, 20, 35, 40, 45 et 49) et compte les paquets décodés. L'iGate est arrêté pendant ce temps (environ 6 minutes), puis redémarre tout seul. Faites-le à une heure où il y a du trafic, et refaites-le au besoin : le trafic varie d'une minute à l'autre."); ?>
        </p>
        <?php if ($gain_results): ?>
            <p class="muted" style="margin-bottom: 8px;"><?php echo th('Dernier test :'); ?> <?php echo h($gain_header); ?></p>
            <table class="simple" style="margin-bottom: 15px;">
                <tr><th><?php echo th('Gain'); ?></th><th><?php echo th('Paquets décodés'); ?></th><th><?php echo th('Niveau audio moyen'); ?></th><th></th></tr>
                <?php foreach ($gain_results as $r): ?>
                    <tr class="<?php echo $best > 0 && $r['packets'] === $best ? 'best' : ''; ?>">
                        <td><?php echo h($r['gain']); ?></td>
                        <td><?php echo h($r['packets']); ?><?php echo $best > 0 && $r['packets'] === $best ? ' 🏆' : ''; ?></td>
                        <td><?php echo h($r['level']); ?></td>
                        <td>
                            <?php if ((string)$settings['gain'] === $r['gain'] || (float)$settings['gain'] === (float)$r['gain']): ?>
                                <span class="pill ok"><?php echo th('actuel'); ?></span>
                            <?php else: ?>
                            <form method="POST" action="direwolf.php" style="display:inline">
                                <?php echo aprs_csrf_field(); ?>
                                <input type="hidden" name="op" value="use-gain">
                                <input type="hidden" name="gain" value="<?php echo h($r['gain']); ?>">
                                <button class="btn btn-small"><?php echo th('Utiliser'); ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <div class="actions">
            <form method="POST" action="direwolf.php" onsubmit="return confirm(<?php echo h(json_encode(t("Arrêter l'iGate environ 6 minutes pour tester les gains ?"))); ?>);">
                <?php echo aprs_csrf_field(); ?>
                <input type="hidden" name="op" value="gain-test">
                <button class="btn" <?php echo $test_running ? 'disabled' : ''; ?>>🎚️ <?php echo th('Tester les gains'); ?></button>
            </form>
            <form method="POST" action="direwolf.php">
                <?php echo aprs_csrf_field(); ?>
                <input type="hidden" name="op" value="restart">
                <button class="btn btn-secondary">🔄 <?php echo th('Redémarrer Direwolf'); ?></button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>💡 <?php echo th('Conseils pour mieux capter'); ?></h2>
        <ul style="margin-left: 20px; line-height: 1.7;">
            <li><?php echo t("<strong>L'antenne compte plus que tout</strong> : dehors et le plus haut possible, accordée pour la fréquence APRS, avec un câble court ou de bonne qualité."); ?></li>
            <li><?php echo t('<strong>Gain</strong> : commencez avec le test ci-dessus. En ville, un gain trop fort fait saturer le dongle (beaucoup de signaux forts, peu de paquets).'); ?></li>
            <li><?php echo t('<strong>Correction ppm</strong> : un vieux dongle sans TCXO peut être décalé en fréquence ; essayez des valeurs entre -60 et +60 si vous ne décodez presque rien.'); ?></li>
            <li><?php echo t("<strong>Profil « + »</strong> et <strong>FIX_BITS</strong> : gardent quelques paquets de plus, mais surveillez le CPU dans l'onglet %s.", '<a class="back" href="system.php">📊 ' . th('Système') . '</a>'); ?></li>
            <li><?php echo t('<strong>Alimentation</strong> : une sous-tension (visible dans Système) fait perdre des paquets et redémarrer le dongle.'); ?></li>
            <li><?php echo t("<strong>Filtre FM</strong> : près d'une station FM ou d'un émetteur de téléavertisseurs (pagettes), un filtre passe-bande 144 MHz devant le dongle fait souvent une grosse différence."); ?></li>
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
        return Math.floor(sec / 86400) + ' ' + t('j') + ' ' + Math.floor(sec % 86400 / 3600) + ' h';
    }
    var LOC = window.APRS_LANG === 'en' ? 'en-CA' : 'fr-CA';
    function hhmmss(ts) { var d = new Date(ts * 1000); return d.toLocaleTimeString(LOC, {hour12: false}); }

    function renderSdr(d) {
        var devs = d.sdr || [], hints = [];
        if (!devs.length) {
            $('sdr-box').innerHTML = '<span class="pill bad">' + esc(t('Aucun dongle SDR détecté')) + '</span>';
            hints.push(esc(t('Vérifiez le câble micro-USB (un câble « charge seulement » ne transmet pas de données) et l’alimentation. Avec un hub USB alimenté par le Pi, activez l’option « hub USB » dans Configuration → Matériel.')));
        } else {
            $('sdr-box').innerHTML = devs.map(function (x, i) {
                var rows = [
                    [t('Modèle'), '<strong>' + esc(x.model) + '</strong> ' + (x.supported ? '<span class="pill ok">' + esc(t('compatible')) + '</span>' : '<span class="pill bad">' + esc(t('non supporté par ce kit')) + '</span>')],
                    [t('Chipset'), esc(x.chipset)],
                    [t('Tuner'), i === 0 && d.tuner ? esc(d.tuner) : '<span class="muted">' + esc(d.service === 'active' ? t('non annoncé dans le journal') : t('connu quand l’iGate tourne')) + '</span>'],
                    [t('Identifiant USB'), esc(x.id) + (x.speed ? ' · ' + esc(x.speed) + ' Mbit/s' : '') + ' · port ' + esc(x.port)],
                    [t('Fabricant / produit'), esc(x.manufacturer || '?') + ' / ' + esc(x.product || '?')],
                    [t('N° de série'), esc(x.serial || '–')]
                ];
                if (x.driver) rows.push([t('Pilote du noyau'), esc(x.driver)]);
                return '<table class="simple" style="margin-bottom:10px">' + rows.map(function (r) {
                    return '<tr><th style="width:190px">' + esc(r[0]) + '</th><td>' + r[1] + '</td></tr>';
                }).join('') + '</table>';
            }).join('');
            devs.forEach(function (x) {
                if (!x.supported) hints.push(esc(t('%s n’est pas un RTL-SDR : ce kit utilise rtl_fm, qui ne fonctionne qu’avec les clés à puce RTL2832U.', x.model)));
                if (x.driver === 'dvb_usb_rtl28xxu') hints.push(esc(t('Le pilote TV (DVB) du noyau tient le dongle : redémarrez le Pi pour que la liste noire installée par le kit s’applique.')));
                if (x.speed && parseFloat(x.speed) < 480) hints.push(esc(t('Le dongle est connecté en USB lent (%s Mbit/s) : essayez un autre câble ou hub.', x.speed)));
            });
            var v4 = devs.some(function (x) { return /V4/.test(x.model); }) || /R828D/.test(d.tuner || '');
            if (v4 && !d.blog_v4_driver && d.service === 'active') {
                hints.push(esc(t('RTL-SDR Blog V4 détecté, mais le pilote rtl-sdr installé ne semble pas le reconnaître (pas de message « RTL-SDR Blog V4 Detected »). La réception peut être mauvaise ou nulle : il faut installer le pilote rtl-sdr-blog (voir le README, section Matériel).')));
            }
            if (devs.filter(function (x) { return x.supported; }).length > 1) {
                hints.push(esc(t('Plusieurs RTL-SDR sont branchés : rtl_fm utilise le premier (index 0).')));
            }
        }
        if (d.sdr_error) hints.unshift(esc(d.sdr_error));
        $('sdr-hints').innerHTML = hints.map(function (h) { return '<div class="warn" style="margin: 10px 0 0">⚠️ ' + h + '</div>'; }).join('');
    }

    function render(d) {
        $('dw-updated').textContent = '· ' + t('mis à jour %s', hhmmss(d.now));
        pill($('dw-service'), d.service, d.service === 'active' ? 'ok' : 'bad');
        renderSdr(d);
        var is = d.aprsis || {};
        if (is.state === 'connected') pill($('dw-aprsis'), t('connecté'), 'ok');
        else if (is.state === 'disconnected') pill($('dw-aprsis'), t('déconnecté'), 'bad');
        else pill($('dw-aprsis'), t('inconnu'), 'unk');
        $('dw-aprsis').title = is.line || '';
        if (is.verified === true) pill($('dw-verified'), t('vérifié'), 'ok');
        else if (is.verified === false) pill($('dw-verified'), t('NON vérifié'), 'bad');
        else pill($('dw-verified'), '?', 'unk');
        pill($('dw-since'), d.since ? ago(d.now - d.since) : '–', d.since ? 'ok' : 'unk');
        var nr = parseInt((d.unit || {}).NRestarts || '0', 10);
        pill($('dw-restarts'), String(nr), nr > 3 ? 'mid' : 'unk');
        pill($('dw-version'), d.version || '?', 'unk');
        pill($('dw-gain'), d.tuner_gain === null ? '?' : (d.tuner_gain === 'auto' ? 'auto' : d.tuner_gain + ' dB'), 'unk');
        var c = d.settings;
        $('dw-config').textContent = t('Réglages actifs : %s · gain %s · ppm %s · %s Hz · profil %s · FIX_BITS %s',
            c.freq.replace('M', ' MHz'), c.gain, c.ppm, c.rate, c.profile, c.fix_bits);
        $('dw-journal-warn').style.display = d.journal_ok || d.service !== 'active' ? 'none' : 'block';

        // Niveau audio : moyenne des 10 derniers paquets
        var lv = (d.levels || []).slice(-10);
        if (lv.length) {
            var avg = Math.round(lv.reduce(function (a, b) { return a + b.level; }, 0) / lv.length);
            var mn = Math.min.apply(null, lv.map(function (x) { return x.level; }));
            var mx = Math.max.apply(null, lv.map(function (x) { return x.level; }));
            var verdict = avg < 25 ? ' · ' + t('trop faible') : (avg > 90 ? ' · ' + t('saturation') : (avg >= 40 && avg <= 75 ? ' · ' + t('bon') : ''));
            $('dw-level').textContent = avg + verdict;
            $('dw-needle').style.display = 'block';
            $('dw-needle').style.left = Math.min(avg, 100) + '%';
            $('dw-level-info').textContent = t('Moyenne des %s derniers paquets (min %s, max %s, le dernier il y a %s).',
                lv.length, mn, mx, ago(Math.max(0, d.now - lv[lv.length - 1].t)));
        } else {
            $('dw-level').textContent = t('aucun paquet récent');
        }

        var st = d.stats;
        if (st) {
            $('st-15m').textContent = st.p15m; $('st-1h').textContent = st.p1h; $('st-24h').textContent = st.p24h;
            $('st-st1h').textContent = st.st1h; $('st-st24h').textContent = st.st24h;
            $('st-last').textContent = st.last ? t('il y a %s', ago(Math.max(0, d.now - st.last))) : t('jamais');
            var max = Math.max.apply(null, st.hours.concat([1]));
            $('st-hours').innerHTML = st.hours.map(function (n, i) {
                return '<div style="height:' + Math.max(1, Math.round(n / max * 100)) + '%" title="' + esc(t('%s paquets (il y a %s h)', n, 23 - i)) + '"></div>';
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
        pre.innerHTML = html || esc(d.journal_ok ? t('Journal vide.') : t('Journal non disponible.'));
        if ($('dw-follow').checked) pre.scrollTop = pre.scrollHeight;
    }

    function load() {
        if (document.hidden) return;
        fetch('api.php?what=direwolf', {credentials: 'same-origin'})
            .then(function (r) { if (r.status === 401) { location.reload(); throw 0; } return r.json(); })
            .then(render)
            .catch(function (e) { if (e) $('dw-updated').textContent = '· ' + t('erreur de lecture'); });
    }
    load();
    setInterval(load, 5000);
    document.addEventListener('visibilitychange', load);

    // ------------------------------------------------------------ vu-mètre
    var CX = 100, CY = 112, R = 88;
    function angle(v) { return -60 + 120 * Math.max(0, Math.min(100, v)) / 100; }
    function pt(v, r) {
        var a = angle(v) * Math.PI / 180;
        return [CX + r * Math.sin(a), CY - r * Math.cos(a)];
    }
    (function drawScale() {
        var svg = '', zones = [[0, 20, '#90a4ae'], [20, 55, '#ffb300'], [55, 100, '#43a047']];
        zones.forEach(function (z) {
            var a = pt(z[0], R), b = pt(z[1], R);
            svg += '<path d="M' + a[0].toFixed(2) + ' ' + a[1].toFixed(2) + ' A' + R + ' ' + R + ' 0 0 1 ' +
                   b[0].toFixed(2) + ' ' + b[1].toFixed(2) + '" stroke="' + z[2] + '" stroke-width="9" fill="none"/>';
        });
        for (var v = 0; v <= 100; v += 10) {
            var o = pt(v, R + 6), i = pt(v, v % 50 === 0 ? R - 10 : R - 6);
            svg += '<line x1="' + o[0].toFixed(2) + '" y1="' + o[1].toFixed(2) + '" x2="' + i[0].toFixed(2) + '" y2="' + i[1].toFixed(2) +
                   '" stroke="#444" stroke-width="' + (v % 50 === 0 ? 1.6 : 0.8) + '"/>';
            if (v % 25 === 0 || v === 0) {
                var tp = pt(v, R - 20);
                svg += '<text x="' + tp[0].toFixed(2) + '" y="' + (tp[1] + 3).toFixed(2) + '" text-anchor="middle" font-size="8" fill="#555">' + v + '</text>';
            }
        }
        $('vu-scale').innerHTML = svg;
    })();

    var peak = 0, hist = [], HIST = 480;   // 2 min à 4 mesures/s
    function setNeedle(el, v) { el.style.transform = 'rotate(' + angle(v).toFixed(1) + 'deg)'; }
    function drawHist() {
        var c = $('vu-hist'), dpr = window.devicePixelRatio || 1, w = c.clientWidth, h = c.clientHeight;
        if (c.width !== Math.round(w * dpr)) { c.width = Math.round(w * dpr); c.height = Math.round(h * dpr); }
        var ctx = c.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);
        var col = w / HIST, off = HIST - hist.length;
        hist.forEach(function (v, i) {
            ctx.fillStyle = v >= 55 ? '#43a047' : (v >= 20 ? '#ffb300' : '#b0bec5');
            var bh = Math.max(1, v / 100 * h);
            ctx.fillRect((off + i) * col, h - bh, Math.max(1, col), bh);
        });
    }
    var lastT = 0;
    function renderLevel(d) {
        if (!d || d.age === null || d.age > 3) {
            $('vu-state').textContent = '· ' + t('aucune mesure (iGate arrêté ?)');
            $('vu-value').textContent = '–';
            $('vu-label').textContent = t('Le vu-mètre fonctionne quand le service iGate tourne.');
            setNeedle($('vu-needle'), 0);
            return;
        }
        $('vu-state').textContent = '';
        var s = d.signal;
        setNeedle($('vu-needle'), s);
        peak = Math.max(s, peak - 2);           // aiguille de crête qui redescend doucement
        setNeedle($('vu-peak'), peak);
        $('vu-value').textContent = Math.round(s) + ' %';
        $('vu-label').textContent = s >= 55 ? '📡 ' + t('signal présent') : (s >= 20 ? t('signal faible ou bruit') : t('souffle seulement (fréquence libre)'));
        var db = Math.max(-60, Math.min(0, d.dbfs));
        $('vu-dbfs').textContent = d.dbfs.toFixed(1) + ' dBFS · ' + t('crête') + ' ' + Math.round(d.peak) + ' %';
        $('vu-dbfs-bar').style.width = ((db + 60) / 60 * 100).toFixed(1) + '%';
        $('vu-dbfs-bar').style.background = d.clip ? '#c62828' : (db > -6 ? '#ffb300' : '#667eea');
        $('vu-clip').innerHTML = d.clip ? '<span class="pill bad">' + esc(t('saturation : baissez le gain')) + '</span>' : '';
        if (d.t !== lastT) { hist.push(s); if (hist.length > HIST) hist.shift(); lastT = d.t; drawHist(); }
    }
    var vuBusy = false;
    function loadLevel() {
        if (vuBusy || document.hidden) return;
        vuBusy = true;
        fetch('api.php?what=level', {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(renderLevel)
            .catch(function () {})
            .then(function () { vuBusy = false; });
    }
    loadLevel();
    setInterval(loadLevel, 400);
    window.addEventListener('resize', drawHist);
})();
</script>
<?php aprs_admin_footer();
