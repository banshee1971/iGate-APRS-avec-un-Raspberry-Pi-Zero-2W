<?php
// /admin/api.php?what=metrics|direwolf — données JSON des pages Système et Direwolf.
// Les compteurs bruts (jiffies CPU, octets réseau) sont envoyés tels quels :
// le navigateur calcule les pourcentages et débits entre deux lectures.
require __DIR__ . '/../lib/admin.php';
aprs_require_login(true);
session_write_close();   // ne pas bloquer les autres onglets pendant la lecture

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$what = $_GET['what'] ?? '';
if ($what === 'metrics') {
    echo json_encode(api_metrics(), JSON_UNESCAPED_UNICODE);
} elseif ($what === 'direwolf') {
    echo json_encode(api_direwolf(), JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'inconnu']);
}

function read_file($path) {
    return is_readable($path) ? @file_get_contents($path) : false;
}

// =================================================================== Système
function api_metrics() {
    $out = ['time' => microtime(true), 'hz' => 100];

    // CPU : une ligne par cœur dans /proc/stat (user nice system idle iowait irq softirq steal)
    $out['cpu'] = [];
    foreach (explode("\n", (string)read_file('/proc/stat')) as $line) {
        if (preg_match('/^(cpu\d*)\s+(.*)$/', $line, $m)) {
            $v = array_map('intval', preg_split('/\s+/', trim($m[2])));
            $idle = ($v[3] ?? 0) + ($v[4] ?? 0);
            $total = array_sum(array_slice($v, 0, 8));
            $out['cpu'][] = ['name' => $m[1], 'idle' => $idle, 'total' => $total];
        }
    }
    $out['load'] = array_map('floatval', array_slice(explode(' ', (string)read_file('/proc/loadavg')), 0, 3));
    $out['uptime'] = intval((float)read_file('/proc/uptime'));

    $freq = read_file('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq');
    $out['freq_mhz'] = $freq !== false ? round(intval($freq) / 1000) : null;
    $temp = read_file('/sys/class/thermal/thermal_zone0/temp');
    $out['temp_c'] = $temp !== false ? round(intval($temp) / 1000, 1) : null;

    // Alimentation du Pi (sous-tension = cause n°1 de problèmes avec un RTL-SDR)
    $thr = read_file('/sys/devices/platform/soc/soc:firmware/get_throttled');
    if ($thr !== false) {
        $t = hexdec(trim($thr));
        $out['throttled'] = [
            'undervolt_now' => (bool)($t & 0x1),
            'throttled_now' => (bool)($t & 0x4),
            'undervolt_since_boot' => (bool)($t & 0x10000),
            'throttled_since_boot' => (bool)($t & 0x40000),
        ];
    }

    // Mémoire (en kio)
    $mem = [];
    foreach (explode("\n", (string)read_file('/proc/meminfo')) as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) $mem[$m[1]] = intval($m[2]);
    }
    $out['mem'] = [
        'total' => $mem['MemTotal'] ?? 0,
        'available' => $mem['MemAvailable'] ?? 0,
        'free' => $mem['MemFree'] ?? 0,
        'cached' => ($mem['Cached'] ?? 0) + ($mem['Buffers'] ?? 0) + ($mem['SReclaimable'] ?? 0),
        'swap_total' => $mem['SwapTotal'] ?? 0,
        'swap_free' => $mem['SwapFree'] ?? 0,
    ];

    // Disques : systèmes de fichiers réels montés (carte SD, clé USB)
    $out['disks'] = [];
    $seen = [];
    foreach (explode("\n", (string)read_file('/proc/mounts')) as $line) {
        $p = explode(' ', $line);
        if (count($p) < 3 || strpos($p[0], '/dev/') !== 0 || isset($seen[$p[0]])) continue;
        $mnt = str_replace('\040', ' ', $p[1]);
        $total = @disk_total_space($mnt);
        $free = @disk_free_space($mnt);
        if (!$total) continue;
        $seen[$p[0]] = true;
        $out['disks'][] = ['mount' => $mnt, 'dev' => basename($p[0]), 'fs' => $p[2],
                           'total' => $total, 'used' => $total - $free, 'free' => $free];
    }

    // Réseau : octets reçus/envoyés par interface
    $out['net'] = [];
    foreach (array_slice(explode("\n", (string)read_file('/proc/net/dev')), 2) as $line) {
        if (!preg_match('/^\s*([^:]+):\s*(.*)$/', $line, $m) || trim($m[1]) === 'lo') continue;
        $v = preg_split('/\s+/', trim($m[2]));
        $out['net'][] = ['name' => trim($m[1]), 'rx' => (float)$v[0], 'tx' => (float)$v[8]];
    }

    // Processus : temps CPU cumulé (le navigateur calcule le % entre deux lectures)
    $out['procs'] = [];
    $users = [];
    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
        $stat = read_file("$dir/stat");
        if ($stat === false) continue;
        $l = strpos($stat, '(');
        $r = strrpos($stat, ')');
        $comm = substr($stat, $l + 1, $r - $l - 1);
        $f = explode(' ', substr($stat, $r + 2));
        // f[11]=utime f[12]=stime f[21]=rss (pages), voir « man proc »
        $uid = @fileowner($dir);
        if (!isset($users[$uid])) {
            $pw = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
            $users[$uid] = $pw ? $pw['name'] : (string)$uid;
        }
        $out['procs'][] = [
            'pid' => intval(basename($dir)), 'name' => $comm, 'user' => $users[$uid],
            'ticks' => intval($f[11] ?? 0) + intval($f[12] ?? 0),
            'rss' => intval($f[21] ?? 0) * 4,   // kio (pages de 4 kio)
        ];
    }
    return $out;
}

