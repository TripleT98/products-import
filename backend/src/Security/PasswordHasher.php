<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        // PASSWORD_DEFAULT is bcrypt in this PHP version; reject silent 72-byte truncation.
        if ($password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new InvalidArgumentException('Invalid password length or content.');
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool
    {
        if ($password === '' || str_contains($password, "\0") || (str_starts_with($hash, '$2') && strlen($password) > 72)) {
            return false;
        }
        return password_verify($password, $hash);
    }
}
