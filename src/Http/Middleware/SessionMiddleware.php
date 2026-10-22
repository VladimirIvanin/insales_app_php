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
 * On failure, the request proceeds without attributes (controllers handle auth).
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public const COOKIE_NAME = 'insales_app_session';
    private const COOKIE_TTL = 3600;

    public function __construct(
        private LoginService $loginService,
        private string $baseUrl,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $authToken = $cookies[self::COOKIE_NAME] ?? '';

        if ($authToken !== '' && preg_match('/^[a-f0-9]{64}$/', $authToken)) {
            $session = $this->loginService->validateSession($authToken);
            if ($session !== null) {
                $request = $request->withAttribute('session', $session)
                    ->withAttribute('store', $session);
            }
        }

        $response = $handler->handle($request);

        // Renew cookie on active session
        if ($authToken !== '' && $request->getAttribute('session') !== null) {
            $response = $this->setSessionCookie($response, $authToken);
        }

        return $response;
    }

    /**
     * Set session cookie on response.
     */
    public function setSessionCookie(ResponseInterface $response, string $sessionId): ResponseInterface
    {
        $secure = str_starts_with($this->baseUrl, 'https://');
        $sameSite = 'Lax';

        $cookieValue = rawurlencode(self::COOKIE_NAME) . '=' . rawurlencode($sessionId)
            . '; HttpOnly'
            . '; SameSite=' . $sameSite
            . '; Path=/'
            . '; Max-Age=' . self::COOKIE_TTL;

        if ($secure) {
            $cookieValue .= '; Secure';
        }

        return $response->withHeader('Set-Cookie', $cookieValue);
    }

    /**
     * Clear session cookie.
     */
    public function clearSessionCookie(ResponseInterface $response): ResponseInterface
    {
        $cookieValue = rawurlencode(self::COOKIE_NAME) . '=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0';

        return $response->withHeader('Set-Cookie', $cookieValue);
    }
}