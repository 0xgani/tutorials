<?php

declare(strict_types=1);

namespace Bookshelf\Security;

use InvalidArgumentException;

/**
 * The password policy, and hashing with PHP's password API.
 */
final class Passwords
{
    public const int MIN_LENGTH = 15;

    /** Bcrypt, PHP's default algorithm, ignores everything after the first 72 bytes. */
    public const int MAX_BYTES = 72;

    /** Why $password is not acceptable as a new password, or null if it is. */
    public static function problemWith(#[\SensitiveParameter] string $password): ?string
    {
        return match (true) {
            !mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")
                => 'The password contains invalid characters',
            mb_strlen($password) < self::MIN_LENGTH
                => sprintf('Use at least %d characters', self::MIN_LENGTH),
            strlen($password) > self::MAX_BYTES
                => sprintf('Use at most %d bytes (fewer characters if you use accents or emoji)', self::MAX_BYTES),
            default => null,
        };
    }

    /** @throws InvalidArgumentException if the password breaks the policy */
    public static function hash(#[\SensitiveParameter] string $password): string
    {
        $problem = self::problemWith($password);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }
}
