<?php

declare(strict_types=1);

namespace App\Http;

const SESSION_USER_ID = 'user_id';
const SESSION_USERNAME = 'username';
const SESSION_CSRF = 'csrf_token';
const SESSION_CREATED_AT = 'created_at';
const SESSION_LAST_ACTIVITY = 'last_activity';

const SESSION_IDLE_SECONDS = 1800;
const SESSION_MAX_SECONDS = 28800;
// last_activity is refreshed at most this often, so most requests don't rewrite the session file.
const SESSION_TOUCH_SECONDS = 60;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postString(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
}

/**
 * The client's IP. X-Forwarded-For is only read when the direct peer is a trusted proxy.
 *
 * @param list<string> $trustedProxies
 */
function clientIp(array $trustedProxies): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

    if (!in_array($remote, $trustedProxies, true) || !is_string($forwarded) || $forwarded === '') {
        return $remote;
    }

    // Walk from the right: the first address not added by our own proxies is the client.
    foreach (array_reverse(array_map('trim', explode(',', $forwarded))) as $hop) {
        if (!in_array($hop, $trustedProxies, true)) {
            return filter_var($hop, FILTER_VALIDATE_IP) !== false ? $hop : $remote;
        }
    }

    return $remote;
}

/**
 * Sent before anything else, so even the error page carries them.
 */
function sendSecurityHeaders(): void
{
    header(
        "Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; "
        . "frame-ancestors 'none'; base-uri 'none'; object-src 'none'"
    );
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

function startSession(bool $httpsOnly): void
{
    ini_set('session.use_strict_mode', '1');
    // The __Host- prefix makes the browser refuse the cookie unless it is Secure, host-only and path=/.
    session_name($httpsOnly ? '__Host-sid' : 'sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $httpsOnly,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if ($httpsOnly) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Ends the session after SESSION_IDLE_SECONDS without a request or SESSION_MAX_SECONDS in total.
 */
function enforceSessionLifetime(): void
{
    $now = time();
    $createdAt = $_SESSION[SESSION_CREATED_AT] ?? null;
    $lastActivity = $_SESSION[SESSION_LAST_ACTIVITY] ?? null;

    $expired = (is_int($lastActivity) && $now - $lastActivity > SESSION_IDLE_SECONDS)
        || (is_int($createdAt) && $now - $createdAt > SESSION_MAX_SECONDS);
    if ($expired) {
        resetSession();
    }

    if (!is_int($_SESSION[SESSION_CREATED_AT] ?? null)) {
        $_SESSION[SESSION_CREATED_AT] = $now;
    }
    if (!is_int($lastActivity) || $expired || $now - $lastActivity >= SESSION_TOUCH_SECONDS) {
        $_SESSION[SESSION_LAST_ACTIVITY] = $now;
    }
}

/**
 * Logs the user out (if logged in) and starts a fresh session with a new ID and CSRF token.
 */
function resetSession(): void
{
    $_SESSION = [];
    renewSession();
}

/**
 * New session ID and CSRF token, keeping the data. Call when the user logs in.
 */
function renewSession(): void
{
    session_regenerate_id(true);
    unset($_SESSION[SESSION_CSRF]);
    $_SESSION[SESSION_CREATED_AT] = time();
}

/**
 * Runs slow work (bcrypt) without holding the session lock, so other tabs aren't blocked.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 */
function withoutSessionLock(callable $work): mixed
{
    session_write_close();
    try {
        return $work();
    } finally {
        session_start();
    }
}

function csrfToken(): string
{
    if (!is_string($_SESSION[SESSION_CSRF] ?? null)) {
        $_SESSION[SESSION_CSRF] = bin2hex(random_bytes(32));
    }

    return $_SESSION[SESSION_CSRF];
}

function isValidCsrfToken(string $token): bool
{
    $expected = $_SESSION[SESSION_CSRF] ?? null;

    return is_string($expected) && $token !== '' && hash_equals($expected, $token);
}

/**
 * Renders a template with only the given variables in scope.
 *
 * @param array<string, mixed> $vars
 */
function render(string $template, array $vars): void
{
    (static function (string $__template, array $__vars): void {
        extract($__vars, EXTR_SKIP);
        require $__template;
    })($template, $vars);
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}
