<?php

declare(strict_types=1);

use Model\VillageModel;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

if ($argc !== 5 || (int)$argv[1] <= 0 || (int)$argv[2] <= 0 || $argv[3] === '' || $argv[4] === '') {
    fwrite(STDERR, "Usage: php capital-change-worker.php <uid> <village-id> <barrier-file> <ready-file>\n");
    exit(2);
}

try {
    if (file_put_contents($argv[4], 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to signal capital-change worker readiness.');
    }
    $deadline = microtime(true) + 10;
    while (@file_get_contents($argv[3]) !== 'go') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the capital-change start barrier.');
        }
        usleep(1000);
    }

    $changed = (new VillageModel())->changeCapital((int)$argv[1], (int)$argv[2]);
    fwrite(STDOUT, $changed ? 'true' : 'false');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
