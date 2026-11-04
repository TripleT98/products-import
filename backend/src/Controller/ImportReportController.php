<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\IssuePagination;
use App\Http\ApiException;
use App\Import\Job\ImportJobService;
use App\Security\AuthenticatedUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ImportReportController
{
    public function __construct(private ImportJobService $jobs)
    {
    }

    /** @param array{id: string} $args */
    public function status(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->report($request, $response, $args['id'], false);
    }

    /** @param array{id: string} $args */
    public function issues(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->report($request, $response, $args['id'], true);
    }

    private function report(ServerRequestInterface $request, ResponseInterface $response, string $id, bool $issues): ResponseInterface
    {
        $user = $request->getAttribute('authUser');
        if (!$user instanceof AuthenticatedUser) {
            throw ApiException::unauthorized();
        }
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Некорректный идентификатор задачи.');
        }
        if ($issues) {
            $filter = IssuePagination::fromQueryString($request->getUri()->getQuery());
            $data = $this->jobs->issuesForOwner($id, $user->id, $filter->page, $filter->limit);
        } else {
            $data = $this->jobs->findForOwner($id, $user->id);
        }
        if ($data === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Ресурс не найден.');
        }
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
