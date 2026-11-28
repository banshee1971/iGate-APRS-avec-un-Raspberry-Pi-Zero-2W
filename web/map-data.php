<?php
// /map-data.php — positions des stations pour la carte (JSON, lecture seule).
//   hours : stations entendues dans les N dernières heures (1 à 168)
//   trail : durée des trajets en minutes (0 = aucun, 1440 max)
// Rechargé par la carte toutes les 30 secondes pour suivre les stations.
require __DIR__ . '/lib/settings.php';
require __DIR__ . '/lib/packets.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$hours = max(1, min(168, intval($_GET['hours'] ?? 24)));
$trail = max(0, min(1440, intval($_GET['trail'] ?? 30)));
$trail = min($trail, $hours * 60);
const TRACK_MAX_POINTS = 300;   // par station : assez pour 24 h de balises aux 5 minutes

try {
    $db = new SQLite3(APRS_DB_PATH, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(3000);
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => 'db']);
    exit;
}

// Dernière position de chaque station (l'id le plus grand = le paquet le plus récent)
$stmt = $db->prepare("SELECT p.callsign, p.latitude, p.longitude,
                             CAST(strftime('%s', p.timestamp) AS INTEGER) AS ts, p.decoded_info, m.n
                      FROM packets p
                      JOIN (SELECT callsign, MAX(id) AS mid, COUNT(*) AS n FROM packets
                            WHERE latitude IS NOT NULL AND longitude IS NOT NULL
                              AND timestamp > datetime('now', ?)
                            GROUP BY callsign) m ON p.id = m.mid
                      ORDER BY ts DESC");
$stmt->bindValue(1, '-' . $hours . ' hours');
$res = $stmt->execute();
$stations = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $d = $row['decoded_info'] ? (json_decode($row['decoded_info'], true) ?: []) : [];
    $sym = aprs_tr_symbol($d['symbol_name'] ?? '');
    $stations[$row['callsign']] = [
        'call'    => $row['callsign'],
        'lat'     => round((float)$row['latitude'], 5),
        'lon'     => round((float)$row['longitude'], 5),
        'ts'      => (int)$row['ts'],
        'n'       => (int)$row['n'],
        'sym'     => $sym,
        'icon'    => aprs_symbol_html($d['table'] ?? '/', $d['code'] ?? '/', $d['emoji'] ?? '📍', $sym),
        'comment' => (string)($d['comment'] ?? ''),
        'track'   => [],
    ];
}

// Trajets : positions successives dans la fenêtre demandée ; une station
// immobile qui répète sa position ne garde qu'un point (heure la plus récente)
if ($trail > 0 && $stations) {
    $stmt = $db->prepare("SELECT callsign, latitude, longitude, CAST(strftime('%s', timestamp) AS INTEGER) AS ts
                          FROM packets
                          WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND timestamp > datetime('now', ?)
                          ORDER BY id");
    $stmt->bindValue(1, '-' . $trail . ' minutes');
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $c = $row['callsign'];
        if (!isset($stations[$c])) continue;
        $pt = [round((float)$row['latitude'], 5), round((float)$row['longitude'], 5), (int)$row['ts']];
        $track = &$stations[$c]['track'];
        $n = count($track);
        if ($n && $track[$n - 1][0] === $pt[0] && $track[$n - 1][1] === $pt[1]) {
            $track[$n - 1][2] = $pt[2];
        } else {
            $track[] = $pt;
            if ($n + 1 > TRACK_MAX_POINTS) array_shift($track);
        }
        unset($track);
    }
}
$db->close();

echo json_encode([
    'now'      => time(),
    'hours'    => $hours,
    'trail'    => $trail,
    'stations' => array_values($stations),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
