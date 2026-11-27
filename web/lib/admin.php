<?php
// Éléments communs des pages /admin : connexion obligatoire, en-tête avec
// onglets, formulaire généré à partir de settings.schema.json.
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/auth.php';

const APRS_ADMIN_TABS = [
    'config'   => ['./',             '⚙️ Configuration'],
    'reporting'=> ['reporting.php',  '📡 Reporting aprs.fi'],
    'direwolf' => ['direwolf.php',   '🐺 Direwolf'],
    'network'  => ['network.php',    '🌐 Réseau'],
    'updates'  => ['updates.php',    '🔄 Mises à jour'],
    'system'   => ['system.php',     '📊 Système'],
    'account'  => ['account.php',    '👤 Compte'],
];

function aprs_admin_header($title, $active, array $opts = []) {
    $s = aprs_load_settings();
    aprs_lang();   // avant toute sortie : ?lang=xx doit pouvoir poser son cookie
    ?>
<!DOCTYPE html>
<html lang="<?php echo aprs_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($opts['refresh'])): ?><meta http-equiv="refresh" content="<?php echo intval($opts['refresh']); ?>"><?php endif; ?>
    <title><?php echo h($title); ?> - <?php echo h(aprs_title($s)); ?></title>
    <?php echo aprs_i18n_js(); ?>
    <?php if (!empty($opts['leaflet'])) echo aprs_leaflet_tags(); ?>
    <link rel="stylesheet" href="/admin/admin.css?v=9">
</head>
<body class="<?php echo h($opts['body_class'] ?? ''); ?>">
<div class="container">
    <div class="card topbar">
        <div>
            <h1><?php echo h($title); ?></h1>
            <div class="subtitle"><?php echo h(aprs_station($s)); ?> · <a href="/"><?php echo th('← Retour à l\'interface'); ?></a></div>
        </div>
        <div class="topbar-right">
            <?php echo aprs_lang_switch(); ?>
            <?php if (!empty($_SESSION['aprs_user'])): ?>
            <form method="POST" action="logout.php" class="logout">
                <?php echo aprs_csrf_field(); ?>
                <span class="muted">👤 <?php echo h($_SESSION['aprs_user']); ?></span>
                <button class="btn btn-small btn-secondary"><?php echo th('🚪 Déconnexion'); ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <nav class="tabs">
        <?php foreach (APRS_ADMIN_TABS as $id => [$href, $label]): ?>
            <a href="<?php echo h($href); ?>" class="<?php echo $id === $active ? 'active' : ''; ?>"><?php echo h(t($label)); ?></a>
        <?php endforeach; ?>
        <?php if ($pisugar = aprs_pisugar()): ?>
            <a href="<?php echo h($pisugar['url']); ?>" target="_blank" rel="noopener" class="ext"
               title="<?php echo th('Interface du PiSugar (batterie)'); ?>">🔋 PiSugar<?php
                if ($pisugar['battery'] !== null) echo ' ' . h(round($pisugar['battery'])) . ' %';
                if ($pisugar['charging']) echo ' ⚡'; ?> ↗</a>
        <?php endif; ?>
    </nav>
    <?php
}

/** Numéro de version installé (copié par install.sh), '' si inconnu. */
function aprs_kit_version() {
    $v = trim((string)@file_get_contents('/usr/local/share/aprs-igate/VERSION'));
    return preg_match('/^\d+(\.\d+){1,3}$/', $v) ? $v : '';
}

function aprs_admin_footer() {
    $v = aprs_kit_version();
    echo '<p class="kit-version">iGate APRS' . ($v !== '' ? ' v' . h($v) : '')
       . ' · <a href="https://github.com/banshee1971/iGate-APRS-avec-un-Raspberry-Pi-Zero-2W" target="_blank" rel="noopener">GitHub ↗</a></p>';
    echo "</div>\n</body>\n</html>\n";
}

