<?php

declare(strict_types=1);

namespace Application;

use InSales\Protocol;
use InSales\ShopValidator;
use Infrastructure\Database;

/**
 * Service handling the employee login flow:
 * 1. Initiate login: generate nonce, redirect to InSales
 * 2. Handle autologin: verify token3, create session
 *
 * Security:
 * - Nonce is single-use, bound to store, browser (via cookie), and TTL
 * - Initial /login parameters do NOT create an authenticated session
 * - token3 verified with hash_equals (constant-time)
 * - Session ID is generated cryptographically (random)
 * - Challenge consumed once; replay prevented
 * - Browser cookie tied to nonce prevents cross-browser reuse
 */
final readonly class LoginService
{
    private const NONCE_TTL = 300; // 5 minutes
    private const SESSION_TTL = 3600; // 1 hour

    public function __construct(
        private Database $db,
        private string $appId,
        private string $baseUrl,
    ) {}

    /**
     * Initiate login: generate nonce bound to store + browser.
     *
     * @param string $storeId Store numeric ID
     * @param string $shop Normalized shop domain
     * @param string $browserId Browser identifier (from session cookie or similar)
     * @return array{nonce: string, redirectUrl: string, sessionId: string}
     */
    public function initiateLogin(string $storeId, string $shop, string $browserId): array
    {
        $store = $this->getStore($storeId, $shop);

        $nonce = bin2hex(random_bytes(32));
        $sessionId = bin2hex(random_bytes(32));

        // Clean expired challenges
        $this->db->execute(
            'DELETE FROM pending_logins WHERE expires_at < :now',
            ['now' => time()],
        );

        $this->db->execute(
            'INSERT INTO pending_logins (session_id, store_id, nonce, browser_id, expires_at, created_at)
             VALUES (:session_id, :store_id, :nonce, :browser_id, :expires_at, :created_at)',
            [
                'session_id' => $sessionId,
                'store_id' => $store['store_id'],
                'nonce' => $nonce,
                'browser_id' => $browserId,
                'expires_at' => time() + self::NONCE_TTL,
                'created_at' => time(),
            ],
        );

        $redirectUrl = sprintf(
            'https://%s/admin/applications/%s/login?token=%s&login=%s',
            $shop,
            rawurlencode($this->appId),
            rawurlencode($nonce),
            rawurlencode($this->baseUrl . '/autologin'),
        );

        return [
            'nonce' => $nonce,
            'redirectUrl' => $redirectUrl,
            'sessionId' => $sessionId,
        ];
    }

    /**
     * Handle autologin callback from InSales.
     *
     * @param string $sessionId Pending session ID (from cookie)
     * @param array $params Callback parameters (token3, user_email, user_name, user_id, email_confirmed)
     * @return array{authToken: string, storeId: string} Authenticated session token
     * @throws LoginException
     */
    public function handleAutologin(string $sessionId, array $params): array
    {
        // Atomically consume the challenge
        $pending = $this->db->fetch(
            'DELETE FROM pending_logins WHERE session_id = :sid RETURNING *',
            ['sid' => $sessionId],
        );

        if ($pending === null) {
            throw new LoginException('Login challenge not found or already used');
        }

        if ($pending['expires_at'] < time()) {
            throw new LoginException('Login challenge expired');
        }

        $store = $this->getStore($pending['store_id']);

        $expectedToken3 = Protocol::token3(
            $pending['nonce'],
            $params['user_email'] ?? '',
            $params['user_name'] ?? '',
            $params['user_id'] ?? '',
            $params['email_confirmed'] ?? 'false',
            $store['password'],
        );

        $signature = $params['token3'] ?? '';

        if (!preg_match('/^[a-f0-9]{32}$/', $signature)) {
            throw new LoginException('Invalid token3 format');
        }

        if (!hash_equals($expectedToken3, $signature)) {
            throw new LoginException('Invalid token3 signature');
        }

        // Create authenticated session
        $authToken = bin2hex(random_bytes(32));

        // Clean expired sessions
        $this->db->execute(
            'DELETE FROM sessions WHERE expires_at < :now',
            ['now' => time()],
        );

        $this->db->execute(
            'INSERT INTO sessions (id, store_id, user_id, generation, expires_at, created_at)
             VALUES (:id, :store_id, :user_id, :generation, :expires_at, :created_at)',
            [
                'id' => $authToken,
                'store_id' => $store['store_id'],
                'user_id' => $params['user_id'] ?? '0',
                'generation' => $store['generation'],
                'expires_at' => time() + self::SESSION_TTL,
                'created_at' => time(),
            ],
        );

        return [
            'authToken' => $authToken,
            'storeId' => $store['store_id'],
        ];
    }

    /**
     * Validate an active session.
     *
     * @return array|null Session data or null if invalid/expired
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
        if ((int) $session['generation'] !== (int) ($session['store_generation'] ?? 0)) {
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

        return $store;
    }
}