<?php

declare(strict_types=1);

use Core\Database\DB;
use Core\Village;
use Model\MarketModel;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

if ($argc !== 6
    || !in_array($argv[1], ['send', 'offer'], true)
    || (int)$argv[2] <= 0
    || (int)$argv[3] <= 0
    || $argv[4] === ''
    || $argv[5] === '') {
    fwrite(STDERR, "Usage: php marketplace-mutation-worker.php <send|offer> <source-kid> <target-kid> <barrier-file> <ready-file>\n");
    exit(2);
}

try {
    if (file_put_contents($argv[5], 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to signal marketplace worker readiness.');
    }
    $deadline = microtime(true) + 10;
    while (@file_get_contents($argv[4]) !== 'go') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the marketplace start barrier.');
        }
        usleep(1000);
    }

    $mode = $argv[1];
    $sourceKid = (int)$argv[2];
    $targetKid = (int)$argv[3];
    $row = DB::getInstance()->query(
        "SELECT kid, wood, clay, iron, crop, lastmupdate FROM vdata WHERE kid=$sourceKid"
    )->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('Marketplace worker source village is missing.');
    }
    $reflection = new ReflectionClass(Village::class);
    /** @var Village $village */
    $village = $reflection->newInstanceWithoutConstructor();
    $village->village = $row;
    $market = new MarketModel();
    $result = $market->performAtomicVillageMutation(
        $village,
        static function () use ($mode, $village, $market, $sourceKid, $targetKid): bool {
            if (!$village->isResourcesAvailable([10, 0, 0, 0])
                || !$village->modifyResources([10, 0, 0, 0])) {
                return false;
            }
            if ($mode === 'offer') {
                return $market->addOffer($sourceKid, 0, 0, 2, 10, 1, 10);
            }
            return $market->sendResources($sourceKid, $targetKid, 1, 10, 0, 0, 0, 1);
        }
    );
    fwrite(STDOUT, $result ? 'true' : 'false');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
