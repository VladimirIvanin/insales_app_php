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
    ) {}

    /**
     * Handle uninstall callback from InSales.
     *
     * @return bool True if uninstall was processed, false if store not found
     * @throws \InvalidArgumentException
     */
    public function handleUninstall(string $shop, string $token, string $insalesId): bool
    {
        $shop = ShopValidator::validate($shop, $this->allowedShops, $this->allowHttp);

        $store = $this->db->fetch(
            'SELECT * FROM stores WHERE store_id = :store_id AND shop = :shop',
            ['store_id' => $insalesId, 'shop' => $shop],
        );

        if ($store === null) {
            // Already uninstalled — idempotent response
            return false;
        }

        $password = $this->encryption->decrypt($store['encrypted_password']);

        // Compare token with store password (constant-time)
        if (!hash_equals($password, $token)) {
            throw new \InvalidArgumentException('Invalid uninstall token');
        }

        // Atomically remove everything
        $this->db->transaction(function () use ($insalesId) {
            // Invalidate sessions
            $this->db->execute(
                'DELETE FROM sessions WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            );

            // Remove pending challenges
            $this->db->execute(
                'DELETE FROM pending_logins WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            );

            // Remove install candidates for this store
            $this->db->execute(
                'DELETE FROM install_candidates WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            );

            // Remove store credentials
            $this->db->execute(
                'DELETE FROM stores WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            );
        });

        return true;
    }
}