<?php
// Affichage « lisible » d'un paquet APRS décodé par le parser (colonne decoded_info).

/**
 * Icône APRS officielle (jeu d'aprs.fi, téléchargé par install.sh), avec le
 * caractère de surimpression éventuel. Sans les images : l'emoji de repli.
 */
function aprs_symbol_html($table, $code, $fallback = '📍', $title = '') {
    static $have = null;
    if ($have === null) $have = is_file('/var/www/aprs/vendor/aprs-symbols/aprs-symbols-24-2@2x.png');
    $t = (string)$table; $c = (string)$code;
    if (!$have || strlen($c) !== 1 || strlen($t) !== 1 || ord($c) < 33 || ord($c) > 126) {
        return '<span class="pk-emoji" title="' . h($title) . '">' . $fallback . '</span>';
    }
    $pos = function ($ch) {
        $i = ord($ch) - 33;
        return '-' . (($i % 16) * 24) . 'px -' . (intdiv($i, 16) * 24) . 'px';
    };
    $sheet = $t === '/' ? 0 : 1;
    $html = '<span class="aprs-sym" title="' . h($title) . '" style="background-image:url(/vendor/aprs-symbols/aprs-symbols-24-'
          . $sheet . '@2x.png);background-position:' . $pos($c) . '">';
    if ($t !== '/' && $t !== '\\' && ord($t) >= 33 && ord($t) <= 126) {
        $html .= '<i style="background-position:' . $pos($t) . '"></i>';   // surimpression (A-Z, 0-9)
    }
    return $html . '</span>';
}

function aprs_compass($deg) {
    $dirs = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSO', 'SO', 'OSO', 'O', 'ONO', 'NO', 'NNO'];
    return t($dirs[intval(round(fmod($deg + 360, 360) / 22.5)) % 16]);
}

/**
 * Nom de symbole produit par le parser (en français dans la base), traduit à
 * l'affichage : « Voiture », « Symbole \x », « Voiture (surimpression A) ».
 */
function aprs_tr_symbol($name) {
    $name = (string)$name;
    if ($name === '') return '';
    if (preg_match('/^(.*) \(surimpression (.)\)$/su', $name, $m)) {
        return t('%s (surimpression %s)', aprs_tr_symbol($m[1]), $m[2]);
    }
    if (preg_match('/^Symbole (.+)$/su', $name, $m)) {
        return t('Symbole %s', $m[1]);
    }
    return t($name);
}

/** Statut Mic-E du parser (« En route », « Personnalisé 3 »…), traduit. */
function aprs_tr_status($s) {
    $s = (string)$s;
    if (preg_match('/^Personnalisé (\d+)$/u', $s, $m)) {
        return t('Personnalisé %s', $m[1]);
    }
    return t($s);
}

/** Heure indiquée par la station (« le 12 à 14:30 UTC »…), traduite. */
function aprs_tr_time($s) {
    $s = (string)$s;
    if (preg_match('/^le (\d{2}) à (\d{2}:\d{2}) UTC$/u', $s, $m)) {
        return t('le %s à %s UTC', $m[1], $m[2]);
    }
    if (preg_match('/^le (\d{2}) à (\d{2}:\d{2}) \(heure locale\)$/u', $s, $m)) {
        return t('le %s à %s (heure locale)', $m[1], $m[2]);
    }
    return $s;
}

/** Distance (km) et cap (degrés) de (lat1, lon1) vers (lat2, lon2). */
function aprs_distance($lat1, $lon1, $lat2, $lon2) {
    $r = 6371.0;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1); $dl = deg2rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    $dist = 2 * $r * asin(min(1, sqrt($a)));
    $y = sin($dl) * cos($p2);
    $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
    return [$dist, fmod(rad2deg(atan2($y, $x)) + 360, 360)];
}

function aprs_num($v, $dec = 1) {
    if (aprs_lang() === 'en') return number_format((float)$v, $dec, '.', ',');
    return number_format((float)$v, $dec, ',', ' ');
}

function aprs_map_link($lat, $lon, $label) {
    $q = http_build_query(['tab' => 'map', 'hours' => 168, 'focus' => $label, 'lat' => $lat, 'lon' => $lon]);
    return '<a class="pk-maplink" href="?' . h($q) . '">🗺️ ' . th('voir sur la carte') . '</a>';
}

