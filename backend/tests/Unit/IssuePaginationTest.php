<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Dto\IssuePagination;
use App\Http\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IssuePaginationTest extends TestCase
{
    public function testDefaultsEncodedNamesAndLargeOffsets(): void
    {
        self::assertSame(50, IssuePagination::fromQueryString('')->limit);
        $page = IssuePagination::fromQueryString('page=2147483647&%6cimit=100');
        self::assertSame(2147483647, $page->page);
        self::assertSame(100, $page->limit);
    }

    #[DataProvider('invalidQueries')]
    public function testRejectsAmbiguousOrUnboundedQueries(string $query): void
    {
        $this->expectException(ApiException::class);
        IssuePagination::fromQueryString($query);
    }

    public static function invalidQueries(): array
    {
        return array_map(static fn (string $query): array => [$query], ['page=0','page=-1','page=1.2','page=','page=2147483648','page=9999999999999999','limit=101','limit=0','limit[]=1','page=1&page=2','limit=1&%6cimit=2','page=%xx']);
    }
}
