<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * Picks a platform driver by platform name.
 */
final class PlatformDrivers
{
    /**
     * @param list<class-string<PlatformDriver>> $candidates
     */
    public static function for(string $platform, array $candidates = [MySqlPlatformDriver::class, PostgresPlatformDriver::class]): ?PlatformDriver
    {
        foreach ($candidates as $candidate) {
            if ($candidate::supports($platform)) {
                return new $candidate();
            }
        }

        return null;
    }
}
