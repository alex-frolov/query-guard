<?php

declare(strict_types=1);

namespace QueryGuard\Adapter;

use QueryGuard\Adapter\Doctrine\DoctrineAdapter;
use QueryGuard\Adapter\Eloquent\EloquentAdapter;
use QueryGuard\Collector\QueryCollector;

/**
 * The adapters active during a run. There can be several at once — a project using
 * both Doctrine and Eloquent is rare, but nothing here forbids it.
 */
final class AdapterSet
{
    /**
     * @param list<OrmAdapter> $adapters
     */
    public function __construct(private readonly array $adapters)
    {
    }

    /**
     * @param list<class-string<OrmAdapter>> $candidates
     */
    public static function detect(array $candidates = [DoctrineAdapter::class, EloquentAdapter::class]): self
    {
        $adapters = [];

        foreach ($candidates as $candidate) {
            if ($candidate::supports()) {
                $adapters[] = new $candidate();
            }
        }

        return new self($adapters);
    }

    public function install(QueryCollector $collector): void
    {
        foreach ($this->adapters as $adapter) {
            $adapter->install($collector);
        }
    }

    /**
     * The first adapter able to run EXPLAIN. Needed by tier 2.
     */
    public function explainer(): ?Explainer
    {
        foreach ($this->adapters as $adapter) {
            $explainer = $adapter->explainer();

            if (null !== $explainer) {
                return $explainer;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->adapters;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (OrmAdapter $adapter): string => $adapter->name(), $this->adapters);
    }

    /**
     * @return list<string> names of adapters that found their ORM but failed to hook in
     */
    public function notInstalled(): array
    {
        $names = [];

        foreach ($this->adapters as $adapter) {
            if (!$adapter->isInstalled()) {
                $names[] = $adapter->name();
            }
        }

        return $names;
    }

    /**
     * @return list<string> specific hints for the ones that failed to hook in
     */
    public function installationHints(): array
    {
        $hints = [];

        foreach ($this->adapters as $adapter) {
            if (!$adapter->isInstalled()) {
                $hints[] = $adapter->installationHint();
            }
        }

        return $hints;
    }
}
