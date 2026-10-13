<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Integration;

use RuntimeException;
use WilyCode\Database\Repository\Tests\Fixtures\Read\ArticleRepository;
use WilyCode\Database\Repository\Tests\Fixtures\Read\Category;
use WilyCode\Database\Repository\Tests\Fixtures\Read\Comment;
use WilyCode\Database\Repository\Tests\Support\DbTestCase;

require_once __DIR__ . '/../bootstrap.php';

final class RelationWriteTransactionTest extends DbTestCase
{
    private ArticleRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean + create schema for relation write tests.
        foreach ([
            'article_tag',
            'comment',
            'article',
            'tag',
            'category',
            'users',
        ] as $t) {
            $this->execSql('DROP TABLE IF EXISTS ' . $t);
        }

        $this->execSql('CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL
        ) ENGINE=InnoDB');

        $this->execSql('CREATE TABLE category (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            created_by_id INT NULL,
            updated_by_id INT NULL
        ) ENGINE=InnoDB');

        $this->execSql('CREATE TABLE article (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(191) NOT NULL,
            status INT NOT NULL,
            category_id INT NULL
        ) ENGINE=InnoDB');

        $this->execSql('CREATE TABLE tag (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            created_by_id INT NULL
        ) ENGINE=InnoDB');

        $this->execSql('CREATE TABLE article_tag (
            article_id INT NOT NULL,
            tag_id INT NOT NULL,
            PRIMARY KEY (article_id, tag_id)
        ) ENGINE=InnoDB');

        $this->execSql('CREATE TABLE comment (
            id INT AUTO_INCREMENT PRIMARY KEY,
            content TEXT NULL,
            article_id INT NOT NULL,
            created_by_id INT NULL
        ) ENGINE=InnoDB');

        // Seed
        $this->execSql("INSERT INTO users (name) VALUES ('Alice'),('Bob'),('Charlie')");
        $this->execSql("INSERT INTO category (name, created_by_id, updated_by_id) VALUES ('Cat A', 1, 2)");
        $this->execSql("INSERT INTO article (title, status, category_id) VALUES ('Hello', 1, 1)");
        $this->execSql("INSERT INTO tag (name, created_by_id) VALUES ('php', 2),('db', 3),('js', 1)");
        $this->execSql("INSERT INTO article_tag (article_id, tag_id) VALUES (1,1),(1,2)");
        $this->execSql("INSERT INTO comment (content, article_id, created_by_id) VALUES ('c1',1,1),('c2',1,2)");

        $this->repo = new ArticleRepository(self::$executor);
    }

    public function testReplaceHasManyParticipatesInOuterTransactionAndRollsBack(): void
    {
        $article = $this->repo->find(1);
        $this->assertNotNull($article);

        $before = $this->fetchAll('SELECT id, content FROM comment WHERE article_id = 1 ORDER BY id');
        $this->assertSame([
            ['id' => 1, 'content' => 'c1'],
            ['id' => 2, 'content' => 'c2'],
        ], $before);

        try {
            $this->repo->transaction(function (ArticleRepository $r) use ($article): void {
                // Update c1, insert a new comment, orphan c2 should be deleted.
                $c1 = new Comment();
                $c1->setId(1);
                $c1->setContent('c1-updated');

                $cNew = new Comment();
                $cNew->setContent('c3');
                $cNew->setArticleId(1);

                $r->replaceHasMany($article, 'comments', [$c1, $cNew], deleteOrphans: true);

                throw new RuntimeException('boom');
            });

            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // Nothing should be changed after rollback.
        $after = $this->fetchAll('SELECT id, content FROM comment WHERE article_id = 1 ORDER BY id');
        $this->assertSame($before, $after);
    }

    public function testSyncManyToManyParticipatesInOuterTransactionAndRollsBack(): void
    {
        $article = $this->repo->find(1);
        $this->assertNotNull($article);

        $before = $this->fetchAll('SELECT tag_id FROM article_tag WHERE article_id = 1 ORDER BY tag_id');
        $this->assertSame([
            ['tag_id' => 1],
            ['tag_id' => 2],
        ], $before);

        try {
            $this->repo->transaction(function (ArticleRepository $r) use ($article): void {
                // Replace tags with [1,3] (attach 3, detach 2)... then rollback.
                $r->syncManyToMany($article, 'tags', [1, 3]);
                throw new RuntimeException('boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $after = $this->fetchAll('SELECT tag_id FROM article_tag WHERE article_id = 1 ORDER BY tag_id');
        $this->assertSame($before, $after);
    }

    public function testSyncHasOneParticipatesInOuterTransactionAndRollsBack(): void
    {
        $beforeArticle = $this->fetchAll('SELECT category_id FROM article WHERE id = 1');
        $this->assertSame([['category_id' => 1]], $beforeArticle);
        $this->assertSame(1, (int)$this->fetchColumn('SELECT COUNT(*) FROM category'));

        $article = $this->repo->find(1);
        $this->assertNotNull($article);

        try {
            $this->repo->transaction(function (ArticleRepository $r) use ($article): void {
                $cat = new Category();
                $cat->setName('Cat B');

                $r->syncHasOne($article, 'category', $cat);

                throw new RuntimeException('boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $afterArticle = $this->fetchAll('SELECT category_id FROM article WHERE id = 1');
        $this->assertSame([['category_id' => 1]], $afterArticle);
        $this->assertSame(1, (int)$this->fetchColumn('SELECT COUNT(*) FROM category'));
        $this->assertSame(0, (int)$this->fetchColumn("SELECT COUNT(*) FROM category WHERE name = 'Cat B'"));
    }
}
