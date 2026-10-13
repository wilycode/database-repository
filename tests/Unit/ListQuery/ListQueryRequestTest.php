<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Unit\ListQuery;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WilyCode\Database\Repository\ListQuery\ListQueryRequest;

final class ListQueryRequestTest extends TestCase
{
    public function testFromQueryNormalizesSupportedInput(): void
    {
        $request = ListQueryRequest::fromQuery([
            'page' => '2',
            'perPage' => '50',
            'filter' => ['status' => 'active'],
            'sort' => '-createdAt,name',
            'include' => 'profile,roles',
        ]);

        self::assertSame(2, $request->page);
        self::assertSame(50, $request->perPage);
        self::assertSame(['status' => 'active'], $request->filters);
        self::assertSame(['-createdAt', 'name'], $request->sort);
        self::assertSame(['profile', 'roles'], $request->includes);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'zero page' => [['page' => 0]];
        yield 'non numeric page' => [['page' => 'abc']];
        yield 'too many items' => [['perPage' => 201]];
        yield 'invalid filter shape' => [['filter' => 'status=active']];
        yield 'invalid sort shape' => [['sort' => ['name']]];
        yield 'invalid include shape' => [['include' => ['profile']]];
    }

    #[DataProvider('invalidInputProvider')]
    public function testFromQueryRejectsInvalidInput(array $query): void
    {
        $this->expectException(InvalidArgumentException::class);

        ListQueryRequest::fromQuery($query);
    }
}
