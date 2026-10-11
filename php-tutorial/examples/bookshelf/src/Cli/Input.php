<?php

declare(strict_types=1);

namespace Bookshelf\Cli;

/**
 * The arguments and options of one command, parsed from the command line.
 *
 * Supported syntax: positional arguments, `--name`, `--name=value`, short flags `-v` and `-qv`,
 * `-` as an argument (conventionally "standard input"), and `--` to end option parsing.
 */
final readonly class Input
{
    /**
     * @param list<string> $arguments
     * @param array<string, string|true> $options
     */
    private function __construct(
        public array $arguments,
        public array $options,
    ) {
    }

    /**
     * @param list<string> $tokens the command line after the command name
     */
    public static function parse(array $tokens): self
    {
        $arguments = [];
        $options = [];
        $optionsEnded = false;

        foreach ($tokens as $token) {
            if ($optionsEnded || $token === '-' || !str_starts_with($token, '-')) {
                $arguments[] = $token;
            } elseif ($token === '--') {
                $optionsEnded = true;
            } elseif (str_starts_with($token, '--')) {
                $parts = explode('=', substr($token, 2), 2);
                $options[$parts[0]] = $parts[1] ?? true;
            } else {
                foreach (str_split(substr($token, 1)) as $flag) {
                    $options[$flag] = true;
                }
            }
        }

        return new self($arguments, $options);
    }

    public function argument(int $position): ?string
    {
        return $this->arguments[$position] ?? null;
    }

    public function requireArgument(int $position, string $name): string
    {
        return $this->argument($position) ?? throw new UsageError("Missing argument <$name>");
    }

    public function flag(string $name, ?string $short = null): bool
    {
        return isset($this->options[$name]) || ($short !== null && isset($this->options[$short]));
    }

    /**
     * The value of `--name=value`, or $default when the option is absent.
     *
     * @param list<string> $allowed when not empty, the only accepted values
     */
    public function option(string $name, string $default, array $allowed = []): string
    {
        $value = $this->options[$name] ?? $default;
        if ($value === true) {
            throw new UsageError("Option --$name needs a value: --$name=...");
        }
        if ($allowed !== [] && !in_array($value, $allowed, true)) {
            throw new UsageError("Option --$name must be one of: " . implode(', ', $allowed));
        }

        return $value;
    }
}
