<?php
// /admin/system.php — moniteur du Pi façon « btop » : CPU, mémoire, disques,
// réseau et processus, mis à jour toutes les 2 secondes.
require __DIR__ . '/../lib/admin.php';
aprs_require_login();

aprs_admin_header(t('📊 Système'), 'system', ['body_class' => 'btop']);
?>
    <div class="bt-grid">
        <div class="bt-box bt-cpu wide">
            <span class="bt-title">¹<b>cpu</b></span>
            <span class="bt-right" id="cpu-info">…</span>
            <canvas class="bt-graph" id="cpu-graph"></canvas>
            <div id="cpu-cores"></div>
            <div class="bt-kv" style="margin-top: 8px;"><span><?php echo th('charge moyenne (1, 5, 15 min)'); ?></span><span id="cpu-load">–</span></div>
            <div class="bt-kv"><span><?php echo th('en marche depuis'); ?></span><span id="cpu-uptime">–</span></div>
            <div class="bt-kv"><span><?php echo th('alimentation'); ?></span><span id="cpu-power">–</span></div>
        </div>

        <div class="bt-box wide">
            <span class="bt-title">²<b><?php echo th('alimentation'); ?></b></span>
            <span class="bt-right" id="pwr-model">…</span>
            <div class="pwr-gauges" id="pwr-gauges"></div>
            <div class="pwr-flags" id="pwr-flags"></div>
            <div class="pwr-note" id="pwr-note"></div>
        </div>

        <div class="bt-box bt-mem">
            <span class="bt-title">³<b><?php echo th('mémoire'); ?></b></span>
            <span class="bt-right" id="mem-total">…</span>
            <div id="mem-rows"></div>
        </div>

        <div class="bt-box bt-disk">
            <span class="bt-title">⁴<b><?php echo th('disques'); ?></b></span>
            <div id="disk-rows"></div>
        </div>

        <div class="bt-box bt-net">
            <span class="bt-title">⁵<b><?php echo th('réseau'); ?></b></span>
            <span class="bt-right" id="net-iface">…</span>
            <canvas class="bt-graph" id="net-graph" style="height: 80px;"></canvas>
            <div id="net-rows"></div>
        </div>

        <div class="bt-box bt-proc wide">
            <span class="bt-title">⁶<b><?php echo th('processus'); ?></b></span>
            <span class="bt-right" id="proc-count">…</span>
            <table class="bt-ps">
                <colgroup><col style="width:70px"><col><col style="width:110px"><col style="width:70px"><col style="width:90px"><col style="width:70px"></colgroup>
                <thead><tr><th class="n">pid</th><th><?php echo th('programme'); ?></th><th><?php echo th('utilisateur'); ?></th><th class="n">cpu%</th><th class="n"><?php echo th('mém'); ?></th><th class="n"><?php echo th('mém%'); ?></th></tr></thead>
                <tbody id="proc-rows"></tbody>
            </table>
        </div>
    </div>
    <p class="muted" id="bt-status" style="margin-top: 12px; color: #7f8a99;"><?php echo th('Chargement…'); ?></p>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var HISTORY = 150;
    var cpuHist = [], rxHist = [], txHist = [];
    var prev = null;

    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function bytes(n) {
        var u = [t('o'), t('Ko'), t('Mo'), t('Go'), t('To')], i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return (n >= 100 || i === 0 ? n.toFixed(0) : n.toFixed(1)) + ' ' + u[i];
    }
    function rate(n) { return bytes(n) + '/s'; }
    function dur(s) {
        var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
        return (d ? d + ' ' + t('j') + ' ' : '') + (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }
    function heat(p) { return p >= 85 ? 'bt-hot' : (p >= 60 ? 'bt-warm' : ''); }
    function meter(kind, pct) {
        pct = Math.max(0, Math.min(100, pct));
        // le dégradé reste fixe sur toute la largeur : vert à gauche, rouge à droite
        var size = pct > 0 ? (10000 / pct) : 100;
        return '<div class="bt-meter ' + kind + '"><i style="width:' + pct.toFixed(1) + '%;background-size:' + size.toFixed(0) + '% 100%"></i></div>';
    }
    function row(label, kind, pct, value, title) {
        return '<div class="bt-row"' + (title ? ' title="' + esc(title) + '"' : '') + '><span class="lbl">' + esc(label) + '</span>' +
               meter(kind, pct) + '<span class="val ' + heat(pct) + '">' + esc(value) + '</span></div>';
    }

    // Graphique en colonnes façon btop (dégradé vertical vert -> jaune -> rouge)
    function drawGraph(canvas, series, colors, maxVal) {
        var dpr = window.devicePixelRatio || 1;
        var w = canvas.clientWidth, h = canvas.clientHeight;
        if (canvas.width !== Math.round(w * dpr)) { canvas.width = Math.round(w * dpr); canvas.height = Math.round(h * dpr); }
        var ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);
        ctx.strokeStyle = '#1d2430'; ctx.lineWidth = 1;
        for (var g = 1; g < 4; g++) { ctx.beginPath(); ctx.moveTo(0, Math.round(h * g / 4) + 0.5); ctx.lineTo(w, Math.round(h * g / 4) + 0.5); ctx.stroke(); }
        var col = w / HISTORY;
        series.forEach(function (data, si) {
            var grad = ctx.createLinearGradient(0, h, 0, 0);
            colors[si].forEach(function (c, i) { grad.addColorStop(i / (colors[si].length - 1), c); });
            ctx.fillStyle = grad;
            var off = HISTORY - data.length;
            data.forEach(function (v, i) {
                var bh = Math.max(v > 0 ? 1 : 0, Math.min(1, v / maxVal) * (h - 2));
                var x = (off + i) * col;
                if (series.length === 2 && si === 1) ctx.fillRect(x, 0, Math.max(1, col - 1), bh);   // envoi : vers le bas
                else ctx.fillRect(x, h - bh, Math.max(1, col - 1), bh);
            });
        });
    }
    function drawCpu() { drawGraph($('cpu-graph'), [cpuHist], [['#50fa7b', '#f1fa8c', '#ff5555']], 100); }
    function drawNet() {
        // réception vers le haut, envoi vers le bas : chacun a la moitié de la hauteur
        var nmax = Math.max.apply(null, rxHist.concat(txHist).concat([1024]));
        drawGraph($('net-graph'), [rxHist, txHist], [['#7b4fbf', '#c678dd'], ['#2f7f8a', '#56b6c2']], nmax * 2);
    }
    function push(arr, v) { arr.push(v); if (arr.length > HISTORY) arr.shift(); }

    // ---------------- Jauges d'alimentation (aiguille + zones vert/jaune/rouge)
    var RED = '#ff5555', YEL = '#f1fa8c', GRN = '#50fa7b';
    var GAUGES = {
        ext5v: {title: t('Entrée 5 V'), min: 4.4, max: 5.6, dec: 2, ideal: t('idéal : 5,0 à 5,25 V'),
                zones: [[4.4, 4.63, RED], [4.63, 4.85, YEL], [4.85, 5.25, GRN], [5.25, 5.4, YEL], [5.4, 5.6, RED]], ticks: [4.4, 4.8, 5.2, 5.6]},
        core_v: {title: t('Cœur du processeur'), min: 0.8, max: 1.5, dec: 3, ideal: t('idéal : 1,2 à 1,4 V (réglé par le Pi)'),
                 zones: [[0.8, 0.95, RED], [0.95, 1.1, YEL], [1.1, 1.4, GRN], [1.4, 1.45, YEL], [1.45, 1.5, RED]], ticks: [0.8, 1.0, 1.2, 1.4]},
        battery_v: {title: t('Batterie PiSugar'), min: 3.0, max: 4.4, dec: 2, ideal: t('idéal : 3,7 à 4,2 V'),
                    zones: [[3.0, 3.4, RED], [3.4, 3.7, YEL], [3.7, 4.25, GRN], [4.25, 4.4, YEL]], ticks: [3.0, 3.5, 4.0, 4.4]}
    };
    var gaugeMin = {};
    function num(v, dec) { var s = v.toFixed(dec); return window.APRS_LANG === 'en' ? s : s.replace('.', ','); }
    function frac(g, v) { return Math.max(0, Math.min(1, (v - g.min) / (g.max - g.min))); }
    function pt(r, f) { var a = (-90 + 180 * f) * Math.PI / 180; return [(110 + r * Math.sin(a)).toFixed(1), (110 - r * Math.cos(a)).toFixed(1)]; }
    function arc(r, f0, f1) { var a = pt(r, f0), b = pt(r, f1); return 'M' + a[0] + ' ' + a[1] + ' A' + r + ' ' + r + ' 0 0 1 ' + b[0] + ' ' + b[1]; }
    function gaugeSvg(key, g) {
        var s = '<svg viewBox="0 0 220 130" aria-label="' + esc(g.title) + '">';
        g.zones.forEach(function (z) {
            s += '<path d="' + arc(88, frac(g, z[0]), frac(g, z[1])) + '" stroke="' + z[2] + '" stroke-width="14" fill="none" opacity="0.85"/>';
        });
        g.ticks.forEach(function (v) {
            var f = frac(g, v), a = pt(78, f), b = pt(70, f), l = pt(56, f);
            s += '<line x1="' + a[0] + '" y1="' + a[1] + '" x2="' + b[0] + '" y2="' + b[1] + '" stroke="#7f8a99" stroke-width="2"/>';
            s += '<text x="' + l[0] + '" y="' + (parseFloat(l[1]) + 4) + '" fill="#7f8a99" font-size="11" text-anchor="middle">' + num(v, 1) + '</text>';
        });
        // repère de la valeur la plus basse vue depuis l'ouverture de la page
        s += '<line class="needle" id="gmin-' + key + '" x1="110" y1="14" x2="110" y2="28" stroke="' + RED + '" stroke-width="3" style="transform: rotate(-90deg); opacity: 0"/>';
        s += '<line class="needle" id="gneedle-' + key + '" x1="110" y1="110" x2="110" y2="30" stroke="#e5e5e5" stroke-width="3" stroke-linecap="round" style="transform: rotate(-90deg)"/>';
        s += '<circle cx="110" cy="110" r="7" fill="#e5e5e5"/></svg>';
        return '<div class="pwr-gauge" id="gauge-' + key + '"><div class="g-title">' + esc(g.title) + '</div>' + s +
               '<div class="g-val" id="gval-' + key + '">–</div><div class="g-ideal">' + esc(g.ideal) + '</div>' +
               '<div class="g-min" id="gminv-' + key + '"></div></div>';
    }
    function zoneColor(g, v) {
        for (var i = 0; i < g.zones.length; i++) if (v >= g.zones[i][0] && v <= g.zones[i][1]) return g.zones[i][2];
        return RED;
    }
    function setGauge(key, v) {
        var g = GAUGES[key];
        if (!$('gauge-' + key)) $('pwr-gauges').insertAdjacentHTML('beforeend', gaugeSvg(key, g));
        $('gneedle-' + key).style.transform = 'rotate(' + (-90 + 180 * frac(g, v)).toFixed(1) + 'deg)';
        $('gval-' + key).innerHTML = '<span style="font-size: 1.4em; font-weight: 600; color:' + zoneColor(g, v) + '">' + num(v, g.dec) + ' V</span>';
        if (gaugeMin[key] === undefined || v < gaugeMin[key]) gaugeMin[key] = v;
        var mk = $('gmin-' + key);
        mk.style.transform = 'rotate(' + (-90 + 180 * frac(g, gaugeMin[key])).toFixed(1) + 'deg)';
        mk.style.opacity = '1';
        $('gminv-' + key).textContent = t('minimum vu : %s V', num(gaugeMin[key], g.dec));
    }
    function renderPower(p, thr) {
        if (!p) return;
        $('pwr-model').textContent = p.model || '';
        // Entrée 5 V : vraie mesure sur Pi 5, sinon seulement l'alerte de sous-tension du firmware
        var box = $('pwr-supply');
        if (p.ext5v !== null) {
            if (box) box.parentNode.removeChild(box);
            setGauge('ext5v', p.ext5v);
        } else {
            if (!box) {
                $('pwr-gauges').insertAdjacentHTML('afterbegin', '<div class="pwr-state" id="pwr-supply"><div class="g-title">' + esc(t('Alimentation 5 V')) +
                    '</div><div class="big" id="pwr-supply-state">–</div><div class="g-ideal">' + esc(t('idéal : 5,0 à 5,25 V')) + '</div></div>');
            }
            var st = $('pwr-supply-state');
            if (!thr) st.innerHTML = '<span style="color:#7f8a99">' + esc(t('inconnue')) + '</span>';
            else if (thr.undervolt_now) st.innerHTML = '<span class="bt-hot">⚡ ' + esc(t('SOUS-TENSION')) + '</span>';
            else if (thr.undervolt_since_boot) st.innerHTML = '<span class="bt-warm">⚠️ ' + esc(t('sous-tension depuis le démarrage')) + '</span>';
            else st.innerHTML = '<span style="color:' + GRN + '">✓ ' + esc(t('suffisante (plus de 4,63 V)')) + '</span>';
        }
        if (p.core_v !== null) setGauge('core_v', p.core_v);
        if (p.battery_v !== null) setGauge('battery_v', p.battery_v);

        // Indicateurs du firmware : maintenant / depuis le démarrage
        if (thr) {
            var flags = [
                ['undervolt', t('Sous-tension (moins de 4,63 V)')],
                ['capped', t('Fréquence du processeur plafonnée')],
                ['throttled', t('Processeur ralenti')],
                ['softtemp', t('Limite thermique douce atteinte')]
            ];
            var cell = function (on, warn) {
                return on ? '<span class="' + (warn ? 'bt-warm' : 'bt-hot') + '">' + esc(warn ? t('oui, depuis le démarrage') : t('OUI, maintenant')) + '</span>'
                          : '<span style="color:' + GRN + '">' + esc(t('non')) + '</span>';
            };
            $('pwr-flags').innerHTML = flags.map(function (f) {
                var now = thr[f[0] + '_now'], since = thr[f[0] + '_since_boot'];
                var state = now ? cell(true, false) : (thr.history ? cell(since, true) : cell(false));
                return '<div class="bt-kv"><span>' + esc(f[1]) + '</span>' + state + '</div>';
            }).join('') + '<div class="bt-kv" style="color:#56606e"><span>' + esc(t('source')) + '</span><span>' +
                esc(thr.source === 'vcgencmd' ? 'vcgencmd get_throttled = ' + thr.raw : (thr.source === 'hwmon' ? t('capteur noyau rpi_volt (sans historique)') : 'sysfs = ' + thr.raw)) + '</span></div>';
        }
        var notes = [];
        if (p.ext5v === null) notes.push(t('Ce modèle ne mesure pas la tension de son entrée 5 V : le firmware signale seulement une chute sous 4,63 V. Une sous-tension cause des pertes de paquets et des déconnexions du dongle SDR.'));
        if (!p.vcgencmd) notes.push(t('Tension du cœur indisponible : relancez sudo ./install.sh pour donner à la page l\'accès à vcgencmd.'));
        notes.push(t('Le repère rouge sur le bord de la jauge marque la valeur la plus basse vue depuis l\'ouverture de la page.'));
        $('pwr-note').textContent = notes.join(' ');
    }

    function render(d) {
        var dt = prev ? Math.max(0.1, d.time - prev.time) : 0;

        // ---------------- CPU
        var cores = [], total = null;
        if (prev) {
            d.cpu.forEach(function (c, i) {
                var p = prev.cpu[i];
                if (!p) return;
                var dtot = c.total - p.total, didle = c.idle - p.idle;
                var pct = dtot > 0 ? Math.max(0, 100 * (1 - didle / dtot)) : 0;
                if (c.name === 'cpu') total = pct; else cores.push({name: c.name, pct: pct});
            });
        }
        if (total !== null) push(cpuHist, total);
        drawCpu();
        var info = [];
        if (total !== null) info.push('<span class="' + heat(total) + '">' + total.toFixed(0) + '%</span>');
        if (d.freq_mhz) info.push(d.freq_mhz + ' MHz');
        if (d.temp_c !== null) info.push('<span class="' + (d.temp_c >= 75 ? 'bt-hot' : (d.temp_c >= 65 ? 'bt-warm' : '')) + '">' + d.temp_c.toFixed(1) + ' °C</span>');
        $('cpu-info').innerHTML = info.join(' · ') || '…';
        $('cpu-cores').innerHTML = cores.map(function (c) {
            return row(c.name.replace('cpu', 'C'), 'cpu', c.pct, c.pct.toFixed(0) + '%');
        }).join('') || '<div class="bt-sub">' + esc(t('mesure en cours…')) + '</div>';
        $('cpu-load').textContent = d.load.map(function (x) { return x.toFixed(2); }).join('  ');
        $('cpu-uptime').textContent = dur(d.uptime);
        var thr = d.throttled;
        if (!thr) $('cpu-power').textContent = t('inconnue');
        else if (thr.undervolt_now) $('cpu-power').innerHTML = '<span class="bt-hot">⚡ ' + esc(t('SOUS-TENSION en ce moment')) + '</span>';
        else if (thr.throttled_now) $('cpu-power').innerHTML = '<span class="bt-hot">' + esc(t('ralenti (chaleur ou tension)')) + '</span>';
        else if (thr.undervolt_since_boot) $('cpu-power').innerHTML = '<span class="bt-warm">' + esc(t('sous-tension depuis le démarrage')) + '</span>';
        else $('cpu-power').textContent = 'OK';
        renderPower(d.power, thr);

        // ---------------- Mémoire (kio)
        var m = d.mem, K = 1024;
        var used = m.total - m.available;
        $('mem-total').textContent = bytes(m.total * K);
        var rows = [
            row(t('utilisée'), 'mem', 100 * used / m.total, bytes(used * K)),
            row(t('dispo'), 'mem', 100 * m.available / m.total, bytes(m.available * K)),
            row(t('cache'), 'mem', 100 * m.cached / m.total, bytes(m.cached * K)),
            row(t('libre'), 'mem', 100 * m.free / m.total, bytes(m.free * K))
        ];
        if (m.swap_total > 0) {
            var su = m.swap_total - m.swap_free;
            rows.push('<div class="bt-sub">swap ' + bytes(m.swap_total * K) + '</div>');
            rows.push(row('swap', 'mem', 100 * su / m.swap_total, bytes(su * K)));
        }
        $('mem-rows').innerHTML = rows.join('');

        // ---------------- Disques
        $('disk-rows').innerHTML = d.disks.map(function (k) {
            var pct = 100 * k.used / k.total;
            return '<div class="bt-sub">' + esc(k.mount) + ' <span style="color:#56606e">(' + esc(k.dev) + ', ' + esc(k.fs) + ')</span></div>' +
                   row(t('utilisé'), 'disk', pct, pct.toFixed(0) + '%', t('%s sur %s', bytes(k.used), bytes(k.total))) +
                   '<div class="bt-kv"><span>' + esc(t('%s utilisés', bytes(k.used))) + '</span><span>' + esc(t('%s libres / %s', bytes(k.free), bytes(k.total))) + '</span></div>';
        }).join('') || '<div class="bt-sub">' + esc(t('aucun disque')) + '</div>';

        // ---------------- Réseau (interface la plus active)
        var netRows = [], rxTot = 0, txTot = 0, busiest = null;
        d.net.forEach(function (n) {
            var p = prev && prev.net.filter(function (x) { return x.name === n.name; })[0];
            var rx = p && dt ? Math.max(0, (n.rx - p.rx) / dt) : 0;
            var tx = p && dt ? Math.max(0, (n.tx - p.tx) / dt) : 0;
            rxTot += rx; txTot += tx;
            if (!busiest || n.rx + n.tx > busiest.rx + busiest.tx) busiest = n;
            netRows.push('<div class="bt-kv"><span>' + esc(n.name) + '</span><span>▼ ' + rate(rx) + ' &nbsp; ▲ ' + rate(tx) + '</span></div>');
            netRows.push('<div class="bt-kv" style="color:#56606e"><span></span><span>total ▼ ' + bytes(n.rx) + ' ▲ ' + bytes(n.tx) + '</span></div>');
        });
        if (prev) { push(rxHist, rxTot); push(txHist, txTot); }
        drawNet();
        $('net-iface').textContent = '▼ ' + rate(rxTot) + ' ▲ ' + rate(txTot);
        $('net-rows').innerHTML = netRows.join('');

        // ---------------- Processus (CPU % depuis la dernière lecture)
        var prevTicks = {};
        if (prev) prev.procs.forEach(function (p) { prevTicks[p.pid + p.name] = p.ticks; });
        var ncpu = Math.max(1, d.cpu.length - 1);
        var list = d.procs.map(function (p) {
            var before = prevTicks[p.pid + p.name];
            var cpu = (before !== undefined && dt) ? 100 * (p.ticks - before) / d.hz / dt : 0;
            return {pid: p.pid, name: p.name, user: p.user, cpu: Math.max(0, cpu), rss: p.rss};
        }).filter(function (p) { return p.rss > 0; });
        list.sort(function (a, b) { return b.cpu - a.cpu || b.rss - a.rss; });
        $('proc-count').textContent = t('%s processus · %s cœurs', d.procs.length, ncpu);
        $('proc-rows').innerHTML = list.slice(0, 14).map(function (p) {
            var mp = 100 * p.rss / m.total;
            var watch = /^(direwolf|rtl_fm|python3|nginx|php-fpm)/.test(p.name) ? ' style="color:#e5c07b"' : '';
            return '<tr><td class="n">' + p.pid + '</td><td' + watch + '>' + esc(p.name) + '</td><td>' + esc(p.user) +
                   '</td><td class="n ' + heat(p.cpu) + '">' + p.cpu.toFixed(1) + '</td><td class="n">' + bytes(p.rss * 1024) +
                   '</td><td class="n">' + mp.toFixed(1) + '</td></tr>';
        }).join('');

        $('bt-status').textContent = t('Mise à jour toutes les 2 secondes') + ' · ' + new Date().toLocaleTimeString(window.APRS_LANG === 'en' ? 'en-CA' : 'fr-CA', {hour12: false}) +
            ' · ' + t('cpu% d\'un processus : 100 = un cœur complet');
        prev = d;
    }

    var busy = false;
    function load() {
        if (busy || document.hidden) return;
        busy = true;
        fetch('api.php?what=metrics', {credentials: 'same-origin'})
            .then(function (r) { if (r.status === 401) { location.reload(); throw 0; } return r.json(); })
            .then(render)
            .catch(function (e) { if (e) $('bt-status').innerHTML = '<span class="bt-err">' + esc(t('Erreur de lecture des mesures')) + '</span>'; })
            .then(function () { busy = false; });
    }
    load();
    setTimeout(load, 700);   // deuxième lecture rapide pour avoir des pourcentages tout de suite
    setInterval(load, 2000);
    window.addEventListener('resize', function () { drawCpu(); drawNet(); });
})();
</script>
<?php aprs_admin_footer();
