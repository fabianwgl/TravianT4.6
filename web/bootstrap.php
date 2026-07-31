<?php

declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure',
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
session_start();

function env_value(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function game_database(): PDO
{
    static $database;
    if ($database instanceof PDO) {
        return $database;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        env_value('GAME_DB_HOST', 'database'),
        env_value('GAME_DB_NAME', 'openvillage_game')
    );
    $database = new PDO(
        $dsn,
        env_value('GAME_DB_USER', 'openvillage'),
        env_value('GAME_DB_PASSWORD', 'local-game-password'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $database;
}

function csrf_token(): string
{
    if (empty($_SESSION['launcher_csrf'])) {
        $_SESSION['launcher_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['launcher_csrf'];
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