// ================================================================== Direwolf
function api_direwolf() {
    $s = aprs_load_settings();
    $out = ['service' => aprs_svc_state('aprs-igate.service'), 'dongle' => aprs_dongle_present(),
            'sdr' => aprs_sdr_devices()];

    $show = aprs_shell('systemctl show aprs-igate.service -p ActiveEnterTimestamp -p NRestarts -p MainPID');
    foreach (explode("\n", (string)$show) as $line) {
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $out['unit'][$k] = $v;
        }
    }
    $since = strtotime($out['unit']['ActiveEnterTimestamp'] ?? '');
    $out['since'] = ($since && $out['service'] === 'active') ? $since : null;

    // Journal de l'iGate (www-data est dans le groupe systemd-journal)
    $raw = aprs_shell('journalctl -u aprs-igate.service -n 400 --no-pager -o short-iso');
    $out['journal_ok'] = $raw !== null && trim($raw) !== '' && strpos($raw, 'No journal files') === false;
    // Le tuner, la version et la connexion APRS-IS ne sont annoncés qu'au démarrage :
    // on relit aussi le début du journal depuis le dernier démarrage du service.
    $startup = '';
    if ($out['since']) {
        $startup = (string)aprs_shell('journalctl -u aprs-igate.service --no-pager -o short-iso --since @'
            . intval($out['since'] - 5) . ' | head -n 150');   // @epoch : pas de souci de fuseau horaire
    }
    $lines = [];
    $levels = [];
    $is = ['state' => 'unknown', 'line' => null, 'time' => null, 'verified' => null];
    $version = null;
    $tuner_gain = null;
    $tuner = null;         // puce du tuner annoncée par rtl_fm au démarrage
    $blog_v4 = false;
    $sdr_error = null;     // dernier problème d'accès au dongle
    $all_lines = array_merge(
        array_map(function ($l) { return [false, $l]; }, explode("\n", $startup)),
        array_map(function ($l) { return [true, $l]; }, explode("\n", (string)$raw))
    );
    foreach ($all_lines as [$recent, $line]) {
        if (!preg_match('/^(\S+)\s+\S+\s+[^:]+:\s?(.*)$/', $line, $m)) continue;
        [$all, $ts, $msg] = $m;
        $t = strtotime($ts);
        if ($recent) $lines[] = ['t' => $t, 'm' => $msg];
        if ($recent && preg_match('/audio level = (\d+)\((\d+)\/(\d+)\)/', $msg, $a)) {
            $levels[] = ['t' => $t, 'level' => intval($a[1]), 'mark' => intval($a[2]), 'space' => intval($a[3])];
        }
        if (preg_match('/Dire ?Wolf (?:DEVELOPMENT )?version ([\w.\- ]+)/i', $msg, $v)) $version = trim($v[1]);
        if (preg_match('/Tuner gain set to ([\d.]+) dB/i', $msg, $g)) $tuner_gain = floatval($g[1]);
        if (preg_match('/Tuner gain set to automatic/i', $msg)) $tuner_gain = 'auto';
        if (preg_match('/Found (.+?) tuner/i', $msg, $tn)) { $tuner = trim($tn[1]); $sdr_error = null; }
        if (preg_match('/RTL-SDR Blog V4 Detected/i', $msg)) $blog_v4 = true;
        if (preg_match('/^Found \d+ device|Using device/i', $msg)) $sdr_error = null;
        if (preg_match('/No supported devices found/i', $msg)) {
            $sdr_error = 'rtl_fm ne trouve aucun dongle (débranché ou mal alimenté ?).';
        } elseif (preg_match('/usb_claim_interface error|Kernel driver is active|device or resource busy/i', $msg)) {
            $sdr_error = 'Dongle occupé : le pilote DVB du noyau ou un autre programme le bloque (redémarrez le Pi).';
        } elseif (preg_match('/Failed to open rtlsdr device/i', $msg)) {
            $sdr_error = "rtl_fm n'arrive pas à ouvrir le dongle (droits USB, pilote DVB ou autre programme).";
        }
        if (preg_match('/logresp \S+ (verified|unverified)/i', $msg, $v)) {
            $is['verified'] = strtolower($v[1]) === 'verified';
        }
        if (preg_match('/now connected to igate server|connected to igate server/i', $msg)
            && !preg_match('/dis(connected)|unable|could not|not connected/i', $msg)) {
            $is = ['state' => 'connected', 'line' => $msg, 'time' => $t, 'verified' => $is['verified']];
        } elseif (preg_match('/igate server|aprs-?is/i', $msg)
            && preg_match('/disconnect|unable to connect|could not connect|lost|closed|error|timeout/i', $msg)) {
            $is = ['state' => 'disconnected', 'line' => $msg, 'time' => $t, 'verified' => null];
        }
    }
    $out['version'] = $version;
    $out['tuner_gain'] = $tuner_gain;
    $out['tuner'] = $tuner;
    $out['blog_v4_driver'] = $blog_v4;
    $out['sdr_error'] = $sdr_error;
    $out['aprsis'] = $is;
    $out['levels'] = array_slice($levels, -40);
    $out['log'] = array_slice($lines, -150);

    // Statistiques de la base
    $out['stats'] = null;
    if (is_readable(APRS_DB_PATH)) {
        try {
            $db = new SQLite3(APRS_DB_PATH, SQLITE3_OPEN_READONLY);
            $db->busyTimeout(2000);
            $q = function ($sql) use ($db) { return $db->querySingle($sql); };
            $hours = array_fill(0, 24, 0);
            $res = $db->query("SELECT CAST((strftime('%s','now') - strftime('%s', timestamp)) / 3600 AS INTEGER) h, COUNT(*) c
                               FROM packets WHERE timestamp > datetime('now', '-24 hours') GROUP BY h");
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                if ($row['h'] >= 0 && $row['h'] < 24) $hours[23 - $row['h']] = $row['c'];
            }
            $last = $q("SELECT strftime('%s', MAX(timestamp)) FROM packets");
            $out['stats'] = [
                'p15m' => $q("SELECT COUNT(*) FROM packets WHERE timestamp > datetime('now', '-15 minutes')"),
                'p1h' => $q("SELECT COUNT(*) FROM packets WHERE timestamp > datetime('now', '-1 hour')"),
                'p24h' => array_sum($hours),
                'st1h' => $q("SELECT COUNT(DISTINCT callsign) FROM packets WHERE timestamp > datetime('now', '-1 hour')"),
                'st24h' => $q("SELECT COUNT(DISTINCT callsign) FROM packets WHERE timestamp > datetime('now', '-24 hours')"),
                'last' => $last ? intval($last) : null,
                'hours' => $hours,
            ];
            $db->close();
        } catch (Exception $e) {
            $out['stats'] = null;
        }
    }

    $out['settings'] = [
        'freq' => $s['freq'], 'gain' => $s['rtl_agc'] ? 'auto' : $s['gain'], 'ppm' => $s['ppm'],
        'rate' => $s['rtl_sample_rate'], 'profile' => $s['dw_modem_profile'] ?: 'défaut',
        'fix_bits' => $s['dw_fix_bits'],
    ];
    $out['now'] = time();
    return $out;
}
