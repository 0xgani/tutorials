<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Functional;

use Bookshelf\Container\Container;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Domain\MemberType;
use Bookshelf\Http\Request;
use Bookshelf\Http\Response;
use Bookshelf\Lending\LendingService;
use Bookshelf\Persistence\Migrator;
use Bookshelf\Support\Clock;
use Bookshelf\Support\FrozenClock;
use Bookshelf\Tests\Support\ArraySessionStorage;
use Bookshelf\Tests\Support\Builders;
use Bookshelf\Web\Kernel;
use Bookshelf\Web\Session;
use Bookshelf\Web\SessionStorage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Logged-in flows through the whole application: the staff's lending desk and members renewing their loans.
 * The session lives in memory and the clock stands still, so each test can play a visitor over several requests.
 */
#[CoversNothing]
final class LendingDeskTest extends TestCase
{
    use Builders;

    private Container $container;
    private ArraySessionStorage $session;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->container = require dirname(__DIR__, 2) . '/config/container.php';
        $this->session = new ArraySessionStorage();
        $this->clock = FrozenClock::at('2026-10-11 10:00', 'Europe/Berlin');
        $this->container->set(SessionStorage::class, fn () => $this->session);
        $this->container->set(Clock::class, fn () => $this->clock);
        $this->container->get(Migrator::class)->migrate();

        $this->container->get(BookRepository::class)->save(self::book(id: null, copies: 1));   // Dune, id 1
        $members = $this->container->get(MemberRepository::class);
        $members->save(new Member(null, 'Grace Hopper', 'grace@example.org', MemberType::Staff));
        $members->save(self::member(id: null));                                               // Ada, standard
        $members->save(new Member(null, 'Alan Turing', 'alan@example.org'));
    }

    public function testStaffLogInAndLandOnTheDesk(): void
    {
        $member = $this->findMember('grace@example.org');
        $member->changePasswordHash(password_hash('correct horse battery staple', PASSWORD_DEFAULT));
        $this->container->get(MemberRepository::class)->save($member);

        self::assertSame(200, $this->get('/login', ['next' => '/desk'])->status);    // the form sets the CSRF token
        $response = $this->post('/login', [
            'email' => 'grace@example.org',
            'password' => 'correct horse battery staple',
            'next' => '/desk',
        ]);

        self::assertSame(303, $response->status);
        self::assertSame('/desk', $response->header('Location'));
        self::assertSame(1, $this->session->regenerations);                         // a new session ID at login
        $desk = $this->get('/desk');
        self::assertSame(200, $desk->status);
        self::assertStringContainsString('Welcome back, Grace Hopper.', $desk->body);
        self::assertStringContainsString('Nothing is overdue.', $desk->body);
    }

    public function testStaffLendAndTakeBackABook(): void
    {
        $this->logIn('grace@example.org');

        $lent = $this->post('/desk/loans', ['member' => 'ada@example.org', 'isbn' => '978-0441013593']);

        self::assertSame(303, $lent->status);
        self::assertSame('/desk?member=ada%40example.org', $lent->header('Location'));
        $desk = $this->get('/desk', ['member' => 'ada@example.org']);
        self::assertStringContainsString('Lent &quot;Dune&quot; to Ada Lovelace, due back on Sun 1 Nov 2026.', $desk->body);
        self::assertStringContainsString('action="/desk/loans/1/return"', $desk->body);
        self::assertSame(0, $this->container->get(BookRepository::class)->get(1)->available);

        $this->clock->advance('+24 days');                                          // three days late
        $this->logIn('grace@example.org');                                          // the old session timed out
        $returned = $this->post('/desk/loans/1/return', []);

        self::assertSame('/desk?member=ada%40example.org', $returned->header('Location'));
        self::assertStringContainsString('Please collect a fine of $0.75.', $this->get('/desk')->body);
        self::assertSame(1, $this->container->get(BookRepository::class)->get(1)->available);
    }

    public function testTheDeskExplainsWhyABookCannotBeLent(): void
    {
        $this->logIn('grace@example.org');
        $this->post('/desk/loans', ['member' => 'ada@example.org', 'isbn' => '9780441013593']);

        $response = $this->post('/desk/loans', ['member' => 'alan@example.org', 'isbn' => '9780441013593']);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('No copies of &#039;Dune&#039; are available', $response->body);
        self::assertStringContainsString('value="9780441013593"', $response->body);   // what was typed is kept
    }

    public function testMembersCannotUseTheDesk(): void
    {
        $this->logIn('ada@example.org');

        self::assertSame(403, $this->get('/desk')->status);
        self::assertSame(403, $this->post('/desk/loans', ['member' => 'ada@example.org', 'isbn' => '9780441013593'])->status);
    }

    public function testAMemberRenewsTheirOwnLoan(): void
    {
        $this->lend('ada@example.org');                                              // loan 1, due Sun 1 Nov 2026
        $this->logIn('ada@example.org');

        $response = $this->post('/loans/1/renew', []);

        self::assertSame('/loans', $response->header('Location'));
        self::assertStringContainsString('The new due date is Sun 22 Nov 2026.', $this->get('/loans')->body);
    }

    public function testAMemberCannotRenewALoanOfAnotherMember(): void
    {
        $this->lend('alan@example.org');                                             // loan 1 is Alan's
        $this->logIn('ada@example.org');

        $response = $this->post('/loans/1/renew', []);

        self::assertSame(404, $response->status);
        self::assertSame(0, $this->container->get(LoanRepository::class)->get(1)->renewals);
    }

    public function testFormsWithoutTheTokenAreRejected(): void
    {
        $this->logIn('grace@example.org');

        $response = $this->handle(new Request('POST', '/desk/loans', body: ['member' => 'ada@example.org', 'isbn' => '9780441013593']));

        self::assertSame(403, $response->status);
        self::assertSame(1, $this->container->get(BookRepository::class)->get(1)->available);
    }

    /** Logs in without the login form, the way a session looks after a successful login. */
    private function logIn(string $email): void
    {
        $this->session->set('member_id', $this->findMember($email)->id);
        $this->session->set('last_seen', $this->clock->now()->getTimestamp());
    }

    private function lend(string $email): void
    {
        $this->container->get(LendingService::class)->lend(1, (int) $this->findMember($email)->id);
    }

    private function findMember(string $email): Member
    {
        return $this->container->get(MemberRepository::class)->findByEmail($email) ?? self::fail("No member $email");
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->handle(new Request('GET', $path, query: $query));
    }

    /**
     * Submits a form, with the session's CSRF token as a real page would include it.
     *
     * @param array<string, string> $fields
     */
    private function post(string $path, array $fields): Response
    {
        $token = $this->container->get(Session::class)->csrfToken();

        return $this->handle(new Request('POST', $path, body: [...$fields, '_csrf' => $token]));
    }

    private function handle(Request $request): Response
    {
        return $this->container->get(Kernel::class)->handle($request);
    }
}
