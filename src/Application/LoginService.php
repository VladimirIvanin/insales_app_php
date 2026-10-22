<?php

declare(strict_types=1);

namespace Application;

use InSales\Protocol;
use InSales\ShopValidator;
use Infrastructure\Database;
use Infrastructure\Encryption;

/**
 * Service handling the employee login flow:
 * 1. Prepare store installation (verify candidate if needed)
 * 2. Initiate login: generate nonce bound to store + generation, redirect to InSales
 * 3. Handle autologin: verify token3 with generation check, create session
 *
 * Security:
 * - Nonce is single-use, bound to store, generation and TTL
 * - Initial /login parameters do NOT create an authenticated session
 * - token3 verified with hash_equals (constant-time)
 * - Session ID is generated cryptographically (random)
 * - Challenge consumed in transaction with session creation; replay prevented
 */
final readonly class LoginService
{
    private const NONCE_TTL = 300; // 5 minutes
    private const SESSION_TTL = 3600; // 1 hour

    public function __construct(
        private Database $db,
        private Encryption $encryption,
        private InstallationService $installationService,
        private string $appId,
        private string $baseUrl,
        private ?string $platformBaseUrl = null,
    ) {
    }

    /**
     * Initiate login: prepare store install, then generate nonce bound to store + generation.
     *
     * @param string $storeId Store numeric ID
     * @param string $shop Normalized shop domain
     * @return array{nonce: string, redirectUrl: string, sessionId: string, generation: int}
     *
     * @throws \RuntimeException On temporary API error (caller shows 503)
     * @throws \InvalidArgumentException On permanent rejection
     * @throws LoginException If store not found
     */
    public function initiateLogin(string $storeId, string $shop): array
    {
        // 1. Prepare installation (verify + activate candidate if pending, or check active store)
        $storeData = $this->installationService->prepareAndActivate($shop, $storeId);

        if ($storeData === null) {
            throw new LoginException('Store is not installed');
        }

        $storeId = $storeData['store_id'];
        $generation = $storeData['generation'];
        $store = $this->getStore($storeId, $shop);
        $nonce = bin2hex(random_bytes(32));
        $sessionId = bin2hex(random_bytes(32));

        // Clean expired challenges
        $this->db->execute(
            'DELETE FROM pending_logins WHERE expires_at < :now',
            ['now' => time()],
        );

        $this->db->execute(
            'INSERT INTO pending_logins (session_id, store_id, nonce, generation, expires_at, created_at)
             VALUES (:session_id, :store_id, :nonce, :generation, :expires_at, :created_at)',
            [
                'session_id' => $sessionId,
                'store_id' => $store['store_id'],
                'nonce' => $nonce,
                'generation' => $generation,
                'expires_at' => time() + self::NONCE_TTL,
                'created_at' => time(),
            ],
        );

        $host = $this->platformBaseUrl ?? sprintf('https://%s', $shop);

        $redirectUrl = sprintf(
            '%s/admin/applications/%s/login?token=%s&login=%s&shop=%s',
            $host,
            rawurlencode($this->appId),
            rawurlencode($nonce),
            rawurlencode($this->baseUrl . '/autologin'),
            rawurlencode($shop),
        );

        return [
            'nonce' => $nonce,
            'redirectUrl' => $redirectUrl,
            'sessionId' => $sessionId,
            'generation' => $generation,
        ];
    }

    /**
     * Handle autologin callback from InSales.
     *
     * @param array<string, mixed> $params
     * @return array{authToken: string, storeId: string} Authenticated session token
     * @throws LoginException
     */
    public function handleAutologin(string $sessionId, array $params): array
    {
        $authToken = bin2hex(random_bytes(32));
        $resultStoreId = null;

        // Всю проверку и потребление — в одной транзакции для атомарности
        $this->db->transaction(function () use ($sessionId, $params, $authToken, &$resultStoreId) {
            // Читаем challenge внутри транзакции
            $pending = $this->db->fetch(
                'SELECT * FROM pending_logins WHERE session_id = :sid',
                ['sid' => $sessionId],
            );

            if ($pending === null) {
                throw new LoginException('Login challenge not found or already used');
            }

            if ((int) $pending['expires_at'] < time()) {
                throw new LoginException('Login challenge expired');
            }

            $store = $this->getStore((string) $pending['store_id']);

            // Проверяем соответствие поколения: challenge связан с поколением
            $challengeGeneration = (int) ($pending['generation'] ?? 0);
            $storeGeneration = (int) $store['generation'];
            if ($challengeGeneration > 0 && $challengeGeneration !== $storeGeneration) {
                // Challenge от старого поколения — не принимаем
                $this->db->execute('DELETE FROM pending_logins WHERE session_id = :sid', ['sid' => $sessionId]);
                throw new LoginException('Login challenge generation mismatch');
            }

            $expectedToken3 = Protocol::token3(
                (string) $pending['nonce'],
                (string) ($params['user_email'] ?? ''),
                (string) ($params['user_name'] ?? ''),
                (string) ($params['user_id'] ?? ''),
                (string) ($params['email_confirmed'] ?? 'false'),
                (string) $store['password'],
            );

            $signature = $params['token3'] ?? '';

            if (!preg_match('/^[a-f0-9]{32}$/', $signature)) {
                throw new LoginException('Invalid token3 format');
            }

            if (!hash_equals($expectedToken3, $signature)) {
                throw new LoginException('Invalid token3 signature');
            }

            // Потребляем challenge (атомарно: проверяем, что ровно одна строка удалена)
            $deleted = $this->db->execute(
                'DELETE FROM pending_logins WHERE session_id = :sid',
                ['sid' => $sessionId],
            );

            if ($deleted !== 1) {
                // Другой запрос уже потребил этот challenge
                throw new LoginException('Login challenge not found or already used');
            }

            // Очищаем expired сессии
            $this->db->execute(
                'DELETE FROM sessions WHERE expires_at < :now',
                ['now' => time()],
            );

            // Создаём сессию
            $this->db->execute(
                'INSERT INTO sessions (id, store_id, user_id, generation, expires_at, created_at)
                 VALUES (:id, :store_id, :user_id, :generation, :expires_at, :created_at)',
                [
                    'id' => $authToken,
                    'store_id' => $store['store_id'],
                    'user_id' => (string) ($params['user_id'] ?? '0'),
                    'generation' => $store['generation'],
                    'expires_at' => time() + self::SESSION_TTL,
                    'created_at' => time(),
                ],
            );

            $resultStoreId = $store['store_id'];
        });

        if ($resultStoreId === null) {
            throw new LoginException('Login challenge not found or already used');
        }

        return [
            'authToken' => $authToken,
            'storeId' => $resultStoreId,
        ];
    }

    /**
     * Validate an active session.
     *
     * @return array<string, mixed>|null Session data or null if invalid/expired
     */
    public function validateSession(string $authToken): ?array
    {
        $session = $this->db->fetch(
            'SELECT s.*, st.shop, st.title, st.generation as store_generation
             FROM sessions s
             JOIN stores st ON s.store_id = st.store_id
             WHERE s.id = :id AND s.expires_at > :now',
            ['id' => $authToken, 'now' => time()],
        );

        if ($session === null) {
            return null;
        }

        // Check that session generation matches store generation
        $generation = isset($session['generation']) ? (int) $session['generation'] : 0;
        $storeGeneration = isset($session['store_generation']) ? (int) $session['store_generation'] : 0;
        if ($generation !== $storeGeneration) {
            $this->db->execute('DELETE FROM sessions WHERE id = :id', ['id' => $authToken]);
            return null;
        }

        return $session;
    }

    /**
     * Destroy a session (logout).
     */
    public function destroySession(string $authToken): void
    {
        $this->db->execute('DELETE FROM sessions WHERE id = :id', ['id' => $authToken]);
    }

    /**
     * Get store by ID and optionally verify shop.
     * Returns store data with decrypted password.
     *
     * @return array<string, mixed>
     */
    private function getStore(string $storeId, ?string $shop = null): array
    {
        if ($shop !== null) {
            $store = $this->db->fetch(
                'SELECT * FROM stores WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $storeId, 'shop' => $shop],
            );
        } else {
            $store = $this->db->fetch(
                'SELECT * FROM stores WHERE store_id = :store_id',
                ['store_id' => $storeId],
            );
        }

        if ($store === null) {
            throw new LoginException('Store is not installed');
        }

        $store['password'] = $this->encryption->decrypt((string) $store['encrypted_password']);

        return $store;
    }
}
