<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Integration;

use Bookshelf\Cli\Command\OverdueCommand;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Domain\MemberType;
use Bookshelf\Lending\LendingService;
use Bookshelf\Persistence\PdoBookRepository;
use Bookshelf\Persistence\PdoLoanRepository;
use Bookshelf\Persistence\PdoMemberRepository;
use Bookshelf\Support\FrozenClock;
use Bookshelf\Tests\Support\Builders;
use Bookshelf\Tests\Support\DatabaseTestCase;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OverdueCommand::class)]
final class OverdueCommandTest extends DatabaseTestCase
{
    use Builders;

    private FrozenClock $clock;
    private OverdueCommand $command;
    private LendingService $lending;
    private PdoBookRepository $books;
    private PdoMemberRepository $members;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->database->pdo;
        $this->books = new PdoBookRepository($pdo);
        $this->members = new PdoMemberRepository($pdo);
        $loans = new PdoLoanRepository($pdo, new DateTimeZone('Europe/Berlin'));
        $this->clock = FrozenClock::at('2026-10-11 10:00', 'Europe/Berlin');
        $this->lending = new LendingService($this->database, $this->books, $this->members, $loans, $this->clock);
        $this->command = new OverdueCommand($loans, $this->books, $this->members, $this->clock);
    }

    public function testReportsNothingWhenNoLoanIsLate(): void
    {
        [$stdout, $stderr] = $this->execute([]);

        self::assertSame('', $stdout);
        self::assertSame("No overdue loans\n", $stderr);
    }

    public function testListsLateLoansWithTheirFines(): void
    {
        $book = self::book(id: null);
        $this->books->save($book);
        $member = self::member(MemberType::Standard, id: null);
        $this->members->save($member);
        $this->lending->lend((int) $book->id, (int) $member->id);     // due 2026-11-01

        $this->clock->advance('+24 days');                           // 2026-11-04: three days late
        [$stdout] = $this->execute(['--json']);

        self::assertSame(
            [['loan' => 1, 'due' => '2026-11-01', 'days' => 3, 'fine' => '$0.75', 'title' => 'Dune', 'member' => 'ada@example.org']],
            json_decode($stdout, true),
        );
    }

    /**
     * @param list<string> $arguments
     * @return array{string, string} what the command wrote to STDOUT and STDERR
     */
    private function execute(array $arguments): array
    {
        $stdout = fopen('php://memory', 'w+') ?: self::fail('Cannot open a memory stream');
        $stderr = fopen('php://memory', 'w+') ?: self::fail('Cannot open a memory stream');

        $exitCode = $this->command->run(Input::parse($arguments), new Output($stdout, $stderr, colors: false));

        self::assertSame(ExitCode::Success, $exitCode);
        rewind($stdout);
        rewind($stderr);

        return [(string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
