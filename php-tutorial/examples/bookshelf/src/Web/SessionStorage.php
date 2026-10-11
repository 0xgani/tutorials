<?php

declare(strict_types=1);

namespace Bookshelf\Web;

/**
 * Where the visitor's session data lives. Session builds flash messages and CSRF tokens on top of it.
 *
 * Production uses PHP's native sessions (NativeSessionStorage); tests use an in-memory implementation,
 * so they can log in and follow a visitor across several requests.
 */
interface SessionStorage
{
    /** Whether the visitor has a session already. Reading must never create one. */
    public function exists(): bool;

    /** The stored value, or null when there is none. */
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Gives the session a new ID; with $clear, also forgets all its data. */
    public function regenerate(bool $clear = false): void;
}
