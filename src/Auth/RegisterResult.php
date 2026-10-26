<?php

declare(strict_types=1);

namespace App\Auth;

enum RegisterResult
{
    case Created;
    case Invalid;
    case UsernameTaken;
    case Throttled;
}
