<?php

declare(strict_types=1);

namespace Bookshelf\Security;

/**
 * Cross-site request forgery tokens: a random secret stored in the session and repeated in every form.
 * A request from another site cannot know it, so it cannot submit a valid form.
 */
final class Csrf
{
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Compares in constant time, so the response time doesn't leak how much of a guess was right. */
    public static function isValid(mixed $expected, mixed $submitted): bool
    {
        return is_string($expected) && $expected !== '' && is_string($submitted) && hash_equals($expected, $submitted);
    }
}
