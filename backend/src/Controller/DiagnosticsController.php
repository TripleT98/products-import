<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\RuntimeDiagnostics;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DiagnosticsController
{
    public function __construct(private readonly RuntimeDiagnostics $diagnostics)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $report = $this->diagnostics->inspect();
        $response->getBody()->write(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($report['status'] === 'ok' ? 200 : 503);
    }
}
