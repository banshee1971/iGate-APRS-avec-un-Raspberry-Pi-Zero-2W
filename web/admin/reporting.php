<?php
// /admin/reporting.php — envoi des paquets reçus vers APRS-IS (visibles sur aprs.fi)
require __DIR__ . '/../lib/admin.php';
aprs_require_login();
aprs_csrf_check();

$sections = aprs_sections_for('reporting');
$settings = aprs_load_settings();
$errors   = [];
$flash    = $_GET['msg'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';
    if ($op === 'save') {
        [$settings, $errors] = aprs_handle_save($sections, 'reporting.php');
    } elseif ($op === 'enable' || $op === 'disable') {
        $raw = aprs_load_settings();
        if ($op === 'enable' && !aprs_is_configured($raw)) {
            $msg = "Entrez d'abord votre indicatif d'appel (puis « Enregistrer »).";
        } else {
            $raw['aprsis_enabled'] = $op === 'enable';
            [$clean, $err] = aprs_validate($raw);
            $ok = !$err && aprs_save_settings($clean) && aprs_queue_action('apply');
            $msg = !$ok ? "Impossible d'enregistrer le réglage."
                 : ($op === 'enable' ? 'Reporting activé : connexion à APRS-IS en cours...'
                                     : 'Reporting désactivé : plus rien n\'est envoyé à APRS-IS.');
        }
        header('Location: reporting.php?msg=' . urlencode($msg));
        exit;
    }
}

$configured = aprs_is_configured($settings);
$enabled    = $settings['aprsis_enabled'];
$station    = aprs_station($settings);
$ssid_icon  = APRS_SSIDS[max(0, min(15, (int)$settings['ssid']))][0];

