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
 * 1. Receive install request, reserve generation, create candidate + verification job
 * 2. Verify candidate via API (async, in worker)
 * 3. Activate verified connection
 *
 * Generations and transactional safety:
 * - Each install attempt reserves a generation via installation_generations table
 * - Reserve, candidate save, and job push are in ONE write transaction
 * - verifyCandidate checks generation freshness before activation
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
        private JobQueue $queue,
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

        // Всё в одной транзакции: резервирование поколения + кандидат + задача
        $this->db->transaction(function () use ($shop, $insalesId, $candidateId, $encryptedPassword) {
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

            // Ставим задачу с store_id, generation и candidate_id для корректной
            // отмены при uninstall и проверки актуальности
            $this->queue->push('verify_install', [
                'candidate_id' => $candidateId,
                'store_id' => $insalesId,
                'generation' => $newGeneration,
            ], $insalesId);
        });

        return $candidateId;
    }

    /**
     * Verify install candidate via InSales API.
     * Called from worker process.
     */
    public function verifyCandidate(string $candidateId): void
    {
        $candidate = $this->db->fetch(
            'SELECT c.*, ig.status as gen_status, ig.generation as gen_generation
             FROM install_candidates c
             LEFT JOIN installation_generations ig ON ig.store_id = c.store_id
                                                  AND ig.candidate_id = c.id
             WHERE c.id = :id',
            ['id' => $candidateId],
        );

        if ($candidate === null) {
            return; // Already removed (e.g., uninstall happened)
        }

        $storeId = (string) $candidate['store_id'];
        $shop = (string) $candidate['shop'];
        $password = $this->encryption->decrypt((string) $candidate['encrypted_password']);

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
            $this->db->transaction(function () use ($candidateId, $candidate, $storeId, $shop, $password, $account) {
                // Re-check that candidate still exists
                $current = $this->db->fetch(
                    'SELECT c.id, c.candidate_version, ig.status as gen_status, ig.generation as gen_generation
                     FROM install_candidates c
                     LEFT JOIN installation_generations ig ON ig.store_id = c.store_id
                                                          AND ig.candidate_id = c.id
                     WHERE c.id = :id',
                    ['id' => $candidateId],
                );

                if ($current === null) {
                    return; // Already removed
                }

                $myVersion = (int) $candidate['candidate_version'];

                // Проверяем, что наш candidate — актуальный (максимальная версия)
                $maxVersion = (int) ($this->db->fetch(
                    'SELECT MAX(candidate_version) as v FROM install_candidates WHERE store_id = :store_id',
                    ['store_id' => $storeId],
                )['v'] ?? 0);

                if ($myVersion < $maxVersion) {
                    // Есть более новый кандидат — наш устарел
                    $this->cleanupCandidate($candidateId, $storeId);
                    return;
                }

                // Проверяем статус поколения: если cancelled или expired — не активируем
                $genStatus = (string) ($candidate['gen_status'] ?? '');
                if ($genStatus === 'cancelled' || $genStatus === 'expired') {
                    $this->cleanupCandidate($candidateId, $storeId);
                    return;
                }

                $generation = (int) ($this->db->fetch(
                    'SELECT COALESCE(MAX(generation), 0) as g FROM stores WHERE store_id = :store_id',
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

                // Отмечаем поколение как активное
                $this->db->execute(
                    'UPDATE installation_generations SET status = :active, activated_at = :now
                     WHERE store_id = :store_id AND candidate_id = :candidate_id',
                    [
                        'active' => 'active',
                        'now' => time(),
                        'store_id' => $storeId,
                        'candidate_id' => $candidateId,
                    ],
                );

                // Отменяем другие зарезервированные поколения
                $this->db->execute(
                    'UPDATE installation_generations SET status = :cancelled
                     WHERE store_id = :store_id AND status = :reserved',
                    [
                        'cancelled' => 'cancelled',
                        'store_id' => $storeId,
                        'reserved' => 'reserved',
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

                // Remove the candidate and its generation link
                $this->cleanupCandidate($candidateId, $storeId);
            });
        } catch (\Throwable $e) {
            // Transient error — candidate stays in queue for retry
            // Permanent auth errors — reject candidate
            if ($e instanceof \InSales\ApiException && !$e->isTemporary()) {
                $this->cleanupCandidate($candidateId, $storeId);
            }
            throw $e; // Re-throw for queue retry logic
        }
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
     */
    private function cleanupCandidate(string $candidateId, string $storeId): void
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