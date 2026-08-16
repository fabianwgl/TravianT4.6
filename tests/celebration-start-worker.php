<?php

declare(strict_types=1);

use Model\CelebrationModel;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

if (
    $argc !== 6
    || (int)$argv[1] <= 0
    || (int)$argv[2] <= 0
    || !in_array((int)$argv[3], [1, 2], true)
    || $argv[4] === ''
    || $argv[5] === ''
) {
    fwrite(STDERR, "Usage: php celebration-start-worker.php <uid> <village-id> <type> <barrier-file> <ready-file>\n");
    exit(2);
}

try {
    if (file_put_contents($argv[5], 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to signal celebration worker readiness.');
    }
    $deadline = microtime(true) + 10;
    while (@file_get_contents($argv[4]) !== 'go') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the celebration start barrier.');
        }
        usleep(1000);
    }

    $started = (new CelebrationModel())->startCelebration(
        (int)$argv[1],
        (int)$argv[2],
        (int)$argv[3]
    );
    fwrite(STDOUT, $started ? 'true' : 'false');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
