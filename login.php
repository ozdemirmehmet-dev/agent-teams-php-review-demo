<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Auth\CredentialRules;
use App\Auth\LoginResult;
use App\Auth\RegisterResult;
use App\Auth\Throttle;
use App\Config;
use App\Database;

use function App\Http\clientIp;
use function App\Http\csrfToken;
use function App\Http\enforceSessionLifetime;
use function App\Http\isValidCsrfToken;
use function App\Http\postString;
use function App\Http\redirect;
use function App\Http\render;
use function App\Http\renewSession;
use function App\Http\resetSession;
use function App\Http\sendSecurityHeaders;
use function App\Http\startSession;
use function App\Http\withoutSessionLock;

use const App\Http\SESSION_USER_ID;
use const App\Http\SESSION_USERNAME;

require_once __DIR__ . '/src/bootstrap.php';

$notices = [
    'registered' => 'Kayıt başarılı, giriş yapabilirsiniz.',
    'logged_out' => 'Çıkış yaptınız.',
];

// Buffer output so a late error can replace a half-rendered page.
ob_start();
sendSecurityHeaders();

try {
    $config = Config::fromEnv();
    startSession($config->httpsOnly);
    enforceSessionLifetime();

    // The database is only opened when a request needs it, and at most once.
    $authService = null;
    $auth = static function () use ($config, &$authService): AuthService {
        if ($authService === null) {
            $db = Database::connect($config);
            $authService = new AuthService($db, new Throttle($db));
        }

        return $authService;
    };

    $msgKey = $_GET['msg'] ?? '';
    $notice = is_string($msgKey) ? ($notices[$msgKey] ?? null) : null;
    $error = null;
    $oldUsername = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = postString('action');
        $ip = clientIp($config->trustedProxies);

        if (!isValidCsrfToken(postString('csrf_token'))) {
            [$status, $error] = [400, 'Oturum süresi doldu, lütfen tekrar deneyin.'];
        } elseif ($action === 'login') {
            $oldUsername = trim(postString('username'));
            $outcome = withoutSessionLock(
                static fn () => $auth()->attemptLogin($oldUsername, postString('password'), $ip)
            );

            if ($outcome->isSuccess()) {
                renewSession();
                $_SESSION[SESSION_USER_ID] = $outcome->user->id;
                $_SESSION[SESSION_USERNAME] = $outcome->user->username;
                redirect('login.php');
            }

            [$status, $error] = match ($outcome->result) {
                LoginResult::Throttled => [429, 'Çok fazla başarısız deneme. Lütfen biraz sonra tekrar deneyin.'],
                LoginResult::InvalidCredentials => [401, 'Kullanıcı adı veya şifre hatalı.'],
            };
        } elseif ($action === 'register') {
            $result = withoutSessionLock(static fn () => $auth()->register(
                trim(postString('new_username')),
                postString('new_password'),
                trim(postString('email')),
                $ip,
            ));

            if ($result === RegisterResult::Created) {
                redirect('login.php?msg=registered');
            }

            [$status, $error] = match ($result) {
                RegisterResult::Invalid => [422, sprintf(
                    'Kullanıcı adı %d-%d karakter (harf, rakam, _), şifre en az %d karakter ve en fazla %d bayt, '
                    . 'e-posta geçerli olmalı.',
                    CredentialRules::USERNAME_MIN,
                    CredentialRules::USERNAME_MAX,
                    CredentialRules::PASSWORD_MIN_CHARS,
                    CredentialRules::PASSWORD_MAX_BYTES,
                )],
                RegisterResult::UsernameTaken => [422, 'Bu kullanıcı adı zaten kullanılıyor.'],
                RegisterResult::Throttled => [429, 'Çok fazla kayıt denemesi. Lütfen daha sonra tekrar deneyin.'],
            };
        } elseif ($action === 'logout') {
            resetSession();
            redirect('login.php?msg=logged_out');
        } else {
            [$status, $error] = [400, 'Geçersiz istek.'];
        }

        http_response_code($status);
    }

    $userId = $_SESSION[SESSION_USER_ID] ?? null;
    $username = $_SESSION[SESSION_USERNAME] ?? null;
    $loggedInUser = null;
    $roles = [];

    if (is_int($userId) && is_string($username)) {
        // Read on every request so revoked roles and deleted users take effect immediately.
        $roles = $auth()->rolesForActiveUser($userId);
        if ($roles === null) {
            resetSession();
            $roles = [];
        } else {
            $loggedInUser = $username;
        }
    }

    $csrfToken = csrfToken();
    session_write_close();

    render(__DIR__ . '/templates/login.php', [
        'notice' => $notice,
        'error' => $error,
        'loggedInUser' => $loggedInUser,
        'roles' => $roles,
        'oldUsername' => $oldUsername,
        'csrfToken' => $csrfToken,
    ]);
    ob_end_flush();
} catch (Throwable $e) {
    ob_clean();
    error_log('login.php: ' . $e);
    http_response_code(500);
    render(__DIR__ . '/templates/error.php', []);
    ob_end_flush();
}
