<?php

declare(strict_types=1);

namespace Bookshelf\Web;

/**
 * Session data in PHP's native sessions ($_SESSION and the session cookie). The session starts only when
 * something is stored, or when the browser already sent a session cookie, so visitors who only browse never get one.
 */
final readonly class NativeSessionStorage implements SessionStorage
{
    public function __construct(
        private bool $secureCookie = false,
        private string $name = 'PHPSESSID',
    ) {
    }

    public function exists(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE || isset($_COOKIE[$this->name]);
    }

    public function get(string $key): mixed
    {
        if (!$this->exists()) {
            return null;
        }
        $this->start();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        if ($this->exists()) {
            $this->start();
            unset($_SESSION[$key]);
        }
    }

    public function regenerate(bool $clear = false): void
    {
        $this->start();
        if ($clear) {
            $_SESSION = [];
        }
        session_regenerate_id(true);
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'name' => $this->name,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cookie_secure' => $this->secureCookie,
                'use_strict_mode' => true,
            ]);
        }
    }
}
