<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Single source for credential rules; the service, the messages and the HTML form all read from here.
 */
final class CredentialRules
{
    public const USERNAME_MIN = 3;
    public const USERNAME_MAX = 32;
    public const USERNAME_CHARS = 'A-Za-z0-9_';
    public const PASSWORD_MIN_CHARS = 8;
    // bcrypt ignores everything after 72 bytes, so longer new passwords are rejected.
    public const PASSWORD_MAX_BYTES = 72;
    // Upper bound for login input; older accounts may have passwords longer than 72 bytes.
    public const LOGIN_PASSWORD_MAX_BYTES = 1024;
    public const EMAIL_MAX = 254;

    public static function usernamePattern(): string
    {
        return sprintf('[%s]{%d,%d}', self::USERNAME_CHARS, self::USERNAME_MIN, self::USERNAME_MAX);
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match('/^' . self::usernamePattern() . '$/D', $username) === 1;
    }

    public static function isValidNewPassword(string $password): bool
    {
        return mb_strlen($password, 'UTF-8') >= self::PASSWORD_MIN_CHARS
            && strlen($password) <= self::PASSWORD_MAX_BYTES;
    }

    public static function isValidEmail(string $email): bool
    {
        return strlen($email) <= self::EMAIL_MAX
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
