<?php

declare(strict_types=1);

function migration_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function run_schema_migrations(): void
{
    $database = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            migration_env('GAME_DB_HOST', 'database'),
            migration_env('GAME_DB_NAME', 'openvillage_game')
        ),
        migration_env('GAME_DB_USER', 'openvillage'),
        migration_env('GAME_DB_PASSWORD', 'local-game-password'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $database->exec(
        'CREATE TABLE IF NOT EXISTS openvillage_schema_migrations ('
        . 'version VARCHAR(191) NOT NULL PRIMARY KEY, '
        . 'checksum CHAR(64) NOT NULL, '
        . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if ((int)$database->query("SELECT GET_LOCK('openvillage_schema_migrations', 30)")->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the schema migration lock.');
    }

    try {
        $directory = dirname(__DIR__, 2) . '/include/schema/migrations';
        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $lookup = $database->prepare(
            'SELECT checksum FROM openvillage_schema_migrations WHERE version = :version'
        );
        $record = $database->prepare(
            'INSERT INTO openvillage_schema_migrations (version, checksum) VALUES (:version, :checksum)'
        );

        foreach ($files as $file) {
            $version = basename($file);
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Could not read migration ' . $version . '.');
            }
            $checksum = hash('sha256', $sql);
            $lookup->execute(['version' => $version]);
            $existing = $lookup->fetchColumn();
            if ($existing !== false) {
                if (!hash_equals((string)$existing, $checksum)) {
                    throw new RuntimeException('Applied migration checksum changed: ' . $version);
                }
                continue;
            }

            $database->exec($sql);
            $record->execute(['version' => $version, 'checksum' => $checksum]);
            echo 'Applied migration ' . $version . PHP_EOL;
        }
    } finally {
        $database->query("SELECT RELEASE_LOCK('openvillage_schema_migrations')");
    }
}
