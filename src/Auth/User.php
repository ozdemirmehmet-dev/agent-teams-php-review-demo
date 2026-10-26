<?php

declare(strict_types=1);

namespace App\Auth;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $passwordHash,
    ) {
    }
}
