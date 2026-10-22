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
    ) {}

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
            return $response->withStatus(200);
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write('Invalid request: ' . $e->getMessage());
            return $response->withStatus(400);
        } catch (\Throwable $e) {
            $response->getBody()->write('Installation error');
            return $response->withStatus(500);
        }
    }
}