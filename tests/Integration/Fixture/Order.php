<?php

declare(strict_types=1);

namespace QueryGuard\Test\Integration\Fixture;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'orders')]
    public ?Customer $customer = null;

    public function __construct(#[ORM\Column(type: 'string')] public string $number = '')
    {
    }
}
