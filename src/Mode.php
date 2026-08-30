<?php

declare(strict_types=1);

namespace QueryGuard;

/**
 * Reading a mode out of the configuration is `ExtensionConfiguration`'s job, and it
 * warns about a value it did not recognise instead of falling back in silence. There is
 * deliberately no `fromString()` here: two spellings of the same decision drift apart.
 */
enum Mode: string
{
    /** The default: nothing fails, a summary is printed at the end of the run. */
    case Report = 'report';

    /** Findings fail the run. */
    case Strict = 'strict';
}
