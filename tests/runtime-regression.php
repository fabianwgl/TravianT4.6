<?php

declare(strict_types=1);

use Core\Config;
use Core\Automation;
use Core\Database\DB;
use Core\Jobs\TransactionalTask;
use Core\Security\Password;
use Controller\RallyPoint\Simulator;
use Game\Buildings\BuildingHelper;
use Game\Formulas;
use Game\TruceDay;
use Model\AuctionModel;
use Model\MasterBuilder;
use Model\NatarsModel;
use Model\OptionModel;
use Model\VillageModel;
use Model\WonderOfTheWorldModel;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

function expect_same(mixed $expected, mixed $actual, string $label): void
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

function expect_true(bool $actual, string $label): void
{
    expect_same(true, $actual, $label);
}

function expect_close(float $expected, float $actual, float $tolerance, string $label): void
{
    if (abs($expected - $actual) > $tolerance) {
        throw new RuntimeException(sprintf(
            '%s: expected %.12f ± %.12f, received %.12f',
            $label,
            $expected,
            $tolerance,
            $actual
        ));
    }
}

$config = Config::getInstance();
$start = (int)$config->game->start_time;

expect_same(10, (int)$config->game->speed, 'supported game speed');
expect_same(25, (int)MAP_SIZE, 'supported map radius');
expect_same(4, (int)$config->game->movement_speed_increase, 'movement multiplier');
expect_same(false, (bool)$config->game->allowNewTribes, 'additional tribes disabled');
expect_same(false, (bool)$config->custom->paymentWizardBuyGoldEnabled, 'payments disabled');
expect_same(false, (bool)$config->custom->serverIsFreeGold, 'free-gold mode disabled');
expect_same(true, (bool)$config->game->starvation, 'starvation enabled');
expect_same(86400, (int)$config->game->dailyQuestInterval, 'daily quest interval');
expect_same(35, (int)$config->game->round_length, 'round length');

expect_same($start + 10 * 86400, (int)$config->timers->ArtifactsReleaseTime, 'artifact release');
expect_same($start + 20 * 86400, (int)$config->timers->wwPlansReleaseTime, 'plan release');
expect_same($start + 25 * 86400, (int)$config->timers->WWConstructStartTime, 'Natar WW start');
expect_same(8640, (int)$config->timers->WWUpLvlInterval, 'Natar WW interval');
expect_same($start + 35 * 86400, (int)$config->timers->AutoFinishTime, 'automatic finish');

expect_same(86400, (int)Formulas::getProtectionBasicTime($start), 'initial protection');
expect_same(86400, (int)Formulas::getProtectionExtendTime($start), 'protection extension');
expect_same([40.0, 100.0, 50.0, 60.0], Formulas::buildingUpgradeCosts(1, 1), 'woodcutter level-one costs');
expect_same([120, 100, 150, 30], Formulas::uTrainingCost(1), 'Roman legionnaire costs');
expect_same([95, 75, 40, 40], Formulas::uTrainingCost(11), 'Teuton clubswinger costs');
expect_same([100, 130, 55, 30], Formulas::uTrainingCost(21), 'Gaul phalanx costs');
expect_same(24, Formulas::uSpeed(1), 'x10 legionnaire movement speed');
expect_same(60, Formulas::uCarry(11), 'clubswinger carrying capacity');
expect_same(2800.0, Formulas::fieldProduction(10), 'level-ten resource production');
expect_same(80000.0, Formulas::storeCAP(20), 'level-twenty storage capacity');

foreach ([[-25, -25], [-25, 25], [0, 0], [25, -25], [25, 25]] as [$x, $y]) {
    $coordinates = Formulas::kid2xy(Formulas::xy2kid($x, $y));
    expect_same(['x' => $x, 'y' => $y], ['x' => (int)$coordinates['x'], 'y' => (int)$coordinates['y']], "map round trip $x,$y");
}

$password = 'Regression-only-passphrase';
$hash = Password::hash($password);
expect_true(Password::verify($password, $hash), 'current password verification');
expect_same(false, Password::verify($password . '-wrong', $hash), 'wrong password rejection');
expect_true(Password::verify($password, sha1($password)), 'legacy SHA-1 verification');
expect_true(Password::needsRehash(sha1($password)), 'legacy SHA-1 migration signal');

