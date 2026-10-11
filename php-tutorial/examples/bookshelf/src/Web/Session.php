<?php

declare(strict_types=1);

namespace Bookshelf\Web;

use Bookshelf\Security\Csrf;

/**
 * The visitor's session: key-value data plus flash messages and the CSRF token. Where the data is kept is up to
 * the SessionStorage (PHP's native sessions in production, memory in tests).
 */
final readonly class Session
{
    public function __construct(
        private SessionStorage $storage,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->storage->get($key) ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->storage->set($key, $value);
    }

    public function remove(string $key): void
    {
        $this->storage->remove($key);
    }

    /** A new session ID for the same data: call it when the visitor's privileges change (login, logout). */
    public function regenerate(): void
    {
        $this->storage->regenerate();
    }

    /** Forgets everything and continues with a new, empty session. */
    public function reset(): void
    {
        $this->storage->regenerate(clear: true);
    }

    public function flash(string $message): void
    {
        $this->set('flash', $message);
    }

    public function takeFlash(): ?string
    {
        $message = $this->get('flash');
        $this->remove('flash');

        return is_string($message) ? $message : null;
    }

    public function csrfToken(): string
    {
        $token = $this->get('csrf');
        if (!is_string($token)) {
            $token = Csrf::generateToken();
            $this->set('csrf', $token);
        }

        return $token;
    }

    public function renewCsrfToken(): void
    {
        $this->set('csrf', Csrf::generateToken());
    }
}
