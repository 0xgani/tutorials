<?php

declare(strict_types=1);

namespace Bookshelf\Cli\Command;

use Bookshelf\Cli\Command;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Persistence\Migrator;

final readonly class MigrateCommand implements Command
{
    public function __construct(
        private Migrator $migrator,
    ) {
    }

    public function name(): string
    {
        return 'db:migrate';
    }

    public function description(): string
    {
        return 'Create or update the database tables';
    }

    public function help(): string
    {
        return "Usage: bookshelf db:migrate\n\nApplies the files in migrations/ that have not been applied yet.";
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $applied = $this->migrator->migrate();
        foreach ($applied as $file) {
            $output->info("applied $file");
        }
        $output->success($applied === [] ? 'The database is up to date' : sprintf('%d migrations applied', count($applied)));

        return ExitCode::Success;
    }
}
