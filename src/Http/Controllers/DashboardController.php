<?php

declare(strict_types=1);

namespace Http\Controllers;

use InSales\ApiClient;
use InSales\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DashboardController
{
    public function __construct(
        private string $appId,
        private bool $allowHttp,
        private ?\Infrastructure\Database $db = null,
        private ?\Infrastructure\Encryption $encryption = null,
        private ?\Psr\Log\LoggerInterface $logger = null,
        private ?string $platformBaseUrl = null,
    ) {
    }

    /**
     * GET / — main dashboard (requires authenticated session).
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $request->getAttribute('session');

        $title = htmlspecialchars((string) ($session['title'] ?? 'Store'), ENT_QUOTES, 'UTF-8');
        $shop = htmlspecialchars((string) ($session['shop'] ?? ''), ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Partner App</title>
    <style>
        body { font: 18px system-ui, -apple-system, sans-serif; max-width: 720px; margin: 60px auto; padding: 24px; background: #f4f7fb; color: #192a40; }
        main { background: white; border-radius: 16px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        h1 { margin-top: 0; color: #0a1a2a; }
        .info { margin: 16px 0; }
        .label { color: #667; font-size: 14px; }
        .value { font-weight: 500; }
        a { color: #165dcc; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .nav { margin-top: 24px; padding-top: 16px; border-top: 1px solid #e0e6ed; }
        button, .btn { display: inline-block; padding: 8px 20px; border-radius: 8px; border: none; cursor: pointer; font-size: 16px; }
        .btn-primary { background: #165dcc; color: white; }
        .btn-primary:hover { background: #134db3; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .status { padding: 8px 16px; border-radius: 8px; margin: 8px 0; }
        .status-ok { background: #d4edda; color: #155724; }
        .status-error { background: #f8d7da; color: #721c24; }
        .webhook-section { margin-top: 16px; padding: 16px; background: #f8f9fa; border-radius: 8px; }
        .webhook-section h3 { margin-top: 0; }
    </style>
</head>
<body>
    <main>
        <h1>Панель партнёрского приложения</h1>
        <div class="info">
            <div class="label">Магазин</div>
            <div class="value">{$title}</div>
        </div>
        <div class="info">
            <div class="label">Домен</div>
            <div class="value">{$shop}</div>
        </div>
        <div class="info">
            <div class="label">Статус</div>
            <div class="status status-ok">Установка подтверждена. Сессия активна.</div>
        </div>
        <div class="nav">
            <a href="/probe" class="btn btn-primary">Проверить API магазина</a>
            <form method="post" action="/logout" style="display:inline">
                <button type="submit" class="btn btn-danger">Выйти</button>
            </form>
        </div>
        <div class="webhook-section">
            <h3>Расширение приложения</h3>
            <p>Для добавления обработчиков вебхуков создайте контроллер в <code>src/Http/Controllers/</code>
               и зарегистрируйте маршрут в <code>public/index.php</code>.</p>
            <p>Для фоновых задач используйте <code>Infrastructure\\JobQueue</code> и worker:
               <code>php bin/worker.php</code>.</p>
        </div>
    </main>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * GET /probe — test API connectivity (requires authenticated session).
     * Выполняет реальный API-запрос account.read к InSales.
     */
    public function probe(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $request->getAttribute('session');

        $shop = (string) ($session['shop'] ?? '');
        $storeId = (string) ($session['store_id'] ?? '');

        if ($shop === '' || $storeId === '') {
            return $this->renderHtml($response, 'Session data incomplete', 400);
        }

        try {
            if ($this->db === null || $this->encryption === null) {
                return $this->renderHtml($response, 'Probe service not configured', 500);
            }

            $store = $this->db->fetch(
                'SELECT * FROM stores WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $storeId, 'shop' => $shop],
            );

            if ($store === null) {
                return $this->renderHtml($response, 'Store not found in database', 404);
            }

            $password = $this->encryption->decrypt($store['encrypted_password']);

            $client = new ApiClient(
                appId: $this->appId,
                shop: $shop,
                password: $password,
                allowHttp: $this->allowHttp,
                logger: $this->logger ?? new \Psr\Log\NullLogger(),
                platformBaseUrl: $this->platformBaseUrl,
            );

            $account = $client->getAccount();

            $accountId = htmlspecialchars((string) ($account['id'] ?? '?'), ENT_QUOTES, 'UTF-8');
            $accountTitle = htmlspecialchars((string) ($account['title'] ?? '?'), ENT_QUOTES, 'UTF-8');

            $html = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>API Probe — успех</title>
    <style>
        body { font: 18px system-ui; max-width: 720px; margin: 60px auto; padding: 24px; background: #f4f7fb; color: #192a40; }
        main { background: white; border-radius: 16px; padding: 32px; }
        pre { background: #f0f2f5; padding: 16px; border-radius: 8px; overflow-x: auto; }
        .success { background: #d4edda; color: #155724; padding: 12px 20px; border-radius: 8px; margin: 16px 0; }
        .info-row { margin: 8px 0; }
        .label { color: #667; font-size: 14px; }
    </style>
</head>
<body>
    <main>
        <h1>Проверка API магазина</h1>
        <div class="success">✓ API-запрос выполнен успешно</div>
        <div class="info-row"><span class="label">Магазин:</span> {$shop}</div>
        <div class="info-row"><span class="label">ID аккаунта:</span> {$accountId}</div>
        <div class="info-row"><span class="label">Название:</span> {$accountTitle}</div>
        <p><a href="/">Вернуться в приложение</a></p>
    </main>
</body>
</html>
HTML;
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (\InSales\ApiException $e) {
            $this->logger?->error('API probe failed', ['shop' => $shop, 'error' => $e->getMessage()]);
            return $this->renderHtml($response, 'Ошибка API: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 502);
        } catch (\Throwable $e) {
            $this->logger?->error('API probe error', ['shop' => $shop, 'error' => $e->getMessage()]);
            return $this->renderHtml($response, 'Ошибка: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 502);
        }
    }

    /**
     * GET /health — simple health check.
     */
    public function health(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('ok');
        return $response->withStatus(200);
    }

    private function renderHtml(ResponseInterface $response, string $message, int $status = 200): ResponseInterface
    {
        $escaped = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><title>Partner App</title>
<style>body{font:18px system-ui;max-width:720px;margin:60px auto;padding:24px;background:#f4f7fb;color:#192a40}main{background:white;border-radius:16px;padding:32px}</style>
</head>
<body><main><p>{$escaped}</p><a href="/">На главную</a></main></body>
</html>
HTML;
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withStatus($status);
    }
}
