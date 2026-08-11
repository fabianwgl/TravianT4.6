<?php

declare(strict_types=1);

use Core\Config;
use Core\Automation;
use Core\Database\DB;
use Core\Database\GlobalDB;
use Core\Helper\Mailer;
use Core\Helper\Notification;
use Core\Jobs\TransactionalTask;
use Core\Jobs\WorkerRegistry;
use Core\Security\Password;
use Controller\RallyPoint\Simulator;
use Game\Buildings\BuildingHelper;
use Game\Formulas;
use Game\NoticeHelper;
use Game\Starvation;
use Game\TruceDay;
use Model\AuctionModel;
use Model\AllianceModel;
use Model\MasterBuilder;
use Model\MarketPlaceProcessor;
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

$workerRegistry = new WorkerRegistry();
expect_same('movementComplete:1', $workerRegistry->nextIdentity('movementComplete'), 'first worker identity');
expect_same('movementComplete:2', $workerRegistry->nextIdentity('movementComplete'), 'duplicate job name gets unique worker identity');
expect_same(0, $workerRegistry->count(), 'worker identity allocation does not register a process');

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
$demolitionAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='demolition'"
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
$trainingAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='training'"
);
$allianceAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='alidata'"
);
$aliLogAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ali_log'"
);
$surroundingAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='surrounding'"
);
$aliInviteAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ali_invite'"
);
$allianceBonusQueueAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='alliance_bonus_upgrade_queue'"
);
$sendAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='send'"
);
$buyGoldMessageAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='buyGoldMessages'"
);
$banQueueAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='banQueue'"
);
$messageAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mdata'"
);
$playerReferenceAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='player_references'"
);
$oasisDeletionAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='odelete'"
);
$tradeRouteAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='traderoutes'"
);
$notificationAutoIncrement = (int)GlobalDB::getInstance()->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications'"
);
$mailAutoIncrement = (int)GlobalDB::getInstance()->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mailServer'"
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
$poisonResearchTask = 2000000002;
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
    expect_same(
        '1|Simulated worker crash.',
        (string)$db->fetchScalar(
            "SELECT CONCAT(attempts, '|', last_error) FROM scheduled_task_failures
             WHERE task_table='research' AND task_id=$researchTask"
        ),
        'research crash recorded for bounded retry'
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
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM scheduled_task_failures WHERE task_table='research' AND task_id=$researchTask"),
        'successful research retry clears failure ledger'
    );

    $db->query("INSERT INTO research (id, kid, nr, mode, end_time) VALUES ($poisonResearchTask, $researchKid, 2, 0, 0)");
    for ($attempt = 1; $attempt <= 5; ++$attempt) {
        try {
            TransactionalTask::consume('research', $poisonResearchTask, function (): void {
                throw new RuntimeException('Poison research task.');
            });
            throw new RuntimeException('Poison research task was not rejected.');
        } catch (RuntimeException $e) {
            expect_same('Poison research task.', $e->getMessage(), "poison research attempt $attempt");
        }
    }
    expect_same(
        '0|5|Poison research task.',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM research WHERE id=$poisonResearchTask), '|', attempts, '|', last_error
            ) FROM scheduled_task_failures WHERE task_table='research' AND task_id=$poisonResearchTask"
        ),
        'poison research task quarantined with recoverable payload after retry limit'
    );
} finally {
    $db->query("DELETE FROM scheduled_task_failures WHERE task_table='research' AND task_id IN ($researchTask, $poisonResearchTask)");
    $db->query("DELETE FROM research WHERE id IN ($researchTask, $poisonResearchTask) OR kid=$researchKid");
    $db->query("DELETE FROM smithy WHERE kid=$researchKid");
    $db->query("ALTER TABLE research AUTO_INCREMENT=$researchAutoIncrement");
}

$scheduledOwner = 2000000008;
$scheduledVillage = 2000000022;
$scheduledAlliance = 2000000001;
$trainingTask = 2000000001;
$allianceBonusTask = 2000000001;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$scheduledOwner"),
        'scheduled-task fixture user ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$scheduledVillage"),
        'scheduled-task fixture village ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM alidata WHERE id=$scheduledAlliance"),
        'scheduled-task fixture alliance ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM training WHERE id=$trainingTask"),
        'training fixture task ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM alliance_bonus_upgrade_queue WHERE id=$allianceBonusTask"),
        'alliance bonus fixture task ID available'
    );
    $db->query("INSERT INTO alidata (id, name, tag) VALUES ($scheduledAlliance, 'OV Scheduled', 'OVS')");
    $db->query("INSERT INTO users (id, uuid, aid, name, password, email, race, kid, desc1, desc2, note)
        VALUES ($scheduledOwner, 'ov-regression-scheduled', $scheduledAlliance, 'OVScheduled', 'x', '', 1, $scheduledVillage, '', '', '')");
    $lastUpdate = miliseconds();
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($scheduledVillage, $scheduledOwner, 3, 'OV Scheduled Village', 1, 0, 0,
         0, 0, 0, 0, 0, 0, 1000000, 1000, 1000, 1000000, 0, $lastUpdate, " . time() . ", 0)");
    $db->query("INSERT INTO fdata (kid) VALUES ($scheduledVillage)");
    $db->query("INSERT INTO units (kid, race) VALUES ($scheduledVillage, 1)");
    $db->query("INSERT INTO training (id, kid, nr, num, item_id, training_time, commence, end_time)
        VALUES ($trainingTask, $scheduledVillage, 1, 3, 19, 0, 0, 0)");

    $automation = Automation::getInstance();
    expect_true($automation->processTrainingTask($trainingTask), 'training task processed');
    expect_same(
        '0|3|3',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM training WHERE id=$trainingTask), '|',
                (SELECT u1 FROM units WHERE kid=$scheduledVillage), '|', upkeep
            ) FROM vdata WHERE kid=$scheduledVillage"
        ),
        'training queue, troops, and upkeep commit together'
    );
    expect_same(false, $automation->processTrainingTask($trainingTask), 'duplicate training delivery ignored');
    expect_same(3, (int)$db->fetchScalar("SELECT u1 FROM units WHERE kid=$scheduledVillage"), 'training effect not duplicated');

    $db->query("INSERT INTO alliance_bonus_upgrade_queue (id, aid, type, time)
        VALUES ($allianceBonusTask, $scheduledAlliance, 1, 0)");
    expect_true($automation->processAllianceBonusTask($allianceBonusTask), 'alliance bonus task processed');
    expect_same(
        '0|1|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM alliance_bonus_upgrade_queue WHERE id=$allianceBonusTask), '|',
                (SELECT training_bonus_level FROM alidata WHERE id=$scheduledAlliance), '|',
                pending_training_alliance_bonus_unlock_animation
            ) FROM users WHERE id=$scheduledOwner"
        ),
        'alliance bonus queue and effects commit together'
    );
    expect_same(false, $automation->processAllianceBonusTask($allianceBonusTask), 'duplicate alliance bonus delivery ignored');
    expect_same(
        1,
        (int)$db->fetchScalar("SELECT training_bonus_level FROM alidata WHERE id=$scheduledAlliance"),
        'alliance bonus effect not duplicated'
    );
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE training AUTO_INCREMENT=$trainingAutoIncrement");
    $db->query("ALTER TABLE alidata AUTO_INCREMENT=$allianceAutoIncrement");
    $db->query("ALTER TABLE alliance_bonus_upgrade_queue AUTO_INCREMENT=$allianceBonusQueueAutoIncrement");
}

