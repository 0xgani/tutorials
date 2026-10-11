<?php

declare(strict_types=1);

namespace Bookshelf\Cli\Command;

use Bookshelf\Cli\Command;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Support\Clock;

/**
 * The daily overdue report. Meant to run on a schedule, so its output is plain enough for a log or an email,
 * and --json makes it easy for another program to consume.
 */
final readonly class OverdueCommand implements Command
{
    public function __construct(
        private LoanRepository $loans,
        private BookRepository $books,
        private MemberRepository $members,
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
        $today = $this->clock->now();
        $overdue = $this->loans->overdue($today);
        $books = $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $overdue));

        /** @var array<int, Member> $members */
        $members = [];
        $rows = [];
        foreach ($overdue as $loan) {
            $member = $members[$loan->memberId] ??= $this->members->get($loan->memberId);
            $rows[] = [
                'loan' => $loan->id,
                'due' => $loan->dueOn->format('Y-m-d'),
                'days' => $loan->daysOverdue($today),
                'fine' => $loan->fine($today)->format(),
                'title' => $books[$loan->bookId]->title ?? "(book {$loan->bookId})",
                'member' => $member->email,
            ];
        }

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
