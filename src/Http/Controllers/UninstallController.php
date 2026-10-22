<?php

declare(strict_types=1);

namespace Http\Controllers;

use Application\UninstallService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class UninstallController
{
    public function __construct(
        private UninstallService $uninstallService,
    ) {
    }

    /**
     * Handle GET /uninstall callback from InSales.
     *
     * Parameters: shop, token (store password), insales_id
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $shop = $params['shop'] ?? '';
        $token = $params['token'] ?? '';
        $insalesId = $params['insales_id'] ?? '';

        try {
            $this->uninstallService->handleUninstall($shop, $token, $insalesId);
            $response->getBody()->write('Uninstalled');
            return $response->withStatus(200);
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write('Invalid uninstall request');
            return $response->withStatus(401);
        } catch (\Throwable $e) {
            $response->getBody()->write('Uninstall error');
            return $response->withStatus(500);
        }
    }
}