expect_same(4, MasterBuilder::queuedTargetLevel(2, 1, 0), 'first queued Master Builder target level');
expect_same(5, MasterBuilder::queuedTargetLevel(2, 1, 1), 'second queued Master Builder target level');
expect_same(
    1800,
    MasterBuilder::calculateResourceWait([0, 100, 100, 100], [200, 200, 200, 200], [100, 100, 100, 100]),
    'Master Builder resource wait'
);
expect_same(
    null,
    MasterBuilder::calculateResourceWait([100, 100, 100, 0], [100, 100, 100, -10], [100, 100, 100, 1]),
    'Master Builder never-ready crop state'
);
expect_same(1, OptionModel::vacationDaysToUse(1, 10), 'requested vacation duration');
expect_same(10, OptionModel::vacationDaysToUse(99, 10), 'vacation duration upper bound');
expect_same(0, OptionModel::vacationDaysToUse(1, 0), 'no vacation days remaining');
expect_same(
    'Configured public truce',
    TruceDay::renderPublicNotice(['params' => 'Configured public truce']),
    'configured public-truce notice'
);
expect_true(
    str_contains(TruceDay::renderPublicNotice([
        'params' => '',
        'showFrom' => time(),
        'showTo' => time() + 3600,
    ]), 'Public truce active'),
    'generated public-truce notice'
);

$zeros = array_fill(0, 10, 0);
$defenders = $zeros;
$defenders[0] = 100;
$attackers = $zeros;
$attackers[0] = 200;
$combat = (new Simulator())->init([
    'R' => false,
    'trapped2killed' => [],
    'attacker' => ['kid' => 1],
    'defender' => [
        'r' => 0,
        't' => 0,
        'p' => 100,
        'rpLevel' => 0,
        'wLevel' => 0,
        'stone' => 0,
        'artifacts' => ['durability' => 0, 'scout' => 0],
    ],
    'waves' => [
        ['r' => 0, 'u' => $defenders, 'U' => $zeros, 'side' => 'def'],
        [
            'r' => 1,
            'u' => $attackers,
            'U' => $zeros,
            'side' => 'off',
            'h' => false,
            'p' => 100,
            'b' => [0, 0],
            'hero' => [
                'health' => 100,
                'total_power' => 0,
                'offBonus' => 0,
                'str' => 0,
                'armor' => 0,
                'bandage' => ['num' => 0, 'eff' => 0],
                'cages' => 0,
            ],
        ],
    ],
]);
expect_close(0.290620131008, (float)$combat[0]['losses'][0], 0.000000000001, 'combat attacker loss ratio');
expect_same(1, $combat[0]['losses'][1], 'combat defender loss ratio');

$db = DB::getInstance();
$userAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'"
);
$artefactAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artefacts'"
);
$buildingUpgradeAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='building_upgrade'"
);
$itemAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='items'"
);
$accountingAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting'"
);
$movementAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='movement'"
);
$enforcementAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enforcement'"
);
$trappedAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='trapped'"
);
$infoBoxAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='infobox'"
);
$researchAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research'"
);

$nestedTransactionKid = 2000000019;
$db->begin_transaction();
try {
    $db->query("INSERT INTO smithy (kid) VALUES ($nestedTransactionKid)");
    expect_true($db->begin_transaction(), 'nested transaction started');
    $db->query("UPDATE smithy SET u1=1 WHERE kid=$nestedTransactionKid");
    expect_true($db->rollback(), 'nested transaction rolled back');
    expect_same(0, (int)$db->fetchScalar("SELECT u1 FROM smithy WHERE kid=$nestedTransactionKid"), 'savepoint rollback preserves outer transaction');

    expect_true($db->begin_transaction(), 'second nested transaction started');
    $db->query("UPDATE smithy SET u1=2 WHERE kid=$nestedTransactionKid");
    expect_true($db->commit(), 'nested transaction committed');
    expect_same(2, (int)$db->fetchScalar("SELECT u1 FROM smithy WHERE kid=$nestedTransactionKid"), 'savepoint commit preserves nested effect');
} finally {
    $db->rollback();
}
expect_same(
    0,
    (int)$db->fetchScalar("SELECT COUNT(*) FROM smithy WHERE kid=$nestedTransactionKid"),
    'outer rollback removes nested transaction fixture'
);

