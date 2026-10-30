<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Integration;

use WilyCode\Database\Repository\PaginationResult;
use WilyCode\Database\Repository\Exception\ModelNotFoundException;
use WilyCode\Database\Repository\Tests\Fixtures\Article;
use WilyCode\Database\Repository\Tests\Fixtures\ArticleRepository;
use WilyCode\Database\Repository\Tests\Support\DbTestCase;

require_once __DIR__ . '/../bootstrap.php';

final class SelectOpsTest extends DbTestCase
{
    private ArticleRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = new ArticleRepository(self::$executor);

        // Recreate schema
        $this->execSql('DROP TABLE IF EXISTS articles');
        $this->execSchema('CREATE TABLE articles (
              id {identity},
              title VARCHAR(255) NOT NULL,
              status INT NOT NULL DEFAULT 0,
              user_id INT NULL
            )');

        // Seed
        $this->repo->insertMany([
            ['title' => 'First',  'status' => 1, 'user_id' => 10],
            ['title' => 'Second', 'status' => 0, 'user_id' => 11],
            ['title' => 'Third',  'status' => 1, 'user_id' => null],
            ['title' => 'Fourth', 'status' => 1, 'user_id' => 12],
            ['title' => 'Fifth',  'status' => 1, 'user_id' => 13],
        ]);
    }

    public function testFindAllHydratesModels(): void
    {
        $items = $this->repo->where('status', 1)->findAll();

        $this->assertNotEmpty($items);
        $this->assertContainsOnlyInstancesOf(Article::class, $items);

        $titles = array_map(static fn(Article $a) => $a->getTitle(), $items);
        sort($titles);

        $this->assertSame(['Fifth', 'First', 'Fourth', 'Third'], $titles);
    }

    public function testFindOneReturnsModelOrNull(): void
    {
        $one = $this->repo->where('title', 'Second')->findOne();
        $this->assertInstanceOf(Article::class, $one);
        $this->assertSame('Second', $one->getTitle());

        $none = $this->repo->where('title', 'DOES_NOT_EXIST')->findOne();
        $this->assertNull($none);
    }

    public function testFindFindsByPrimaryKey(): void
    {
        $id = (int)$this->fetchColumn("SELECT id FROM articles WHERE title = 'Third' LIMIT 1");
        $found = $this->repo->find($id);

        $this->assertInstanceOf(Article::class, $found);
        $this->assertSame('Third', $found->getTitle());

        $missing = $this->repo->find(999999);
        $this->assertNull($missing);
    }


    public function testFindOneOrThrowThrowsWhenMissing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->repo->where('title', 'DOES_NOT_EXIST')->findOneOrThrow();
    }

    public function testFindOrThrowThrowsWhenMissing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->repo->findOrThrow(999999);
    }

    public function testPaginateReturnsItemsAndTotal(): void
    {
        $result = $this->repo->where('status', 1)->paginate(page: 2, perPage: 2);

        $this->assertInstanceOf(PaginationResult::class, $result);
        $this->assertSame(4, $result->total); // 4 items with status=1
        $this->assertSame(2, $result->page);
        $this->assertSame(2, $result->perPage);
        $this->assertCount(2, $result->items);
        $this->assertSame(2, $result->lastPage());
        $this->assertContainsOnlyInstancesOf(Article::class, $result->items);
    }

    public function testWhereRawWorks(): void
    {
        $items = $this->repo->whereRaw('user_id IS NULL')->findAll();
        $this->assertCount(1, $items);
        $this->assertSame('Third', $items[0]->getTitle());
    }
}