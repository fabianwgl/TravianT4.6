<?php

namespace Core\Security;

final class Password
{
    public static function hash(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if ($hash === false) {
            throw new \RuntimeException('Unable to hash password.');
        }
        return $hash;
    }

    public static function verify(string $password, string $storedHash): bool
    {
        if (self::isLegacySha1($storedHash)) {
            return hash_equals(strtolower($storedHash), sha1($password));
        }
        return password_verify($password, $storedHash);
    }

    public static function needsRehash(string $storedHash): bool
    {
        if (self::isLegacySha1($storedHash)) {
            return true;
        }
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_needs_rehash($storedHash, $algorithm);
    }

    public static function isLegacySha1(string $storedHash): bool
    {
        return preg_match('/^[a-f0-9]{40}$/i', $storedHash) === 1;
    }
}
