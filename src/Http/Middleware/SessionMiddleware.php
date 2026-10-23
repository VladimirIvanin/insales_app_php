<?php

declare(strict_types=1);

namespace Http\Middleware;

use Application\LoginService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that validates the authentication session cookie.
 *
 * On success, sets 'store' and 'session' attributes on the request.
 * On failure, returns 401.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public const COOKIE_NAME = 'insales_app_session';
    public const CHALLENGE_COOKIE = 'insales_app_challenge';
    private const COOKIE_TTL = 3600;
    private const CHALLENGE_TTL = 300; // 5 min, matches LoginService::NONCE_TTL

    public function __construct(
        private LoginService $loginService,
        private string $baseUrl,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $authToken = $cookies[self::COOKIE_NAME] ?? '';

        if ($authToken === '' || preg_match('/^[a-f0-9]{64}$/', $authToken) !== 1) {
            return $this->unauthorized($request);
        }

        $session = $this->loginService->validateSession($authToken);
        if ($session === null) {
            return $this->unauthorized($request);
        }

        $expected = $request->getQueryParams()['store_id'] ?? null;
        if ($expected !== null && (!is_string($expected) || $expected !== $session['store_id'])) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write('Другая вкладка сменила магазин. Откройте приложение из нужного магазина.');
            return $response->withStatus(409)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $request = $request->withAttribute('session', $session)
            ->withAttribute('store', $session);

        $response = $handler->handle($request);

        // Renew cookie on active session
        $response = $this->setSessionCookie($response, $authToken);

        return $response;
    }

    /**
     * Return 401 Unauthorized response.
     */
    private function unauthorized(ServerRequestInterface $request): ResponseInterface
    {
        $response = new \Slim\Psr7\Response();
         $response->getBody()->write(
             '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Требуется вход</title>'
             . '<main><h1>Требуется вход</h1><p>Откройте приложение из раздела расширений InSales.</p></main></html>'
         );
        return $response->withStatus(401)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Set session cookie on response.
     */
    public function setSessionCookie(ResponseInterface $response, string $sessionId): ResponseInterface
    {
        return $this->setCookie($response, self::COOKIE_NAME, $sessionId, self::COOKIE_TTL);
    }

    /**
     * Set challenge cookie on response (for login flow).
     */
    public function setChallengeCookie(ResponseInterface $response, string $sessionId): ResponseInterface
    {
        return $this->setCookie($response, self::CHALLENGE_COOKIE, $sessionId, self::CHALLENGE_TTL);
    }

    public function setFlowCookie(ResponseInterface $response, string $flow, string $sessionId): ResponseInterface
    {
        return $this->setCookie($response, 'login_' . $flow, $sessionId, self::CHALLENGE_TTL);
    }

    public function clearFlowCookie(ResponseInterface $response, string $flow): ResponseInterface
    {
        return $this->clearCookie($response, 'login_' . $flow);
    }

    /**
     * Clear session cookie.
     */
    public function clearSessionCookie(ResponseInterface $response): ResponseInterface
    {
        return $this->clearCookie($response, self::COOKIE_NAME);
    }

    /**
     * Clear challenge cookie.
     */
    public function clearChallengeCookie(ResponseInterface $response): ResponseInterface
    {
        return $this->clearCookie($response, self::CHALLENGE_COOKIE);
    }

    /**
     * Combine clearing both challenge and session cookies.
     */
    public function clearAllCookies(ResponseInterface $response): ResponseInterface
    {
        $response = $this->clearCookie($response, self::COOKIE_NAME);
        return $this->clearCookie($response, self::CHALLENGE_COOKIE);
    }

    /**
     * Set a cookie with given name, value and TTL.
     */
    private function setCookie(ResponseInterface $response, string $name, string $value, int $ttl): ResponseInterface
    {
        $secure = str_starts_with($this->baseUrl, 'https://');
        $cookieValue = rawurlencode($name) . '=' . rawurlencode($value)
            . '; HttpOnly'
            . '; SameSite=Lax'
            . '; Path=/'
            . '; Max-Age=' . $ttl;

        if ($secure) {
            $cookieValue .= '; Secure';
        }

        return $response->withHeader('Set-Cookie', $cookieValue);
    }

    /**
     * Clear a cookie by name.
     */
    private function clearCookie(ResponseInterface $response, string $name): ResponseInterface
    {
        $cookieValue = rawurlencode($name) . '=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0';
        return $response->withHeader('Set-Cookie', $cookieValue);
    }
}
