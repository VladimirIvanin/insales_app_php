<?php

declare(strict_types=1);

namespace Http\Controllers;

use Application\LoginException;
use Application\LoginService;
use Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

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
            $response->getBody()->write('Missing shop or insales_id');
            return $response->withStatus(400);
        }

        try {
            $result = $this->loginService->initiateLogin($insalesId, $shop);

            // Set the challenge cookie (separate from session)
            $response = $this->sessionMiddleware->setChallengeCookie($response, $result['sessionId']);

            return $response
                ->withStatus(302)
                ->withHeader('Location', $result['redirectUrl']);
        } catch (LoginException | \InvalidArgumentException $e) {
            $response->getBody()->write('Login error: ' . $e->getMessage());
            return $response->withStatus(400);
        } catch (\RuntimeException $e) {
            // Temporary API error during install verification
            $response->getBody()->write('Service temporarily unavailable');
            return $response->withStatus(503);
        } catch (\Throwable $e) {
            $response->getBody()->write('Internal error');
            return $response->withStatus(500);
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
        $sessionId = $cookies[SessionMiddleware::CHALLENGE_COOKIE] ?? '';

        if ($sessionId === '' || !preg_match('/^[a-f0-9]{64}$/', $sessionId)) {
            $response->getBody()->write('Unauthorized');
            return $response->withStatus(401);
        }

        $params = $request->getQueryParams();

        try {
            $result = $this->loginService->handleAutologin($sessionId, $params);

            // Clear challenge, set authenticated session
            $response = $this->sessionMiddleware->clearChallengeCookie($response);
            $response = $this->sessionMiddleware->setSessionCookie($response, $result['authToken']);

            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->baseUrl . '/');
        } catch (LoginException $e) {
            $response = $this->sessionMiddleware->clearChallengeCookie($response);
            $response->getBody()->write('Login failed: ' . $e->getMessage());
            return $response->withStatus(401);
        } catch (\Throwable $e) {
            $response->getBody()->write('Internal error');
            return $response->withStatus(500);
        }
    }

    /**
     * Handle POST /logout — destroy session and clear all cookies.
     */
    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $authToken = $cookies[SessionMiddleware::COOKIE_NAME] ?? '';

        if ($authToken !== '' && preg_match('/^[a-f0-9]{64}$/', $authToken)) {
            $this->loginService->destroySession($authToken);
        }

        $response = $this->sessionMiddleware->clearAllCookies($response);
        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->baseUrl . '/');
    }
}
