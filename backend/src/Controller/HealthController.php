<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiException;
use App\Service\RuntimeDiagnostics;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HealthController
{
    public function __construct(private RuntimeDiagnostics $diagnostics)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $report = $this->diagnostics->readiness();
        if ($report['status'] !== 'ok') {
            throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Сервис временно недоступен.');
        }
        $response->getBody()->write(json_encode($report, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
