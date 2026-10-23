<?php

declare(strict_types=1);

namespace Http\Controllers;

use Application\LoginException;
use Application\LoginService;
use Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class LoginController
{
    public function __construct(
        private LoginService $loginService,
        private SessionMiddleware $sessionMiddleware,
        private string $baseUrl,
    ) {
    }

    /**
     * Handle GET /login — initiate employee login via InSales.
     *
     * Parameters: shop, insales_id
     * Does NOT create an authenticated session based on these params alone.
     * Sets a challenge cookie (separate from the authenticated session cookie).
     */
    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $shop = $params['shop'] ?? '';
        $insalesId = $params['insales_id'] ?? '';

        if ($shop === '' || $insalesId === '') {
            return $this->plain($response, 'Missing shop or insales_id', 400);
        }

        try {
            $result = $this->loginService->initiateLogin($insalesId, $shop);

            // Set the challenge cookie (separate from session)
            $response = $this->sessionMiddleware->setChallengeCookie($response, $result['sessionId']);
            $response = $this->sessionMiddleware->setFlowCookie($response, $result['nonce'], $result['sessionId']);

            return $response
                ->withStatus(302)
                ->withHeader('Location', $result['redirectUrl']);
        } catch (LoginException | \InvalidArgumentException) {
            return $this->plain($response, 'Login verification failed', 400);
        } catch (\RuntimeException) {
            return $this->plain($response, 'Service temporarily unavailable', 503);
        } catch (\Throwable) {
            return $this->plain($response, 'Internal error', 500);
        }
    }

    /**
     * Handle GET /autologin — verify token3 and create authenticated session.
     *
     * Reads the challenge cookie, verifies token3, then exchanges
     * challenge for an authenticated session cookie.
     * On success, clears the challenge cookie.
     */
    public function autologin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $flow = $request->getQueryParams()['flow'] ?? '';
        if (!is_string($flow) || ($flow !== '' && preg_match('/^[a-f0-9]{64}$/', $flow) !== 1)) {
            return $this->plain($response, 'Unauthorized', 401);
        }
        $sessionId = $cookies[$flow !== '' ? 'login_' . $flow : SessionMiddleware::CHALLENGE_COOKIE] ?? '';

        if ($sessionId === '' || preg_match('/^[a-f0-9]{64}$/', $sessionId) !== 1) {
            return $this->plain($response, 'Unauthorized', 401);
        }

        $params = $request->getQueryParams();

        try {
            $result = $this->loginService->handleAutologin($sessionId, $params);

            // Clear challenge, set authenticated session
            $response = $flow !== '' ? $this->sessionMiddleware->clearFlowCookie($response, $flow)
                : $this->sessionMiddleware->clearChallengeCookie($response);
            $response = $this->sessionMiddleware->setSessionCookie($response, $result['authToken']);

            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->baseUrl . '/?store_id=' . rawurlencode($result['storeId']));
        } catch (LoginException) {
            $response = $flow !== '' ? $this->sessionMiddleware->clearFlowCookie($response, $flow)
                : $this->sessionMiddleware->clearChallengeCookie($response);
            return $this->plain($response, 'Login failed', 401);
        } catch (\Throwable) {
            return $this->plain($response, 'Internal error', 500);
        }
    }

    /**
     * Handle POST /logout — destroy session and clear all cookies.
     */
    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $authToken = $cookies[SessionMiddleware::COOKIE_NAME] ?? '';

        if ($authToken !== '' && preg_match('/^[a-f0-9]{64}$/', $authToken) === 1) {
            $this->loginService->destroySession($authToken);
        }

        $response = $this->sessionMiddleware->clearAllCookies($response);
        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->baseUrl . '/');
    }

    private function plain(ResponseInterface $response, string $body, int $status): ResponseInterface
    {
        $response->getBody()->write($body);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
