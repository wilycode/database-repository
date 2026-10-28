<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WilyCode\Database\Repository\PaginationResult;

final class PaginationResultTest extends TestCase
{
    public function testKeepsPaginationDataAndCalculatesLastPage(): void
    {
        $result = new PaginationResult(
            items: [],
            total: 41,
            page: 2,
            perPage: 20,
        );

        self::assertSame([], $result->items);
        self::assertSame(41, $result->total);
        self::assertSame(2, $result->page);
        self::assertSame(20, $result->perPage);
        self::assertSame(3, $result->lastPage());
    }

    public function testEmptyResultStillHasOnePage(): void
    {
        $result = new PaginationResult([], 0, 1, 20);

        self::assertSame(1, $result->lastPage());
    }

    public function testRejectsNegativeTotal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaginationResult([], -1, 1, 20);
    }

    public function testRejectsInvalidPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaginationResult([], 0, 0, 20);
    }

    public function testRejectsInvalidPageSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaginationResult([], 0, 1, 0);
    }
}
