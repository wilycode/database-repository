<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\Query\Dialect\MySqlDialect;
use WilyCode\Database\Query\Dialect\PostgreSqlDialect;
use WilyCode\Database\Query\Dialect\SqliteDialect;
use WilyCode\Database\Query\Query;
use WilyCode\Database\Repository\Query\PaginationCountQuery;

final class PaginationDialectTest extends TestCase
{
    public function testCountCanBeCompiledForEachConnectionWithoutChangingGlobalDialect(): void
    {
        $select = Query::select('articles', 'a')->columns(['title' => 'a.title'])
            ->where('a.status', 1)->orderBy('a.id')->limit(2)->offset(3);
        $query = new PaginationCountQuery($select);
        foreach ([new MySqlDialect(), new PostgreSqlDialect(), new SqliteDialect()] as $dialect) {
            $compiled = $query->compile($dialect);
            self::assertStringContainsString('AS ' . $dialect->quoteIdentifier('title'), $compiled->sql);
            self::assertStringNotContainsString('ORDER BY', $compiled->sql);
            self::assertStringNotContainsString('LIMIT', $compiled->sql);
            self::assertStringNotContainsString('OFFSET', $compiled->sql);
            self::assertSame([1], array_values($compiled->params));
        }
        self::assertStringContainsString('LIMIT 2 OFFSET 3', $select->compile(new PostgreSqlDialect())->sql);
    }
}