$researchKid = 2000000020;
$researchTask = 2000000001;
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM smithy WHERE kid=$researchKid"),
        'research fixture village ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM research WHERE id=$researchTask"),
        'research fixture task ID available'
    );
    $db->query("INSERT INTO smithy (kid) VALUES ($researchKid)");
    $db->query("INSERT INTO research (id, kid, nr, mode, end_time) VALUES ($researchTask, $researchKid, 1, 0, 0)");

    try {
        TransactionalTask::consume('research', $researchTask, function () use ($db, $researchKid): void {
            $db->query("UPDATE smithy SET u1=u1+1 WHERE kid=$researchKid");
            throw new RuntimeException('Simulated worker crash.');
        });
        throw new RuntimeException('Simulated worker crash was not propagated.');
    } catch (RuntimeException $e) {
        expect_same('Simulated worker crash.', $e->getMessage(), 'research crash propagated');
    }
    expect_same(
        '1|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT((SELECT COUNT(*) FROM research WHERE id=$researchTask), '|', u1) FROM smithy WHERE kid=$researchKid"
        ),
        'research crash rolls back effect and preserves task'
    );

    $automation = Automation::getInstance();
    expect_true($automation->processResearchTask($researchTask), 'research task consumed after retry');
    expect_same(
        '0|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT((SELECT COUNT(*) FROM research WHERE id=$researchTask), '|', u1) FROM smithy WHERE kid=$researchKid"
        ),
        'research retry commits effect and consumes task'
    );
    expect_same(false, $automation->processResearchTask($researchTask), 'duplicate research delivery ignored');
    expect_same(1, (int)$db->fetchScalar("SELECT u1 FROM smithy WHERE kid=$researchKid"), 'research effect not duplicated');
} finally {
    $db->query("DELETE FROM research WHERE id=$researchTask OR kid=$researchKid");
    $db->query("DELETE FROM smithy WHERE kid=$researchKid");
    $db->query("ALTER TABLE research AUTO_INCREMENT=$researchAutoIncrement");
}

