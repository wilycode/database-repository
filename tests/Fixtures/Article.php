<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Fixtures;

use WilyCode\Database\Model\Attribute\PrimaryKey;
use WilyCode\Database\Model\Attribute\Property;
use WilyCode\Database\Model\Attribute\Table;
use WilyCode\Database\Model\Model;

#[Table('articles')]
final class Article extends Model
{
    static $count = 0;
    #[PrimaryKey]
    private ?int $id = null;

    #[Property]
    private ?string $title = null;

    #[Property]
    private ?int $status = null;

    #[Property]
    private ?int $userId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(?int $status): void
    {
        $this->status = $status;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }
}
