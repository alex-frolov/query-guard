<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\Persistence\Proxy;
use QueryGuard\Adapter\QueryEnricher;

/**
 * Recognises Doctrine's lazy loading in the stack and extracts the entity and
 * association names.
 *
 * Why it is needed: a trace-based rule only sees "one shape, one place, differing
 * values", which does not distinguish a lazy-loaded association from a batch loader
 * called several times, or from repeated HTTP requests inside one test. Lazy
 * initialisation, by contrast, is unambiguous: if the query came out of it, this is
 * N+1 by definition rather than a guess.
 *
 * Two cases:
 *
 * - **a collection** — `PersistentCollection::initialize()` in the stack. The mapping
 *   gives both the owning entity and the field name: `App\Entity\Order::$items`;
 * - **an entity** — two different worlds here, and both are current. In ORM 2 the proxy
 *   object itself sits in a frame. In ORM 3 with PHP 8.4+ native lazy objects (the only
 *   available mode once Symfony 8 is in play) the entity does not appear in the stack at
 *   all: the initialiser is a static closure of `ProxyFactory`. We recognise the factory
 *   by the frame's class name and take the entity name from the persister. The
 *   association name is out of reach either way — a proxy only knows about itself.
 *
 * Nothing found means empty annotations and the rule falls back to its heuristic.
 * One must never be silently passed off as the other.
 */
final class DoctrineEnricher implements QueryEnricher
{
    public const KIND = 'doctrine.kind';

    public const ENTITY = 'doctrine.entity';

    public const ASSOCIATION = 'doctrine.association';

    public const KIND_COLLECTION = 'collection';

    public const KIND_ENTITY = 'entity';

    /**
     * Only the top frames matter: between the query and the lazy initialisation lie the
     * internals of DBAL and the persister, not the whole application. Further down comes
     * application code, where a proxy may turn up by coincidence and have nothing to do
     * with this query.
     */
    private const DEPTH = 30;

    public function annotate(array $stack): array
    {
        $depth = 0;
        $persister = null;

        foreach ($stack as $frame) {
            if ($depth++ >= self::DEPTH) {
                break;
            }

            $object = $frame['object'] ?? null;

            if ($object instanceof PersistentCollection) {
                return self::fromCollection($object);
            }

            // ORM 2: the proxy class `Proxies\__CG__\App\Entity\X` sits in the frame as an object
            if ($object instanceof Proxy && self::isUninitialized($object)) {
                return [
                    self::KIND => self::KIND_ENTITY,
                    self::ENTITY => self::realClass($object),
                ];
            }

            if ($object instanceof EntityPersister) {
                $persister = $object;
            }

            // ORM 3 with native lazy objects: the entity never appears in the frames —
            // the initialiser is a static closure of the proxy factory. Recognise the
            // factory itself and take the class name from the persister further down.
            if (($frame['class'] ?? null) === ProxyFactory::class) {
                return array_filter([
                    self::KIND => self::KIND_ENTITY,
                    self::ENTITY => $persister?->getClassMetadata()->getName(),
                ], static fn (mixed $value): bool => null !== $value);
            }
        }

        return [];
    }

    /**
     * @param PersistentCollection<array-key, object> $collection
     *
     * @return array<string, mixed>
     */
    private static function fromCollection(PersistentCollection $collection): array
    {
        $annotations = [self::KIND => self::KIND_COLLECTION];

        try {
            $mapping = $collection->getMapping();
        } catch (\Throwable) {
            return $annotations;
        }

        // ORM 2 returns an array, ORM 3 an AssociationMapping object (which is ArrayAccess)
        $source = self::mappingValue($mapping, 'sourceEntity');
        $field = self::mappingValue($mapping, 'fieldName');

        if (null !== $source) {
            $annotations[self::ENTITY] = $source;
        }

        if (null !== $field) {
            $annotations[self::ASSOCIATION] = $field;
        }

        return $annotations;
    }

    private static function mappingValue(mixed $mapping, string $key): ?string
    {
        if (\is_array($mapping)) {
            $value = $mapping[$key] ?? null;

            return \is_string($value) ? $value : null;
        }

        if (\is_object($mapping)) {
            if (property_exists($mapping, $key)) {
                $value = $mapping->{$key};

                return \is_string($value) ? $value : null;
            }

            if ($mapping instanceof \ArrayAccess && $mapping->offsetExists($key)) {
                $value = $mapping->offsetGet($key);

                return \is_string($value) ? $value : null;
            }
        }

        return null;
    }

    private static function isUninitialized(object $proxy): bool
    {
        if (!method_exists($proxy, '__isInitialized')) {
            return false;
        }

        try {
            return false === $proxy->__isInitialized();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * `Proxies\__CG__\App\Entity\Customer` → `App\Entity\Customer`.
     */
    private static function realClass(object $proxy): string
    {
        $class = $proxy::class;
        $marker = '\\'.Proxy::MARKER.'\\';
        $position = strrpos($class, $marker);

        if (false !== $position) {
            return substr($class, $position + \strlen($marker));
        }

        $parent = get_parent_class($proxy);

        return false !== $parent ? $parent : $class;
    }
}
