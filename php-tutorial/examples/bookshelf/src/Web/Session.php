<?php

declare(strict_types=1);

namespace Bookshelf\Web;

use Bookshelf\Security\Csrf;

/**
 * The visitor's session, on top of PHP's native sessions. It starts the session only when something is stored,
 * or when the browser already has a session cookie, so visitors who only browse never get one.
 */
final class Session
{
    public function __construct(
        private readonly bool $secureCookie = false,
        private readonly string $name = 'PHPSESSID',
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->exists()) {
            return $default;
        }
        $this->start();

        return $_SESSION[$key] ?? $default;
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

    /** A new session ID for the same data: call it when the visitor's privileges change (login, logout). */
    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    /** Forgets everything and continues with a new, empty session. */
    public function reset(): void
    {
        $this->start();
        $_SESSION = [];
        session_regenerate_id(true);
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

    private function exists(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE || isset($_COOKIE[$this->name]);
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
