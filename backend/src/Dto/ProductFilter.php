<?php

declare(strict_types=1);

namespace App\Dto;

use App\Http\ApiException;

final readonly class ProductFilter
{
    private function __construct(public int $page, public int $limit, public string $name, public ?string $priceMin, public ?string $priceMax)
    {
    }

    public static function fromQueryString(string $query): self
    {
        $values = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            if (preg_match('/%(?![0-9a-fA-F]{2})/', $pair)) {
                throw self::invalid();
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if (preg_match('/^(page|limit|name|price_min|price_max)\[/', $key)) {
                throw self::invalid();
            }
            if (!in_array($key, ['page', 'limit', 'name', 'price_min', 'price_max'], true)) {
                continue;
            }
            if (array_key_exists($key, $values)) {
                throw self::invalid();
            }
            $values[$key] = urldecode($value);
        }
        $page = isset($values['page']) ? self::integer($values['page'], 2147483647) : 1;
        $limit = isset($values['limit']) ? self::integer($values['limit'], 100) : 20;
        $name = $values['name'] ?? '';
        if (str_contains($name, "\0") || !preg_match('//u', $name)) {
            throw self::invalid();
        }
        $min = isset($values['price_min']) ? self::decimal($values['price_min']) : null;
        $max = isset($values['price_max']) ? self::decimal($values['price_max']) : null;
        if ($min !== null && $max !== null && strcmp(str_pad($min, 19, '0', STR_PAD_LEFT), str_pad($max, 19, '0', STR_PAD_LEFT)) > 0) {
            throw self::invalid();
        }
        return new self($page, $limit, trim($name), $min, $max);
    }

    public static function id(string $value): int
    {
        return self::integer($value, 2147483647);
    }

    private static function integer(string $value, int $maximum): int
    {
        if (!preg_match('/^[0-9]+$/D', $value)) {
            throw self::invalid();
        }
        $normalized = ltrim($value, '0');
        $bound = (string) $maximum;
        if ($normalized === '' || strlen($normalized) > strlen($bound) || (strlen($normalized) === strlen($bound) && strcmp($normalized, $bound) > 0)) {
            throw self::invalid();
        }
        return (int) $normalized;
    }

    private static function decimal(string $value): string
    {
        if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', $value, $parts)) {
            throw self::invalid();
        }
        $integer = ltrim($parts[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        if (strlen($integer) > 16) {
            throw self::invalid();
        }
        return $integer . '.' . str_pad($parts[2] ?? '', 2, '0');
    }

    private static function invalid(): ApiException
    {
        return new ApiException(400, 'INVALID_REQUEST', 'Некорректные параметры запроса.');
    }
}
