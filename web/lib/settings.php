<?php
// Réglages de l'iGate : lecture, validation, enregistrement.
// La validation reprend celle de /usr/local/sbin/aprs-apply-config (Python),
// qui revalide de toute façon avant d'appliquer quoi que ce soit.

const APRS_STATE_DIR     = '/var/lib/aprs-igate';
const APRS_SETTINGS_FILE = APRS_STATE_DIR . '/settings.json';
const APRS_QUEUE_DIR     = APRS_STATE_DIR . '/queue';
const APRS_STATUS_FILE   = APRS_STATE_DIR . '/status.json';
const APRS_LOG_FILE      = APRS_STATE_DIR . '/apply.log';
const APRS_DB_PATH       = '/var/www/aprs/packets.db';
const APRS_UNCONFIGURED  = 'N0CALL';
const APRS_ACTIONS       = ['apply', 'restart', 'backup', 'usb-backup', 'tiles', 'clear-tiles', 'gain-test', 'reboot'];
const APRS_TILE_DIR      = '/var/www/aprs/tiles';
const APRS_AUTH_FILE     = APRS_STATE_DIR . '/admin-auth.json';
const APRS_GAIN_RESULTS  = APRS_STATE_DIR . '/gain-test.txt';

function aprs_schema() {
    static $schema = null;
    if ($schema === null) {
        $schema = json_decode(file_get_contents(__DIR__ . '/settings.schema.json'), true);
    }
    return $schema;
}

function aprs_fields() {
    $fields = [];
    foreach (aprs_schema()['sections'] as $section) {
        foreach ($section['fields'] as $f) {
            $fields[$f['key']] = $f;
        }
    }
    return $fields;
}

/** Valide un tableau de valeurs brutes. Retourne [réglages propres, erreurs par clé]. */
function aprs_validate(array $raw) {
    $clean = [];
    $errors = [];
    foreach (aprs_fields() as $key => $f) {
        $val = array_key_exists($key, $raw) ? $raw[$key] : $f['default'];
        $err = null;
        switch ($f['type']) {
            case 'bool':
                $val = in_array($val, [true, 1, '1', 'true', 'on', 'yes'], true);
                break;
            case 'int':
                if (!is_numeric($val) || intval($val) != $val) {
                    $err = 'doit être un nombre entier';
                } else {
                    $val = intval($val);
                    if ($val < $f['min'] || $val > $f['max']) {
                        $err = "doit être entre {$f['min']} et {$f['max']}";
                    }
                }
                break;
            case 'float':
                $val = str_replace(',', '.', (string)$val);
                if (!is_numeric($val)) {
                    $err = 'doit être un nombre';
                } else {
                    $val = round(floatval($val), $f['decimals'] ?? 4);
                    if ($val < $f['min'] || $val > $f['max']) {
                        $err = "doit être entre {$f['min']} et {$f['max']}";
                    }
                }
                break;
            case 'choice':
                $val = (string)$val;
                if (!in_array($val, array_column($f['choices'], 'value'), true)) {
                    $err = 'choix invalide';
                }
                break;
            default: // text
                $val = trim((string)$val);
                if (!empty($f['upper'])) {
                    $val = strtoupper($val);
                }
                if (!preg_match('~' . str_replace('~', '\~', $f['pattern']) . '~u', $val)
                    || preg_match('~[\x00-\x1f"\'`$\\\\;|&<>]~', $val)) {
                    $err = 'format invalide';
                }
        }
        if ($err !== null) {
            $errors[$key] = $err;
            $val = $f['default'];
        }
        $clean[$key] = $val;
    }
    return [$clean, $errors];
}

function aprs_load_settings() {
    $raw = [];
    if (is_readable(APRS_SETTINGS_FILE)) {
        $raw = json_decode(file_get_contents(APRS_SETTINGS_FILE), true) ?: [];
    }
    return aprs_validate($raw)[0];
}

function aprs_save_settings(array $settings) {
    $tmp = APRS_SETTINGS_FILE . '.tmp';
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json . "\n") === false) {
        return false;
    }
    @chmod($tmp, 0664);
    return rename($tmp, APRS_SETTINGS_FILE);
}

/** Dépose une demande pour le service root aprs-admin (voir aprs-admin.path). */
function aprs_queue_action($action) {
    if (!in_array($action, APRS_ACTIONS, true)) {
        return false;
    }
    $name = sprintf('%s-%s', str_replace('.', '', sprintf('%.6f', microtime(true))), $action);
    return file_put_contents(APRS_QUEUE_DIR . '/' . $name, '') !== false;
}

function aprs_status() {
    if (!is_readable(APRS_STATUS_FILE)) {
        return null;
    }
    return json_decode(file_get_contents(APRS_STATUS_FILE), true);
}

function aprs_pending_actions() {
    $files = @scandir(APRS_QUEUE_DIR) ?: [];
    return count(array_diff($files, ['.', '..']));
}

function aprs_passcode($callsign) {
    $call = strtoupper(explode('-', $callsign)[0]);
    $hash = 0x73E2;
    for ($i = 0; $i < strlen($call); $i += 2) {
        $hash ^= ord($call[$i]) << 8;
        if ($i + 1 < strlen($call)) {
            $hash ^= ord($call[$i + 1]);
        }
    }
    return $hash & 0x7FFF;
}

function aprs_station(array $s) {
    return $s['ssid'] == 0 ? $s['callsign'] : $s['callsign'] . '-' . $s['ssid'];
}

function aprs_title(array $s) {
    return $s['web_title'] !== '' ? $s['web_title'] : 'APRS iGate ' . aprs_station($s);
}

function aprs_is_configured(array $s) {
    return $s['callsign'] !== APRS_UNCONFIGURED;
}

/** Leaflet local (installé par install.sh) sinon CDN. */
function aprs_leaflet_tags() {
    if (is_file('/var/www/aprs/vendor/leaflet/leaflet.js')) {
        return '<link rel="stylesheet" href="/vendor/leaflet/leaflet.css" />' . "\n"
             . '    <script src="/vendor/leaflet/leaflet.js"></script>';
    }
    return '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />' . "\n"
         . '    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>';
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
