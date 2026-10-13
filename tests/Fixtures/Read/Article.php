<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Fixtures\Read;

use WilyCode\Database\Model\Attribute\ForeignKey;
use WilyCode\Database\Model\Attribute\HasMany;
use WilyCode\Database\Model\Attribute\HasManyToMany;
use WilyCode\Database\Model\Attribute\HasOne;
use WilyCode\Database\Model\Attribute\PrimaryKey;
use WilyCode\Database\Model\Attribute\Property;
use WilyCode\Database\Model\Attribute\Table;
use WilyCode\Database\Model\Model;

#[Table('article')]
final class Article extends Model
{
    #[PrimaryKey]
    private ?int $id = null;

    #[Property]
    private ?string $title = null;

    #[Property]
    private ?int $status = null;

    #[ForeignKey]
    #[HasOne]
    private ?Category $category = null;

    #[HasMany(modelName: Comment::class)]
    private array $comments = [];

    #[HasManyToMany(modelName: Tag::class)]
    private array $tags = [];

    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): void { $this->id = $id; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): void { $this->title = $title; }

    public function getStatus(): ?int { return $this->status; }
    public function setStatus(?int $status): void { $this->status = $status; }

    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): void { $this->category = $category; }

    /** @return array<int, Comment> */
    public function getComments(): array { return $this->comments; }

    /** @param array<int, Comment> $comments */
    public function setComments(array $comments): void { $this->comments = $comments; }

    /** @return array<int, Tag> */
    public function getTags(): array { return $this->tags; }

    /** @param array<int, Tag> $tags */
    public function setTags(array $tags): void { $this->tags = $tags; }
}
