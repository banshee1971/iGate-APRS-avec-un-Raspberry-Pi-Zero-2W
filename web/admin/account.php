<?php
// /admin/account.php — changer le nom d'utilisateur et le mot de passe
require __DIR__ . '/../lib/admin.php';
aprs_require_login();
aprs_csrf_check();

$errors = [];
$flash = $_GET['msg'] ?? '';
$account = aprs_auth_account();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['user'] ?? ''));
    $cur  = (string)($_POST['current'] ?? '');
    $new  = (string)($_POST['new'] ?? '');
    $conf = (string)($_POST['confirm'] ?? '');

    if (!$account || !password_verify($cur, $account['hash'])) {
        $errors['current'] = 'Mot de passe actuel incorrect.';
        usleep(800000);
    }
    if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $user)) {
        $errors['user'] = 'Lettres, chiffres, point, tiret ou souligné seulement (32 maximum).';
    }
    if ($new === '') {
        $new = $cur;   // changer seulement le nom
    } elseif (strlen($new) < 8) {
        $errors['new'] = '8 caractères minimum.';
    } elseif ($new !== $conf) {
        $errors['confirm'] = 'Les deux mots de passe ne correspondent pas.';
    }
    if (!$errors) {
        if (aprs_save_account($user, $new)) {
            $_SESSION['aprs_user'] = $user;
            session_regenerate_id(true);
            header('Location: account.php?msg=' . urlencode('Compte mis à jour.'));
            exit;
        }
        $errors['_'] = "Impossible d'écrire " . APRS_AUTH_FILE . ' (droits ?).';
    }
}

aprs_admin_header('👤 Compte administrateur', 'account');
?>
    <?php if ($flash): ?><div class="flash"><?php echo h($flash); ?></div><?php endif; ?>
    <?php if (isset($errors['_'])): ?><div class="error-box"><?php echo h($errors['_']); ?></div><?php endif; ?>

    <form method="POST" class="card">
        <?php echo aprs_csrf_field(); ?>
        <h2>Identifiants de la page /admin</h2>
        <div class="fields">
            <?php
            $rows = [
                ['user', "Nom d'utilisateur", 'text', $_POST['user'] ?? ($account['user'] ?? ''), 'username', ''],
                ['current', 'Mot de passe actuel', 'password', '', 'current-password', 'Obligatoire pour toute modification.'],
                ['new', 'Nouveau mot de passe', 'password', '', 'new-password', 'Laissez vide pour ne changer que le nom. 8 caractères minimum.'],
                ['confirm', 'Confirmez le nouveau mot de passe', 'password', '', 'new-password', ''],
            ];
            foreach ($rows as [$name, $label, $type, $val, $ac, $help]): ?>
                <div class="field<?php echo isset($errors[$name]) ? ' has-error' : ''; ?>">
                    <label for="<?php echo $name; ?>"><?php echo h($label); ?></label>
                    <input type="<?php echo $type; ?>" id="<?php echo $name; ?>" name="<?php echo $name; ?>"
                           value="<?php echo h($val); ?>" autocomplete="<?php echo $ac; ?>">
                    <?php if (isset($errors[$name])): ?><div class="err">⚠️ <?php echo h($errors[$name]); ?></div><?php endif; ?>
                    <?php if ($help): ?><div class="help"><?php echo h($help); ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p style="margin-top: 20px;"><button class="btn">💾 Enregistrer</button></p>
    </form>

    <div class="card">
        <h2>Mot de passe oublié ?</h2>
        <p>Connectez-vous au Pi en SSH et lancez <code>sudo aprs-admin-passwd</code>.</p>
        <?php if (!empty($account['updated'])): ?>
            <p class="muted" style="margin-top: 8px;">Dernière modification : <?php echo h(date('Y-m-d H:i', strtotime($account['updated']))); ?></p>
        <?php endif; ?>
    </div>
<?php aprs_admin_footer();
