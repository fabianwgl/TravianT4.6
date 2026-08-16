<?php

declare(strict_types=1);

use Model\AccountDeleter;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

if ($argc !== 4 || (int)$argv[1] <= 0 || $argv[2] === '' || $argv[3] === '') {
    fwrite(STDERR, "Usage: php village-delete-worker.php <village-id> <barrier-file> <ready-file>\n");
    exit(2);
}

try {
    if (file_put_contents($argv[3], 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to signal village-delete worker readiness.');
    }
    $deadline = microtime(true) + 10;
    while (@file_get_contents($argv[2]) !== 'go') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the village-delete start barrier.');
        }
        usleep(1000);
    }

    $deleted = (new AccountDeleter())->deleteVillage((int)$argv[1], false);
    fwrite(STDOUT, $deleted ? 'true' : 'false');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
