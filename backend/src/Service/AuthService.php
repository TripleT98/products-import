<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Http\ApiException;
use App\Repository\UserRepository;
use App\Security\AuthenticatedUser;
use App\Security\PasswordHasher;
use App\Security\TokenService;
use LogicException;

final class AuthService
{
    public function __construct(private UserRepository $users, private TokenService $tokens, private PasswordHasher $passwords)
    {
    }

    /** @return array{accessToken: string, tokenType: string, expiresIn: int, user: array{id: int, username: string}} */
    public function login(string $username, string $password): array
    {
        $user = $this->users->findByUsername($username);
        // Perform password verification for unknown usernames as well.
        $hash = $user?->getPasswordHash() ?? '$2y$10$VyO8iBFaEfiziaHo/fY0Kupa880K/JGzxO2sE2XlEs6U2IowEqNZ2';
        $valid = $this->passwords->verify($password, $hash);
        if (!$valid || $user === null) {
            throw ApiException::unauthorized();
        }
        $identity = $this->identity($user);
        return $this->tokens->issue($identity->id) + ['user' => ['id' => $identity->id, 'username' => $identity->username]];
    }

    public function authenticate(string $token): AuthenticatedUser
    {
        $user = $this->users->find($this->tokens->subject($token));
        if ($user === null) {
            throw ApiException::unauthorized();
        }
        return $this->identity($user);
    }

    private function identity(User $user): AuthenticatedUser
    {
        return new AuthenticatedUser($user->getId() ?? throw new LogicException('Unpersisted user.'), $user->getUsername());
    }
}
