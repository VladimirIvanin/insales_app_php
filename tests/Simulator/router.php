<?php

/**
 * Роутер для встроенного сервера PHP — симулятор платформы InSales.
 *
 * Переменные окружения:
 * - INSALES_SIM_STATE — путь к JSON-файлу состояния
 *
 * Протокол:
 * - GET /admin/account.json — Basic Auth, возвращает account
 * - GET /admin/applications/{appId}/login — редирект с token/token2/token3
 * - respondNext — очередь скриптованных ответов
 * - 404 на неизвестный путь
 * - Заголовок API-Usage-Limit
 */

declare(strict_types=1);

$stateFile = getenv('INSALES_SIM_STATE');
if ($stateFile === false || $stateFile === '') {
    http_response_code(500);
    echo json_encode(['error' => 'INSALES_SIM_STATE not set']);
    exit;
}

/**
 * Читает состояние из JSON-файла с эксклюзивной блокировкой.
 *
 * @return array<string, mixed>
 */
function sim_read_state(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        return [];
    }
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $state = $content !== false ? json_decode($content, true) : null;
    return is_array($state) ? $state : [];
}

/**
 * Пишет состояние в JSON-файл с эксклюзивной блокировкой.
 *
 * @param array<string, mixed> $state
 */
function sim_write_state(string $path, array $state): void
{
    $fp = fopen($path, 'cb');
    if ($fp === false) {
        return;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// --- Основная логика ---

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = $_GET;

$state = sim_read_state($stateFile);

// Rate limiting
$now = time();
$state['rate_limit_start'] ??= $now;
$state['request_count'] ??= 0;

if ($now - $state['rate_limit_start'] > 300) {
    $state['rate_limit_start'] = $now;
    $state['request_count'] = 0;
}

$state['request_count']++;
$usage = sprintf('%d/%d', $state['request_count'], $state['rate_limit_max'] ?? 500);

// Scripted responses
if (!empty($state['scripted_responses'])) {
    $scripted = $state['scripted_responses'][0];
    if ($scripted['method'] === $method && $scripted['path'] === $uri) {
        array_shift($state['scripted_responses']);
        sim_write_state($stateFile, $state);

        if (($scripted['delay'] ?? 0) > 0) {
            usleep((int) $scripted['delay'] * 1000);
        }
        if (!empty($scripted['disconnect'])) {
            http_response_code(500);
            exit;
        }

        http_response_code($scripted['status']);
        header('API-Usage-Limit: ' . $usage);
        if ($scripted['status'] === 429) {
            header('Retry-After: 10');
        }
        header('Content-Type: application/json');
        if ($scripted['body'] !== null) {
            echo json_encode($scripted['body']);
        }
        sim_write_state($stateFile, $state);
        exit;
    }
}

header('API-Usage-Limit: ' . $usage);

// GET /admin/account.json — проверка Basic Auth
if ($uri === '/admin/account.json' && $method === 'GET') {
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (!preg_match('/^Basic\s+(.+)$/i', $auth, $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'Authorization required']);
        sim_write_state($stateFile, $state);
        exit;
    }

    $decoded = base64_decode($m[1]);
    if ($decoded === false || !str_contains($decoded, ':')) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid authorization']);
        sim_write_state($stateFile, $state);
        exit;
    }

    [$username, $password] = explode(':', $decoded, 2);

    $store = null;
    foreach (($state['stores'] ?? []) as $shop => $info) {
        if ($info['password'] === $password) {
            $store = $info;
            $store['shop'] = $shop;
            break;
        }
    }

    if ($store === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid password']);
        sim_write_state($stateFile, $state);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'id' => (int) $store['id'],
        'title' => $store['title'],
        'shop' => $store['shop'],
    ]);
    sim_write_state($stateFile, $state);
    exit;
}

// GET /admin/applications/{appId}/login — редирект с подписями
if (preg_match('#^/admin/applications/([^/]+)/login$#', $uri, $m) && $method === 'GET') {
    $appId = $m[1];
    $nonce = $query['token'] ?? '';
    $loginUrl = $query['login'] ?? '';

    if ($nonce === '' || $loginUrl === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing token or login']);
        sim_write_state($stateFile, $state);
        exit;
    }

    $state['login_nonces'][$nonce] = ['app_id' => $appId, 'timestamp' => time()];
    sim_write_state($stateFile, $state);

    $password = '';
    foreach (($state['stores'] ?? []) as $shop => $info) {
        $password = $info['password'];
    }

    if ($password === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Store not found']);
        sim_write_state($stateFile, $state);
        exit;
    }

    $userEmail = $query['user_email'] ?? 'admin@example.com';
    $userName = $query['user_name'] ?? 'Test Admin';
    $userId = $query['user_id'] ?? '1';
    $emailConfirmed = $query['email_confirmed'] ?? 'true';

    $token3 = md5($nonce . $userEmail . $userName . $userId . $emailConfirmed . $password);

    $queryParts = [];
    $queryStr = parse_url($loginUrl, PHP_URL_QUERY);
    if (is_string($queryStr)) {
        parse_str($queryStr, $queryParts);
    }
    $separator = $queryParts !== [] ? '&' : '?';
    $redirectUrl = $loginUrl . $separator . http_build_query([
        'token' => md5($nonce . $password),
        'token2' => md5($nonce . $userEmail . $userName . $userId . $password),
        'token3' => $token3,
        'user_email' => $userEmail,
        'user_name' => $userName,
        'user_id' => $userId,
        'email_confirmed' => $emailConfirmed,
    ]);

    http_response_code(302);
    header('Location: ' . $redirectUrl);
    sim_write_state($stateFile, $state);
    exit;
}

// 404
http_response_code(404);
echo json_encode(['error' => 'Not found']);
sim_write_state($stateFile, $state);
