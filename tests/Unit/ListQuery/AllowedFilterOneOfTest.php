<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Unit\ListQuery;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\Repository\Repository;
use WilyCode\Database\Repository\ListQuery\AllowedFilter;
use WilyCode\Database\Repository\Tests\Fixtures\Read\User;

final class AllowedFilterOneOfTest extends TestCase
{
    private function repo(): Repository
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        return new Repository($executor, User::class);
    }

    public function test_oneOf_applies_where_for_single_allowed_value(): void
    {
        $f = AllowedFilter::oneOf('role', ['worker', 'admin', 'viewer', 'customer']);

        $repo = $f->apply($this->repo(), 'admin');

        $wheres = $this->getProtected($repo, 'wheres');
        $rawWheres = $this->getProtected($repo, 'rawWheres');

        self::assertCount(1, $wheres);
        self::assertSame('role', $wheres[0]['column']);
        self::assertSame('admin', $wheres[0]['value']);
        self::assertCount(0, $rawWheres);
    }

    public function test_oneOf_applies_whereIn_for_multiple_allowed_values(): void
    {
        $f = AllowedFilter::oneOf('role', ['worker', 'admin', 'viewer', 'customer']);

        $repo = $f->apply($this->repo(), ['worker', 'admin']);

        $wheres = $this->getProtected($repo, 'wheres');
        $rawWheres = $this->getProtected($repo, 'rawWheres');

        self::assertCount(0, $wheres);
        self::assertCount(1, $rawWheres);

        self::assertStringContainsString('role IN', $rawWheres[0]['condition']);
        self::assertSame(['worker', 'admin'], $rawWheres[0]['params']);
    }

    public function test_oneOf_trims_deduplicates_and_ignores_empty_values(): void
    {
        $f = AllowedFilter::oneOf('role', ['worker', 'admin', 'viewer', 'customer']);

        $repo = $f->apply($this->repo(), [' admin ', '', 'admin']);

        $wheres = $this->getProtected($repo, 'wheres');
        $rawWheres = $this->getProtected($repo, 'rawWheres');

        // After normalization it becomes a single value => WHERE
        self::assertCount(1, $wheres);
        self::assertSame('role', $wheres[0]['column']);
        self::assertSame('admin', $wheres[0]['value']);
        self::assertCount(0, $rawWheres);
    }

    public function test_oneOf_throws_on_invalid_value_by_default(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $f = AllowedFilter::oneOf('role', ['worker', 'admin', 'viewer', 'customer']);
        $f->apply($this->repo(), 'hacker');
    }

    public function test_oneOf_can_ignore_invalid_values_when_configured(): void
    {
        $f = AllowedFilter::oneOf('role', ['worker', 'admin', 'viewer', 'customer'], throwOnInvalid: false);

        $repo = $f->apply($this->repo(), ['hacker', 'admin']);

        $wheres = $this->getProtected($repo, 'wheres');
        $rawWheres = $this->getProtected($repo, 'rawWheres');

        // Only admin remains after filtering invalid values.
        self::assertCount(1, $wheres);
        self::assertSame('role', $wheres[0]['column']);
        self::assertSame('admin', $wheres[0]['value']);
        self::assertCount(0, $rawWheres);
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
