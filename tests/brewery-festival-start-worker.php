<?php

declare(strict_types=1);

use Model\BreweryModel;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

if (
    $argc !== 5
    || (int)$argv[1] <= 0
    || (int)$argv[2] <= 0
    || $argv[3] === ''
    || $argv[4] === ''
) {
    fwrite(STDERR, "Usage: php brewery-festival-start-worker.php <uid> <village-id> <barrier-file> <ready-file>\n");
    exit(2);
}

try {
    if (file_put_contents($argv[4], 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to signal Brewery festival worker readiness.');
    }
    $deadline = microtime(true) + 10;
    while (@file_get_contents($argv[3]) !== 'go') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the Brewery festival start barrier.');
        }
        usleep(1000);
    }

    $started = (new BreweryModel())->startFestival((int)$argv[1], (int)$argv[2]);
    fwrite(STDOUT, $started ? 'true' : 'false');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