function aprs_flash_box($flash, array $errors = []) {
    if ($flash) {
        echo '<div class="flash">' . h(t((string)$flash)) . '</div>';
    }
    if ($errors) {
        echo '<div class="error-box"><strong>' . th('Les réglages n\'ont pas été enregistrés.') . '</strong> ' . th('Corrigez les champs en rouge.');
        if (isset($errors['_'])) echo '<br>' . h($errors['_']);
        echo '</div>';
    }
}

/** Sections du schéma affichées sur une page ('config' = sections sans "page"). */
function aprs_sections_for($page) {
    return array_values(array_filter(aprs_schema()['sections'], function ($sec) use ($page) {
        return ($sec['page'] ?? 'config') === $page;
    }));
}

/**
 * Enregistre les champs des sections données (les autres réglages restent
 * tels quels), puis demande l'application. Redirige si tout va bien.
 * Retourne [réglages à réafficher, erreurs].
 */
function aprs_handle_save(array $sections, $redirect) {
    $raw = aprs_load_settings();
    foreach ($sections as $sec) {
        foreach ($sec['fields'] as $f) {
            // Une case à cocher non cochée n'est pas envoyée par le navigateur
            $raw[$f['key']] = $f['type'] === 'bool' ? isset($_POST[$f['key']]) : ($_POST[$f['key']] ?? $f['default']);
        }
    }
    [$clean, $errors] = aprs_validate($raw);
    if (!$errors) {
        if (aprs_save_settings($clean) && aprs_queue_action('apply')) {
            header('Location: ' . $redirect . '?msg=' . urlencode('Réglages enregistrés. Application en cours...'));
            exit;
        }
        $errors['_'] = t('Impossible d\'écrire dans %s (droits ?).', APRS_STATE_DIR);
    }
    return [$raw, $errors];  // réafficher ce que l'usager a tapé
}

