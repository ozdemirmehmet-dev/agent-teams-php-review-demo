<?php

declare(strict_types=1);

namespace App\Auth;

enum LoginResult
{
    case Success;
    case InvalidCredentials;
    case Throttled;
}
