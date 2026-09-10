<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd\Fixture\Chain;

use PHPUnit\Framework\TestCase;

final class ChainTest extends TestCase
{
    public function testOneQueryPerOrder(): void
    {
        $repository = new OrderRepository();

        foreach ([1, 2, 3, 4] as $id) {
            $repository->find($id);
        }

        self::assertTrue(true);
    }
}
