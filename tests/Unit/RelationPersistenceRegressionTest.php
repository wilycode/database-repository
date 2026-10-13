<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\Model\Attribute\{Table, PrimaryKey, HasOne};
use WilyCode\Database\Model\Model;
use WilyCode\Database\Query\Statement\QueryInterface;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\Repository\Repository;
use WilyCode\Database\Repository\Tests\Fixtures\Read\{Article, Category, Comment, Tag};

final class RelationPersistenceRegressionTest extends TestCase
{
    public function testHasManyInfersForeignKeyAndBindsOwner(): void
    {
        $executor = $this->executor();
        $executor->expects(self::once())->method('fetchAll')->willReturnCallback(function (QueryInterface $query): array {
            $compiled = $query->compile();
            self::assertStringContainsString('c.article_id', $compiled->sql);
            self::assertSame([7], array_values($compiled->params));
            return [['cid' => 9]];
        });
        $executor->expects(self::once())->method('execute')->willReturnCallback(function (QueryInterface $query): int {
            self::assertStringContainsString('article_id', $query->compile()->sql);
            self::assertSame([null, 7, '9'], array_values($query->compile()->params));
            return 1;
        });
        $result = new Repository($executor, Article::class)->replaceHasMany(new Article(['id' => 7]), 'comments', [], false);
        self::assertSame(1, $result['detached']);
    }

