<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Fixtures\Read;

use WilyCode\Database\Model\Attribute\PrimaryKey;
use WilyCode\Database\Model\Attribute\Property;
use WilyCode\Database\Model\Attribute\Table;
use WilyCode\Database\Model\Model;

#[Table('users')]
final class User extends Model
{
    #[PrimaryKey]
    private ?int $id = null;

    #[Property]
    private ?string $name = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }
}
