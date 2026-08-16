<?php

declare(strict_types=1);

use Model\MovementsModel;
use Model\Units;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

if ($argc !== 5 || (int)$argv[1] <= 0 || (int)$argv[2] <= 0 || $argv[3] === '' || $argv[4] === '') {
    fwrite(STDERR, "Usage: php movement-dispatch-worker.php <source-kid> <target-kid> <barrier-file> <ready-file>\n");
    exit(2);
}

try {
    if (file_put_contents($argv[4], 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to signal movement-dispatch worker readiness.');
    }
    $deadline = microtime(true) + 10;
    while (@file_get_contents($argv[3]) !== 'go') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the movement-dispatch start barrier.');
        }
        usleep(1000);
    }

    $sourceKid = (int)$argv[1];
    $targetKid = (int)$argv[2];
    $units = array_fill(1, 11, 0);
    $units[1] = 4;
    $now = miliseconds();
    $movementId = (new MovementsModel())->addMovementWithSourceMutation(
        static function () use ($sourceKid, $units): bool {
            return Units::debitIfAvailable($sourceKid, $units);
        },
        $sourceKid,
        $targetKid,
        1,
        $units,
        0,
        0,
        0,
        0,
        0,
        MovementsModel::ATTACKTYPE_RAID,
        $now,
        $now + 1000
    );
    fwrite(STDOUT, (string)$movementId);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
