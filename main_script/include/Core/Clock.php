<?php

namespace Core;

/**
 * Runtime clock with an explicit, process-local test freeze.
 *
 * Production callers receive the system clock. Regression fixtures can freeze
 * it without changing world configuration or relying on sleeps.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function now(): int
    {
        return self::$frozen ?? time();
    }

    public static function milliseconds(): int
    {
        return self::now() * 1000;
    }

    public static function nanoseconds(): int
    {
        return self::now() * 1000000000;
    }

    public static function freeze(int $timestamp): void
    {
        self::$frozen = $timestamp;
    }

    public static function reset(): void
    {
        self::$frozen = null;
    }

    public static function isFrozen(): bool
    {
        return self::$frozen !== null;
    }
}
