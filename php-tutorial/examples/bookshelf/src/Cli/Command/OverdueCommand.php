<?php

declare(strict_types=1);

namespace Bookshelf\Cli\Command;

use Bookshelf\Cli\Command;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Lending\OverdueLoan;
use Bookshelf\Lending\OverdueReport;
use Bookshelf\Support\Clock;

/**
 * The daily overdue report. Meant to run on a schedule, so its output is plain enough for a log or an email,
 * and --json makes it easy for another program to consume.
 */
final readonly class OverdueCommand implements Command
{
    public function __construct(
        private OverdueReport $report,
        private Clock $clock,
    ) {
    }

    public function name(): string
    {
        return 'loans:overdue';
    }

    public function description(): string
    {
        return 'List the loans that are past their due date';
    }

    public function help(): string
    {
        return <<<'TEXT'
            Usage: bookshelf loans:overdue [--json]

            Lists every loan that is past its due date, oldest first, with the fine owed so far.
            Meant to run on a schedule (cron, a systemd timer, a Kubernetes CronJob).

            Options:
              --json  Print the loans as JSON
            TEXT;
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $rows = array_map(fn (OverdueLoan $late): array => [
            'loan' => $late->loanId,
            'due' => $late->dueOn->format('Y-m-d'),
            'days' => $late->daysOverdue,
            'fine' => $late->fine->format(),
            'title' => $late->title,
            'member' => $late->memberEmail,
        ], $this->report->on($this->clock->now()));

        if ($input->flag('json')) {
            $output->json($rows);
        } elseif ($rows === []) {
            $output->success('No overdue loans');
        } else {
            $output->table(
                ['Loan', 'Due', 'Days', 'Fine', 'Title', 'Member'],
                array_map(fn (array $row): array => array_map(strval(...), array_values($row)), $rows),
            );
        }

        return ExitCode::Success;
    }
}
