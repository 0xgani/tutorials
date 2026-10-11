<?php

declare(strict_types=1);

namespace Bookshelf\Persistence;

use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Domain\MemberType;
use PDO;

final readonly class PdoMemberRepository implements MemberRepository
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    public function get(int $id): Member
    {
        $statement = $this->pdo->prepare('SELECT id, name, email, type, password_hash FROM members WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? throw EntityNotFound::member($id) : $this->toMember($row);
    }

    public function findByEmail(string $email): ?Member
    {
        $statement = $this->pdo->prepare('SELECT id, name, email, type, password_hash FROM members WHERE email = ?');
        $statement->execute([strtolower(trim($email))]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->toMember($row);
    }

    public function save(Member $member): void
    {
        $values = [
            'name' => $member->name,
            'email' => $member->email,
            'type' => $member->type->value,
            'password_hash' => $member->passwordHash,
        ];

        if ($member->id === null) {
            $this->pdo->prepare(
                'INSERT INTO members (name, email, type, password_hash) VALUES (:name, :email, :type, :password_hash)',
            )->execute($values);
            $member->assignId((int) $this->pdo->lastInsertId());
            return;
        }

        $this->pdo->prepare(
            'UPDATE members SET name = :name, email = :email, type = :type, password_hash = :password_hash WHERE id = :id',
        )->execute($values + ['id' => $member->id]);
    }

    /** @param array<string, mixed> $row */
    private function toMember(array $row): Member
    {
        return new Member($row['id'], $row['name'], $row['email'], MemberType::from($row['type']), $row['password_hash']);
    }
}
