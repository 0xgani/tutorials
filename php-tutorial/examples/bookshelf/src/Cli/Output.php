<?php

declare(strict_types=1);

namespace Bookshelf\Cli;

/**
 * Writes to standard output (results) and standard error (diagnostics).
 *
 * Results go to STDOUT so they can be piped or redirected; messages for the human go to STDERR.
 * Colors are used only when STDERR is a terminal and the NO_COLOR environment variable is not set.
 */
final class Output
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private bool $colors;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null, ?bool $colors = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->colors = $colors ?? (stream_isatty($this->stderr) && getenv('NO_COLOR') === false);
    }

    /** A line of the command's result, on STDOUT. */
    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    /** A diagnostic message, on STDERR. */
    public function info(string $text): void
    {
        fwrite($this->stderr, $text . PHP_EOL);
    }

    public function warning(string $text): void
    {
        $this->info($this->paint($text, '33'));
    }

    public function error(string $text): void
    {
        $this->info($this->paint($text, '31'));
    }

    public function success(string $text): void
    {
        $this->info($this->paint($text, '32'));
    }

    public function json(mixed $data): void
    {
        $this->line(json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map(mb_strlen(...), $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], mb_strlen($cell));
            }
        }

        $format = fn (array $cells): string => rtrim(implode('  ', array_map(
            fn (string $cell, int $width): string => mb_str_pad($cell, $width),
            $cells,
            $widths,
        )));

        $this->line($format($headers));
        $this->line(implode('  ', array_map(fn (int $width): string => str_repeat('-', $width), $widths)));
        foreach ($rows as $row) {
            $this->line($format($row));
        }
    }

    private function paint(string $text, string $color): string
    {
        return $this->colors ? "\e[{$color}m$text\e[0m" : $text;
    }
}
