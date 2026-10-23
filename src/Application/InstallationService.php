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
 * - Одинаковый пароль не создаёт второго кандидата и не меняет активное поколение
 * - Непроверенные кандидаты старше суток освобождают квоту при следующем install
 * - Неподписанный callback не заменяет уже проверенные credentials
 * - До 5 свежих непроверенных кандидатов на магазин
 */
final readonly class InstallationService
{
    private const MAX_SHOP_LENGTH = 255;
    private const MAX_PENDING_CANDIDATES = 5;
    private const PENDING_CANDIDATE_TTL = 86400;

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
     * @return string Id кандидата либо store id, если такой пароль уже активирован
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

        // Квота, дедуп и запись — в одной транзакции. Сравнение идёт по
        // расшифрованному паролю: ciphertext каждый раз получает новый nonce.
        $savedId = $this->db->transaction(function () use ($shop, $insalesId, $password): string {
            /** @var array{store_id: string, shop: string, encrypted_password: string}|null $store */
            $store = $this->db->fetch(
                'SELECT store_id, shop, encrypted_password FROM stores WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            );
            if ($store !== null) {
                if ($store['shop'] !== $shop) {
                    throw new \InvalidArgumentException('Store id is already installed for another shop');
                }
                $storePassword = $this->encryption->decrypt($store['encrypted_password']);
                if ($this->sameSecret($storePassword, $password)) {
                    return $store['store_id'];
                }
                throw new \InvalidArgumentException(
                    'Store already has an active installation. Uninstall first before reinstalling.'
                );
            }

            // Активное поколение без строки stores не должно блокировать новую установку.
            $this->db->execute(
                'UPDATE installation_generations SET status = :cancelled
                 WHERE store_id = :store_id AND status = :active',
                [
                    'cancelled' => 'cancelled',
                    'store_id' => $insalesId,
                    'active' => 'active',
                ],
            );

            $this->expireStaleCandidates($insalesId);

            /** @var array<int, array{id: string, encrypted_password: string}> $candidates */
            $candidates = $this->db->fetchAll(
                'SELECT id, encrypted_password FROM install_candidates
                 WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $insalesId, 'shop' => $shop],
            );
            foreach ($candidates as $candidate) {
                $existingPassword = $this->encryption->decrypt($candidate['encrypted_password']);
                if ($this->sameSecret($existingPassword, $password)) {
                    return $candidate['id'];
                }
            }

            /** @var array{cnt: int}|null $pendingRow */
            $pendingRow = $this->db->fetch(
                'SELECT COUNT(*) as cnt FROM install_candidates WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            );
            $pendingCount = $pendingRow['cnt'] ?? 0;

            if ($pendingCount >= self::MAX_PENDING_CANDIDATES) {
                throw new \RuntimeException(
                    "Too many pending install candidates for store {$insalesId}. "
                    . 'Complete or remove existing candidates first.'
                );
            }

            $candidateId = bin2hex(random_bytes(32));
            $encryptedPassword = $this->encryption->encrypt($password);

            // candidate_version: монотонно возрастающий номер (сохраняется, т.к. считаем по MAX)
            /** @var array{v: int}|null $maxRow */
            $maxRow = $this->db->fetch(
                'SELECT MAX(candidate_version) as v FROM install_candidates WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            );
            $currentMaxVersion = $maxRow['v'] ?? 0;
            $candidateVersion = $currentMaxVersion + 1;

            // Резервируем поколение
            /** @var array{g: int}|null $genRow */
            $genRow = $this->db->fetch(
                'SELECT COALESCE(MAX(generation), 0) as g FROM installation_generations WHERE store_id = :store_id',
                ['store_id' => $insalesId],
            );
            $currentGen = $genRow['g'] ?? 0;
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

            return $candidateId;
        });

        if (!is_string($savedId) || $savedId === '') {
            throw new \RuntimeException('Install was not saved');
        }

        return $savedId;
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
        /** @var array{id: string, encrypted_password: string, gen_generation: int}|null $candidate */
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
            /** @var array{store_id: string, generation: int}|null $store */
            $store = $this->db->fetch(
                'SELECT store_id, generation FROM stores WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $insalesId, 'shop' => $shop],
            );
            return $store !== null
                ? ['store_id' => $store['store_id'], 'generation' => $store['generation']]
                : null;
        }

        $candidateId = $candidate['id'];
        $encryptedPassword = $candidate['encrypted_password'];
        $genGeneration = $candidate['gen_generation'];
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
            $this->rejectCandidate($insalesId, $candidateId);
            throw new \InvalidArgumentException('Install verification failed: ' . $e->getMessage());
        }

        $returnedId = (string) ($account['id'] ?? '');
        if ($returnedId !== $insalesId) {
            $this->rejectCandidate($insalesId, $candidateId);
            throw new \InvalidArgumentException('Store identity mismatch during install verification');
        }

        // 3. Activate inside write transaction
        $activated = null;
        $this->db->transaction(function () use (
            $candidateId,
            $insalesId,
            $shop,
            $password,
            $genGeneration,
            $account,
            &$activated,
        ) {
            // Re-read candidate within transaction
            /** @var array{gen_status: string}|null $current */
            $current = $this->db->fetch(
                'SELECT c.id, ig.status as gen_status
                 FROM install_candidates c
                 JOIN installation_generations ig ON ig.store_id = c.store_id AND ig.candidate_id = c.id
                 WHERE c.id = :id',
                ['id' => $candidateId],
            );

            if ($current === null || $current['gen_status'] !== 'reserved') {
                return; // Cancelled by concurrent uninstall
            }

            // Check no newer active generation exists
            /** @var array{generation: int}|null $activeGen */
            $activeGen = $this->db->fetch(
                'SELECT generation FROM installation_generations
                 WHERE store_id = :store_id AND status = :active
                 ORDER BY generation DESC LIMIT 1',
                ['store_id' => $insalesId, 'active' => 'active'],
            );
            if ($activeGen !== null && $activeGen['generation'] >= $genGeneration) {
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

    private function sameSecret(string $left, string $right): bool
    {
        return strlen($left) === strlen($right) && hash_equals($left, $right);
    }

    /**
     * Drop unverified candidates older than the pending TTL and free their quota.
     */
    private function expireStaleCandidates(string $storeId): void
    {
        $cutoff = time() - self::PENDING_CANDIDATE_TTL;
        /** @var array<int, array{id: string}> $stale */
        $stale = $this->db->fetchAll(
            'SELECT id FROM install_candidates WHERE store_id = :store_id AND created_at < :cutoff',
            ['store_id' => $storeId, 'cutoff' => $cutoff],
        );

        foreach ($stale as $row) {
            $this->rejectCandidate($storeId, $row['id']);
        }
    }

    private function rejectCandidate(string $storeId, string $candidateId): void
    {
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
