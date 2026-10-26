<?php declare(strict_types=1) ?>
<?php

use App\Auth\CredentialRules;

use function App\Http\e;

/**
 * Variables passed by render():
 *
 * @var string|null  $notice
 * @var string|null  $error
 * @var string|null  $loggedInUser
 * @var list<string> $roles
 * @var string       $oldUsername
 * @var string       $csrfToken
 */
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giriş</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>
<?php if ($notice !== null) : ?>
    <p class="notice"><?= e($notice) ?></p>
<?php endif; ?>

<?php if ($error !== null) : ?>
    <p class="error"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($loggedInUser !== null) : ?>
    <h2>Tekrar hoş geldin, <?= e($loggedInUser) ?>!</h2>
    <ul>
        <?php foreach ($roles as $role) : ?>
            <li><?= e($role) ?></li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="login.php">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <button type="submit">Çıkış yap</button>
    </form>
<?php else : ?>
    <form method="post" action="login.php" class="stack">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <label for="username">Kullanıcı adı</label>
        <input type="text" id="username" name="username" value="<?= e($oldUsername) ?>"
               maxlength="<?= CredentialRules::USERNAME_MAX ?>" autocomplete="username" required>
        <label for="password">Şifre</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <button type="submit">Giriş yap</button>
    </form>

    <form method="post" action="login.php" class="stack">
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <label for="new_username">Yeni kullanıcı adı</label>
        <input type="text" id="new_username" name="new_username"
               pattern="<?= e(CredentialRules::usernamePattern()) ?>"
               maxlength="<?= CredentialRules::USERNAME_MAX ?>" autocomplete="username" required>
        <label for="new_password">Yeni şifre</label>
        <?php /* No maxlength: the byte limit is checked on the server, the browser counts characters. */ ?>
        <input type="password" id="new_password" name="new_password" autocomplete="new-password"
               minlength="<?= CredentialRules::PASSWORD_MIN_CHARS ?>" required>
        <label for="email">E-posta</label>
        <input type="email" id="email" name="email" maxlength="<?= CredentialRules::EMAIL_MAX ?>"
               autocomplete="email" required>
        <button type="submit">Kayıt ol</button>
    </form>
<?php endif; ?>
</body>
</html>
