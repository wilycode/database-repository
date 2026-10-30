<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Integration;

use WilyCode\Database\Repository\Tests\Fixtures\ArticleRepository;
use WilyCode\Database\Repository\Tests\Support\DbTestCase;
use WilyCode\Database\Repository\Exception\UnsafeWriteException;

require_once __DIR__ . '/../bootstrap.php';

final class TableOpsTest extends DbTestCase
{
    private ArticleRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        // Schema reset per test (safe & deterministic)
        $this->execSql('DROP TABLE IF EXISTS articles');
        // NOTE: Do NOT use "\\n" inside single-quoted strings – PHP will not interpret it.
        // MariaDB then receives literal backslash-n and throws SQL 1064.
        $this->execSchema(<<<SQL
CREATE TABLE articles (
  id {identity},
  title VARCHAR(255) NOT NULL,
  status INT NOT NULL DEFAULT 0,
  user_id INT NULL
)
SQL);

        $this->repo = new ArticleRepository(self::$executor);
    }

    public function testInsertReturnsLastInsertIdAndInsertsRow(): void
    {
        $id = $this->repo->insert([
            'title' => 'First',
            'status' => 1,
            'user_id' => 10,
        ]);

        $this->assertGreaterThan(0, (int)$id);

        $rows = $this->fetchAll('SELECT * FROM articles WHERE id = ' . (int)$id);
        $this->assertCount(1, $rows);
        $this->assertSame('First', $rows[0]['title']);
        $this->assertSame(1, (int)$rows[0]['status']);
        $this->assertSame(10, (int)$rows[0]['user_id']);
    }

    public function testInsertManyInsertsAllRows(): void
    {
        $inserted = $this->repo->insertMany([
            ['title' => 'A', 'status' => 0, 'user_id' => null],
            ['title' => 'B', 'status' => 1, 'user_id' => 5],
            ['title' => 'C', 'status' => 1, 'user_id' => 6],
        ]);
        $this->assertSame(3, $inserted);

        $count = (int)$this->fetchColumn('SELECT COUNT(*) FROM articles');
        $this->assertSame(3, $count);
    }

    public function testUpdateWithoutWhereThrows(): void
    {
        $this->repo->insert(['title' => 'X', 'status' => 0, 'user_id' => null]);

        $this->expectException(UnsafeWriteException::class);
        $this->repo->update(['status' => 1]);
    }

    public function testUpdateWithWhereReturnsAffectedRows(): void
    {
        $this->repo->insertMany([
            ['title' => 'A', 'status' => 0, 'user_id' => null],
            ['title' => 'B', 'status' => 0, 'user_id' => null],
            ['title' => 'C', 'status' => 1, 'user_id' => null],
        ]);

        $affected = $this->repo
            ->where('status', 0)
            ->update(['status' => 1]);

        $this->assertSame(2, $affected);

        $count0 = (int)$this->fetchColumn('SELECT COUNT(*) FROM articles WHERE status = 0');
        $count1 = (int)$this->fetchColumn('SELECT COUNT(*) FROM articles WHERE status = 1');

        $this->assertSame(0, $count0);
        $this->assertSame(3, $count1);
    }

    public function testDeleteWithoutWhereThrows(): void
    {
        $this->repo->insert(['title' => 'X', 'status' => 1, 'user_id' => null]);

        $this->expectException(UnsafeWriteException::class);
        $this->repo->delete();
    }

    public function testDeleteWithWhereReturnsAffectedRows(): void
    {
        $this->repo->insertMany([
            ['title' => 'A', 'status' => 0, 'user_id' => null],
            ['title' => 'B', 'status' => 1, 'user_id' => null],
            ['title' => 'C', 'status' => 1, 'user_id' => null],
            ['title' => 'D', 'status' => 2, 'user_id' => null],
        ]);

        $deleted = $this->repo
            ->where('status', 1)
            ->delete();

        $this->assertSame(2, $deleted);

        $count = (int)$this->fetchColumn('SELECT COUNT(*) FROM articles');
        $this->assertSame(2, $count);

        $countStatus1 = (int)$this->fetchColumn('SELECT COUNT(*) FROM articles WHERE status = 1');
        $this->assertSame(0, $countStatus1);
    }
}
