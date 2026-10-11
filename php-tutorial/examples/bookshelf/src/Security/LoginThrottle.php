<?php

declare(strict_types=1);

namespace Bookshelf\Security;

use Bookshelf\Support\Clock;
use PDO;

/**
 * Limits password guessing: too many recent failures for one account, or from one IP address, block further attempts
 * until the oldest failures are out of the time window.
 */
final readonly class LoginThrottle
{
    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private int $maxPerAccount = 5,
        private int $maxPerAddress = 20,
        private int $windowSeconds = 15 * 60,
    ) {
    }

    public function isBlocked(string $email, string $address): bool
    {
        $count = $this->pdo->prepare('SELECT count(*) FROM login_attempts WHERE subject = ? AND attempted_at > ?');
        $since = $this->now() - $this->windowSeconds;

        $count->execute([self::account($email), $since]);
        if ((int) $count->fetchColumn() >= $this->maxPerAccount) {
            return true;
        }
        $count->execute([self::address($address), $since]);

        return (int) $count->fetchColumn() >= $this->maxPerAddress;
    }

    public function recordFailure(string $email, string $address): void
    {
        $now = $this->now();
        $insert = $this->pdo->prepare('INSERT INTO login_attempts (subject, attempted_at) VALUES (?, ?)');
        $insert->execute([self::account($email), $now]);
        $insert->execute([self::address($address), $now]);

        // Housekeeping: attempts outside the window no longer count.
        $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at <= ?')->execute([$now - $this->windowSeconds]);
    }

    /** After a successful login, the account's earlier failures no longer count. */
    public function clear(string $email): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE subject = ?')->execute([self::account($email)]);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private static function account(string $email): string
    {
        return 'account:' . strtolower(trim($email));
    }

    private static function address(string $address): string
    {
        return 'address:' . $address;
    }
}
