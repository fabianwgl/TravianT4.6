<?php

declare(strict_types=1);

use Controller\WinnerCtrl;
use Core\Automation;
use Core\Clock;
use Core\Config;
use Core\Database\DB;
use Core\Random;
use Game\Buildings\BuildingHelper;
use Game\Formulas;
use Game\NoticeHelper;
use Model\ArtefactsModel;
use Model\MarketPlaceProcessor;
use Model\MovementsModel;
use Model\RegisterModel;
use Model\VillageModel;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

// The legacy DB wrapper returns false and records the failing SQL itself; keep
// mysqli from converting that diagnostic path into an opaque exception.
mysqli_report(MYSQLI_REPORT_OFF);

function round_expect_same(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, received %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function round_expect_true(bool $actual, string $label): void
{
    round_expect_same(true, $actual, $label);
}

function round_query_id(DB $db, string $table): int
{
    return (int)$db->fetchScalar(
        "SELECT AUTO_INCREMENT FROM information_schema.TABLES " .
        "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->real_escape_string($table) . "'"
    );
}

function round_reset_auto_increment(DB $db, string $table, int $value): void
{
    if ($value > 0) {
        $db->query("ALTER TABLE `$table` AUTO_INCREMENT=$value");
    }
}

/**
 * Select a fixed, ordered map position and refuse to use an occupied tile.
 * The coordinates are deliberately explicit so the round remains reproducible
 * on clean worlds and does not depend on ORDER BY RAND().
 */
function round_pick_kid(DB $db, array $coordinates, array $used): int
{
    foreach ($coordinates as [$x, $y]) {
        $kid = Formulas::xy2kid((int)$x, (int)$y);
        if (in_array($kid, $used, true)) {
            continue;
        }
        $result = $db->query(
            "SELECT w.id, w.occupied, a.occupied AS available_occupied " .
            "FROM wdata w JOIN available_villages a ON a.kid=w.id WHERE w.id=$kid"
        );
        if (!$result || !$result->num_rows) {
            continue;
        }
        $row = $result->fetch_assoc();
        if ((int)$row['occupied'] === 0 && (int)$row['available_occupied'] === 0) {
            return $kid;
        }
    }
    throw new RuntimeException('No deterministic disposable map position is available.');
}

$db = DB::getInstance();
$config = Config::getInstance();
$automation = Automation::getInstance();
$register = new RegisterModel();
$started = false;
$originalServerFinished = (int)$db->fetchScalar('SELECT serverFinished FROM config LIMIT 1');
$originalDynamicServerFinished = $config->dynamic->serverFinished;
$originalArtifactsReleased = $config->dynamic->ArtifactsReleased;
$originalWWPlansReleased = $config->dynamic->WWPlansReleased;
$originalWwPlansEnabled = $config->custom->wwPlansEnabled;
$originalNeedAlliancePlan = $config->custom->needAllianceWWPlan;
$originalFirstVillageFieldsLevel = $config->game->firstVillageCreationFieldsLevel;
$autoIncrementTables = [
    'users',
    'hero',
    'face',
    'inventory',
    'adventure',
    'building_upgrade',
    'training',
    'send',
    'movement',
    'ndata',
    'surrounding',
    'artefacts',
    'artlog',
    'alidata',
];
$autoIncrements = [];
$roundStage = 'initialisation';
$roundNow = (int)$config->game->start_time + 86400;
set_exception_handler(static function (Throwable $exception) use (&$roundStage): void {
    fwrite(STDERR, "complete-round stage [$roundStage]: " . $exception->getMessage() . "\n");
    exit(1);
});
set_error_handler(static function (int $severity, string $message) use (&$roundStage): bool {
    if (str_contains($message, 'Mysqli Error') || str_contains($message, 'SQL syntax')) {
        fwrite(STDERR, "complete-round stage [$roundStage]: $message\n");
        throw new RuntimeException("$message (stage: $roundStage)");
    }
    // Registration/build helpers predate CLI execution and emit these known
    // notices when no browser session exists. Surface every other warning.
    if (str_contains($message, 'Trying to access array offset on null')
        || str_contains($message, 'explode(): Passing null')
        || str_contains($message, 'Implicit conversion from float')) {
        return true;
    }
    return false;
});

try {
    Clock::freeze($roundNow);
    Random::freeze(13371337);
    mt_srand(make_seed());
    round_expect_same($roundNow, Clock::now(), 'fixture clock is frozen');
    round_expect_same($roundNow * 1000, miliseconds(true), 'fixture millisecond clock is frozen');
    round_expect_same(13371337, make_seed(), 'fixture random seed is frozen');
    // Registration normally runs in a web session. Keep the CLI fixture
    // deterministic and let the test's explicit build step cover construction.
    $config->game->firstVillageCreationFieldsLevel = 0;
    $roundStage = 'register';
    foreach ($autoIncrementTables as $table) {
        $autoIncrements[$table] = round_query_id($db, $table);
    }
    round_expect_same(0, $originalServerFinished, 'round starts unfinished');
    round_expect_true($db->begin_transaction(), 'complete-round fixture transaction started');
    $started = true;

    // Register two real accounts and their base villages through the product API.
    $baseKid = round_pick_kid($db, [[20, 0], [20, 1], [19, 0], [18, 0]], []);
    $defenderKid = round_pick_kid($db, [[22, 0], [22, 1], [23, 1], [21, 0]], [$baseKid]);
    $settleKid = round_pick_kid($db, [[24, 0], [24, 1], [23, 0], [17, 0]], [$baseKid, $defenderKid]);
    $wwKid = round_pick_kid($db, [[20, -1], [21, -1], [22, -1], [23, -1]], [$baseKid, $defenderKid, $settleKid]);

    $roundStage = 'register-actor-user';
    $actorUid = (int)$register->addUser('OVRoundActor', 'round-regression', '', 1, $baseKid, 1, true, true);
    $roundStage = 'register-defender-user';
    $defenderUid = (int)$register->addUser('OVRoundDefender', 'round-regression', '', 3, $defenderKid, 1, true, true);
    round_expect_true($actorUid > 2, 'actor registered');
    round_expect_true($defenderUid > 2, 'defender registered');
    $roundStage = 'register-actor-village';
    $surroundingBeforeRegistration = (int)$db->fetchScalar(
        "SELECT COUNT(*) FROM surrounding WHERE kid=$baseKid"
    );
    $actorVillageCreated = $register->createBaseVillage($actorUid, 'OVRoundActor', 1, $baseKid, true);
    if ($db->mysqli->errno) {
        throw new RuntimeException('actor village SQL error: ' . $db->mysqli->error);
    }
    round_expect_true($actorVillageCreated, 'actor village created');
    round_expect_same(
        $surroundingBeforeRegistration + 1,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=$baseKid"),
        'registration records surrounding village founding'
    );
    $registrationSurrounding = $db->query(
        "SELECT x, y, type, params, time FROM surrounding WHERE kid=$baseKid ORDER BY id DESC LIMIT 1"
    )->fetch_assoc();
    $baseCoordinates = Formulas::kid2xy($baseKid);
    round_expect_same((int)$baseCoordinates['x'], (int)$registrationSurrounding['x'], 'registration surrounding x coordinate');
    round_expect_same((int)$baseCoordinates['y'], (int)$registrationSurrounding['y'], 'registration surrounding y coordinate');
    round_expect_same(NoticeHelper::SURROUNDING_VILLAGE_FOUND, (int)$registrationSurrounding['type'], 'registration surrounding event type');
    round_expect_same("$actorUid:OVRoundActor:$baseKid", $registrationSurrounding['params'], 'registration surrounding payload');
    round_expect_true((int)$registrationSurrounding['time'] > 0, 'registration surrounding timestamp');
    $roundStage = 'register-defender-village';
    $defenderVillageCreated = $register->createBaseVillage($defenderUid, 'OVRoundDefender', 3, $defenderKid);
    if ($db->mysqli->errno) {
        throw new RuntimeException('defender village SQL error: ' . $db->mysqli->error);
    }
    round_expect_true($defenderVillageCreated, 'defender village created');

    // Give the fixture enough culture/resources to exercise the round quickly.
    $db->query("UPDATE users SET cp=100000, cp_prod=100000, total_pop=100, total_villages=1, gift_gold=100000 WHERE id IN ($actorUid, $defenderUid)");
    $db->query("UPDATE vdata SET pop=100, cp=100, wood=1000000, clay=1000000, iron=1000000, crop=1000000, maxstore=10000000, maxcrop=10000000 WHERE kid IN ($baseKid, $defenderKid)");
    $db->query("UPDATE vdata SET capital=0, loyalty=0 WHERE kid=$defenderKid");
    $db->query("UPDATE fdata SET f1=1, f1t=1 WHERE kid=$baseKid");
    $db->query("UPDATE units SET u1=10000, u9=5, u10=3 WHERE kid=$baseKid");
    $db->query("UPDATE units SET u1=100, u9=0, u10=0 WHERE kid=$defenderKid");
    // A real account would move its hero before abandoning a non-capital
    // village. Remove the fixture hero so capture can exercise that path
    // without inventing a second defender village.
    $db->query("DELETE FROM hero WHERE uid=$defenderUid");
    $db->query("UPDATE units SET u11=0 WHERE kid=$defenderKid");

    // Build: consume a real scheduled building task.
    $roundStage = 'build';
    $beforeBuildLevel = (int)$db->fetchScalar("SELECT f1 FROM fdata WHERE kid=$baseKid");
    $db->query("INSERT INTO building_upgrade (kid, building_field, isMaster, start_time, commence) VALUES ($baseKid, 1, 0, $roundNow, $roundNow)");
    $buildTask = (int)$db->lastInsertId();
    round_expect_true($automation->processBuildingTask($buildTask), 'building task completed');
    round_expect_same($beforeBuildLevel + 1, (int)$db->fetchScalar("SELECT f1 FROM fdata WHERE kid=$baseKid"), 'building level advanced');

    // Train: complete a queue entry through the transactional worker path.
    $roundStage = 'train';
    $beforeTrainingTroops = (int)$db->fetchScalar("SELECT u1 FROM units WHERE kid=$baseKid");
    $db->query("INSERT INTO training (kid, nr, num, item_id, training_time, commence, end_time) VALUES ($baseKid, 1, 25, 19, 0, 0, 0)");
    $trainingTask = (int)$db->lastInsertId();
    round_expect_true($automation->processTrainingTask($trainingTask), 'training task completed');
    round_expect_same($beforeTrainingTroops + 25, (int)$db->fetchScalar("SELECT u1 FROM units WHERE kid=$baseKid"), 'trained troops available');

    // Trade: deliver resources and create the merchant return leg.
    $roundStage = 'trade';
    $defenderWoodBefore = (int)$db->fetchScalar("SELECT wood FROM vdata WHERE kid=$defenderKid");
    $db->query("INSERT INTO send (kid, to_kid, wood, clay, iron, crop, x, mode, end_time) VALUES ($baseKid, $defenderKid, 125, 100, 75, 50, 1, 0, " . ($roundNow - 1) . ")");
    $sendTask = (int)$db->lastInsertId();
    round_expect_true((new MarketPlaceProcessor())->processRow(['id' => $sendTask]), 'merchant delivery completed');
    round_expect_same($defenderWoodBefore + 125, (int)$db->fetchScalar("SELECT wood FROM vdata WHERE kid=$defenderKid"), 'trade delivered wood');
    round_expect_true((int)$db->fetchScalar("SELECT COUNT(*) FROM send WHERE kid=$defenderKid AND to_kid=$baseKid AND mode=1") > 0, 'merchant return leg queued');

    // Attack: first resolve a conventional attack without a chief.
    $roundStage = 'attack';
    $attackUnits = array_fill(1, 11, 0);
    $attackUnits[1] = 1000;
    $movement = new MovementsModel();
    $past = ($roundNow - 10) * 1000;
    $attackId = (int)$movement->addMovement($baseKid, $defenderKid, 1, $attackUnits, 0, 0, 0, 0, 0, MovementsModel::ATTACKTYPE_NORMAL, $past, $past);
    round_expect_true($attackId > 0, 'attack movement queued');
    round_expect_true($automation->processMovementTask($attackId), 'attack movement resolved');
    round_expect_same($defenderUid, (int)$db->fetchScalar("SELECT owner FROM vdata WHERE kid=$defenderKid"), 'conventional attack preserves village owner');

    // Settle: use the real settlers processor to found a second village.
    $roundStage = 'settle';
    $settlerUnits = array_fill(1, 11, 0);
    $settlerUnits[10] = 3;
    $settleId = (int)$movement->addMovement($baseKid, $settleKid, 1, $settlerUnits, 0, 0, 0, 0, 0, MovementsModel::ATTACKTYPE_SETTLERS, $past, $past);
    round_expect_true($settleId > 0, 'settlement movement queued');
    round_expect_true($automation->processMovementTask($settleId), 'settlement movement resolved');
    round_expect_same($actorUid, (int)$db->fetchScalar("SELECT owner FROM vdata WHERE kid=$settleKid"), 'settlement created actor village');
    round_expect_same(2, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE owner=$actorUid"), 'actor has two villages after settlement');

    // Conquer: a second attack with chiefs captures the non-capital village.
    $roundStage = 'conquer';
    $staleCaptureVillageState = (string)$db->fetchScalar(
        "SELECT CONCAT_WS('|', owner, pop, cp, loyalty, maxstore, maxcrop) FROM vdata WHERE kid=$defenderKid"
    );
    $staleCaptureTroopState = (string)$db->fetchScalar(
        "SELECT CONCAT_WS('|', u1, u9, u10, u11) FROM units WHERE kid=$defenderKid"
    );
    $staleCaptureUserState = (string)$db->fetchScalar(
        "SELECT GROUP_CONCAT(CONCAT_WS('|', id, total_pop, cp_prod, total_villages) ORDER BY id SEPARATOR ':') " .
        "FROM users WHERE id IN ($actorUid, $defenderUid)"
    );
    $surroundingBeforeStaleCapture = (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=$defenderKid");
    round_expect_same(
        false,
        (new VillageModel())->captureVillage($actorUid, $defenderKid, 100, 1, 0, 5, $baseKid),
        'stale-source-owner capture is rejected'
    );
    round_expect_same(
        $staleCaptureVillageState,
        (string)$db->fetchScalar(
            "SELECT CONCAT_WS('|', owner, pop, cp, loyalty, maxstore, maxcrop) FROM vdata WHERE kid=$defenderKid"
        ),
        'stale-source-owner capture preserves village state'
    );
    round_expect_same(
        $staleCaptureTroopState,
        (string)$db->fetchScalar("SELECT CONCAT_WS('|', u1, u9, u10, u11) FROM units WHERE kid=$defenderKid"),
        'stale-source-owner capture preserves troops'
    );
    round_expect_same(
        $staleCaptureUserState,
        (string)$db->fetchScalar(
            "SELECT GROUP_CONCAT(CONCAT_WS('|', id, total_pop, cp_prod, total_villages) ORDER BY id SEPARATOR ':') " .
            "FROM users WHERE id IN ($actorUid, $defenderUid)"
        ),
        'stale-source-owner capture preserves user totals'
    );
    round_expect_same(
        $surroundingBeforeStaleCapture,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=$defenderKid"),
        'stale-source-owner capture records no surrounding event'
    );
    $surroundingBeforeNoOpCapture = (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=$defenderKid");
    round_expect_same(
        false,
        (new VillageModel())->captureVillage($defenderUid, $defenderKid, 100, $defenderUid, 100, 3, $defenderKid),
        'same-owner capture is rejected'
    );
    round_expect_same(
        $surroundingBeforeNoOpCapture,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=$defenderKid"),
        'same-owner capture records no surrounding event'
    );
    round_expect_same(
        $defenderUid,
        (int)$db->fetchScalar("SELECT owner FROM vdata WHERE kid=$defenderKid"),
        'same-owner capture preserves village ownership'
    );
    $surroundingBeforeConquest = $surroundingBeforeNoOpCapture;
    $surroundingBeforeConquestId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    $defenderVillageName = (string)$db->fetchScalar("SELECT name FROM vdata WHERE kid=$defenderKid");
    $conquestUnits = array_fill(1, 11, 0);
    $conquestUnits[1] = 5000;
    $conquestUnits[9] = 5;
    $conquestId = (int)$movement->addMovement($baseKid, $defenderKid, 1, $conquestUnits, 0, 0, 0, 0, 0, MovementsModel::ATTACKTYPE_NORMAL, $past, $past);
    round_expect_true($conquestId > 0, 'conquest movement queued');
    round_expect_true($automation->processMovementTask($conquestId), 'conquest movement resolved');
    round_expect_same($actorUid, (int)$db->fetchScalar("SELECT owner FROM vdata WHERE kid=$defenderKid"), 'conquest transferred village ownership');
    round_expect_same(
        $surroundingBeforeConquest + 2,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=$defenderKid"),
        'conquest records conquer and loss surrounding events'
    );
    $conquestSurroundingResult = $db->query(
        "SELECT x, y, type, params, time FROM surrounding " .
        "WHERE kid=$defenderKid AND id>$surroundingBeforeConquestId ORDER BY id"
    );
    round_expect_same(2, $conquestSurroundingResult->num_rows, 'conquest surrounding event count');
    $conquerSurrounding = $conquestSurroundingResult->fetch_assoc();
    $lossSurrounding = $conquestSurroundingResult->fetch_assoc();
    $defenderCoordinates = Formulas::kid2xy($defenderKid);
    round_expect_same((int)$defenderCoordinates['x'], (int)$conquerSurrounding['x'], 'conquest surrounding x coordinate');
    round_expect_same((int)$defenderCoordinates['y'], (int)$conquerSurrounding['y'], 'conquest surrounding y coordinate');
    round_expect_same(NoticeHelper::SURROUNDING_VILLAGE_CONQUER, (int)$conquerSurrounding['type'], 'conquest surrounding event type');
    round_expect_same("$actorUid:OVRoundActor:$defenderKid", $conquerSurrounding['params'], 'conquest surrounding payload');
    round_expect_same((int)$defenderCoordinates['x'], (int)$lossSurrounding['x'], 'loss surrounding x coordinate');
    round_expect_same((int)$defenderCoordinates['y'], (int)$lossSurrounding['y'], 'loss surrounding y coordinate');
    round_expect_same(NoticeHelper::SURROUNDING_VILLAGE_LOST, (int)$lossSurrounding['type'], 'loss surrounding event type');
    round_expect_same(
        "$defenderUid:OVRoundDefender:$defenderKid:$defenderVillageName",
        $lossSurrounding['params'],
        'loss surrounding payload'
    );
    round_expect_true((int)$conquerSurrounding['time'] > 0, 'conquest surrounding timestamp');
    round_expect_same((int)$conquerSurrounding['time'], (int)$lossSurrounding['time'], 'conquest surrounding events share a timestamp');

    // Artifacts: capture and activate a deterministic artifact on the conquered village.
    $roundStage = 'artifacts';
    $db->query("UPDATE config SET ArtifactsReleased=1, WWPlansReleased=0");
    $config->dynamic->ArtifactsReleased = 1;
    $config->dynamic->WWPlansReleased = 0;
    $db->query("INSERT INTO artefacts (uid, kid, release_kid, type, size, conquered, num, effecttype, effect, aoe, status, active) VALUES (1, $defenderKid, $defenderKid, 2, 1, " . ($roundNow - 100) . ", 1, 2, 4, 1, 1, 0)");
    $artifactId = (int)$db->lastInsertId();
    $artifacts = new ArtefactsModel();
    $artifacts->captureArtefact($artifactId, $defenderKid, $actorUid);
    round_expect_same($actorUid, (int)$db->fetchScalar("SELECT uid FROM artefacts WHERE id=$artifactId"), 'artifact captured by actor');
    $db->query("UPDATE artefacts SET conquered=" . ($roundNow - ArtefactsModel::getArtifactActivationTime() - 1) . " WHERE id=$artifactId");
    $artifactRow = $db->query("SELECT * FROM artefacts WHERE id=$artifactId")->fetch_assoc();
    $artifacts->activateArtifact($artifactRow);
    round_expect_same(1, (int)$db->fetchScalar("SELECT active FROM artefacts WHERE id=$artifactId"), 'captured artifact activated');

    // World Wonder plans: exercise player and allied-plan requirements.
    $roundStage = 'plans';
    $db->query("INSERT INTO alidata (name, tag) VALUES ('OV Round Alliance', 'OVR')");
    $allianceId = (int)$db->lastInsertId();
    $db->query("UPDATE users SET aid=$allianceId WHERE id IN ($actorUid, $defenderUid)");
    $config->custom->wwPlansEnabled = true;
    $config->custom->needAllianceWWPlan = true;
    round_expect_same(2, (new BuildingHelper())->checkArtifactDependencies($allianceId, $actorUid, $wwKid, 40, true, 0), 'WW requires player plan before release');
    $db->query("INSERT INTO artefacts (uid, kid, release_kid, type, size, conquered, num, effecttype, effect, aoe, status, active) VALUES ($actorUid, $defenderKid, $defenderKid, 12, 1, $roundNow, 0, 12, 0, 1, 1, 1)");
    $playerPlanId = (int)$db->lastInsertId();
    round_expect_same(0, (new BuildingHelper())->checkArtifactDependencies($allianceId, $actorUid, $wwKid, 40, true, 0), 'player plan unlocks WW start');
    round_expect_same(3, (new BuildingHelper())->checkArtifactDependencies($allianceId, $actorUid, $wwKid, 40, true, 50), 'allied plan required after level 50');
    $db->query("INSERT INTO artefacts (uid, kid, release_kid, type, size, conquered, num, effecttype, effect, aoe, status, active) VALUES ($defenderUid, $defenderKid, $defenderKid, 12, 1, $roundNow, 0, 12, 0, 1, 1, 1)");
    round_expect_same(0, (new BuildingHelper())->checkArtifactDependencies($allianceId, $actorUid, $wwKid, 40, true, 50), 'allied plan unlocks late WW levels');
    round_expect_true($playerPlanId > 0, 'player plan persisted');

    // World Wonder and winner: create a real Natar WW, capture it, and expose level 100.
    $roundStage = 'world-wonder';
    round_expect_true($register->createWWVillage($wwKid), 'WW village created');
    $wwPop = (int)$db->fetchScalar("SELECT pop FROM vdata WHERE kid=$wwKid");
    round_expect_true((new VillageModel())->captureVillage(1, $wwKid, $wwPop, $actorUid, 0, 1, $baseKid), 'WW village captured');
    round_expect_same($actorUid, (int)$db->fetchScalar("SELECT owner FROM vdata WHERE kid=$wwKid"), 'WW owned by actor');
    $db->query("UPDATE fdata SET f99=100, f99t=40 WHERE kid=$wwKid");
    round_expect_same(100, (int)$db->fetchScalar("SELECT f99 FROM fdata WHERE kid=$wwKid"), 'WW reached level 100');

    // Render the same winner surface users see after the round is finished.
    $db->query("UPDATE config SET serverFinished=1");
    $config->dynamic->serverFinished = 1;
    $winnerCss = '';
    $winnerContent = '';
    new WinnerCtrl($winnerCss, $winnerContent);
    round_expect_true(str_contains($winnerContent, 'OVRoundActor'), 'winner page names the winning player');
    round_expect_true(str_contains($winnerContent, '60%'), 'winner page preserves literal CSS percentages');
    $config->dynamic->serverFinished = 0;
    $db->query("UPDATE config SET serverFinished=0");
    $noWinnerCss = '';
    $noWinnerContent = '';
    new WinnerCtrl($noWinnerCss, $noWinnerContent);
    round_expect_true($noWinnerContent !== '', 'no-winner page renders');
} finally {
    $config->dynamic->serverFinished = $originalDynamicServerFinished;
    $config->dynamic->ArtifactsReleased = $originalArtifactsReleased;
    $config->dynamic->WWPlansReleased = $originalWWPlansReleased;
    $config->custom->wwPlansEnabled = $originalWwPlansEnabled;
    $config->custom->needAllianceWWPlan = $originalNeedAlliancePlan;
    $config->game->firstVillageCreationFieldsLevel = $originalFirstVillageFieldsLevel;
    Clock::reset();
    Random::reset();
    if ($started) {
        $db->rollback();
    }
    foreach ($autoIncrements as $table => $value) {
        round_reset_auto_increment($db, $table, $value);
    }
}

restore_error_handler();

echo "Complete-round regression checks passed.\n";
