<?php
// /admin/system.php — moniteur du Pi façon « btop » : CPU, mémoire, disques,
// réseau et processus, mis à jour toutes les 2 secondes.
require __DIR__ . '/../lib/admin.php';
aprs_require_login();

aprs_admin_header('📊 Système', 'system', ['body_class' => 'btop']);
?>
    <div class="bt-grid">
        <div class="bt-box bt-cpu wide">
            <span class="bt-title">¹<b>cpu</b></span>
            <span class="bt-right" id="cpu-info">…</span>
            <canvas class="bt-graph" id="cpu-graph"></canvas>
            <div id="cpu-cores"></div>
            <div class="bt-kv" style="margin-top: 8px;"><span>charge moyenne (1, 5, 15 min)</span><span id="cpu-load">–</span></div>
            <div class="bt-kv"><span>en marche depuis</span><span id="cpu-uptime">–</span></div>
            <div class="bt-kv"><span>alimentation</span><span id="cpu-power">–</span></div>
        </div>

        <div class="bt-box bt-mem">
            <span class="bt-title">²<b>mémoire</b></span>
            <span class="bt-right" id="mem-total">…</span>
            <div id="mem-rows"></div>
        </div>

        <div class="bt-box bt-disk">
            <span class="bt-title">³<b>disques</b></span>
            <div id="disk-rows"></div>
        </div>

        <div class="bt-box bt-net">
            <span class="bt-title">⁴<b>réseau</b></span>
            <span class="bt-right" id="net-iface">…</span>
            <canvas class="bt-graph" id="net-graph" style="height: 80px;"></canvas>
            <div id="net-rows"></div>
        </div>

        <div class="bt-box bt-proc wide">
            <span class="bt-title">⁵<b>processus</b></span>
            <span class="bt-right" id="proc-count">…</span>
            <table class="bt-ps">
                <colgroup><col style="width:70px"><col><col style="width:110px"><col style="width:70px"><col style="width:90px"><col style="width:70px"></colgroup>
                <thead><tr><th class="n">pid</th><th>programme</th><th>utilisateur</th><th class="n">cpu%</th><th class="n">mém</th><th class="n">mém%</th></tr></thead>
                <tbody id="proc-rows"></tbody>
            </table>
        </div>
    </div>
    <p class="muted" id="bt-status" style="margin-top: 12px; color: #7f8a99;">Chargement…</p>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var HISTORY = 150;
    var cpuHist = [], rxHist = [], txHist = [];
    var prev = null;

    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function bytes(n) {
        var u = ['o', 'Ko', 'Mo', 'Go', 'To'], i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return (n >= 100 || i === 0 ? n.toFixed(0) : n.toFixed(1)) + ' ' + u[i];
    }
    function rate(n) { return bytes(n) + '/s'; }
    function dur(s) {
        var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
        return (d ? d + ' j ' : '') + (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
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
        }).join('') || '<div class="bt-sub">mesure en cours…</div>';
        $('cpu-load').textContent = d.load.map(function (x) { return x.toFixed(2); }).join('  ');
        $('cpu-uptime').textContent = dur(d.uptime);
        var t = d.throttled;
        if (!t) $('cpu-power').textContent = 'inconnue';
        else if (t.undervolt_now) $('cpu-power').innerHTML = '<span class="bt-hot">⚡ SOUS-TENSION en ce moment</span>';
        else if (t.throttled_now) $('cpu-power').innerHTML = '<span class="bt-hot">ralenti (chaleur ou tension)</span>';
        else if (t.undervolt_since_boot) $('cpu-power').innerHTML = '<span class="bt-warm">sous-tension depuis le démarrage</span>';
        else $('cpu-power').textContent = 'OK';

        // ---------------- Mémoire (kio)
        var m = d.mem, K = 1024;
        var used = m.total - m.available;
        $('mem-total').textContent = bytes(m.total * K);
        var rows = [
            row('utilisée', 'mem', 100 * used / m.total, bytes(used * K)),
            row('dispo', 'mem', 100 * m.available / m.total, bytes(m.available * K)),
            row('cache', 'mem', 100 * m.cached / m.total, bytes(m.cached * K)),
            row('libre', 'mem', 100 * m.free / m.total, bytes(m.free * K))
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
                   row('utilisé', 'disk', pct, pct.toFixed(0) + '%', bytes(k.used) + ' sur ' + bytes(k.total)) +
                   '<div class="bt-kv"><span>' + bytes(k.used) + ' utilisés</span><span>' + bytes(k.free) + ' libres / ' + bytes(k.total) + '</span></div>';
        }).join('') || '<div class="bt-sub">aucun disque</div>';

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
        $('proc-count').textContent = d.procs.length + ' processus · ' + ncpu + ' cœurs';
        $('proc-rows').innerHTML = list.slice(0, 14).map(function (p) {
            var mp = 100 * p.rss / m.total;
            var watch = /^(direwolf|rtl_fm|python3|nginx|php-fpm)/.test(p.name) ? ' style="color:#e5c07b"' : '';
            return '<tr><td class="n">' + p.pid + '</td><td' + watch + '>' + esc(p.name) + '</td><td>' + esc(p.user) +
                   '</td><td class="n ' + heat(p.cpu) + '">' + p.cpu.toFixed(1) + '</td><td class="n">' + bytes(p.rss * 1024) +
                   '</td><td class="n">' + mp.toFixed(1) + '</td></tr>';
        }).join('');

        $('bt-status').textContent = 'Mise à jour toutes les 2 secondes · ' + new Date().toLocaleTimeString('fr-CA', {hour12: false}) +
            ' · cpu% d\'un processus : 100 = un cœur complet';
        prev = d;
    }

    var busy = false;
    function load() {
        if (busy || document.hidden) return;
        busy = true;
        fetch('api.php?what=metrics', {credentials: 'same-origin'})
            .then(function (r) { if (r.status === 401) { location.reload(); throw 0; } return r.json(); })
            .then(render)
            .catch(function (e) { if (e) $('bt-status').innerHTML = '<span class="bt-err">Erreur de lecture des mesures</span>'; })
            .then(function () { busy = false; });
    }
    load();
    setTimeout(load, 700);   // deuxième lecture rapide pour avoir des pourcentages tout de suite
    setInterval(load, 2000);
    window.addEventListener('resize', function () { drawCpu(); drawNet(); });
})();
</script>
<?php aprs_admin_footer();
