<?php
// Profils réseau (page /admin -> Réseau). Appliqués en root par
// /usr/local/sbin/aprs-network, qui revalide tout.
require_once __DIR__ . '/settings.php';

const APRS_NET_PROFILES = APRS_STATE_DIR . '/network-profiles.json';
const APRS_NET_STATE    = APRS_STATE_DIR . '/network-state.json';
const APRS_NET_PENDING  = APRS_STATE_DIR . '/network-pending.json';
const APRS_NET_CONFIRM  = APRS_STATE_DIR . '/network-confirm';

function aprs_net_origin() {
    return ['id' => 'origine', 'name' => 'Origine (installation)', 'builtin' => true,
            'eth' => ['mode' => 'dhcp'], 'wifi' => ['mode' => 'existing']];
}

/** ['profiles' => [...], 'fallback' => id|''] — avec le profil Origine garanti. */
function aprs_net_load() {
    $data = is_readable(APRS_NET_PROFILES) ? (json_decode((string)file_get_contents(APRS_NET_PROFILES), true) ?: []) : [];
    $profiles = array_values(array_filter($data['profiles'] ?? [], 'is_array'));
    if (!in_array('origine', array_column($profiles, 'id'), true)) {
        array_unshift($profiles, aprs_net_origin());
    }
    return ['profiles' => $profiles, 'fallback' => (string)($data['fallback'] ?? '')];
}

function aprs_net_save(array $data) {
    $tmp = APRS_NET_PROFILES . '.tmp';
    $json = json_encode(['profiles' => array_values($data['profiles']), 'fallback' => $data['fallback']],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json . "\n") === false) return false;
    @chmod($tmp, 0600);   // contient les mots de passe Wi-Fi
    return rename($tmp, APRS_NET_PROFILES);
}

function aprs_net_find(array $data, $id) {
    foreach ($data['profiles'] as $p) {
        if ($p['id'] === $id) return $p;
    }
    return null;
}

function aprs_net_state() {
    return is_readable(APRS_NET_STATE) ? (json_decode((string)file_get_contents(APRS_NET_STATE), true) ?: []) : [];
}

function aprs_net_pending() {
    return is_readable(APRS_NET_PENDING) ? json_decode((string)file_get_contents(APRS_NET_PENDING), true) : null;
}

// ------------------------------------------------------------- validation
function aprs_net_ip($v, $cidr) {
    $v = trim((string)$v);
    if ($cidr) {
        if (!preg_match('~^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$~', $v, $m) || $m[2] < 1 || $m[2] > 32) return null;
        return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $v : null;
    }
    return filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $v : null;
}

function aprs_net_text($v, $min, $max) {
    $v = (string)$v;
    return (strlen($v) >= $min && strlen($v) <= $max && !preg_match('/[\x00-\x1f\x7f]/', $v)) ? $v : null;
}

