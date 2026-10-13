<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Unit\ListQuery;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\Repository\Repository;
use WilyCode\Database\Repository\ListQuery\AbstractListFilter;
use WilyCode\Database\Repository\ListQuery\AllowedFilter;
use WilyCode\Database\Repository\ListQuery\AllowedInclude;
use WilyCode\Database\Repository\ListQuery\AllowedSort;
use WilyCode\Database\Repository\Tests\Fixtures\Read\User;

final class AbstractListFilterTest extends TestCase
{
    private function repo(): Repository
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        return new Repository($executor, User::class);
    }

    public function test_applyTo_applies_allowed_filters_sorts_and_includes(): void
    {
        $spec = TestUserListFilter::fromQuery([
            'filter' => [
                'status' => 'active',
                'name' => 'john',
            ],
            'sort' => '-createdAt,name',
            'include' => 'profile,roles:id|name',
        ]);

        $repo = $spec->applyTo($this->repo());

        $wheres = $this->getProtected($repo, 'wheres');
        $rawWheres = $this->getProtected($repo, 'rawWheres');
        $orders = $this->getProtected($repo, 'orders');
        $withs = $this->getProtected($repo, 'withs');

        self::assertCount(1, $wheres);
        self::assertSame('status', $wheres[0]['column']);
        self::assertSame('active', $wheres[0]['value']);

        self::assertCount(1, $rawWheres);
        self::assertStringContainsString('LIKE ?', $rawWheres[0]['condition']);
        self::assertSame(['%john%'], $rawWheres[0]['params']);

        // Two explicit sorts + PK tie-breaker injected later in query build.
        self::assertCount(2, $orders);
        self::assertSame('created_at', $orders[0]['column']);
        self::assertSame('DESC', $orders[0]['direction']);
        self::assertSame('name', $orders[1]['column']);
        self::assertSame('ASC', $orders[1]['direction']);

        self::assertCount(2, $withs);
        self::assertSame('profile', $withs[0]['path']);
        self::assertSame('*', $withs[0]['columns']);
        self::assertSame('roles', $withs[1]['path']);
        self::assertSame(['id', 'name'], $withs[1]['columns']);
    }

    public function test_strict_mode_rejects_unknown_filter_sort_include(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestUserListFilter::fromQuery([
            'filter' => ['unknown' => 'x'],
        ], strict: true)->applyTo($this->repo());
    }

    public function test_non_strict_mode_ignores_unknown_filter_sort_include(): void
    {
        $spec = TestUserListFilter::fromQuery([
            'filter' => ['unknown' => 'x', 'status' => 'active'],
            'sort' => 'unknown',
            'include' => 'unknown,profile',
        ], strict: false);

        $repo = $spec->applyTo($this->repo());

        $wheres = $this->getProtected($repo, 'wheres');
        $withs = $this->getProtected($repo, 'withs');

        self::assertCount(1, $wheres);
        self::assertSame('status', $wheres[0]['column']);
        self::assertCount(1, $withs);
        self::assertSame('profile', $withs[0]['path']);
    }

    public function test_withPagination_returns_a_filter_with_reusable_view_data(): void
    {
        $filter = TestUserListFilter::fromQuery([
            'page' => 2,
            'perPage' => 20,
        ])->withPagination(43);

        self::assertSame([
            'page' => 2,
            'pageCount' => 3,
            'perPage' => 20,
            'total' => 43,
            'from' => 21,
            'to' => 40,
        ], $filter->getPagination());
        self::assertSame(2, $filter->page());
    }

    public function test_withPagination_clamps_page_and_handles_empty_results(): void
    {
        $filter = TestUserListFilter::fromQuery([
            'page' => 9,
            'perPage' => 20,
        ]);

        $resolvedFilter = $filter->withPagination(43);

        self::assertSame([
            'page' => 3,
            'pageCount' => 3,
            'perPage' => 20,
            'total' => 43,
            'from' => 41,
            'to' => 43,
        ], $resolvedFilter->getPagination());
        self::assertSame(9, $filter->page());
        self::assertSame(3, $resolvedFilter->page());

        self::assertSame([
            'page' => 1,
            'pageCount' => 1,
            'perPage' => 20,
            'total' => 0,
            'from' => 0,
            'to' => 0,
        ], $filter->withPagination(0)->getPagination());
    }

    public function test_withPagination_rejects_negative_total(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TestUserListFilter::fromQuery([])->withPagination(-1);
    }

    public function test_getPagination_requires_resolved_pagination(): void
    {
        $this->expectException(\LogicException::class);

        TestUserListFilter::fromQuery([])->getPagination();
    }

    /** @return mixed */
    private function getProtected(object $obj, string $prop): mixed
    {
        $r = new \ReflectionClass($obj);
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        return $p->getValue($obj);
    }
}

final class TestUserListFilter extends AbstractListFilter
{
    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::exact('status'),
            AllowedFilter::partial('name'),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            AllowedSort::field('createdAt', 'created_at'),
            AllowedSort::field('name'),
        ];
    }

    protected function allowedIncludes(): array
    {
        return [
            AllowedInclude::simple('profile'),
            AllowedInclude::simple('roles'),
        ];
    }
}
