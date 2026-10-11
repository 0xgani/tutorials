<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Unit\Domain;

use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanRuleViolation;
use Bookshelf\Domain\LoanStatus;
use Bookshelf\Domain\MemberType;
use Bookshelf\Domain\Money;
use Bookshelf\Tests\Support\Builders;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Loan::class)]
final class LoanTest extends TestCase
{
    use Builders;

    public function testDueDateDependsOnTheMemberType(): void
    {
        $loan = $this->loan(MemberType::Standard, lentOn: '2026-10-11');

        self::assertSame('2026-11-01', $loan->dueOn->format('Y-m-d'));
        self::assertSame(LoanStatus::Active, $loan->status(self::day('2026-11-01')));
        self::assertSame(LoanStatus::Overdue, $loan->status(self::day('2026-11-02')));
    }

    #[DataProvider('fines')]
    public function testFineGrowsPerDayLateUpToTheCap(string $today, int $expectedCents): void
    {
        $loan = $this->loan(MemberType::Standard, lentOn: '2026-10-11');   // due 2026-11-01, 25 cents a day

        self::assertEquals(Money::cents($expectedCents), $loan->fine(self::day($today)));
    }

    /** @return iterable<string, array{string, int}> */
    public static function fines(): iterable
    {
        yield 'on the due date' => ['2026-11-01', 0];
        yield 'one day late' => ['2026-11-02', 25];
        yield 'ten days late' => ['2026-11-11', 250];
        yield 'capped at $10' => ['2027-03-01', Loan::FINE_CAP_CENTS];
    }

    public function testStaffPayNoFines(): void
    {
        $loan = $this->loan(MemberType::Staff, lentOn: '2026-10-11');

        self::assertTrue($loan->fine(self::day('2027-01-01'))->isZero());
    }

    public function testRenewingExtendsTheDueDateByTheLoanLength(): void
    {
        $loan = $this->loan(MemberType::Standard, lentOn: '2026-10-11');
        $loan->renew(self::day('2026-10-30'));

        self::assertSame('2026-11-22', $loan->dueOn->format('Y-m-d'));
        self::assertSame(1, $loan->renewals);
    }

    public function testCannotRenewMoreThanTwice(): void
    {
        $loan = $this->loan(MemberType::Standard, lentOn: '2026-10-11');
        $loan->renew(self::day('2026-10-12'));
        $loan->renew(self::day('2026-10-12'));

        $this->expectExceptionObject(LoanRuleViolation::renewalLimitReached($loan));
        $loan->renew(self::day('2026-10-12'));
    }

    public function testCannotRenewAnOverdueLoan(): void
    {
        $loan = $this->loan(MemberType::Standard, lentOn: '2026-10-11');

        $this->expectException(LoanRuleViolation::class);
        $loan->renew(self::day('2026-11-05'));
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }
}
