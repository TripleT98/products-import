<?php

declare(strict_types=1);

namespace App\Dto;

use App\Http\ApiException;

final readonly class IssuePagination
{
    private function __construct(public int $page, public int $limit)
    {
    }

    public static function fromQueryString(string $query): self
    {
        $values = ['page' => 1, 'limit' => 50];
        $seen = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            $value = urldecode($value);
            if (preg_match('/%(?![a-f0-9]{2})/i', $pair) || preg_match('/^(page|limit)\[/', $key)) {
                throw self::invalid();
            }
            if (!array_key_exists($key, $values)) {
                continue;
            }
            if (isset($seen[$key]) || !preg_match('/^[0-9]{1,10}$/D', $value) || (int) $value < 1 || (int) $value > ($key === 'page' ? 2147483647 : 100)) {
                throw self::invalid();
            }
            $seen[$key] = true;
            $values[$key] = (int) $value;
        }
        return new self($values['page'], $values['limit']);
    }

    private static function invalid(): ApiException
    {
        return new ApiException(400, 'INVALID_REQUEST', 'Некорректные параметры запроса.');
    }
}
