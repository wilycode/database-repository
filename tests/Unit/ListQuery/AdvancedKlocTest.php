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

final class AdvancedKlocTest extends TestCase
{
    private function repo(): Repository
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        return new Repository($executor, User::class);
    }

    public function test_bool_filter_equals_and_null_check(): void
    {
        $spec = TestAdvancedFilter::fromQuery([
            'filter' => [
                'active' => 'true',
                'archived' => 'false',
            ],
        ]);

        $repo = $spec->applyTo($this->repo());

        $wheres = $this->getProtected($repo, 'wheres');
        $rawWheres = $this->getProtected($repo, 'rawWheres');

        self::assertCount(1, $wheres);
        self::assertSame('is_active', $wheres[0]['column']);
        self::assertSame(1, $wheres[0]['value']);

        self::assertCount(1, $rawWheres);
        self::assertStringContainsString('archived_at IS NULL', $rawWheres[0]['condition']);
    }

    public function test_date_range_filter_builds_between(): void
    {
        $spec = TestAdvancedFilter::fromQuery([
            'filter' => [
                'createdAt' => [
                    'from' => '2026-01-01',
                    'to' => '2026-01-31',
                ],
            ],
        ]);

        $repo = $spec->applyTo($this->repo());
        $rawWheres = $this->getProtected($repo, 'rawWheres');

        self::assertCount(1, $rawWheres);
        self::assertStringContainsString('created_at BETWEEN ? AND ?', $rawWheres[0]['condition']);
        self::assertSame(['2026-01-01', '2026-01-31'], $rawWheres[0]['params']);
    }

    public function test_default_sort_is_used_when_request_sort_is_empty(): void
    {
        $spec = TestAdvancedFilter::fromQuery([
            'filter' => [],
        ]);

        $repo = $spec->applyTo($this->repo());
        $orders = $this->getProtected($repo, 'orders');

        self::assertCount(2, $orders);
        self::assertSame('created_at', $orders[0]['column']);
        self::assertSame('DESC', $orders[0]['direction']);
        self::assertSame('name', $orders[1]['column']);
        self::assertSame('ASC', $orders[1]['direction']);
    }

    public function test_tree_include_allows_nested_paths_and_maps_base_path(): void
    {
        $spec = TestAdvancedFilter::fromQuery([
            'include' => 'roles.permissions:id|name,profile',
        ]);

        $repo = $spec->applyTo($this->repo());
        $withs = $this->getProtected($repo, 'withs');

        self::assertCount(2, $withs);
        self::assertSame('userRoles.permissions', $withs[0]['path']);
        self::assertSame(['id', 'name'], $withs[0]['columns']);
        self::assertSame('profile', $withs[1]['path']);
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

final class TestAdvancedFilter extends AbstractListFilter
{
    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::bool('active', 'is_active'),
            AllowedFilter::bool('archived', 'archived_at', nullCheck: true),
            AllowedFilter::dateRange('createdAt', 'created_at'),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            AllowedSort::field('createdAt', 'created_at'),
            AllowedSort::field('name'),
        ];
    }

    protected function defaultSorts(): array
    {
        return ['-createdAt', 'name'];
    }

    protected function allowedIncludes(): array
    {
        return [
            AllowedInclude::tree('roles', path: 'userRoles'),
            AllowedInclude::simple('profile'),
        ];
    }
}
