<?php

declare(strict_types=1);

namespace Application;

use InSales\ApiClient;
use InSales\ApiException;
use InSales\Protocol;
use InSales\ShopValidator;
use Infrastructure\Database;
use Infrastructure\Encryption;

/**
 * Service handling the installation lifecycle:
 * 1. Receive install request, reserve generation, create candidate (no API call)
 * 2. Verify candidate via API during /login (synchronous)
 * 3. Activate verified connection
 *
 * Generations and transactional safety:
 * - Each install attempt reserves a generation via installation_generations table
 * - Reserve, candidate save are in ONE write transaction
 * - prepareAndActivate checks candidate freshness before activation
 * - Uninstall cancels all pending generations for the store
 * - Max 5 pending candidates per store to prevent abuse
 */
final readonly class InstallationService
{
    private const MAX_SHOP_LENGTH = 255;
    private const MAX_PENDING_CANDIDATES = 5;

    /**
     * @param array<int, string> $allowedShops
     */
    public function __construct(
        private Database $db,
        private Encryption $encryption,
        private string $appSecret,
        private string $appId,
        private array $allowedShops,
        private bool $allowHttp,
        private ?string $platformBaseUrl = null,
    ) {
    }

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

        // Всё в одной транзакции: резервирование поколения + кандидат
        $this->db->transaction(function () use ($shop, $insalesId, $candidateId, $encryptedPassword) {
            // Если есть активное поколение — новый install не нужен.
            // Повторная установка возможна только после uninstall.
            $activeGen = $this->db->fetch(
                'SELECT 1 FROM installation_generations
                 WHERE store_id = :store_id AND status = :active
                 LIMIT 1',
                ['store_id' => $insalesId, 'active' => 'active'],
            );
            if ($activeGen !== null) {
                throw new \InvalidArgumentException(
                    "Store {$insalesId} already has an active installation. "
                    . 'Uninstall first before reinstalling.'
                );
            }

            // Лимит незавершённых кандидатов
            $pendingCount = (int) ($this->db->fetch(
                'SELECT COUNT(*) as cnt FROM install_candidates WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            )['cnt'] ?? 0);

            if ($pendingCount >= self::MAX_PENDING_CANDIDATES) {
                throw new \RuntimeException(
                    "Too many pending install candidates for store {$insalesId}. "
                    . 'Complete or remove existing candidates first.'
                );
            }

            // candidate_version: монотонно возрастающий номер (сохраняется, т.к. считаем по MAX)
            $currentMaxVersion = (int) ($this->db->fetch(
                'SELECT MAX(candidate_version) as v FROM install_candidates WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            )['v'] ?? 0);
            $candidateVersion = $currentMaxVersion + 1;

            // Резервируем поколение
            $currentGen = (int) ($this->db->fetch(
                'SELECT COALESCE(MAX(generation), 0) as g FROM installation_generations WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            )['g'] ?? 0);
            $newGeneration = $currentGen + 1;

            $this->db->execute(
                'INSERT INTO installation_generations (store_id, generation, status, candidate_id, created_at)
                 VALUES (:store_id, :generation, :status, :candidate_id, :created_at)',
                [
                    'store_id' => $insalesId,
                    'generation' => $newGeneration,
                    'status' => 'reserved',
                    'candidate_id' => $candidateId,
                    'created_at' => time(),
                ],
            );

            // Сохраняем кандидата с привязкой к поколению
            $this->db->execute(
                'INSERT INTO install_candidates (id, store_id, shop, encrypted_password, candidate_version, created_at)
                 VALUES (:id, :store_id, :shop, :encrypted_password, :candidate_version, :created_at)',
                [
                    'id' => $candidateId,
                    'store_id' => $insalesId,
                    'shop' => $shop,
                    'encrypted_password' => $encryptedPassword,
                    'candidate_version' => $candidateVersion,
                    'created_at' => time(),
                ],
            );
        });

        return $candidateId;
    }

    /**
     * Prepare a store for login: find and activate the best pending candidate.
     *
     * Called synchronously during /login before creating the login challenge.
     * If no pending candidate exists, proceeds with active installation if any.
     * Makes one API call to verify credentials.
     *
     * @return array{store_id: string, generation: int}|null Active store data, or null if not found
     *
     * @throws \RuntimeException On temporary API failure (caller should show 503)
     * @throws \InvalidArgumentException On permanent rejection (bad credentials, wrong account)
     */
    public function prepareAndActivate(string $shop, string $insalesId): ?array
    {
        $shop = ShopValidator::validate($shop, $this->allowedShops, $this->allowHttp);

        // 1. Find the newest pending candidate for this shop+store
        $candidate = $this->db->fetch(
            'SELECT c.*, ig.generation as gen_generation, ig.status as gen_status
             FROM install_candidates c
             JOIN installation_generations ig ON ig.store_id = c.store_id AND ig.candidate_id = c.id
             WHERE c.store_id = :store_id AND c.shop = :shop AND ig.status = :reserved
             ORDER BY c.candidate_version DESC
             LIMIT 1',
            ['store_id' => $insalesId, 'shop' => $shop, 'reserved' => 'reserved'],
        );

        // No candidate — check if store is already active
        if ($candidate === null) {
            $store = $this->db->fetch(
                'SELECT store_id, generation FROM stores WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $insalesId, 'shop' => $shop],
            );
            return $store !== null
                ? ['store_id' => (string) $store['store_id'], 'generation' => (int) $store['generation']]
                : null;
        }

        $candidateId = (string) $candidate['id'];
        $encryptedPassword = (string) $candidate['encrypted_password'];
        $genGeneration = (int) $candidate['gen_generation'];
        $password = $this->encryption->decrypt($encryptedPassword);

        // 2. Verify via API (outside write transaction)
        try {
            $client = new ApiClient(
                appId: $this->appId,
                shop: $shop,
                password: $password,
                allowHttp: $this->allowHttp,
                logger: new \Psr\Log\NullLogger(),
                platformBaseUrl: $this->platformBaseUrl,
            );

            $account = $client->getAccount();
        } catch (ApiException $e) {
            if ($e->isTemporary()) {
                throw new \RuntimeException(
                    'Temporary API error: ' . $e->getMessage(),
                    (int) $e->getCode(),
                    $e,
                );
            }
            // Permanent auth failure — reject this candidate only
            $this->db->execute(
                'UPDATE installation_generations SET status = :cancelled
                 WHERE store_id = :store_id AND candidate_id = :candidate_id AND status = :reserved',
                ['cancelled' => 'cancelled', 'store_id' => $insalesId, 'candidate_id' => $candidateId, 'reserved' => 'reserved'],
            );
            $this->db->execute('DELETE FROM install_candidates WHERE id = :id', ['id' => $candidateId]);
            throw new \InvalidArgumentException('Install verification failed: ' . $e->getMessage());
        }

        $returnedId = (string) ($account['id'] ?? '');
        if ($returnedId !== $insalesId) {
            // Wrong account — reject
            $this->db->execute(
                'UPDATE installation_generations SET status = :cancelled
                 WHERE store_id = :store_id AND candidate_id = :candidate_id AND status = :reserved',
                ['cancelled' => 'cancelled', 'store_id' => $insalesId, 'candidate_id' => $candidateId, 'reserved' => 'reserved'],
            );
            $this->db->execute('DELETE FROM install_candidates WHERE id = :id', ['id' => $candidateId]);
            throw new \InvalidArgumentException('Store identity mismatch during install verification');
        }

        // 3. Activate inside write transaction
        $activated = null;
        $this->db->transaction(function () use ($candidateId, $insalesId, $shop, $password, $genGeneration, $account, &$activated) {
            // Re-read candidate within transaction
            $current = $this->db->fetch(
                'SELECT c.id, ig.status as gen_status
                 FROM install_candidates c
                 JOIN installation_generations ig ON ig.store_id = c.store_id AND ig.candidate_id = c.id
                 WHERE c.id = :id',
                ['id' => $candidateId],
            );

            if ($current === null || ((string) ($current['gen_status'] ?? '')) !== 'reserved') {
                return; // Cancelled by concurrent uninstall
            }

            // Check no newer active generation exists
            $activeGen = $this->db->fetch(
                'SELECT generation FROM installation_generations
                 WHERE store_id = :store_id AND status = :active
                 ORDER BY generation DESC LIMIT 1',
                ['store_id' => $insalesId, 'active' => 'active'],
            );
            if ($activeGen !== null && (int) $activeGen['generation'] >= $genGeneration) {
                return; // Already activated by concurrent request
            }

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
                    'store_id' => $insalesId,
                    'shop' => $shop,
                    'encrypted_password' => $encryptedPassword,
                    'title' => $title,
                    'generation' => $genGeneration,
                    'installed_at' => time(),
                ],
            );

            $this->db->execute(
                'UPDATE installation_generations SET status = :active, activated_at = :now
                 WHERE store_id = :store_id AND candidate_id = :candidate_id',
                ['active' => 'active', 'now' => time(), 'store_id' => $insalesId, 'candidate_id' => $candidateId],
            );

            // Cancel other reserved generations
            $this->db->execute(
                'UPDATE installation_generations SET status = :cancelled
                 WHERE store_id = :store_id AND status = :reserved',
                ['cancelled' => 'cancelled', 'store_id' => $insalesId, 'reserved' => 'reserved'],
            );

            // Invalidate old sessions and challenges
            $this->db->execute('DELETE FROM sessions WHERE store_id = :store_id', ['store_id' => $insalesId]);
            $this->db->execute('DELETE FROM pending_logins WHERE store_id = :store_id', ['store_id' => $insalesId]);

            // Remove candidate
            $this->db->execute('DELETE FROM install_candidates WHERE id = :id', ['id' => $candidateId]);

            $activated = ['store_id' => $insalesId, 'generation' => $genGeneration];
        });

        return $activated;
    }

    /**
     * Cancel a generation (called from uninstall).
     */
    public function cancelGeneration(string $storeId): void
    {
        $this->db->transaction(function () use ($storeId) {
            // Cancel all reserved candidates and generations
            $candidates = $this->db->fetchAll(
                'SELECT id FROM install_candidates WHERE store_id = :store_id',
                ['store_id' => $storeId],
            );

            foreach ($candidates as $candidate) {
                $this->db->execute(
                    'DELETE FROM install_candidates WHERE id = :id',
                    ['id' => $candidate['id']],
                );
            }

            // Cancel pending generations
            $this->db->execute(
                'UPDATE installation_generations SET status = :cancelled
                 WHERE store_id = :store_id AND status = :reserved',
                [
                    'cancelled' => 'cancelled',
                    'store_id' => $storeId,
                    'reserved' => 'reserved',
                ],
            );
        });
    }

    /**
     * Clean up a candidate and its generation link.
     *
     * @internal Используется JobQueue::onFinalFail для очистки при исчерпании retries.
     *           Не предназначен для внешнего вызова — меняет состояние generations.
     */
    public function cleanupCandidate(string $candidateId, string $storeId): void
    {
        // Cancel abandoned generations
        $this->db->execute(
            'UPDATE installation_generations SET status = :cancelled
             WHERE store_id = :store_id AND candidate_id = :candidate_id AND status = :reserved',
            [
                'cancelled' => 'cancelled',
                'store_id' => $storeId,
                'candidate_id' => $candidateId,
                'reserved' => 'reserved',
            ],
        );

        $this->db->execute(
            'DELETE FROM install_candidates WHERE id = :id',
            ['id' => $candidateId],
        );
    }
}