$allianceLeaveAid = 2000000040;
$allianceLeavingUid = 2000000041;
$allianceRemainingUid = 2000000042;
$allianceFounderUid = 2000000043;
$allianceInviteeUid = 2000000044;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM users WHERE id IN ($allianceLeavingUid, $allianceRemainingUid, $allianceFounderUid, $allianceInviteeUid)"
        ),
        'alliance leave fixture user IDs available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM alidata WHERE id=$allianceLeaveAid"),
        'alliance leave fixture alliance ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM ali_log WHERE aid=$allianceLeaveAid"),
        'alliance leave fixture log IDs available'
    );
    $surroundingBeforeLeave = (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=1");
    $surroundingBeforeFounderJoin = (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=3");
    $surroundingBeforeInviteJoin = (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=4");
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid IN (3, 4)"),
        'alliance join fixture village IDs available'
    );
    $db->query("INSERT INTO alidata (id, name, tag) VALUES ($allianceLeaveAid, 'OV Leave Alliance', 'OVL')");
    $db->query("INSERT INTO users (id, uuid, aid, name, password, email, race, kid, desc1, desc2, note)
        VALUES
        ($allianceLeavingUid, 'ov-regression-alliance-leaver', $allianceLeaveAid, 'OVAllianceLeaver', 'x', '', 1, 1, '', '', ''),
        ($allianceRemainingUid, 'ov-regression-alliance-remaining', $allianceLeaveAid, 'OVAllianceRemaining', 'x', '', 1, 2, '', '', ''),
        ($allianceFounderUid, 'ov-regression-alliance-founder', 0, 'OVAllianceFounder', 'x', '', 1, 3, '', '', ''),
        ($allianceInviteeUid, 'ov-regression-alliance-invitee', 0, 'OVAllianceInvitee', 'x', '', 1, 4, '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, isWW, isFarm, expandedfrom)
        VALUES
        (3, $allianceFounderUid, 3, 'OV Alliance Founder Village', 1, 0, 0, 0, 0, 0, 0, 0, 0, 1000,
         0, 0, 1000, 0, " . miliseconds() . ", " . time() . ", 0, 0, 0),
        (4, $allianceInviteeUid, 3, 'OV Alliance Invitee Village', 1, 0, 0, 0, 0, 0, 0, 0, 0, 1000,
         0, 0, 1000, 0, " . miliseconds() . ", " . time() . ", 0, 0, 0)");

    $allianceModel = new AllianceModel();
    $allianceModel->leaveAlliance($allianceLeavingUid, $allianceLeaveAid);
    expect_same(
        '0|' . $allianceLeaveAid,
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT aid FROM users WHERE id=$allianceLeavingUid), '|',
                (SELECT aid FROM users WHERE id=$allianceRemainingUid)
            )"
        ),
        'alliance leave updates membership'
    );
    expect_same(
        "2|2:$allianceLeavingUid:OVAllianceLeaver",
        (string)$db->fetchScalar(
            "SELECT CONCAT(type, '|', data) FROM ali_log WHERE aid=$allianceLeaveAid ORDER BY id DESC LIMIT 1"
        ),
        'alliance leave log preserves departing player name'
    );
    expect_same(
        $surroundingBeforeLeave + 1,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=1"),
        'alliance leave records surrounding event'
    );
    $allianceLeaveSurrounding = $db->query(
        "SELECT x, y, type, params, time FROM surrounding WHERE kid=1 ORDER BY id DESC LIMIT 1"
    )->fetch_assoc();
    $allianceLeaveCoordinates = Formulas::kid2xy(1);
    expect_same((int)$allianceLeaveCoordinates['x'], (int)$allianceLeaveSurrounding['x'], 'alliance leave surrounding x coordinate');
    expect_same((int)$allianceLeaveCoordinates['y'], (int)$allianceLeaveSurrounding['y'], 'alliance leave surrounding y coordinate');
    expect_same(NoticeHelper::SURROUNDING_ALLIANCE, (int)$allianceLeaveSurrounding['type'], 'alliance leave surrounding event type');
    expect_same(
        "$allianceLeavingUid:OVAllianceLeaver:$allianceLeaveAid:0",
        $allianceLeaveSurrounding['params'],
        'alliance leave surrounding payload'
    );
    expect_true((int)$allianceLeaveSurrounding['time'] > 0, 'alliance leave surrounding timestamp');

    $createdAllianceAid = (int)$allianceModel->createAlliance($allianceFounderUid, 'OV Created Alliance', 'OVC');
    expect_true($createdAllianceAid > 0, 'alliance founder creates alliance');
    expect_same(
        $createdAllianceAid,
        (int)$db->fetchScalar("SELECT aid FROM users WHERE id=$allianceFounderUid"),
        'alliance founder joins created alliance'
    );
    expect_same(
        $surroundingBeforeFounderJoin + 1,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=3"),
        'alliance creation records surrounding event'
    );
    $allianceFounderSurrounding = $db->query(
        "SELECT x, y, type, params, time FROM surrounding WHERE kid=3 ORDER BY id DESC LIMIT 1"
    )->fetch_assoc();
    $allianceFounderCoordinates = Formulas::kid2xy(3);
    expect_same((int)$allianceFounderCoordinates['x'], (int)$allianceFounderSurrounding['x'], 'alliance creation surrounding x coordinate');
    expect_same((int)$allianceFounderCoordinates['y'], (int)$allianceFounderSurrounding['y'], 'alliance creation surrounding y coordinate');
    expect_same(NoticeHelper::SURROUNDING_ALLIANCE, (int)$allianceFounderSurrounding['type'], 'alliance creation surrounding event type');
    expect_same(
        "$allianceFounderUid:OVAllianceFounder:0:$createdAllianceAid",
        $allianceFounderSurrounding['params'],
        'alliance creation surrounding payload'
    );
    expect_true((int)$allianceFounderSurrounding['time'] > 0, 'alliance creation surrounding timestamp');

    $db->query("UPDATE alidata SET max=10 WHERE id=$createdAllianceAid");
    $db->query("INSERT INTO ali_invite (from_uid, aid, uid) VALUES ($allianceFounderUid, $createdAllianceAid, $allianceInviteeUid)");
    $inviteId = (int)$db->lastInsertId();
    expect_same($createdAllianceAid, (int)$allianceModel->acceptInvite($allianceInviteeUid, $inviteId), 'alliance invitation accepted');
    expect_same(
        $createdAllianceAid,
        (int)$db->fetchScalar("SELECT aid FROM users WHERE id=$allianceInviteeUid"),
        'invited player joins alliance'
    );
    expect_same(
        $surroundingBeforeInviteJoin + 1,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE kid=4"),
        'alliance invitation records surrounding event'
    );
    $allianceInviteeSurrounding = $db->query(
        "SELECT x, y, type, params, time FROM surrounding WHERE kid=4 ORDER BY id DESC LIMIT 1"
    )->fetch_assoc();
    $allianceInviteeCoordinates = Formulas::kid2xy(4);
    expect_same((int)$allianceInviteeCoordinates['x'], (int)$allianceInviteeSurrounding['x'], 'alliance invitation surrounding x coordinate');
    expect_same((int)$allianceInviteeCoordinates['y'], (int)$allianceInviteeSurrounding['y'], 'alliance invitation surrounding y coordinate');
    expect_same(NoticeHelper::SURROUNDING_ALLIANCE, (int)$allianceInviteeSurrounding['type'], 'alliance invitation surrounding event type');
    expect_same(
        "$allianceInviteeUid:OVAllianceInvitee:0:$createdAllianceAid",
        $allianceInviteeSurrounding['params'],
        'alliance invitation surrounding payload'
    );
    expect_true((int)$allianceInviteeSurrounding['time'] > 0, 'alliance invitation surrounding timestamp');
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE alidata AUTO_INCREMENT=$allianceAutoIncrement");
    $db->query("ALTER TABLE ali_log AUTO_INCREMENT=$aliLogAutoIncrement");
    $db->query("ALTER TABLE surrounding AUTO_INCREMENT=$surroundingAutoIncrement");
    $db->query("ALTER TABLE ali_invite AUTO_INCREMENT=$aliInviteAutoIncrement");
}

