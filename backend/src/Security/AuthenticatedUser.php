<?php

declare(strict_types=1);

namespace App\Security;

final readonly class AuthenticatedUser
{
    public function __construct(public int $id, public string $username)
    {
    }
}