$db->begin_transaction();
try {
    $holder = 2000000001;
    $ally = 2000000002;
    $alliance = 2000000000;
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id IN ($holder, $ally)"),
        'WW fixture user IDs available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar('SELECT COUNT(*) FROM artefacts WHERE id BETWEEN 2000000001 AND 2000000003'),
        'WW fixture artifact IDs available'
    );
    $db->query("INSERT INTO users (id, uuid, aid, name, password, email, race, kid, desc1, desc2, note) VALUES
        ($holder, 'ov-regression-holder', $alliance, 'OVTestHolder', 'x', '', 1, 1, '', '', ''),
        ($ally, 'ov-regression-ally', $alliance, 'OVTestAlly', 'x', '', 2, 2, '', '', '')");

    $helper = new BuildingHelper();
    expect_same(2, $helper->checkArtifactDependencies($alliance, $holder, 1, 40, true, 0), 'first WW plan required');

    $db->query("INSERT INTO artefacts (id, uid, kid, type, size, conquered, num, effecttype, effect, aoe, status, active)
        VALUES (2000000001, $holder, 1, 12, 1, 0, 1, 0, 1, 0, 1, 1)");
    expect_same(0, $helper->checkArtifactDependencies($alliance, $holder, 1, 40, true, 49), 'one plan through level 49');
    expect_same(3, $helper->checkArtifactDependencies($alliance, $holder, 1, 40, true, 50), 'distinct alliance plan required at level 50');

    $db->query("INSERT INTO artefacts (id, uid, kid, type, size, conquered, num, effecttype, effect, aoe, status, active)
        VALUES (2000000002, $holder, 1, 12, 1, 0, 2, 0, 1, 0, 1, 1)");
    expect_same(3, $helper->checkArtifactDependencies($alliance, $holder, 1, 40, true, 50), 'holder cannot supply second plan');

    $db->query("INSERT INTO artefacts (id, uid, kid, type, size, conquered, num, effecttype, effect, aoe, status, active)
        VALUES (2000000003, $ally, 2, 12, 1, 0, 3, 0, 1, 0, 1, 1)");
    expect_same(0, $helper->checkArtifactDependencies($alliance, $holder, 1, 40, true, 50), 'allied second plan accepted');

    $db->query("UPDATE users SET aid=0 WHERE id=$ally");
    expect_same(3, $helper->checkArtifactDependencies($alliance, $holder, 1, 40, true, 50), 'non-allied plan rejected');

    $builderOwner = 2000000003;
    $builderVillage = 2000000001;
    $normalTask = 2000000001;
    $firstMasterTask = 2000000002;
    $secondMasterTask = 2000000003;
    $firstCost = Formulas::buildingUpgradeCosts(1, 4);
    $secondCost = Formulas::buildingUpgradeCosts(1, 5);
    $fixtureResources = [];
    for ($i = 0; $i < 4; ++$i) {
        $fixtureResources[$i] = max($firstCost[$i], $secondCost[$i]);
    }
    $normalCommence = time() + 60;

    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, desc1, desc2, note)
        VALUES ($builderOwner, 'ov-regression-builder', 'OVTestBuilder', 'x', '', 2, $builderVillage, '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($builderVillage, $builderOwner, 3, 'OV Builder Village', 1, 0, 0,
         {$fixtureResources[0]}, {$fixtureResources[1]}, {$fixtureResources[2]}, 0, 0, 0, 1000000000,
         {$fixtureResources[3]}, 10000, 1000000000, 10000, 0, " . time() . ", 0)");
    $db->query("INSERT INTO fdata (kid, f1, f1t) VALUES ($builderVillage, 2, 1)");
    $db->query("INSERT INTO building_upgrade (id, kid, building_field, isMaster, start_time, commence) VALUES
        ($normalTask, $builderVillage, 1, 0, " . time() . ", $normalCommence),
        ($firstMasterTask, $builderVillage, 1, 1, " . time() . ", " . time() . "),
        ($secondMasterTask, $builderVillage, 1, 1, " . time() . ", " . time() . ")");

    expect_true((new MasterBuilder())->updateCommence($builderVillage, false), 'Master Builder queue recalculation');
    expect_same(
        $normalCommence,
        (int)$db->fetchScalar("SELECT commence FROM building_upgrade WHERE id=$firstMasterTask"),
        'first Master Builder task starts after the active worker'
    );
    expect_true(
        (int)$db->fetchScalar("SELECT commence FROM building_upgrade WHERE id=$secondMasterTask") >= time() + 99 * 86400,
        'second Master Builder task cannot reuse resources reserved by the first'
    );
    expect_same(
        3,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM building_upgrade WHERE kid=$builderVillage"),
        'valid Master Builder tasks remain queued'
    );
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE artefacts AUTO_INCREMENT=$artefactAutoIncrement");
    $db->query("ALTER TABLE building_upgrade AUTO_INCREMENT=$buildingUpgradeAutoIncrement");
}

$natarTarget = 2000000003;
$greyAreaTarget = 2000000004;
try {
    expect_same([5, 10], WonderOfTheWorldModel::attackLevelsBetween(0, 10), 'crossed WW attack levels');
    expect_same(
        [95, 96, 97, 98, 99],
        WonderOfTheWorldModel::attackLevelsBetween(94, 100),
        'late WW attack levels'
    );
    expect_same([], WonderOfTheWorldModel::attackLevelsBetween(99, 100), 'no level-100 Natar attack');
    expect_same(8640, WonderOfTheWorldModel::attackTravelSeconds(10), 'WW attack travel time');
    expect_same(1, WonderOfTheWorldModel::attackMultiplierForSpeed(10, false), 'WW baseline army multiplier');

    $attackProfile = [];
    foreach (WonderOfTheWorldModel::attackLevelsBetween(0, 99) as $attackLevel) {
        $attackProfile[$attackLevel] = WonderOfTheWorldModel::attackWavesForLevel($attackLevel);
    }
    expect_same(
        'ba70e4e45ca6da8c3527fa9c746d710c4fca238217c6732253b98869dac1db9a',
        hash('sha256', json_encode($attackProfile)),
        'complete WW Natar army profile'
    );

    $levelFiveWaves = WonderOfTheWorldModel::attackWavesForLevel(5);
    expect_same(2, count($levelFiveWaves), 'WW Natar two-wave profile');
    expect_same(3412, $levelFiveWaves[0][2], 'WW clearing-wave army');
    expect_same(10, $levelFiveWaves[1][8], 'WW demolition-wave ballistae');
    expect_same([], WonderOfTheWorldModel::attackWavesForLevel(6), 'non-attack WW level has no army');

    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM movement WHERE to_kid IN ($natarTarget, $greyAreaTarget)"),
        'Natar movement fixture targets available'
    );
    $wonder = new WonderOfTheWorldModel();
    expect_same(2, $wonder->attackWWVillage($natarTarget, 5), 'WW Natar waves scheduled');
    expect_same(0, $wonder->attackWWVillage($natarTarget, 6), 'invalid WW attack level rejected');

    $wwMovements = $db->query(
        "SELECT race, u2, u8, ctar1, ctar2, attack_type, end_time-start_time AS travel
         FROM movement WHERE to_kid=$natarTarget ORDER BY id"
    );
    expect_same(2, $wwMovements->num_rows, 'two WW Natar movements persisted');
    $clearingWave = $wwMovements->fetch_assoc();
    $demolitionWave = $wwMovements->fetch_assoc();
    expect_same('5|3412|0|40|40|3|8640000', implode('|', $clearingWave), 'WW clearing movement');
    expect_same('5|35|10|40|40|3|8641000', implode('|', $demolitionWave), 'WW demolition movement');

    expect_same(8640, NatarsModel::greyAreaAttackTravelSeconds(10), 'grey-area attack travel time');
    expect_same(4, NatarsModel::greyAreaWaveDelayMilliseconds(14), 'last grey-area wave offset');
    expect_same(
        NatarsModel::GREY_AREA_ATTACK_WAVE_COUNT,
        (new NatarsModel())->attackNewVillage($greyAreaTarget),
        'grey-area Natar batch persisted atomically'
    );
    expect_same(
        NatarsModel::GREY_AREA_ATTACK_WAVE_COUNT,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM movement WHERE to_kid=$greyAreaTarget"),
        'all grey-area Natar waves scheduled'
    );
    expect_same(
        '8640000|8640004',
        (string)$db->fetchScalar(
            "SELECT CONCAT(MIN(end_time-start_time), '|', MAX(end_time-start_time))
             FROM movement WHERE to_kid=$greyAreaTarget"
        ),
        'grey-area Natar wave timing'
    );
    expect_same(
        '1000|100',
        (string)$db->fetchScalar(
            "SELECT CONCAT(u1, '|', u8) FROM movement WHERE to_kid=$greyAreaTarget ORDER BY id LIMIT 1"
        ),
        'first grey-area Natar wave army'
    );
    expect_same(
        '25|18',
        (string)$db->fetchScalar(
            "SELECT CONCAT(u1, '|', u8) FROM movement WHERE to_kid=$greyAreaTarget ORDER BY id DESC LIMIT 1"
        ),
        'fourteenth grey-area Natar wave army'
    );
} finally {
    $db->query("DELETE FROM movement WHERE to_kid IN ($natarTarget, $greyAreaTarget)");
    $db->query("ALTER TABLE movement AUTO_INCREMENT=$movementAutoIncrement");
}

$horseOwner = 2000000004;
$firstHorse = 2000000001;
$otherHorse = 2000000002;
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$horseOwner"),
        'horse exchange fixture user ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM items WHERE id IN ($firstHorse, $otherHorse)"),
        'horse exchange fixture item IDs available'
    );
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, silver, desc1, desc2, note)
        VALUES ($horseOwner, 'ov-regression-horse', 'OVTestHorse', 'x', '', 1, 1, 25, '', '', '')");
    $db->query("INSERT INTO items (id, uid, btype, type, num, placeId, proc)
        VALUES ($firstHorse, $horseOwner, 6, 103, 1, 1, 0)");

    $auction = new AuctionModel();
    expect_same(
        false,
        $auction->canExchangeFirstHorseForSilver($horseOwner, $firstHorse),
        'first horse requires another owned horse'
    );
    expect_same(
        false,
        $auction->exchangeFirstHorseForSilver($horseOwner, $firstHorse),
        'first horse exchange rejected without replacement horse'
    );
    expect_same(
        25,
        (int)$db->fetchScalar("SELECT silver FROM users WHERE id=$horseOwner"),
        'rejected horse exchange preserves silver'
    );

    $db->query("INSERT INTO items (id, uid, btype, type, num, placeId, proc)
        VALUES ($otherHorse, $horseOwner, 6, 104, 1, 2, 0)");
    expect_true(
        $auction->canExchangeFirstHorseForSilver($horseOwner, $firstHorse),
        'first horse exchange becomes available with replacement horse'
    );
    expect_true(
        $auction->exchangeFirstHorseForSilver($horseOwner, $firstHorse),
        'first horse exchange succeeds'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM items WHERE id=$firstHorse"),
        'exchanged first horse removed'
    );
    expect_same(
        1,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM items WHERE id=$otherHorse"),
        'replacement horse preserved'
    );
    expect_same(
        125,
        (int)$db->fetchScalar("SELECT silver FROM users WHERE id=$horseOwner"),
        'first horse silver credited'
    );
    expect_same(
        '1,6,103,1|100|125',
        (string)$db->fetchScalar(
            "SELECT CONCAT(cause, '|', reserve, '|', balance)
             FROM accounting WHERE uid=$horseOwner ORDER BY id DESC LIMIT 1"
        ),
        'first horse exchange accounting entry'
    );
    expect_same(
        false,
        $auction->exchangeFirstHorseForSilver($horseOwner, $firstHorse),
        'first horse exchange cannot be replayed'
    );
    expect_same(
        125,
        (int)$db->fetchScalar("SELECT silver FROM users WHERE id=$horseOwner"),
        'replayed horse exchange cannot duplicate silver'
    );
    expect_true(
        $auction->creditSilver($horseOwner, 500, AuctionModel::BOOKING_CAUSE_QUEST_REWARD, time()),
        'quest silver credited with accounting'
    );
    expect_same(
        625,
        (int)$db->fetchScalar("SELECT silver FROM users WHERE id=$horseOwner"),
        'quest silver balance'
    );
    expect_same(
        'quest|500|625',
        (string)$db->fetchScalar(
            "SELECT CONCAT(cause, '|', reserve, '|', balance)
             FROM accounting WHERE uid=$horseOwner ORDER BY id DESC LIMIT 1"
        ),
        'quest silver accounting entry'
    );
} finally {
    $db->query("DELETE FROM accounting WHERE uid=$horseOwner");
    $db->query("DELETE FROM items WHERE uid=$horseOwner OR id IN ($firstHorse, $otherHorse)");
    $db->query("DELETE FROM users WHERE id=$horseOwner");
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE items AUTO_INCREMENT=$itemAutoIncrement");
    $db->query("ALTER TABLE accounting AUTO_INCREMENT=$accountingAutoIncrement");
}

$vacationOwner = 2000000005;
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$vacationOwner"),
        'vacation fixture user ID available'
    );
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, desc1, desc2, note)
        VALUES ($vacationOwner, 'ov-regression-vacation', 'OVTestVacation', 'x', '', 1, 1, '', '', '')");

    $vacation = new OptionModel();
    $vacationStart = time();
    expect_true($vacation->enterVacationMode($vacationOwner, 2), 'vacation mode entered');
    $vacationTill = (int)$db->fetchScalar("SELECT vacationActiveTil FROM users WHERE id=$vacationOwner");
    expect_true(
        $vacationTill >= $vacationStart + 2 * 86400 && $vacationTill <= time() + 2 * 86400,
        'vacation end persisted'
    );
    expect_same(
        "2|$vacationTill",
        (string)$db->fetchScalar(
            "SELECT CONCAT(vacationUsedDays, '|', vacationActiveTil) FROM users WHERE id=$vacationOwner"
        ),
        'vacation duration accounted'
    );
    expect_same(
        "13|$vacationTill",
        (string)$db->fetchScalar(
            "SELECT CONCAT(type, '|', showTo) FROM infobox WHERE uid=$vacationOwner ORDER BY id DESC LIMIT 1"
        ),
        'vacation infobox notification'
    );
    expect_true($vacation->abortVacation($vacationOwner), 'vacation mode aborted');
    expect_same(
        '0|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(vacationActiveTil, '|', (SELECT COUNT(*) FROM infobox WHERE uid=$vacationOwner AND type=13))
             FROM users WHERE id=$vacationOwner"
        ),
        'vacation notification removed on abort'
    );
} finally {
    $db->query("DELETE FROM infobox WHERE uid=$vacationOwner");
    $db->query("DELETE FROM users WHERE id=$vacationOwner");
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE infobox AUTO_INCREMENT=$infoBoxAutoIncrement");
}

