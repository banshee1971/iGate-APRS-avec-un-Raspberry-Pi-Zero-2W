<?php
// Exporter / importer la configuration de l'iGate (onglet Configuration).
//
// Le fichier exporté est un JSON compressé en gzip (.json.gz) qui contient
// les réglages, les profils réseau et, si demandé, le compte administrateur
// (nom et mot de passe chiffré, jamais en clair). À l'importation, chaque
// réglage est revalidé comme s'il avait été tapé dans le formulaire.
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/auth.php';

const APRS_EXPORT_FORMAT = 'aprs-igate-config';
const APRS_EXPORT_VERSION = 1;
const APRS_IMPORT_MAX_BYTES = 1048576;      // fichier envoyé (compressé)
const APRS_IMPORT_MAX_JSON = 4194304;       // une fois décompressé

function aprs_config_export($with_account) {
    $settings = aprs_load_settings();
    $net = aprs_net_load();
    $bundle = [
        'format'   => APRS_EXPORT_FORMAT,
        'version'  => APRS_EXPORT_VERSION,
        'exported' => date('c'),
        'station'  => aprs_station($settings),
        'settings' => $settings,
        'network'  => [
            // Le profil Origine est recréé automatiquement : inutile de l'exporter
            'profiles' => array_values(array_filter($net['profiles'], function ($p) { return ($p['id'] ?? '') !== 'origine'; })),
            'fallback' => $net['fallback'],
        ],
    ];
    if ($with_account && ($acc = aprs_auth_account())) {
        $bundle['account'] = ['user' => $acc['user'], 'hash' => $acc['hash']];
    }
    return $bundle;
}

/** Nom du fichier proposé au téléchargement. */
function aprs_config_export_name() {
    $station = preg_replace('/[^A-Za-z0-9-]/', '', aprs_station(aprs_load_settings()));
    return 'aprs-igate-config-' . $station . '-' . date('Ymd-Hi') . '.json.gz';
}

/**
 * Importe un fichier envoyé par le formulaire.
 * Retourne [ok, message, avertissements[]].
 */
function aprs_config_import(array $upload) {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        return [false, t('Aucun fichier reçu.'), []];
    }
    if (filesize($upload['tmp_name']) > APRS_IMPORT_MAX_BYTES) {
        return [false, t('Fichier trop gros pour être une configuration.'), []];
    }
    $raw = (string)file_get_contents($upload['tmp_name']);
    if (substr($raw, 0, 2) === "\x1f\x8b") {                 // gzip
        $raw = @gzdecode($raw, APRS_IMPORT_MAX_JSON);
        if ($raw === false) {
            return [false, t('Fichier compressé illisible.'), []];
        }
    }
    $bundle = json_decode($raw, true);
    if (!is_array($bundle) || ($bundle['format'] ?? '') !== APRS_EXPORT_FORMAT || !is_array($bundle['settings'] ?? null)) {
        return [false, t('Ce fichier n\'est pas une configuration exportée par l\'iGate.'), []];
    }
    if (intval($bundle['version'] ?? 0) > APRS_EXPORT_VERSION) {
        return [false, t('Ce fichier vient d\'une version plus récente du kit : mettez l\'iGate à jour d\'abord.'), []];
    }

    $warnings = [];

    // 1. Réglages : revalidés ; une valeur refusée garde la valeur par défaut
    [$clean, $errors] = aprs_validate($bundle['settings']);
    $fields = aprs_fields();
    foreach ($errors as $key => $err) {
        $label = isset($fields[$key]) ? t($fields[$key]['label']) : $key;
        $warnings[] = t('Réglage « %s » ignoré : %s', $label, $err);
    }
    if (!aprs_save_settings($clean)) {
        return [false, t('Impossible d\'écrire dans %s (droits ?).', APRS_STATE_DIR), $warnings];
    }

    // 2. Profils réseau : enregistrés seulement, jamais activés automatiquement
    if (is_array($bundle['network']['profiles'] ?? null)) {
        $profiles = [];
        foreach ($bundle['network']['profiles'] as $p) {
            if (!is_array($p) || !preg_match('/^[a-z0-9-]{1,32}$/', (string)($p['id'] ?? '')) || $p['id'] === 'origine'
                || !is_array($p['eth'] ?? null) || !is_array($p['wifi'] ?? null)) {
                $warnings[] = t('Un profil réseau invalide a été ignoré.');
                continue;
            }
            $p['name'] = mb_substr_safe((string)($p['name'] ?? $p['id']), 40);
            unset($p['builtin']);
            $profiles[] = $p;
        }
        $fallback = (string)($bundle['network']['fallback'] ?? '');
        if ($fallback !== '' && !in_array($fallback, array_column($profiles, 'id'), true)) {
            $fallback = '';
        }
        $data = aprs_net_load();
        $data['profiles'] = array_merge([aprs_net_origin()], $profiles);
        $data['fallback'] = $fallback;
        if (!aprs_net_save($data)) {
            $warnings[] = t('Les profils réseau n\'ont pas pu être enregistrés.');
        }
    }

    // 3. Compte administrateur (seulement s'il a été exporté)
    $acc = $bundle['account'] ?? null;
    if (is_array($acc)) {
        $user = (string)($acc['user'] ?? '');
        $hash = (string)($acc['hash'] ?? '');
        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $user) && preg_match('/^\$(2y|argon2id?)\$/', $hash) && strlen($hash) < 300) {
            $json = json_encode(['user' => $user, 'hash' => $hash, 'updated' => date('c')], JSON_PRETTY_PRINT) . "\n";
            $tmp = APRS_AUTH_FILE . '.tmp';
            if (file_put_contents($tmp, $json) !== false) {
                @chmod($tmp, 0600);
                rename($tmp, APRS_AUTH_FILE);
                $_SESSION['aprs_user'] = $user;
                $warnings[] = t('Compte administrateur importé : utilisez désormais le nom « %s » et son mot de passe.', $user);
            }
        } else {
            $warnings[] = t('Le compte administrateur du fichier est invalide : le compte actuel est conservé.');
        }
    }

    // 4. Appliquer : Direwolf, services et tâches sont régénérés et relancés
    if (!aprs_queue_action('apply') || !aprs_queue_action('restart')) {
        return [false, t('Impossible de déposer la demande.'), $warnings];
    }
    return [true, t('Configuration de %s importée. Application et redémarrage des services en cours...', (string)($bundle['station'] ?? '?')), $warnings];
}

/** Tronque une chaîne UTF-8 sans l'extension mbstring (absente sur le Pi). */
function mb_substr_safe($s, $max) {
    if (preg_match('/^.{0,' . intval($max) . '}/us', $s, $m)) return $m[0];
    return substr($s, 0, $max);
}
