<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

interface MemberRepository
{
    /** @throws EntityNotFound */
    public function get(int $id): Member;

    public function findByEmail(string $email): ?Member;

    /** Inserts a new member (and assigns its id) or updates an existing one. */
    public function save(Member $member): void;
}