/** Ligne de position : coordonnées, distance et direction depuis l'iGate. */
function aprs_position_html($d, array $S, $label) {
    $lat = (float)$d['lat']; $lon = (float)$d['lon'];
    $html = '📍 <strong>' . aprs_num($lat, 5) . ', ' . aprs_num($lon, 5) . '</strong>';
    if (!empty($d['ambiguity'])) {
        $html .= ' <span class="pk-muted">' . th('(position approximative)') . '</span>';
    }
    if (aprs_is_configured($S)) {
        [$km, $brg] = aprs_distance((float)$S['lat'], (float)$S['lon'], $lat, $lon);
        $html .= ' · ' . th('%s km au %s de l\'iGate', ($km < 10 ? aprs_num($km, 1) : aprs_num($km, 0)), aprs_compass($brg));
    }
    return $html . ' · ' . aprs_map_link($lat, $lon, $label);
}

/** HTML complet d'un paquet de la liste. */
function aprs_packet_html(array $row, array $S) {
    $d = $row['decoded_info'] ? json_decode($row['decoded_info'], true) : null;
    if (!is_array($d)) {
        $d = ['type' => 'unknown', 'label' => 'Paquet'];
    }
    $call = $d['src'] ?? $row['callsign'];
    $type_icons = ['message' => '✉️', 'status' => '💬', 'telemetry' => '📈', 'capabilities' => 'ℹ️', 'weather' => '⛅'];
    $emoji = $d['emoji'] ?? ($type_icons[$d['type']] ?? '📦');
    $lines = [];

    // Symbole et appareil
    $who = [];
    if (!empty($d['symbol_name'])) $who[] = h(aprs_tr_symbol($d['symbol_name']));
    if (!empty($d['device']) && $d['device'] !== 'générique') $who[] = '📟 ' . h(t($d['device']));
    if (!empty($d['status'])) $who[] = th('statut Mic-E :') . ' <strong>' . h(aprs_tr_status($d['status'])) . '</strong>';
    if ($who) $lines[] = implode(' · ', $who);

    // Objet / item
    if (!empty($d['object'])) {
        $o = $d['object'];
        $lines[] = '🏷️ ' . h(t($d['label'])) . ' <strong>' . h($o['name']) . '</strong>'
                 . ($o['live'] ? '' : ' <span class="pk-muted">' . th('(supprimé)') . '</span>') . ' ' . th('annoncé par %s', $call);
    }

    // Position
    if (isset($d['lat'], $d['lon'])) {
        $lines[] = aprs_position_html($d, $S, !empty($d['object']) ? $d['object']['name'] : $call);
    }

    // Déplacement
    $mv = [];
    if (isset($d['course'])) $mv[] = '🧭 ' . th('cap %d° (%s)', intval($d['course']), aprs_compass($d['course']));
    if (isset($d['speed_kmh'])) $mv[] = '🚀 ' . aprs_num($d['speed_kmh'], $d['speed_kmh'] < 10 ? 1 : 0) . ' km/h';
    if (isset($d['altitude_m'])) $mv[] = '🏔️ ' . th('altitude %s m', aprs_num($d['altitude_m'], 0));
    if (isset($d['range_km'])) $mv[] = '📶 ' . th('portée %s km', aprs_num($d['range_km'], 0));
    if ($mv) $lines[] = implode(' · ', $mv);

    // Station (PHG)
    if (!empty($d['phg'])) {
        $p = $d['phg'];
        $lines[] = '📡 ' . th('%d W · antenne à %d m · gain %d dB · %s', intval($p['power_w']), intval($p['height_m']),
                                intval($p['gain_db']), t((string)$p['direction']));
    }

    // Météo
    if (!empty($d['weather'])) {
        $w = $d['weather']; $wx = [];
        if (isset($w['temp_c'])) $wx[] = '🌡️ <strong>' . aprs_num($w['temp_c']) . ' °C</strong>';
        if (isset($w['humidity'])) $wx[] = '💧 ' . intval($w['humidity']) . ' %';
        if (isset($w['wind_kmh'])) {
            $wind = '🌬️ ' . th('vent %d km/h', intval($w['wind_kmh']));
            if (isset($w['wind_dir']) && $w['wind_kmh'] > 0) $wind .= ' ' . th('du %s', aprs_compass($w['wind_dir']));
            if (!empty($w['gust_kmh'])) $wind .= ', ' . th('rafales %d km/h', intval($w['gust_kmh']));
            $wx[] = $wind;
        }
        if (isset($w['pressure_hpa'])) $wx[] = '🔽 ' . aprs_num($w['pressure_hpa']) . ' hPa';
        if (isset($w['rain_1h_mm'])) $wx[] = '☔ ' . aprs_num($w['rain_1h_mm']) . ' mm (1 h)';
        if (isset($w['rain_today_mm'])) $wx[] = th('%s mm aujourd\'hui', aprs_num($w['rain_today_mm']));
        if (isset($w['luminosity'])) $wx[] = '☀️ ' . intval($w['luminosity']) . ' W/m²';
        if ($wx) $lines[] = implode(' · ', $wx);
    }

    // Message
    if (!empty($d['message'])) {
        $m = $d['message'];
        if ($m['kind'] === 'ack' || $m['kind'] === 'rej') {
            // Format traduit (texte fixe) ; les valeurs sont échappées avant insertion.
            $lines[] = ($m['kind'] === 'ack' ? '✅ ' : '⛔ ')
                     . t('%s pour %s (message n° %s)', h(t($d['label'])), '<strong>' . h($m['to']) . '</strong>',
                         h(substr($m['text'], 3)));
        } else {
            $lines[] = '✉️ ' . t('%s à %s : %s', h(t($d['label'])), '<strong>' . h($m['to']) . '</strong>',
                                 '<span class="pk-msg">' . t('« %s »', h($m['text'])) . '</span>')
                     . ($m['id'] ? ' <span class="pk-muted">' . th('(n° %s)', $m['id']) . '</span>' : '');
        }
    }

    // Télémétrie
    if (!empty($d['telemetry'])) {
        $t = $d['telemetry'];
        $lines[] = '📈 ' . th('Séquence %s', $t['seq']) . ' · '
                 . th('valeurs : %s', implode(' · ', array_filter($t['values'], 'strlen')))
                 . ($t['bits'] ? ' · bits ' . h($t['bits']) : '');
    }

    // Commentaire / statut
    if (!empty($d['comment'])) {
        $icon = $d['type'] === 'status' ? '💬 ' . th('Statut :') . ' ' : ($d['type'] === 'capabilities' ? 'ℹ️ ' : '💬 ');
        $lines[] = $icon . '<span class="pk-comment">' . h($d['comment']) . '</span>';
    }
    if (!empty($d['time'])) $lines[] = '<span class="pk-muted">⏱️ ' . th('heure indiquée par la station : %s', aprs_tr_time($d['time'])) . '</span>';
    if (!empty($d['third_party'])) $lines[] = '<span class="pk-muted">↪️ ' . th('relayé (paquet tiers) par %s', $d['third_party']['via']) . '</span>';

    // Réception
    if (!empty($d['direct'])) {
        $rx = '<span class="pk-tag pk-direct" title="' . th('Reçu directement par l\'iGate, sans digipeater') . '">📡 ' . th('direct') . '</span>';
    } elseif (!empty($d['via'])) {
        $rx = '<span class="pk-tag pk-via" title="' . th('Relayé par un digipeater avant d\'arriver à l\'iGate') . '">🔁 via ' . h(t($d['via'])) . '</span>';
    } else {
        $rx = '';
    }

    ob_start(); ?>
    <div class="packet-item pk-<?php echo h($d['type']); ?>">
        <div class="packet-header">
            <span>
                <?php echo isset($d['table'], $d['code'])
                    ? aprs_symbol_html($d['table'], $d['code'], $emoji, aprs_tr_symbol($d['symbol_name'] ?? ''))
                    : '<span class="pk-emoji">' . $emoji . '</span>'; ?>
                <a class="packet-callsign" href="?<?php echo h(http_build_query(['tab' => 'packets', 'callsign' => $row['callsign'], 'date' => ''])); ?>"
                   title="<?php echo th('Tous les paquets de %s', $row['callsign']); ?>"><?php echo h($row['callsign']); ?></a>
                <span class="pk-tag pk-type"><?php echo h(t($d['label'] ?? 'Paquet')); ?></span>
                <?php echo $rx; ?>
            </span>
            <span class="packet-time"><?php echo h($row['timestamp']); ?></span>
        </div>
        <?php if ($lines): ?>
            <div class="pk-lines"><?php foreach ($lines as $l) echo '<div>' . $l . '</div>'; ?></div>
        <?php endif; ?>
        <details class="pk-raw">
            <summary><?php echo th('Paquet brut'); ?></summary>
            <div class="packet-raw"><?php echo h($row['raw_packet']); ?></div>
        </details>
    </div>
    <?php
    return ob_get_clean();
}
