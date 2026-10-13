<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Fixtures\Read;

use WilyCode\Database\Model\Attribute\ForeignKey;
use WilyCode\Database\Model\Attribute\HasOne;
use WilyCode\Database\Model\Attribute\PrimaryKey;
use WilyCode\Database\Model\Attribute\Property;
use WilyCode\Database\Model\Attribute\Table;
use WilyCode\Database\Model\Model;

#[Table('comment')]
final class Comment extends Model
{
    #[PrimaryKey]
    private ?int $id = null;

    #[Property]
    private ?string $content = null;

    #[Property]
    private ?int $articleId = null;

    #[ForeignKey]
    #[HasOne]
    private ?User $createdBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): void
    {
        $this->content = $content;
    }

    public function getArticleId(): ?int
    {
        return $this->articleId;
    }

    public function setArticleId(?int $articleId): void
    {
        $this->articleId = $articleId;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): void
    {
        $this->createdBy = $createdBy;
    }
}
