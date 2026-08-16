<?php

declare(strict_types=1);

use Core\Database\DB;
use Game\ResourcesHelper;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

if ($argc !== 4 || (int)$argv[1] <= 0 || $argv[2] === '' || $argv[3] === '') {
    fwrite(STDERR, "Usage: php resource-settlement-worker.php <village-id> <barrier-file> <ready-file>\n");
    exit(2);
}

try {
    if (file_put_contents($argv[3], 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to signal resource-settlement worker readiness.');
    }
    $deadline = microtime(true) + 10;
    while (@file_get_contents($argv[2]) !== 'go') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the resource-settlement start barrier.');
        }
        usleep(1000);
    }

    ResourcesHelper::updateVillageResources((int)$argv[1], true);
    $row = DB::getInstance()->query(
        'SELECT wood, lastmupdate FROM vdata WHERE kid=' . (int)$argv[1]
    );
    if (!$row || !$row->num_rows) {
        throw new RuntimeException('Unable to read settled resource state.');
    }
    $row = $row->fetch_assoc();
    fwrite(STDOUT, json_encode([
        'wood' => (float)$row['wood'],
        'lastmupdate' => (int)$row['lastmupdate'],
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
