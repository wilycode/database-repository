<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Fixtures\Read;

use WilyCode\Database\Model\Attribute\HasManyToMany;
use WilyCode\Database\Model\Attribute\PrimaryKey;
use WilyCode\Database\Model\Attribute\Table;
use WilyCode\Database\Model\Model;

#[Table('article')]
final class ArticleWithExplicitTagAlias extends Model
{
    #[PrimaryKey]
    private ?int $id = null;

    #[HasManyToMany(modelName: Tag::class, tableAlias: 'x')]
    private array $tags = [];

    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): void { $this->id = $id; }

    /** @return array<int, Tag> */
    public function getTags(): array { return $this->tags; }

    /** @param array<int, Tag> $tags */
    public function setTags(array $tags): void { $this->tags = $tags; }
}
