<?php

declare(strict_types=1);

namespace Application;

use InSales\ShopValidator;
use Infrastructure\Database;
use Infrastructure\Encryption;

/**
 * Service handling application uninstall.
 *
 * Token — готовый пароль подключения. Сравнение и удаление выполняются
 * в одной транзакции: снимаются все кандидаты и активная установка
 * с этим паролем. Записи с другим паролем не изменяются.
 *
 * Повтор того же пароля после удаления подтверждается по ограниченному
 * tombstone (хеш, не сам пароль) и отвечает успехом, ничего не удаляя.
 * При новом поколении старый пароль не считается доверенным.
 */
final readonly class UninstallService
{
    private const TOMBSTONE_TTL = 2592000;
    private const TOMBSTONE_LIMIT = 5;

    /**
     * @param array<int, string> $allowedShops
     */
    public function __construct(
        private Database $db,
        private Encryption $encryption,
        private array $allowedShops,
        private bool $allowHttp,
    ) {
    }

    /**
     * Handle uninstall callback from InSales.
     *
     * @return bool True when credentials were removed, false on idempotent replay
     * @throws \InvalidArgumentException
     */
    public function handleUninstall(string $shop, string $token, string $insalesId): bool
    {
        $shop = ShopValidator::validate($shop, $this->allowedShops, $this->allowHttp);

        if (!ctype_digit($insalesId)) {
            throw new \InvalidArgumentException('insales_id must be numeric');
        }

        $removed = $this->db->transaction(function () use ($insalesId, $shop, $token): bool {
            /** @var array{encrypted_password: string}|null $store */
            $store = $this->db->fetch(
                'SELECT encrypted_password FROM stores WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $insalesId, 'shop' => $shop],
            );

            $storeMatches = false;
            if ($store !== null) {
                $storePassword = $this->encryption->decrypt($store['encrypted_password']);
                $storeMatches = $this->sameSecret($storePassword, $token);
            }

            /** @var array<int, array{encrypted_password: string, id: string}> $candidates */
            $candidates = $this->db->fetchAll(
                'SELECT id, encrypted_password FROM install_candidates
                 WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $insalesId, 'shop' => $shop],
            );

            $matchedCandidateIds = [];
            foreach ($candidates as $candidate) {
                $candidatePassword = $this->encryption->decrypt($candidate['encrypted_password']);
                if ($this->sameSecret($candidatePassword, $token)) {
                    $matchedCandidateIds[] = $candidate['id'];
                }
            }

            if (!$storeMatches && $matchedCandidateIds === []) {
                // Tombstone подтверждает повтор уже снятого пароля и ничего не удаляет.
                // При живой установке с другим паролем старый token не доверенный.
                if ($store === null && $this->tombstoneMatches($insalesId, $shop, $token)) {
                    return false;
                }
                throw new \InvalidArgumentException('Invalid uninstall token');
            }

            foreach ($matchedCandidateIds as $matchedCandidateId) {
                $this->db->execute(
                    'DELETE FROM install_candidates WHERE id = :id',
                    ['id' => $matchedCandidateId],
                );
                $this->db->execute(
                    'UPDATE installation_generations SET status = :cancelled
                     WHERE store_id = :store_id AND candidate_id = :candidate_id AND status = :reserved',
                    [
                        'cancelled' => 'cancelled',
                        'store_id' => $insalesId,
                        'candidate_id' => $matchedCandidateId,
                        'reserved' => 'reserved',
                    ],
                );
            }

            if ($storeMatches) {
                $this->db->execute(
                    'DELETE FROM sessions WHERE store_id = :store_id',
                    ['store_id' => $insalesId],
                );
                $this->db->execute(
                    'DELETE FROM pending_logins WHERE store_id = :store_id',
                    ['store_id' => $insalesId],
                );
                $this->db->execute(
                    'DELETE FROM stores WHERE store_id = :store_id',
                    ['store_id' => $insalesId],
                );
                $this->db->execute(
                    'UPDATE installation_generations SET status = :cancelled
                     WHERE store_id = :store_id AND status = :active',
                    [
                        'cancelled' => 'cancelled',
                        'store_id' => $insalesId,
                        'active' => 'active',
                    ],
                );
            }

            $this->rememberTombstone($insalesId, $shop, $token);
            return true;
        });

        return $removed === true;
    }

    private function sameSecret(string $left, string $right): bool
    {
        return strlen($left) === strlen($right) && hash_equals($left, $right);
    }

    private function tombstoneMatches(string $storeId, string $shop, string $password): bool
    {
        $hash = $this->encryption->fingerprint($password);
        $row = $this->db->fetch(
            'SELECT 1 FROM uninstall_tombstones
             WHERE store_id = :store_id AND shop = :shop AND password_hash = :password_hash
               AND created_at >= :cutoff',
            [
                'store_id' => $storeId,
                'shop' => $shop,
                'password_hash' => $hash,
                'cutoff' => time() - self::TOMBSTONE_TTL,
            ],
        );

        return $row !== null;
    }

    private function rememberTombstone(string $storeId, string $shop, string $password): void
    {
        $hash = $this->encryption->fingerprint($password);
        $now = time();
        $this->db->execute(
            'INSERT INTO uninstall_tombstones (store_id, shop, password_hash, created_at)
             VALUES (:store_id, :shop, :password_hash, :created_at)
             ON CONFLICT(store_id, shop, password_hash) DO UPDATE SET created_at = excluded.created_at',
            [
                'store_id' => $storeId,
                'shop' => $shop,
                'password_hash' => $hash,
                'created_at' => $now,
            ],
        );
        $this->db->execute(
            'DELETE FROM uninstall_tombstones
             WHERE store_id = :store_id AND shop = :shop AND created_at < :cutoff',
            [
                'store_id' => $storeId,
                'shop' => $shop,
                'cutoff' => $now - self::TOMBSTONE_TTL,
            ],
        );

        /** @var array<int, array{password_hash: string}> $rows */
        $rows = $this->db->fetchAll(
            'SELECT password_hash FROM uninstall_tombstones
             WHERE store_id = :store_id AND shop = :shop
             ORDER BY created_at DESC',
            ['store_id' => $storeId, 'shop' => $shop],
        );
        foreach (array_slice($rows, self::TOMBSTONE_LIMIT) as $row) {
            $this->db->execute(
                'DELETE FROM uninstall_tombstones
                 WHERE store_id = :store_id AND shop = :shop AND password_hash = :password_hash',
                [
                    'store_id' => $storeId,
                    'shop' => $shop,
                    'password_hash' => $row['password_hash'],
                ],
            );
        }
    }
}
