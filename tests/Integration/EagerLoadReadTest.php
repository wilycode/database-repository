<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Integration;

use WilyCode\Database\Repository\Tests\Fixtures\Read\ArticleRepository;
use WilyCode\Database\Repository\Tests\Support\DbTestCase;

require_once __DIR__ . '/../bootstrap.php';

final class EagerLoadReadTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clean + create schema for eager-loading tests.
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
        $this->execSql("INSERT INTO tag (name, created_by_id) VALUES ('php', 2),('db', 3)");
        $this->execSql("INSERT INTO article_tag (article_id, tag_id) VALUES (1,1),(1,2)");
        $this->execSql("INSERT INTO comment (content, article_id, created_by_id) VALUES ('c1',1,1),('c2',1,2)");
    }

    public function testPlainReadHydratesForeignKeyAsReferenceWithoutEagerLoading(): void
    {
        $repo = new ArticleRepository(self::$executor);

        $article = $repo->find(1);

        $this->assertNotNull($article);
        $this->assertNotNull($article->getCategory());
        $this->assertSame(1, $article->getCategory()->getId());
        $this->assertTrue($article->isRelationReference('category'));
        $this->assertFalse($article->isRelationLoaded('category'));
    }

    public function testEagerLoadNestedWithColumns(): void
    {
        $repo = new ArticleRepository(self::$executor);

        $article = $repo
            ->with('category', ['name'])
            ->with('category.createdBy', ['name'])
            ->with('tags', ['name'])
            ->with('tags.createdBy', ['name'])
            ->with('comments', ['content'])
            ->with('comments.createdBy', ['name'])
            ->find(1);

        $this->assertNotNull($article);
        $this->assertSame('Hello', $article->getTitle());

        // category (HasOne belongsTo)
        $category = $article->getCategory();
        $this->assertNotNull($category);
        $this->assertSame('Cat A', $category->getName());
        // id should be auto-included even though we requested only 'name'
        $this->assertSame(1, $category->getId());

        $createdBy = $category->getCreatedBy();
        $this->assertNotNull($createdBy);
        $this->assertSame('Alice', $createdBy->getName());
        $this->assertSame(1, $createdBy->getId());

        // tags (HasManyToMany)
        $tags = $article->getTags();
        $this->assertCount(2, $tags);
        $names = array_map(static fn($t) => $t->getName(), $tags);
        sort($names);
        $this->assertSame(['db', 'php'], $names);

        foreach ($tags as $tag) {
            $this->assertNotNull($tag->getId(), 'Tag id should be auto-included.');
            $u = $tag->getCreatedBy();
            $this->assertNotNull($u);
            $this->assertNotNull($u->getId());
            $this->assertContains($u->getName(), ['Bob', 'Charlie']);
        }

        // comments (HasMany)
        $comments = $article->getComments();
        $this->assertCount(2, $comments);
        $contents = array_map(static fn($c) => $c->getContent(), $comments);
        sort($contents);
        $this->assertSame(['c1', 'c2'], $contents);

        foreach ($comments as $c) {
            // id should be auto-included even though we requested only 'content'
            $this->assertNotNull($c->getId());
            // FK article_id should also be selected (we map it via articleId property)
            $this->assertSame(1, $c->getArticleId());

            $u = $c->getCreatedBy();
            $this->assertNotNull($u);
            $this->assertNotNull($u->getId());
            $this->assertContains($u->getName(), ['Alice', 'Bob']);
        }
    }

    public function testEagerLoadConditionsFilterRelationsWithoutFilteringOwner(): void
    {
        $repo = new ArticleRepository(self::$executor);

        $article = $repo
            ->with('category', ['name'], ['name' => 'Missing'])
            ->with('tags', ['name'], ['name' => ['php']])
            ->with('comments', ['content'], ['content' => 'c1'])
            ->find(1);

        $this->assertNotNull($article);
        $this->assertSame('Hello', $article->getTitle());
        $this->assertNull($article->getCategory());

        $tags = $article->getTags();
        $this->assertCount(1, $tags);
        $this->assertSame('php', $tags[0]->getName());

        $comments = $article->getComments();
        $this->assertCount(1, $comments);
        $this->assertSame('c1', $comments[0]->getContent());
    }

    public function testEagerLoadConditionsApplyToLastNestedPathSegment(): void
    {
        $repo = new ArticleRepository(self::$executor);

        $article = $repo
            ->with('comments', ['content'])
            ->with('comments.createdBy', ['name'], ['name' => 'Alice'])
            ->find(1);

        $this->assertNotNull($article);
        $comments = $article->getComments();
        $this->assertCount(2, $comments);

        $commentsByContent = [];
        foreach ($comments as $comment) {
            $commentsByContent[$comment->getContent()] = $comment;
        }

        $this->assertSame('Alice', $commentsByContent['c1']->getCreatedBy()?->getName());
        $this->assertNull($commentsByContent['c2']->getCreatedBy());
    }
}
