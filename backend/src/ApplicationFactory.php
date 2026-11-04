<?php

declare(strict_types=1);

namespace App;

use App\Controller\AuthController;
use App\Controller\DiagnosticsController;
use App\Entity\User;
use App\Http\ApiErrorHandler;
use App\Infrastructure\DoctrineFactory;
use App\Middleware\AuthMiddleware;
use App\Middleware\RequestIdMiddleware;
use App\Security\PasswordHasher;
use App\Security\TokenService;
use App\Service\AuthService;
use App\Service\RuntimeDiagnostics;
use Doctrine\ORM\EntityManagerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

final class ApplicationFactory
{
    public static function create(?EntityManagerInterface $entityManager = null, ?TokenService $tokens = null, ?\App\Service\ImportUploadService $uploads = null, ?\App\Service\ImportRateLimiter $limiter = null, ?\App\Import\Job\ImportJobService $jobs = null): App
    {
        $app = AppFactory::create();
        $entityManager ??= DoctrineFactory::entityManager();
        $auth = new AuthService($entityManager->getRepository(User::class), $tokens ?? new TokenService(), new PasswordHasher());
        $app->post('/api/auth/login', new AuthController($auth));
        $app->post('/api/imports', new \App\Controller\ImportController($uploads ?? \App\Service\ImportUploadService::fromEnvironment(), $limiter ?? \App\Service\ImportRateLimiter::fromEnvironment()));
        $reports = new \App\Controller\ImportReportController($jobs ?? new \App\Import\Job\ImportJobService());
        $app->get('/api/imports/{id}', [$reports, 'status']);
        $app->get('/api/imports/{id}/issues', [$reports, 'issues']);
        $app->get('/api/health', new \App\Controller\HealthController(new RuntimeDiagnostics()));
        $products = new \App\Controller\ProductController(new \App\Service\ProductQueryService($entityManager->getRepository(\App\Entity\Product::class)));
        $app->get('/api/products', [$products, 'list']);
        $app->get('/api/products/{id}', [$products, 'detail']);
        // Optional infrastructure/storage diagnostics retained for operator checks.
        if (filter_var(getenv('APP_DIAGNOSTICS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL)) {
            $app->get('/api/_diagnostics', new DiagnosticsController(new RuntimeDiagnostics()));
        }
        $app->add(new AuthMiddleware($auth));
        $app->addRoutingMiddleware();
        $errors = $app->addErrorMiddleware(false, true, true);
        $errors->setDefaultErrorHandler(new ApiErrorHandler($app->getResponseFactory()));
        $app->add(new RequestIdMiddleware());
        return $app;
    }
}
