<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Support;

use Bookshelf\Web\SessionStorage;

/**
 * Session data in a plain array. One instance plays the browser's session cookie: every request in a test that
 * uses the same instance belongs to the same visitor.
 */
final class ArraySessionStorage implements SessionStorage
{
    /** @var array<string, mixed> */
    public private(set) array $data = [];

    public private(set) bool $started = false;

    /** How often the session ID was changed (at login and logout). */
    public private(set) int $regenerations = 0;

    public function exists(): bool
    {
        return $this->started;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->started = true;
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(bool $clear = false): void
    {
        $this->started = true;
        $this->regenerations++;
        if ($clear) {
            $this->data = [];
        }
    }
}
