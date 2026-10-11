<?php

declare(strict_types=1);

namespace Bookshelf\Security;

use Bookshelf\Domain\BookshelfException;

final class AuthenticationFailed extends BookshelfException
{
    private function __construct(
        string $message,
        public readonly bool $throttled,
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        // The same message whether the e-mail address or the password was wrong: don't reveal which accounts exist.
        return new self('The e-mail address or the password is wrong.', false);
    }

    public static function tooManyAttempts(): self
    {
        return new self('Too many failed attempts. Please wait a few minutes and try again.', true);
    }
}