    public function testHasOneInfersForeignKey(): void
    {
        $executor = $this->executor();
        $executor->expects(self::once())->method('fetchAll')->willReturnCallback(function (QueryInterface $query): array {
            self::assertStringContainsString('c.article_id', $query->compile()->sql);
            self::assertSame([7], array_values($query->compile()->params));
            return [];
        });
        self::assertSame(
            ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'detached' => 0],
            new Repository($executor, SingleCommentOwner::class)->replaceHasOne(new SingleCommentOwner(['id' => 7]), 'comment', null),
        );
    }

    public function testSyncHasOneAssignsTheSameObjectToAnUnsavedOwner(): void
    {
        $executor = $this->executor();
        $executor->expects(self::never())->method('execute');
        $owner = new Article();
        $category = new Category(['id' => 4]);
        $category->markHydrated();
        new Repository($executor, Article::class)->syncHasOne($owner, 'category', $category);
        self::assertSame($category, $owner->getCategory());
        self::assertSame(4, $owner->getInsertDbValues()['category_id']);
    }

    public function testSyncHasOneAssignsRelatedAfterUpdatingPersistedOwner(): void
    {
        $executor = $this->executor();
        $executor->expects(self::once())->method('execute')->willReturnCallback(function (QueryInterface $query): int {
            self::assertSame([4, 7], array_values($query->compile()->params));
            return 1;
        });
        $owner = new Article(['id' => 7]);
        $category = new Category(['id' => 4]);
        $category->markHydrated();
        new Repository($executor, Article::class)->syncHasOne($owner, 'category', $category);
        self::assertSame($category, $owner->getCategory());
    }

    public function testManyToManyAcceptsMixedIdentifiersAndModels(): void
    {
        $executor = $this->executor();
        $executor->method('fetchAll')->willReturn([['rid' => 1]]);
        $executor->expects(self::once())->method('execute')->willReturnCallback(function (QueryInterface $query): int {
            self::assertSame([7, '2'], array_values($query->compile()->params));
            return 1;
        });
        $result = new Repository($executor, Article::class)->syncManyToMany(new Article(['id' => 7]), 'tags', [1, new Tag(['id' => 2])]);
        self::assertSame(['attached' => 1, 'detached' => 0], $result);
    }

    public function testNestedConditionsBindMixedModelsScalarsAndNull(): void
    {
        $executor = $this->executor();
        $executor->expects(self::once())->method('fetchAll')->willReturnCallback(function (QueryInterface $query): array {
            $compiled = $query->compile();
            self::assertContains(4, $compiled->params);
            self::assertContains(5, $compiled->params);
            self::assertStringContainsString('IS NULL', $compiled->sql);
            return [['id' => 7, 'category' => null]];
        });
        $models = new Repository($executor, Article::class)
            ->with('category.createdBy', '*', [
                'id' => [new \WilyCode\Database\Repository\Tests\Fixtures\Read\User(['id' => 4]), 5],
                'name' => null,
            ])
            ->findAll();
        self::assertCount(1, $models);
        self::assertSame(7, $models[0]->getId());
        self::assertTrue($models[0]->isPersisted());
    }

    public function testManyToManyRejectsModelWithoutIdentifierBeforeWriting(): void
    {
        $executor = $this->executor();
        $executor->expects(self::never())->method('execute');
        $this->expectException(\InvalidArgumentException::class);
        new Repository($executor, Article::class)->syncManyToMany(new Article(['id' => 7]), 'tags', [new Tag()]);
    }

    public function testReplaceHasManyUpdatesMappedForeignKeyAndDirtySnapshot(): void
    {
        $executor = $this->executor();
        $executor->method('fetchAll')->willReturn([]);
        $executor->expects(self::once())->method('execute')->willReturn(1);
        $owner = new Article(['id' => 7]);
        $child = new Comment(['id' => 9, 'articleId' => 2, 'content' => 'old']);
        $child->markHydrated();

        new Repository($executor, Article::class)->replaceHasMany($owner, 'comments', [$child]);

        self::assertSame(7, $child->getArticleId());
        self::assertSame([], $child->getDirtyDbValues());
        $child->setContent('new');
        self::assertSame(['content' => 'new'], $child->getUpdateDbValues());
    }

    public function testReplaceHasOneUpdatesMappedForeignKey(): void
    {
        $executor = $this->executor();
        $executor->method('fetchAll')->willReturn([]);
        $executor->expects(self::once())->method('execute')->willReturn(1);
        $child = new Comment(['id' => 9, 'articleId' => 2]);
        $child->markHydrated();
        new Repository($executor, SingleCommentOwner::class)
            ->replaceHasOne(new SingleCommentOwner(['id' => 7]), 'comment', $child);
        self::assertSame(7, $child->getArticleId());
        self::assertSame([], $child->getDirtyDbValues());
    }

    public function testReplaceHasManyAssignsOwnerObjectToMappedBackRelation(): void
    {
        $executor = $this->executor();
        $executor->method('fetchAll')->willReturn([]);
        $executor->expects(self::once())->method('execute')->willReturn(1);
        $owner = new BacklinkedOwner(['id' => 7]);
        $child = new BacklinkedChild(['id' => 9]);
        $child->markHydrated();
        new Repository($executor, BacklinkedOwner::class)->replaceHasMany($owner, 'children', [$child]);
        self::assertSame($owner, $child->getOwner());
        self::assertTrue($child->isRelationLoaded('owner'));
        self::assertSame([], $child->getDirtyDbValues());
    }

    public function testSyncHasOneUpdatesRelationState(): void
    {
        $executor = $this->executor();
        $owner = new Article(['id' => 7, 'category' => 2]);
        self::assertTrue($owner->isRelationReference('category'));
        $category = new Category(['id' => 4]);
        $category->markHydrated();
        $repo = new Repository($executor, Article::class);
        $repo->syncHasOne($owner, 'category', $category);
        self::assertTrue($owner->isRelationLoaded('category'));
        $repo->syncHasOne($owner, 'category', null);
        self::assertTrue($owner->isRelationLoaded('category'));
        self::assertNull($owner->getCategory());
    }

    public function testFailedDetachDoesNotClearOwnerRelation(): void
    {
        $executor = $this->executor();
        $executor->method('execute')->willThrowException(new \RuntimeException('write failed'));
        $owner = new Article(['id' => 7, 'category' => 2]);
        $original = $owner->getCategory();
        try {
            new Repository($executor, Article::class)->syncHasOne($owner, 'category', null);
            self::fail('Expected write failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('write failed', $exception->getMessage());
        }
        self::assertSame($original, $owner->getCategory());
        self::assertTrue($owner->isRelationReference('category'));
    }

    public function testNewChildReceivesIdentifierAndMappedOwner(): void
    {
        $executor = $this->executor();
        $executor->method('fetchAll')->willReturn([]);
        $executor->method('getLastInsertId')->willReturn('12');
        $executor->expects(self::once())->method('execute')->willReturnCallback(function (QueryInterface $query): int {
            self::assertContains(7, $query->compile()->params);
            return 1;
        });
        $child = new Comment(['content' => 'new']);
        new Repository($executor, Article::class)->replaceHasMany(new Article(['id' => 7]), 'comments', [$child]);
        self::assertSame(12, $child->getId());
        self::assertSame(7, $child->getArticleId());
        self::assertTrue($child->isPersisted());
        self::assertSame([], $child->getDirtyDbValues());
    }

    private function executor(): QueryExecutorInterface
    {
        $executor = $this->createMock(QueryExecutorInterface::class);
        $executor->method('transaction')->willReturnCallback(static fn (callable $callback): mixed => $callback($executor));
        return $executor;
    }
}

#[Table('articles')]
final class SingleCommentOwner extends Model
{
    #[PrimaryKey]
    private ?int $id = null;
    #[HasOne]
    private ?Comment $comment = null;
    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): void { $this->id = $id; }
    public function getComment(): ?Comment { return $this->comment; }
    public function setComment(?Comment $comment): void { $this->comment = $comment; }
}

#[Table('owners')]
final class BacklinkedOwner extends Model
{
    #[PrimaryKey]
    private ?int $id = null;
    #[\WilyCode\Database\Model\Attribute\HasMany(modelName: BacklinkedChild::class, foreignKey: 'owner_id')]
    private array $children = [];
    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): void { $this->id = $id; }
    public function getChildren(): array { return $this->children; }
    public function setChildren(array $children): void { $this->children = $children; }
}

#[Table('children')]
final class BacklinkedChild extends Model
{
    #[PrimaryKey]
    private ?int $id = null;
    #[\WilyCode\Database\Model\Attribute\ForeignKey('owner_id')]
    #[HasOne]
    private ?BacklinkedOwner $owner = null;
    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): void { $this->id = $id; }
    public function getOwner(): ?BacklinkedOwner { return $this->owner; }
    public function setOwner(?BacklinkedOwner $owner): void { $this->owner = $owner; }
}