$punishedOwner = 2000000006;
$receiverOwner = 2000000007;
$punishedVillage = 2000000010;
$receiverVillage = 2000000011;
$untouchedVillage = 2000000012;
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id IN ($punishedOwner, $receiverOwner)"),
        'punishment fixture user IDs available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid IN ($punishedVillage, $receiverVillage, $untouchedVillage)"),
        'punishment fixture village IDs available'
    );
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, desc1, desc2, note) VALUES
        ($punishedOwner, 'ov-regression-punished', 'OVTestPunished', 'x', '', 1, $punishedVillage, '', '', ''),
        ($receiverOwner, 'ov-regression-receiver', 'OVTestReceiver', 'x', '', 1, $receiverVillage, '', '', '')");
    $lastUpdate = miliseconds();
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($punishedVillage, $punishedOwner, 3, 'OV Punished', 1, 0, 0, 0, 0, 0, 0, 0, 0, 1000000, 1000, 1000, 1000000, 120, $lastUpdate, " . time() . ", 0),
        ($receiverVillage, $receiverOwner, 3, 'OV Receiver', 1, 0, 0, 0, 0, 0, 0, 0, 0, 1000000, 1000, 1000, 1000000, 40, $lastUpdate, " . time() . ", 0),
        ($untouchedVillage, $punishedOwner, 3, 'OV Untouched', 0, 0, 0, 0, 0, 0, 0, 0, 0, 1000000, 1000, 1000, 1000000, 30, $lastUpdate, " . time() . ", 0)");
    $db->query("INSERT INTO fdata (kid) VALUES ($punishedVillage), ($receiverVillage), ($untouchedVillage)");
    $db->query("INSERT INTO units (kid, race, u1) VALUES
        ($punishedVillage, 1, 100),
        ($receiverVillage, 1, 0),
        ($untouchedVillage, 1, 30)");
    $db->query("INSERT INTO enforcement (uid, kid, to_kid, race, u1)
        VALUES ($punishedOwner, $punishedVillage, $receiverVillage, 1, 40)");
    $db->query("INSERT INTO trapped (kid, to_kid, race, u1)
        VALUES ($punishedVillage, $receiverVillage, 1, 20)");

    expect_true(
        (new VillageModel())->punishPlayer($punishedOwner, $punishedVillage, 0, 50, 0, 0),
        'village troop punishment'
    );
    expect_same(
        '50|30|20|10',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT u1 FROM units WHERE kid=$punishedVillage), '|',
                (SELECT u1 FROM units WHERE kid=$untouchedVillage), '|',
                (SELECT u1 FROM enforcement WHERE kid=$punishedVillage), '|',
                (SELECT u1 FROM trapped WHERE kid=$punishedVillage)
            )"
        ),
        'punishment troop scope and reductions'
    );
    expect_same(
        '60|20|30',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT upkeep FROM vdata WHERE kid=$punishedVillage), '|',
                (SELECT upkeep FROM vdata WHERE kid=$receiverVillage), '|',
                (SELECT upkeep FROM vdata WHERE kid=$untouchedVillage)
            )"
        ),
        'punishment upkeep recalculation'
    );
} finally {
    $db->query("DELETE FROM enforcement WHERE kid=$punishedVillage OR to_kid=$receiverVillage");
    $db->query("DELETE FROM trapped WHERE kid=$punishedVillage OR to_kid=$receiverVillage");
    $db->query("DELETE FROM units WHERE kid IN ($punishedVillage, $receiverVillage, $untouchedVillage)");
    $db->query("DELETE FROM fdata WHERE kid IN ($punishedVillage, $receiverVillage, $untouchedVillage)");
    $db->query("DELETE FROM vdata WHERE kid IN ($punishedVillage, $receiverVillage, $untouchedVillage)");
    $db->query("DELETE FROM users WHERE id IN ($punishedOwner, $receiverOwner)");
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE enforcement AUTO_INCREMENT=$enforcementAutoIncrement");
    $db->query("ALTER TABLE trapped AUTO_INCREMENT=$trappedAutoIncrement");
}

echo "Runtime regression checks passed.\n";
