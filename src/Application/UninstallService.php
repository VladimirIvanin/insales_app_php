<?php

declare(strict_types=1);

namespace Application;

use InSales\Protocol;
use InSales\ShopValidator;
use Infrastructure\Database;
use Infrastructure\Encryption;

/**
 * Service handling application uninstall.
 *
 * Verifies uninstall token (which is the store password),
 * atomically removes credentials, invalidates sessions, challenges,
 * and pending install candidates.
 *
 * Re-delivery: repeated uninstall for already removed store returns 200
 * (idempotent). Old uninstall callback after reinstall is rejected because
 * token comparison uses the old password which won't match the new generation.
 */
final readonly class UninstallService
{
    public function __construct(
        private Database $db,
        private Encryption $encryption,
        private string $appSecret,
        private array $allowedShops,
        private bool $allowHttp,
    ) {
    }

    /**
     * Handle uninstall callback from InSales.
     *
     * Всегда чистит install_candidates и jobs для store_id/shop,
     * даже если строки stores ещё нет (неактивированная установка).
     * При наличии candidate проверяет token по его credential.
     *
     * @return bool True if uninstall was processed, false if nothing to remove (and token matches — no-op)
     * @throws \InvalidArgumentException
     */
    public function handleUninstall(string $shop, string $token, string $insalesId): bool
    {
        $shop = ShopValidator::validate($shop, $this->allowedShops, $this->allowHttp);

        // Проверяем token (может быть по store или candidate)
        // Чтение и проверка — только для авторизации, без состояния для удаления
        $store = $this->db->fetch(
            'SELECT * FROM stores WHERE store_id = :store_id AND shop = :shop',
            ['store_id' => $insalesId, 'shop' => $shop],
        );

        $passwordToVerify = null;

        if ($store !== null) {
            $passwordToVerify = $this->encryption->decrypt($store['encrypted_password']);
        } else {
            $candidate = $this->db->fetch(
                'SELECT * FROM install_candidates WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $insalesId, 'shop' => $shop],
            );
            if ($candidate !== null) {
                $passwordToVerify = $this->encryption->decrypt($candidate['encrypted_password']);
            }
        }

        if ($passwordToVerify === null) {
            return false;
        }

        if (!hash_equals($passwordToVerify, $token)) {
            throw new \InvalidArgumentException('Invalid uninstall token');
        }

        // Весь cleanup — в транзакции, с перепроверкой актуального состояния
        $processed = false;

        $this->db->transaction(function () use ($insalesId, $shop, &$processed) {
            // Перечитываем store внутри транзакции (worker мог активировать между чтением и транзакцией)
            $store = $this->db->fetch(
                'SELECT * FROM stores WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $insalesId, 'shop' => $shop],
            );

            if ($store !== null) {
                $processed = true;

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
            }

            $this->db->execute(
                'DELETE FROM install_candidates WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $insalesId, 'shop' => $shop],
            );

            $this->db->execute(
                "UPDATE jobs SET status = 'failed', error = :error, completed_at = :now
                 WHERE store_id = :store_id AND status = 'pending'",
                [
                    'store_id' => $insalesId,
                    'error' => 'Uninstalled',
                    'now' => time(),
                ],
            );
        });

        return $processed;
    }
}
