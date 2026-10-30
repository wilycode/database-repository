<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Integration;

use WilyCode\Database\Repository\Tests\Fixtures\Article;
use WilyCode\Database\Repository\Tests\Fixtures\ArticleRepository;
use WilyCode\Database\Repository\Tests\Support\DbTestCase;

require_once __DIR__ . '/../bootstrap.php';

final class ModelOpsTest extends DbTestCase
{
    private ArticleRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        // Schema reset per test (safe & deterministic)
        $this->execSql('DROP TABLE IF EXISTS articles');
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

    public function testCreateModelInsertsAndHydratesPrimaryKey(): void
    {
        $m = new Article([
            'title' => 'Hello',
            'status' => 1,
            'user_id' => 10,
        ]);

        $this->assertTrue($m->isNew());
        $this->assertFalse($m->isPersisted());
        $this->assertNull($m->getPrimaryKeyValue());

        $saved = $this->repo->createModel($m);

        $this->assertSame($m, $saved);
        $this->assertTrue($saved->isPersisted());
        $this->assertNotNull($saved->getPrimaryKeyValue());

        $id = (int)$saved->getPrimaryKeyValue();
        $row = $this->fetchAll('SELECT * FROM articles WHERE id = ' . $id);
        $this->assertCount(1, $row);
        $this->assertSame('Hello', $row[0]['title']);
        $this->assertSame(1, (int)$row[0]['status']);
        $this->assertSame(10, (int)$row[0]['user_id']);
    }

    public function testUpdateModelUpdatesOnlyDirtyFields(): void
    {
        $m = new Article([
            'title' => 'Initial',
            'status' => 0,
            'user_id' => 1,
        ]);
        $this->repo->createModel($m);

        $id = (int)$m->getPrimaryKeyValue();
        $this->assertGreaterThan(0, $id);

        // change one field
        $m->setStatus(2);
        $this->repo->updateModel($m);

        $row = $this->fetchAll('SELECT * FROM articles WHERE id = ' . $id);
        $this->assertCount(1, $row);
        $this->assertSame('Initial', $row[0]['title']);
        $this->assertSame(2, (int)$row[0]['status']);
        $this->assertSame(1, (int)$row[0]['user_id']);

        // now no changes -> no-op but should stay persisted
        $this->repo->updateModel($m);
        $this->assertTrue($m->isPersisted());
    }

    public function testSaveModelInsertsThenUpdates(): void
    {
        $m = new Article([
            'title' => 'A',
            'status' => 0,
            'user_id' => null,
        ]);

        $this->repo->saveModel($m);
        $this->assertTrue($m->isPersisted());
        $id = (int)$m->getPrimaryKeyValue();
        $this->assertGreaterThan(0, $id);

        $m->setTitle('B');
        $this->repo->saveModel($m);

        $row = $this->fetchAll('SELECT * FROM articles WHERE id = ' . $id);
        $this->assertCount(1, $row);
        $this->assertSame('B', $row[0]['title']);
    }

    public function testDeleteModelDeletesByPrimaryKey(): void
    {
        $m = new Article([
            'title' => 'To delete',
            'status' => 1,
            'user_id' => null,
        ]);
        $this->repo->createModel($m);

        $id = (int)$m->getPrimaryKeyValue();
        $this->assertGreaterThan(0, $id);

        $deleted = $this->repo->deleteModel($m);
        $this->assertSame(1, (int)$deleted);
        $this->assertFalse($m->isPersisted());

        $count = (int)$this->fetchColumn('SELECT COUNT(*) FROM articles WHERE id = ' . $id);
        $this->assertSame(0, $count);
    }
}
