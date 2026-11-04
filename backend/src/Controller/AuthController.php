<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiException;
use App\Service\AuthService;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

final class AuthController
{
    public function __construct(private AuthService $auth)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($contentType !== 'application/json') {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Требуется application/json.');
        }
        try {
            $data = json_decode((string) $request->getBody(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Некорректные параметры запроса.');
        }
        if (!$data instanceof stdClass || count(get_object_vars($data)) !== 2
            || !isset($data->username, $data->password) || !is_string($data->username) || !is_string($data->password)
            || $data->username === '' || $data->password === '') {
            throw new ApiException(400, 'INVALID_REQUEST', 'Некорректные параметры запроса.');
        }
        $result = $this->auth->login($data->username, $data->password);
        $response->getBody()->write(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