function aprs_render_sections(array $sections, array $settings, array $errors, array $after = []) {
    foreach ($sections as $section): ?>
        <div class="card">
            <h2><?php echo h(t($section['title'])); ?></h2>
            <div class="fields">
            <?php foreach ($section['fields'] as $f):
                $key = $f['key'];
                $val = $settings[$key];
                $err = $errors[$key] ?? null;
                $cls = 'field' . ($f['type'] === 'bool' ? ' check' : '') . ($err ? ' has-error' : ''); ?>
                <div class="<?php echo $cls; ?>">
                    <?php if ($f['type'] === 'bool'): ?>
                        <label><input type="checkbox" id="f_<?php echo h($key); ?>" name="<?php echo h($key); ?>" <?php echo $val ? 'checked' : ''; ?>> <?php echo h(t($f['label'])); ?></label>
                    <?php else: ?>
                        <label for="f_<?php echo h($key); ?>"><?php echo h(t($f['label'])); ?></label>
                        <?php if (($f['widget'] ?? '') === 'ssid'): ?>
                            <div class="ssid-pick">
                                <span class="ssid-icon" id="ssid-icon"><?php echo APRS_SSIDS[max(0, min(15, (int)$val))][0]; ?></span>
                                <select id="f_<?php echo h($key); ?>" name="<?php echo h($key); ?>">
                                    <?php foreach (APRS_SSIDS as $n => [$icon, $short, $long]): ?>
                                        <option value="<?php echo $n; ?>" data-icon="<?php echo h($icon); ?>" data-desc="<?php echo h(t($long)); ?>"
                                            <?php echo (int)$val === $n ? 'selected' : ''; ?>><?php echo h($icon . '  -' . $n . ' : ' . t($short)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="help" id="ssid-desc"><?php echo h(t(APRS_SSIDS[max(0, min(15, (int)$val))][2])); ?></div>
                        <?php elseif (($f['widget'] ?? '') === 'passcode'):
                            $pc = aprs_is_configured($settings) ? aprs_passcode($settings['callsign']) : ''; ?>
                            <input type="text" id="f_<?php echo h($key); ?>" value="<?php echo h($pc); ?>" readonly
                                   class="readonly-code" placeholder="<?php echo th('Entrez votre indicatif'); ?>">
                        <?php elseif ($f['type'] === 'choice'): ?>
                            <select id="f_<?php echo h($key); ?>" name="<?php echo h($key); ?>">
                                <?php foreach ($f['choices'] as $c): ?>
                                    <option value="<?php echo h($c['value']); ?>" <?php echo (string)$val === (string)$c['value'] ? 'selected' : ''; ?>><?php echo h(t($c['label'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($f['type'] === 'int' || $f['type'] === 'float'): ?>
                            <input type="number" id="f_<?php echo h($key); ?>" name="<?php echo h($key); ?>"
                                   value="<?php echo h($val); ?>" min="<?php echo h($f['min']); ?>" max="<?php echo h($f['max']); ?>"
                                   step="<?php echo $f['type'] === 'int' ? '1' : h(pow(10, -($f['decimals'] ?? 4))); ?>">
                        <?php else: ?>
                            <input type="text" id="f_<?php echo h($key); ?>" name="<?php echo h($key); ?>"
                                   value="<?php echo h($val); ?>" placeholder="<?php echo h(isset($f['placeholder']) ? t($f['placeholder']) : ''); ?>">
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($err): ?><div class="err">⚠️ <?php echo h($err); ?></div><?php endif; ?>
                    <?php if (!empty($f['help']) && ($f['widget'] ?? '') !== 'ssid'): ?>
                        <div class="help"><?php echo h(t($f['help'])); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <?php if (isset($after[$section['id']])) echo $after[$section['id']]; ?>
        </div>
    <?php endforeach;
}

// ------------------------------------------------------- état du système
function aprs_shell($cmd) {
    if (!function_exists('shell_exec')) return null;
    $out = @shell_exec($cmd . ' 2>/dev/null');
    return $out === null ? null : (string)$out;
}

function aprs_svc_state($unit) {
    $out = aprs_shell('systemctl is-active ' . escapeshellarg($unit));
    return $out === null ? '?' : trim($out);
}

/**
 * Demandes en attente + celle que le service root traite en ce moment
 * (il retire la demande de la file dès qu'il la prend : une recherche de
 * mises à jour ou un test de gain tourne ensuite plusieurs minutes).
 */
function aprs_admin_busy_count() {
    $n = aprs_pending_actions();
    if (in_array(aprs_svc_state('aprs-admin.service'), ['activating', 'active', 'reloading'], true)) $n++;
    return $n;
}

function aprs_svc_pill($state) {
    $cls = $state === 'active' ? 'ok' : ($state === '?' ? 'unk' : 'bad');
    return '<span class="pill ' . $cls . '">' . h($state) . '</span>';
}

// ------------------------------------------------------------------ SSID
// Recommandations APRS pour les SSID : [icône, court, description]
const APRS_SSIDS = [
    0  => ['🏠', 'station principale', 'Votre station principale, habituellement fixe et capable de messages.'],
    1  => ['📟', 'station additionnelle', 'Station additionnelle générique : digipeater, mobile, météo, etc.'],
    2  => ['📟', 'station additionnelle', 'Station additionnelle générique : digipeater, mobile, météo, etc.'],
    3  => ['📟', 'station additionnelle', 'Station additionnelle générique : digipeater, mobile, météo, etc.'],
    4  => ['📟', 'station additionnelle', 'Station additionnelle générique : digipeater, mobile, météo, etc.'],
    5  => ['📱', 'autres réseaux, téléphones', 'Autres réseaux : D-Star, téléphones intelligents (iPhone, Android), etc.'],
    6  => ['🛰️', 'activité spéciale', 'Activité spéciale : satellites, camping, 6 mètres, etc.'],
    7  => ['🚶', 'portatif', 'Portatif (walkie-talkie, HT) ou autre appareil porté par une personne.'],
    8  => ['⛵', 'bateau, VR, 2e mobile', 'Bateaux, voiliers, VR ou deuxième station mobile principale.'],
    9  => ['🚗', 'mobile principal', 'Station mobile principale (habituellement capable de messages).'],
    10 => ['🌐', 'iGate, Internet', 'Internet : iGates, Echolink, Winlink, AVRS, APRN, etc. Le bon choix pour cet iGate.'],
    11 => ['🎈', 'ballon, aéronef', 'Ballons, aéronefs, engins spatiaux, etc.'],
    12 => ['📍', 'traceur, APRStt', 'APRStt, DTMF, RFID, appareils, traceurs à sens unique, etc.'],
    13 => ['⛅', 'station météo', 'Stations météo.'],
    14 => ['🚚', 'camionneur', 'Camionneurs ou conducteurs à temps plein.'],
    15 => ['📟', 'station additionnelle', 'Station additionnelle générique.'],
];

// --------------------------------------------------------------- PiSugar
/**
 * PiSugar détecté ? (pisugar-server : interface web sur le port 8421,
 * API texte sur le port 8423). Retourne null s'il n'est pas installé/actif.
 */
function aprs_pisugar() {
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    $web = @fsockopen('127.0.0.1', 8421, $errno, $errstr, 0.3);
    if (!$web) return null;
    fclose($web);
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_ADDR'] ?? 'localhost'));
    if (!preg_match('/^[A-Za-z0-9.\-]+$|^\[[0-9a-fA-F:]+\]$/', $host)) $host = $_SERVER['SERVER_ADDR'] ?? 'localhost';
    $cache = ['url' => 'http://' . $host . ':8421', 'battery' => null, 'charging' => false, 'voltage' => null];
    $api = @fsockopen('127.0.0.1', 8423, $errno, $errstr, 0.3);
    if ($api) {
        stream_set_timeout($api, 0, 400000);
        foreach (['battery', 'battery_charging', 'battery_v'] as $q) {
            fwrite($api, "get $q\n");
            $line = (string)fgets($api, 128);
            if (preg_match('/^battery:\s*([\d.]+)/', $line, $m)) $cache['battery'] = (float)$m[1];
            if (preg_match('/^battery_charging:\s*true/i', $line)) $cache['charging'] = true;
            if (preg_match('/^battery_v:\s*([\d.]+)/', $line, $m)) $cache['voltage'] = (float)$m[1];
        }
        fclose($api);
    }
    return $cache;
}

// ------------------------------------------------------------ dongle SDR
// Clés USB à puce Realtek RTL2832U reconnues par librtlsdr (rtl_fm)
const APRS_RTL_IDS = [
    '0bda:2832' => 'Realtek RTL2832U (générique)',
    '0bda:2838' => 'Realtek RTL2832U (RTL-SDR / DVB-T)',
    '0413:6680' => 'DigitalNow Quad DVB-T',
    '0413:6f0f' => 'Leadtek WinFast DTV Dongle mini D',
    '0458:707f' => 'Genius TVGo DVB-T03',
    '0ccd:00a9' => 'Terratec Cinergy T Stick Black',
    '0ccd:00b3' => 'Terratec NOXON DAB/DAB+',
    '0ccd:00d3' => 'Terratec Cinergy T Stick RC',
    '0ccd:00e0' => 'Terratec NOXON DAB Stick',
    '185b:0620' => 'Compro Videomate U620F',
    '185b:0650' => 'Compro Videomate U650F',
    '1b80:d393' => 'GIGABYTE GT-U7300',
    '1b80:d394' => 'DIKOM USB-DVBT HD',
    '1b80:d395' => 'Peak 102569AGPK',
    '1b80:d39d' => 'SVEON STV20',
    '1d19:1101' => 'Dexatek DK DVB-T',
    '1d19:1102' => 'Dexatek DK DVB-T',
    '1d19:1103' => 'Dexatek DK 5217 DVB-T',
    '1f4d:b803' => 'GTek T803',
    '1f4d:c803' => 'Lifeview LV5TDeluxe',
];
// Autres SDR reconnus mais NON utilisables par ce kit (rtl_fm)
const APRS_OTHER_SDR_IDS = [
    '1d50:60a1' => 'Airspy',
    '03eb:800c' => 'Airspy HF+',
    '1d50:6089' => 'HackRF One',
    '1df7:2500' => 'SDRplay RSP1',
    '1df7:3000' => 'SDRplay RSP1A',
    '1df7:3010' => 'SDRplay RSP2',
    '1df7:3020' => 'SDRplay RSPduo',
    '1df7:3050' => 'SDRplay RSPdx',
    '1fc9:000c' => 'FUNcube Dongle Pro',
    '04d8:fb31' => 'FUNcube Dongle Pro+',
];

/**
 * Dongles SDR branchés, lus dans /sys/bus/usb (pas besoin de lsusb ni de root).
 * Chaque entrée : id, chipset, model, manufacturer, product, serial, speed,
 * supported, driver (pilote du noyau attaché, ex. dvb_usb_rtl28xxu = problème).
 */
function aprs_sdr_devices() {
    $rd = function ($f) { return is_readable($f) ? trim((string)@file_get_contents($f)) : ''; };
    $found = [];
    foreach (glob('/sys/bus/usb/devices/*/idVendor') ?: [] as $vf) {
        $dir = dirname($vf);
        $id = strtolower($rd($vf) . ':' . $rd("$dir/idProduct"));
        $rtl = array_key_exists($id, APRS_RTL_IDS);
        if (!$rtl && !array_key_exists($id, APRS_OTHER_SDR_IDS)) continue;
        $man = $rd("$dir/manufacturer");
        $prod = $rd("$dir/product");
        $model = t($rtl ? APRS_RTL_IDS[$id] : APRS_OTHER_SDR_IDS[$id]);
        if (stripos($man, 'RTLSDRBlog') !== false || stripos($prod, 'Blog V') !== false) {
            $model = 'RTL-SDR Blog ' . trim(preg_replace('/^Blog\s*/i', '', $prod));
        } elseif (stripos($man . $prod, 'NooElec') !== false) {
            $model = 'NooElec ' . $prod;
        } elseif ($prod !== '' && $rtl && $id === '0bda:2838') {
            $model = 'RTL2832U · ' . $prod;   // ex. RTL2838UHIDIR = dongle générique
        }
        $driver = '';
        foreach (glob("$dir/*:1.0/driver") ?: [] as $drv) {
            $driver = basename((string)@readlink($drv));
        }
        $found[] = [
            'id' => $id, 'chipset' => $rtl ? 'Realtek RTL2832U' : t('non RTL'),
            'model' => $model, 'manufacturer' => $man, 'product' => $prod,
            'serial' => $rd("$dir/serial"), 'speed' => $rd("$dir/speed"),
            'supported' => $rtl, 'driver' => $driver, 'port' => basename($dir),
        ];
    }
    return $found;
}

/** true = un dongle RTL-SDR utilisable est branché ; null = impossible à savoir. */
function aprs_dongle_present() {
    if (!is_dir('/sys/bus/usb/devices')) return null;
    foreach (aprs_sdr_devices() as $d) {
        if ($d['supported']) return true;
    }
    return false;
}

/** Libellé court pour l'état du système : modèle du premier dongle ou « absent ». */
function aprs_dongle_label() {
    $devs = aprs_sdr_devices();
    foreach ($devs as $d) {
        if ($d['supported']) return [true, $d['model']];
    }
    return $devs ? [false, t('%s (non supporté)', $devs[0]['model'])] : [false, t('absent')];
}
