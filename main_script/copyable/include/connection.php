<?php
global $connection;

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
};

$connection = [
    'speed' => (int)$env('GAME_SPEED', '10'),
    'round_length' => (int)$env('GAME_ROUND_LENGTH_DAYS', '35'),
    'worldId' => $env('GAME_WORLD_ID', 'local'),
    'secure_hash_code' => hash('sha256', $env('APP_SECRET', 'local-development-only')),
    'title' => $env('GAME_TITLE', 'OpenVillage 4.6'),
    'gameWorldUrl' => rtrim($env('GAME_WORLD_URL', 'http://127.0.0.1:8080/game/'), '/') . '/',
    'serverName' => $env('GAME_SERVER_NAME', 'Local World'),
    'auto_reinstall' => false,
    'auto_reinstall_start_after' => 0,
    'engine_filename' => 'automation',
    'database' => [
        'hostname' => $env('GAME_DB_HOST', 'database'),
        'username' => $env('GAME_DB_USER', 'openvillage'),
        'password' => $env('GAME_DB_PASSWORD', 'local-game-password'),
        'database' => $env('GAME_DB_NAME', 'openvillage_game'),
        'charset' => 'utf8mb4',
    ],
];
