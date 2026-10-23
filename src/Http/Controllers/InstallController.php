<?php

declare(strict_types=1);

namespace Http\Controllers;

use Application\InstallationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final readonly class InstallController
{
    public function __construct(
        private InstallationService $installationService,
    ) {
    }

    /**
     * Handle GET /install callback from InSales.
     *
     * Parameters: shop, token, insales_id
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $shop = $params['shop'] ?? '';
        $token = $params['token'] ?? '';
        $insalesId = $params['insales_id'] ?? '';

        try {
            $this->installationService->handleInstall($shop, $token, $insalesId);
            $response->getBody()->write('Installation received');
            return $response->withStatus(200)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write('Invalid request');
            return $response->withStatus(400)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (\Application\PendingQuotaException) {
            $response->getBody()->write('Installation verification unavailable; retry later');
            return $response->withStatus(503)->withHeader('Retry-After', '5');
        } catch (\Throwable $e) {
            $response->getBody()->write('Installation error');
            return $response->withStatus(500)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }
    }
}