$merchantOwner = 2000000009;
$merchantOrigin = 2000000023;
$merchantVillage = 2000000024;
$merchantTask = 2000000001;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$merchantOwner"),
        'merchant fixture user ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$merchantVillage"),
        'merchant fixture village ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM send WHERE id=$merchantTask"),
        'merchant fixture task ID available'
    );
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, desc1, desc2, note)
        VALUES ($merchantOwner, 'ov-regression-merchant', 'OVMerchant', 'x', '', 1, $merchantVillage, '', '', '')");
    $lastUpdate = miliseconds();
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($merchantVillage, $merchantOwner, 3, 'OV Merchant Village', 1, 0, 0,
         100, 100, 100, 0, 0, 0, 1000000, 100, 0, 1000000, 0, $lastUpdate, " . time() . ", 0)");
    $db->query("INSERT INTO fdata (kid) VALUES ($merchantVillage)");
    $db->query("INSERT INTO send (id, kid, to_kid, wood, clay, iron, crop, x, mode, end_time)
        VALUES ($merchantTask, $merchantOrigin, $merchantVillage, 10, 20, 30, 40, 1, 1, 0)");

    $market = new MarketPlaceProcessor();
    expect_true($market->processRow(['id' => $merchantTask]), 'merchant task processed');
    expect_same(
        '0|90.0000|80.0000|70.0000|60.0000|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM send WHERE id=$merchantTask), '|', wood, '|', clay, '|', iron, '|', crop, '|',
                (SELECT COUNT(*) FROM send WHERE kid=$merchantVillage AND to_kid=$merchantOrigin AND mode=0)
            ) FROM vdata WHERE kid=$merchantVillage"
        ),
        'merchant queue, resources, and outbound route commit together'
    );
    expect_same(false, $market->processRow(['id' => $merchantTask]), 'duplicate merchant delivery ignored');
    expect_same(
        '90.0000|80.0000|70.0000|60.0000|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT(wood, '|', clay, '|', iron, '|', crop, '|',
                (SELECT COUNT(*) FROM send WHERE kid=$merchantVillage AND to_kid=$merchantOrigin AND mode=0))
             FROM vdata WHERE kid=$merchantVillage"
        ),
        'merchant effect not duplicated'
    );
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE send AUTO_INCREMENT=$sendAutoIncrement");
}

