<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\Repository\Tests\Fixtures\ArticleRepository;

final class PersistedReadContractTest extends TestCase
{
    public function testFindOneRejectsHydratedRowWithoutPrimaryKey(): void
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        $executor
            ->expects(self::once())
            ->method('fetch')
            ->willReturn([
                'title' => 'Broken row',
                'status' => 1,
                'user_id' => null,
            ]);

        $repository = new ArticleRepository($executor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no primary key');

        $repository->findOne();
    }

    public function testFindOneReturnsPersistedModelWithPrimaryKey(): void
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        $executor
            ->expects(self::once())
            ->method('fetch')
            ->willReturn([
                'id' => 7,
                'title' => 'Valid row',
                'status' => 1,
                'user_id' => null,
            ]);

        $repository = new ArticleRepository($executor);
        $article = $repository->findOne();

        self::assertNotNull($article);
        self::assertSame(7, $article->getId());
        self::assertTrue($article->isPersisted());
    }
}
