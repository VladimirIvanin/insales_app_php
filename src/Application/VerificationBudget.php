<?php

declare(strict_types=1);

namespace Application;

use Infrastructure\Database;

final readonly class VerificationBudget
{
    public function __construct(private Database $db)
    {
    }

    public function claim(string $shop): ?string
    {
        return $this->db->transaction(function () use ($shop): ?string {
            $now = microtime(true);
            $this->db->execute(
                'INSERT OR IGNORE INTO verification_budgets (shop, window_started) VALUES (:shop, :now)',
                ['shop' => $shop, 'now' => $now],
            );
            /** @var array{window_started: float, attempts: int, next_attempt_at: float, lease_until: float}|null $row */
            $row = $this->db->fetch('SELECT * FROM verification_budgets WHERE shop = :shop', ['shop' => $shop]);
            if ($row === null || $row['next_attempt_at'] > $now || $row['lease_until'] > $now) {
                return null;
            }
            $attempts = $row['window_started'] > $now - 60 ? (int) $row['attempts'] : 0;
            if ($attempts >= 10) {
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $this->db->execute(
                'UPDATE verification_budgets SET window_started = :started, attempts = :attempts, lease_until = :lease,
                 lease_token = :token WHERE shop = :shop',
                [
                    'started' => $attempts > 0 ? $row['window_started'] : $now,
                    'attempts' => $attempts + 1, 'lease' => $now + 15, 'token' => $token, 'shop' => $shop,
                ]
            );
            return $token;
        });
    }

    public function release(string $shop, string $token, float $delay): void
    {
        $this->db->execute(
            'UPDATE verification_budgets SET lease_until = 0, lease_token = NULL, next_attempt_at = :next
             WHERE shop = :shop AND lease_token = :token',
            ['next' => microtime(true) + $delay, 'shop' => $shop, 'token' => $token]
        );
    }
}
