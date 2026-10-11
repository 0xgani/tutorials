<?php

declare(strict_types=1);

namespace Bookshelf\Security;

use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberRepository;

/**
 * Checks e-mail and password logins.
 */
final readonly class Authenticator
{
    /**
     * A bcrypt hash of a random string nobody knows. It is verified when the e-mail address is unknown, so that the
     * answer takes as long as for a real account and response times don't reveal which addresses are registered.
     */
    private const string DUMMY_HASH = '$2y$12$ah.zwSRfP.qohIDyoLUUu.vhgssNeI8H7WjEqE4KapObfr9iV4QNi';

    public function __construct(
        private MemberRepository $members,
        private LoginThrottle $throttle,
    ) {
    }

    /** @throws AuthenticationFailed */
    public function authenticate(string $email, #[\SensitiveParameter] string $password, string $address): Member
    {
        if ($this->throttle->isBlocked($email, $address)) {
            throw AuthenticationFailed::tooManyAttempts();
        }

        $member = $this->members->findByEmail($email);
        $hash = $member?->passwordHash;
        $matches = password_verify($password, $hash ?? self::DUMMY_HASH);

        if ($member === null || $hash === null || !$matches) {
            $this->throttle->recordFailure($email, $address);
            throw AuthenticationFailed::invalidCredentials();
        }

        $this->throttle->clear($email);

        // Hashes made with older settings (a lower cost, an older algorithm) are upgraded at the next login,
        // the only moment the plain password is available.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $member->changePasswordHash(password_hash($password, PASSWORD_DEFAULT));
            $this->members->save($member);
        }

        return $member;
    }
}
