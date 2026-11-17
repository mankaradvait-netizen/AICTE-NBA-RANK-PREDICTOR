<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

class InvalidRefreshTokenException extends RuntimeException
{
}
