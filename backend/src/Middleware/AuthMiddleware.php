<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\ApiException;
use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthService $auth)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $path = $route?->getPattern() ?? '';
        // Use the matched pattern, so percent-encoded request paths cannot bypass auth.
        // Guard all matched API routes by default, including routes added in later tasks.
        if ($route === null || ($path !== '/api' && !str_starts_with($path, '/api/'))
            || ($path === '/api/auth/login' && $request->getMethod() === 'POST')
            || ($path === '/api/health' && $request->getMethod() === 'GET')
            || ($path === '/api/_diagnostics' && $request->getMethod() === 'GET')) {
            return $handler->handle($request);
        }
        $headers = $request->getHeader('Authorization');
        if (count($headers) !== 1 || !preg_match('/^Bearer ([A-Za-z0-9_\-.]+)$/iD', $headers[0], $matches)) {
            throw ApiException::unauthorized();
        }
        $user = $this->auth->authenticate($matches[1]);
        return $handler->handle($request->withAttribute('authUser', $user))->withHeader('Cache-Control', 'no-store');
    }
}