aprs_admin_header(t('📡 Reporting vers aprs.fi'), 'reporting');
aprs_flash_box($flash, $errors);
?>
    <!-- État du reporting -->
    <div class="card report-state <?php echo $enabled && $configured ? 'on' : 'off'; ?>">
        <div class="report-main">
            <div class="report-station">
                <span class="ssid-icon big"><?php echo $ssid_icon; ?></span>
                <div>
                    <div class="report-call"><?php echo h($configured ? $station : t('Indicatif à entrer')); ?></div>
                    <div class="muted">
                        <?php if (!$configured): ?>
                            <?php echo th('Entrez votre indicatif ci-dessous, puis enregistrez.'); ?>
                        <?php elseif ($enabled): ?>
                            <?php echo th('Les paquets reçus sont envoyés à APRS-IS (%s) et apparaissent sur aprs.fi.', $settings['igserver']); ?>
                        <?php else: ?>
                            <?php echo th("L'iGate reçoit et affiche les paquets localement, mais n'envoie rien à APRS-IS."); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="report-toggle">
                <span class="pill big-pill <?php echo $enabled && $configured ? 'ok' : 'bad'; ?>">
                    <?php echo $enabled && $configured ? '● ' . th('ACTIVÉ') : '○ ' . th('DÉSACTIVÉ'); ?>
                </span>
                <form method="POST" action="reporting.php">
                    <?php echo aprs_csrf_field(); ?>
                    <?php if ($enabled): ?>
                        <input type="hidden" name="op" value="disable">
                        <button class="btn btn-danger">⏸ <?php echo th('Disable'); ?></button>
                    <?php else: ?>
                        <input type="hidden" name="op" value="enable">
                        <button class="btn btn-ok" <?php echo $configured ? '' : 'disabled'; ?>>▶ <?php echo th('Enable'); ?></button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Dernier reporting -->
    <div class="card">
        <h2>✅ <?php echo th('Dernier reporting réussi'); ?> <span class="muted" id="rp-updated"></span></h2>
        <div class="status-grid">
            <div class="status-item"><span><?php echo th('Connexion APRS-IS'); ?></span><span id="rp-conn" class="pill unk">…</span></div>
            <div class="status-item"><span><?php echo th('Passcode accepté'); ?></span><span id="rp-verified" class="pill unk">…</span></div>
            <div class="status-item"><span><?php echo th('Dernier paquet relayé'); ?></span><span id="rp-last" class="pill unk">…</span></div>
            <div class="status-item"><span><?php echo th("Balise de l'iGate"); ?></span><span id="rp-beacon" class="pill unk">…</span></div>
        </div>
        <p class="muted" id="rp-detail" style="margin-top: 10px;"></p>

        <h3 style="margin: 18px 0 8px;"><?php echo th('Confirmation par aprs.fi'); ?></h3>
        <div id="rp-aprsfi" class="muted">
            <?php if ($settings['aprsfi_apikey'] === ''): ?>
                <?php echo th("Ajoutez votre clé API aprs.fi (plus bas) pour voir ce qu'aprs.fi a réellement reçu de votre station."); ?>
            <?php else: ?><?php echo th("Interrogation d'aprs.fi…"); ?><?php endif; ?>
        </div>
        <?php if ($configured): ?>
            <p style="margin-top: 12px;">
                <a class="back" href="https://aprs.fi/#!call=a%2F<?php echo urlencode($station); ?>" target="_blank" rel="noopener"><?php echo th('Voir %s sur aprs.fi', $station); ?> ↗</a>
                · <a class="back" href="https://aprs.fi/info/a/<?php echo urlencode($station); ?>" target="_blank" rel="noopener"><?php echo th('fiche de la station'); ?> ↗</a>
            </p>
        <?php endif; ?>
    </div>

    <!-- Réglages -->
    <form method="POST" action="reporting.php">
        <?php echo aprs_csrf_field(); ?>
        <input type="hidden" name="op" value="save">
        <?php aprs_render_sections($sections, $settings, $errors); ?>
        <div class="save-bar">
            <button type="submit" class="btn">💾 <?php echo th('Enregistrer et appliquer'); ?></button>
            <span class="muted"><?php echo th('Le passcode est recalculé et Direwolf se reconnecte avec les nouveaux identifiants.'); ?></span>
        </div>
    </form>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    function pill(el, text, cls) { el.textContent = text; el.className = 'pill ' + cls; }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function ago(sec) {
        return t('il y a %s', dur(sec));
    }
    function dur(sec) {
        sec = Math.max(0, Math.round(sec));
        if (sec < 60) return sec + ' s';
        if (sec < 3600) return Math.floor(sec / 60) + ' min';
        if (sec < 86400) return Math.floor(sec / 3600) + ' h ' + Math.floor(sec % 3600 / 60) + ' min';
        return Math.floor(sec / 86400) + ' ' + t('j');
    }
    var LOC = window.APRS_LANG === 'en' ? 'en-CA' : 'fr-CA';
    function when(ts) { return new Date(ts * 1000).toLocaleString(LOC, {hour12: false}); }

    // ---- Passcode calculé en direct (algorithme public d'APRS-IS)
    function passcode(call) {
        call = call.toUpperCase().split('-')[0];
        var h = 0x73e2;
        for (var i = 0; i < call.length; i += 2) {
            h ^= call.charCodeAt(i) << 8;
            if (i + 1 < call.length) h ^= call.charCodeAt(i + 1);
        }
        return h & 0x7fff;
    }
    var callIn = $('f_callsign'), pcOut = $('f_passcode');
    function updatePasscode() {
        if (!callIn || !pcOut) return;
        var c = callIn.value.trim().toUpperCase();
        pcOut.value = /^[A-Z0-9]{3,7}$/.test(c) && c !== 'N0CALL' ? passcode(c) : '';
    }
    if (callIn) { callIn.addEventListener('input', updatePasscode); updatePasscode(); }

    // ---- Icône du SSID
    var ssidSel = $('f_ssid');
    if (ssidSel) ssidSel.addEventListener('change', function () {
        var o = ssidSel.options[ssidSel.selectedIndex];
        $('ssid-icon').textContent = o.getAttribute('data-icon');
        $('ssid-desc').textContent = o.getAttribute('data-desc');
    });

    // ---- État du reporting (journal Direwolf + base de données)
    function render(d) {
        var c = d.settings, is = d.aprsis || {};
        $('rp-updated').textContent = '· ' + t('mis à jour %s', new Date(d.now * 1000).toLocaleTimeString(LOC, {hour12: false}));
        if (!c.aprsis_enabled) {
            ['rp-conn', 'rp-verified', 'rp-last', 'rp-beacon'].forEach(function (id) { pill($(id), t('désactivé'), 'unk'); });
            $('rp-detail').textContent = t('Le reporting est désactivé : cliquez sur Enable pour envoyer les paquets à APRS-IS.');
            return;
        }
        if (d.service !== 'active') pill($('rp-conn'), t('iGate arrêté'), 'bad');
        else if (is.state === 'connected') pill($('rp-conn'), t('connecté depuis %s', dur(d.now - is.time)), 'ok');
        else if (is.state === 'disconnected') pill($('rp-conn'), t('déconnecté'), 'bad');
        else pill($('rp-conn'), t('inconnu'), 'unk');

        if (is.verified === true) pill($('rp-verified'), t('oui (vérifié)'), 'ok');
        else if (is.verified === false) pill($('rp-verified'), t('NON : paquets refusés'), 'bad');
        else pill($('rp-verified'), '?', 'unk');

        // Direwolf relaie chaque paquet reçu tant qu'il est connecté :
        // le dernier paquet reçu après la connexion est le dernier relayé.
        var last = d.stats && d.stats.last;
        if (is.state === 'connected' && d.service === 'active' && last && last >= is.time - 5) {
            pill($('rp-last'), ago(d.now - last), d.now - last < 3600 ? 'ok' : 'mid');
            $('rp-last').title = when(last);
        } else if (last) {
            pill($('rp-last'), t('aucun depuis la connexion'), 'unk');
        } else {
            pill($('rp-last'), t('aucun paquet reçu'), 'unk');
        }
        pill($('rp-beacon'), c.beacon ? t('toutes les %s min', c.beacon_every) : t('désactivée'), c.beacon ? 'ok' : 'unk');

        var txt = t('Serveur %s.', c.igserver);
        if (is.line) txt += ' ' + t('Dernier message : « %s »', is.line);
        if (is.verified === false) txt += ' — ' + t('Le serveur refuse le passcode : vérifiez l\'indicatif.');
        $('rp-detail').textContent = txt;
    }
    function renderAprsfi(a) {
        var box = $('rp-aprsfi');
        if (!a.enabled) return;
        if (!a.ok) { box.innerHTML = '<span class="pill bad">' + esc(a.error || t('erreur')) + '</span>'; return; }
        if (!a.found) {
            box.innerHTML = '<span class="pill mid">' + esc(t('aprs.fi n\'a encore rien reçu de %s', a.station)) + '</span>' +
                ' <span class="muted">' + esc(t('(normal pendant les premières minutes ; la balise de l\'iGate doit être activée)')) + '</span>';
            return;
        }
        var e = a.entry, age = Math.floor(Date.now() / 1000) - e.lasttime;
        var viaUs = /qAC|TCPIP/.test(e.path);
        box.innerHTML = '<span class="pill ' + (age < 7200 ? 'ok' : 'mid') + '">' + esc(t('aprs.fi a reçu %s %s', a.station, ago(age))) + '</span>' +
            ' <span class="muted">' + esc(t('le %s', when(e.lasttime))) + (viaUs ? ' · ' + esc(t('via la connexion Internet de l\'iGate')) : '') +
            (e.path ? ' · ' + esc(t('chemin %s', e.path)) : '') + '</span>' +
            (e.comment ? '<div class="muted" style="margin-top:6px">' + esc(t('Commentaire : %s', e.comment)) + '</div>' : '');
    }

    function load() {
        if (document.hidden) return;
        fetch('api.php?what=direwolf', {credentials: 'same-origin'})
            .then(function (r) { if (r.status === 401) { location.reload(); throw 0; } return r.json(); })
            .then(render).catch(function () {});
    }
    function loadAprsfi() {
        if (document.hidden) return;
        fetch('api.php?what=aprsfi', {credentials: 'same-origin'})
            .then(function (r) { return r.json(); }).then(renderAprsfi).catch(function () {});
    }
    load(); loadAprsfi();
    setInterval(load, 10000);
    setInterval(loadAprsfi, 60000);
})();
</script>
<?php aprs_admin_footer();
