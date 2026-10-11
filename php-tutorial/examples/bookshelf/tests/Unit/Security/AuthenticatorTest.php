<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Unit\Security;

use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Security\AuthenticationFailed;
use Bookshelf\Security\Authenticator;
use Bookshelf\Security\LoginThrottle;
use Bookshelf\Support\FrozenClock;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Authenticator::class)]
final class AuthenticatorTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        // LoginThrottle is a final class, so it can't be replaced by a mock; a real one on an in-memory table is
        // simple and fast.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, subject TEXT, attempted_at INTEGER)');
        $this->throttle = new LoginThrottle($pdo, FrozenClock::at('2026-10-11 10:00'));
    }

    public function testReturnsTheMemberForTheRightPassword(): void
    {
        $ada = $this->memberWithPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT));
        $members = $this->createStub(MemberRepository::class);
        $members->method('findByEmail')->willReturn($ada);

        $authenticator = new Authenticator($members, $this->throttle);

        self::assertSame($ada, $authenticator->authenticate('ada@example.org', self::PASSWORD, '203.0.113.7'));
    }

    public function testRejectsAWrongPasswordAndAnUnknownAddressWithTheSameMessage(): void
    {
        $members = $this->createStub(MemberRepository::class);
        $members->method('findByEmail')->willReturnCallback(
            fn (string $email) => $email === 'ada@example.org'
                ? $this->memberWithPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
                : null,
        );
        $authenticator = new Authenticator($members, $this->throttle);

        $messages = [];
        foreach (['ada@example.org', 'nobody@example.org'] as $email) {
            try {
                $authenticator->authenticate($email, 'wrong password', '203.0.113.7');
                self::fail('Expected AuthenticationFailed');
            } catch (AuthenticationFailed $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertSame($messages[0], $messages[1]);
    }

    public function testUpgradesAnOutdatedHashAtLogin(): void
    {
        $ada = $this->memberWithPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]));
        $members = $this->createMock(MemberRepository::class);
        $members->method('findByEmail')->willReturn($ada);
        $members->expects($this->once())->method('save')->with($ada);

        new Authenticator($members, $this->throttle)->authenticate('ada@example.org', self::PASSWORD, '203.0.113.7');

        self::assertFalse(password_needs_rehash((string) $ada->passwordHash, PASSWORD_DEFAULT));
        self::assertTrue(password_verify(self::PASSWORD, (string) $ada->passwordHash));
    }

    public function testDoesNotSaveWhenTheHashIsCurrent(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $members->method('findByEmail')->willReturn($this->memberWithPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT)));
        $members->expects($this->never())->method('save');

        new Authenticator($members, $this->throttle)->authenticate('ada@example.org', self::PASSWORD, '203.0.113.7');
    }

    public function testBlocksAfterTooManyFailures(): void
    {
        $members = $this->createStub(MemberRepository::class);
        $members->method('findByEmail')->willReturn(null);
        $authenticator = new Authenticator($members, $this->throttle);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $authenticator->authenticate('ada@example.org', "guess $attempt", '203.0.113.7');
            } catch (AuthenticationFailed $e) {
                self::assertFalse($e->throttled);
            }
        }

        $this->expectExceptionObject(AuthenticationFailed::tooManyAttempts());
        $authenticator->authenticate('ada@example.org', self::PASSWORD, '203.0.113.7');
    }

    private function memberWithPassword(string $hash): Member
    {
        return new Member(1, 'Ada Lovelace', 'ada@example.org', passwordHash: $hash);
    }
}
