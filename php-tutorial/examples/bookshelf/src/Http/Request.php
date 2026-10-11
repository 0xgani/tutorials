<?php

declare(strict_types=1);

namespace Bookshelf\Http;

use Uri\Rfc3986\Uri;

/**
 * An HTTP request: an immutable value built once from PHP's superglobals.
 *
 * Code below the front controller reads the request from this object, never from $_GET, $_POST or $_SERVER,
 * so it can be tested with requests built by hand.
 */
final readonly class Request
{
    /**
     * @param array<string, mixed> $query       the query string ($_GET)
     * @param array<string, mixed> $body        form fields ($_POST)
     * @param array<string, string> $cookies
     * @param array<string, string> $headers    by lower-case name
     * @param array<string, mixed> $server      $_SERVER, for details such as REMOTE_ADDR
     * @param array<string, mixed> $attributes  values added while handling the request (route parameters, the member)
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        public array $cookies = [],
        public array $headers = [],
        public array $server = [],
        public array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            Uri::parse($_SERVER['REQUEST_URI'] ?? '/')?->getPath() ?? '/',
            $_GET,
            $_POST,
            $_COOKIE,
            $headers,
            $_SERVER,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** A query-string parameter as trimmed text ('' when missing or not a string). */
    public function query(string $name, int $maxLength = 200): string
    {
        return self::text($this->query[$name] ?? null, $maxLength);
    }

    /** A form field as trimmed text ('' when missing or not a string). */
    public function input(string $name, int $maxLength = 200): string
    {
        return self::text($this->body[$name] ?? null, $maxLength);
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return clone($this, ['attributes' => [...$this->attributes, $name => $value]]);   // PHP 8.5+
    }

    /** The path with its query string, e.g. for redirecting back here after a login. */
    public function target(): string
    {
        return $this->query === [] ? $this->path : $this->path . '?' . http_build_query($this->query);
    }

    public function clientAddress(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    private static function text(mixed $value, int $maxLength): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $maxLength) : '';
    }
}
