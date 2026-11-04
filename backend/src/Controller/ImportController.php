<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiException;
use App\Security\AuthenticatedUser;
use App\Service\ImportUploadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ImportController
{
    public function __construct(private ImportUploadService $uploads, private \App\Service\ImportRateLimiter $limiter)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('authUser');
        if (!$user instanceof AuthenticatedUser) {
            throw ApiException::unauthorized();
        }
        $this->limiter->consume($user->id);
        $accepted = $this->uploads->accept($request, $user->id);
        $response->getBody()->write(json_encode($accepted, JSON_THROW_ON_ERROR));
        return $response->withStatus(202)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
