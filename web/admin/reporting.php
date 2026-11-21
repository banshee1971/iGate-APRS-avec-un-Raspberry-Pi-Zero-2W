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

aprs_admin_header('📡 Reporting vers aprs.fi', 'reporting');
aprs_flash_box($flash, $errors);
?>
    <!-- État du reporting -->
    <div class="card report-state <?php echo $enabled && $configured ? 'on' : 'off'; ?>">
        <div class="report-main">
            <div class="report-station">
                <span class="ssid-icon big"><?php echo $ssid_icon; ?></span>
                <div>
                    <div class="report-call"><?php echo h($configured ? $station : 'Indicatif à entrer'); ?></div>
                    <div class="muted">
                        <?php if (!$configured): ?>
                            Entrez votre indicatif ci-dessous, puis enregistrez.
                        <?php elseif ($enabled): ?>
                            Les paquets reçus sont envoyés à APRS-IS (<?php echo h($settings['igserver']); ?>) et apparaissent sur aprs.fi.
                        <?php else: ?>
                            L'iGate reçoit et affiche les paquets localement, mais n'envoie rien à APRS-IS.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="report-toggle">
                <span class="pill big-pill <?php echo $enabled && $configured ? 'ok' : 'bad'; ?>">
                    <?php echo $enabled && $configured ? '● ACTIVÉ' : '○ DÉSACTIVÉ'; ?>
                </span>
                <form method="POST" action="reporting.php">
                    <?php echo aprs_csrf_field(); ?>
                    <?php if ($enabled): ?>
                        <input type="hidden" name="op" value="disable">
                        <button class="btn btn-danger">⏸ Disable</button>
                    <?php else: ?>
                        <input type="hidden" name="op" value="enable">
                        <button class="btn btn-ok" <?php echo $configured ? '' : 'disabled'; ?>>▶ Enable</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Dernier reporting -->
    <div class="card">
        <h2>✅ Dernier reporting réussi <span class="muted" id="rp-updated"></span></h2>
        <div class="status-grid">
            <div class="status-item"><span>Connexion APRS-IS</span><span id="rp-conn" class="pill unk">…</span></div>
            <div class="status-item"><span>Passcode accepté</span><span id="rp-verified" class="pill unk">…</span></div>
            <div class="status-item"><span>Dernier paquet relayé</span><span id="rp-last" class="pill unk">…</span></div>
            <div class="status-item"><span>Balise de l'iGate</span><span id="rp-beacon" class="pill unk">…</span></div>
        </div>
        <p class="muted" id="rp-detail" style="margin-top: 10px;"></p>

        <h3 style="margin: 18px 0 8px;">Confirmation par aprs.fi</h3>
        <div id="rp-aprsfi" class="muted">
            <?php if ($settings['aprsfi_apikey'] === ''): ?>
                Ajoutez votre clé API aprs.fi (plus bas) pour voir ce qu'aprs.fi a réellement reçu de votre station.
            <?php else: ?>Interrogation d'aprs.fi…<?php endif; ?>
        </div>
        <?php if ($configured): ?>
            <p style="margin-top: 12px;">
                <a class="back" href="https://aprs.fi/#!call=a%2F<?php echo urlencode($station); ?>" target="_blank" rel="noopener">Voir <?php echo h($station); ?> sur aprs.fi ↗</a>
                · <a class="back" href="https://aprs.fi/info/a/<?php echo urlencode($station); ?>" target="_blank" rel="noopener">fiche de la station ↗</a>
            </p>
        <?php endif; ?>
    </div>

    <!-- Réglages -->
    <form method="POST" action="reporting.php">
        <?php echo aprs_csrf_field(); ?>
        <input type="hidden" name="op" value="save">
        <?php aprs_render_sections($sections, $settings, $errors); ?>
        <div class="save-bar">
            <button type="submit" class="btn">💾 Enregistrer et appliquer</button>
            <span class="muted">Le passcode est recalculé et Direwolf se reconnecte avec les nouveaux identifiants.</span>
        </div>
    </form>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    function pill(el, text, cls) { el.textContent = text; el.className = 'pill ' + cls; }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function ago(sec) {
        sec = Math.max(0, Math.round(sec));
        if (sec < 60) return 'il y a ' + sec + ' s';
        if (sec < 3600) return 'il y a ' + Math.floor(sec / 60) + ' min';
        if (sec < 86400) return 'il y a ' + Math.floor(sec / 3600) + ' h ' + Math.floor(sec % 3600 / 60) + ' min';
        return 'il y a ' + Math.floor(sec / 86400) + ' j';
    }
    function when(t) { return new Date(t * 1000).toLocaleString('fr-CA', {hour12: false}); }

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
        $('rp-updated').textContent = '· mis à jour ' + new Date(d.now * 1000).toLocaleTimeString('fr-CA', {hour12: false});
        if (!c.aprsis_enabled) {
            ['rp-conn', 'rp-verified', 'rp-last', 'rp-beacon'].forEach(function (id) { pill($(id), 'désactivé', 'unk'); });
            $('rp-detail').textContent = 'Le reporting est désactivé : cliquez sur Enable pour envoyer les paquets à APRS-IS.';
            return;
        }
        if (d.service !== 'active') pill($('rp-conn'), 'iGate arrêté', 'bad');
        else if (is.state === 'connected') pill($('rp-conn'), 'connecté ' + ago(d.now - is.time).replace('il y a ', 'depuis '), 'ok');
        else if (is.state === 'disconnected') pill($('rp-conn'), 'déconnecté', 'bad');
        else pill($('rp-conn'), 'inconnu', 'unk');

        if (is.verified === true) pill($('rp-verified'), 'oui (vérifié)', 'ok');
        else if (is.verified === false) pill($('rp-verified'), 'NON : paquets refusés', 'bad');
        else pill($('rp-verified'), '?', 'unk');

        // Direwolf relaie chaque paquet reçu tant qu'il est connecté :
        // le dernier paquet reçu après la connexion est le dernier relayé.
        var last = d.stats && d.stats.last;
        if (is.state === 'connected' && d.service === 'active' && last && last >= is.time - 5) {
            pill($('rp-last'), ago(d.now - last), d.now - last < 3600 ? 'ok' : 'mid');
            $('rp-last').title = when(last);
        } else if (last) {
            pill($('rp-last'), 'aucun depuis la connexion', 'unk');
        } else {
            pill($('rp-last'), 'aucun paquet reçu', 'unk');
        }
        pill($('rp-beacon'), c.beacon ? 'toutes les ' + c.beacon_every + ' min' : 'désactivée', c.beacon ? 'ok' : 'unk');

        var txt = 'Serveur ' + c.igserver + '.';
        if (is.line) txt += ' Dernier message : « ' + is.line + ' »';
        if (is.verified === false) txt += ' — Le serveur refuse le passcode : vérifiez l\'indicatif.';
        $('rp-detail').textContent = txt;
    }
    function renderAprsfi(a) {
        var box = $('rp-aprsfi');
        if (!a.enabled) return;
        if (!a.ok) { box.innerHTML = '<span class="pill bad">' + esc(a.error || 'erreur') + '</span>'; return; }
        if (!a.found) {
            box.innerHTML = '<span class="pill mid">aprs.fi n\'a encore rien reçu de ' + esc(a.station) + '</span>' +
                ' <span class="muted">(normal pendant les premières minutes ; la balise de l\'iGate doit être activée)</span>';
            return;
        }
        var e = a.entry, age = Math.floor(Date.now() / 1000) - e.lasttime;
        var viaUs = /qAC|TCPIP/.test(e.path);
        box.innerHTML = '<span class="pill ' + (age < 7200 ? 'ok' : 'mid') + '">aprs.fi a reçu ' + esc(a.station) + ' ' + ago(age) + '</span>' +
            ' <span class="muted">le ' + esc(when(e.lasttime)) + (viaUs ? ' · via la connexion Internet de l\'iGate' : '') +
            (e.path ? ' · chemin ' + esc(e.path) : '') + '</span>' +
            (e.comment ? '<div class="muted" style="margin-top:6px">Commentaire : ' + esc(e.comment) + '</div>' : '');
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