$returnOwner = 2000000010;
$returnVillage = 2000000025;
$returnOrigin = 2000000026;
$returnTask = 2000000001;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$returnOwner"),
        'return-movement fixture user ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$returnVillage"),
        'return-movement fixture village ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM movement WHERE id=$returnTask"),
        'return-movement fixture task ID available'
    );
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, desc1, desc2, note)
        VALUES ($returnOwner, 'ov-regression-return', 'OVReturn', 'x', '', 1, $returnVillage, '', '', '')");
    $lastUpdate = miliseconds();
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($returnVillage, $returnOwner, 3, 'OV Return Village', 1, 0, 0,
         0, 0, 0, 0, 0, 0, 1000000, 0, 0, 1000000, 0, $lastUpdate, " . time() . ", 0)");
    $db->query("INSERT INTO fdata (kid) VALUES ($returnVillage)");
    $db->query("INSERT INTO units (kid, race) VALUES ($returnVillage, 1)");
    $db->query("INSERT INTO movement
        (id, kid, to_kid, race, u1, mode, attack_type, start_time, end_time, data)
        VALUES ($returnTask, $returnOrigin, $returnVillage, 1, 5, 1, 3, 0, 0, '')");

    $automation = Automation::getInstance();
    expect_true($automation->processMovementTask($returnTask), 'return movement processed');
    expect_same(
        '0|5',
        (string)$db->fetchScalar(
            "SELECT CONCAT((SELECT COUNT(*) FROM movement WHERE id=$returnTask), '|', u1)
             FROM units WHERE kid=$returnVillage"
        ),
        'return movement queue and troop arrival commit together'
    );
    expect_same(false, $automation->processMovementTask($returnTask), 'duplicate return movement ignored');
    expect_same(5, (int)$db->fetchScalar("SELECT u1 FROM units WHERE kid=$returnVillage"), 'return movement troops not duplicated');
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE movement AUTO_INCREMENT=$movementAutoIncrement");
}

$queuedMessageOwner = 2000000011;
$buyGoldMessageTask = 2000000001;
$banTask = 2000000001;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$queuedMessageOwner"),
        'queued-message fixture user ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM buyGoldMessages WHERE id=$buyGoldMessageTask"),
        'purchase-message fixture task ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM banQueue WHERE id=$banTask"),
        'ban fixture task ID available'
    );
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, access, kid, desc1, desc2, note)
        VALUES ($queuedMessageOwner, 'ov-regression-queued-message', 'OVQueue', 'x', '', 1, 2, 1, '', '', '')");
    $db->query("INSERT INTO infobox (forAll, uid, type, params, showFrom, showTo)
        VALUES (0, $queuedMessageOwner, 14, '', 0, 0)");
    $db->query("INSERT INTO buyGoldMessages (id, uid, gold, type, trackingCode)
        VALUES ($buyGoldMessageTask, $queuedMessageOwner, 50, 1, 'OV-REGRESSION')");
    $db->query("INSERT INTO banQueue (id, uid, reason, time, end)
        VALUES ($banTask, $queuedMessageOwner, 'OV regression', 0, 1)");

    $automation = Automation::getInstance();
    expect_true($automation->processBuyGoldMessageTask($buyGoldMessageTask), 'purchase message task processed');
    expect_same(
        '0|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM buyGoldMessages WHERE id=$buyGoldMessageTask), '|', COUNT(*)
            ) FROM mdata WHERE to_uid=$queuedMessageOwner"
        ),
        'purchase queue and player message commit together'
    );
    expect_same(false, $automation->processBuyGoldMessageTask($buyGoldMessageTask), 'duplicate purchase message ignored');
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM mdata WHERE to_uid=$queuedMessageOwner"), 'purchase message not duplicated');

    expect_true($automation->processBanTask($banTask), 'expired ban task processed');
    expect_same(
        '0|1|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM banQueue WHERE id=$banTask), '|', access, '|',
                (SELECT COUNT(*) FROM infobox WHERE uid=$queuedMessageOwner AND type=14)
            ) FROM users WHERE id=$queuedMessageOwner"
        ),
        'ban queue, access, and infobox state commit together'
    );
    expect_same(false, $automation->processBanTask($banTask), 'duplicate expired ban ignored');
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE infobox AUTO_INCREMENT=$infoBoxAutoIncrement");
    $db->query("ALTER TABLE buyGoldMessages AUTO_INCREMENT=$buyGoldMessageAutoIncrement");
    $db->query("ALTER TABLE banQueue AUTO_INCREMENT=$banQueueAutoIncrement");
    $db->query("ALTER TABLE mdata AUTO_INCREMENT=$messageAutoIncrement");
}

