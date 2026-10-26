<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Config
{
    /**
     * @param list<string> $trustedProxies
     */
    private function __construct(
        public readonly string $dbHost,
        public readonly string $dbUser,
        #[\SensitiveParameter] public readonly string $dbPass,
        public readonly string $dbName,
        public readonly int $dbPort,
        public readonly bool $httpsOnly,
        public readonly array $trustedProxies,
    ) {
    }

    /**
     * Reads settings from the environment and stops with a clear error if any are missing or invalid.
     *
     * HTTPS_ONLY=0        turns off the Secure cookie flag and HSTS (local HTTP development only).
     * TRUSTED_PROXIES     comma-separated proxy IPs whose X-Forwarded-For header may be trusted.
     */
    public static function fromEnv(): self
    {
        $port = getenv('DB_PORT') ?: '3306';
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('DB_PORT must be a number between 1 and 65535');
        }

        $proxies = array_values(array_filter(array_map('trim', explode(',', getenv('TRUSTED_PROXIES') ?: ''))));

        return new self(
            self::required('DB_HOST'),
            self::required('DB_USER'),
            self::required('DB_PASS'),
            self::required('DB_NAME'),
            (int) $port,
            getenv('HTTPS_ONLY') !== '0',
            $proxies,
        );
    }

    private static function required(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException("Missing required environment variable {$name}");
        }

        return $value;
    }
}
