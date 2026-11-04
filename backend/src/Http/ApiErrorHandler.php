<?php

declare(strict_types=1);

namespace App\Http;

use Doctrine\DBAL\Exception as DatabaseException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Throwable;

final class ApiErrorHandler
{
    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function __invoke(ServerRequestInterface $request, Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails): ResponseInterface
    {
        $status = match (true) {
            $exception instanceof ApiException => $exception->status,
            $exception instanceof DatabaseException => 503,
            $exception instanceof HttpException => $exception->getCode(),
            default => 500,
        };
        $code = match ($status) {
            400 => 'INVALID_REQUEST', 401 => 'UNAUTHORIZED', 404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED', 503 => 'SERVICE_UNAVAILABLE', default => 'INTERNAL_ERROR',
        };
        $message = match ($status) {
            400 => 'Некорректные параметры запроса.', 401 => 'Требуется вход.',
            404 => 'Ресурс не найден.', 405 => 'Метод не поддерживается.',
            503 => 'Сервис временно недоступен.', default => 'Внутренняя ошибка.',
        };
        if ($exception instanceof ApiException) {
            $code = $exception->errorCode;
            $message = $exception->getMessage();
        }
        if ($status >= 500) {
            // Never log body, Authorization, query, SQL, exception text or credentials.
            error_log(json_encode(['requestId' => $request->getAttribute('requestId'), 'status' => $status, 'exception' => $exception::class], JSON_THROW_ON_ERROR));
        }
        $response = $this->responses->createResponse($status);
        $response->getBody()->write(json_encode(['error' => ['code' => $code, 'message' => $message, 'details' => []]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        if ($exception instanceof ApiException && $exception->retryAfter !== null) {
            $response = $response->withHeader('Retry-After', (string) $exception->retryAfter);
        }
        return $status === 401 ? $response->withHeader('WWW-Authenticate', 'Bearer') : $response;
    }
}