$referenceOwner = 2000000012;
$referenceInvitee = 2000000013;
$referenceTask = 2000000001;
$referenceCrashTask = 2000000002;
$inviteGold = (int)Config::getProperty('gold', 'invitePlayerGold');
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id IN ($referenceOwner, $referenceInvitee)"),
        'referral fixture users available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM player_references WHERE id IN ($referenceTask, $referenceCrashTask)"),
        'referral fixture tasks available'
    );
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, total_villages, desc1, desc2, note)
        VALUES
        ($referenceOwner, 'ov-regression-referrer', 'OVReferrer', 'x', '', 1, 1, 1, '', '', ''),
        ($referenceInvitee, 'ov-regression-invitee', 'OVInvitee', 'x', '', 1, 2, 2, '', '', '')");
    $db->query("INSERT INTO player_references (id, ref_uid, uid) VALUES
        ($referenceTask, $referenceOwner, $referenceInvitee),
        ($referenceCrashTask, $referenceOwner, $referenceInvitee)");

    $automation = Automation::getInstance();
    expect_true($automation->processReferenceTask($referenceTask), 'referral reward processed');
    expect_same(
        "1|$inviteGold",
        (string)$db->fetchScalar(
            "SELECT CONCAT(rewardGiven, '|', (SELECT gift_gold FROM users WHERE id=$referenceOwner))
             FROM player_references WHERE id=$referenceTask"
        ),
        'referral state and gold grant commit together'
    );
    expect_true($automation->processReferenceTask($referenceTask), 'replayed referral delivery ignored safely');
    expect_same(
        $inviteGold,
        (int)$db->fetchScalar("SELECT gift_gold FROM users WHERE id=$referenceOwner"),
        'replayed referral cannot duplicate gold'
    );

    try {
        TransactionalTask::mutate('player_references', $referenceCrashTask, function (array $row) use ($db, $referenceOwner): void {
            $db->query("UPDATE users SET gift_gold=gift_gold+1 WHERE id=$referenceOwner");
            throw new RuntimeException('Simulated referral worker crash.');
        });
        throw new RuntimeException('Simulated referral worker crash was not propagated.');
    } catch (RuntimeException $e) {
        expect_same('Simulated referral worker crash.', $e->getMessage(), 'referral crash propagated');
    }
    expect_same(
        "0|$inviteGold|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT rewardGiven FROM player_references WHERE id=$referenceCrashTask), '|',
                (SELECT gift_gold FROM users WHERE id=$referenceOwner), '|',
                (SELECT attempts FROM scheduled_task_failures WHERE task_table='player_references' AND task_id=$referenceCrashTask)
            )"
        ),
        'referral crash rolls back effects and preserves task'
    );
    expect_true($automation->processReferenceTask($referenceCrashTask), 'referral retry processed');
    expect_same(
        $inviteGold * 2,
        (int)$db->fetchScalar("SELECT gift_gold FROM users WHERE id=$referenceOwner"),
        'referral retry grants exactly once'
    );
} finally {
    $db->query("DELETE FROM scheduled_task_failures WHERE task_table='player_references' AND task_id IN ($referenceTask, $referenceCrashTask)");
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE player_references AUTO_INCREMENT=$playerReferenceAutoIncrement");
}

$oasisOwner = 2000000014;
$oasisVillage = 2000000027;
$oasisTarget = 2000000028;
$oasisTask = 2000000001;
$oasisCrashTask = 2000000002;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$oasisOwner"),
        'oasis-deletion fixture user available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM odelete WHERE id IN ($oasisTask, $oasisCrashTask)"),
        'oasis-deletion fixture tasks available'
    );
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, total_villages, desc1, desc2, note)
        VALUES ($oasisOwner, 'ov-regression-oasis', 'OVOasis', 'x', '', 1, $oasisVillage, 1, '', '', '')");
    $lastUpdate = miliseconds();
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($oasisVillage, $oasisOwner, 3, 'OV Oasis Village', 1, 0, 0,
         0, 0, 0, 0, 0, 0, 1000000, 1000, 0, 1000000, 0, $lastUpdate, " . time() . ", 0)");
    $db->query("INSERT INTO fdata (kid) VALUES ($oasisVillage)");
    $db->query("INSERT INTO odata
        (kid, type, did, wood, iron, clay, crop, lastmupdate, owner, loyalty)
        VALUES ($oasisTarget, 1, $oasisVillage, 0, 0, 0, 0, $lastUpdate, $oasisOwner, 100)");
    $db->query("INSERT INTO wdata (id, x, y, fieldtype, oasistype, landscape, occupied)
        VALUES ($oasisTarget, 10, 10, 3, 1, 1, 1)");
    $movementTarget = 2000000007;
    $db->query("INSERT INTO movement
        (id, kid, to_kid, race, u1, mode, attack_type, start_time, end_time, data)
        VALUES ($movementTarget, 2000000006, $oasisTarget, 1, 5, 0, 0, 0, 0, '')");
    $db->query("INSERT INTO odelete (id, kid, oid, end_time) VALUES
        ($oasisTask, $oasisVillage, $oasisTarget, 0),
        ($oasisCrashTask, $oasisVillage, $oasisTarget, 0)");

    $automation = Automation::getInstance();
    expect_true($automation->processOasisDeletionTask($oasisTask), 'oasis deletion processed');
    expect_same(
        '0|0|0|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM odelete WHERE id=$oasisTask), '|', owner, '|', did, '|',
                (SELECT mode FROM movement WHERE id=$movementTarget)
             ) FROM odata WHERE kid=$oasisTarget"
        ),
        'oasis release and incoming movement cancellation commit with queue consumption'
    );
    expect_same(false, $automation->processOasisDeletionTask($oasisTask), 'duplicate oasis deletion ignored');

    try {
        TransactionalTask::consume('odelete', $oasisCrashTask, function (array $row) use ($db, $oasisTarget): void {
            $db->query("UPDATE odata SET owner=99 WHERE kid=$oasisTarget");
            throw new RuntimeException('Simulated oasis worker crash.');
        });
        throw new RuntimeException('Simulated oasis worker crash was not propagated.');
    } catch (RuntimeException $e) {
        expect_same('Simulated oasis worker crash.', $e->getMessage(), 'oasis crash propagated');
    }
    expect_same(
        '0|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT(owner, '|', (SELECT attempts FROM scheduled_task_failures
                WHERE task_table='odelete' AND task_id=$oasisCrashTask))
             FROM odata WHERE kid=$oasisTarget"
        ),
        'oasis crash rolls back effects and preserves task'
    );
    expect_true($automation->processOasisDeletionTask($oasisCrashTask), 'oasis deletion retry processed');
    expect_same(0, (int)$db->fetchScalar("SELECT owner FROM odata WHERE kid=$oasisTarget"), 'oasis retry releases oasis once');
} finally {
    $db->query("DELETE FROM scheduled_task_failures WHERE task_table='odelete' AND task_id IN ($oasisTask, $oasisCrashTask)");
    $db->rollback();
    $db->query("DELETE FROM movement WHERE id=2000000007");
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE movement AUTO_INCREMENT=$movementAutoIncrement");
    $db->query("ALTER TABLE odelete AUTO_INCREMENT=$oasisDeletionAutoIncrement");
}

