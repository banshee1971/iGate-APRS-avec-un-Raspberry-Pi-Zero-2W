<?php
require __DIR__ . '/../lib/auth.php';

$next = (string)($_GET['next'] ?? $_POST['next'] ?? '/admin/');
// Redirection seulement vers une page /admin locale
if (!preg_match('~^/admin/[A-Za-z0-9._/?=&%-]*$~', $next)) {
    $next = '/admin/';
}

if (aprs_logged_in()) {
    header('Location: ' . $next);
    exit;
}

$account = aprs_auth_account();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account) {
    if (aprs_login_blocked()) {
        $error = 'Trop d\'essais ratés. Réessayez dans quelques minutes.';
    } elseif (aprs_login($_POST['user'] ?? '', $_POST['pass'] ?? '')) {
        header('Location: ' . $next);
        exit;
    } else {
        $error = 'Nom d\'utilisateur ou mot de passe incorrect.';
    }
}
$s = aprs_load_settings();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - <?php echo h(aprs_title($s)); ?></title>
    <link rel="stylesheet" href="/admin/admin.css?v=4">
</head>
<body>
<div class="card login-box">
    <h1>🔐 Configuration</h1>
    <p class="subtitle" style="margin-bottom: 20px;"><?php echo h(aprs_station($s)); ?> · <a href="/">← Retour à l'interface</a></p>
    <?php if (!$account): ?>
        <div class="warn">
            Aucun compte administrateur n'existe encore. Sur le Pi, en SSH, lancez :<br>
            <code>sudo aprs-admin-passwd</code>
        </div>
    <?php else: ?>
        <?php if ($error): ?><div class="error-box"><?php echo h($error); ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="next" value="<?php echo h($next); ?>">
            <div class="field">
                <label for="user">Nom d'utilisateur</label>
                <input type="text" id="user" name="user" autocomplete="username" autofocus required>
            </div>
            <div class="field">
                <label for="pass">Mot de passe</label>
                <input type="password" id="pass" name="pass" autocomplete="current-password" required>
            </div>
            <button class="btn">Se connecter</button>
        </form>
        <p class="muted" style="margin-top: 15px;">Mot de passe oublié ? En SSH sur le Pi : <code>sudo aprs-admin-passwd</code></p>
    <?php endif; ?>
</div>
</body>
</html>
