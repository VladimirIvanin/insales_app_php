<?php

declare(strict_types=1);

namespace Application;

use InSales\ApiClient;
use InSales\Protocol;
use InSales\ShopValidator;
use Infrastructure\Database;
use Infrastructure\Encryption;
use Infrastructure\JobQueue;
use Psr\Log\NullLogger;

/**
 * Service handling the installation lifecycle:
 * 1. Receive install request, create candidate + verification job
 * 2. Verify candidate via API (async, in worker)
 * 3. Activate verified connection
 *
 * Design:
 * - Candidate is saved before responding 200 to InSales
 * - Verification is done asynchronously via JobQueue
 * - Existing active connection is preserved until new one is verified
 * - Old candidates are cleaned up on reinstall
 */
final readonly class InstallationService
{
    private const MAX_SHOP_LENGTH = 255;

    public function __construct(
        private Database $db,
        private Encryption $encryption,
        private JobQueue $queue,
        private string $appSecret,
        private string $appId,
        private array $allowedShops,
        private bool $allowHttp,
        private ?string $platformBaseUrl = null,
    ) {}

    /**
     * Handle install callback from InSales.
     *
     * @param string $shop Shop domain
     * @param string $token Install token from InSales
     * @param string $insalesId Store numeric ID
     * @return string Candidate ID for tracking
     */
    public function handleInstall(string $shop, string $token, string $insalesId): string
    {
        $shop = ShopValidator::validate($shop, $this->allowedShops, $this->allowHttp);

        if (!ctype_digit($insalesId)) {
            throw new \InvalidArgumentException('insales_id must be numeric');
        }

        if ($token === '') {
            throw new \InvalidArgumentException('install token must not be empty');
        }

        if (mb_strlen($shop) > self::MAX_SHOP_LENGTH) {
            throw new \InvalidArgumentException('Shop domain too long');
        }

        $password = Protocol::installPassword($token, $this->appSecret);
        $candidateId = bin2hex(random_bytes(32));
        $encryptedPassword = $this->encryption->encrypt($password);

        $this->db->execute(
            'INSERT INTO install_candidates (id, store_id, shop, encrypted_password, created_at)
             VALUES (:id, :store_id, :shop, :encrypted_password, :created_at)',
            [
                'id' => $candidateId,
                'store_id' => $insalesId,
                'shop' => $shop,
                'encrypted_password' => $encryptedPassword,
                'created_at' => time(),
            ],
        );

        // Schedule verification job
        $this->queue->push('verify_install', [
            'candidate_id' => $candidateId,
        ]);

        return $candidateId;
    }

    /**
     * Verify install candidate via InSales API.
     * Called from worker process.
     */
    public function verifyCandidate(string $candidateId): void
    {
        $candidate = $this->db->fetch(
            'SELECT * FROM install_candidates WHERE id = :id',
            ['id' => $candidateId],
        );

        if ($candidate === null) {
            return; // Already removed (e.g., uninstall happened)
        }

        $storeId = $candidate['store_id'];
        $shop = $candidate['shop'];
        $password = $this->encryption->decrypt($candidate['encrypted_password']);

        try {
            $client = new ApiClient(
                appId: $this->appId,
                shop: $shop,
                password: $password,
                allowHttp: $this->allowHttp,
                logger: new NullLogger(),
                platformBaseUrl: $this->platformBaseUrl,
            );

            $account = $client->getAccount();

            if ((string) $account['id'] !== (string) $storeId) {
                // Store identity mismatch — reject candidate
                $this->db->execute(
                    'DELETE FROM install_candidates WHERE id = :id',
                    ['id' => $candidateId],
                );
                return;
            }

            // Activation: atomic update within transaction
            $this->db->transaction(function () use ($candidateId, $storeId, $shop, $password, $account) {
                // Re-check that candidate still exists
                $stillExists = $this->db->fetch(
                    'SELECT 1 FROM install_candidates WHERE id = :id',
                    ['id' => $candidateId],
                );
                if ($stillExists === null) {
                    return;
                }

                $generation = (int) ($this->db->fetch(
                    'SELECT MAX(generation) as g FROM stores WHERE store_id = :store_id',
                    ['store_id' => $storeId],
                )['g'] ?? 0);

                $encryptedPassword = $this->encryption->encrypt($password);
                $title = mb_substr((string) ($account['title'] ?? 'Store'), 0, 255);

                $this->db->execute(
                    'INSERT INTO stores (store_id, shop, encrypted_password, title, generation, installed_at)
                     VALUES (:store_id, :shop, :encrypted_password, :title, :generation, :installed_at)
                     ON CONFLICT(store_id) DO UPDATE SET
                         shop = excluded.shop,
                         encrypted_password = excluded.encrypted_password,
                         title = excluded.title,
                         generation = excluded.generation,
                         installed_at = excluded.installed_at',
                    [
                        'store_id' => $storeId,
                        'shop' => $shop,
                        'encrypted_password' => $encryptedPassword,
                        'title' => $title,
                        'generation' => $generation + 1,
                        'installed_at' => time(),
                    ],
                );

                // Invalidate sessions for this store
                $this->db->execute(
                    'DELETE FROM sessions WHERE store_id = :store_id',
                    ['store_id' => $storeId],
                );

                // Clean up pending challenges
                $this->db->execute(
                    'DELETE FROM pending_logins WHERE store_id = :store_id',
                    ['store_id' => $storeId],
                );

                // Remove the candidate
                $this->db->execute(
                    'DELETE FROM install_candidates WHERE id = :id',
                    ['id' => $candidateId],
                );
            });
        } catch (\Throwable $e) {
            // Transient error — candidate stays in queue for retry
            // Permanent auth errors — reject candidate
            if ($e instanceof \InSales\ApiException && !$e->isTemporary()) {
                $this->db->execute(
                    'DELETE FROM install_candidates WHERE id = :id',
                    ['id' => $candidateId],
                );
            }
            throw $e; // Re-throw for queue retry logic
        }
    }
}