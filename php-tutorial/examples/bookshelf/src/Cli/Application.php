<?php

declare(strict_types=1);

namespace Bookshelf\Cli;

use Throwable;

/**
 * Dispatches `program <command> [arguments] [options]` to a Command, and turns its result into an exit code.
 */
final class Application
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(
        private readonly string $program,
        Command ...$commands,
    ) {
        foreach ($commands as $command) {
            $this->commands[$command->name()] = $command;
        }
    }

    /**
     * @param list<string> $argv the full command line, as in the global $argv
     */
    public function run(array $argv, Output $output): int
    {
        $name = $argv[1] ?? 'help';
        if (in_array($name, ['help', '--help', '-h'], true)) {
            $this->listCommands($output);
            return ExitCode::Success->value;
        }

        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $output->error("Unknown command '$name'. Run '{$this->program} help' for a list.");
            return ExitCode::Usage->value;
        }

        try {
            $input = Input::parse(array_slice($argv, 2));
            if ($input->flag('help', 'h')) {
                $output->line($command->help());
                return ExitCode::Success->value;
            }

            return $command->run($input, $output)->value;
        } catch (UsageError $e) {
            $output->error($e->getMessage());
            $output->info("Run '{$this->program} $name --help' for usage.");
            return ExitCode::Usage->value;
        } catch (Throwable $e) {
            $output->error('Error: ' . $e->getMessage());
            if (getenv('BOOKSHELF_DEBUG') !== false) {
                $output->info((string) $e);
            }
            return ExitCode::Failure->value;
        }
    }

    private function listCommands(Output $output): void
    {
        $output->line("Usage: {$this->program} <command> [arguments] [options]");
        $output->line();
        $output->line('Commands:');
        $width = max(9, ...array_map(strlen(...), array_keys($this->commands)));
        foreach ($this->commands as $name => $command) {
            $output->line(sprintf("  %-{$width}s  %s", $name, $command->description()));
        }
        $output->line();
        $output->line("Run '{$this->program} <command> --help' for details.");
    }
}
