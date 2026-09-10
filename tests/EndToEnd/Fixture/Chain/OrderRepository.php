<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd\Fixture\Chain;

use QueryGuard\Test\EndToEnd\Fixture\Support\FakeAdapter;

/**
 * The shape every project with this problem has: one method every caller shares, so the
 * callsite is the same line whoever is at fault. What the finding has to say is who
 * called it — see `Callsite`.
 */
final class OrderRepository
{
    public function find(int $id): void
    {
        FakeAdapter::query('SELECT id, total FROM orders WHERE id = ?', [$id]);
    }
}
