<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\Repository\Exception\UnsafeWriteException;
use WilyCode\Database\Repository\Tests\Fixtures\ArticleRepository;

final class RepositorySafetyTest extends TestCase
{
    public function testUnfilteredUpdateIsRejectedWithoutCallingExecutor(): void
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        $executor->expects(self::never())->method('execute');

        $repository = new ArticleRepository($executor);
        $this->expectException(UnsafeWriteException::class);
        $repository->update(['status' => 1]);
    }

    public function testUnfilteredDeleteIsRejectedWithoutCallingExecutor(): void
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        $executor->expects(self::never())->method('execute');

        $repository = new ArticleRepository($executor);
        $this->expectException(UnsafeWriteException::class);
        $repository->delete();
    }

    public function testModifiersReturnClonesAndDoNotModifyBaseRepository(): void
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        $repository = new ArticleRepository($executor);

        $filtered = $repository->where('status', 1)->orderBy('id', 'DESC');

        self::assertNotSame($repository, $filtered);
        $this->expectException(UnsafeWriteException::class);
        $repository->delete();
    }

    public function testInvalidOrderDirectionIsRejected(): void
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        $repository = new ArticleRepository($executor);

        $this->expectException(InvalidArgumentException::class);
        $repository->orderBy('id', 'RANDOM');
    }

    public function testPaginateRejectsInvalidPageBeforeQueryExecution(): void
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        $executor->expects(self::never())->method('fetchColumn');
        $repository = new ArticleRepository($executor);

        $this->expectException(InvalidArgumentException::class);
        $repository->paginate(page: 0);
    }
}
