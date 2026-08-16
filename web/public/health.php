<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$checks = ['database' => false, 'redis' => false, 'installed' => false];

try {
    $checks['database'] = (int)game_database()->query('SELECT 1')->fetchColumn() === 1;
    $checks['installed'] = (int)game_database()->query('SELECT installed FROM config LIMIT 1')->fetchColumn() === 1;
} catch (Throwable $exception) {
    $checks['database'] = false;
}

try {
    $redis = new Redis();
    $redis->connect(env_value('REDIS_HOST', 'redis'), (int)env_value('REDIS_PORT', '6379'), 1.0);
    $checks['redis'] = $redis->ping() === true || $redis->ping() === '+PONG';
    $redis->close();
} catch (Throwable $exception) {
    $checks['redis'] = false;
}

$ready = !in_array(false, $checks, true);
http_response_code($ready ? 200 : 503);
echo json_encode(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks], JSON_THROW_ON_ERROR);
