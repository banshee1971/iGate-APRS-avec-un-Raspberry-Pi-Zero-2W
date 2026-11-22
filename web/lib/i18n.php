<?php
// Interface bilingue français / anglais.
//
// Le texte français du code sert de clé : t('Réglages enregistrés.') renvoie
// la traduction anglaise trouvée dans web/lang/en/*.php, ou le français tel
// quel s'il n'y a pas de traduction (jamais de page vide).
//
// Avec des arguments, la chaîne passe par sprintf : t('%d paquets', $n).
// Sans argument, aucun sprintf (un « % » isolé reste intact).
//
// Langue choisie, dans l'ordre : ?lang=xx (mémorisé dans un cookie), cookie
// aprs_lang, réglage « Langue de l'interface », langue du navigateur, français.
//
// Ajouter une langue : créer web/lang/<code>/ (mêmes clés françaises) et
// l'ajouter à APRS_LANGS.

require_once __DIR__ . '/settings.php';

const APRS_LANGS = ['fr' => 'Français', 'en' => 'English'];
const APRS_LANG_DEFAULT = 'fr';

function aprs_lang() {
    static $lang = null, $busy = false;
    if ($lang !== null) return $lang;
    if ($busy) return APRS_LANG_DEFAULT;   // appel imbriqué (lecture des réglages)
    $busy = true;

    $pick = null;
    if (isset($_GET['lang']) && is_string($_GET['lang']) && array_key_exists($_GET['lang'], APRS_LANGS)) {
        $pick = $_GET['lang'];
        if (!headers_sent()) {
            setcookie('aprs_lang', $pick, [
                'expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax',
            ]);
        }
    } elseif (isset($_COOKIE['aprs_lang']) && is_string($_COOKIE['aprs_lang']) && array_key_exists($_COOKIE['aprs_lang'], APRS_LANGS)) {
        $pick = $_COOKIE['aprs_lang'];
    } else {
        $s = aprs_load_settings();
        $set = $s['ui_lang'] ?? 'auto';
        if (array_key_exists($set, APRS_LANGS)) {
            $pick = $set;
        } else {
            // Accept-Language : « en-CA,en;q=0.9,fr;q=0.8 » -> première langue connue
            $al = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
            foreach (preg_split('/\s*,\s*/', $al) as $part) {
                $code = substr($part, 0, 2);
                if (array_key_exists($code, APRS_LANGS)) { $pick = $code; break; }
            }
        }
    }
    $lang = $pick ?: APRS_LANG_DEFAULT;
    $busy = false;
    return $lang;
}

/** Dictionnaire de la langue courante (fusion de web/lang/<code>/*.php). */
function aprs_dict() {
    static $cache = [];
    $lang = aprs_lang();
    if (isset($cache[$lang])) return $cache[$lang];
    $dict = [];
    if ($lang !== APRS_LANG_DEFAULT) {
        $files = glob(dirname(__DIR__) . '/lang/' . $lang . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $part = include $file;
            if (is_array($part)) $dict = array_merge($dict, $part);
        }
    }
    return $cache[$lang] = $dict;
}

/** Traduit un texte français. Arguments facultatifs passés à sprintf. */
function t($fr, ...$args) {
    $dict = aprs_dict();
    $s = (isset($dict[$fr]) && $dict[$fr] !== '') ? $dict[$fr] : $fr;
    return $args ? vsprintf($s, $args) : $s;
}

/**
 * Traduit un message déjà construit en français (ex. statut écrit par les
 * scripts root) : phrase par phrase, et les clés à %s/%d servent de modèles
 * (« Réglages appliqués pour %s. » reconnaît « Réglages appliqués pour VE2ABC. »).
 */
function t_msg($msg) {
    $msg = (string)$msg;
    $dict = aprs_dict();
    if (!$dict || $msg === '') return $msg;
    if (isset($dict[$msg])) return $dict[$msg];
    static $patterns = null;
    if ($patterns === null) {
        $patterns = [];
        foreach ($dict as $fr => $en) {
            if (strpos($fr, '%') === false) continue;
            $parts = preg_split('/%[sd]/', $fr);
            if (count($parts) < 2) continue;
            $re = implode('(.+?)', array_map(function ($p) { return preg_quote($p, '~'); }, $parts));
            $literal = strlen(implode('', $parts));
            if ($literal < 6) continue;   // « %s : %s » : trop vague pour servir de modèle
            $patterns[] = ['~^' . $re . '$~u', str_replace('%d', '%s', $en), $literal];
        }
        // Le modèle le plus précis (le plus de texte fixe) gagne
        usort($patterns, function ($a, $b) { return $b[2] - $a[2]; });
    }
    $out = [];
    foreach (preg_split('/(?<=[.!?])\s+/u', trim($msg)) as $sentence) {
        if (isset($dict[$sentence])) { $out[] = $dict[$sentence]; continue; }
        $done = false;
        foreach ($patterns as [$re, $en]) {
            if (preg_match($re, $sentence, $m)) {
                array_shift($m);
                $out[] = vsprintf($en, $m);
                $done = true;
                break;
            }
        }
        if (!$done) $out[] = $sentence;
    }
    return implode(' ', $out);
}

/** Traduit puis échappe pour le HTML. */
function th($fr, ...$args) {
    return h(t($fr, ...$args));
}

/** Liens « FR | EN » qui gardent les paramètres de la page courante. */
function aprs_lang_switch() {
    $cur = aprs_lang();
    $out = [];
    foreach (APRS_LANGS as $code => $name) {
        if ($code === $cur) {
            $out[] = '<strong title="' . h($name) . '">' . strtoupper($code) . '</strong>';
        } else {
            $q = $_GET;
            $q['lang'] = $code;
            unset($q['msg']);
            $url = strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($q);
            $out[] = '<a href="' . h($url) . '" title="' . h($name) . '" hreflang="' . $code . '">' . strtoupper($code) . '</a>';
        }
    }
    return '<span class="lang-switch">🌐 ' . implode(' | ', $out) . '</span>';
}

/**
 * Balise <script> qui définit t() côté JavaScript avec le même dictionnaire.
 * t('%d paquets', n) remplace %s / %d dans l'ordre.
 */
function aprs_i18n_js() {
    $json = json_encode((object)aprs_dict(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    return '<script>window.APRS_LANG=' . json_encode(aprs_lang()) . ';window.APRS_I18N=' . $json . ';'
         . 'function t(s){var r=(window.APRS_I18N[s]||s),a=Array.prototype.slice.call(arguments,1),i=0;'
         . 'return a.length?r.replace(/%(?:\.\d+)?[sdf]/g,function(){return a[i++];}):r;}</script>';
}
