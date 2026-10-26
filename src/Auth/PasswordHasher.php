<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The only place that knows how passwords are stored, including the old formats.
 *
 * Stored formats:
 *   - bcrypt (current)
 *   - "legacy-md5:" + bcrypt(md5(password))  written by migrate_md5_passwords.php
 *   - raw md5 (32 hex)                        accepted only until the migration has finished
 */
final class PasswordHasher
{
    public const ALGORITHM = PASSWORD_BCRYPT;
    // Pinned so the dummy hash below always costs the same as a real one, whatever PHP's default is.
    public const OPTIONS = ['cost' => 12];

    private const LEGACY_MD5_PREFIX = 'legacy-md5:';
    // bcrypt (cost 12) of a random value nobody knows. Must use the same cost as OPTIONS.
    private const DUMMY_HASH = '$2y$12$T4TpRBubTnfqDRBiwvw8SOCk0GdJJIFYf9M8XT14ihGrI5uTQK2Tq';

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, self::ALGORITHM, self::OPTIONS);
    }

    /**
     * Runs exactly one bcrypt check on every path, so the response time doesn't reveal
     * whether the user exists or which format their hash is in.
     */
    public function verify(?string $stored, #[\SensitiveParameter] string $password): bool
    {
        if ($stored === null) {
            password_verify($password, self::DUMMY_HASH);

            return false;
        }

        if (self::isRawMd5($stored)) {
            password_verify($password, self::DUMMY_HASH);

            return hash_equals(strtolower($stored), md5($password));
        }

        if (str_starts_with($stored, self::LEGACY_MD5_PREFIX)) {
            return password_verify(md5($password), substr($stored, strlen(self::LEGACY_MD5_PREFIX)));
        }

        return password_verify($password, $stored);
    }

    public function needsUpgrade(string $stored): bool
    {
        return self::isRawMd5($stored)
            || str_starts_with($stored, self::LEGACY_MD5_PREFIX)
            || password_needs_rehash($stored, self::ALGORITHM, self::OPTIONS);
    }

    /**
     * For the migration: returns what to store instead of $stored, or null if it needs no migration.
     * Users keep their passwords; they are moved to a normal hash on their next login.
     */
    public function migrateStored(string $stored): ?string
    {
        if (self::isRawMd5($stored)) {
            return self::LEGACY_MD5_PREFIX . $this->hash(strtolower($stored));
        }

        if (str_starts_with($stored, self::LEGACY_MD5_PREFIX) || password_get_info($stored)['algo'] !== null) {
            return null;
        }

        // Anything else is a plain-text password left over from the first version of login.php.
        return $this->hash($stored);
    }

    private static function isRawMd5(string $stored): bool
    {
        return preg_match('/^[0-9a-f]{32}$/iD', $stored) === 1;
    }
}
