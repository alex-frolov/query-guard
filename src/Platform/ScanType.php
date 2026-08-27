<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * How a plan node fetches rows. Normalised: MySQL and PostgreSQL do not share a single
 * word of vocabulary here, and the rules need one language.
 */
enum ScanType: string
{
    /** A full table scan: MySQL `ALL`, PostgreSQL `Seq Scan`. */
    case FullTable = 'full-table';

    /** A full index scan: MySQL `index`, PostgreSQL `Index Scan` with no condition. */
    case FullIndex = 'full-index';

    /** An index range: MySQL `range`, PostgreSQL `Bitmap Heap Scan`. */
    case Range = 'range';

    /** An index lookup: MySQL `ref`/`eq_ref`, PostgreSQL `Index Scan`/`Index Only Scan`. */
    case Lookup = 'lookup';

    /** A single row by primary key: MySQL `const`, PostgreSQL `Tid Scan`. */
    case Constant = 'constant';

    case Unknown = 'unknown';

    public function readsWholeRelation(): bool
    {
        return self::FullTable === $this || self::FullIndex === $this;
    }
}
