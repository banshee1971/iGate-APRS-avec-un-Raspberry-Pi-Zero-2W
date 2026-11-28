<?php
// Carte de suivi des stations, partagée par la carte en ligne (index.php)
// et la carte Offline (map-offline.php) : filtre par groupes de favoris,
// trajets, âge des positions, mise à jour automatique (map-data.php).
//
//   aprs_map_tracker_css()                       dans le <head>
//   aprs_map_tracker($S, $hours, $tiles, $online)  là où la carte doit apparaître
//     $tiles  : '/tiles/{z}/{x}/{y}.png' (cache + Internet) ou '/tiles-cache/...' (cache seulement)
//     $online : vrai si un avertissement « tuiles manquantes » doit s'afficher

function aprs_map_tracker_css() {
    return "<style>\n" . <<<'CSS'
        .aprs-sym { display: inline-block; width: 24px; height: 24px; background-size: 384px 144px;
                    background-repeat: no-repeat; vertical-align: middle; position: relative; margin-right: 4px; }
        .aprs-sym i { position: absolute; inset: 0; background-image: url(/vendor/aprs-symbols/aprs-symbols-24-2@2x.png);
                      background-size: 384px 144px; background-repeat: no-repeat; }
        .station-icon .aprs-sym { margin: 0; filter: drop-shadow(0 1px 1px rgba(0,0,0,.4)); }
        .station-emoji { font-size: 22px; line-height: 22px; text-align: center; filter: drop-shadow(0 1px 1px rgba(0,0,0,.5)); }
        /* Suivi des stations */
        .map-tools { background: #f8f9fa; border-radius: 8px; padding: 12px 15px; margin-bottom: 12px; display: flex; flex-direction: column; gap: 10px; }
        .mt-row { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; }
        .mt-row label { display: flex; gap: 6px; align-items: center; font-weight: 500; color: #444; }
        .mt-row select, .mt-row input[type=search] { padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 0.95em; }
        .mt-row input[type=search] { flex: 1; min-width: 220px; }
        .mt-check { font-weight: normal !important; }
        .mt-btn { padding: 6px 12px; border: 1px solid #c5cae9; background: white; color: #3f51b5; border-radius: 5px; cursor: pointer; font-size: 0.9em; }
        .mt-btn:hover { background: #e8eaf6; }
        .mt-danger { color: #c62828; border-color: #ef9a9a; }
        .mt-status { color: #888; font-size: 0.85em; margin-left: auto; }
        .mt-picker { border-top: 1px solid #e0e0e0; padding-top: 10px; display: flex; flex-direction: column; gap: 8px; }
        .mt-picker[hidden] { display: none; }
        .mt-hint { color: #777; font-size: 0.85em; }
        .mt-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 4px 12px; max-height: 220px; overflow-y: auto; }
        .mt-item { display: flex; gap: 6px; align-items: center; font-size: 0.9em; cursor: pointer; }
        .mt-item span { color: #888; font-size: 0.85em; margin-left: auto; }
        .mt-item.age-fresh b { color: #2e7d32; } .mt-item.age-mid b { color: #e65100; } .mt-item.age-old b, .mt-item.age-none b { color: #777; }
        .leaflet-tooltip.age-label { padding: 1px 6px; font-size: 11px; font-weight: 600; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.3); white-space: nowrap; }
        .leaflet-tooltip.age-label.age-fresh { background: #e8f5e9; border-color: #2e7d32; color: #1b5e20; }
        .leaflet-tooltip.age-label.age-mid { background: #fff3e0; border-color: #e65100; color: #bf360c; }
        .leaflet-tooltip.age-label.age-old { background: #f5f5f5; border-color: #9e9e9e; color: #616161; }
        .leaflet-tooltip.age-label .age { font-weight: normal; }
        .igate-marker { filter: drop-shadow(0 1px 2px rgba(0,0,0,.5)); }
CSS
    . "    </style>\n";
}

function aprs_map_tracker(array $S, $hours, $tiles, $online) {
?>
                    <!-- Suivi des stations : filtre, groupes de favoris, trajets, âge des positions -->
                    <div class="map-tools">
                        <div class="mt-row">
                            <label><?php echo th('Trajets'); ?>
                                <select id="mt-trail">
                                    <option value="0"><?php echo th('aucun trajet'); ?></option>
                                    <option value="15">15 min</option>
                                    <option value="30">30 min</option>
                                    <option value="60">1 h</option>
                                    <option value="120">2 h</option>
                                    <option value="360">6 h</option>
                                    <option value="1440">24 h</option>
                                </select>
                            </label>
                            <label class="mt-check"><input type="checkbox" id="mt-ages" checked> <?php echo th('Âge des positions'); ?></label>
                            <label class="mt-check"><input type="checkbox" id="mt-follow"> <?php echo th('Suivre (recentrer la carte)'); ?></label>
                            <span class="mt-status" id="mt-status"></span>
                        </div>
                        <div class="mt-row">
                            <label><?php echo th('Stations'); ?>
                                <select id="mt-group"></select>
                            </label>
                            <button type="button" class="mt-btn" id="mt-new"><?php echo th('＋ Nouveau groupe'); ?></button>
                            <button type="button" class="mt-btn" id="mt-rename"><?php echo th('Renommer'); ?></button>
                            <button type="button" class="mt-btn mt-danger" id="mt-delete"><?php echo th('Supprimer'); ?></button>
                            <button type="button" class="mt-btn" id="mt-toggle-list"><?php echo th('Choisir les stations ▾'); ?></button>
                        </div>
                        <div id="mt-picker" class="mt-picker" hidden>
                            <div class="mt-row">
                                <input type="search" id="mt-search" placeholder="<?php echo th('Rechercher ou ajouter un indicatif (ex. VE2ABC-9)'); ?>">
                                <button type="button" class="mt-btn" id="mt-add"><?php echo th('Ajouter'); ?></button>
                                <button type="button" class="mt-btn" id="mt-all"><?php echo th('Tout cocher'); ?></button>
                                <button type="button" class="mt-btn" id="mt-none"><?php echo th('Tout décocher'); ?></button>
                            </div>
                            <div class="mt-hint" id="mt-hint"></div>
                            <div class="mt-list" id="mt-list"></div>
                        </div>
                    </div>

                    <div class="map-info" id="map-info">…</div>

                    <div id="map"></div>

                    <script>
                    (function () {
                        var HOURS = <?php echo intval($hours); ?>;
                        var ONLINE = <?php echo $online ? 'true' : 'false'; ?>;
                        if (typeof L === 'undefined') {
                            document.getElementById('map').innerHTML = '<div class="no-leaflet">' +
                                t('La bibliothèque de carte (Leaflet) n\'est pas installée sur le Pi.') + ' ' +
                                t('Relancez %s avec Internet pour l\'installer.', '<code>sudo ./install.sh</code>') + '</div>';
                            return;
                        }
                        var REFRESH_MS = 30000;
                        var map = L.map('map').setView([<?php echo json_encode($S['lat']); ?>, <?php echo json_encode($S['lon']); ?>], 10);

                        // Tuiles servies par le Pi : depuis son cache (/var/www/aprs/tiles) si
                        // déjà vues, sinon nginx les récupère sur OpenStreetMap et les garde.
                        // Erreurs = pas d'Internet ET tuile absente du cache.
                        var offlineMode = false, tileErrorCount = 0;
                        var tileLayer = L.tileLayer(<?php echo json_encode($tiles); ?>, {
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' + (ONLINE ? '' : ' ' + t('(cache local)')),
                            maxZoom: 18,
                            errorTileUrl: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
                        });
                        tileLayer.on('tileerror', function () {
                            tileErrorCount++;
                            var warn = document.getElementById('offline-warning');
                            if (ONLINE && warn && tileErrorCount > 3 && !offlineMode) {
                                offlineMode = true;
                                warn.style.display = 'block';
                            }
                        });
                        tileLayer.addTo(map);

                        // Votre iGate (point vert)
                        var IGATE = <?php echo json_encode(['name' => aprs_station($S), 'lat' => (float)$S['lat'], 'lon' => (float)$S['lon']]); ?>;
                        L.circleMarker([IGATE.lat, IGATE.lon], {radius: 9, color: '#ffffff', weight: 2, fillColor: '#4CAF50', fillOpacity: 1, className: 'igate-marker'})
                            .addTo(map).bindPopup('<strong>' + IGATE.name.replace(/[&<>"']/g, '') + '</strong><br>' + t('Votre iGate'));

                        var $ = function (id) { return document.getElementById(id); };
                        var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) {
                            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };

                        // ---------- préférences et groupes (gardés dans ce navigateur)
                        var store = {
                            get: function (k, d) { try { var v = localStorage.getItem('aprs_map_' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
                            set: function (k, v) { try { localStorage.setItem('aprs_map_' + k, JSON.stringify(v)); } catch (e) {} }
                        };
                        var groups = store.get('groups', {});          // { "Club": ["VE2ABC-9", "VE2XYZ*"] }
                        var active = store.get('active', '');          // '' = toutes les stations
                        if (active && !groups[active]) active = '';
                        $('mt-trail').value = String(store.get('trail', 30));
                        $('mt-ages').checked = store.get('ages', true);
                        $('mt-follow').checked = store.get('follow', false);

                        function norm(c) { return String(c || '').toUpperCase().replace(/\s+/g, ''); }
                        function members() { return active ? groups[active] : null; }
                        // « VE2ABC* » = toutes les stations de VE2ABC (VE2ABC, VE2ABC-9, ...)
                        function matches(call, list) {
                            for (var i = 0; i < list.length; i++) {
                                var m = list[i];
                                if (m.slice(-1) === '*' ? call.indexOf(m.slice(0, -1)) === 0 : call === m) return true;
                            }
                            return false;
                        }
                        function visible(call) { var l = members(); return !l || matches(call, l); }
                        function saveGroups() { store.set('groups', groups); store.set('active', active); }

                        function fillGroups() {
                            var sel = $('mt-group');
                            sel.innerHTML = '<option value="">' + esc(t('Toutes les stations')) + '</option>' +
                                Object.keys(groups).sort().map(function (g) {
                                    return '<option value="' + esc(g) + '">⭐ ' + esc(g) + ' (' + groups[g].length + ')</option>';
                                }).join('');
                            sel.value = active;
                            $('mt-rename').hidden = $('mt-delete').hidden = !active;
                            $('mt-hint').textContent = active
                                ? t('Groupe « %s » : cochez ou décochez pour ajouter ou retirer une station. Les changements sont enregistrés tout de suite.', active)
                                : t('Créez un groupe pour ne suivre que certaines stations.');
                            $('mt-all').hidden = $('mt-none').hidden = $('mt-add').hidden = !active;
                        }

                        // ---------- carte
                        var data = {now: 0, stations: []}, fetchedAt = 0, firstLoad = true;
                        var layer = L.layerGroup().addTo(map);
                        var COLORS = ['#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#008080', '#f032e6', '#9a6324', '#800000', '#000075'];
                        function color(call) { var h = 0; for (var i = 0; i < call.length; i++) h = (h * 31 + call.charCodeAt(i)) >>> 0; return COLORS[h % COLORS.length]; }
                        function nowServer() { return data.now + (Date.now() - fetchedAt) / 1000; }
                        function age(ts) {
                            var s = Math.max(0, nowServer() - ts), m = Math.floor(s / 60);
                            if (m < 1) return t('à l\'instant');
                            if (m < 60) return t('%d min', m);
                            var h = Math.floor(m / 60);
                            if (h < 24) return t('%d h %s', h, (m % 60 < 10 ? '0' : '') + (m % 60));
                            return t('%d j', Math.floor(h / 24));
                        }
                        function ageClass(ts) { var m = (nowServer() - ts) / 60; return m < 10 ? 'age-fresh' : (m < 60 ? 'age-mid' : 'age-old'); }
                        function when(ts) { return new Date(ts * 1000).toLocaleString(window.APRS_LANG === 'en' ? 'en-CA' : 'fr-CA', {hour12: false}); }

                        var markers = {};
                        function draw() {
                            // garder ouverte la bulle que l'utilisateur lit
                            var openCall = null;
                            Object.keys(markers).forEach(function (c) { if (markers[c].mk.isPopupOpen()) openCall = c; });
                            layer.clearLayers();
                            markers = {};
                            var bounds = [], shown = 0, showAges = $('mt-ages').checked;
                            data.stations.forEach(function (s) {
                                if (!visible(s.call)) return;
                                shown++;
                                var col = color(s.call);
                                // trajet : ligne entre les positions + un point par position
                                if (s.track.length > 1) {
                                    var pts = s.track.map(function (p) { return [p[0], p[1]]; });
                                    L.polyline(pts, {color: col, weight: 3, opacity: 0.75}).addTo(layer);
                                    s.track.slice(0, -1).forEach(function (p) {
                                        L.circleMarker([p[0], p[1]], {radius: 3, color: col, weight: 1, fillOpacity: 0.8})
                                            .bindTooltip(esc(s.call) + ' · ' + esc(age(p[2])) + '<br>' + esc(when(p[2])))
                                            .addTo(layer);
                                        bounds.push([p[0], p[1]]);
                                    });
                                }
                                var icon = L.divIcon({className: 'station-icon', html: s.icon, iconSize: [24, 24], iconAnchor: [12, 12], popupAnchor: [0, -12]});
                                var mk = L.marker([s.lat, s.lon], {icon: icon, title: s.call, zIndexOffset: 1000}).addTo(layer);
                                mk.bindPopup('<div style="font-family: Arial; min-width: 200px;">' +
                                    '<strong style="font-size: 1.2em; color: ' + col + ';">' + esc(s.call) + '</strong>' +
                                    (s.sym ? ' <span style="color:#666">· ' + esc(s.sym) + '</span>' : '') + '<br>' +
                                    (s.comment ? '<em>' + esc(s.comment) + '</em><br>' : '') +
                                    '<hr style="margin: 8px 0;">' +
                                    '📍 <strong>' + esc(t('Position:')) + '</strong> ' + s.lat.toFixed(4) + '°, ' + s.lon.toFixed(4) + '°<br>' +
                                    '🕐 <strong>' + esc(t('Dernière réception:')) + '</strong> ' + esc(when(s.ts)) + ' (' + esc(age(s.ts)) + ')<br>' +
                                    '📦 <strong>' + esc(t('Paquets reçus:')) + '</strong> ' + s.n +
                                    (s.track.length > 1 ? '<br>〰️ ' + esc(t('%d positions sur le trajet', s.track.length)) : '') +
                                    '</div>', {autoPan: false});
                                if (showAges) {
                                    mk.bindTooltip(esc(s.call) + ' · <span class="age">' + esc(age(s.ts)) + '</span>',
                                        {permanent: true, direction: 'right', offset: [12, 0], className: 'age-label ' + ageClass(s.ts)});
                                }
                                markers[s.call] = {mk: mk, s: s};
                                bounds.push([s.lat, s.lon]);
                            });
                            var info = t('%d station(s)', shown) + ' · ' + t('entendues dans les %d dernières heures', HOURS);
                            if (members()) info = '⭐ ' + esc(active) + ' · ' + info;
                            $('map-info').innerHTML = '<strong>📍 ' + info + '</strong>';
                            if (openCall && markers[openCall]) markers[openCall].mk.openPopup();
                            return bounds;
                        }
                        // l'âge affiché avance entre deux mises à jour, sans tout redessiner
                        function updateAges() {
                            Object.keys(markers).forEach(function (c) {
                                var tip = markers[c].mk.getTooltip(), s = markers[c].s;
                                if (!tip) return;
                                tip.setContent(esc(s.call) + ' · <span class="age">' + esc(age(s.ts)) + '</span>');
                                var el = tip.getElement();
                                if (el) { el.classList.remove('age-fresh', 'age-mid', 'age-old'); el.classList.add(ageClass(s.ts)); }
                            });
                            if (!$('mt-picker').hidden) drawList();
                        }
                        function fit(bounds) {
                            if (bounds.length === 1) map.setView(bounds[0], Math.max(map.getZoom(), 13));
                            else if (bounds.length > 1) map.fitBounds(bounds, {padding: [50, 50], maxZoom: 15});
                        }

                        // ---------- liste des stations (cases à cocher)
                        function drawList() {
                            var q = norm($('mt-search').value), list = members(), rows = [], seen = {};
                            data.stations.forEach(function (s) {
                                seen[s.call] = true;
                                if (q && s.call.indexOf(q) < 0) return;
                                var on = list ? matches(s.call, list) : true;
                                rows.push('<label class="mt-item ' + ageClass(s.ts) + '"><input type="checkbox" data-call="' + esc(s.call) + '" ' +
                                    (on ? 'checked' : '') + (list ? '' : ' disabled') + '> <b>' + esc(s.call) + '</b> <span>' + esc(age(s.ts)) + '</span></label>');
                            });
                            // membres du groupe pas entendus pendant la période
                            (list || []).forEach(function (m) {
                                if (seen[m] || (q && m.indexOf(q) < 0)) return;
                                rows.push('<label class="mt-item age-none"><input type="checkbox" data-call="' + esc(m) + '" checked> <b>' + esc(m) + '</b> <span>' +
                                    esc(m.slice(-1) === '*' ? t('toutes ses stations') : t('pas entendue')) + '</span></label>');
                            });
                            $('mt-list').innerHTML = rows.join('') || '<span class="mt-hint">' + esc(t('Aucune station.')) + '</span>';
                        }
                        $('mt-list').addEventListener('change', function (e) {
                            var call = e.target.getAttribute('data-call'), list = members();
                            if (!call || !list) return;
                            var i = list.indexOf(call);
                            if (e.target.checked && i < 0) list.push(call);
                            if (!e.target.checked && i >= 0) list.splice(i, 1);
                            saveGroups(); fillGroups(); refreshView(true);
                        });

                        function refreshView(refit) {
                            var b = draw();
                            if (refit || $('mt-follow').checked) fit(b);
                            if (!$('mt-picker').hidden) drawList();
                        }

                        // ---------- boutons
                        $('mt-group').addEventListener('change', function () { active = this.value; saveGroups(); fillGroups(); refreshView(true); });
                        $('mt-new').addEventListener('click', function () {
                            var name = (prompt(t('Nom du nouveau groupe :'), '') || '').trim().slice(0, 30);
                            if (!name) return;
                            if (!groups[name]) groups[name] = [];
                            active = name; saveGroups(); fillGroups();
                            $('mt-picker').hidden = false; refreshView(false);
                        });
                        $('mt-rename').addEventListener('click', function () {
                            var name = (prompt(t('Nouveau nom du groupe :'), active) || '').trim().slice(0, 30);
                            if (!name || name === active || groups[name]) return;
                            groups[name] = groups[active]; delete groups[active]; active = name;
                            saveGroups(); fillGroups(); refreshView(false);
                        });
                        $('mt-delete').addEventListener('click', function () {
                            if (!active || !confirm(t('Supprimer le groupe « %s » ?', active))) return;
                            delete groups[active]; active = ''; saveGroups(); fillGroups(); refreshView(true);
                        });
                        $('mt-toggle-list').addEventListener('click', function () {
                            $('mt-picker').hidden = !$('mt-picker').hidden;
                            if (!$('mt-picker').hidden) drawList();
                        });
                        $('mt-search').addEventListener('input', drawList);
                        function addTyped() {
                            var c = norm($('mt-search').value), list = members();
                            if (!list || !/^[A-Z0-9]{1,9}(-[A-Z0-9]{1,2})?\*?$/.test(c)) return;
                            if (list.indexOf(c) < 0) list.push(c);
                            $('mt-search').value = ''; saveGroups(); fillGroups(); refreshView(true);
                        }
                        $('mt-add').addEventListener('click', addTyped);
                        $('mt-search').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addTyped(); } });
                        $('mt-all').addEventListener('click', function () {
                            var list = members(); if (!list) return;
                            data.stations.forEach(function (s) { if (list.indexOf(s.call) < 0 && (!$('mt-search').value || s.call.indexOf(norm($('mt-search').value)) >= 0)) list.push(s.call); });
                            saveGroups(); fillGroups(); refreshView(true);
                        });
                        $('mt-none').addEventListener('click', function () {
                            if (!active) return;
                            groups[active] = []; saveGroups(); fillGroups(); refreshView(false);
                        });
                        $('mt-trail').addEventListener('change', function () { store.set('trail', parseInt(this.value, 10)); load(); });
                        $('mt-ages').addEventListener('change', function () { store.set('ages', this.checked); refreshView(false); });
                        $('mt-follow').addEventListener('change', function () { store.set('follow', this.checked); refreshView(this.checked); });

                        // ---------- chargement et mise à jour automatique
                        var busy = false;
                        function load() {
                            if (busy) return;
                            busy = true;
                            fetch('map-data.php?hours=' + HOURS + '&trail=' + encodeURIComponent($('mt-trail').value), {cache: 'no-store'})
                                .then(function (r) { return r.json(); })
                                .then(function (d) {
                                    if (!d || !d.stations) throw 0;
                                    data = d; fetchedAt = Date.now();
                                    var b = draw();
                                    if (firstLoad) { firstLoad = false; if (!focusOn()) fit(b); }
                                    else if ($('mt-follow').checked) fit(b);
                                    if (!$('mt-picker').hidden) drawList();
                                    $('mt-status').textContent = t('Mise à jour automatique · %s', new Date().toLocaleTimeString(window.APRS_LANG === 'en' ? 'en-CA' : 'fr-CA', {hour12: false}));
                                })
                                .catch(function () { $('mt-status').textContent = t('Mise à jour impossible (nouvel essai dans 30 s)'); })
                                .then(function () { busy = false; });
                        }

                        // Lien « voir sur la carte » d'un paquet : centrer sur cette position
                        var focus = <?php echo json_encode(isset($_GET['lat'], $_GET['lon']) && is_numeric($_GET['lat']) && is_numeric($_GET['lon'])
                            ? ['lat' => (float)$_GET['lat'], 'lon' => (float)$_GET['lon'], 'label' => substr((string)($_GET['focus'] ?? ''), 0, 20)] : null); ?>;
                        function focusOn() {
                            if (!focus) return false;
                            map.setView([focus.lat, focus.lon], 13);
                            var m = markers[focus.label] && markers[focus.label].mk;
                            if (m && m.getLatLng().distanceTo([focus.lat, focus.lon]) < 50) m.openPopup();
                            else L.circleMarker([focus.lat, focus.lon], {radius: 9, color: '#e65100', weight: 3, fillOpacity: 0.2})
                                    .addTo(map).bindPopup('<strong>' + esc(focus.label) + '</strong>').openPopup();
                            return true;
                        }

                        fillGroups();
                        load();
                        setInterval(function () { if (!document.hidden) load(); }, REFRESH_MS);
                        setInterval(function () { if (!document.hidden) updateAges(); }, 15000);
                    })();
                    </script>
<?php
}