/** Profil depuis le formulaire. Retourne [profil, erreurs]. */
function aprs_net_from_post(array $in, $id, $builtin = false) {
    $e = [];
    $p = ['id' => $id, 'builtin' => $builtin];
    $p['name'] = aprs_net_text(trim($in['name'] ?? ''), 1, 40) ?? ($e['name'] = 'Nom requis (40 caractères maximum).');
    $dns_ok = function ($v) {
        foreach (preg_split('/[\s,]+/', trim((string)$v), -1, PREG_SPLIT_NO_EMPTY) as $d) {
            if (!aprs_net_ip($d, false)) return false;
        }
        return true;
    };

    $eth = ['mode' => in_array($in['eth_mode'] ?? '', ['dhcp', 'static', 'off'], true) ? $in['eth_mode'] : 'dhcp'];
    if ($eth['mode'] === 'static') {
        $eth['address'] = aprs_net_ip($in['eth_address'] ?? '', true) ?? ($e['eth_address'] = 'Format : 192.168.1.50/24');
        $eth['gateway'] = trim($in['eth_gateway'] ?? '');
        if ($eth['gateway'] !== '' && !aprs_net_ip($eth['gateway'], false)) $e['eth_gateway'] = 'Adresse invalide.';
        $eth['dns'] = trim($in['eth_dns'] ?? '');
        if (!$dns_ok($eth['dns'])) $e['eth_dns'] = 'Adresses séparées par des espaces.';
    }
    $p['eth'] = $eth;

    $wmode = in_array($in['wifi_mode'] ?? '', ['existing', 'client', 'ap', 'off'], true) ? $in['wifi_mode'] : 'existing';
    $wifi = ['mode' => $wmode];
    if ($wmode === 'client') {
        $wifi['ssid'] = aprs_net_text($in['wifi_ssid'] ?? '', 1, 32) ?? ($e['wifi_ssid'] = 'Nom du réseau requis (32 caractères maximum).');
        $pw = (string)($in['wifi_password'] ?? '');
        if ($pw === '' && !empty($in['_keep_wifi_password'])) $pw = $in['_keep_wifi_password'];
        $wifi['password'] = $pw === '' ? '' : (aprs_net_text($pw, 8, 63) ?? ($e['wifi_password'] = '8 à 63 caractères (vide = réseau ouvert).'));
        $wifi['ip'] = ($in['wifi_ip'] ?? 'dhcp') === 'static' ? 'static' : 'dhcp';
        if ($wifi['ip'] === 'static') {
            $wifi['address'] = aprs_net_ip($in['wifi_address'] ?? '', true) ?? ($e['wifi_address'] = 'Format : 192.168.1.51/24');
            $wifi['gateway'] = trim($in['wifi_gateway'] ?? '');
            if ($wifi['gateway'] !== '' && !aprs_net_ip($wifi['gateway'], false)) $e['wifi_gateway'] = 'Adresse invalide.';
            $wifi['dns'] = trim($in['wifi_dns'] ?? '');
            if (!$dns_ok($wifi['dns'])) $e['wifi_dns'] = 'Adresses séparées par des espaces.';
        }
    } elseif ($wmode === 'ap') {
        $wifi['ssid'] = aprs_net_text($in['ap_ssid'] ?? '', 1, 32) ?? ($e['ap_ssid'] = 'Nom de la borne requis (32 caractères maximum).');
        $pw = (string)($in['ap_password'] ?? '');
        if ($pw === '' && !empty($in['_keep_ap_password'])) $pw = $in['_keep_ap_password'];
        $wifi['password'] = aprs_net_text($pw, 8, 63) ?? ($e['ap_password'] = '8 à 63 caractères (obligatoire).');
        $wifi['channel'] = in_array((int)($in['ap_channel'] ?? 6), [1, 6, 11], true) ? (int)$in['ap_channel'] : 6;
    }
    $p['wifi'] = $wifi;
    if ($eth['mode'] === 'off' && in_array($wmode, ['off'], true)) {
        $e['wifi_mode'] = 'Au moins un accès réseau doit rester actif.';
    }
    return [$p, $e];
}

/** Résumé lisible d'un profil. */
function aprs_net_summary(array $p) {
    $eth_labels = ['dhcp' => 'Ethernet : DHCP', 'off' => 'Ethernet : désactivé'];
    $eth = $eth_labels[$p['eth']['mode']] ?? ('Ethernet : ' . ($p['eth']['address'] ?? '?'));
    $w = $p['wifi'];
    switch ($w['mode']) {
        case 'client': $wifi = 'Wi-Fi : réseau « ' . $w['ssid'] . ' »' . (($w['ip'] ?? '') === 'static' ? ' (' . $w['address'] . ')' : ''); break;
        case 'ap':     $wifi = '📶 Borne Wi-Fi « ' . $w['ssid'] . ' » (10.42.0.1)'; break;
        case 'off':    $wifi = 'Wi-Fi : désactivé'; break;
        default:       $wifi = 'Wi-Fi : réseau configuré à l\'installation';
    }
    return $eth . ' · ' . $wifi;
}

// ------------------------------------------------------------ état actuel
/** Cartes réseau vues par NetworkManager : [device, type, state, connection, ip]. */
function aprs_net_devices() {
    $out = [];
    $raw = function_exists('shell_exec') ? @shell_exec('nmcli -t -f DEVICE,TYPE,STATE,CONNECTION device 2>/dev/null') : null;
    foreach (explode("\n", trim((string)$raw)) as $line) {
        $f = preg_split('/(?<!\\\\):/', $line);
        if (count($f) < 4 || in_array($f[1], ['loopback', 'wifi-p2p', 'bridge'], true)) continue;
        $ip = trim((string)@shell_exec('ip -4 -o addr show dev ' . escapeshellarg($f[0]) . " 2>/dev/null | awk '{print \$4}' | head -n1"));
        $out[] = ['device' => $f[0], 'type' => $f[1], 'state' => $f[2], 'connection' => str_replace('\\:', ':', $f[3]), 'ip' => $ip];
    }
    return $out;
}
