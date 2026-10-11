<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

use InvalidArgumentException;
use LogicException;

/**
 * A person who can borrow books. Entity.
 */
final class Member
{
    public readonly string $email;

    public function __construct(
        public private(set) ?int $id,
        public readonly string $name,
        string $email,
        public readonly MemberType $type = MemberType::Standard,
        public private(set) ?string $passwordHash = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A member needs a name');
        }
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Invalid e-mail address '$email'");
        }
        $this->email = $email;
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new LogicException("Member already has id {$this->id}");
        }
        $this->id = $id;
    }

    public function changePasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
    }

    public function isStaff(): bool
    {
        return $this->type === MemberType::Staff;
    }
}
