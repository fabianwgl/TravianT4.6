<?php

namespace Core;

/**
 * Secure seed source with an explicit deterministic override for fixtures.
 */
final class Random
{
    private static ?int $seed = null;

    public static function seed(): int
    {
        if (self::$seed !== null) {
            return self::$seed;
        }

        return unpack('N', random_bytes(4))[1];
    }

    public static function freeze(int $seed): void
    {
        self::$seed = $seed;
    }

    public static function reset(): void
    {
        self::$seed = null;
    }
}
