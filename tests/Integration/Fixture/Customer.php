<?php

declare(strict_types=1);

namespace QueryGuard\Test\Integration\Fixture;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customers')]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    /** @var Collection<int, Order> */
    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'customer')]
    public Collection $orders;

    public function __construct(#[ORM\Column(type: 'string')] public string $name = '')
    {
        $this->orders = new ArrayCollection();
    }
}
