<?php

declare(strict_types=1);

namespace App\Auth;

use InvalidArgumentException;

final class LoginOutcome
{
    private function __construct(
        public readonly LoginResult $result,
        public readonly ?User $user,
    ) {
    }

    public static function success(User $user): self
    {
        return new self(LoginResult::Success, $user);
    }

    public static function failure(LoginResult $result): self
    {
        if ($result === LoginResult::Success) {
            throw new InvalidArgumentException('A successful login needs a user; use LoginOutcome::success().');
        }

        return new self($result, null);
    }

    /**
     * @phpstan-assert-if-true !null $this->user
     */
    public function isSuccess(): bool
    {
        return $this->result === LoginResult::Success;
    }
}
