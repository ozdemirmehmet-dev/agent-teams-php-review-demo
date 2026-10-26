<?php

declare(strict_types=1);

namespace App\Auth;

use mysqli;
use mysqli_sql_exception;

final class AuthService
{
    private const LOGIN_WINDOW_SECONDS = 900;
    // Per IP (or IPv6 /64): high, so users behind a shared address don't lock each other out.
    private const LOGIN_IP_LIMIT = 50;
    // Per username + IP: stops guessing one account without letting others lock it from elsewhere.
    private const LOGIN_PAIR_LIMIT = 5;
    private const REGISTER_WINDOW_SECONDS = 3600;
    private const REGISTER_IP_LIMIT = 5;

    private const MYSQL_DUPLICATE_KEY = 1062;

    public function __construct(
        private readonly mysqli $db,
        private readonly Throttle $throttle,
        private readonly PasswordHasher $hasher = new PasswordHasher(),
    ) {
    }

    public function attemptLogin(string $username, #[\SensitiveParameter] string $password, string $ip): LoginOutcome
    {
        $bucket = self::ipBucket($ip);

        // Checked first and alone: once an address is over its limit it can't create more counter rows.
        if ($this->throttle->hit('login-ip:' . $bucket, self::LOGIN_WINDOW_SECONDS) > self::LOGIN_IP_LIMIT) {
            return LoginOutcome::failure(LoginResult::Throttled);
        }

        $isWellFormed = CredentialRules::isValidUsername($username)
            && strlen($password) <= CredentialRules::LOGIN_PASSWORD_MAX_BYTES;
        $pairKey = 'login-pair:' . strtolower($username) . '|' . $bucket;

        if ($isWellFormed && $this->throttle->hit($pairKey, self::LOGIN_WINDOW_SECONDS) > self::LOGIN_PAIR_LIMIT) {
            return LoginOutcome::failure(LoginResult::Throttled);
        }

        $user = $isWellFormed ? $this->findUserByUsername($username) : null;
        // Called for unknown users too (with null), so every path costs one bcrypt.
        $passwordMatches = $this->hasher->verify($user?->passwordHash, $password);

        if ($user === null || !$passwordMatches) {
            $this->logAttempt($isWellFormed ? $username : '', $ip, false);

            return LoginOutcome::failure(LoginResult::InvalidCredentials);
        }

        if ($this->hasher->needsUpgrade($user->passwordHash)) {
            $this->updatePasswordHash($user->id, $this->hasher->hash($password));
        }
        $this->throttle->clear($pairKey);
        $this->logAttempt($user->username, $ip, true);

        return LoginOutcome::success($user);
    }

    public function register(
        string $username,
        #[\SensitiveParameter] string $password,
        string $email,
        string $ip,
    ): RegisterResult {
        // Counted before hashing so repeated requests can't be used to burn CPU.
        $hits = $this->throttle->hit('register-ip:' . self::ipBucket($ip), self::REGISTER_WINDOW_SECONDS);
        if ($hits > self::REGISTER_IP_LIMIT) {
            return RegisterResult::Throttled;
        }

        $isValid = CredentialRules::isValidUsername($username)
            && CredentialRules::isValidNewPassword($password)
            && CredentialRules::isValidEmail($email);
        if (!$isValid) {
            return RegisterResult::Invalid;
        }

        $hash = $this->hasher->hash($password);
        $stmt = $this->db->prepare('INSERT INTO users (username, password, email) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $username, $hash, $email);

        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === self::MYSQL_DUPLICATE_KEY) {
                return RegisterResult::UsernameTaken;
            }
            throw $e;
        } finally {
            $stmt->close();
        }

        return RegisterResult::Created;
    }

    /**
     * Returns the user's role names, or null if the user no longer exists (the session should end).
     *
     * @return list<string>|null
     */
    public function rolesForActiveUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.name
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = ?
             ORDER BY r.name'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_NUM);
        $stmt->close();

        if ($rows === []) {
            return null;
        }

        $roles = [];
        foreach ($rows as [$name]) {
            if ($name !== null) {
                $roles[] = (string) $name;
            }
        }

        return $roles;
    }

    /**
     * Groups IPv6 addresses by /64, because one client usually controls a whole /64.
     */
    private static function ipBucket(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    private function findUserByUsername(string $username): ?User
    {
        $stmt = $this->db->prepare('SELECT id, username, password FROM users WHERE username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!is_array($row)) {
            return null;
        }

        return new User((int) $row['id'], (string) $row['username'], (string) $row['password']);
    }

    private function updatePasswordHash(int $userId, string $hash): void
    {
        $stmt = $this->db->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function logAttempt(string $username, string $ip, bool $success): void
    {
        $successFlag = $success ? 1 : 0;
        $stmt = $this->db->prepare(
            'INSERT INTO login_log (username, ip, success, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->bind_param('ssi', $username, $ip, $successFlag);
        $stmt->execute();
        $stmt->close();
    }
}