$tradeOwner = 2000000015;
$tradeOrigin = 2000000029;
$tradeDestination = 2000000030;
$tradeTask = 2000000001;
$tradeCrashTask = 2000000002;
$tradeInitialTime = time() - 10;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$tradeOwner"),
        'trade-route fixture user available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM traderoutes WHERE id IN ($tradeTask, $tradeCrashTask)"),
        'trade-route fixture tasks available'
    );
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, total_villages, desc1, desc2, note)
        VALUES ($tradeOwner, 'ov-regression-traderoute', 'OVTrade', 'x', '', 1, $tradeOrigin, 1, '', '', '')");
    $lastUpdate = miliseconds();
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($tradeOrigin, $tradeOwner, 3, 'OV Trade Origin', 1, 0, 0,
         100, 100, 100, 0, 0, 0, 1000000, 100, 0, 1000000, 0, $lastUpdate, " . time() . ", 0)");
    $db->query("INSERT INTO fdata (kid, f19, f19t) VALUES ($tradeOrigin, 2, 17)");
    $db->query("INSERT INTO traderoutes
        (id, kid, to_kid, r1, r2, r3, r4, enabled, start_hour, times, time)
        VALUES
        ($tradeTask, $tradeOrigin, $tradeDestination, 10, 20, 30, 40, 1, 3600, 1, $tradeInitialTime),
        ($tradeCrashTask, $tradeOrigin, $tradeDestination, 10, 20, 30, 40, 1, 3600, 1, $tradeInitialTime)");

    $automation = Automation::getInstance();
    expect_true($automation->processTradeRouteTask($tradeTask), 'trade route processed');
    expect_same(
        '90.0000|80.0000|70.0000|60.0000|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT(wood, '|', clay, '|', iron, '|', crop, '|',
                (SELECT COUNT(*) FROM send WHERE kid=$tradeOrigin AND to_kid=$tradeDestination AND mode=0))
             FROM vdata WHERE kid=$tradeOrigin"
        ),
        'trade route resources and merchant dispatch commit together'
    );
    expect_same(
        $tradeInitialTime + 86400,
        (int)$db->fetchScalar("SELECT time FROM traderoutes WHERE id=$tradeTask"),
        'trade route next-run timestamp advances after dispatch'
    );
    expect_true($automation->processTradeRouteTask($tradeTask), 'replayed trade route delivery ignored safely');
    expect_same(
        '90.0000|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT(wood, '|', (SELECT COUNT(*) FROM send WHERE kid=$tradeOrigin AND to_kid=$tradeDestination AND mode=0))
             FROM vdata WHERE kid=$tradeOrigin"
        ),
        'replayed trade route cannot duplicate dispatch'
    );

    try {
        TransactionalTask::mutate('traderoutes', $tradeCrashTask, function (array $row) use ($db, $tradeOrigin): void {
            $db->query("UPDATE vdata SET wood=wood-1 WHERE kid=$tradeOrigin");
            throw new RuntimeException('Simulated trade-route worker crash.');
        });
        throw new RuntimeException('Simulated trade-route worker crash was not propagated.');
    } catch (RuntimeException $e) {
        expect_same('Simulated trade-route worker crash.', $e->getMessage(), 'trade-route crash propagated');
    }
    expect_same(
        "90.0000|$tradeInitialTime|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                wood, '|', time, '|', (SELECT attempts FROM scheduled_task_failures
                    WHERE task_table='traderoutes' AND task_id=$tradeCrashTask)
             ) FROM traderoutes JOIN vdata ON traderoutes.kid=vdata.kid
             WHERE traderoutes.id=$tradeCrashTask"
        ),
        'trade-route crash rolls back resource and schedule effects'
    );
    expect_true($automation->processTradeRouteTask($tradeCrashTask), 'trade route retry processed');
    expect_same(
        2,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM send WHERE kid=$tradeOrigin AND to_kid=$tradeDestination AND mode=0"),
        'trade route retry dispatches exactly once'
    );
} finally {
    $db->query("DELETE FROM scheduled_task_failures WHERE task_table='traderoutes' AND task_id IN ($tradeTask, $tradeCrashTask)");
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE send AUTO_INCREMENT=$sendAutoIncrement");
    $db->query("ALTER TABLE traderoutes AUTO_INCREMENT=$tradeRouteAutoIncrement");
}

$notificationTask = 2000000001;
$notificationCrashTask = 2000000002;
$notificationGlobal = GlobalDB::getInstance();
$notificationKey = Notification::deliveryKey($notificationTask);
$notificationCrashKey = Notification::deliveryKey($notificationCrashTask);
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM notificationQueue WHERE id IN ($notificationTask, $notificationCrashTask)"),
        'notification fixture tasks available'
    );
    $escapedNotificationKey = $notificationGlobal->real_escape_string($notificationKey);
    $escapedNotificationCrashKey = $notificationGlobal->real_escape_string($notificationCrashKey);
    $notificationGlobal->query(
        "DELETE FROM notifications WHERE delivery_key IN ('$escapedNotificationKey', '$escapedNotificationCrashKey')"
    );
    $db->query("INSERT INTO notificationQueue (id, message, time) VALUES
        ($notificationTask, 'Runtime notification', " . time() . "),
        ($notificationCrashTask, 'Runtime crash notification', " . time() . ")");

    $automation = Automation::getInstance();
    expect_true($automation->processNotificationTask($notificationTask), 'notification task processed');
    expect_same(
        '0|1',
        (string)$db->fetchScalar("SELECT COUNT(*) FROM notificationQueue WHERE id=$notificationTask") . '|' .
            (string)$notificationGlobal->fetchScalar("SELECT COUNT(*) FROM notifications WHERE delivery_key='$escapedNotificationKey'"),
        'notification queue consumption and global delivery commit together'
    );
    expect_same(false, $automation->processNotificationTask($notificationTask), 'duplicate notification delivery ignored');

    try {
        TransactionalTask::consume('notificationQueue', $notificationCrashTask, function (array $row) use ($notificationCrashKey): void {
            Notification::notifyReal($row['message'], $notificationCrashKey);
            throw new RuntimeException('Simulated notification worker crash.');
        });
        throw new RuntimeException('Simulated notification worker crash was not propagated.');
    } catch (RuntimeException $e) {
        expect_same('Simulated notification worker crash.', $e->getMessage(), 'notification crash propagated');
    }
    expect_same(
        '1|1|1',
        (string)$db->fetchScalar("SELECT COUNT(*) FROM notificationQueue WHERE id=$notificationCrashTask") . '|' .
            (string)$notificationGlobal->fetchScalar("SELECT COUNT(*) FROM notifications WHERE delivery_key='$escapedNotificationCrashKey'") . '|' .
            (string)$db->fetchScalar(
                "SELECT attempts FROM scheduled_task_failures
                    WHERE task_table='notificationQueue' AND task_id=$notificationCrashTask"
            ),
        'notification crash preserves queue while global idempotent delivery remains'
    );
    expect_true($automation->processNotificationTask($notificationCrashTask), 'notification retry processed');
    expect_same(
        '0|1',
        (string)$db->fetchScalar("SELECT COUNT(*) FROM notificationQueue WHERE id=$notificationCrashTask") . '|' .
            (string)$notificationGlobal->fetchScalar("SELECT COUNT(*) FROM notifications WHERE delivery_key='$escapedNotificationCrashKey'"),
        'notification retry consumes queue without duplicate global delivery'
    );
} finally {
    $db->query("DELETE FROM scheduled_task_failures WHERE task_table='notificationQueue' AND task_id IN ($notificationTask, $notificationCrashTask)");
    $db->rollback();
    $notificationGlobal->query(
        "DELETE FROM notifications WHERE delivery_key IN ('$escapedNotificationKey', '$escapedNotificationCrashKey')"
    );
    $notificationGlobal->query("ALTER TABLE notifications AUTO_INCREMENT=$notificationAutoIncrement");
}

