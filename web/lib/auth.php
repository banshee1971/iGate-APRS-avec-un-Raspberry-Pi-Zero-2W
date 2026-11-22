<?php
// Authentification de la page /admin.
// Le compte est créé par install.sh (ou « sudo aprs-admin-passwd ») dans
// /var/lib/aprs-igate/admin-auth.json : {"user": "...", "hash": "<bcrypt>"}.
require_once __DIR__ . '/settings.php';

const APRS_SESSION_IDLE = 8 * 3600;   // déconnexion après 8 h d'inactivité
const APRS_FAIL_FILE    = APRS_STATE_DIR . '/login-failures.json';
const APRS_FAIL_MAX     = 5;          // essais ratés avant blocage...
const APRS_FAIL_WINDOW  = 300;        // ... pendant 5 minutes, par adresse IP

function aprs_auth_account() {
    if (!is_readable(APRS_AUTH_FILE)) {
        return null;
    }
    $a = json_decode(file_get_contents(APRS_AUTH_FILE), true);
    return (is_array($a) && !empty($a['user']) && !empty($a['hash'])) ? $a : null;
}

function aprs_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('APRSADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/admin/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function aprs_logged_in() {
    aprs_session_start();
    if (empty($_SESSION['aprs_user'])) {
        return false;
    }
    if (time() - ($_SESSION['aprs_seen'] ?? 0) > APRS_SESSION_IDLE) {
        aprs_logout();
        return false;
    }
    $_SESSION['aprs_seen'] = time();
    return true;
}

/** À appeler en haut de chaque page /admin. $api = true : réponse JSON 401. */
function aprs_require_login($api = false) {
    if (aprs_logged_in()) {
        return;
    }
    if ($api) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'login']);
        exit;
    }
    $next = $_SERVER['REQUEST_URI'] ?? '/admin/';
    header('Location: /admin/login.php?next=' . urlencode($next));
    exit;
}

// ------------------------------------------------- protection force brute
function aprs_fail_load() {
    $all = is_readable(APRS_FAIL_FILE) ? (json_decode(file_get_contents(APRS_FAIL_FILE), true) ?: []) : [];
    $now = time();
    foreach ($all as $ip => $times) {
        $all[$ip] = array_values(array_filter($times, function ($t) use ($now) { return $now - $t < APRS_FAIL_WINDOW; }));
        if (!$all[$ip]) unset($all[$ip]);
    }
    return $all;
}

function aprs_login_blocked() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    return count(aprs_fail_load()[$ip] ?? []) >= APRS_FAIL_MAX;
}

function aprs_login_failed() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $all = aprs_fail_load();
    $all[$ip][] = time();
    @file_put_contents(APRS_FAIL_FILE, json_encode($all), LOCK_EX);
    error_log("aprs-admin: échec de connexion depuis $ip");
    usleep(random_int(800000, 1500000));
}

/** Retourne true si les identifiants sont bons (et ouvre la session). */
function aprs_login($user, $pass) {
    $acc = aprs_auth_account();
    if (!$acc || aprs_login_blocked()) {
        return false;
    }
    $ok_user = hash_equals((string)$acc['user'], (string)$user);
    $ok_pass = password_verify((string)$pass, $acc['hash']);
    if (!$ok_user || !$ok_pass) {
        aprs_login_failed();
        return false;
    }
    if (password_needs_rehash($acc['hash'], PASSWORD_DEFAULT)) {
        aprs_save_account($acc['user'], $pass);
    }
    aprs_session_start();
    session_regenerate_id(true);
    $_SESSION['aprs_user'] = $acc['user'];
    $_SESSION['aprs_seen'] = time();
    $_SESSION['aprs_csrf'] = bin2hex(random_bytes(16));
    return true;
}

function aprs_logout() {
    aprs_session_start();
    $_SESSION = [];
    session_destroy();
}

function aprs_save_account($user, $pass) {
    $json = json_encode([
        'user'    => $user,
        'hash'    => password_hash($pass, PASSWORD_DEFAULT),
        'updated' => date('c'),
    ], JSON_PRETTY_PRINT) . "\n";
    $tmp = APRS_AUTH_FILE . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    return rename($tmp, APRS_AUTH_FILE);
}

// ---------------------------------------------------------------- CSRF
function aprs_csrf_token() {
    aprs_session_start();
    if (empty($_SESSION['aprs_csrf'])) {
        $_SESSION['aprs_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['aprs_csrf'];
}

function aprs_csrf_field() {
    return '<input type="hidden" name="csrf" value="' . h(aprs_csrf_token()) . '">';
}

/** Refuse toute requête POST sans le bon jeton (formulaire venant d'un autre site). */
function aprs_csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!hash_equals(aprs_csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit(t('Formulaire expiré : rechargez la page et recommencez.'));
    }
}