$mailDeliveryKey = 'runtime-mail-delivery-key';
$mailGlobal = GlobalDB::getInstance();
$escapedMailDeliveryKey = $mailGlobal->real_escape_string($mailDeliveryKey);
try {
    $mailGlobal->query("DELETE FROM mailServer WHERE delivery_key='$escapedMailDeliveryKey'");
    expect_true(
        Mailer::sendEmail('runtime@example.invalid', 'Runtime mail', 'Runtime mail body', 0, $mailDeliveryKey),
        'mail delivery key first enqueue'
    );
    expect_true(
        Mailer::sendEmail('runtime@example.invalid', 'Runtime mail', 'Runtime mail body', 0, $mailDeliveryKey),
        'mail delivery key replay enqueue'
    );
    expect_same(
        1,
        (int)$mailGlobal->fetchScalar("SELECT COUNT(*) FROM mailServer WHERE delivery_key='$escapedMailDeliveryKey'"),
        'mail delivery key suppresses duplicate outbox rows'
    );
} finally {
    $mailGlobal->query("DELETE FROM mailServer WHERE delivery_key='$escapedMailDeliveryKey'");
    $mailGlobal->query("ALTER TABLE mailServer AUTO_INCREMENT=$mailAutoIncrement");
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
    $completionTask = 2000000004;
    $demolitionTask = 2000000001;
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

    $db->query("INSERT INTO building_upgrade (id, kid, building_field, isMaster, start_time, commence) VALUES
        ($completionTask, $builderVillage, 1, 0, " . time() . ", " . time() . ")");
    $automation = Automation::getInstance();
    expect_true($automation->processBuildingTask($completionTask), 'building task consumed');
    expect_same(
        '0|3',
        (string)$db->fetchScalar(
            "SELECT CONCAT((SELECT COUNT(*) FROM building_upgrade WHERE id=$completionTask), '|', f1) FROM fdata WHERE kid=$builderVillage"
        ),
        'building effect and task consumption commit together'
    );
    expect_same(false, $automation->processBuildingTask($completionTask), 'duplicate building delivery ignored');
    expect_same(3, (int)$db->fetchScalar("SELECT f1 FROM fdata WHERE kid=$builderVillage"), 'building effect not duplicated');

    $db->query("INSERT INTO demolition (id, kid, building_field, end_time, complete) VALUES
        ($demolitionTask, $builderVillage, 1, " . time() . ", 0)");
    expect_true($automation->processDemolitionTask($demolitionTask), 'demolition task consumed');
    expect_same(
        '0|2',
        (string)$db->fetchScalar(
            "SELECT CONCAT((SELECT COUNT(*) FROM demolition WHERE id=$demolitionTask), '|', f1) FROM fdata WHERE kid=$builderVillage"
        ),
        'demolition effect and task consumption commit together'
    );
    expect_same(false, $automation->processDemolitionTask($demolitionTask), 'duplicate demolition delivery ignored');
    expect_same(2, (int)$db->fetchScalar("SELECT f1 FROM fdata WHERE kid=$builderVillage"), 'demolition effect not duplicated');
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE artefacts AUTO_INCREMENT=$artefactAutoIncrement");
    $db->query("ALTER TABLE building_upgrade AUTO_INCREMENT=$buildingUpgradeAutoIncrement");
    $db->query("ALTER TABLE demolition AUTO_INCREMENT=$demolitionAutoIncrement");
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

$starvationRomanOwner = 2000000016;
$starvationGaulOwner = 2000000017;
$starvationHighHeroOwner = 2000000018;
$starvationZeroHeroOwner = 2000000019;
$starvationVillages = [
    2000000031 => ['owner' => $starvationRomanOwner, 'race' => 1, 'trough' => 10, 'unit' => 'u4', 'troops' => 2, 'upkeep' => 4, 'expected' => '1|1', 'capital' => 1],
    2000000032 => ['owner' => $starvationRomanOwner, 'race' => 1, 'trough' => 15, 'unit' => 'u5', 'troops' => 2, 'upkeep' => 6, 'expected' => '1|2', 'capital' => 0],
    2000000033 => ['owner' => $starvationRomanOwner, 'race' => 1, 'trough' => 20, 'unit' => 'u6', 'troops' => 2, 'upkeep' => 8, 'expected' => '1|3', 'capital' => 0],
    2000000034 => ['owner' => $starvationRomanOwner, 'race' => 1, 'trough' => null, 'unit' => 'u4', 'troops' => 2, 'upkeep' => 4, 'expected' => '1|2', 'capital' => 0],
    2000000035 => ['owner' => $starvationGaulOwner, 'race' => 3, 'trough' => 20, 'unit' => 'u4', 'troops' => 2, 'upkeep' => 4, 'expected' => '1|2', 'capital' => 1],
    2000000036 => [
        'owner' => $starvationHighHeroOwner, 'race' => 1, 'trough' => null, 'unit' => 'u11', 'troops' => 1,
        'secondaryUnit' => 'u1', 'secondaryTroops' => 1, 'upkeep' => 7, 'crop' => -100, 'maxcrop' => 2000,
        'expected' => '0|1', 'capital' => 1, 'heroExp' => Formulas::heroExperience(10),
    ],
    2000000037 => [
        'owner' => $starvationZeroHeroOwner, 'race' => 1, 'trough' => null, 'unit' => 'u11', 'troops' => 1,
        'secondaryUnit' => 'u1', 'secondaryTroops' => 1, 'upkeep' => 7, 'crop' => -100, 'maxcrop' => 2000,
        'expected' => '0|0', 'capital' => 1, 'heroExp' => 0,
    ],
];
$starvationTransactionStarted = false;
try {
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM users WHERE id IN ($starvationRomanOwner, $starvationGaulOwner, $starvationHighHeroOwner, $starvationZeroHeroOwner)"
        ),
        'starvation fixture user IDs available'
    );
    $starvationVillageIds = implode(',', array_keys($starvationVillages));
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM vdata WHERE kid IN ($starvationVillageIds)"
        ),
        'starvation fixture village IDs available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM fdata WHERE kid IN ($starvationVillageIds)"
        ),
        'starvation fixture building IDs available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM units WHERE kid IN ($starvationVillageIds)"
        ),
        'starvation fixture troop IDs available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM hero WHERE uid IN ($starvationHighHeroOwner, $starvationZeroHeroOwner)"
        ),
        'starvation fixture hero IDs available'
    );
    expect_true(
        !TruceDay::isActive() && TruceDay::getTo() + 86400 <= time(),
        'starvation fixture is outside truce protection'
    );

    expect_true($db->begin_transaction(), 'starvation fixture transaction started');
    $starvationTransactionStarted = true;
    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, access, kid, total_villages, vacationActiveTil, desc1, desc2, note)
        VALUES
        ($starvationRomanOwner, 'ov-regression-starvation-roman', 'OVStarveRoman', 'x', '', 1, 1, 2000000031, 6, 0, '', '', ''),
        ($starvationGaulOwner, 'ov-regression-starvation-gaul', 'OVStarveGaul', 'x', '', 3, 1, 2000000035, 1, 0, '', '', ''),
        ($starvationHighHeroOwner, 'ov-regression-starvation-high-hero', 'OVStarveHighHero', 'x', '', 1, 1, 2000000036, 1, 0, '', '', ''),
        ($starvationZeroHeroOwner, 'ov-regression-starvation-zero-hero', 'OVStarveZeroHero', 'x', '', 1, 1, 2000000037, 1, 0, '', '', '')");

    foreach ($starvationVillages as $kid => $fixture) {
        $lastUpdate = miliseconds();
        $db->query("INSERT INTO vdata
            (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
             crop, cropp, maxcrop, upkeep, lastmupdate, created, isWW, isFarm, expandedfrom)
            VALUES
            ($kid, {$fixture['owner']}, 3, 'OV Starvation', {$fixture['capital']}, 0, 0, 0, 0, 0, 0, 0, 0, 1000,
             " . ($fixture['crop'] ?? -1) . ", {$fixture['upkeep']}, " . ($fixture['maxcrop'] ?? 1000) . ", {$fixture['upkeep']}, $lastUpdate, " . time() . ", 0, 0, 0)");
        if ($fixture['trough'] === null) {
            $db->query("INSERT INTO fdata (kid) VALUES ($kid)");
        } else {
            $db->query("INSERT INTO fdata (kid, f19, f19t) VALUES ($kid, {$fixture['trough']}, 41)");
        }
        $unitColumns = "kid, race, {$fixture['unit']}";
        $unitValues = "$kid, {$fixture['race']}, {$fixture['troops']}";
        if (isset($fixture['secondaryUnit'])) {
            $unitColumns .= ", {$fixture['secondaryUnit']}";
            $unitValues .= ", {$fixture['secondaryTroops']}";
        }
        $db->query("INSERT INTO units ($unitColumns) VALUES ($unitValues)");
        if (array_key_exists('heroExp', $fixture)) {
            $db->query("INSERT INTO hero (uid, kid, exp, health) VALUES ({$fixture['owner']}, $kid, {$fixture['heroExp']}, 100)");
        }
    }

    foreach ($starvationVillages as $kid => $fixture) {
        new Starvation($kid);
        expect_same(
            $fixture['expected'],
            (string)$db->fetchScalar(
                "SELECT CONCAT((SELECT {$fixture['unit']} FROM units WHERE kid=$kid), '|', upkeep)
                 FROM vdata WHERE kid=$kid"
            ),
            "starvation upkeep and troop reduction for village $kid"
        );
    }
    expect_same(
        '0|0|1|1100',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                CAST(ROUND((SELECT health FROM hero WHERE uid=$starvationHighHeroOwner), 0) AS UNSIGNED), '|',
                (SELECT u11 FROM units WHERE kid=2000000036), '|',
                (SELECT u1 FROM units WHERE kid=2000000036), '|',
                CAST(ROUND((SELECT crop FROM vdata WHERE kid=2000000036), 0) AS SIGNED)
            )"
        ),
        'high-level hero starvation cost and death'
    );
    expect_same(
        '0|0|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                CAST(ROUND((SELECT health FROM hero WHERE uid=$starvationZeroHeroOwner), 0) AS UNSIGNED), '|',
                (SELECT u11 FROM units WHERE kid=2000000037), '|',
                (SELECT u1 FROM units WHERE kid=2000000037)
            )"
        ),
        'level-zero hero starvation control'
    );
} finally {
    if ($starvationTransactionStarted) {
        $db->rollback();
    }
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
}

echo "Runtime regression checks passed.\n";
