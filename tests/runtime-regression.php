<?php

declare(strict_types=1);

use Core\Config;
use Core\Automation;
use Core\Database\DB;
use Core\Database\GlobalDB;
use Core\Helper\Mailer;
use Core\Helper\Notification;
use Core\Jobs\QuarantineTaskException;
use Core\Jobs\TransactionalTask;
use Core\Jobs\WorkerRegistry;
use Core\Security\Password;
use Controller\RallyPoint\RallyPointHTML;
use Controller\RallyPoint\Simulator;
use Game\Buildings\BuildingHelper;
use Game\Formulas;
use Game\GoldHelper;
use Game\NoticeHelper;
use Game\Starvation;
use Game\TruceDay;
use Model\AuctionModel;
use Model\AccountDeleter;
use Model\AllianceModel;
use Model\AutoExtendModel;
use Model\BattleModel;
use Model\BattleSetter;
use Model\BreweryModel;
use Model\CelebrationModel;
use Model\MasterBuilder;
use Model\MarketPlaceProcessor;
use Model\MovementsModel;
use Model\NatarsModel;
use Model\OasesModel;
use Model\OptionModel;
use Model\RallyPoint\RallyPointModel;
use Model\Units;
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
expect_same(125, (int)Formulas::getCelebrationMaxCP(false), 'x10 small celebration CP limit');
expect_same(500, (int)Formulas::getCelebrationMaxCP(true), 'x10 large celebration CP limit');
expect_same(20, Formulas::buildingMaxLvl(35, true), 'Brewery maximum level');
expect_same(51840, Formulas::getFestivalDuration(), 'x10 Brewery festival duration');

$catapultTableRow = [
    'owner' => ['villageName' => 'Brewery regression village'],
    'units' => [19 => 20],
];
$catapultTableSettings = [
    'noCoordinates' => true,
    'noVillageLink' => true,
    'showTroopsNum' => true,
    'showTroopsType' => true,
    'cata' => [[
        'type' => 'cata',
        'count' => 20,
        'level' => 20,
        'isRaid' => false,
        'randomOnly' => true,
    ]],
];
$catapultTable = (new RallyPointHTML())->getMovementTable($catapultTableRow, $catapultTableSettings);
expect_same(
    false,
    str_contains($catapultTable, '<option value="10">'),
    'Brewery festival hides explicit catapult targets'
);
$catapultTableSettings['cata'][0]['randomOnly'] = false;
$catapultTable = (new RallyPointHTML())->getMovementTable($catapultTableRow, $catapultTableSettings);
expect_true(
    str_contains($catapultTable, '<option value="10">'),
    'ordinary catapult targeting keeps explicit targets'
);

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
$noticeAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ndata'"
);
$casualtiesAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='casualties'"
);
$multiAccountLogAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='multiaccount_log'"
);
$farmListLastReportsAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='farmlist_last_reports'"
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
$autoExtendAutoIncrement = (int)$db->fetchScalar(
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='autoExtend'"
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
$terminalResearchTask = 2000000003;
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM smithy WHERE kid=$researchKid"),
        'research fixture village ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM research WHERE id IN ($researchTask, $poisonResearchTask, $terminalResearchTask)"
        ),
        'research fixture task IDs available'
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

    $db->query("INSERT INTO research (id, kid, nr, mode, end_time) VALUES ($terminalResearchTask, $researchKid, 3, 0, 0)");
    expect_true(
        TransactionalTask::consume(
            'research',
            $terminalResearchTask,
            function () use ($db, $researchKid): void {
                $db->query("UPDATE smithy SET u1=u1+10 WHERE kid=$researchKid");
                throw new QuarantineTaskException('Structurally invalid research task.');
            }
        ),
        'structurally invalid task quarantined immediately'
    );
    expect_same(
        '0|1|5|Structurally invalid research task.',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM research WHERE id=$terminalResearchTask), '|',
                (SELECT u1 FROM smithy WHERE kid=$researchKid), '|', attempts, '|', last_error
            ) FROM scheduled_task_failures WHERE task_table='research' AND task_id=$terminalResearchTask"
        ),
        'terminal quarantine rolls back effects and retains recovery metadata'
    );
    expect_same(
        false,
        TransactionalTask::consume('research', $terminalResearchTask, static function (): void {
        }),
        'duplicate terminal task delivery ignored'
    );
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM scheduled_task_failures
             WHERE task_table='research' AND task_id=$terminalResearchTask AND attempts=5"
        ),
        'terminal quarantine ledger survives duplicate delivery'
    );
} finally {
    $db->query(
        "DELETE FROM scheduled_task_failures
         WHERE task_table='research' AND task_id IN ($researchTask, $poisonResearchTask, $terminalResearchTask)"
    );
    $db->query("DELETE FROM research WHERE id IN ($researchTask, $poisonResearchTask, $terminalResearchTask) OR kid=$researchKid");
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
$malformedReturnTask = 2000000002;
$malformedNatureReturnTask = 2000000003;
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
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM movement WHERE id IN ($returnTask, $malformedReturnTask, $malformedNatureReturnTask)"
        ),
        'return-movement fixture task IDs available'
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
    $db->query("INSERT INTO movement
        (id, kid, to_kid, race, u1, u2, mode, attack_type, start_time, end_time, data)
        VALUES ($malformedReturnTask, $returnOrigin, $returnVillage, 1, 7, -2, 1, 3, 0, 123000, '10,20,30,40,0')");
    $db->query("INSERT INTO movement
        (id, kid, to_kid, race, u2, mode, attack_type, start_time, end_time, data)
        VALUES ($malformedNatureReturnTask, $returnOrigin, $returnVillage, 5, -1, 1, 3, 0, 0, '')");
    $malformedReturnResult = $db->query("SELECT * FROM movement WHERE id=$malformedReturnTask");
    $malformedReturnPayload = $malformedReturnResult->fetch_assoc();

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

    expect_true($automation->processMovementTask($malformedReturnTask), 'malformed return movement quarantined');
    expect_same(
        '0|5|0|0|0|0|0|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM movement WHERE id=$malformedReturnTask), '|',
                u.u1, '|', u.u2, '|', FLOOR(v.wood), '|', FLOOR(v.clay), '|', FLOOR(v.iron), '|', FLOOR(v.crop), '|',
                v.lastReturn
            ) FROM units u JOIN vdata v ON v.kid=u.kid WHERE u.kid=$returnVillage"
        ),
        'malformed return movement applies no troop, resource, or timestamp effects'
    );
    $malformedFailureResult = $db->query(
        "SELECT attempts, payload, last_error FROM scheduled_task_failures
         WHERE task_table='movement' AND task_id=$malformedReturnTask"
    );
    $malformedFailure = $malformedFailureResult->fetch_assoc();
    expect_same(5, (int)$malformedFailure['attempts'], 'malformed return movement has terminal attempt count');
    expect_same(
        'Malformed return movement: u2 cannot be negative.',
        $malformedFailure['last_error'],
        'malformed return movement records a stable reason'
    );
    expect_same(
        $malformedReturnPayload,
        json_decode($malformedFailure['payload'], true, 512, JSON_THROW_ON_ERROR),
        'malformed return movement retains its complete payload'
    );
    expect_same(
        false,
        $automation->processMovementTask($malformedReturnTask),
        'duplicate malformed return delivery ignored'
    );
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM scheduled_task_failures
             WHERE task_table='movement' AND task_id=$malformedReturnTask AND attempts=5"
        ),
        'malformed return quarantine survives duplicate delivery'
    );
    expect_true(
        $automation->processMovementTask($malformedNatureReturnTask),
        'malformed nature return movement quarantined'
    );
    expect_same(
        '0|5|Malformed return movement: u2 cannot be negative.',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM movement WHERE id=$malformedNatureReturnTask), '|', attempts, '|', last_error
            ) FROM scheduled_task_failures
              WHERE task_table='movement' AND task_id=$malformedNatureReturnTask"
        ),
        'nature return validation runs before the race shortcut'
    );
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE movement AUTO_INCREMENT=$movementAutoIncrement");
}

$dispatchOwner = 2000000100;
$dispatchSource = 2000000101;
$dispatchTarget = 2000000102;
$dispatchEnforcement = 2000000103;
$dispatchFixtureCommitted = false;
$dispatchWorkers = [];
$dispatchBarrierFiles = [];
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$dispatchOwner"),
        'movement-dispatch fixture user ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$dispatchSource"),
        'movement-dispatch fixture village ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM enforcement WHERE id=$dispatchEnforcement"),
        'movement-dispatch fixture enforcement ID available'
    );
    expect_true($db->begin_transaction(), 'movement-dispatch fixture transaction started');
    $nowMs = miliseconds();
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, desc1, desc2, note)
        VALUES ($dispatchOwner, 'ov-regression-dispatch', 'OVDispatch', 'x', '', 1, $dispatchSource, '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($dispatchSource, $dispatchOwner, 3, 'OV Dispatch Source', 1, 0, 0,
         1000, 1000, 1000, 0, 0, 0, 1000000, 1000, 0, 1000000, 0, $nowMs, " . time() . ", 0)");
    $db->query("INSERT INTO units (kid, race, u1) VALUES ($dispatchSource, 1, 5)");
    $db->query("INSERT INTO enforcement (id, uid, kid, to_kid, race, u1)
        VALUES ($dispatchEnforcement, $dispatchOwner, $dispatchSource, $dispatchTarget, 1, 3)");
    expect_true($db->commit(), 'movement-dispatch fixture committed');
    $dispatchFixtureCommitted = true;

    expect_same(
        false,
        Units::debitIfAvailable($dispatchSource, [1 => 6]),
        'troop debit rejects insufficient units'
    );
    expect_same(5, (int)$db->fetchScalar("SELECT u1 FROM units WHERE kid=$dispatchSource"), 'rejected troop debit changes nothing');
    expect_same(
        false,
        Units::debitIfAvailable($dispatchSource, [1 => -1]),
        'troop debit rejects negative counts'
    );
    expect_same(
        false,
        RallyPointModel::debitEnforcementIfAvailable($dispatchEnforcement, [1 => 4]),
        'reinforcement debit rejects insufficient units'
    );
    expect_same(
        3,
        (int)$db->fetchScalar("SELECT u1 FROM enforcement WHERE id=$dispatchEnforcement"),
        'rejected reinforcement debit changes nothing'
    );

    $movement = new MovementsModel();
    $failingUnits = array_fill(1, 11, 0);
    $failingUnits[1] = 2;
    $failingMovement = $movement->addMovementWithSourceMutation(
        static function () use ($db, $dispatchSource, $dispatchEnforcement, $failingUnits): bool {
            $resourceDebit = $db->query(
                "UPDATE vdata SET wood=wood-100, clay=clay-100, iron=iron-100, crop=crop-100
                 WHERE kid=$dispatchSource AND wood>=100 AND clay>=100 AND iron>=100 AND crop>=100"
            );
            if (!$resourceDebit || $db->affectedRows() !== 1) {
                return false;
            }
            if (!RallyPointModel::debitEnforcementIfAvailable($dispatchEnforcement, [1 => 1])) {
                return false;
            }

            return Units::debitIfAvailable($dispatchSource, $failingUnits);
        },
        $dispatchSource,
        $dispatchTarget,
        1,
        $failingUnits,
        0,
        0,
        0,
        0,
        0,
        MovementsModel::ATTACKTYPE_RAID,
        $nowMs,
        $nowMs + 1000,
        str_repeat('x', 256)
    );
    expect_same(0, (int)$failingMovement, 'failed movement insert reports failure');
    expect_same(
        '5|1000|1000|1000|1000|3|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                u.u1, '|', FLOOR(v.wood), '|', FLOOR(v.clay), '|', FLOOR(v.iron), '|', FLOOR(v.crop), '|',
                (SELECT u1 FROM enforcement WHERE id=$dispatchEnforcement), '|',
                (SELECT COUNT(*) FROM movement WHERE kid=$dispatchSource)
            ) FROM units u JOIN vdata v ON v.kid=u.kid WHERE u.kid=$dispatchSource"
        ),
        'failed movement insert rolls back troops, resources, and reinforcement state'
    );

    expect_true($db->begin_transaction(), 'full reinforcement-debit test transaction started');
    expect_true(
        RallyPointModel::debitEnforcementIfAvailable($dispatchEnforcement, [1 => 3]),
        'full reinforcement debit succeeds'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM enforcement WHERE id=$dispatchEnforcement"),
        'empty enforcement row removed'
    );
    expect_true($db->rollback(), 'full reinforcement-debit test rolled back');
    expect_same(
        3,
        (int)$db->fetchScalar("SELECT u1 FROM enforcement WHERE id=$dispatchEnforcement"),
        'reinforcement rollback restores source row'
    );

    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $barrierPath = tempnam(sys_get_temp_dir(), 'ov-dispatch-start-');
    $firstReadyPath = tempnam(sys_get_temp_dir(), 'ov-dispatch-ready-');
    $secondReadyPath = tempnam(sys_get_temp_dir(), 'ov-dispatch-ready-');
    expect_true($barrierPath !== false, 'movement-dispatch start barrier created');
    expect_true($firstReadyPath !== false, 'first movement-dispatch ready signal created');
    expect_true($secondReadyPath !== false, 'second movement-dispatch ready signal created');
    $dispatchBarrierFiles = [$barrierPath, $firstReadyPath, $secondReadyPath];
    $readyPaths = [$firstReadyPath, $secondReadyPath];
    for ($i = 0; $i < 2; ++$i) {
        $pipes = [];
        $process = proc_open(
            [
                'php',
                '/app/tests/movement-dispatch-worker.php',
                (string)$dispatchSource,
                (string)$dispatchTarget,
                $barrierPath,
                $readyPaths[$i],
            ],
            $descriptorSpec,
            $pipes
        );
        expect_true(is_resource($process), "movement-dispatch worker $i started");
        $dispatchWorkers[] = ['process' => $process, 'pipes' => $pipes];
    }

    $readyDeadline = microtime(true) + 10;
    while (
        (@file_get_contents($firstReadyPath) !== 'ready' || @file_get_contents($secondReadyPath) !== 'ready')
        && microtime(true) < $readyDeadline
    ) {
        usleep(1000);
    }
    expect_same('ready', @file_get_contents($firstReadyPath), 'first movement-dispatch worker ready');
    expect_same('ready', @file_get_contents($secondReadyPath), 'second movement-dispatch worker ready');
    expect_true(file_put_contents($barrierPath, 'go', LOCK_EX) !== false, 'movement-dispatch workers released together');

    $dispatchOutcomes = [];
    foreach ($dispatchWorkers as $i => &$worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $worker['process'] = null;
        $worker['pipes'] = [];
        expect_same(0, $exitCode, "movement-dispatch worker $i exited successfully: $stderr");
        $dispatchOutcomes[] = (int)$stdout;
    }
    unset($worker);
    sort($dispatchOutcomes);
    expect_same(0, $dispatchOutcomes[0], 'one concurrent movement dispatch rejected');
    expect_true($dispatchOutcomes[1] > 0, 'one concurrent movement dispatch committed');
    expect_same(
        '1|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT(u1, '|',
                (SELECT COUNT(*) FROM movement
                 WHERE kid=$dispatchSource AND to_kid=$dispatchTarget AND attack_type=" . MovementsModel::ATTACKTYPE_RAID . "))
             FROM units WHERE kid=$dispatchSource"
        ),
        'concurrent dispatch cannot overdraw troops or duplicate movement'
    );
} finally {
    foreach ($dispatchWorkers as &$worker) {
        if (isset($worker['pipes']) && is_array($worker['pipes'])) {
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
        if (isset($worker['process']) && is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
    }
    unset($worker);
    foreach ($dispatchBarrierFiles as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
    $db->rollback();
    if ($dispatchFixtureCommitted) {
        $db->query("DELETE FROM movement WHERE kid=$dispatchSource OR to_kid=$dispatchTarget");
        $db->query("DELETE FROM enforcement WHERE id=$dispatchEnforcement");
        $db->query("DELETE FROM units WHERE kid=$dispatchSource");
        $db->query("DELETE FROM vdata WHERE kid=$dispatchSource");
        $db->query("DELETE FROM users WHERE id=$dispatchOwner");
    }
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE movement AUTO_INCREMENT=$movementAutoIncrement");
    $db->query("ALTER TABLE enforcement AUTO_INCREMENT=$enforcementAutoIncrement");
}

$settlerCancelOwner = 2000000110;
$settlerCancelSource = 2000000111;
$settlerCancelTarget = 2000000112;
$settlerCancelFixtureCommitted = false;
$settlerCancelMovementIds = [];
$settlerCancelWorkers = [];
$settlerCancelBarrierFiles = [];
$settlerCancelLockHeld = false;
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$settlerCancelOwner"),
        'settler-cancellation fixture user ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$settlerCancelSource"),
        'settler-cancellation fixture village ID available'
    );
    expect_true($db->begin_transaction(), 'settler-cancellation fixture transaction started');
    $settlerCancelNow = miliseconds();
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, desc1, desc2, note)
        VALUES ($settlerCancelOwner, 'ov-regression-settler-cancel', 'OVSettlerCancel', 'x', '', 1,
                $settlerCancelSource, '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($settlerCancelSource, $settlerCancelOwner, 3, 'OV Settler Cancellation', 1, 0, 0,
         250, 250, 250, 0, 0, 0, 1000000, 250, 0, 1000000, 0, $settlerCancelNow, " . time() . ", 0)");
    expect_true($db->commit(), 'settler-cancellation fixture committed');
    $settlerCancelFixtureCommitted = true;

    $settlerUnits = array_fill(1, 11, 0);
    $settlerUnits[10] = 3;
    $movement = new MovementsModel();
    $tooLateMovement = (int)$movement->addMovement(
        $settlerCancelSource,
        $settlerCancelTarget,
        1,
        $settlerUnits,
        0,
        0,
        0,
        0,
        0,
        MovementsModel::ATTACKTYPE_SETTLERS,
        $settlerCancelNow - 3600000,
        $settlerCancelNow + 3600000
    );
    expect_true($tooLateMovement > 0, 'too-late settler movement created');
    $settlerCancelMovementIds[] = $tooLateMovement;
    expect_same(
        false,
        RallyPointModel::cancelTaskForVillage($tooLateMovement, $settlerCancelSource),
        'settler cancellation outside the allowed window rejected'
    );
    expect_same(
        "250|250|250|250|$settlerCancelSource|$settlerCancelTarget|0",
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                FLOOR(v.wood), '|', FLOOR(v.clay), '|', FLOOR(v.iron), '|', FLOOR(v.crop), '|',
                m.kid, '|', m.to_kid, '|', m.mode
             ) FROM vdata v JOIN movement m ON m.id=$tooLateMovement
               WHERE v.kid=$settlerCancelSource"
        ),
        'rejected late cancellation preserves resources and outgoing movement'
    );

    $staleMovement = (int)$movement->addMovement(
        $settlerCancelSource,
        $settlerCancelTarget,
        1,
        $settlerUnits,
        0,
        0,
        0,
        0,
        0,
        MovementsModel::ATTACKTYPE_SETTLERS,
        $settlerCancelNow - 1000,
        $settlerCancelNow + 60000
    );
    expect_true($staleMovement > 0, 'stale settler movement created');
    $db->query("DELETE FROM movement WHERE id=$staleMovement");
    expect_same(
        false,
        RallyPointModel::cancelTaskForVillage($staleMovement, $settlerCancelSource),
        'consumed settler movement cannot be cancelled'
    );
    expect_same(
        '250|250|250|250',
        (string)$db->fetchScalar(
            "SELECT CONCAT(FLOOR(wood), '|', FLOOR(clay), '|', FLOOR(iron), '|', FLOOR(crop))
             FROM vdata WHERE kid=$settlerCancelSource"
        ),
        'stale cancellation does not refund resources'
    );

    $concurrentMovement = (int)$movement->addMovement(
        $settlerCancelSource,
        $settlerCancelTarget,
        1,
        $settlerUnits,
        0,
        0,
        0,
        0,
        0,
        MovementsModel::ATTACKTYPE_SETTLERS,
        miliseconds() - 1000,
        miliseconds() + 60000
    );
    expect_true($concurrentMovement > 0, 'concurrent settler movement created');
    $settlerCancelMovementIds[] = $concurrentMovement;
    expect_true($db->begin_transaction(), 'settler-cancellation race lock transaction started');
    $settlerCancelLockHeld = true;
    $lockedMovement = $db->query("SELECT id FROM movement WHERE id=$concurrentMovement FOR UPDATE");
    expect_true($lockedMovement && $lockedMovement->num_rows === 1, 'settler-cancellation race movement locked');

    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $barrierPath = tempnam(sys_get_temp_dir(), 'ov-settler-cancel-start-');
    $firstReadyPath = tempnam(sys_get_temp_dir(), 'ov-settler-cancel-ready-');
    $secondReadyPath = tempnam(sys_get_temp_dir(), 'ov-settler-cancel-ready-');
    expect_true($barrierPath !== false, 'settler-cancellation start barrier created');
    expect_true($firstReadyPath !== false, 'first settler-cancellation ready signal created');
    expect_true($secondReadyPath !== false, 'second settler-cancellation ready signal created');
    $settlerCancelBarrierFiles = [$barrierPath, $firstReadyPath, $secondReadyPath];
    $readyPaths = [$firstReadyPath, $secondReadyPath];
    for ($i = 0; $i < 2; ++$i) {
        $pipes = [];
        $process = proc_open(
            [
                'php',
                '/app/tests/settler-cancel-worker.php',
                (string)$concurrentMovement,
                (string)$settlerCancelSource,
                $barrierPath,
                $readyPaths[$i],
            ],
            $descriptorSpec,
            $pipes
        );
        expect_true(is_resource($process), "settler-cancellation worker $i started");
        $settlerCancelWorkers[] = ['process' => $process, 'pipes' => $pipes];
    }

    $readyDeadline = microtime(true) + 10;
    while (
        (@file_get_contents($firstReadyPath) !== 'ready' || @file_get_contents($secondReadyPath) !== 'ready')
        && microtime(true) < $readyDeadline
    ) {
        usleep(1000);
    }
    expect_same('ready', @file_get_contents($firstReadyPath), 'first settler-cancellation worker ready');
    expect_same('ready', @file_get_contents($secondReadyPath), 'second settler-cancellation worker ready');
    expect_true(file_put_contents($barrierPath, 'go', LOCK_EX) !== false, 'settler-cancellation workers released together');
    $blockedWorkers = 0;
    $blockedDeadline = microtime(true) + 10;
    while ($blockedWorkers < 2 && microtime(true) < $blockedDeadline) {
        $blockedWorkers = (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST
             WHERE ID<>CONNECTION_ID() AND DB=DATABASE() AND INFO LIKE '%movement%'
               AND INFO LIKE '%id=$concurrentMovement%'"
        );
        if ($blockedWorkers < 2) {
            usleep(1000);
        }
    }
    expect_true($blockedWorkers >= 2, 'both settler-cancellation workers blocked on the same movement');
    expect_true($db->commit(), 'settler-cancellation race lock released');
    $settlerCancelLockHeld = false;

    $settlerCancelOutcomes = [];
    foreach ($settlerCancelWorkers as $i => &$worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $worker['process'] = null;
        $worker['pipes'] = [];
        expect_same(0, $exitCode, "settler-cancellation worker $i exited successfully: $stderr");
        $settlerCancelOutcomes[] = $stdout;
    }
    unset($worker);
    sort($settlerCancelOutcomes);
    expect_same(['false', 'true'], $settlerCancelOutcomes, 'exactly one concurrent settler cancellation committed');
    expect_same(
        "1000|1000|1000|1000|$settlerCancelTarget|$settlerCancelSource|1|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                FLOOR(v.wood), '|', FLOOR(v.clay), '|', FLOOR(v.iron), '|', FLOOR(v.crop), '|',
                m.kid, '|', m.to_kid, '|', m.mode, '|',
                (SELECT COUNT(*) FROM movement WHERE id=$concurrentMovement)
             ) FROM vdata v JOIN movement m ON m.id=$concurrentMovement
               WHERE v.kid=$settlerCancelSource"
        ),
        'concurrent settler cancellation refunds once and creates one return'
    );
    expect_same(
        false,
        RallyPointModel::cancelTaskForVillage($concurrentMovement, $settlerCancelSource),
        'replayed settler cancellation ignored'
    );
    expect_same(
        '1000|1000|1000|1000',
        (string)$db->fetchScalar(
            "SELECT CONCAT(FLOOR(wood), '|', FLOOR(clay), '|', FLOOR(iron), '|', FLOOR(crop))
             FROM vdata WHERE kid=$settlerCancelSource"
        ),
        'replayed settler cancellation does not duplicate the refund'
    );
} finally {
    foreach ($settlerCancelWorkers as &$worker) {
        if (isset($worker['pipes']) && is_array($worker['pipes'])) {
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
        if (isset($worker['process']) && is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
    }
    unset($worker);
    foreach ($settlerCancelBarrierFiles as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
    if ($settlerCancelLockHeld) {
        $db->rollback();
    }
    if ($settlerCancelFixtureCommitted) {
        $db->query("DELETE FROM movement WHERE id IN(" . implode(',', $settlerCancelMovementIds ?: [0]) . ")");
        $db->query("DELETE FROM vdata WHERE kid=$settlerCancelSource");
        $db->query("DELETE FROM users WHERE id=$settlerCancelOwner");
    }
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE movement AUTO_INCREMENT=$movementAutoIncrement");
}

$capitalOwner = 2000000050;
$capitalForeignOwner = 2000000051;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id IN ($capitalOwner, $capitalForeignOwner)"),
        'manual-capital fixture user IDs available'
    );
    $capitalFields = $db->query(
        "SELECT w.id, w.fieldtype
         FROM wdata w LEFT JOIN vdata v ON v.kid=w.id
         WHERE w.id>0 AND w.occupied=0 AND w.oasistype=0 AND v.kid IS NULL
         ORDER BY w.id DESC LIMIT 5"
    );
    expect_same(5, $capitalFields->num_rows, 'manual-capital fixture fields available');
    $oldCapitalField = $capitalFields->fetch_assoc();
    $newCapitalField = $capitalFields->fetch_assoc();
    $noPalaceField = $capitalFields->fetch_assoc();
    $wwCapitalField = $capitalFields->fetch_assoc();
    $foreignCapitalField = $capitalFields->fetch_assoc();
    $oldCapitalKid = (int)$oldCapitalField['id'];
    $newCapitalKid = (int)$newCapitalField['id'];
    $noPalaceKid = (int)$noPalaceField['id'];
    $wwCapitalKid = (int)$wwCapitalField['id'];
    $foreignCapitalKid = (int)$foreignCapitalField['id'];
    $capitalKidList = implode(',', [
        $oldCapitalKid,
        $newCapitalKid,
        $noPalaceKid,
        $wwCapitalKid,
        $foreignCapitalKid,
    ]);
    $now = time();
    $lastUpdate = miliseconds();

    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, kid, total_pop, total_villages, cp_prod, desc1, desc2, note)
        VALUES
        ($capitalOwner, 'ov-regression-capital-owner', 'OVCapitalOwner', 'x', '', 2, $oldCapitalKid, 250, 4, 900, '', '', ''),
        ($capitalForeignOwner, 'ov-regression-capital-foreign', 'OVCapitalForeign', 'x', '', 1, $foreignCapitalKid, 50, 1, 100, '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, isWW, pop, cp, loyalty, wood, clay, iron, woodp, clayp, ironp,
         maxstore, crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($oldCapitalKid, $capitalOwner, " . (int)$oldCapitalField['fieldtype'] . ", 'OV Old Capital', 1, 0, 100, 500, 93,
         1000000, 1000000, 1000000, 10000, 10000, 10000, 1000000, 1000000, 10000, 1000000, 0, $lastUpdate, $now, 0),
        ($newCapitalKid, $capitalOwner, " . (int)$newCapitalField['fieldtype'] . ", 'OV New Capital', 0, 0, 50, 200, 87,
         1000000, 1000000, 1000000, 10000, 10000, 10000, 1000000, 1000000, 10000, 1000000, 0, $lastUpdate, $now, 0),
        ($noPalaceKid, $capitalOwner, " . (int)$noPalaceField['fieldtype'] . ", 'OV No Palace', 0, 0, 50, 100, 81,
         1000000, 1000000, 1000000, 10000, 10000, 10000, 1000000, 1000000, 10000, 1000000, 0, $lastUpdate, $now, 0),
        ($wwCapitalKid, $capitalOwner, " . (int)$wwCapitalField['fieldtype'] . ", 'OV WW Candidate', 0, 1, 50, 100, 79,
         1000000, 1000000, 1000000, 10000, 10000, 10000, 1000000, 1000000, 10000, 1000000, 0, $lastUpdate, $now, 0),
        ($foreignCapitalKid, $capitalForeignOwner, " . (int)$foreignCapitalField['fieldtype'] . ", 'OV Foreign Candidate', 1, 0, 50, 100, 75,
         1000000, 1000000, 1000000, 10000, 10000, 10000, 1000000, 1000000, 10000, 1000000, 0, $lastUpdate, $now, 0)");
    $db->query("INSERT INTO fdata
        (kid, f1, f1t, f2, f2t, f3, f3t, f19, f19t, f20, f20t, f21, f21t, f22, f22t)
        VALUES
        ($oldCapitalKid, 12, 1, 8, 2, 10, 3, 1, 34, 1, 35, 5, 15, 0, 0),
        ($newCapitalKid, 10, 1, 10, 2, 10, 3, 1, 26, 1, 29, 0, 30, 3, 15),
        ($noPalaceKid, 10, 1, 10, 2, 10, 3, 1, 25, 0, 0, 0, 0, 0, 0),
        ($wwCapitalKid, 10, 1, 10, 2, 10, 3, 1, 26, 0, 0, 0, 0, 0, 0),
        ($foreignCapitalKid, 10, 1, 10, 2, 10, 3, 1, 26, 0, 0, 0, 0, 0, 0)");
    $db->query("INSERT INTO units (kid, race) VALUES
        ($oldCapitalKid, 2), ($newCapitalKid, 2), ($noPalaceKid, 2), ($wwCapitalKid, 2), ($foreignCapitalKid, 1)");
    $db->query("INSERT INTO hero (uid, kid, health) VALUES ($capitalOwner, $oldCapitalKid, 73)");
    $db->query("UPDATE wdata SET occupied=1 WHERE id IN ($capitalKidList) AND occupied=0");
    expect_same(5, $db->affectedRows(), 'manual-capital fixture fields occupied');

    $queueTime = $now + 3600;
    $db->query("INSERT INTO building_upgrade (kid, building_field, isMaster, start_time, commence) VALUES
        ($oldCapitalKid, 1, 0, $now, $queueTime),
        ($oldCapitalKid, 1, 1, $now, $queueTime),
        ($oldCapitalKid, 2, 0, $now, $queueTime),
        ($oldCapitalKid, 2, 1, $now, " . ($queueTime + 1) . "),
        ($oldCapitalKid, 2, 1, $now, " . ($queueTime + 2) . "),
        ($oldCapitalKid, 3, 0, $now, $queueTime),
        ($oldCapitalKid, 19, 0, $now, $queueTime),
        ($oldCapitalKid, 20, 1, $now, $queueTime),
        ($oldCapitalKid, 21, 0, $now, $queueTime),
        ($newCapitalKid, 20, 0, $now, $queueTime),
        ($newCapitalKid, 21, 1, $now, $queueTime),
        ($newCapitalKid, 22, 0, $now, $queueTime)");
    $db->query("INSERT INTO demolition (kid, building_field, end_time, complete) VALUES
        ($oldCapitalKid, 19, $queueTime, 1),
        ($newCapitalKid, 20, $queueTime, 1),
        ($oldCapitalKid, 21, $queueTime, 0)");

    $capitalModel = new VillageModel();
    $capitalState = static function () use ($db, $capitalOwner, $oldCapitalKid, $newCapitalKid): string {
        return (string)$db->fetchScalar(
            "SELECT CONCAT_WS('|',
                (SELECT GROUP_CONCAT(CONCAT(kid, ':', capital) ORDER BY kid SEPARATOR ',') FROM vdata WHERE owner=$capitalOwner),
                (SELECT CONCAT(f1, ':', f2, ':', f3, ':', f19, ':', f19t, ':', f20, ':', f20t, ':', f21, ':', f21t) FROM fdata WHERE kid=$oldCapitalKid),
                (SELECT CONCAT(f19, ':', f19t, ':', f20, ':', f20t, ':', f21, ':', f21t, ':', f22, ':', f22t) FROM fdata WHERE kid=$newCapitalKid),
                (SELECT GROUP_CONCAT(CONCAT(kid, ':', building_field, ':', isMaster) ORDER BY id SEPARATOR ',') FROM building_upgrade WHERE kid IN ($oldCapitalKid, $newCapitalKid)),
                (SELECT GROUP_CONCAT(CONCAT(kid, ':', building_field) ORDER BY id SEPARATOR ',') FROM demolition WHERE kid IN ($oldCapitalKid, $newCapitalKid)),
                (SELECT CONCAT(kid, ':', health) FROM hero WHERE uid=$capitalOwner)
            )"
        );
    };
    $unchangedCapitalState = $capitalState();
    expect_same(false, $capitalModel->changeCapital($capitalOwner, $foreignCapitalKid), 'foreign capital candidate rejected');
    expect_same(false, $capitalModel->changeCapital($capitalOwner, $noPalaceKid), 'capital candidate without Palace rejected');
    expect_same(false, $capitalModel->changeCapital($capitalOwner, $wwCapitalKid), 'World Wonder capital candidate rejected');
    expect_same(false, $capitalModel->changeCapital($capitalOwner, $oldCapitalKid), 'current capital replay rejected');
    $db->query("UPDATE fdata SET f19=0 WHERE kid=$newCapitalKid");
    expect_same(false, $capitalModel->changeCapital($capitalOwner, $newCapitalKid), 'level-zero Palace candidate rejected');
    $db->query("UPDATE fdata SET f19=1 WHERE kid=$newCapitalKid");
    $db->query("UPDATE vdata SET capital=0 WHERE kid=$oldCapitalKid");
    expect_same(false, $capitalModel->changeCapital($capitalOwner, $newCapitalKid), 'missing current capital rejected');
    $db->query("UPDATE vdata SET capital=1 WHERE kid=$oldCapitalKid");
    $db->query("UPDATE vdata SET capital=1 WHERE kid=$noPalaceKid");
    expect_same(false, $capitalModel->changeCapital($capitalOwner, $newCapitalKid), 'multiple current capitals rejected');
    $db->query("UPDATE vdata SET capital=0 WHERE kid=$noPalaceKid");
    expect_same($unchangedCapitalState, $capitalState(), 'rejected capital changes mutate no game state');

    expect_true($db->begin_transaction(), 'manual-capital crash savepoint started');
    try {
        expect_true($capitalModel->changeCapital($capitalOwner, $newCapitalKid), 'manual-capital nested transition completed');
        throw new RuntimeException('Simulated manual-capital caller crash.');
    } catch (RuntimeException $e) {
        expect_same('Simulated manual-capital caller crash.', $e->getMessage(), 'manual-capital caller crash propagated');
        expect_true($db->rollback(), 'manual-capital caller crash rolled back');
    }
    expect_same($unchangedCapitalState, $capitalState(), 'manual-capital caller crash restores all state');

    expect_true($capitalModel->changeCapital($capitalOwner, $newCapitalKid), 'manual capital changed successfully');
    expect_same(
        "$newCapitalKid|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT(kid, '|', COUNT(*)) FROM vdata WHERE owner=$capitalOwner AND capital=1"
        ),
        'manual capital transition leaves exactly the destination capital'
    );
    expect_same('10|8|10', (string)$db->fetchScalar("SELECT CONCAT(f1, '|', f2, '|', f3) FROM fdata WHERE kid=$oldCapitalKid"), 'old capital resource fields capped at level ten');
    expect_same('0|0|0|0', (string)$db->fetchScalar("SELECT CONCAT(f19, '|', f19t, '|', f20, '|', f20t) FROM fdata WHERE kid=$oldCapitalKid"), 'old capital Stonemason and Brewery removed');
    expect_same('1|26|0|0|0|0', (string)$db->fetchScalar("SELECT CONCAT(f19, '|', f19t, '|', f20, '|', f20t, '|', f21, '|', f21t) FROM fdata WHERE kid=$newCapitalKid"), 'new capital keeps Palace and removes Great Barracks and Great Stable');
    expect_same('5|15|3|15', (string)$db->fetchScalar("SELECT CONCAT((SELECT f21 FROM fdata WHERE kid=$oldCapitalKid), '|', (SELECT f21t FROM fdata WHERE kid=$oldCapitalKid), '|', f22, '|', f22t) FROM fdata WHERE kid=$newCapitalKid"), 'manual capital transition preserves unrelated buildings');
    expect_same(
        '2:0,1|1|1|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT CONCAT(COUNT(*), ':', GROUP_CONCAT(isMaster ORDER BY id)) FROM building_upgrade WHERE kid=$oldCapitalKid AND building_field=2), '|',
                (SELECT COUNT(*) FROM building_upgrade WHERE kid=$oldCapitalKid AND building_field=21), '|',
                (SELECT COUNT(*) FROM building_upgrade WHERE kid=$newCapitalKid AND building_field=22), '|',
                (SELECT COUNT(*) FROM building_upgrade
                 WHERE (kid=$oldCapitalKid AND building_field IN (1, 3, 19, 20))
                    OR (kid=$newCapitalKid AND building_field IN (20, 21)))
            )"
        ),
        'manual capital transition trims only incompatible construction work'
    );
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM demolition WHERE kid=$oldCapitalKid AND building_field=21"), 'unrelated demolition remains queued');
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM demolition WHERE kid IN ($oldCapitalKid, $newCapitalKid) AND building_field IN (19, 20)"), 'incompatible demolitions removed');
    expect_same("$oldCapitalKid|73.0000000000", (string)$db->fetchScalar("SELECT CONCAT(kid, '|', health) FROM hero WHERE uid=$capitalOwner"), 'manual capital change preserves hero location and health');
    expect_same($oldCapitalKid, (int)$db->fetchScalar("SELECT kid FROM users WHERE id=$capitalOwner"), 'manual capital change preserves selected village');
    expect_same('93.0000000000|87.0000000000', (string)$db->fetchScalar("SELECT CONCAT((SELECT loyalty FROM vdata WHERE kid=$oldCapitalKid), '|', loyalty) FROM vdata WHERE kid=$newCapitalKid"), 'manual capital change preserves loyalty');
    expect_same(
        (string)$db->fetchScalar("SELECT CONCAT(SUM(pop), '|', SUM(cp)) FROM vdata WHERE owner=$capitalOwner"),
        (string)$db->fetchScalar("SELECT CONCAT(total_pop, '|', cp_prod) FROM users WHERE id=$capitalOwner"),
        'manual capital change keeps population and culture aggregates consistent'
    );
    expect_true((int)$db->fetchScalar("SELECT profileCacheVersion FROM users WHERE id=$capitalOwner") > 0, 'manual capital change invalidates profile cache');
    $successfulCapitalState = $capitalState();
    expect_same(false, $capitalModel->changeCapital($capitalOwner, $newCapitalKid), 'manual capital replay is a no-op');
    expect_same($successfulCapitalState, $capitalState(), 'manual capital replay preserves successful state');
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE building_upgrade AUTO_INCREMENT=$buildingUpgradeAutoIncrement");
    $db->query("ALTER TABLE demolition AUTO_INCREMENT=$demolitionAutoIncrement");
}

$concurrentCapitalOwner = 2000000052;
$concurrentCapitalKids = [];
$concurrentCapitalTaskIds = [];
$concurrentCapitalDemolitionTaskIds = [];
$concurrentCapitalUnrelatedSendId = 0;
$concurrentCapitalCommitted = false;
$concurrentCapitalAvailableOccupancy = [];
$concurrentCapitalWorkers = [];
$concurrentCapitalBarrierFiles = [];
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$concurrentCapitalOwner"),
        'concurrent manual-capital fixture user ID available'
    );
    $concurrentCapitalFields = $db->query(
        "SELECT w.id, w.fieldtype
         FROM wdata w LEFT JOIN vdata v ON v.kid=w.id
         WHERE w.id>0 AND w.occupied=0 AND w.oasistype=0 AND v.kid IS NULL
           AND NOT EXISTS (SELECT 1 FROM fdata stale_fdata WHERE stale_fdata.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM research stale_research WHERE stale_research.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM tdata stale_tdata WHERE stale_tdata.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM smithy stale_smithy WHERE stale_smithy.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM training stale_training WHERE stale_training.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM odelete stale_odelete WHERE stale_odelete.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM traderoutes stale_route WHERE stale_route.kid=w.id OR stale_route.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM send stale_send WHERE stale_send.kid=w.id OR stale_send.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM market stale_market WHERE stale_market.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM building_upgrade stale_upgrade WHERE stale_upgrade.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM demolition stale_demolition WHERE stale_demolition.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM farmlist stale_farmlist WHERE stale_farmlist.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM raidlist stale_raidlist WHERE stale_raidlist.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM units stale_units WHERE stale_units.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM hero stale_hero WHERE stale_hero.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM enforcement stale_enforcement WHERE stale_enforcement.kid=w.id OR stale_enforcement.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM trapped stale_trapped WHERE stale_trapped.kid=w.id OR stale_trapped.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM blocks b WHERE b.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM marks m WHERE m.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM vdata child WHERE child.expandedfrom=w.id)
           AND NOT EXISTS (SELECT 1 FROM movement movement_ref WHERE movement_ref.kid=w.id OR movement_ref.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM odata oasis_ref WHERE oasis_ref.did=w.id)
         ORDER BY w.id DESC LIMIT 3"
    );
    expect_same(3, $concurrentCapitalFields->num_rows, 'concurrent manual-capital fixture fields available');
    $concurrentOldField = $concurrentCapitalFields->fetch_assoc();
    $concurrentNewField = $concurrentCapitalFields->fetch_assoc();
    $concurrentThirdField = $concurrentCapitalFields->fetch_assoc();
    $concurrentOldKid = (int)$concurrentOldField['id'];
    $concurrentNewKid = (int)$concurrentNewField['id'];
    $concurrentThirdKid = (int)$concurrentThirdField['id'];
    $concurrentCapitalKids = [$concurrentOldKid, $concurrentNewKid, $concurrentThirdKid];
    $concurrentCapitalKidList = implode(',', $concurrentCapitalKids);
    $availableRows = $db->query(
        "SELECT kid, occupied FROM available_villages WHERE kid IN ($concurrentCapitalKidList)"
    );
    while ($availableRow = $availableRows->fetch_assoc()) {
        $concurrentCapitalAvailableOccupancy[(int)$availableRow['kid']] = (int)$availableRow['occupied'];
    }
    $now = time();
    $lastUpdate = miliseconds();
    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, kid, gift_gold, total_pop, total_villages, cp_prod, desc1, desc2, note)
        VALUES ($concurrentCapitalOwner, 'ov-regression-concurrent-capital', 'OVConcurrentCapital', 'x', '', 1,
                $concurrentOldKid, 100, 60, 3, 60, '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, loyalty, wood, clay, iron, woodp, clayp, ironp,
         maxstore, crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($concurrentOldKid, $concurrentCapitalOwner, " . (int)$concurrentOldField['fieldtype'] . ", 'OV Concurrent Old Capital', 1, 10, 10, 100,
         1000000, 1000000, 1000000, 10000, 10000, 10000, 1000000, 1000000, 10000, 1000000, 0, $lastUpdate, $now, 0),
        ($concurrentNewKid, $concurrentCapitalOwner, " . (int)$concurrentNewField['fieldtype'] . ", 'OV Concurrent New Capital', 0, 30, 30, 100,
         1000000, 1000000, 1000000, 10000, 10000, 10000, 1000000, 1000000, 10000, 1000000, 0, $lastUpdate, $now, 0),
        ($concurrentThirdKid, $concurrentCapitalOwner, " . (int)$concurrentThirdField['fieldtype'] . ", 'OV Concurrent Third Capital', 0, 20, 20, 100,
         1000000, 1000000, 1000000, 10000, 10000, 10000, 1000000, 1000000, 10000, 1000000, 0, $lastUpdate, $now, 0)");
    $db->query("INSERT INTO fdata (kid, f1, f1t, f19, f19t, f20, f20t) VALUES
        ($concurrentOldKid, 10, 1, 0, 0, 0, 0),
        ($concurrentNewKid, 10, 1, 1, 26, 0, 0),
        ($concurrentThirdKid, 10, 1, 0, 0, 0, 0)");
    $db->query("INSERT INTO units (kid, race) VALUES
        ($concurrentOldKid, 1), ($concurrentNewKid, 1), ($concurrentThirdKid, 1)");
    $db->query("INSERT INTO hero (uid, kid, health) VALUES ($concurrentCapitalOwner, $concurrentThirdKid, 100)");
    $db->query("INSERT INTO send (kid, to_kid, wood, clay, iron, crop, x, mode, end_time)
        VALUES ($concurrentOldKid, $concurrentOldKid, 1, 2, 3, 4, 1, 0, " . ($now + 3600) . ")");
    $concurrentCapitalUnrelatedSendId = (int)$db->lastInsertId();
    $db->query("UPDATE wdata SET occupied=1 WHERE id IN ($concurrentCapitalKidList) AND occupied=0");
    expect_same(3, $db->affectedRows(), 'concurrent manual-capital fixture fields occupied');
    expect_true($db->commit(), 'concurrent manual-capital fixture committed');
    $concurrentCapitalCommitted = true;

    $runCapitalRace = function (array $commandPrefixes, string $label) use (
        &$concurrentCapitalWorkers,
        &$concurrentCapitalBarrierFiles
    ): array {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $barrierPath = tempnam(sys_get_temp_dir(), 'ov-capital-start-');
        expect_true($barrierPath !== false, "$label start barrier created");
        $concurrentCapitalBarrierFiles[] = $barrierPath;
        $readyPaths = [];
        foreach ($commandPrefixes as $i => $prefix) {
            $readyPath = tempnam(sys_get_temp_dir(), 'ov-capital-ready-');
            expect_true($readyPath !== false, "$label worker $i ready signal created");
            $readyPaths[] = $readyPath;
            $concurrentCapitalBarrierFiles[] = $readyPath;
            $pipes = [];
            $process = proc_open(array_merge($prefix, [$barrierPath, $readyPath]), $descriptorSpec, $pipes);
            expect_true(is_resource($process), "$label worker $i started");
            $concurrentCapitalWorkers[] = ['process' => $process, 'pipes' => $pipes];
        }

        $readyDeadline = microtime(true) + 10;
        do {
            $ready = true;
            foreach ($readyPaths as $readyPath) {
                if (@file_get_contents($readyPath) !== 'ready') {
                    $ready = false;
                    break;
                }
            }
            if (!$ready) {
                usleep(1000);
            }
        } while (!$ready && microtime(true) < $readyDeadline);
        expect_true($ready, "$label workers ready");
        expect_true(file_put_contents($barrierPath, 'go', LOCK_EX) !== false, "$label workers released");

        $outcomes = [];
        $workerStart = count($concurrentCapitalWorkers) - count($commandPrefixes);
        foreach ($commandPrefixes as $i => $_prefix) {
            $workerIndex = $workerStart + $i;
            $worker = &$concurrentCapitalWorkers[$workerIndex];
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            $worker['process'] = null;
            $worker['pipes'] = [];
            expect_same(0, $exitCode, "$label worker $i exit status: $stderr");
            expect_same('', $stderr, "$label worker $i stderr");
            $outcomes[] = $stdout;
            unset($worker);
        }

        return $outcomes;
    };

    $stageOnlyPalace = function (int $palaceKid, string $label) use (
        $db,
        $concurrentCapitalKidList
    ): void {
        $db->query(
            "UPDATE fdata
             SET f19=IF(kid=$palaceKid, 1, 0), f19t=IF(kid=$palaceKid, 26, 0)
             WHERE kid IN ($concurrentCapitalKidList)"
        );
        expect_same(
            "$palaceKid|1",
            (string)$db->fetchScalar(
                "SELECT CONCAT(
                    MAX(IF(f19>=1 AND f19t=26, kid, 0)), '|',
                    SUM(f19>=1 AND f19t=26)
                 ) FROM fdata WHERE kid IN ($concurrentCapitalKidList)"
            ),
            "$label has exactly one account Palace"
        );
    };

    $stageOnlyPalace($concurrentNewKid, 'duplicate manual-capital race');
    $duplicateOutcomes = $runCapitalRace([
        ['php', '/app/tests/capital-change-worker.php', (string)$concurrentCapitalOwner, (string)$concurrentNewKid],
        ['php', '/app/tests/capital-change-worker.php', (string)$concurrentCapitalOwner, (string)$concurrentNewKid],
    ], 'duplicate manual-capital race');
    sort($duplicateOutcomes);
    expect_same(['false', 'true'], $duplicateOutcomes, 'duplicate manual-capital race changes capital once');
    expect_same(
        "$concurrentNewKid|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT(kid, '|', COUNT(*)) FROM vdata WHERE owner=$concurrentCapitalOwner AND capital=1"
        ),
        'duplicate manual-capital race leaves one destination capital'
    );

    $stageOnlyPalace($concurrentThirdKid, 'capital-building race');
    $db->query("UPDATE fdata SET f20=0, f20t=29 WHERE kid=$concurrentThirdKid");
    $db->query("INSERT INTO building_upgrade (kid, building_field, isMaster, start_time, commence)
        VALUES ($concurrentThirdKid, 20, 0, $now, $now)");
    $concurrentBuildingTask = (int)$db->lastInsertId();
    $concurrentCapitalTaskIds[] = $concurrentBuildingTask;
    $buildingRaceOutcomes = $runCapitalRace([
        ['php', '/app/tests/capital-change-worker.php', (string)$concurrentCapitalOwner, (string)$concurrentThirdKid],
        ['php', '/app/tests/building-task-worker.php', (string)$concurrentBuildingTask],
    ], 'capital-building race');
    expect_same('true', $buildingRaceOutcomes[0], 'capital-building race changes capital');
    expect_true(in_array($buildingRaceOutcomes[1], ['false', 'true'], true), 'capital-building race returns a valid worker outcome');
    expect_same(
        "$concurrentThirdKid|1|0|0|0",
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT kid FROM vdata WHERE owner=$concurrentCapitalOwner AND capital=1), '|',
                (SELECT COUNT(*) FROM vdata WHERE owner=$concurrentCapitalOwner AND capital=1), '|',
                f20, '|', f20t, '|',
                (SELECT COUNT(*) FROM building_upgrade WHERE id=$concurrentBuildingTask)
            ) FROM fdata WHERE kid=$concurrentThirdKid"
        ),
        'capital-building race cannot preserve or recreate Great Barracks'
    );

    $stageOnlyPalace($concurrentNewKid, 'capital-master-builder race');
    $db->query("UPDATE fdata SET f20=0, f20t=29 WHERE kid=$concurrentNewKid");
    $db->query("INSERT INTO building_upgrade (kid, building_field, isMaster, start_time, commence)
        VALUES ($concurrentNewKid, 20, 1, $now, $now)");
    $concurrentMasterTask = (int)$db->lastInsertId();
    $concurrentCapitalTaskIds[] = $concurrentMasterTask;
    $masterRaceOutcomes = $runCapitalRace([
        ['php', '/app/tests/capital-change-worker.php', (string)$concurrentCapitalOwner, (string)$concurrentNewKid],
        ['php', '/app/tests/master-builder-task-worker.php', (string)$concurrentMasterTask],
    ], 'capital-master-builder race');
    expect_same('true', $masterRaceOutcomes[0], 'capital-master-builder race changes capital');
    expect_true(in_array($masterRaceOutcomes[1], ['false', 'true'], true), 'capital-master-builder race returns a valid worker outcome');
    expect_same(
        "$concurrentNewKid|1|0|0|0",
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT kid FROM vdata WHERE owner=$concurrentCapitalOwner AND capital=1), '|',
                (SELECT COUNT(*) FROM vdata WHERE owner=$concurrentCapitalOwner AND capital=1), '|',
                f20, '|', f20t, '|',
                (SELECT COUNT(*) FROM building_upgrade WHERE kid=$concurrentNewKid AND building_field=20)
            ) FROM fdata WHERE kid=$concurrentNewKid"
        ),
        'capital-master-builder race cannot retain queued or active Great Barracks work'
    );

    $stageOnlyPalace($concurrentThirdKid, 'capital-demolition race');
    $db->query("UPDATE fdata SET f20=1, f20t=29 WHERE kid=$concurrentThirdKid");
    $db->query("INSERT INTO demolition (kid, building_field, end_time, complete)
        VALUES ($concurrentThirdKid, 20, $now, 1)");
    $concurrentDemolitionTask = (int)$db->lastInsertId();
    $concurrentCapitalDemolitionTaskIds[] = $concurrentDemolitionTask;
    $demolitionRaceOutcomes = $runCapitalRace([
        ['php', '/app/tests/capital-change-worker.php', (string)$concurrentCapitalOwner, (string)$concurrentThirdKid],
        ['php', '/app/tests/demolition-task-worker.php', (string)$concurrentDemolitionTask],
    ], 'capital-demolition race');
    expect_same('true', $demolitionRaceOutcomes[0], 'capital-demolition race changes capital');
    expect_true(in_array($demolitionRaceOutcomes[1], ['false', 'true'], true), 'capital-demolition race returns a valid worker outcome');
    expect_same(
        "$concurrentThirdKid|1|0|0|0",
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT kid FROM vdata WHERE owner=$concurrentCapitalOwner AND capital=1), '|',
                (SELECT COUNT(*) FROM vdata WHERE owner=$concurrentCapitalOwner AND capital=1), '|',
                f20, '|', f20t, '|',
                (SELECT COUNT(*) FROM demolition WHERE id=$concurrentDemolitionTask)
            ) FROM fdata WHERE kid=$concurrentThirdKid"
        ),
        'capital-demolition race cannot retain forbidden building or stale demolition work'
    );

    $stageOnlyPalace($concurrentNewKid, 'capital-destruction race');
    $destructionRaceOutcomes = $runCapitalRace([
        ['php', '/app/tests/village-delete-worker.php', (string)$concurrentThirdKid],
        ['php', '/app/tests/capital-change-worker.php', (string)$concurrentCapitalOwner, (string)$concurrentNewKid],
    ], 'capital-destruction race');
    expect_same('true', $destructionRaceOutcomes[0], 'capital-destruction race deletes target');
    expect_true(in_array($destructionRaceOutcomes[1], ['false', 'true'], true), 'capital-destruction race returns a valid switch outcome');
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$concurrentThirdKid"), 'capital-destruction race removes target village');
    expect_same(
        "$concurrentNewKid|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT(kid, '|', COUNT(*)) FROM vdata WHERE owner=$concurrentCapitalOwner AND capital=1"
        ),
        'capital-destruction race leaves one eligible capital'
    );
    expect_same(
        "$concurrentNewKid|0.0000000000",
        (string)$db->fetchScalar("SELECT CONCAT(kid, '|', health) FROM hero WHERE uid=$concurrentCapitalOwner"),
        'capital-destruction race relocates hero to surviving capital'
    );
    expect_same(
        "$concurrentOldKid|$concurrentOldKid|1|2|3|4",
        (string)$db->fetchScalar(
            "SELECT CONCAT(kid, '|', to_kid, '|', wood, '|', clay, '|', iron, '|', crop)
             FROM send WHERE id=$concurrentCapitalUnrelatedSendId"
        ),
        'capital concurrency fixture preserves unrelated data on the top candidate'
    );
} finally {
    foreach ($concurrentCapitalWorkers as &$worker) {
        if (!isset($worker['process']) || !is_resource($worker['process'])) {
            continue;
        }
        $status = proc_get_status($worker['process']);
        if (!empty($status['running'])) {
            proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
        $worker['process'] = null;
        $worker['pipes'] = [];
    }
    unset($worker);
    foreach ($concurrentCapitalBarrierFiles as $barrierFile) {
        if (is_string($barrierFile) && file_exists($barrierFile)) {
            unlink($barrierFile);
        }
    }

    if (!$concurrentCapitalCommitted) {
        $db->rollback();
    }
    if ($concurrentCapitalKids !== []) {
        $kidList = implode(',', array_map('intval', $concurrentCapitalKids));
        if ($concurrentCapitalTaskIds !== []) {
            $taskList = implode(',', array_map('intval', $concurrentCapitalTaskIds));
            $db->query("DELETE FROM scheduled_task_failures WHERE task_table='building_upgrade' AND task_id IN ($taskList)");
        }
        if ($concurrentCapitalDemolitionTaskIds !== []) {
            $taskList = implode(',', array_map('intval', $concurrentCapitalDemolitionTaskIds));
            $db->query("DELETE FROM scheduled_task_failures WHERE task_table='demolition' AND task_id IN ($taskList)");
        }
        $db->query("DELETE FROM building_upgrade WHERE kid IN ($kidList)");
        $db->query("DELETE FROM demolition WHERE kid IN ($kidList)");
        if ($concurrentCapitalUnrelatedSendId > 0) {
            $db->query("DELETE FROM send WHERE id=$concurrentCapitalUnrelatedSendId");
        }
        $db->query("DELETE FROM hero WHERE uid=$concurrentCapitalOwner");
        $db->query("DELETE FROM units WHERE kid IN ($kidList)");
        $db->query("DELETE FROM fdata WHERE kid IN ($kidList)");
        $db->query("DELETE FROM vdata WHERE kid IN ($kidList)");
        $db->query("DELETE FROM users WHERE id=$concurrentCapitalOwner");
        $db->query("UPDATE wdata SET occupied=0 WHERE id IN ($kidList)");
        foreach ($concurrentCapitalAvailableOccupancy as $kid => $occupied) {
            $db->query(
                "UPDATE available_villages SET occupied=" . (int)$occupied . " WHERE kid=" . (int)$kid
            );
        }
    }
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE building_upgrade AUTO_INCREMENT=$buildingUpgradeAutoIncrement");
    $db->query("ALTER TABLE demolition AUTO_INCREMENT=$demolitionAutoIncrement");
    $db->query("ALTER TABLE send AUTO_INCREMENT=$sendAutoIncrement");
}

$celebrationOwner = 2000000053;
$celebrationForeignOwner = 2000000054;
$celebrationKids = [];
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM users WHERE id IN ($celebrationOwner, $celebrationForeignOwner)"
        ),
        'celebration fixture user IDs available'
    );
    $greyCelebrationFields = $db->query(
        "SELECT w.id, w.fieldtype
         FROM wdata w LEFT JOIN vdata v ON v.kid=w.id
         WHERE w.id>0 AND w.occupied=0 AND w.oasistype=0 AND v.kid IS NULL
           AND SQRT(POW(w.x, 2)+POW(w.y, 2))<=22
           AND NOT EXISTS (SELECT 1 FROM fdata stale_fdata WHERE stale_fdata.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM blocks b WHERE b.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM marks m WHERE m.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM vdata child WHERE child.expandedfrom=w.id)
           AND NOT EXISTS (SELECT 1 FROM movement movement_ref WHERE movement_ref.kid=w.id OR movement_ref.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM odata oasis_ref WHERE oasis_ref.did=w.id)
         ORDER BY w.id DESC LIMIT 1"
    );
    expect_same(1, $greyCelebrationFields->num_rows, 'grey celebration fixture field available');
    $greyCelebrationField = $greyCelebrationFields->fetch_assoc();
    $normalCelebrationFields = $db->query(
        "SELECT w.id, w.fieldtype
         FROM wdata w LEFT JOIN vdata v ON v.kid=w.id
         WHERE w.id>0 AND w.occupied=0 AND w.oasistype=0 AND v.kid IS NULL
           AND SQRT(POW(w.x, 2)+POW(w.y, 2))>22
           AND NOT EXISTS (SELECT 1 FROM fdata stale_fdata WHERE stale_fdata.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM blocks b WHERE b.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM marks m WHERE m.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM vdata child WHERE child.expandedfrom=w.id)
           AND NOT EXISTS (SELECT 1 FROM movement movement_ref WHERE movement_ref.kid=w.id OR movement_ref.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM odata oasis_ref WHERE oasis_ref.did=w.id)
         ORDER BY w.id DESC LIMIT 4"
    );
    expect_same(4, $normalCelebrationFields->num_rows, 'normal celebration fixture fields available');
    $normalCelebrationField = $normalCelebrationFields->fetch_assoc();
    $otherCelebrationField = $normalCelebrationFields->fetch_assoc();
    $wwCelebrationField = $normalCelebrationFields->fetch_assoc();
    $foreignCelebrationField = $normalCelebrationFields->fetch_assoc();
    $greyCelebrationKid = (int)$greyCelebrationField['id'];
    $normalCelebrationKid = (int)$normalCelebrationField['id'];
    $otherCelebrationKid = (int)$otherCelebrationField['id'];
    $wwCelebrationKid = (int)$wwCelebrationField['id'];
    $foreignCelebrationKid = (int)$foreignCelebrationField['id'];
    $celebrationKids = [
        $greyCelebrationKid,
        $normalCelebrationKid,
        $otherCelebrationKid,
        $wwCelebrationKid,
        $foreignCelebrationKid,
    ];
    $celebrationKidList = implode(',', $celebrationKids);
    $celebrationLastUpdate = miliseconds();
    $celebrationCreated = time();
    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, kid, cp, cp_prod, lastupdate, total_pop, total_villages, desc1, desc2, note)
        VALUES
        ($celebrationOwner, 'ov-regression-celebration-owner', 'OVCelebrationOwner', 'x', '', 1,
         $greyCelebrationKid, 1000, 300, $celebrationCreated, 0, 4, '', '', ''),
        ($celebrationForeignOwner, 'ov-regression-celebration-foreign', 'OVCelebrationForeign', 'x', '', 2,
         $foreignCelebrationKid, 2000, 31, $celebrationCreated, 0, 1, '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, isWW, pop, cp, loyalty,
         wood, clay, iron, woodp, clayp, ironp, maxstore, crop, cropp, maxcrop, upkeep,
         lastmupdate, created, celebration, type, expandedfrom)
        VALUES
        ($greyCelebrationKid, $celebrationOwner, " . (int)$greyCelebrationField['fieldtype'] . ", 'OV Grey Celebration', 1, 0, 0, 0, 100,
         100000, 100000, 100000, 0, 0, 0, 1000000, 100000, 0, 1000000, 0,
         $celebrationLastUpdate, $celebrationCreated, 0, 0, 0),
        ($normalCelebrationKid, $celebrationOwner, " . (int)$normalCelebrationField['fieldtype'] . ", 'OV Normal Celebration', 0, 0, 0, 223, 100,
         100000, 100000, 100000, 0, 0, 0, 1000000, 100000, 0, 1000000, 0,
         $celebrationLastUpdate, $celebrationCreated, 0, 0, 0),
        ($otherCelebrationKid, $celebrationOwner, " . (int)$otherCelebrationField['fieldtype'] . ", 'OV Other Celebration', 0, 0, 0, 77, 100,
         100000, 100000, 100000, 0, 0, 0, 1000000, 100000, 0, 1000000, 0,
         $celebrationLastUpdate, $celebrationCreated, 0, 0, 0),
        ($wwCelebrationKid, $celebrationOwner, " . (int)$wwCelebrationField['fieldtype'] . ", 'OV WW Celebration', 0, 1, 0, 0, 100,
         100000, 100000, 100000, 0, 0, 0, 1000000, 100000, 0, 1000000, 0,
         $celebrationLastUpdate, $celebrationCreated, 0, 0, 0),
        ($foreignCelebrationKid, $celebrationForeignOwner, " . (int)$foreignCelebrationField['fieldtype'] . ", 'OV Foreign Celebration', 1, 0, 0, 31, 100,
         100000, 100000, 100000, 0, 0, 0, 1000000, 100000, 0, 1000000, 0,
         $celebrationLastUpdate, $celebrationCreated, 0, 0, 0)");
    $db->query("INSERT INTO fdata (kid, f19, f19t, f20, f20t) VALUES
        ($greyCelebrationKid, 1, 24, 20, 15),
        ($normalCelebrationKid, 10, 24, 20, 26),
        ($otherCelebrationKid, 20, 15, 0, 0),
        ($wwCelebrationKid, 20, 24, 20, 26),
        ($foreignCelebrationKid, 10, 24, 0, 0)");
    $db->query("INSERT INTO daily_quest (uid, qst10) VALUES
        ($celebrationOwner, 0), ($celebrationForeignOwner, 0)");
    $db->query("UPDATE wdata SET occupied=1 WHERE id IN ($celebrationKidList) AND occupied=0");
    expect_same(5, $db->affectedRows(), 'celebration fixture fields occupied');

    $celebrationModel = new CelebrationModel();
    $greyTheoreticalCp = (int)(Formulas::buildingCP(24, 1) + Formulas::buildingCP(15, 20));
    $normalTheoreticalCp = (int)(Formulas::buildingCP(24, 10) + Formulas::buildingCP(26, 20));
    $otherTheoreticalCp = (int)Formulas::buildingCP(15, 20);
    expect_same(83, $greyTheoreticalCp, 'grey celebration theoretical CP fixture');
    expect_same(223, $normalTheoreticalCp, 'normal celebration theoretical CP fixture');
    expect_same(77, $otherTheoreticalCp, 'other celebration theoretical CP fixture');
    expect_same(
        ['small' => 83, 'large' => 383],
        $celebrationModel->getRewardPreview($celebrationOwner, $greyCelebrationKid),
        'grey celebration preview uses theoretical CP and excludes WW CP'
    );
    expect_same(
        ['small' => 125, 'large' => 383],
        $celebrationModel->getRewardPreview($celebrationOwner, $normalCelebrationKid),
        'normal celebration preview applies 10x CP caps'
    );
    expect_same(
        ['small' => 0, 'large' => 0],
        $celebrationModel->getRewardPreview($celebrationOwner, $foreignCelebrationKid),
        'celebration preview rejects a foreign destination'
    );

    $celebrationState = function () use (
        $db,
        $celebrationOwner,
        $celebrationForeignOwner,
        $celebrationKidList
    ): array {
        return [
            'users' => (string)$db->fetchScalar(
                "SELECT GROUP_CONCAT(CONCAT(id, ':', cp, ':', cp_prod) ORDER BY id SEPARATOR '|')
                 FROM users WHERE id IN ($celebrationOwner, $celebrationForeignOwner)"
            ),
            'villages' => (string)$db->fetchScalar(
                "SELECT GROUP_CONCAT(
                    CONCAT(kid, ':', wood, ':', clay, ':', iron, ':', crop, ':', celebration, ':', type, ':', lastmupdate)
                    ORDER BY kid SEPARATOR '|')
                 FROM vdata WHERE kid IN ($celebrationKidList)"
            ),
            'quests' => (string)$db->fetchScalar(
                "SELECT GROUP_CONCAT(CONCAT(uid, ':', qst10) ORDER BY uid SEPARATOR '|')
                 FROM daily_quest WHERE uid IN ($celebrationOwner, $celebrationForeignOwner)"
            ),
        ];
    };
    $resetCelebrations = function () use (
        $db,
        $celebrationOwner,
        $celebrationForeignOwner,
        $celebrationKidList
    ): void {
        $lastUpdate = miliseconds();
        $db->query(
            "UPDATE vdata SET wood=100000, clay=100000, iron=100000, crop=100000,
                 celebration=0, type=0, lastmupdate=$lastUpdate
             WHERE kid IN ($celebrationKidList)"
        );
        $db->query("UPDATE users SET cp=IF(id=$celebrationOwner, 1000, 2000) WHERE id IN ($celebrationOwner, $celebrationForeignOwner)");
        $db->query("UPDATE daily_quest SET qst10=0 WHERE uid IN ($celebrationOwner, $celebrationForeignOwner)");
    };

    $baselineCelebrationState = $celebrationState();
    expect_same(false, $celebrationModel->startCelebration($celebrationOwner, $greyCelebrationKid, 0), 'celebration rejects type zero');
    expect_same(false, $celebrationModel->startCelebration($celebrationOwner, $greyCelebrationKid, 3), 'celebration rejects unknown type');
    expect_same(false, $celebrationModel->startCelebration(0, $greyCelebrationKid, 1), 'celebration rejects missing owner');
    expect_same(false, $celebrationModel->startCelebration($celebrationOwner, 0, 1), 'celebration rejects missing village');
    expect_same(false, $celebrationModel->startCelebration($celebrationOwner, $foreignCelebrationKid, 1), 'celebration rejects foreign village');
    expect_same(false, $celebrationModel->startCelebration($celebrationOwner, $otherCelebrationKid, 1), 'celebration rejects missing Town Hall');
    expect_same(false, $celebrationModel->startCelebration($celebrationOwner, $greyCelebrationKid, 2), 'large celebration requires Town Hall level ten');
    expect_same($baselineCelebrationState, $celebrationState(), 'basic celebration rejections preserve state');

    $db->query("UPDATE fdata SET f19=0, f19t=24 WHERE kid=$otherCelebrationKid");
    $levelZeroState = $celebrationState();
    expect_same(false, $celebrationModel->startCelebration($celebrationOwner, $otherCelebrationKid, 1), 'celebration rejects level-zero Town Hall');
    expect_same($levelZeroState, $celebrationState(), 'level-zero Town Hall rejection preserves state');
    $db->query("UPDATE fdata SET f19=20, f19t=15 WHERE kid=$otherCelebrationKid");

    $db->query("UPDATE vdata SET celebration=" . (time() + 60) . ", type=1 WHERE kid=$greyCelebrationKid");
    $cooldownState = $celebrationState();
    expect_same(false, $celebrationModel->startCelebration($celebrationOwner, $greyCelebrationKid, 1), 'celebration rejects active cooldown');
    expect_same($cooldownState, $celebrationState(), 'active cooldown rejection preserves state');
    $db->query("UPDATE vdata SET celebration=0, type=0 WHERE kid=$greyCelebrationKid");

    $smallCelebrationCost = array_map('intval', Formulas::celebrationCost(false));
    foreach (['wood', 'clay', 'iron', 'crop'] as $resourceIndex => $resource) {
        $db->query(
            "UPDATE vdata SET wood=100000, clay=100000, iron=100000, crop=100000,
                 $resource=" . ($smallCelebrationCost[$resourceIndex] - 1) . ", celebration=0, type=0,
                 lastmupdate=" . miliseconds() . " WHERE kid=$greyCelebrationKid"
        );
        $insufficientState = $celebrationState();
        expect_same(
            false,
            $celebrationModel->startCelebration($celebrationOwner, $greyCelebrationKid, 1),
            "celebration rejects insufficient $resource"
        );
        expect_same($insufficientState, $celebrationState(), "insufficient $resource rejection preserves state");
    }

    $resetCelebrations();
    $db->query("UPDATE vdata SET celebration=" . time() . " WHERE kid=$greyCelebrationKid");
    $smallStartBefore = time();
    expect_true($celebrationModel->startCelebration($celebrationOwner, $greyCelebrationKid, 1), 'expired-boundary grey celebration starts');
    $smallStartAfter = time();
    $smallCelebration = $db->query(
        "SELECT wood, clay, iron, crop, celebration, type FROM vdata WHERE kid=$greyCelebrationKid"
    )->fetch_assoc();
    foreach (['wood', 'clay', 'iron', 'crop'] as $resourceIndex => $resource) {
        expect_close(
            100000 - $smallCelebrationCost[$resourceIndex],
            (float)$smallCelebration[$resource],
            0.0001,
            "small celebration deducts exact $resource"
        );
    }
    $smallDuration = (int)Formulas::celebrationTime(false, 1);
    expect_true(
        (int)$smallCelebration['celebration'] >= $smallStartBefore + $smallDuration
        && (int)$smallCelebration['celebration'] <= $smallStartAfter + $smallDuration,
        'small celebration stores canonical cooldown'
    );
    expect_same(1, (int)$smallCelebration['type'], 'small celebration stores type');
    expect_same(1083, (int)$db->fetchScalar("SELECT cp FROM users WHERE id=$celebrationOwner"), 'small celebration credits grey theoretical CP immediately');
    expect_same(1, (int)$db->fetchScalar("SELECT qst10 FROM daily_quest WHERE uid=$celebrationOwner"), 'small celebration advances daily quest once');
    $smallSuccessState = $celebrationState();
    expect_same(false, $celebrationModel->startCelebration($celebrationOwner, $greyCelebrationKid, 1), 'small celebration replay is rejected');
    expect_same($smallSuccessState, $celebrationState(), 'small celebration replay preserves committed state');

    $resetCelebrations();
    $nestedCelebrationBaseline = $celebrationState();
    expect_true($db->begin_transaction(), 'celebration caller savepoint opened');
    try {
        expect_true($celebrationModel->startCelebration($celebrationOwner, $greyCelebrationKid, 1), 'nested celebration succeeds before caller failure');
        throw new RuntimeException('simulated celebration caller failure');
    } catch (RuntimeException $e) {
        expect_true($db->rollback(), 'celebration caller savepoint rolled back');
        expect_same('simulated celebration caller failure', $e->getMessage(), 'celebration caller failure propagated');
    }
    expect_same($nestedCelebrationBaseline, $celebrationState(), 'caller rollback restores complete celebration state');
    expect_true($celebrationModel->startCelebration($celebrationOwner, $greyCelebrationKid, 1), 'celebration retry succeeds after caller rollback');

    $resetCelebrations();
    $largeCelebrationCost = array_map('intval', Formulas::celebrationCost(true));
    $largeStartBefore = time();
    expect_true($celebrationModel->startCelebration($celebrationOwner, $normalCelebrationKid, 2), 'large celebration starts');
    $largeStartAfter = time();
    $largeCelebration = $db->query(
        "SELECT wood, clay, iron, crop, celebration, type FROM vdata WHERE kid=$normalCelebrationKid"
    )->fetch_assoc();
    foreach (['wood', 'clay', 'iron', 'crop'] as $resourceIndex => $resource) {
        expect_close(
            100000 - $largeCelebrationCost[$resourceIndex],
            (float)$largeCelebration[$resource],
            0.0001,
            "large celebration deducts exact $resource"
        );
    }
    $largeDuration = (int)Formulas::celebrationTime(true, 10);
    expect_true(
        (int)$largeCelebration['celebration'] >= $largeStartBefore + $largeDuration
        && (int)$largeCelebration['celebration'] <= $largeStartAfter + $largeDuration,
        'large celebration stores canonical cooldown'
    );
    expect_same(2, (int)$largeCelebration['type'], 'large celebration stores type');
    expect_same(1383, (int)$db->fetchScalar("SELECT cp FROM users WHERE id=$celebrationOwner"), 'large celebration credits capped account theoretical CP immediately');
    expect_same(1, (int)$db->fetchScalar("SELECT qst10 FROM daily_quest WHERE uid=$celebrationOwner"), 'large celebration advances daily quest once');
    expect_true((new BattleSetter())->is_great_celebration_running($normalCelebrationKid), 'large celebration activates conquest loyalty effect');
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
}

$concurrentCelebrationOwner = 2000000055;
$concurrentCelebrationKids = [];
$concurrentCelebrationCommitted = false;
$concurrentCelebrationAvailableOccupancy = [];
$concurrentCelebrationWorkers = [];
$concurrentCelebrationBarrierFiles = [];
$concurrentCelebrationDemolitionTask = 0;
$concurrentCelebrationDemolitionTaskIds = [];
$concurrentCelebrationUnrelatedSendId = 0;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$concurrentCelebrationOwner"),
        'concurrent celebration fixture user ID available'
    );
    $concurrentCelebrationFields = $db->query(
        "SELECT w.id, w.fieldtype
         FROM wdata w LEFT JOIN vdata v ON v.kid=w.id
         WHERE w.id>0 AND w.occupied=0 AND w.oasistype=0 AND v.kid IS NULL
           AND SQRT(POW(w.x, 2)+POW(w.y, 2))>22
           AND NOT EXISTS (SELECT 1 FROM fdata stale_fdata WHERE stale_fdata.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM research stale_research WHERE stale_research.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM tdata stale_tdata WHERE stale_tdata.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM smithy stale_smithy WHERE stale_smithy.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM training stale_training WHERE stale_training.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM odelete stale_odelete WHERE stale_odelete.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM traderoutes stale_route WHERE stale_route.kid=w.id OR stale_route.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM send stale_send WHERE stale_send.kid=w.id OR stale_send.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM market stale_market WHERE stale_market.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM building_upgrade stale_upgrade WHERE stale_upgrade.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM demolition stale_demolition WHERE stale_demolition.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM farmlist stale_farmlist WHERE stale_farmlist.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM raidlist stale_raidlist WHERE stale_raidlist.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM units stale_units WHERE stale_units.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM hero stale_hero WHERE stale_hero.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM enforcement stale_enforcement WHERE stale_enforcement.kid=w.id OR stale_enforcement.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM trapped stale_trapped WHERE stale_trapped.kid=w.id OR stale_trapped.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM blocks b WHERE b.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM marks m WHERE m.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM vdata child WHERE child.expandedfrom=w.id)
           AND NOT EXISTS (SELECT 1 FROM movement movement_ref WHERE movement_ref.kid=w.id OR movement_ref.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM odata oasis_ref WHERE oasis_ref.did=w.id)
         ORDER BY w.id DESC LIMIT 3"
    );
    expect_same(3, $concurrentCelebrationFields->num_rows, 'concurrent celebration fixture fields available');
    $concurrentCelebrationFieldA = $concurrentCelebrationFields->fetch_assoc();
    $concurrentCelebrationFieldB = $concurrentCelebrationFields->fetch_assoc();
    $concurrentCelebrationFieldC = $concurrentCelebrationFields->fetch_assoc();
    $concurrentCelebrationKidA = (int)$concurrentCelebrationFieldA['id'];
    $concurrentCelebrationKidB = (int)$concurrentCelebrationFieldB['id'];
    $concurrentCelebrationKidC = (int)$concurrentCelebrationFieldC['id'];
    $concurrentCelebrationKids = [
        $concurrentCelebrationKidA,
        $concurrentCelebrationKidB,
        $concurrentCelebrationKidC,
    ];
    $concurrentCelebrationKidList = implode(',', $concurrentCelebrationKids);
    $availableRows = $db->query(
        "SELECT kid, occupied FROM available_villages WHERE kid IN ($concurrentCelebrationKidList)"
    );
    while ($availableRow = $availableRows->fetch_assoc()) {
        $concurrentCelebrationAvailableOccupancy[(int)$availableRow['kid']] = (int)$availableRow['occupied'];
    }
    [$concurrentTownHallPop, $concurrentTownHallCp] = Formulas::buildingCpPop(24, 0, 1);
    $concurrentTownHallPop = (int)$concurrentTownHallPop;
    $concurrentTownHallCp = (int)$concurrentTownHallCp;
    expect_same(6, $concurrentTownHallCp, 'concurrent celebration Town Hall CP fixture');
    $now = time();
    $lastUpdate = miliseconds();
    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, kid, cp, cp_prod, lastupdate, total_pop, total_villages, desc1, desc2, note)
        VALUES ($concurrentCelebrationOwner, 'ov-regression-concurrent-celebration', 'OVConCelebrate',
                'x', '', 1, $concurrentCelebrationKidA, 1000, " . (3 * $concurrentTownHallCp) . ",
                $now, " . (3 * $concurrentTownHallPop) . ", 3, '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, loyalty,
         wood, clay, iron, woodp, clayp, ironp, maxstore, crop, cropp, maxcrop, upkeep,
         lastmupdate, created, celebration, type, expandedfrom)
        VALUES
        ($concurrentCelebrationKidA, $concurrentCelebrationOwner, " . (int)$concurrentCelebrationFieldA['fieldtype'] . ", 'OV Concurrent Celebration A', 1,
         $concurrentTownHallPop, $concurrentTownHallCp, 100, 100000, 100000, 100000, 0, 0, 0, 1000000,
         100000, $concurrentTownHallPop, 1000000, 0, $lastUpdate, $now, 0, 0, 0),
        ($concurrentCelebrationKidB, $concurrentCelebrationOwner, " . (int)$concurrentCelebrationFieldB['fieldtype'] . ", 'OV Concurrent Celebration B', 0,
         $concurrentTownHallPop, $concurrentTownHallCp, 100, 100000, 100000, 100000, 0, 0, 0, 1000000,
         100000, $concurrentTownHallPop, 1000000, 0, $lastUpdate, $now, 0, 0, 0),
        ($concurrentCelebrationKidC, $concurrentCelebrationOwner, " . (int)$concurrentCelebrationFieldC['fieldtype'] . ", 'OV Concurrent Celebration C', 0,
         $concurrentTownHallPop, $concurrentTownHallCp, 100, 100000, 100000, 100000, 0, 0, 0, 1000000,
         100000, $concurrentTownHallPop, 1000000, 0, $lastUpdate, $now, 0, 0, 0)");
    $db->query("INSERT INTO fdata (kid, f19, f19t) VALUES
        ($concurrentCelebrationKidA, 1, 24),
        ($concurrentCelebrationKidB, 1, 24),
        ($concurrentCelebrationKidC, 1, 24)");
    $db->query("INSERT INTO daily_quest (uid, qst10) VALUES ($concurrentCelebrationOwner, 0)");
    $db->query("INSERT INTO send (kid, to_kid, wood, clay, iron, crop, x, mode, end_time)
        VALUES ($concurrentCelebrationKidA, $concurrentCelebrationKidA, 1, 2, 3, 4, 1, 0, " . ($now + 3600) . ")");
    $concurrentCelebrationUnrelatedSendId = (int)$db->lastInsertId();
    $db->query("UPDATE wdata SET occupied=1 WHERE id IN ($concurrentCelebrationKidList) AND occupied=0");
    expect_same(3, $db->affectedRows(), 'concurrent celebration fixture fields occupied');
    expect_true($db->commit(), 'concurrent celebration fixture committed');
    $concurrentCelebrationCommitted = true;

    $runCelebrationRace = function (array $commandPrefixes, string $label) use (
        &$concurrentCelebrationWorkers,
        &$concurrentCelebrationBarrierFiles
    ): array {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $barrierPath = tempnam(sys_get_temp_dir(), 'ov-celebration-start-');
        expect_true($barrierPath !== false, "$label start barrier created");
        $concurrentCelebrationBarrierFiles[] = $barrierPath;
        $readyPaths = [];
        foreach ($commandPrefixes as $i => $prefix) {
            $readyPath = tempnam(sys_get_temp_dir(), 'ov-celebration-ready-');
            expect_true($readyPath !== false, "$label worker $i ready signal created");
            $readyPaths[] = $readyPath;
            $concurrentCelebrationBarrierFiles[] = $readyPath;
            $pipes = [];
            $process = proc_open(array_merge($prefix, [$barrierPath, $readyPath]), $descriptorSpec, $pipes);
            expect_true(is_resource($process), "$label worker $i started");
            $concurrentCelebrationWorkers[] = ['process' => $process, 'pipes' => $pipes];
        }

        $readyDeadline = microtime(true) + 10;
        do {
            $ready = true;
            foreach ($readyPaths as $readyPath) {
                if (@file_get_contents($readyPath) !== 'ready') {
                    $ready = false;
                    break;
                }
            }
            if (!$ready) {
                usleep(1000);
            }
        } while (!$ready && microtime(true) < $readyDeadline);
        expect_true($ready, "$label workers ready");
        expect_true(file_put_contents($barrierPath, 'go', LOCK_EX) !== false, "$label workers released");

        $outcomes = [];
        $workerStart = count($concurrentCelebrationWorkers) - count($commandPrefixes);
        foreach ($commandPrefixes as $i => $_prefix) {
            $workerIndex = $workerStart + $i;
            $worker = &$concurrentCelebrationWorkers[$workerIndex];
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            $worker['process'] = null;
            $worker['pipes'] = [];
            expect_same(0, $exitCode, "$label worker $i exit status: $stderr");
            expect_same('', $stderr, "$label worker $i stderr");
            $outcomes[] = $stdout;
            unset($worker);
        }

        return $outcomes;
    };
    $resetConcurrentCelebrations = function () use (
        $db,
        $concurrentCelebrationOwner,
        $concurrentCelebrationKidList
    ): void {
        $db->query(
            "UPDATE vdata SET wood=100000, clay=100000, iron=100000, crop=100000,
                 celebration=0, type=0, lastmupdate=" . miliseconds() . "
             WHERE kid IN ($concurrentCelebrationKidList)"
        );
        $db->query("UPDATE users SET cp=1000, lastupdate=" . time() . " WHERE id=$concurrentCelebrationOwner");
        $db->query("UPDATE daily_quest SET qst10=0 WHERE uid=$concurrentCelebrationOwner");
    };
    $restoreConcurrentTownHallC = function () use (
        $db,
        $concurrentCelebrationOwner,
        $concurrentCelebrationKidC,
        $concurrentTownHallPop,
        $concurrentTownHallCp
    ): void {
        $db->query("UPDATE fdata SET f19=1, f19t=24 WHERE kid=$concurrentCelebrationKidC");
        $db->query(
            "UPDATE vdata SET pop=$concurrentTownHallPop, cp=$concurrentTownHallCp
             WHERE kid=$concurrentCelebrationKidC"
        );
        $db->query(
            "UPDATE users
             SET total_pop=" . (3 * $concurrentTownHallPop) . ", cp_prod=" . (3 * $concurrentTownHallCp) . "
             WHERE id=$concurrentCelebrationOwner"
        );
    };

    $concurrentSmallCost = array_map('intval', Formulas::celebrationCost(false));
    $resetConcurrentCelebrations();
    $db->query(
        "UPDATE vdata
         SET wood=100000, woodp=3600000, maxstore=1000000000, lastmupdate=" . (miliseconds() - 5000) . "
         WHERE kid=$concurrentCelebrationKidA"
    );
    expect_true($db->begin_transaction(), 'celebration stale-snapshot outer transaction opened');
    $snapshotResourceState = $db->query(
        "SELECT wood, lastmupdate FROM vdata WHERE kid=$concurrentCelebrationKidA"
    )->fetch_assoc();
    $resourceWorkerOutcomes = $runCelebrationRace([
        ['php', '/app/tests/resource-settlement-worker.php', (string)$concurrentCelebrationKidA],
    ], 'celebration stale-snapshot settlement');
    $workerResourceState = json_decode($resourceWorkerOutcomes[0], true, 512, JSON_THROW_ON_ERROR);
    expect_true(
        (float)$workerResourceState['wood'] > (float)$snapshotResourceState['wood'],
        'fresh resource settlement advances beyond outer snapshot'
    );
    expect_true(
        (int)$workerResourceState['lastmupdate'] > (int)$snapshotResourceState['lastmupdate'],
        'fresh resource settlement advances update timestamp'
    );
    expect_true(
        (new CelebrationModel())->startCelebration(
            $concurrentCelebrationOwner,
            $concurrentCelebrationKidA,
            1
        ),
        'celebration starts after fresh resource update despite older outer snapshot'
    );
    $finalResourceState = $db->query(
        "SELECT wood, lastmupdate FROM vdata WHERE kid=$concurrentCelebrationKidA"
    )->fetch_assoc();
    $postWorkerProduction = round(
        ((int)$finalResourceState['lastmupdate'] - (int)$workerResourceState['lastmupdate'])
        * 3600000 / 3600000,
        4
    );
    expect_close(
        (float)$workerResourceState['wood'] + $postWorkerProduction - $concurrentSmallCost[0],
        (float)$finalResourceState['wood'],
        0.0001,
        'celebration settles only production after the fresh-process update'
    );
    expect_true($db->rollback(), 'celebration stale-snapshot outer transaction rolled back');
    $db->query(
        "UPDATE vdata SET woodp=0, maxstore=1000000 WHERE kid=$concurrentCelebrationKidA"
    );
    $resetConcurrentCelebrations();

    $sameVillageOutcomes = $runCelebrationRace([
        ['php', '/app/tests/celebration-start-worker.php', (string)$concurrentCelebrationOwner, (string)$concurrentCelebrationKidA, '1'],
        ['php', '/app/tests/celebration-start-worker.php', (string)$concurrentCelebrationOwner, (string)$concurrentCelebrationKidA, '1'],
    ], 'same-village celebration race');
    sort($sameVillageOutcomes);
    expect_same(['false', 'true'], $sameVillageOutcomes, 'same-village celebration race starts once');
    expect_same(1006, (int)$db->fetchScalar("SELECT cp FROM users WHERE id=$concurrentCelebrationOwner"), 'same-village celebration race credits CP once');
    expect_same(1, (int)$db->fetchScalar("SELECT qst10 FROM daily_quest WHERE uid=$concurrentCelebrationOwner"), 'same-village celebration race advances quest once');
    expect_same(
        1,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$concurrentCelebrationKidA AND celebration>" . time() . " AND type=1"),
        'same-village celebration race stores one cooldown'
    );
    foreach (['wood', 'clay', 'iron', 'crop'] as $resourceIndex => $resource) {
        expect_close(
            100000 - $concurrentSmallCost[$resourceIndex],
            (float)$db->fetchScalar("SELECT $resource FROM vdata WHERE kid=$concurrentCelebrationKidA"),
            0.0001,
            "same-village celebration race charges $resource once"
        );
    }

    $resetConcurrentCelebrations();
    $sameOwnerOutcomes = $runCelebrationRace([
        ['php', '/app/tests/celebration-start-worker.php', (string)$concurrentCelebrationOwner, (string)$concurrentCelebrationKidA, '1'],
        ['php', '/app/tests/celebration-start-worker.php', (string)$concurrentCelebrationOwner, (string)$concurrentCelebrationKidB, '1'],
    ], 'same-owner celebration race');
    expect_same(['true', 'true'], $sameOwnerOutcomes, 'same-owner celebration race starts both villages');
    expect_same(1012, (int)$db->fetchScalar("SELECT cp FROM users WHERE id=$concurrentCelebrationOwner"), 'same-owner celebration race preserves both CP increments');
    expect_same(2, (int)$db->fetchScalar("SELECT qst10 FROM daily_quest WHERE uid=$concurrentCelebrationOwner"), 'same-owner celebration race preserves both quest steps');
    expect_same(
        2,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM vdata
             WHERE kid IN ($concurrentCelebrationKidA, $concurrentCelebrationKidB)
               AND celebration>" . time() . " AND type=1"
        ),
        'same-owner celebration race stores both cooldowns'
    );
    foreach ([$concurrentCelebrationKidA, $concurrentCelebrationKidB] as $raceKid) {
        foreach (['wood', 'clay', 'iron', 'crop'] as $resourceIndex => $resource) {
            expect_close(
                100000 - $concurrentSmallCost[$resourceIndex],
                (float)$db->fetchScalar("SELECT $resource FROM vdata WHERE kid=$raceKid"),
                0.0001,
                "same-owner celebration race charges village $raceKid $resource once"
            );
        }
    }

    $resetConcurrentCelebrations();
    $db->query("INSERT INTO demolition (kid, building_field, end_time, complete)
        VALUES ($concurrentCelebrationKidC, 19, " . ($now + 3600) . ", 1)");
    $concurrentCelebrationDemolitionTask = (int)$db->lastInsertId();
    $concurrentCelebrationDemolitionTaskIds[] = $concurrentCelebrationDemolitionTask;
    $demolitionCelebrationOutcomes = $runCelebrationRace([
        ['php', '/app/tests/celebration-start-worker.php', (string)$concurrentCelebrationOwner, (string)$concurrentCelebrationKidC, '1'],
        ['php', '/app/tests/demolition-task-worker.php', (string)$concurrentCelebrationDemolitionTask],
    ], 'celebration-demolition race');
    expect_true(in_array($demolitionCelebrationOutcomes[0], ['false', 'true'], true), 'celebration-demolition race returns valid celebration outcome');
    expect_same('true', $demolitionCelebrationOutcomes[1], 'celebration-demolition race completes demolition');
    expect_same(
        '0|0|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(f19, '|', f19t, '|',
                (SELECT COUNT(*) FROM demolition WHERE id=$concurrentCelebrationDemolitionTask))
             FROM fdata WHERE kid=$concurrentCelebrationKidC"
        ),
        'celebration-demolition race removes Town Hall and consumes task'
    );
    if ($demolitionCelebrationOutcomes[0] === 'true') {
        expect_same(1006, (int)$db->fetchScalar("SELECT cp FROM users WHERE id=$concurrentCelebrationOwner"), 'start-first demolition race keeps immediate CP reward');
        expect_same(1, (int)$db->fetchScalar("SELECT qst10 FROM daily_quest WHERE uid=$concurrentCelebrationOwner"), 'start-first demolition race keeps quest step');
        expect_same(
            1,
            (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$concurrentCelebrationKidC AND celebration>" . time() . " AND type=1"),
            'start-first demolition race preserves ongoing celebration'
        );
        foreach (['wood', 'clay', 'iron', 'crop'] as $resourceIndex => $resource) {
            expect_close(
                100000 - $concurrentSmallCost[$resourceIndex],
                (float)$db->fetchScalar("SELECT $resource FROM vdata WHERE kid=$concurrentCelebrationKidC"),
                0.0001,
                "start-first demolition race keeps $resource charge"
            );
        }
    } else {
        expect_same(1000, (int)$db->fetchScalar("SELECT cp FROM users WHERE id=$concurrentCelebrationOwner"), 'demolition-first celebration race grants no CP');
        expect_same(0, (int)$db->fetchScalar("SELECT qst10 FROM daily_quest WHERE uid=$concurrentCelebrationOwner"), 'demolition-first celebration race grants no quest step');
        expect_same(
            '100000.0000000000|100000.0000000000|100000.0000000000|100000.0000000000|0|0',
            (string)$db->fetchScalar(
                "SELECT CONCAT(wood, '|', clay, '|', iron, '|', crop, '|', celebration, '|', type)
                 FROM vdata WHERE kid=$concurrentCelebrationKidC"
            ),
            'demolition-first celebration race leaves resources and cooldown untouched'
        );
    }

    $resetConcurrentCelebrations();
    $restoreConcurrentTownHallC();
    $db->query("INSERT INTO demolition (kid, building_field, end_time, complete)
        VALUES ($concurrentCelebrationKidC, 19, " . ($now + 3601) . ", 1)");
    $startFirstDemolitionTask = (int)$db->lastInsertId();
    $concurrentCelebrationDemolitionTaskIds[] = $startFirstDemolitionTask;
    expect_true(
        (new CelebrationModel())->startCelebration(
            $concurrentCelebrationOwner,
            $concurrentCelebrationKidC,
            1
        ),
        'controlled start-first celebration succeeds'
    );
    expect_true(
        Automation::getInstance()->processDemolitionTask($startFirstDemolitionTask),
        'controlled start-first Town Hall demolition succeeds'
    );
    expect_same(
        '0|0|1|1|1006',
        (string)$db->fetchScalar(
            "SELECT CONCAT(f.f19, '|', f.f19t, '|', v.type, '|', v.celebration>" . time() . ", '|', u.cp)
             FROM fdata f JOIN vdata v ON v.kid=f.kid JOIN users u ON u.id=v.owner
             WHERE f.kid=$concurrentCelebrationKidC"
        ),
        'controlled start-first ordering preserves immediate reward and ongoing celebration'
    );
    expect_same(1, (int)$db->fetchScalar("SELECT qst10 FROM daily_quest WHERE uid=$concurrentCelebrationOwner"), 'controlled start-first ordering advances quest');
    foreach (['wood', 'clay', 'iron', 'crop'] as $resourceIndex => $resource) {
        expect_close(
            100000 - $concurrentSmallCost[$resourceIndex],
            (float)$db->fetchScalar("SELECT $resource FROM vdata WHERE kid=$concurrentCelebrationKidC"),
            0.0001,
            "controlled start-first ordering charges $resource once"
        );
    }

    $resetConcurrentCelebrations();
    $restoreConcurrentTownHallC();
    $db->query("INSERT INTO demolition (kid, building_field, end_time, complete)
        VALUES ($concurrentCelebrationKidC, 19, " . ($now + 3602) . ", 1)");
    $demolitionFirstTask = (int)$db->lastInsertId();
    $concurrentCelebrationDemolitionTaskIds[] = $demolitionFirstTask;
    expect_true(
        Automation::getInstance()->processDemolitionTask($demolitionFirstTask),
        'controlled demolition-first Town Hall demolition succeeds'
    );
    expect_same(
        false,
        (new CelebrationModel())->startCelebration(
            $concurrentCelebrationOwner,
            $concurrentCelebrationKidC,
            1
        ),
        'controlled demolition-first celebration is rejected'
    );
    expect_same(
        '0|0|0|0|1000|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(f.f19, '|', f.f19t, '|', v.type, '|', v.celebration, '|', u.cp, '|', q.qst10)
             FROM fdata f JOIN vdata v ON v.kid=f.kid JOIN users u ON u.id=v.owner
             JOIN daily_quest q ON q.uid=u.id WHERE f.kid=$concurrentCelebrationKidC"
        ),
        'controlled demolition-first ordering leaves celebration state untouched'
    );
    foreach (['wood', 'clay', 'iron', 'crop'] as $resource) {
        expect_close(
            100000,
            (float)$db->fetchScalar("SELECT $resource FROM vdata WHERE kid=$concurrentCelebrationKidC"),
            0.0001,
            "controlled demolition-first ordering preserves $resource"
        );
    }
    expect_same(
        "$concurrentCelebrationKidA|$concurrentCelebrationKidA|1|2|3|4",
        (string)$db->fetchScalar(
            "SELECT CONCAT(kid, '|', to_kid, '|', wood, '|', clay, '|', iron, '|', crop)
             FROM send WHERE id=$concurrentCelebrationUnrelatedSendId"
        ),
        'concurrent celebration fixture preserves unrelated candidate data'
    );
} finally {
    foreach ($concurrentCelebrationWorkers as &$worker) {
        if (!isset($worker['process']) || !is_resource($worker['process'])) {
            continue;
        }
        $status = proc_get_status($worker['process']);
        if (!empty($status['running'])) {
            proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
        $worker['process'] = null;
        $worker['pipes'] = [];
    }
    unset($worker);
    foreach ($concurrentCelebrationBarrierFiles as $barrierFile) {
        if (is_string($barrierFile) && file_exists($barrierFile)) {
            unlink($barrierFile);
        }
    }

    if (!$concurrentCelebrationCommitted) {
        $db->rollback();
    }
    if ($concurrentCelebrationKids !== []) {
        $kidList = implode(',', array_map('intval', $concurrentCelebrationKids));
        if ($concurrentCelebrationDemolitionTaskIds !== []) {
            $demolitionTaskList = implode(',', array_map('intval', $concurrentCelebrationDemolitionTaskIds));
            $db->query(
                "DELETE FROM scheduled_task_failures
                 WHERE task_table='demolition' AND task_id IN ($demolitionTaskList)"
            );
        }
        $db->query("DELETE FROM demolition WHERE kid IN ($kidList)");
        if ($concurrentCelebrationUnrelatedSendId > 0) {
            $db->query("DELETE FROM send WHERE id=$concurrentCelebrationUnrelatedSendId");
        }
        $db->query("DELETE FROM daily_quest WHERE uid=$concurrentCelebrationOwner");
        $db->query("DELETE FROM fdata WHERE kid IN ($kidList)");
        $db->query("DELETE FROM vdata WHERE kid IN ($kidList)");
        $db->query("DELETE FROM users WHERE id=$concurrentCelebrationOwner");
        $db->query("UPDATE wdata SET occupied=0 WHERE id IN ($kidList)");
        foreach ($concurrentCelebrationAvailableOccupancy as $kid => $occupied) {
            $db->query(
                "UPDATE available_villages SET occupied=" . (int)$occupied . " WHERE kid=" . (int)$kid
            );
        }
    }
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE demolition AUTO_INCREMENT=$demolitionAutoIncrement");
    $db->query("ALTER TABLE send AUTO_INCREMENT=$sendAutoIncrement");
}

$breweryOwner = 2000000056;
$breweryForeignOwner = 2000000057;
$breweryRomanOwner = 2000000058;
$breweryKids = [];
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM users WHERE id IN ($breweryOwner, $breweryForeignOwner, $breweryRomanOwner)"
        ),
        'Brewery fixture user IDs available'
    );
    $breweryFields = $db->query(
        "SELECT w.id, w.fieldtype
         FROM wdata w LEFT JOIN vdata v ON v.kid=w.id
         WHERE w.id>0 AND w.occupied=0 AND w.oasistype=0 AND v.kid IS NULL
           AND NOT EXISTS (SELECT 1 FROM fdata stale_fdata WHERE stale_fdata.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM movement movement_ref WHERE movement_ref.kid=w.id OR movement_ref.to_kid=w.id)
         ORDER BY w.id DESC LIMIT 4"
    );
    expect_same(4, $breweryFields->num_rows, 'Brewery fixture fields available');
    $breweryCapitalField = $breweryFields->fetch_assoc();
    $breweryOtherField = $breweryFields->fetch_assoc();
    $breweryForeignField = $breweryFields->fetch_assoc();
    $breweryRomanField = $breweryFields->fetch_assoc();
    $breweryCapitalKid = (int)$breweryCapitalField['id'];
    $breweryOtherKid = (int)$breweryOtherField['id'];
    $breweryForeignKid = (int)$breweryForeignField['id'];
    $breweryRomanKid = (int)$breweryRomanField['id'];
    $breweryKids = [$breweryCapitalKid, $breweryOtherKid, $breweryForeignKid, $breweryRomanKid];
    $breweryKidList = implode(',', $breweryKids);
    $breweryNow = time();
    $breweryLastUpdate = miliseconds();
    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, kid, total_pop, total_villages, desc1, desc2, note)
        VALUES
        ($breweryOwner, 'ov-regression-brewery-owner', 'OVBreweryOwner', 'x', '', 2, $breweryCapitalKid, 200, 2, '', '', ''),
        ($breweryForeignOwner, 'ov-regression-brewery-foreign', 'OVBreweryForeign', 'x', '', 2, $breweryForeignKid, 100, 1, '', '', ''),
        ($breweryRomanOwner, 'ov-regression-brewery-roman', 'OVBreweryRoman', 'x', '', 1, $breweryRomanKid, 100, 1, '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, isWW, pop, cp, loyalty,
         wood, clay, iron, woodp, clayp, ironp, maxstore, crop, cropp, maxcrop, upkeep,
         lastmupdate, created, festival, expandedfrom)
        VALUES
        ($breweryCapitalKid, $breweryOwner, " . (int)$breweryCapitalField['fieldtype'] . ", 'OV Brewery Capital', 1, 0, 100, 0, 100,
         100000, 100000, 100000, 0, 0, 0, 1000000, 100000, 0, 1000000, 0,
         $breweryLastUpdate, $breweryNow, 1234, 0),
        ($breweryOtherKid, $breweryOwner, " . (int)$breweryOtherField['fieldtype'] . ", 'OV Brewery Other', 0, 0, 100, 0, 100,
         100000, 100000, 100000, 0, 0, 0, 1000000, 100000, 0, 1000000, 0,
         $breweryLastUpdate, $breweryNow, " . ($breweryNow + 90000) . ", 0),
        ($breweryForeignKid, $breweryForeignOwner, " . (int)$breweryForeignField['fieldtype'] . ", 'OV Brewery Foreign', 1, 0, 100, 0, 100,
         100000, 100000, 100000, 0, 0, 0, 1000000, 100000, 0, 1000000, 0,
         $breweryLastUpdate, $breweryNow, 0, 0),
        ($breweryRomanKid, $breweryRomanOwner, " . (int)$breweryRomanField['fieldtype'] . ", 'OV Brewery Roman', 1, 0, 100, 0, 100,
         100000, 100000, 100000, 0, 0, 0, 1000000, 100000, 0, 1000000, 0,
         $breweryLastUpdate, $breweryNow, 0, 0)");
    $db->query("INSERT INTO fdata (kid, f19, f19t, f20, f20t) VALUES
        ($breweryCapitalKid, 20, 35, 0, 0),
        ($breweryOtherKid, 1, 26, 0, 0),
        ($breweryForeignKid, 20, 35, 0, 0),
        ($breweryRomanKid, 20, 35, 0, 0)");
    $db->query("INSERT INTO units (kid, race) VALUES
        ($breweryCapitalKid, 2), ($breweryOtherKid, 2),
        ($breweryForeignKid, 2), ($breweryRomanKid, 1)");
    $db->query("UPDATE wdata SET occupied=1 WHERE id IN ($breweryKidList) AND occupied=0");
    expect_same(4, $db->affectedRows(), 'Brewery fixture fields occupied');

    $breweryModel = new BreweryModel();
    expect_same(
        ['startedAt' => 0, 'endsAt' => 0, 'active' => false],
        $breweryModel->getFestivalStatus(0),
        'Brewery status rejects missing owner'
    );
    expect_same(
        ['festivalActive' => false, 'breweryLevel' => 0],
        $breweryModel->getBattleEffects($breweryOwner, $breweryNow),
        'inactive Brewery festival grants no battle effects'
    );

    $breweryState = function () use (
        $db,
        $breweryOwner,
        $breweryForeignOwner,
        $breweryRomanOwner,
        $breweryKidList
    ): array {
        return [
            'users' => (string)$db->fetchScalar(
                "SELECT GROUP_CONCAT(CONCAT(id, ':', brewery_festival_started_at, ':', brewery_festival_ends_at)
                 ORDER BY id SEPARATOR '|')
                 FROM users WHERE id IN ($breweryOwner, $breweryForeignOwner, $breweryRomanOwner)"
            ),
            'villages' => (string)$db->fetchScalar(
                "SELECT GROUP_CONCAT(CONCAT(kid, ':', owner, ':', capital, ':', isWW, ':', wood, ':', clay, ':', iron,
                    ':', crop, ':', festival, ':', lastmupdate) ORDER BY kid SEPARATOR '|')
                 FROM vdata WHERE kid IN ($breweryKidList)"
            ),
            'fields' => (string)$db->fetchScalar(
                "SELECT GROUP_CONCAT(CONCAT(kid, ':', f19, ':', f19t, ':', f20, ':', f20t)
                 ORDER BY kid SEPARATOR '|') FROM fdata WHERE kid IN ($breweryKidList)"
            ),
        ];
    };
    $resetBrewery = function () use (
        $db,
        $breweryOwner,
        $breweryForeignOwner,
        $breweryRomanOwner,
        $breweryCapitalKid,
        $breweryOtherKid,
        $breweryForeignKid,
        $breweryRomanKid,
        $breweryNow
    ): void {
        $db->query(
            "UPDATE users SET brewery_festival_started_at=0, brewery_festival_ends_at=0
             WHERE id IN ($breweryOwner, $breweryForeignOwner, $breweryRomanOwner)"
        );
        $db->query(
            "UPDATE vdata SET capital=CASE kid WHEN $breweryCapitalKid THEN 1 WHEN $breweryForeignKid THEN 1
                    WHEN $breweryRomanKid THEN 1 ELSE 0 END,
                 isWW=0, wood=100000, clay=100000, iron=100000, crop=100000, cropp=pop, upkeep=0,
                 festival=CASE kid WHEN $breweryCapitalKid THEN 1234 WHEN $breweryOtherKid THEN " . ($breweryNow + 90000) . " ELSE 0 END,
                 lastmupdate=" . miliseconds() . "
             WHERE kid IN ($breweryCapitalKid, $breweryOtherKid, $breweryForeignKid, $breweryRomanKid)"
        );
        $db->query("UPDATE fdata SET f19=20, f19t=35, f20=0, f20t=0
            WHERE kid IN ($breweryCapitalKid, $breweryForeignKid, $breweryRomanKid)");
        $db->query("UPDATE fdata SET f19=1, f19t=26, f20=0, f20t=0 WHERE kid=$breweryOtherKid");
    };

    $breweryMigrationSql = file_get_contents('/app/main_script/include/schema/migrations/004_brewery_festivals.sql');
    expect_true($breweryMigrationSql !== false, 'Brewery migration SQL is readable');
    $breweryMigrationUpdateOffset = strpos($breweryMigrationSql, 'UPDATE users u');
    expect_true($breweryMigrationUpdateOffset !== false, 'Brewery migration data step is present');
    $breweryMigrationUpdate = substr($breweryMigrationSql, $breweryMigrationUpdateOffset);
    $legacyCapitalFestivalEnd = $breweryNow + 7200;
    $capturedResidueFestivalEnd = $breweryNow + 90000;
    $romanLegacyFestivalEnd = $breweryNow + 10800;
    $db->query(
        "UPDATE vdata SET festival=CASE kid
             WHEN $breweryCapitalKid THEN $legacyCapitalFestivalEnd
             WHEN $breweryOtherKid THEN $capturedResidueFestivalEnd
             WHEN $breweryRomanKid THEN $romanLegacyFestivalEnd
             ELSE 0 END,
             owner=CASE kid WHEN $breweryOtherKid THEN $breweryForeignOwner ELSE owner END
         WHERE kid IN ($breweryCapitalKid, $breweryOtherKid, $breweryForeignKid, $breweryRomanKid)"
    );
    expect_true($db->query($breweryMigrationUpdate), 'Brewery legacy migration data step succeeds');
    expect_same(
        ($legacyCapitalFestivalEnd - 259200) . '|' . $legacyCapitalFestivalEnd,
        (string)$db->fetchScalar(
            "SELECT CONCAT(brewery_festival_started_at, '|', brewery_festival_ends_at)
             FROM users WHERE id=$breweryOwner"
        ),
        'Brewery migration promotes an eligible current-capital festival'
    );
    expect_same(
        '0|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(brewery_festival_started_at, '|', brewery_festival_ends_at)
             FROM users WHERE id=$breweryForeignOwner"
        ),
        'Brewery migration ignores captured non-capital festival residue'
    );
    expect_same(
        '0|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(brewery_festival_started_at, '|', brewery_festival_ends_at)
             FROM users WHERE id=$breweryRomanOwner"
        ),
        'Brewery migration ignores non-Teuton legacy festivals'
    );
    $db->query(
        "UPDATE users SET brewery_festival_started_at=0, brewery_festival_ends_at=0
         WHERE id IN ($breweryOwner, $breweryForeignOwner, $breweryRomanOwner)"
    );
    $db->query(
        "UPDATE vdata SET owner=CASE kid WHEN $breweryOtherKid THEN $breweryOwner ELSE owner END,
             festival=CASE kid WHEN $breweryCapitalKid THEN 1234
                 WHEN $breweryOtherKid THEN " . ($breweryNow + 90000) . " ELSE 0 END
         WHERE kid IN ($breweryCapitalKid, $breweryOtherKid, $breweryForeignKid, $breweryRomanKid)"
    );

    $baselineBreweryState = $breweryState();
    expect_same(false, $breweryModel->startFestival(0, $breweryCapitalKid), 'Brewery start rejects missing owner');
    expect_same(false, $breweryModel->startFestival($breweryOwner, 0), 'Brewery start rejects missing village');
    expect_same(false, $breweryModel->startFestival($breweryOwner, $breweryForeignKid), 'Brewery start rejects foreign village');
    expect_same(false, $breweryModel->startFestival($breweryOwner, $breweryOtherKid), 'Brewery start rejects non-capital village');
    expect_same(false, $breweryModel->startFestival($breweryRomanOwner, $breweryRomanKid), 'Brewery start rejects non-Teuton owner');
    expect_same($baselineBreweryState, $breweryState(), 'basic Brewery rejections preserve state');

    $db->query("UPDATE fdata SET f19=0, f19t=0 WHERE kid=$breweryCapitalKid");
    $noBreweryState = $breweryState();
    expect_same(false, $breweryModel->startFestival($breweryOwner, $breweryCapitalKid), 'Brewery start requires a built Brewery');
    expect_same($noBreweryState, $breweryState(), 'missing Brewery rejection preserves state');
    $db->query("UPDATE fdata SET f19=0, f19t=35 WHERE kid=$breweryCapitalKid");
    $levelZeroBreweryState = $breweryState();
    expect_same(false, $breweryModel->startFestival($breweryOwner, $breweryCapitalKid), 'Brewery start rejects level-zero Brewery');
    expect_same($levelZeroBreweryState, $breweryState(), 'level-zero Brewery rejection preserves state');
    $db->query("UPDATE fdata SET f19=20, f19t=35 WHERE kid=$breweryCapitalKid");

    $db->query("UPDATE vdata SET isWW=1 WHERE kid=$breweryCapitalKid");
    $wonderBreweryState = $breweryState();
    expect_same(false, $breweryModel->startFestival($breweryOwner, $breweryCapitalKid), 'Brewery start rejects World Wonder capital');
    expect_same($wonderBreweryState, $breweryState(), 'World Wonder Brewery rejection preserves state');
    $db->query("UPDATE vdata SET isWW=0, capital=0 WHERE kid=$breweryCapitalKid");
    $noCapitalBreweryState = $breweryState();
    expect_same(false, $breweryModel->startFestival($breweryOwner, $breweryCapitalKid), 'Brewery start requires exactly one capital');
    expect_same($noCapitalBreweryState, $breweryState(), 'missing capital rejection preserves state');
    $db->query("UPDATE vdata SET capital=1 WHERE kid IN ($breweryCapitalKid, $breweryOtherKid)");
    $multipleCapitalBreweryState = $breweryState();
    expect_same(false, $breweryModel->startFestival($breweryOwner, $breweryCapitalKid), 'Brewery start rejects multiple capitals');
    expect_same($multipleCapitalBreweryState, $breweryState(), 'multiple-capital rejection preserves state');
    $db->query("UPDATE vdata SET capital=0 WHERE kid=$breweryOtherKid");

    $db->query(
        "UPDATE users SET brewery_festival_started_at=" . ($breweryNow - 1) . ",
             brewery_festival_ends_at=" . ($breweryNow + 60) . " WHERE id=$breweryOwner"
    );
    $activeBreweryState = $breweryState();
    expect_same(false, $breweryModel->startFestival($breweryOwner, $breweryCapitalKid), 'Brewery start rejects active account festival');
    expect_same($activeBreweryState, $breweryState(), 'active Brewery rejection preserves state');

    $festivalCost = array_map('intval', Formulas::getFestivalResources());
    foreach (['wood', 'clay', 'iron', 'crop'] as $resourceIndex => $resource) {
        $resetBrewery();
        $db->query("UPDATE vdata SET $resource=" . ($festivalCost[$resourceIndex] - 1) . " WHERE kid=$breweryCapitalKid");
        $insufficientBreweryState = $breweryState();
        expect_same(
            false,
            $breweryModel->startFestival($breweryOwner, $breweryCapitalKid),
            "Brewery start rejects insufficient $resource"
        );
        expect_same($insufficientBreweryState, $breweryState(), "insufficient Brewery $resource preserves state");
    }

    $resetBrewery();
    $festivalStartBefore = time();
    expect_true($breweryModel->startFestival($breweryOwner, $breweryCapitalKid), 'level-twenty Brewery festival starts');
    $festivalStartAfter = time();
    $festivalUser = $db->query(
        "SELECT brewery_festival_started_at, brewery_festival_ends_at FROM users WHERE id=$breweryOwner"
    )->fetch_assoc();
    $festivalStartedAt = (int)$festivalUser['brewery_festival_started_at'];
    $festivalEndsAt = (int)$festivalUser['brewery_festival_ends_at'];
    expect_true(
        $festivalStartedAt >= $festivalStartBefore && $festivalStartedAt <= $festivalStartAfter,
        'Brewery festival stores canonical start time'
    );
    expect_same($festivalStartedAt + 51840, $festivalEndsAt, 'Brewery festival stores x10 end time');
    foreach (['wood', 'clay', 'iron', 'crop'] as $resourceIndex => $resource) {
        expect_close(
            100000 - $festivalCost[$resourceIndex],
            (float)$db->fetchScalar("SELECT $resource FROM vdata WHERE kid=$breweryCapitalKid"),
            0.0001,
            "Brewery festival deducts exact $resource"
        );
    }
    expect_same(1234, (int)$db->fetchScalar("SELECT festival FROM vdata WHERE kid=$breweryCapitalKid"), 'Brewery start leaves legacy village timestamp non-authoritative');
    expect_same(
        ['startedAt' => $festivalStartedAt, 'endsAt' => $festivalEndsAt, 'active' => true],
        $breweryModel->getFestivalStatus($breweryOwner, $festivalStartedAt),
        'Brewery account status is active at start boundary'
    );
    expect_same(
        ['festivalActive' => false, 'breweryLevel' => 0],
        $breweryModel->getBattleEffects($breweryOwner, $festivalStartedAt - 1),
        'Brewery effects do not apply before event start'
    );
    expect_same(
        ['festivalActive' => true, 'breweryLevel' => 20],
        $breweryModel->getBattleEffects($breweryOwner, $festivalStartedAt),
        'active level-twenty Brewery grants exact battle snapshot'
    );
    expect_same(
        ['festivalActive' => false, 'breweryLevel' => 0],
        $breweryModel->getBattleEffects($breweryOwner, $festivalEndsAt),
        'Brewery effects expire at end boundary'
    );
    $successfulBreweryState = $breweryState();
    expect_same(false, $breweryModel->startFestival($breweryOwner, $breweryCapitalKid), 'Brewery festival replay is rejected');
    expect_same($successfulBreweryState, $breweryState(), 'Brewery festival replay preserves committed state');

    $db->query("UPDATE fdata SET f19=0, f19t=0 WHERE kid=$breweryCapitalKid");
    expect_same(
        ['festivalActive' => true, 'breweryLevel' => 0],
        $breweryModel->getBattleEffects($breweryOwner, $festivalStartedAt + 1),
        'destroyed Brewery preserves chief penalty but removes level effects'
    );
    $db->query("UPDATE vdata SET capital=0 WHERE kid=$breweryCapitalKid");
    $db->query("UPDATE vdata SET capital=1 WHERE kid=$breweryOtherKid");
    $db->query("UPDATE fdata SET f20=7, f20t=35 WHERE kid=$breweryOtherKid");
    expect_same(
        ['festivalActive' => true, 'breweryLevel' => 7],
        $breweryModel->getBattleEffects($breweryOwner, $festivalStartedAt + 1),
        'rebuilt Brewery in moved capital restores level effects immediately'
    );

    $resetBrewery();
    $nestedBreweryBaseline = $breweryState();
    expect_true($db->begin_transaction(), 'Brewery caller savepoint opened');
    try {
        expect_true($breweryModel->startFestival($breweryOwner, $breweryCapitalKid), 'nested Brewery festival succeeds before caller failure');
        throw new RuntimeException('simulated Brewery caller failure');
    } catch (RuntimeException $e) {
        expect_true($db->rollback(), 'Brewery caller savepoint rolled back');
        expect_same('simulated Brewery caller failure', $e->getMessage(), 'Brewery caller failure propagated');
    }
    expect_same($nestedBreweryBaseline, $breweryState(), 'caller rollback restores complete Brewery state');
    $db->query("UPDATE fdata SET f19=1, f19t=35 WHERE kid=$breweryCapitalKid");
    expect_true($breweryModel->startFestival($breweryOwner, $breweryCapitalKid), 'level-one Brewery retry succeeds after caller rollback');
    $levelOneStatus = $breweryModel->getFestivalStatus($breweryOwner);
    expect_same(
        ['festivalActive' => true, 'breweryLevel' => 1],
        $breweryModel->getBattleEffects($breweryOwner, $levelOneStatus['startedAt']),
        'level-one Brewery grants one-percent battle snapshot'
    );

    $db->query(
        "UPDATE users SET brewery_festival_started_at=" . ($breweryNow - 10) . ",
             brewery_festival_ends_at=" . ($breweryNow + 1000) . " WHERE id=$breweryOwner"
    );
    $db->query("UPDATE users SET brewery_festival_started_at=0, brewery_festival_ends_at=0 WHERE id=$breweryForeignOwner");
    $db->query("UPDATE vdata SET festival=" . ($breweryNow + 90000) . " WHERE kid=$breweryOtherKid");
    expect_true(
        (new VillageModel())->captureVillage(
            $breweryOwner,
            $breweryOtherKid,
            100,
            $breweryForeignOwner,
            100,
            2,
            $breweryCapitalKid
        ),
        'capturing a legacy Brewery-origin village succeeds'
    );
    expect_same($breweryForeignOwner, (int)$db->fetchScalar("SELECT owner FROM vdata WHERE kid=$breweryOtherKid"), 'captured Brewery-origin village changes owner');
    expect_same(0, (int)$db->fetchScalar("SELECT festival FROM vdata WHERE kid=$breweryOtherKid"), 'capture clears legacy village festival timestamp');
    expect_same(
        "$breweryNow",
        (string)$db->fetchScalar(
            "SELECT brewery_festival_ends_at-1000 FROM users WHERE id=$breweryOwner"
        ),
        'capture preserves old owner account festival'
    );
    expect_same(
        '0|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(brewery_festival_started_at, '|', brewery_festival_ends_at)
             FROM users WHERE id=$breweryForeignOwner"
        ),
        'capture does not transfer festival to new owner'
    );
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE surrounding AUTO_INCREMENT=$surroundingAutoIncrement");
}

$concurrentBreweryOwner = 2000000059;
$concurrentBreweryKids = [];
$concurrentBreweryCommitted = false;
$concurrentBreweryAvailableOccupancy = [];
$concurrentBreweryWorkers = [];
$concurrentBreweryBarrierFiles = [];
$concurrentBreweryDemolitionTaskIds = [];
$concurrentBreweryUnrelatedSendId = 0;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$concurrentBreweryOwner"),
        'concurrent Brewery fixture user ID available'
    );
    $concurrentBreweryFields = $db->query(
        "SELECT w.id, w.fieldtype
         FROM wdata w LEFT JOIN vdata v ON v.kid=w.id
         WHERE w.id>0 AND w.occupied=0 AND w.oasistype=0 AND v.kid IS NULL
           AND NOT EXISTS (SELECT 1 FROM fdata stale_fdata WHERE stale_fdata.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM research stale_research WHERE stale_research.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM tdata stale_tdata WHERE stale_tdata.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM smithy stale_smithy WHERE stale_smithy.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM training stale_training WHERE stale_training.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM odelete stale_odelete WHERE stale_odelete.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM traderoutes stale_route WHERE stale_route.kid=w.id OR stale_route.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM building_upgrade stale_build WHERE stale_build.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM demolition stale_demolition WHERE stale_demolition.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM send stale_send WHERE stale_send.kid=w.id OR stale_send.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM blocks b WHERE b.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM marks m WHERE m.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM vdata child WHERE child.expandedfrom=w.id)
           AND NOT EXISTS (SELECT 1 FROM movement movement_ref WHERE movement_ref.kid=w.id OR movement_ref.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM odata oasis_ref WHERE oasis_ref.did=w.id)
         ORDER BY w.id DESC LIMIT 2"
    );
    expect_same(2, $concurrentBreweryFields->num_rows, 'concurrent Brewery fixture fields available');
    $concurrentBreweryCapitalField = $concurrentBreweryFields->fetch_assoc();
    $concurrentBreweryOtherField = $concurrentBreweryFields->fetch_assoc();
    $concurrentBreweryCapitalKid = (int)$concurrentBreweryCapitalField['id'];
    $concurrentBreweryOtherKid = (int)$concurrentBreweryOtherField['id'];
    $concurrentBreweryKids = [$concurrentBreweryCapitalKid, $concurrentBreweryOtherKid];
    $concurrentBreweryKidList = implode(',', $concurrentBreweryKids);
    $availableBreweryRows = $db->query(
        "SELECT kid, occupied FROM available_villages WHERE kid IN ($concurrentBreweryKidList)"
    );
    while ($availableBreweryRow = $availableBreweryRows->fetch_assoc()) {
        $concurrentBreweryAvailableOccupancy[(int)$availableBreweryRow['kid']] = (int)$availableBreweryRow['occupied'];
    }
    [$concurrentBreweryPop, $concurrentBreweryCp] = Formulas::buildingCpPop(35, 0, 20);
    [$concurrentPalacePop, $concurrentPalaceCp] = Formulas::buildingCpPop(26, 0, 1);
    $concurrentBreweryPop = (int)$concurrentBreweryPop;
    $concurrentBreweryCp = (int)$concurrentBreweryCp;
    $concurrentPalacePop = (int)$concurrentPalacePop;
    $concurrentPalaceCp = (int)$concurrentPalaceCp;
    expect_same(83, $concurrentBreweryPop, 'level-twenty Brewery population fixture');
    expect_same(153, $concurrentBreweryCp, 'level-twenty Brewery CP fixture');
    $concurrentBreweryNow = time();
    $concurrentBreweryLastUpdate = miliseconds();
    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, kid, total_pop, total_villages, cp_prod, desc1, desc2, note)
        VALUES ($concurrentBreweryOwner, 'ov-regression-concurrent-brewery', 'OVConBrewery', 'x', '', 2,
                $concurrentBreweryCapitalKid, " . ($concurrentBreweryPop + $concurrentPalacePop) . ", 2,
                " . ($concurrentBreweryCp + $concurrentPalaceCp) . ", '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, isWW, pop, cp, loyalty,
         wood, clay, iron, woodp, clayp, ironp, maxstore, crop, cropp, maxcrop, upkeep,
         lastmupdate, created, festival, expandedfrom)
        VALUES
        ($concurrentBreweryCapitalKid, $concurrentBreweryOwner, " . (int)$concurrentBreweryCapitalField['fieldtype'] . ", 'OV Concurrent Brewery Capital', 1, 0,
         $concurrentBreweryPop, $concurrentBreweryCp, 100, 100000, 100000, 100000, 0, 0, 0, 1000000,
         100000, $concurrentBreweryPop, 1000000, 0, $concurrentBreweryLastUpdate, $concurrentBreweryNow, 0, 0),
        ($concurrentBreweryOtherKid, $concurrentBreweryOwner, " . (int)$concurrentBreweryOtherField['fieldtype'] . ", 'OV Concurrent Brewery Other', 0, 0,
         $concurrentPalacePop, $concurrentPalaceCp, 100, 100000, 100000, 100000, 0, 0, 0, 1000000,
         100000, $concurrentPalacePop, 1000000, 0, $concurrentBreweryLastUpdate, $concurrentBreweryNow, 0, 0)");
    $db->query("INSERT INTO fdata (kid, f19, f19t, f20, f20t) VALUES
        ($concurrentBreweryCapitalKid, 20, 35, 0, 0),
        ($concurrentBreweryOtherKid, 1, 26, 0, 0)");
    $db->query("INSERT INTO units (kid, race) VALUES
        ($concurrentBreweryCapitalKid, 2), ($concurrentBreweryOtherKid, 2)");
    $db->query("INSERT INTO send (kid, to_kid, wood, clay, iron, crop, x, mode, end_time)
        VALUES ($concurrentBreweryCapitalKid, $concurrentBreweryCapitalKid, 5, 6, 7, 8, 1, 0, " . ($concurrentBreweryNow + 3600) . ")");
    $concurrentBreweryUnrelatedSendId = (int)$db->lastInsertId();
    $db->query("UPDATE wdata SET occupied=1 WHERE id IN ($concurrentBreweryKidList) AND occupied=0");
    expect_same(2, $db->affectedRows(), 'concurrent Brewery fixture fields occupied');
    expect_true($db->commit(), 'concurrent Brewery fixture committed');
    $concurrentBreweryCommitted = true;

    $runBreweryRace = function (array $commandPrefixes, string $label) use (
        &$concurrentBreweryWorkers,
        &$concurrentBreweryBarrierFiles
    ): array {
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $barrierPath = tempnam(sys_get_temp_dir(), 'ov-brewery-start-');
        expect_true($barrierPath !== false, "$label start barrier created");
        $concurrentBreweryBarrierFiles[] = $barrierPath;
        $readyPaths = [];
        foreach ($commandPrefixes as $i => $prefix) {
            $readyPath = tempnam(sys_get_temp_dir(), 'ov-brewery-ready-');
            expect_true($readyPath !== false, "$label worker $i ready signal created");
            $readyPaths[] = $readyPath;
            $concurrentBreweryBarrierFiles[] = $readyPath;
            $pipes = [];
            $process = proc_open(array_merge($prefix, [$barrierPath, $readyPath]), $descriptorSpec, $pipes);
            expect_true(is_resource($process), "$label worker $i started");
            $concurrentBreweryWorkers[] = ['process' => $process, 'pipes' => $pipes];
        }

        $readyDeadline = microtime(true) + 10;
        do {
            $ready = true;
            foreach ($readyPaths as $readyPath) {
                if (@file_get_contents($readyPath) !== 'ready') {
                    $ready = false;
                    break;
                }
            }
            if (!$ready) {
                usleep(1000);
            }
        } while (!$ready && microtime(true) < $readyDeadline);
        expect_true($ready, "$label workers ready");
        expect_true(file_put_contents($barrierPath, 'go', LOCK_EX) !== false, "$label workers released");

        $outcomes = [];
        $workerStart = count($concurrentBreweryWorkers) - count($commandPrefixes);
        foreach ($commandPrefixes as $i => $_prefix) {
            $workerIndex = $workerStart + $i;
            $worker = &$concurrentBreweryWorkers[$workerIndex];
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            $worker['process'] = null;
            $worker['pipes'] = [];
            expect_same(0, $exitCode, "$label worker $i exit status: $stderr");
            expect_same('', $stderr, "$label worker $i stderr");
            $outcomes[] = $stdout;
            unset($worker);
        }

        return $outcomes;
    };
    $restoreConcurrentBrewery = function () use (
        $db,
        $concurrentBreweryOwner,
        $concurrentBreweryCapitalKid,
        $concurrentBreweryOtherKid,
        $concurrentBreweryPop,
        $concurrentBreweryCp,
        $concurrentPalacePop,
        $concurrentPalaceCp
    ): void {
        $db->query("DELETE FROM building_upgrade WHERE kid IN ($concurrentBreweryCapitalKid, $concurrentBreweryOtherKid)");
        $db->query("DELETE FROM demolition WHERE kid IN ($concurrentBreweryCapitalKid, $concurrentBreweryOtherKid)");
        $db->query(
            "UPDATE users SET kid=$concurrentBreweryCapitalKid,
                 total_pop=" . ($concurrentBreweryPop + $concurrentPalacePop) . ",
                 cp_prod=" . ($concurrentBreweryCp + $concurrentPalaceCp) . ",
                 brewery_festival_started_at=0, brewery_festival_ends_at=0
             WHERE id=$concurrentBreweryOwner"
        );
        $db->query(
            "UPDATE vdata SET capital=IF(kid=$concurrentBreweryCapitalKid, 1, 0), isWW=0,
                 pop=IF(kid=$concurrentBreweryCapitalKid, $concurrentBreweryPop, $concurrentPalacePop),
                 cp=IF(kid=$concurrentBreweryCapitalKid, $concurrentBreweryCp, $concurrentPalaceCp),
                 wood=100000, clay=100000, iron=100000, crop=100000,
                 woodp=0, clayp=0, ironp=0,
                 cropp=IF(kid=$concurrentBreweryCapitalKid, $concurrentBreweryPop, $concurrentPalacePop),
                 upkeep=0, maxstore=1000000, maxcrop=1000000, festival=0,
                 lastmupdate=" . miliseconds() . "
             WHERE kid IN ($concurrentBreweryCapitalKid, $concurrentBreweryOtherKid)"
        );
        $db->query("UPDATE fdata SET f19=20, f19t=35, f20=0, f20t=0 WHERE kid=$concurrentBreweryCapitalKid");
        $db->query("UPDATE fdata SET f19=1, f19t=26, f20=0, f20t=0 WHERE kid=$concurrentBreweryOtherKid");
    };

    $concurrentFestivalCost = array_map('intval', Formulas::getFestivalResources());
    $restoreConcurrentBrewery();
    $db->query(
        "UPDATE vdata SET wood=100000, woodp=3600000, maxstore=1000000000,
             lastmupdate=" . (miliseconds() - 5000) . " WHERE kid=$concurrentBreweryCapitalKid"
    );
    expect_true($db->begin_transaction(), 'Brewery stale-snapshot outer transaction opened');
    $brewerySnapshotResourceState = $db->query(
        "SELECT wood, lastmupdate FROM vdata WHERE kid=$concurrentBreweryCapitalKid"
    )->fetch_assoc();
    $breweryResourceOutcomes = $runBreweryRace([
        ['php', '/app/tests/resource-settlement-worker.php', (string)$concurrentBreweryCapitalKid],
    ], 'Brewery stale-snapshot settlement');
    $breweryWorkerResourceState = json_decode($breweryResourceOutcomes[0], true, 512, JSON_THROW_ON_ERROR);
    expect_true(
        (float)$breweryWorkerResourceState['wood'] > (float)$brewerySnapshotResourceState['wood'],
        'fresh Brewery resource settlement advances beyond outer snapshot'
    );
    expect_true(
        (new BreweryModel())->startFestival($concurrentBreweryOwner, $concurrentBreweryCapitalKid),
        'Brewery festival starts after fresh resource update despite older outer snapshot'
    );
    $breweryFinalResourceState = $db->query(
        "SELECT wood, lastmupdate FROM vdata WHERE kid=$concurrentBreweryCapitalKid"
    )->fetch_assoc();
    $breweryPostWorkerProduction = round(
        ((int)$breweryFinalResourceState['lastmupdate'] - (int)$breweryWorkerResourceState['lastmupdate'])
        * 3600000 / 3600000,
        4
    );
    expect_close(
        (float)$breweryWorkerResourceState['wood'] + $breweryPostWorkerProduction - $concurrentFestivalCost[0],
        (float)$breweryFinalResourceState['wood'],
        0.0001,
        'Brewery start settles only production after fresh-process update'
    );
    expect_true($db->rollback(), 'Brewery stale-snapshot outer transaction rolled back');
    $restoreConcurrentBrewery();

    $duplicateBreweryOutcomes = $runBreweryRace([
        ['php', '/app/tests/brewery-festival-start-worker.php', (string)$concurrentBreweryOwner, (string)$concurrentBreweryCapitalKid],
        ['php', '/app/tests/brewery-festival-start-worker.php', (string)$concurrentBreweryOwner, (string)$concurrentBreweryCapitalKid],
    ], 'duplicate Brewery start race');
    sort($duplicateBreweryOutcomes);
    expect_same(['false', 'true'], $duplicateBreweryOutcomes, 'duplicate Brewery race starts once');
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM users WHERE id=$concurrentBreweryOwner
               AND brewery_festival_started_at>0 AND brewery_festival_ends_at=brewery_festival_started_at+51840"
        ),
        'duplicate Brewery race stores one interval'
    );
    foreach (['wood', 'clay', 'iron', 'crop'] as $resourceIndex => $resource) {
        expect_close(
            100000 - $concurrentFestivalCost[$resourceIndex],
            (float)$db->fetchScalar("SELECT $resource FROM vdata WHERE kid=$concurrentBreweryCapitalKid"),
            0.0001,
            "duplicate Brewery race charges $resource once"
        );
    }

    $restoreConcurrentBrewery();
    $db->query("INSERT INTO demolition (kid, building_field, end_time, complete)
        VALUES ($concurrentBreweryCapitalKid, 19, " . (time() + 3600) . ", 1)");
    $breweryRaceDemolitionTask = (int)$db->lastInsertId();
    $concurrentBreweryDemolitionTaskIds[] = $breweryRaceDemolitionTask;
    $breweryDemolitionOutcomes = $runBreweryRace([
        ['php', '/app/tests/brewery-festival-start-worker.php', (string)$concurrentBreweryOwner, (string)$concurrentBreweryCapitalKid],
        ['php', '/app/tests/demolition-task-worker.php', (string)$breweryRaceDemolitionTask],
    ], 'Brewery-demolition race');
    expect_true(in_array($breweryDemolitionOutcomes[0], ['false', 'true'], true), 'Brewery-demolition race returns valid start outcome');
    expect_same('true', $breweryDemolitionOutcomes[1], 'Brewery-demolition race completes demolition');
    expect_same('0|0', (string)$db->fetchScalar("SELECT CONCAT(f19, '|', f19t) FROM fdata WHERE kid=$concurrentBreweryCapitalKid"), 'Brewery-demolition race removes Brewery');
    if ($breweryDemolitionOutcomes[0] === 'true') {
        $raceStatus = (new BreweryModel())->getFestivalStatus($concurrentBreweryOwner);
        expect_true($raceStatus['active'], 'start-first Brewery-demolition race preserves account event');
        expect_same(
            ['festivalActive' => true, 'breweryLevel' => 0],
            (new BreweryModel())->getBattleEffects($concurrentBreweryOwner, $raceStatus['startedAt']),
            'start-first Brewery-demolition race preserves chief-only effect'
        );
    } else {
        expect_same(
            '0|0',
            (string)$db->fetchScalar(
                "SELECT CONCAT(brewery_festival_started_at, '|', brewery_festival_ends_at)
                 FROM users WHERE id=$concurrentBreweryOwner"
            ),
            'demolition-first Brewery race stores no event'
        );
    }

    $restoreConcurrentBrewery();
    $db->query("INSERT INTO demolition (kid, building_field, end_time, complete)
        VALUES ($concurrentBreweryCapitalKid, 19, " . (time() + 3601) . ", 1)");
    $breweryStartFirstTask = (int)$db->lastInsertId();
    $concurrentBreweryDemolitionTaskIds[] = $breweryStartFirstTask;
    expect_true((new BreweryModel())->startFestival($concurrentBreweryOwner, $concurrentBreweryCapitalKid), 'controlled start-first Brewery festival succeeds');
    expect_true(Automation::getInstance()->processDemolitionTask($breweryStartFirstTask), 'controlled start-first Brewery demolition succeeds');
    $controlledStartStatus = (new BreweryModel())->getFestivalStatus($concurrentBreweryOwner);
    expect_true($controlledStartStatus['active'], 'controlled start-first demolition preserves account festival');
    expect_same(
        ['festivalActive' => true, 'breweryLevel' => 0],
        (new BreweryModel())->getBattleEffects($concurrentBreweryOwner, $controlledStartStatus['startedAt']),
        'controlled start-first demolition leaves chief penalty only'
    );

    $restoreConcurrentBrewery();
    $db->query("INSERT INTO demolition (kid, building_field, end_time, complete)
        VALUES ($concurrentBreweryCapitalKid, 19, " . (time() + 3602) . ", 1)");
    $breweryDemolitionFirstTask = (int)$db->lastInsertId();
    $concurrentBreweryDemolitionTaskIds[] = $breweryDemolitionFirstTask;
    expect_true(Automation::getInstance()->processDemolitionTask($breweryDemolitionFirstTask), 'controlled demolition-first Brewery demolition succeeds');
    expect_same(false, (new BreweryModel())->startFestival($concurrentBreweryOwner, $concurrentBreweryCapitalKid), 'controlled demolition-first Brewery start rejects');
    $demolitionFirstState = $db->query(
        "SELECT u.brewery_festival_started_at, u.brewery_festival_ends_at,
                v.wood, v.clay, v.iron, v.crop
         FROM users u JOIN vdata v ON v.owner=u.id
         WHERE u.id=$concurrentBreweryOwner AND v.kid=$concurrentBreweryCapitalKid"
    )->fetch_assoc();
    expect_same(
        '0|0',
        $demolitionFirstState['brewery_festival_started_at'] . '|' . $demolitionFirstState['brewery_festival_ends_at'],
        'controlled demolition-first Brewery ordering stores no event'
    );
    foreach (['wood', 'clay', 'iron', 'crop'] as $resource) {
        expect_close(
            100000,
            (float)$demolitionFirstState[$resource],
            0.01,
            "controlled demolition-first Brewery ordering does not debit $resource"
        );
    }

    $restoreConcurrentBrewery();
    $breweryCapitalRaceOutcomes = $runBreweryRace([
        ['php', '/app/tests/brewery-festival-start-worker.php', (string)$concurrentBreweryOwner, (string)$concurrentBreweryCapitalKid],
        ['php', '/app/tests/capital-change-worker.php', (string)$concurrentBreweryOwner, (string)$concurrentBreweryOtherKid],
    ], 'Brewery-capital race');
    expect_true(in_array($breweryCapitalRaceOutcomes[0], ['false', 'true'], true), 'Brewery-capital race returns valid start outcome');
    expect_same('true', $breweryCapitalRaceOutcomes[1], 'Brewery-capital race changes capital');
    expect_same(
        "$concurrentBreweryOtherKid|1|0|0",
        (string)$db->fetchScalar(
            "SELECT CONCAT(v.kid, '|', v.capital, '|', old_fields.f19, '|', old_fields.f19t)
             FROM vdata v JOIN fdata old_fields ON old_fields.kid=$concurrentBreweryCapitalKid
             WHERE v.owner=$concurrentBreweryOwner AND v.capital=1"
        ),
        'Brewery-capital race moves capital and removes old Brewery'
    );
    if ($breweryCapitalRaceOutcomes[0] === 'true') {
        $capitalRaceStatus = (new BreweryModel())->getFestivalStatus($concurrentBreweryOwner);
        expect_true($capitalRaceStatus['active'], 'start-first Brewery-capital race preserves account event');
        expect_same(
            ['festivalActive' => true, 'breweryLevel' => 0],
            (new BreweryModel())->getBattleEffects($concurrentBreweryOwner, $capitalRaceStatus['startedAt']),
            'start-first Brewery-capital race disables level effects until rebuild'
        );
    } else {
        expect_same(
            '0|0',
            (string)$db->fetchScalar(
                "SELECT CONCAT(brewery_festival_started_at, '|', brewery_festival_ends_at)
                 FROM users WHERE id=$concurrentBreweryOwner"
            ),
            'capital-first Brewery race stores no event'
        );
    }

    $restoreConcurrentBrewery();
    expect_true((new BreweryModel())->startFestival($concurrentBreweryOwner, $concurrentBreweryCapitalKid), 'controlled start-first capital-change festival succeeds');
    expect_true((new VillageModel())->changeCapital($concurrentBreweryOwner, $concurrentBreweryOtherKid), 'controlled start-first capital change succeeds');
    $startFirstCapitalStatus = (new BreweryModel())->getFestivalStatus($concurrentBreweryOwner);
    expect_true($startFirstCapitalStatus['active'], 'controlled start-first capital change preserves festival');
    expect_same(
        ['festivalActive' => true, 'breweryLevel' => 0],
        (new BreweryModel())->getBattleEffects($concurrentBreweryOwner, $startFirstCapitalStatus['startedAt']),
        'controlled start-first capital change disables Brewery level effects'
    );
    $db->query("UPDATE fdata SET f20=20, f20t=35 WHERE kid=$concurrentBreweryOtherKid");
    expect_same(
        ['festivalActive' => true, 'breweryLevel' => 20],
        (new BreweryModel())->getBattleEffects($concurrentBreweryOwner, $startFirstCapitalStatus['startedAt']),
        'Brewery rebuild after capital change restores level effects'
    );

    $restoreConcurrentBrewery();
    expect_true((new VillageModel())->changeCapital($concurrentBreweryOwner, $concurrentBreweryOtherKid), 'controlled capital-first change succeeds');
    expect_same(
        false,
        (new BreweryModel())->startFestival($concurrentBreweryOwner, $concurrentBreweryCapitalKid),
        'controlled capital-first stale Brewery start rejects'
    );
    expect_same(
        '0|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(brewery_festival_started_at, '|', brewery_festival_ends_at)
             FROM users WHERE id=$concurrentBreweryOwner"
        ),
        'controlled capital-first ordering stores no event'
    );
    expect_same(
        "$concurrentBreweryCapitalKid|$concurrentBreweryCapitalKid|5|6|7|8",
        (string)$db->fetchScalar(
            "SELECT CONCAT(kid, '|', to_kid, '|', wood, '|', clay, '|', iron, '|', crop)
             FROM send WHERE id=$concurrentBreweryUnrelatedSendId"
        ),
        'concurrent Brewery fixture preserves unrelated candidate data'
    );
} finally {
    foreach ($concurrentBreweryWorkers as &$worker) {
        if (!isset($worker['process']) || !is_resource($worker['process'])) {
            continue;
        }
        $status = proc_get_status($worker['process']);
        if (!empty($status['running'])) {
            proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
        $worker['process'] = null;
        $worker['pipes'] = [];
    }
    unset($worker);
    foreach ($concurrentBreweryBarrierFiles as $barrierFile) {
        if (is_string($barrierFile) && file_exists($barrierFile)) {
            unlink($barrierFile);
        }
    }

    if (!$concurrentBreweryCommitted) {
        $db->rollback();
    }
    if ($concurrentBreweryKids !== []) {
        $concurrentBreweryKidList = implode(',', array_map('intval', $concurrentBreweryKids));
        if ($concurrentBreweryDemolitionTaskIds !== []) {
            $concurrentBreweryTaskList = implode(',', array_map('intval', $concurrentBreweryDemolitionTaskIds));
            $db->query(
                "DELETE FROM scheduled_task_failures
                 WHERE task_table='demolition' AND task_id IN ($concurrentBreweryTaskList)"
            );
        }
        $db->query("DELETE FROM building_upgrade WHERE kid IN ($concurrentBreweryKidList)");
        $db->query("DELETE FROM demolition WHERE kid IN ($concurrentBreweryKidList)");
        if ($concurrentBreweryUnrelatedSendId > 0) {
            $db->query("DELETE FROM send WHERE id=$concurrentBreweryUnrelatedSendId");
        }
        $db->query("DELETE FROM units WHERE kid IN ($concurrentBreweryKidList)");
        $db->query("DELETE FROM fdata WHERE kid IN ($concurrentBreweryKidList)");
        $db->query("DELETE FROM vdata WHERE kid IN ($concurrentBreweryKidList)");
        $db->query("DELETE FROM users WHERE id=$concurrentBreweryOwner");
        $db->query("UPDATE wdata SET occupied=0 WHERE id IN ($concurrentBreweryKidList)");
        foreach ($concurrentBreweryAvailableOccupancy as $kid => $occupied) {
            $db->query("UPDATE available_villages SET occupied=" . (int)$occupied . " WHERE kid=" . (int)$kid);
        }
    }
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE demolition AUTO_INCREMENT=$demolitionAutoIncrement");
    $db->query("ALTER TABLE send AUTO_INCREMENT=$sendAutoIncrement");
}

$battleAttacker = 2000000046;
$battleDefender = 2000000047;
$battleMovementIds = [];
$originalTruceFrom = $config->dynamic->truceFrom;
$originalTruceTo = $config->dynamic->truceTo;
$originalTruceReasonId = $config->dynamic->truceReasonId;
$originalDestroyVillageOnZeroPop = $config->custom->destroyVillageOnZeroPop;
$originalChangeCapitalOnZeroPop = $config->game->changeCapitalOnZeroPop;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id IN ($battleAttacker, $battleDefender)"),
        'village-battle surrounding fixture user IDs available'
    );
    $battleFields = $db->query(
        "SELECT w.id, w.x, w.y, w.fieldtype
         FROM wdata w LEFT JOIN vdata v ON v.kid=w.id
         WHERE w.id>0 AND w.occupied=0 AND w.oasistype=0 AND v.kid IS NULL
         ORDER BY w.id DESC LIMIT 7"
    );
    expect_same(7, $battleFields->num_rows, 'village-battle surrounding fixture fields available');
    $battleSourceField = $battleFields->fetch_assoc();
    $battleTargetField = $battleFields->fetch_assoc();
    $battleReserveField = $battleFields->fetch_assoc();
    $battleSecondTargetField = $battleFields->fetch_assoc();
    $battleRamTargetField = $battleFields->fetch_assoc();
    $battleIneligibleField = $battleFields->fetch_assoc();
    $battleSmallEligibleField = $battleFields->fetch_assoc();
    $battleSource = (int)$battleSourceField['id'];
    $battleTarget = (int)$battleTargetField['id'];
    $battleReserve = (int)$battleReserveField['id'];
    $battleSecondTarget = (int)$battleSecondTargetField['id'];
    $battleRamTarget = (int)$battleRamTargetField['id'];
    $battleIneligible = (int)$battleIneligibleField['id'];
    $battleSmallEligible = (int)$battleSmallEligibleField['id'];

    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, kid, total_pop, total_villages, desc1, desc2, note)
        VALUES
        ($battleAttacker, 'ov-regression-battle-attacker', 'OVBattleAttacker', 'x', '', 1, $battleSource, 100, 1, '', '', ''),
        ($battleDefender, 'ov-regression-battle-defender', 'OVBattleDefender', 'x', '', 3, $battleTarget, 180, 6, '', '', '')");
    $lastUpdate = miliseconds();
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($battleSource, $battleAttacker, " . (int)$battleSourceField['fieldtype'] . ", 'OV Battle Source', 1, 100, 0,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 0, $lastUpdate, " . time() . ", 0),
        ($battleTarget, $battleDefender, " . (int)$battleTargetField['fieldtype'] . ", 'OV Battle Target', 1, 100, 0,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 20, $lastUpdate, " . time() . ", 0),
        ($battleReserve, $battleDefender, " . (int)$battleReserveField['fieldtype'] . ", 'OV Battle Reserve', 0, 20, 2,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 0, $lastUpdate, " . time() . ", 0),
        ($battleSecondTarget, $battleDefender, " . (int)$battleSecondTargetField['fieldtype'] . ", 'OV Battle Target Two', 0, 20, 6,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 0, $lastUpdate, " . time() . ", 0),
        ($battleRamTarget, $battleDefender, " . (int)$battleRamTargetField['fieldtype'] . ", 'OV Battle Ram Target', 0, 0, 1,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 0, $lastUpdate, " . time() . ", 0),
        ($battleIneligible, $battleDefender, " . (int)$battleIneligibleField['fieldtype'] . ", 'OV Battle Ineligible', 0, 30, 0,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 0, $lastUpdate, " . time() . ", 0),
        ($battleSmallEligible, $battleDefender, " . (int)$battleSmallEligibleField['fieldtype'] . ", 'OV Battle Small Eligible', 0, 10, 2,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 0, $lastUpdate, " . time() . ", 0)");
    $db->query("INSERT INTO fdata (kid, f19, f19t, f40, f40t) VALUES
        ($battleSource, 0, 0, 0, 0),
        ($battleTarget, 0, 0, 0, 0),
        ($battleReserve, 1, 25, 0, 0),
        ($battleSecondTarget, 1, 26, 0, 0),
        ($battleRamTarget, 0, 0, 1, 31),
        ($battleIneligible, 0, 0, 0, 0),
        ($battleSmallEligible, 1, 44, 0, 0)");
    $db->query("INSERT INTO units (kid, race, u1) VALUES
        ($battleSource, 1, 0),
        ($battleTarget, 3, 20),
        ($battleReserve, 3, 0),
        ($battleSecondTarget, 3, 0),
        ($battleRamTarget, 3, 0),
        ($battleIneligible, 3, 0),
        ($battleSmallEligible, 3, 0)");
    $db->query("INSERT INTO hero (uid, kid, health) VALUES ($battleDefender, $battleTarget, 100)");
    $db->query("UPDATE wdata SET occupied=1 WHERE id IN ($battleSource, $battleTarget, $battleReserve, $battleSecondTarget, $battleRamTarget, $battleIneligible, $battleSmallEligible) AND occupied=0");
    expect_same(7, $db->affectedRows(), 'village-battle surrounding fixture fields occupied');

    $movement = new MovementsModel();
    $automation = Automation::getInstance();
    $battleEventTime = time() - 600;
    $battleTimeMs = $battleEventTime * 1000;
    $normalUnits = array_fill(1, 11, 0);
    $normalUnits[1] = 1000;
    $surroundingBeforeCrashId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    $battleStateBeforeCrash = (string)$db->fetchScalar(
        "SELECT CONCAT_WS('|', v.owner, v.wood, v.clay, v.iron, v.crop, v.upkeep, u.u1)
         FROM vdata v JOIN units u ON u.kid=v.kid WHERE v.kid=$battleTarget"
    );
    $battleNoticesBeforeCrash = (int)$db->fetchScalar(
        "SELECT COUNT(*) FROM ndata WHERE uid IN ($battleAttacker, $battleDefender)"
    );
    $battleCrashTask = (int)$movement->addMovement(
        $battleSource,
        $battleTarget,
        1,
        $normalUnits,
        0,
        0,
        0,
        0,
        0,
        MovementsModel::ATTACKTYPE_NORMAL,
        $battleTimeMs,
        $battleTimeMs
    );
    $battleMovementIds[] = $battleCrashTask;
    expect_true($battleCrashTask > 0, 'village-battle crash movement queued');
    $battleMovementsBeforeCrash = (int)$db->fetchScalar(
        "SELECT COUNT(*) FROM movement WHERE kid=$battleSource OR to_kid=$battleSource"
    );
    try {
        TransactionalTask::consume('movement', $battleCrashTask, function (array $row): void {
            new BattleModel($row);
            throw new RuntimeException('Simulated village battle worker crash.');
        });
        throw new RuntimeException('Simulated village battle worker crash was not propagated.');
    } catch (RuntimeException $e) {
        expect_same('Simulated village battle worker crash.', $e->getMessage(), 'village-battle crash propagated');
    }
    expect_same(
        $battleStateBeforeCrash,
        (string)$db->fetchScalar(
            "SELECT CONCAT_WS('|', v.owner, v.wood, v.clay, v.iron, v.crop, v.upkeep, u.u1)
             FROM vdata v JOIN units u ON u.kid=v.kid WHERE v.kid=$battleTarget"
        ),
        'village-battle crash rolls back combat state'
    );
    expect_same(
        $battleNoticesBeforeCrash,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM ndata WHERE uid IN ($battleAttacker, $battleDefender)"),
        'village-battle crash rolls back reports'
    );
    expect_same(
        $battleMovementsBeforeCrash,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM movement WHERE kid=$battleSource OR to_kid=$battleSource"),
        'village-battle crash rolls back movement effects and preserves task'
    );
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeCrashId AND type=" . NoticeHelper::SURROUNDING_FIGHT
        ),
        'village-battle crash rolls back fight surrounding event'
    );
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT attempts FROM scheduled_task_failures WHERE task_table='movement' AND task_id=$battleCrashTask"
        ),
        'village-battle crash records retry attempt'
    );

    expect_true($automation->processMovementTask($battleCrashTask), 'village-battle retry processed');
    $fightAfterRetry = $db->query(
        "SELECT x, y, type, params, time FROM surrounding
         WHERE id>$surroundingBeforeCrashId AND type=" . NoticeHelper::SURROUNDING_FIGHT . " ORDER BY id"
    );
    expect_same(1, $fightAfterRetry->num_rows, 'village-battle retry records one fight event');
    $fightAfterRetry = $fightAfterRetry->fetch_assoc();
    expect_same((int)$battleTargetField['x'], (int)$fightAfterRetry['x'], 'village-battle fight x coordinate');
    expect_same((int)$battleTargetField['y'], (int)$fightAfterRetry['y'], 'village-battle fight y coordinate');
    expect_same(NoticeHelper::SURROUNDING_FIGHT, (int)$fightAfterRetry['type'], 'village-battle fight event type');
    expect_same("$battleDefender:OVBattleDefender:$battleTarget", $fightAfterRetry['params'], 'village-battle fight payload');
    expect_same($battleEventTime, (int)$fightAfterRetry['time'], 'village-battle fight occurrence time');
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM scheduled_task_failures WHERE task_table='movement' AND task_id=$battleCrashTask"
        ),
        'village-battle retry clears failure ledger'
    );
    expect_same(false, $automation->processMovementTask($battleCrashTask), 'village-battle replay ignored');
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeCrashId AND type=" . NoticeHelper::SURROUNDING_FIGHT
        ),
        'village-battle replay records no duplicate fight event'
    );

    $db->query("UPDATE units SET u1=0 WHERE kid=$battleTarget");
    $raidUnits = array_fill(1, 11, 0);
    $raidUnits[1] = 20;
    $sameSecondEventTime = $battleEventTime + 10;
    $sameSecondTimeMs = $sameSecondEventTime * 1000;
    $surroundingBeforeRaidsId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    $firstRaidTask = (int)$movement->addMovement(
        $battleSource, $battleTarget, 1, $raidUnits, 0, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_RAID, $sameSecondTimeMs, $sameSecondTimeMs
    );
    $secondRaidTask = (int)$movement->addMovement(
        $battleSource, $battleTarget, 1, $raidUnits, 0, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_RAID, $sameSecondTimeMs, $sameSecondTimeMs
    );
    $battleMovementIds[] = $firstRaidTask;
    $battleMovementIds[] = $secondRaidTask;
    expect_true($automation->processMovementTask($firstRaidTask), 'first same-second village raid processed');
    expect_true($automation->processMovementTask($secondRaidTask), 'second same-second village raid processed');
    expect_same(
        2,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding
             WHERE id>$surroundingBeforeRaidsId AND type=" . NoticeHelper::SURROUNDING_FIGHT . " AND time=$sameSecondEventTime"
        ),
        'distinct same-second village raids each record a fight event'
    );

    $db->query("UPDATE units SET u1=10000 WHERE kid=$battleTarget");
    $annihilatedUnits = array_fill(1, 11, 0);
    $annihilatedUnits[1] = 1;
    $surroundingBeforeAnnihilationId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    $noticesBeforeAnnihilationId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM ndata");
    $annihilatedTask = (int)$movement->addMovement(
        $battleSource, $battleTarget, 1, $annihilatedUnits, 0, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $battleTimeMs, $battleTimeMs
    );
    $battleMovementIds[] = $annihilatedTask;
    expect_true($automation->processMovementTask($annihilatedTask), 'annihilated village attack processed');
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeAnnihilationId AND type=" . NoticeHelper::SURROUNDING_FIGHT
        ),
        'annihilated village attack records a fight event'
    );
    $annihilatedAttackerReport = $db->query(
        "SELECT type FROM ndata
         WHERE id>$noticesBeforeAnnihilationId AND uid=$battleAttacker AND to_kid=$battleTarget
         ORDER BY id LIMIT 1"
    );
    expect_same(1, $annihilatedAttackerReport->num_rows, 'annihilated village attack records attacker report');
    expect_same(
        NoticeHelper::TYPE_LOST_AS_ATTACKER,
        (int)$annihilatedAttackerReport->fetch_assoc()['type'],
        'annihilated village attack proves total attacker loss'
    );

    $scoutUnits = array_fill(1, 11, 0);
    $scoutUnits[4] = 10;
    $surroundingBeforeScoutId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    $scoutTask = (int)$movement->addMovement(
        $battleSource, $battleTarget, 1, $scoutUnits, 0, 0, 1, 0, 0,
        MovementsModel::ATTACKTYPE_SPY, $battleTimeMs, $battleTimeMs
    );
    $battleMovementIds[] = $scoutTask;
    expect_true($automation->processMovementTask($scoutTask), 'village scout movement processed');
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeScoutId AND type=" . NoticeHelper::SURROUNDING_FIGHT
        ),
        'village scout movement records no fight event'
    );

    $freeOasis = $db->query(
        "SELECT w.id
         FROM wdata w JOIN odata o ON o.kid=w.id
         WHERE w.oasistype>0 AND w.occupied=0 AND o.owner=0 AND o.did=0
         ORDER BY w.id LIMIT 1"
    );
    expect_same(1, $freeOasis->num_rows, 'village-battle exclusion oasis available');
    $freeOasisKid = (int)$freeOasis->fetch_assoc()['id'];
    $surroundingBeforeOasisId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    $oasisRaidTask = (int)$movement->addMovement(
        $battleSource, $freeOasisKid, 1, $raidUnits, 0, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_RAID, $battleTimeMs, $battleTimeMs
    );
    $battleMovementIds[] = $oasisRaidTask;
    expect_true($automation->processMovementTask($oasisRaidTask), 'free-oasis raid processed');
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeOasisId AND type=" . NoticeHelper::SURROUNDING_FIGHT
        ),
        'oasis raid records no village fight event'
    );

    $missingTarget = (int)$db->fetchScalar("SELECT MAX(id) FROM wdata") + 1000;
    $surroundingBeforeMissingId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    $missingTargetTask = (int)$movement->addMovement(
        $battleSource, $missingTarget, 1, $raidUnits, 0, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $battleTimeMs, $battleTimeMs
    );
    $battleMovementIds[] = $missingTargetTask;
    expect_true($automation->processMovementTask($missingTargetTask), 'missing-target attack processed');
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeMissingId AND type=" . NoticeHelper::SURROUNDING_FIGHT
        ),
        'missing-target attack records no fight event'
    );

    $config->dynamic->truceFrom = time() - 60;
    $config->dynamic->truceTo = time() + 60;
    $config->dynamic->truceReasonId = 1;
    $surroundingBeforeTruceId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    $truceTask = (int)$movement->addMovement(
        $battleSource, $battleTarget, 1, $raidUnits, 0, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $battleTimeMs, $battleTimeMs
    );
    $battleMovementIds[] = $truceTask;
    expect_true($automation->processMovementTask($truceTask), 'truce-deflected attack processed');
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeTruceId AND type=" . NoticeHelper::SURROUNDING_FIGHT
        ),
        'truce-deflected attack records no fight event'
    );

    $config->dynamic->truceFrom = $originalTruceFrom;
    $config->dynamic->truceTo = $originalTruceTo;
    $config->dynamic->truceReasonId = $originalTruceReasonId;
    $config->custom->destroyVillageOnZeroPop = $originalDestroyVillageOnZeroPop;
    $destructionTime = $battleEventTime + 20;
    $destructionTimeMs = $destructionTime * 1000;
    $ramUnits = array_fill(1, 11, 0);
    $ramUnits[7] = 1000;
    $catapultUnits = array_fill(1, 11, 0);
    $catapultUnits[8] = 1000;

    $processProtectedZeroPop = function (int $kid, string $expectedReason, string $label) use (
        $battleAttacker,
        $battleDefender,
        $battleSource,
        $destructionTimeMs,
        $ramUnits,
        $movement,
        $automation,
        $db,
        &$battleMovementIds
    ): void {
        expect_same(
            $expectedReason,
            (new AccountDeleter())->isVillageDestroyAble($battleAttacker, $kid, $battleDefender),
            "$label destruction reason"
        );
        $beforeId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
        $taskId = (int)$movement->addMovement(
            $battleSource, $kid, 1, $ramUnits, 0, 0, 0, 0, 0,
            MovementsModel::ATTACKTYPE_NORMAL, $destructionTimeMs, $destructionTimeMs
        );
        $battleMovementIds[] = $taskId;
        expect_true($automation->processMovementTask($taskId), "$label attack processed");
        expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$kid"), "$label village preserved");
        expect_same(
            0,
            (int)$db->fetchScalar(
                "SELECT COUNT(*) FROM surrounding WHERE id>$beforeId AND type=" . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED
            ),
            "$label records no destruction event"
        );
    };

    $db->query("UPDATE units SET u1=0 WHERE kid=$battleTarget");
    $db->query("UPDATE vdata SET pop=0, cp=1, capital=0, isWW=0, isFarm=0 WHERE kid=$battleTarget");
    $db->query("UPDATE fdata SET f19=0, f19t=0, f40=1, f40t=31 WHERE kid=$battleTarget");
    $config->custom->destroyVillageOnZeroPop = false;
    $processProtectedZeroPop($battleTarget, 'disabled', 'disabled zero-pop destruction');
    $config->custom->destroyVillageOnZeroPop = $originalDestroyVillageOnZeroPop;

    $db->query("UPDATE fdata SET f40=1, f40t=31 WHERE kid=$battleTarget");
    $db->query("UPDATE vdata SET isWW=1 WHERE kid=$battleTarget");
    $processProtectedZeroPop($battleTarget, 'isWW', 'World Wonder zero-pop destruction');
    $db->query("UPDATE vdata SET isWW=0, isFarm=1 WHERE kid=$battleTarget");
    $db->query("UPDATE fdata SET f40=1, f40t=31 WHERE kid=$battleTarget");
    $processProtectedZeroPop($battleTarget, 'isFarm', 'farm zero-pop destruction');

    $db->query("UPDATE vdata SET isFarm=0, capital=1 WHERE kid=$battleTarget");
    $db->query("UPDATE vdata SET capital=0 WHERE kid=$battleReserve");
    $db->query("UPDATE fdata SET f40=1, f40t=31 WHERE kid=$battleTarget");
    $config->game->changeCapitalOnZeroPop = false;
    expect_same(false, (bool)Config::getProperty('game', 'changeCapitalOnZeroPop'), 'capital zero-pop protection configured');
    $processProtectedZeroPop($battleTarget, 'disabledCapitalOnZeroPop', 'capital zero-pop destruction');
    $config->game->changeCapitalOnZeroPop = $originalChangeCapitalOnZeroPop;
    $db->query("UPDATE vdata SET capital=1 WHERE kid=$battleTarget");
    $db->query("UPDATE vdata SET capital=0 WHERE kid=$battleReserve");

    $db->query("UPDATE vdata SET isWW=1 WHERE kid IN ($battleReserve, $battleSecondTarget, $battleRamTarget, $battleIneligible, $battleSmallEligible)");
    $db->query("UPDATE fdata SET f40=1, f40t=31 WHERE kid=$battleTarget");
    $processProtectedZeroPop($battleTarget, 'OnePlusWW', 'one-plus-Wonder zero-pop destruction');
    $db->query("UPDATE vdata SET isWW=0 WHERE kid IN ($battleReserve, $battleSecondTarget, $battleRamTarget, $battleIneligible, $battleSmallEligible)");

    $db->query("UPDATE vdata SET isWW=1 WHERE kid IN ($battleReserve, $battleIneligible)");
    $db->query("UPDATE vdata SET isFarm=1 WHERE kid IN ($battleSecondTarget, $battleRamTarget)");
    $db->query("UPDATE vdata SET isArtifact=1 WHERE kid=$battleSmallEligible");
    $db->query("UPDATE fdata SET f40=1, f40t=31 WHERE kid=$battleTarget");
    $processProtectedZeroPop($battleTarget, 'NoCapitalSuccessor', 'mixed-special-successor zero-pop destruction');
    $db->query(
        "UPDATE vdata SET isWW=0, isFarm=0, isArtifact=0
         WHERE kid IN ($battleReserve, $battleSecondTarget, $battleRamTarget, $battleIneligible, $battleSmallEligible)"
    );

    $db->query("INSERT INTO artefacts (uid, kid, type, size, conquered, num, effecttype, effect, aoe)
        VALUES ($battleDefender, $battleTarget, 1, 1, " . time() . ", 1, 1, 1, 1)");
    $db->query("UPDATE fdata SET f40=1, f40t=31 WHERE kid=$battleTarget");
    $processProtectedZeroPop($battleTarget, 'ArtifactExists', 'artifact zero-pop destruction');
    $db->query("DELETE FROM artefacts WHERE uid=$battleDefender AND kid=$battleTarget");

    $db->query("UPDATE fdata SET f19=1, f19t=15, f40=0, f40t=0 WHERE kid=$battleTarget");
    $db->query("UPDATE vdata SET pop=2, cp=2, capital=1, isWW=0, isFarm=0 WHERE kid=$battleTarget");
    $db->query("UPDATE vdata SET isWW=1 WHERE kid=$battleIneligible");
    $db->query("UPDATE fdata SET f19=1, f19t=25 WHERE kid=$battleIneligible");
    $db->query("UPDATE users SET kid=$battleTarget, total_pop=82, total_villages=6 WHERE id=$battleDefender");
    $db->query("UPDATE hero SET kid=$battleTarget, health=100 WHERE uid=$battleDefender");
    $surroundingBeforeDestructionId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    $noticesBeforeDestructionId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM ndata");
    $destructionTask = (int)$movement->addMovement(
        $battleSource, $battleTarget, 1, $catapultUnits, 15, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $destructionTimeMs, $destructionTimeMs
    );
    $battleMovementIds[] = $destructionTask;
    expect_true($destructionTask > 0, 'village-destruction movement queued');

    try {
        TransactionalTask::consume('movement', $destructionTask, function (array $row): void {
            new BattleModel($row);
            throw new RuntimeException('Simulated village destruction worker crash.');
        });
        throw new RuntimeException('Simulated village destruction worker crash was not propagated.');
    } catch (RuntimeException $e) {
        expect_same('Simulated village destruction worker crash.', $e->getMessage(), 'village-destruction crash propagated');
    }
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$battleTarget"), 'village-destruction crash restores village');
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM fdata WHERE kid=$battleTarget AND f19=1 AND f19t=15"), 'village-destruction crash restores building');
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM units WHERE kid=$battleTarget"), 'village-destruction crash restores units');
    expect_same(1, (int)$db->fetchScalar("SELECT occupied FROM wdata WHERE id=$battleTarget"), 'village-destruction crash restores occupied tile');
    expect_same($battleTarget, (int)$db->fetchScalar("SELECT kid FROM users WHERE id=$battleDefender"), 'village-destruction crash restores selected village');
    expect_same(
        "$battleTarget|100.0000000000",
        (string)$db->fetchScalar("SELECT CONCAT(kid, '|', health) FROM hero WHERE uid=$battleDefender"),
        'village-destruction crash restores hero home and health'
    );
    expect_same(
        "$battleTarget|1",
        (string)$db->fetchScalar("SELECT CONCAT(kid, '|', capital) FROM vdata WHERE owner=$battleDefender AND capital=1"),
        'village-destruction crash restores capital'
    );
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeDestructionId AND type IN (" .
            NoticeHelper::SURROUNDING_FIGHT . ", " . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED . ")"
        ),
        'village-destruction crash rolls back surrounding events'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM ndata WHERE id>$noticesBeforeDestructionId"),
        'village-destruction crash rolls back battle reports'
    );
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM movement WHERE id=$destructionTask"), 'village-destruction crash preserves movement');

    expect_true($automation->processMovementTask($destructionTask), 'village-destruction retry processed');
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$battleTarget"), 'village-destruction removes village');
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM fdata WHERE kid=$battleTarget"), 'village-destruction removes fields');
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM units WHERE kid=$battleTarget"), 'village-destruction removes units');
    expect_same(0, (int)$db->fetchScalar("SELECT occupied FROM wdata WHERE id=$battleTarget"), 'village-destruction frees tile');
    expect_same(
        '80|5',
        (string)$db->fetchScalar("SELECT CONCAT(total_pop, '|', total_villages) FROM users WHERE id=$battleDefender"),
        'village-destruction updates owner aggregates'
    );
    expect_true($battleSecondTarget < $battleReserve, 'equal-population successor fixture orders second target by lower kid');
    expect_same($battleSecondTarget, (int)$db->fetchScalar("SELECT kid FROM users WHERE id=$battleDefender"), 'destroyed selected capital redirects to largest eligible successor');
    expect_same(
        "$battleSecondTarget|1|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT($battleSecondTarget, '|',
                (SELECT capital FROM vdata WHERE kid=$battleSecondTarget), '|',
                COUNT(*)) FROM vdata WHERE owner=$battleDefender AND capital=1"
        ),
        'largest eligible village becomes the only capital'
    );
    expect_same(0, (int)$db->fetchScalar("SELECT capital FROM vdata WHERE kid=$battleIneligible"), 'higher-population World Wonder candidate is not promoted');
    expect_same(
        "$battleSecondTarget|0.0000000000",
        (string)$db->fetchScalar("SELECT CONCAT(kid, '|', health) FROM hero WHERE uid=$battleDefender"),
        'hero relocates to promoted capital'
    );
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM movement WHERE id=$destructionTask"), 'village-destruction consumes movement');
    expect_true(
        (int)$db->fetchScalar("SELECT COUNT(*) FROM ndata WHERE id>$noticesBeforeDestructionId") >= 2,
        'village-destruction records battle reports'
    );
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeDestructionId AND type=" . NoticeHelper::SURROUNDING_FIGHT
        ),
        'village-destruction records ordinary fight event'
    );
    $destructionEvent = $db->query(
        "SELECT x, y, params, time FROM surrounding
         WHERE id>$surroundingBeforeDestructionId AND type=" . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED
    );
    expect_same(1, $destructionEvent->num_rows, 'village-destruction records one destruction event');
    $destructionEvent = $destructionEvent->fetch_assoc();
    expect_same((int)$battleTargetField['x'], (int)$destructionEvent['x'], 'village-destruction event x coordinate');
    expect_same((int)$battleTargetField['y'], (int)$destructionEvent['y'], 'village-destruction event y coordinate');
    expect_same("$battleDefender:OVBattleDefender:$battleTarget:OV Battle Target", $destructionEvent['params'], 'village-destruction immutable payload');
    expect_same($destructionTime, (int)$destructionEvent['time'], 'village-destruction event occurrence time');

    expect_same(false, $automation->processMovementTask($destructionTask), 'village-destruction replay ignored');
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeDestructionId AND type=" . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED
        ),
        'village-destruction replay records no duplicate event'
    );
    expect_same($battleSecondTarget, (int)$db->fetchScalar("SELECT kid FROM users WHERE id=$battleDefender"), 'village-destruction replay preserves selected successor');

    $db->query("UPDATE vdata SET isWW=0, isFarm=1 WHERE kid=$battleIneligible");
    $db->query("UPDATE fdata SET f19=1, f19t=26 WHERE kid=$battleIneligible");
    $db->query("UPDATE vdata SET pop=1, cp=6 WHERE kid=$battleSecondTarget");
    $db->query("UPDATE users SET total_pop=61 WHERE id=$battleDefender");
    $secondDestructionTask = (int)$movement->addMovement(
        $battleSource, $battleSecondTarget, 1, $catapultUnits, 26, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $destructionTimeMs, $destructionTimeMs
    );
    $battleMovementIds[] = $secondDestructionTask;
    expect_true($automation->processMovementTask($secondDestructionTask), 'second same-second village destruction processed');
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$battleSecondTarget"), 'second same-second village removed');
    expect_same(
        '60|4',
        (string)$db->fetchScalar("SELECT CONCAT(total_pop, '|', total_villages) FROM users WHERE id=$battleDefender"),
        'second same-second destruction updates owner aggregates'
    );
    expect_same($battleReserve, (int)$db->fetchScalar("SELECT kid FROM users WHERE id=$battleDefender"), 'second destroyed capital redirects to next eligible successor');
    expect_same(
        "$battleReserve|1|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT($battleReserve, '|',
                (SELECT capital FROM vdata WHERE kid=$battleReserve), '|',
                COUNT(*)) FROM vdata WHERE owner=$battleDefender AND capital=1"
        ),
        'second succession leaves one capital'
    );
    expect_same(
        "$battleReserve|0.0000000000",
        (string)$db->fetchScalar("SELECT CONCAT(kid, '|', health) FROM hero WHERE uid=$battleDefender"),
        'hero follows repeated capital succession'
    );
    expect_same(0, (int)$db->fetchScalar("SELECT capital FROM vdata WHERE kid=$battleIneligible"), 'higher-population farm candidate is not promoted');
    expect_same(
        2,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding
             WHERE id>$surroundingBeforeDestructionId
               AND type=" . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED . " AND time=$destructionTime"
        ),
        'distinct same-second village destructions each record an event'
    );
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding
             WHERE id>$surroundingBeforeDestructionId
               AND type=" . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED . "
               AND params='$battleDefender:OVBattleDefender:$battleSecondTarget:OV Battle Target Two'"
        ),
        'second same-second destruction records immutable payload'
    );

    $ramAndCatapultUnits = $catapultUnits;
    $ramAndCatapultUnits[7] = 1000;
    $db->query("UPDATE users SET kid=$battleRamTarget WHERE id=$battleDefender");
    $db->query("UPDATE hero SET kid=$battleRamTarget, health=100 WHERE uid=$battleDefender");
    $ramDestructionTask = (int)$movement->addMovement(
        $battleSource, $battleRamTarget, 1, $ramAndCatapultUnits, 15, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $destructionTimeMs, $destructionTimeMs
    );
    $battleMovementIds[] = $ramDestructionTask;
    expect_true($automation->processMovementTask($ramDestructionTask), 'ram-first village destruction processed');
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$battleRamTarget"), 'ram-first village destruction removes village');
    expect_same(
        '60|3',
        (string)$db->fetchScalar("SELECT CONCAT(total_pop, '|', total_villages) FROM users WHERE id=$battleDefender"),
        'ram-first village destruction updates owner aggregates once'
    );
    expect_same($battleReserve, (int)$db->fetchScalar("SELECT kid FROM users WHERE id=$battleDefender"), 'destroyed selected non-capital redirects to existing capital');
    expect_same(1, (int)$db->fetchScalar("SELECT capital FROM vdata WHERE kid=$battleReserve"), 'selected non-capital destruction preserves capital');
    expect_same(
        "$battleReserve|0.0000000000",
        (string)$db->fetchScalar("SELECT CONCAT(kid, '|', health) FROM hero WHERE uid=$battleDefender"),
        'hero in destroyed non-capital relocates to existing capital'
    );
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding
             WHERE id>$surroundingBeforeDestructionId
               AND type=" . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED . "
               AND params='$battleDefender:OVBattleDefender:$battleRamTarget:OV Battle Ram Target'"
        ),
        'ram-first village destruction records one event and skips catapult processing'
    );

    $db->query("UPDATE vdata SET isFarm=0, isArtifact=1 WHERE kid=$battleIneligible");
    $db->query("UPDATE fdata SET f19=1, f19t=25 WHERE kid=$battleIneligible");
    $db->query("UPDATE vdata SET pop=1, cp=2 WHERE kid=$battleReserve");
    $db->query("UPDATE fdata SET f19=1, f19t=25, f40=0, f40t=0 WHERE kid=$battleReserve");
    $db->query("UPDATE users SET kid=$battleReserve, total_pop=41, total_villages=3 WHERE id=$battleDefender");
    $db->query("UPDATE hero SET kid=$battleReserve, health=100 WHERE uid=$battleDefender");
    $thirdSuccessionTask = (int)$movement->addMovement(
        $battleSource, $battleReserve, 1, $catapultUnits, 25, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $destructionTimeMs, $destructionTimeMs
    );
    $battleMovementIds[] = $thirdSuccessionTask;
    expect_true($automation->processMovementTask($thirdSuccessionTask), 'third capital succession processed');
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$battleReserve"), 'third capital succession removes village');
    expect_same(
        '40|2',
        (string)$db->fetchScalar("SELECT CONCAT(total_pop, '|', total_villages) FROM users WHERE id=$battleDefender"),
        'third capital succession updates owner aggregates'
    );
    expect_same($battleSmallEligible, (int)$db->fetchScalar("SELECT kid FROM users WHERE id=$battleDefender"), 'GID 44 eligible village outranks larger artifact candidate');
    expect_same(
        "$battleSmallEligible|1|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT($battleSmallEligible, '|',
                (SELECT capital FROM vdata WHERE kid=$battleSmallEligible), '|',
                COUNT(*)) FROM vdata WHERE owner=$battleDefender AND capital=1"
        ),
        'third succession leaves one eligible capital'
    );
    expect_same(
        "$battleSmallEligible|0.0000000000",
        (string)$db->fetchScalar("SELECT CONCAT(kid, '|', health) FROM hero WHERE uid=$battleDefender"),
        'hero follows third capital succession'
    );
    expect_same(0, (int)$db->fetchScalar("SELECT capital FROM vdata WHERE kid=$battleIneligible"), 'higher-population artifact candidate is not promoted');

    $db->query("UPDATE vdata SET isArtifact=0 WHERE kid=$battleIneligible");
    $db->query("UPDATE fdata SET f19=0, f19t=0 WHERE kid=$battleIneligible");
    $db->query("UPDATE vdata SET pop=1, cp=2 WHERE kid=$battleSmallEligible");
    $db->query("UPDATE fdata SET f19=1, f19t=25, f40=0, f40t=0 WHERE kid=$battleSmallEligible");
    $db->query("UPDATE users SET kid=$battleSmallEligible, total_pop=31, total_villages=2 WHERE id=$battleDefender");
    $db->query("UPDATE hero SET kid=$battleSmallEligible, health=100 WHERE uid=$battleDefender");
    $fallbackDestructionTask = (int)$movement->addMovement(
        $battleSource, $battleSmallEligible, 1, $catapultUnits, 25, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $destructionTimeMs, $destructionTimeMs
    );
    $battleMovementIds[] = $fallbackDestructionTask;
    expect_true($automation->processMovementTask($fallbackDestructionTask), 'fallback capital destruction processed');
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$battleSmallEligible"), 'fallback capital destruction removes village');
    expect_same(
        '30|1',
        (string)$db->fetchScalar("SELECT CONCAT(total_pop, '|', total_villages) FROM users WHERE id=$battleDefender"),
        'fallback capital destruction updates owner aggregates'
    );
    expect_same($battleIneligible, (int)$db->fetchScalar("SELECT kid FROM users WHERE id=$battleDefender"), 'largest ordinary fallback becomes selected capital');
    expect_same(
        "$battleIneligible|1|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT($battleIneligible, '|',
                (SELECT capital FROM vdata WHERE kid=$battleIneligible), '|',
                COUNT(*)) FROM vdata WHERE owner=$battleDefender AND capital=1"
        ),
        'no-administrative-building fallback leaves one capital'
    );
    expect_same(
        "$battleIneligible|0.0000000000",
        (string)$db->fetchScalar("SELECT CONCAT(kid, '|', health) FROM hero WHERE uid=$battleDefender"),
        'hero follows fallback capital succession'
    );

    $db->query("UPDATE vdata SET pop=0, cp=1 WHERE kid=$battleIneligible");
    $db->query("UPDATE fdata SET f40=1, f40t=31 WHERE kid=$battleIneligible");
    $db->query("UPDATE users SET total_pop=0 WHERE id=$battleDefender");
    $processProtectedZeroPop($battleIneligible, 'OnlyOneVillage', 'last-village zero-pop destruction');

    $staleDestructionTask = (int)$movement->addMovement(
        $battleSource, $battleTarget, 1, $catapultUnits, 15, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $destructionTimeMs, $destructionTimeMs
    );
    $battleMovementIds[] = $staleDestructionTask;
    expect_true($automation->processMovementTask($staleDestructionTask), 'stale post-destruction attack processed');
    expect_same(
        5,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeDestructionId AND type=" . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED
        ),
        'stale post-destruction attack records no second event'
    );
} finally {
    $config->dynamic->truceFrom = $originalTruceFrom;
    $config->dynamic->truceTo = $originalTruceTo;
    $config->dynamic->truceReasonId = $originalTruceReasonId;
    $config->custom->destroyVillageOnZeroPop = $originalDestroyVillageOnZeroPop;
    $config->game->changeCapitalOnZeroPop = $originalChangeCapitalOnZeroPop;
    $db->rollback();
    if ($battleMovementIds !== []) {
        $db->query(
            "DELETE FROM scheduled_task_failures
             WHERE task_table='movement' AND task_id IN (" . implode(',', array_map('intval', $battleMovementIds)) . ")"
        );
    }
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE movement AUTO_INCREMENT=$movementAutoIncrement");
    $db->query("ALTER TABLE ndata AUTO_INCREMENT=$noticeAutoIncrement");
    $db->query("ALTER TABLE surrounding AUTO_INCREMENT=$surroundingAutoIncrement");
    $db->query("ALTER TABLE casualties AUTO_INCREMENT=$casualtiesAutoIncrement");
    $db->query("ALTER TABLE multiaccount_log AUTO_INCREMENT=$multiAccountLogAutoIncrement");
    $db->query("ALTER TABLE farmlist_last_reports AUTO_INCREMENT=$farmListLastReportsAutoIncrement");
}

$concurrentAttacker = 2000000048;
$concurrentDefender = 2000000049;
$concurrentKids = [];
$concurrentTaskIds = [];
$concurrentFixtureCommitted = false;
$concurrentAvailableOccupancy = [];
$concurrentWorkers = [];
$concurrentBarrierFiles = [];
$concurrentCasualtyTime = strtotime('today 00:00');
$concurrentCasualtyResult = $db->query("SELECT id FROM casualties WHERE time=$concurrentCasualtyTime");
$concurrentCasualtyExisted = $concurrentCasualtyResult->num_rows === 1;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id IN ($concurrentAttacker, $concurrentDefender)"),
        'concurrent village-destruction fixture user IDs available'
    );
    $concurrentFields = $db->query(
        "SELECT w.id, w.x, w.y, w.fieldtype
         FROM wdata w LEFT JOIN vdata v ON v.kid=w.id
         WHERE w.id>0 AND w.occupied=0 AND w.oasistype=0 AND v.kid IS NULL
           AND NOT EXISTS (SELECT 1 FROM blocks b WHERE b.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM marks m WHERE m.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM vdata child WHERE child.expandedfrom=w.id)
           AND NOT EXISTS (SELECT 1 FROM movement movement_ref WHERE movement_ref.kid=w.id OR movement_ref.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM odata oasis_ref WHERE oasis_ref.did=w.id)
         ORDER BY w.id DESC LIMIT 4"
    );
    expect_same(4, $concurrentFields->num_rows, 'concurrent village-destruction fixture fields available');
    $concurrentSourceField = $concurrentFields->fetch_assoc();
    $concurrentTargetField = $concurrentFields->fetch_assoc();
    $concurrentSecondTargetField = $concurrentFields->fetch_assoc();
    $concurrentReserveField = $concurrentFields->fetch_assoc();
    $concurrentSource = (int)$concurrentSourceField['id'];
    $concurrentTarget = (int)$concurrentTargetField['id'];
    $concurrentSecondTarget = (int)$concurrentSecondTargetField['id'];
    $concurrentReserve = (int)$concurrentReserveField['id'];
    $concurrentKids = [$concurrentSource, $concurrentTarget, $concurrentSecondTarget, $concurrentReserve];
    $availableRows = $db->query(
        "SELECT kid, occupied FROM available_villages WHERE kid IN ($concurrentSource, $concurrentTarget, $concurrentSecondTarget, $concurrentReserve)"
    );
    while ($availableRow = $availableRows->fetch_assoc()) {
        $concurrentAvailableOccupancy[(int)$availableRow['kid']] = (int)$availableRow['occupied'];
    }
    $nowMs = miliseconds();

    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, kid, total_pop, total_villages, desc1, desc2, note)
        VALUES
        ($concurrentAttacker, 'ov-regression-concurrent-attacker', 'OVConcurrentAttacker', 'x', '', 1, $concurrentSource, 100, 1, '', '', ''),
        ($concurrentDefender, 'ov-regression-concurrent-defender', 'OVConcurrentDefender', 'x', '', 3, $concurrentReserve, 3, 3, '', '', '')");
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($concurrentSource, $concurrentAttacker, " . (int)$concurrentSourceField['fieldtype'] . ", 'OV Concurrent Source', 1, 100, 0,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 0, $nowMs, " . time() . ", 0),
        ($concurrentTarget, $concurrentDefender, " . (int)$concurrentTargetField['fieldtype'] . ", 'OV Concurrent Target', 1, 1, 2,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 0, $nowMs, " . time() . ", 0),
        ($concurrentSecondTarget, $concurrentDefender, " . (int)$concurrentSecondTargetField['fieldtype'] . ", 'OV Concurrent Target Two', 0, 1, 2,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 0, $nowMs, " . time() . ", 0),
        ($concurrentReserve, $concurrentDefender, " . (int)$concurrentReserveField['fieldtype'] . ", 'OV Concurrent Reserve', 0, 1, 0,
         1000000, 1000000, 1000000, 0, 0, 0, 1000000, 1000000, 0, 1000000, 0, $nowMs, " . time() . ", 0)");
    $db->query("INSERT INTO fdata (kid, f19, f19t) VALUES
        ($concurrentSource, 0, 0),
        ($concurrentTarget, 1, 15),
        ($concurrentSecondTarget, 1, 25),
        ($concurrentReserve, 0, 0)");
    $db->query("INSERT INTO units (kid, race) VALUES
        ($concurrentSource, 1),
        ($concurrentTarget, 3),
        ($concurrentSecondTarget, 3),
        ($concurrentReserve, 3)");
    $db->query("UPDATE wdata SET occupied=1 WHERE id IN ($concurrentSource, $concurrentTarget, $concurrentSecondTarget, $concurrentReserve) AND occupied=0");
    expect_same(4, $db->affectedRows(), 'concurrent village-destruction fixture fields occupied');

    $concurrentUnits = array_fill(1, 11, 0);
    $concurrentUnits[8] = 1000;
    $concurrentEventTime = time() + 3600;
    $concurrentTaskIds[] = (int)(new MovementsModel())->addMovement(
        $concurrentSource, $concurrentTarget, 1, $concurrentUnits, 15, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $concurrentEventTime * 1000, $concurrentEventTime * 1000
    );
    $concurrentTaskIds[] = (int)(new MovementsModel())->addMovement(
        $concurrentSource, $concurrentSecondTarget, 1, $concurrentUnits, 25, 0, 0, 0, 0,
        MovementsModel::ATTACKTYPE_NORMAL, $concurrentEventTime * 1000, $concurrentEventTime * 1000
    );
    expect_true($concurrentTaskIds[0] > 0 && $concurrentTaskIds[1] > 0, 'distinct concurrent village-destruction movements queued');
    expect_true($db->commit(), 'concurrent village-destruction fixture committed');
    $concurrentFixtureCommitted = true;

    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $barrierPath = tempnam(sys_get_temp_dir(), 'ov-destroy-start-');
    $firstReadyPath = tempnam(sys_get_temp_dir(), 'ov-destroy-ready-');
    $secondReadyPath = tempnam(sys_get_temp_dir(), 'ov-destroy-ready-');
    expect_true($barrierPath !== false, 'concurrent village-destruction start barrier created');
    expect_true($firstReadyPath !== false, 'first concurrent village-destruction ready signal created');
    expect_true($secondReadyPath !== false, 'second concurrent village-destruction ready signal created');
    $concurrentBarrierFiles = [$barrierPath, $firstReadyPath, $secondReadyPath];
    $readyPaths = [$firstReadyPath, $secondReadyPath];
    for ($i = 0; $i < 2; ++$i) {
        $pipes = [];
        $process = proc_open(
            [
                'php',
                '/app/tests/movement-task-worker.php',
                (string)$concurrentTaskIds[$i],
                $barrierPath,
                $readyPaths[$i],
            ],
            $descriptorSpec,
            $pipes
        );
        expect_true(is_resource($process), "concurrent village-destruction worker $i started");
        $concurrentWorkers[] = ['process' => $process, 'pipes' => $pipes];
    }

    $readyDeadline = microtime(true) + 10;
    while (
        (@file_get_contents($firstReadyPath) !== 'ready' || @file_get_contents($secondReadyPath) !== 'ready')
        && microtime(true) < $readyDeadline
    ) {
        usleep(1000);
    }
    expect_same('ready', @file_get_contents($firstReadyPath), 'first concurrent village-destruction worker ready');
    expect_same('ready', @file_get_contents($secondReadyPath), 'second concurrent village-destruction worker ready');
    expect_true(file_put_contents($barrierPath, 'go', LOCK_EX) !== false, 'concurrent village-destruction workers released together');

    $workerOutcomes = [];
    foreach ($concurrentWorkers as $i => &$worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $worker['process'] = null;
        $worker['pipes'] = [];
        $workerOutcomes[] = ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode];
    }
    unset($worker);

    $workerResults = [];
    foreach ($workerOutcomes as $i => $outcome) {
        expect_same(0, $outcome['exitCode'], "concurrent village-destruction worker $i exit status: {$outcome['stderr']}");
        expect_same('', $outcome['stderr'], "concurrent village-destruction worker $i stderr");
        $workerResults[] = $outcome['stdout'];
    }
    sort($workerResults);
    expect_same(['true', 'true'], $workerResults, 'distinct concurrent village-destruction movements both complete');
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM movement WHERE id IN (" . implode(',', $concurrentTaskIds) . ")"),
        'distinct concurrent village-destruction movements both consumed'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid IN ($concurrentTarget, $concurrentSecondTarget)"),
        'distinct concurrent village-destruction targets both removed'
    );
    expect_same($concurrentReserve, (int)$db->fetchScalar("SELECT kid FROM users WHERE id=$concurrentDefender"), 'concurrent distinct-target destruction preserves selected village');
    expect_same(
        "$concurrentReserve|1|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT($concurrentReserve, '|',
                (SELECT capital FROM vdata WHERE kid=$concurrentReserve), '|',
                COUNT(*)) FROM vdata WHERE owner=$concurrentDefender AND capital=1"
        ),
        'concurrent distinct-target destruction preserves exactly one capital'
    );
    expect_same(
        2,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding
             WHERE type=" . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED . "
               AND params IN (
                   '$concurrentDefender:OVConcurrentDefender:$concurrentTarget:OV Concurrent Target',
                   '$concurrentDefender:OVConcurrentDefender:$concurrentSecondTarget:OV Concurrent Target Two'
               )"
        ),
        'concurrent distinct-target destruction records both events'
    );
} finally {
    foreach ($concurrentWorkers as &$worker) {
        if (!isset($worker['process']) || !is_resource($worker['process'])) {
            continue;
        }
        $status = proc_get_status($worker['process']);
        if (!empty($status['running'])) {
            proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
        $worker['process'] = null;
        $worker['pipes'] = [];
    }
    unset($worker);
    foreach ($concurrentBarrierFiles as $barrierFile) {
        if (is_string($barrierFile) && file_exists($barrierFile)) {
            unlink($barrierFile);
        }
    }

    if (!$concurrentFixtureCommitted) {
        $db->rollback();
    }
    if ($concurrentKids !== []) {
        $kidList = implode(',', array_map('intval', $concurrentKids));
        $destructionParams = [
            "$concurrentDefender:OVConcurrentDefender:$concurrentTarget:OV Concurrent Target",
            "$concurrentDefender:OVConcurrentDefender:$concurrentSecondTarget:OV Concurrent Target Two",
        ];
        $fightParams = [
            "$concurrentDefender:OVConcurrentDefender:$concurrentTarget",
            "$concurrentDefender:OVConcurrentDefender:$concurrentSecondTarget",
        ];
        $destructionParamsSql = "'" . implode("','", $destructionParams) . "'";
        $fightParamsSql = "'" . implode("','", $fightParams) . "'";
        $concurrentBattleCount = (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding
             WHERE type=" . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED . "
               AND params IN ($destructionParamsSql)"
        );
        if ($concurrentTaskIds !== []) {
            $db->query(
                "DELETE FROM scheduled_task_failures
                 WHERE task_table='movement' AND task_id IN (" . implode(',', array_map('intval', $concurrentTaskIds)) . ")"
            );
        }
        $db->query("DELETE FROM movement WHERE kid IN ($kidList) OR to_kid IN ($kidList)");
        $db->query("DELETE FROM ndata WHERE uid IN ($concurrentAttacker, $concurrentDefender)");
        $db->query(
            "DELETE FROM surrounding
             WHERE (type=" . NoticeHelper::SURROUNDING_VILLAGE_DESTROYED . " AND params IN ($destructionParamsSql))
                OR (type=" . NoticeHelper::SURROUNDING_FIGHT . " AND params IN ($fightParamsSql))"
        );
        $db->query(
            "DELETE FROM multiaccount_log
             WHERE uid IN ($concurrentAttacker, $concurrentDefender)
                OR to_uid IN ($concurrentAttacker, $concurrentDefender)"
        );
        $db->query(
            "DELETE FROM farmlist_last_reports
             WHERE uid IN ($concurrentAttacker, $concurrentDefender)"
        );
        if ($concurrentBattleCount > 0) {
            $db->query(
                "UPDATE casualties SET attacks=GREATEST(attacks-$concurrentBattleCount, 0)
                 WHERE time=$concurrentCasualtyTime"
            );
            if (!$concurrentCasualtyExisted) {
                $db->query(
                    "DELETE FROM casualties
                     WHERE time=$concurrentCasualtyTime AND attacks=0 AND casualties=0"
                );
            }
        }
        $db->query("DELETE FROM units WHERE kid IN ($kidList)");
        $db->query("DELETE FROM fdata WHERE kid IN ($kidList)");
        $db->query("DELETE FROM vdata WHERE kid IN ($kidList)");
        $db->query("DELETE FROM users WHERE id IN ($concurrentAttacker, $concurrentDefender)");
        $db->query("UPDATE wdata SET occupied=0 WHERE id IN ($kidList)");
        foreach ($concurrentAvailableOccupancy as $kid => $occupied) {
            $db->query(
                "UPDATE available_villages SET occupied=" . (int)$occupied . " WHERE kid=" . (int)$kid
            );
        }
    }
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE movement AUTO_INCREMENT=$movementAutoIncrement");
    $db->query("ALTER TABLE ndata AUTO_INCREMENT=$noticeAutoIncrement");
    $db->query("ALTER TABLE surrounding AUTO_INCREMENT=$surroundingAutoIncrement");
    $db->query("ALTER TABLE casualties AUTO_INCREMENT=$casualtiesAutoIncrement");
    $db->query("ALTER TABLE multiaccount_log AUTO_INCREMENT=$multiAccountLogAutoIncrement");
    $db->query("ALTER TABLE farmlist_last_reports AUTO_INCREMENT=$farmListLastReportsAutoIncrement");
}

$settlementRegressionUsers = [2000000070, 2000000071, 2000000072];
$settlementRegressionKids = [];
$settlementRegressionTasks = [];
$settlementRegressionOriginalMap = [];
$settlementRegressionWorkers = [];
$settlementRegressionBarrierFiles = [];
$settlementRegressionCommitted = false;
$settlementRegressionTarget = 0;
$settlementRegressionInvalidTarget = 0;
$settlementRegressionSummary = $db->query(
    'SELECT first_village_player_name, first_village_time FROM summary LIMIT 1'
)->fetch_assoc();
$settlementRegressionSurroundingFloor = (int)$db->fetchScalar('SELECT COALESCE(MAX(id), 0) FROM surrounding');
try {
    $availableFields = $db->query(
        "SELECT w.id, w.fieldtype
         FROM wdata w JOIN available_villages a ON a.kid=w.id
         WHERE w.id>0 AND w.fieldtype>0 AND w.occupied=0 AND a.occupied=0 AND w.oasistype=0
           AND ROUND(SQRT(POW(w.x, 2)+POW(w.y, 2)))>22
           AND NOT EXISTS (SELECT 1 FROM vdata v WHERE v.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM movement m WHERE m.kid=w.id OR m.to_kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM blocks b WHERE b.kid=w.id)
           AND NOT EXISTS (SELECT 1 FROM marks m WHERE m.kid=w.id)
         ORDER BY w.id DESC LIMIT 5"
    );
    expect_same(5, $availableFields->num_rows, 'settlement regression fixture fields available');
    $fields = [];
    while ($field = $availableFields->fetch_assoc()) {
        $fields[] = ['id' => (int)$field['id'], 'fieldtype' => (int)$field['fieldtype']];
    }
    $settlementRegressionInvalidTarget = $fields[0]['id'];
    $sourceA = $fields[1]['id'];
    $sourceB = $fields[2]['id'];
    $sourceC = $fields[3]['id'];
    $settlementRegressionTarget = $fields[4]['id'];
    $settlementRegressionKids = [$settlementRegressionInvalidTarget, $sourceA, $sourceB, $sourceC, $settlementRegressionTarget];
    foreach ($settlementRegressionKids as $kid) {
        $row = $db->query("SELECT occupied FROM wdata WHERE id=$kid")->fetch_assoc();
        $available = $db->query("SELECT occupied FROM available_villages WHERE kid=$kid")->fetch_assoc();
        $settlementRegressionOriginalMap[$kid] = [(int)$row['occupied'], (int)$available['occupied']];
    }
    foreach ($settlementRegressionUsers as $uid) {
        expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$uid"), "settlement fixture user $uid available");
    }

    $nowMs = miliseconds();
    $db->begin_transaction();
    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, kid, total_pop, total_villages, cp, cp_prod, lastupdate,
         desc1, desc2, note)
        VALUES
        ({$settlementRegressionUsers[0]}, 'ov-regression-settle-a', 'OVSettleA', 'x', '', 1, $sourceA, 100, 1, 1000000, 0, " . time() . ", '', '', ''),
        ({$settlementRegressionUsers[1]}, 'ov-regression-settle-b', 'OVSettleB', 'x', '', 1, $sourceB, 100, 1, 1000000, 0, " . time() . ", '', '', ''),
        ({$settlementRegressionUsers[2]}, 'ov-regression-settle-c', 'OVSettleC', 'x', '', 1, $sourceC, 100, 1, 1000000, 0, " . time() . ", '', '', '')");
    foreach ([[$sourceA, 0], [$sourceB, 1], [$sourceC, 2]] as [$kid, $index]) {
        $uid = $settlementRegressionUsers[$index];
        $fieldtype = $fields[array_search($kid, array_column($fields, 'id'), true)]['fieldtype'];
        $db->query("INSERT INTO vdata
            (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
             crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
            VALUES ($kid, $uid, $fieldtype, 'OV Settlement Source', 1, 100, 0, 1000, 1000, 1000, 0, 0,
                    0, 1000000, 1000, 0, 1000000, 0, $nowMs, " . time() . ", 0)");
        $db->query("INSERT INTO fdata (kid) VALUES ($kid)");
        $db->query("INSERT INTO units (kid, race) VALUES ($kid, 1)");
    }
    $db->query("UPDATE wdata SET occupied=1 WHERE id IN ($sourceA, $sourceB, $sourceC)");
    $db->query("UPDATE available_villages SET occupied=1 WHERE kid IN ($sourceA, $sourceB, $sourceC)");
    // Deliberately create the stale-map condition: world tile free, availability row occupied.
    $db->query("UPDATE available_villages SET occupied=1 WHERE kid=$settlementRegressionInvalidTarget");
    $settlers = array_fill(1, 11, 0);
    $settlers[10] = 3;
    $movement = new MovementsModel();
    $settlementEventTime = (time() + 3600) * 1000;
    $settlementRegressionTasks[] = (int)$movement->addMovement($sourceA, $settlementRegressionInvalidTarget, 1, $settlers, 0, 0, 0, 0, 0, MovementsModel::ATTACKTYPE_SETTLERS, $settlementEventTime, $settlementEventTime);
    expect_true($settlementRegressionTasks[0] > 0, 'occupied available-villages settlement queued');
    expect_true($db->commit(), 'settlement regression fixture committed');
    $settlementRegressionCommitted = true;

    $sourceResourcesBefore = (int)$db->fetchScalar("SELECT wood FROM vdata WHERE kid=$sourceA");
    expect_true(Automation::getInstance()->processMovementTask($settlementRegressionTasks[0]), 'occupied available-villages settlement processed');
    expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$settlementRegressionInvalidTarget"), 'occupied available-villages creates no village');
    expect_same($sourceResourcesBefore + 750, (int)$db->fetchScalar("SELECT wood FROM vdata WHERE kid=$sourceA"), 'failed settlement returns resources');
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM movement WHERE kid=$settlementRegressionInvalidTarget AND to_kid=$sourceA AND mode=1 AND u10=3"), 'failed settlement queues settler return');

    $settlementRegressionTasks = [];
    $settlementRegressionTasks[] = (int)$movement->addMovement($sourceB, $settlementRegressionTarget, 1, $settlers, 0, 0, 0, 0, 0, MovementsModel::ATTACKTYPE_SETTLERS, $settlementEventTime, $settlementEventTime);
    $settlementRegressionTasks[] = (int)$movement->addMovement($sourceC, $settlementRegressionTarget, 1, $settlers, 0, 0, 0, 0, 0, MovementsModel::ATTACKTYPE_SETTLERS, $settlementEventTime, $settlementEventTime);
    expect_true($settlementRegressionTasks[0] > 0 && $settlementRegressionTasks[1] > 0, 'barrier settlement race movements queued');
    $barrierPath = tempnam(sys_get_temp_dir(), 'ov-settle-start-');
    $readyPaths = [tempnam(sys_get_temp_dir(), 'ov-settle-ready-'), tempnam(sys_get_temp_dir(), 'ov-settle-ready-')];
    expect_true($barrierPath !== false && $readyPaths[0] !== false && $readyPaths[1] !== false, 'barrier settlement race files created');
    $settlementRegressionBarrierFiles = array_merge([$barrierPath], $readyPaths);
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    foreach ($settlementRegressionTasks as $i => $task) {
        $pipes = [];
        $process = proc_open(['php', '/app/tests/movement-task-worker.php', (string)$task, $barrierPath, $readyPaths[$i]], $descriptorSpec, $pipes);
        expect_true(is_resource($process), "barrier settlement worker $i started");
        $settlementRegressionWorkers[] = ['process' => $process, 'pipes' => $pipes];
    }
    $deadline = microtime(true) + 10;
    while (
        (@file_get_contents($readyPaths[0]) !== 'ready' || @file_get_contents($readyPaths[1]) !== 'ready')
        && microtime(true) < $deadline
    ) {
        usleep(1000);
    }
    expect_same('ready', @file_get_contents($readyPaths[0]), 'first barrier settlement worker ready');
    expect_same('ready', @file_get_contents($readyPaths[1]), 'second barrier settlement worker ready');
    expect_true(file_put_contents($barrierPath, 'go', LOCK_EX) !== false, 'barrier settlement workers released');
    foreach ($settlementRegressionWorkers as $i => &$worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        expect_same(0, $exit, "barrier settlement worker $i exit: $stderr");
        expect_same('', $stderr, "barrier settlement worker $i stderr");
        expect_same('true', $stdout, "barrier settlement worker $i processed");
    }
    unset($worker);
    $winner = (int)$db->fetchScalar("SELECT owner FROM vdata WHERE kid=$settlementRegressionTarget");
    expect_true(in_array($winner, [$settlementRegressionUsers[1], $settlementRegressionUsers[2]], true), 'settlement race has one valid winner');
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM vdata WHERE kid=$settlementRegressionTarget"), 'settlement race creates exactly one village');
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM movement WHERE kid=$settlementRegressionTarget AND mode=1 AND u10=3"), 'settlement race has one losing return');
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE id>$settlementRegressionSurroundingFloor AND type=" . NoticeHelper::SURROUNDING_VILLAGE_FOUND . " AND params LIKE '%:$settlementRegressionTarget'"), 'settlement race records one found-village event');
    expect_same(1, (int)$db->fetchScalar("SELECT COUNT(*) FROM ndata WHERE uid IN ({$settlementRegressionUsers[1]}, {$settlementRegressionUsers[2]})"), 'settlement race creates one success notice');
} finally {
    foreach ($settlementRegressionWorkers as &$worker) {
        if (isset($worker['process']) && is_resource($worker['process'])) {
            $status = proc_get_status($worker['process']);
            if (!empty($status['running'])) {
                proc_terminate($worker['process']);
            }
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($worker['process']);
        }
    }
    unset($worker);
    foreach ($settlementRegressionBarrierFiles as $file) {
        if (is_string($file) && file_exists($file)) {
            unlink($file);
        }
    }
    if (!$settlementRegressionCommitted) {
        $db->rollback();
    }
    if ($settlementRegressionKids !== []) {
        $kids = implode(',', array_map('intval', $settlementRegressionKids));
        $uids = implode(',', array_map('intval', $settlementRegressionUsers));
        $db->query("DELETE FROM movement WHERE kid IN ($kids) OR to_kid IN ($kids)");
        $db->query("DELETE FROM ndata WHERE uid IN ($uids)");
        $db->query("DELETE FROM surrounding WHERE id>$settlementRegressionSurroundingFloor AND type=" . NoticeHelper::SURROUNDING_VILLAGE_FOUND . " AND (params LIKE '%:$settlementRegressionInvalidTarget' OR params LIKE '%:$settlementRegressionTarget')");
        $db->query("DELETE FROM units WHERE kid IN ($kids)");
        $db->query("DELETE FROM fdata WHERE kid IN ($kids)");
        $db->query("DELETE FROM tdata WHERE kid IN ($kids)");
        $db->query("DELETE FROM smithy WHERE kid IN ($kids)");
        $db->query("DELETE FROM vdata WHERE kid IN ($kids)");
        $db->query("DELETE FROM users WHERE id IN ($uids)");
        foreach ($settlementRegressionOriginalMap as $kid => $state) {
            $db->query("UPDATE wdata SET occupied={$state[0]} WHERE id=$kid");
            $db->query("UPDATE available_villages SET occupied={$state[1]} WHERE kid=$kid");
        }
    }
    if (is_array($settlementRegressionSummary)) {
        $firstVillageName = $db->real_escape_string((string)$settlementRegressionSummary['first_village_player_name']);
        $firstVillageTime = (int)$settlementRegressionSummary['first_village_time'];
        $db->query(
            "UPDATE summary
             SET first_village_player_name='$firstVillageName', first_village_time=$firstVillageTime"
        );
    }
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE movement AUTO_INCREMENT=$movementAutoIncrement");
    $db->query("ALTER TABLE ndata AUTO_INCREMENT=$noticeAutoIncrement");
    $db->query("ALTER TABLE surrounding AUTO_INCREMENT=$surroundingAutoIncrement");
}

$autoExtendOwner = 2000000060;
$autoExtendTask = 2000000001;
$secondAutoExtendTask = 2000000002;
$autoExtendWorkers = [];
$autoExtendBarrierFiles = [];
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id=$autoExtendOwner"),
        'auto-extension fixture user ID available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM autoExtend WHERE id IN ($autoExtendTask, $secondAutoExtendTask)"
        ),
        'auto-extension fixture task IDs available'
    );

    $plusCost = (int)$config->gold->plusGold;
    $plusDuration = (int)$config->gold->plusAccountDurationSeconds;
    $boostCost = (int)$config->gold->productionBoostGold;
    $boostDuration = (int)$config->gold->productionBoostDurationSeconds;
    $autoExtendNow = time();
    $plusCommence = $autoExtendNow + 60;
    $boostCommence = $autoExtendNow + 120;
    $db->query("INSERT INTO users
        (id, uuid, name, password, email, race, kid, gift_gold, bought_gold, plus, b1, desc1, desc2, note)
        VALUES
        ($autoExtendOwner, 'ov-regression-auto-extend', 'OVAutoExtend', 'x', '', 1, 1,
         " . ($plusCost - 1) . ", 0, 0, 0, '', '', '')");
    $db->query("INSERT INTO daily_quest (uid, qst5) VALUES ($autoExtendOwner, 0)");
    $db->query("INSERT INTO autoExtend
        (id, uid, type, commence, lastChecked, enabled, finished)
        VALUES ($autoExtendTask, $autoExtendOwner, 1, $plusCommence, 0, 1, 0)");

    expect_same(
        false,
        GoldHelper::decreaseGold($autoExtendOwner, $plusCost),
        'underfunded gold debit rejected'
    );
    expect_same(
        ($plusCost - 1) . '|0|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(gift_gold, '|', bought_gold, '|',
                (SELECT qst5 FROM daily_quest WHERE uid=$autoExtendOwner))
             FROM users WHERE id=$autoExtendOwner"
        ),
        'underfunded gold debit preserves the balance and quest state'
    );

    $runAutoExtendRace = function (array $taskIds, string $label) use (
        $autoExtendNow,
        &$autoExtendWorkers,
        &$autoExtendBarrierFiles
    ): array {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $barrierPath = tempnam(sys_get_temp_dir(), 'ov-auto-extend-start-');
        expect_true($barrierPath !== false, "$label start barrier created");
        $autoExtendBarrierFiles[] = $barrierPath;
        $readyPaths = [];
        foreach ($taskIds as $i => $taskId) {
            $readyPath = tempnam(sys_get_temp_dir(), 'ov-auto-extend-ready-');
            expect_true($readyPath !== false, "$label worker $i ready signal created");
            $readyPaths[] = $readyPath;
            $autoExtendBarrierFiles[] = $readyPath;
            $pipes = [];
            $process = proc_open(
                [
                    'php',
                    '/app/tests/auto-extend-worker.php',
                    (string)$taskId,
                    (string)$autoExtendNow,
                    $barrierPath,
                    $readyPath,
                ],
                $descriptorSpec,
                $pipes
            );
            expect_true(is_resource($process), "$label worker $i started");
            $autoExtendWorkers[] = ['process' => $process, 'pipes' => $pipes];
        }

        $readyDeadline = microtime(true) + 10;
        do {
            $ready = true;
            foreach ($readyPaths as $readyPath) {
                if (@file_get_contents($readyPath) !== 'ready') {
                    $ready = false;
                    break;
                }
            }
            if (!$ready) {
                usleep(1000);
            }
        } while (!$ready && microtime(true) < $readyDeadline);
        expect_true($ready, "$label workers ready");
        expect_true(file_put_contents($barrierPath, 'go', LOCK_EX) !== false, "$label workers released");

        $outcomes = [];
        $workerStart = count($autoExtendWorkers) - count($taskIds);
        foreach ($taskIds as $i => $_taskId) {
            $workerIndex = $workerStart + $i;
            $worker = &$autoExtendWorkers[$workerIndex];
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            $worker['process'] = null;
            $worker['pipes'] = [];
            expect_same(0, $exitCode, "$label worker $i exit status: $stderr");
            expect_same('', $stderr, "$label worker $i stderr");
            $outcomes[] = $stdout;
            unset($worker);
        }

        return $outcomes;
    };

    $db->query("UPDATE users SET gift_gold=$plusCost, bought_gold=0 WHERE id=$autoExtendOwner");
    $sameTaskOutcomes = $runAutoExtendRace(
        [$autoExtendTask, $autoExtendTask],
        'same-task auto-extension race'
    );
    sort($sameTaskOutcomes);
    expect_same(['false', 'true'], $sameTaskOutcomes, 'same auto-extension task applies once');
    $firstPlusShowTo = $plusCommence + $plusDuration;
    expect_same(
        "0|0|$firstPlusShowTo|1|$firstPlusShowTo|0",
        (string)$db->fetchScalar(
            "SELECT CONCAT(u.gift_gold, '|', u.bought_gold, '|', u.plus, '|', q.qst5, '|',
                a.commence, '|', a.lastChecked)
             FROM users u
             JOIN daily_quest q ON q.uid=u.id
             JOIN autoExtend a ON a.uid=u.id AND a.id=$autoExtendTask
             WHERE u.id=$autoExtendOwner"
        ),
        'same-task auto-extension charges and grants exactly once'
    );

    $db->query(
        "UPDATE users
         SET gift_gold=" . ($plusCost + $boostCost) . ", bought_gold=0, plus=0, b1=0
         WHERE id=$autoExtendOwner"
    );
    $db->query("UPDATE daily_quest SET qst5=0 WHERE uid=$autoExtendOwner");
    $db->query(
        "UPDATE autoExtend
         SET type=1, commence=$plusCommence, lastChecked=0, enabled=1, finished=0
         WHERE id=$autoExtendTask"
    );
    $db->query("INSERT INTO autoExtend
        (id, uid, type, commence, lastChecked, enabled, finished)
        VALUES ($secondAutoExtendTask, $autoExtendOwner, 2, $boostCommence, 0, 1, 0)");

    $distinctTaskOutcomes = $runAutoExtendRace(
        [$autoExtendTask, $secondAutoExtendTask],
        'same-user auto-extension race'
    );
    sort($distinctTaskOutcomes);
    expect_same(['true', 'true'], $distinctTaskOutcomes, 'distinct auto-extension tasks both apply');
    $secondPlusShowTo = $plusCommence + $plusDuration;
    $boostShowTo = $boostCommence + $boostDuration;
    expect_same(
        "0|0|$secondPlusShowTo|$boostShowTo|2|$secondPlusShowTo|$boostShowTo",
        (string)$db->fetchScalar(
            "SELECT CONCAT(u.gift_gold, '|', u.bought_gold, '|', u.plus, '|', u.b1, '|', q.qst5, '|',
                MAX(IF(a.id=$autoExtendTask, a.commence, 0)), '|',
                MAX(IF(a.id=$secondAutoExtendTask, a.commence, 0)))
             FROM users u
             JOIN daily_quest q ON q.uid=u.id
             JOIN autoExtend a ON a.uid=u.id
             WHERE u.id=$autoExtendOwner
             GROUP BY u.id, u.gift_gold, u.bought_gold, u.plus, u.b1, q.qst5"
        ),
        'same-user auto-extensions serialize both gold debits and benefits'
    );
} finally {
    foreach ($autoExtendWorkers as &$worker) {
        if (!isset($worker['process']) || !is_resource($worker['process'])) {
            continue;
        }
        $status = proc_get_status($worker['process']);
        if (!empty($status['running'])) {
            proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
        $worker['process'] = null;
        $worker['pipes'] = [];
    }
    unset($worker);
    foreach ($autoExtendBarrierFiles as $barrierFile) {
        if (is_string($barrierFile) && file_exists($barrierFile)) {
            unlink($barrierFile);
        }
    }
    $db->query(
        "DELETE FROM scheduled_task_failures
         WHERE task_table='autoExtend' AND task_id IN ($autoExtendTask, $secondAutoExtendTask)"
    );
    $db->query("DELETE FROM autoExtend WHERE id IN ($autoExtendTask, $secondAutoExtendTask)");
    $db->query("DELETE FROM infobox WHERE uid=$autoExtendOwner");
    $db->query("DELETE FROM daily_quest WHERE uid=$autoExtendOwner");
    $db->query("DELETE FROM users WHERE id=$autoExtendOwner");
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE autoExtend AUTO_INCREMENT=$autoExtendAutoIncrement");
    $db->query("ALTER TABLE infobox AUTO_INCREMENT=$infoBoxAutoIncrement");
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
$oasisConqueror = 2000000045;
$oasisConquerorVillage = 2000000045;
$oasisTask = 2000000001;
$oasisCrashTask = 2000000002;
$oasisStaleTask = 2000000003;
$oasisCaptureTime = time() - 500;
$oasisTransferTime = time() - 400;
$oasisAbandonTime = time() - 300;
$oasisRecaptureTime = time() - 200;
$oasisCrashTime = time() - 100;
$db->begin_transaction();
try {
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE id IN ($oasisOwner, $oasisConqueror)"),
        'oasis lifecycle fixture users available'
    );
    expect_same(
        0,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM odelete WHERE id IN ($oasisTask, $oasisCrashTask, $oasisStaleTask)"),
        'oasis-deletion fixture tasks available'
    );
    $db->query("INSERT INTO users (id, uuid, name, password, email, race, kid, total_villages, desc1, desc2, note)
        VALUES
        ($oasisOwner, 'ov-regression-oasis', 'OVOasis', 'x', '', 1, $oasisVillage, 1, '', '', ''),
        ($oasisConqueror, 'ov-regression-oasis-conqueror', 'OVOasisConqueror', 'x', '', 3, $oasisConquerorVillage, 1, '', '', '')");
    $lastUpdate = miliseconds();
    $db->query("INSERT INTO vdata
        (kid, owner, fieldtype, name, capital, pop, cp, wood, clay, iron, woodp, clayp, ironp, maxstore,
         crop, cropp, maxcrop, upkeep, lastmupdate, created, expandedfrom)
        VALUES
        ($oasisVillage, $oasisOwner, 3, 'OV Oasis Village', 1, 0, 0,
         0, 0, 0, 0, 0, 0, 1000000, 1000, 0, 1000000, 0, $lastUpdate, " . time() . ", 0),
        ($oasisConquerorVillage, $oasisConqueror, 3, 'OV Oasis Conqueror Village', 1, 0, 0,
         0, 0, 0, 0, 0, 0, 1000000, 1000, 0, 1000000, 0, $lastUpdate, " . time() . ", 0)");
    $db->query("INSERT INTO fdata (kid) VALUES ($oasisVillage), ($oasisConquerorVillage)");
    $db->query("INSERT INTO odata
        (kid, type, did, wood, iron, clay, crop, lastmupdate, owner, loyalty)
        VALUES ($oasisTarget, 1, 0, 0, 0, 0, 0, $lastUpdate, 0, 100)");
    $db->query("INSERT INTO wdata (id, x, y, fieldtype, oasistype, landscape, occupied)
        VALUES ($oasisTarget, 10, 10, 3, 1, 1, 0)");

    $surroundingBeforeOccupationId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    expect_true(
        OasesModel::captureOasis($oasisTarget, $oasisOwner, $oasisVillage, $oasisCaptureTime),
        'unoccupied oasis captured'
    );
    expect_same(
        "$oasisOwner|$oasisVillage|1|$oasisCaptureTime|$oasisCaptureTime",
        (string)$db->fetchScalar(
            "SELECT CONCAT(o.owner, '|', o.did, '|', w.occupied, '|', o.conquered_time, '|', o.last_loyalty_update)
             FROM odata o JOIN wdata w ON w.id=o.kid WHERE o.kid=$oasisTarget"
        ),
        'oasis occupation persists ownership and occurrence time'
    );
    $occupationSurrounding = $db->query(
        "SELECT x, y, type, params, time FROM surrounding
         WHERE id>$surroundingBeforeOccupationId AND x=10 AND y=10 ORDER BY id"
    );
    expect_same(1, $occupationSurrounding->num_rows, 'oasis occupation surrounding event count');
    $occupationSurrounding = $occupationSurrounding->fetch_assoc();
    expect_same(10, (int)$occupationSurrounding['x'], 'oasis occupation surrounding x coordinate');
    expect_same(10, (int)$occupationSurrounding['y'], 'oasis occupation surrounding y coordinate');
    expect_same(NoticeHelper::SURROUNDING_OASIS_OCCUPY, (int)$occupationSurrounding['type'], 'oasis occupation surrounding type');
    expect_same("$oasisOwner:OVOasis", $occupationSurrounding['params'], 'oasis occupation surrounding payload');
    expect_same($oasisCaptureTime, (int)$occupationSurrounding['time'], 'oasis occupation surrounding timestamp');

    $surroundingAfterOccupation = (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE x=10 AND y=10");
    expect_same(
        false,
        OasesModel::captureOasis($oasisTarget, $oasisOwner, $oasisVillage, $oasisCaptureTime),
        'duplicate oasis occupation ignored'
    );
    expect_same(
        $surroundingAfterOccupation,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE x=10 AND y=10"),
        'duplicate oasis occupation records no surrounding event'
    );

    $wrongReleaseState = (string)$db->fetchScalar(
        "SELECT CONCAT(o.owner, '|', o.did, '|', w.occupied) FROM odata o JOIN wdata w ON w.id=o.kid WHERE o.kid=$oasisTarget"
    );
    $wrongReleaseResources = (string)$db->fetchScalar(
        "SELECT GROUP_CONCAT(CONCAT_WS('|', kid, wood, clay, iron, crop, lastmupdate) ORDER BY kid SEPARATOR ':')
         FROM vdata WHERE kid IN ($oasisVillage, $oasisConquerorVillage)"
    );
    expect_same(
        false,
        OasesModel::releaseOasis($oasisTarget, $oasisConquerorVillage, $oasisTransferTime, true),
        'wrong-source oasis release ignored'
    );
    expect_same(
        $wrongReleaseState,
        (string)$db->fetchScalar(
            "SELECT CONCAT(o.owner, '|', o.did, '|', w.occupied) FROM odata o JOIN wdata w ON w.id=o.kid WHERE o.kid=$oasisTarget"
        ),
        'wrong-source oasis release preserves ownership'
    );
    expect_same(
        $wrongReleaseResources,
        (string)$db->fetchScalar(
            "SELECT GROUP_CONCAT(CONCAT_WS('|', kid, wood, clay, iron, crop, lastmupdate) ORDER BY kid SEPARATOR ':')
             FROM vdata WHERE kid IN ($oasisVillage, $oasisConquerorVillage)"
        ),
        'wrong-source oasis release preserves village resources'
    );
    expect_same(
        $surroundingAfterOccupation,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE x=10 AND y=10"),
        'wrong-source oasis release records no surrounding event'
    );
    $db->query("INSERT INTO odelete (id, kid, oid, end_time)
        VALUES ($oasisStaleTask, $oasisConquerorVillage, $oasisTarget, $oasisTransferTime)");
    $automation = Automation::getInstance();
    expect_true($automation->processOasisDeletionTask($oasisStaleTask), 'stale oasis deletion consumed as no-op');
    expect_same(
        "0|$wrongReleaseState",
        (string)$db->fetchScalar(
            "SELECT CONCAT((SELECT COUNT(*) FROM odelete WHERE id=$oasisStaleTask), '|', o.owner, '|', o.did, '|', w.occupied)
             FROM odata o JOIN wdata w ON w.id=o.kid WHERE o.kid=$oasisTarget"
        ),
        'stale oasis deletion preserves current ownership'
    );
    expect_same(
        $surroundingAfterOccupation,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE x=10 AND y=10"),
        'stale oasis deletion records no surrounding event'
    );

    $failedTransferState = (string)$db->fetchScalar(
        "SELECT CONCAT(o.owner, '|', o.did, '|', w.occupied) FROM odata o JOIN wdata w ON w.id=o.kid WHERE o.kid=$oasisTarget"
    );
    $failedTransferResources = (string)$db->fetchScalar(
        "SELECT GROUP_CONCAT(CONCAT_WS('|', kid, wood, clay, iron, crop, lastmupdate) ORDER BY kid SEPARATOR ':')
         FROM vdata WHERE kid IN ($oasisVillage, $oasisConquerorVillage)"
    );
    $failedTransferSurrounding = (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE x=10 AND y=10");
    expect_true($db->begin_transaction(), 'failed hostile oasis transfer transaction started');
    try {
        if (!OasesModel::releaseOasis($oasisTarget, $oasisVillage, $oasisTransferTime, false)
            || !OasesModel::captureOasis($oasisTarget, 2999999999, $oasisConquerorVillage, $oasisTransferTime)) {
            throw new RuntimeException('Simulated hostile oasis capture failure.');
        }
        throw new RuntimeException('Simulated hostile oasis capture failure was not triggered.');
    } catch (RuntimeException $e) {
        expect_same('Simulated hostile oasis capture failure.', $e->getMessage(), 'hostile oasis capture failure propagated');
        expect_true($db->rollback(), 'failed hostile oasis transfer rolled back');
    }
    expect_same(
        $failedTransferState,
        (string)$db->fetchScalar(
            "SELECT CONCAT(o.owner, '|', o.did, '|', w.occupied) FROM odata o JOIN wdata w ON w.id=o.kid WHERE o.kid=$oasisTarget"
        ),
        'failed hostile oasis transfer preserves ownership'
    );
    expect_same(
        $failedTransferResources,
        (string)$db->fetchScalar(
            "SELECT GROUP_CONCAT(CONCAT_WS('|', kid, wood, clay, iron, crop, lastmupdate) ORDER BY kid SEPARATOR ':')
             FROM vdata WHERE kid IN ($oasisVillage, $oasisConquerorVillage)"
        ),
        'failed hostile oasis transfer rolls back village resources'
    );
    expect_same(
        $failedTransferSurrounding,
        (int)$db->fetchScalar("SELECT COUNT(*) FROM surrounding WHERE x=10 AND y=10"),
        'failed hostile oasis transfer records no surrounding event'
    );

    $surroundingBeforeTransferId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    expect_true(
        OasesModel::releaseOasis($oasisTarget, $oasisVillage, $oasisTransferTime, false),
        'hostile oasis transfer releases previous owner without abandonment news'
    );
    expect_true(
        OasesModel::captureOasis($oasisTarget, $oasisConqueror, $oasisConquerorVillage, $oasisTransferTime),
        'hostile oasis transfer assigns conqueror'
    );
    expect_same(
        "$oasisConqueror|$oasisConquerorVillage|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT(o.owner, '|', o.did, '|', w.occupied) FROM odata o JOIN wdata w ON w.id=o.kid WHERE o.kid=$oasisTarget"
        ),
        'hostile oasis transfer persists new ownership'
    );
    $transferSurrounding = $db->query(
        "SELECT type, params, time FROM surrounding
         WHERE id>$surroundingBeforeTransferId AND x=10 AND y=10 ORDER BY id"
    );
    expect_same(1, $transferSurrounding->num_rows, 'hostile oasis transfer records one surrounding event');
    $transferSurrounding = $transferSurrounding->fetch_assoc();
    expect_same(NoticeHelper::SURROUNDING_OASIS_OCCUPY, (int)$transferSurrounding['type'], 'hostile oasis transfer surrounding type');
    expect_same("$oasisConqueror:OVOasisConqueror", $transferSurrounding['params'], 'hostile oasis transfer payload');
    expect_same($oasisTransferTime, (int)$transferSurrounding['time'], 'hostile oasis transfer timestamp');

    $movementTarget = 2000000007;
    $db->query("INSERT INTO movement
        (id, kid, to_kid, race, u1, mode, attack_type, start_time, end_time, data)
        VALUES ($movementTarget, 2000000006, $oasisTarget, 1, 5, 0, 0, 0, 0, '')");
    $db->query("INSERT INTO odelete (id, kid, oid, end_time)
        VALUES ($oasisTask, $oasisConquerorVillage, $oasisTarget, $oasisAbandonTime)");

    $surroundingBeforeAbandonId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");
    expect_true($automation->processOasisDeletionTask($oasisTask), 'oasis deletion processed');
    expect_same(
        '0|0|0|0|1',
        (string)$db->fetchScalar(
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM odelete WHERE id=$oasisTask), '|', o.owner, '|', o.did, '|', w.occupied, '|',
                (SELECT mode FROM movement WHERE id=$movementTarget)
             ) FROM odata o JOIN wdata w ON w.id=o.kid WHERE o.kid=$oasisTarget"
        ),
        'oasis release and incoming movement cancellation commit with queue consumption'
    );
    $abandonSurrounding = $db->query(
        "SELECT type, params, time FROM surrounding
         WHERE id>$surroundingBeforeAbandonId AND x=10 AND y=10 ORDER BY id"
    );
    expect_same(1, $abandonSurrounding->num_rows, 'voluntary oasis abandonment surrounding event count');
    $abandonSurrounding = $abandonSurrounding->fetch_assoc();
    expect_same(NoticeHelper::SURROUNDING_OASIS_ABANDON, (int)$abandonSurrounding['type'], 'voluntary oasis abandonment surrounding type');
    expect_same('', $abandonSurrounding['params'], 'voluntary oasis abandonment surrounding payload');
    expect_same($oasisAbandonTime, (int)$abandonSurrounding['time'], 'voluntary oasis abandonment timestamp');
    expect_same(false, $automation->processOasisDeletionTask($oasisTask), 'duplicate oasis deletion ignored');
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeAbandonId AND x=10 AND y=10"
        ),
        'duplicate oasis deletion records no surrounding event'
    );

    expect_true(
        OasesModel::captureOasis($oasisTarget, $oasisOwner, $oasisVillage, $oasisRecaptureTime),
        'oasis recaptured for rollback fixture'
    );
    $db->query("INSERT INTO odelete (id, kid, oid, end_time)
        VALUES ($oasisCrashTask, $oasisVillage, $oasisTarget, $oasisCrashTime)");
    $surroundingBeforeCrashId = (int)$db->fetchScalar("SELECT COALESCE(MAX(id), 0) FROM surrounding");

    try {
        TransactionalTask::consume('odelete', $oasisCrashTask, function (array $row): void {
            expect_true(
                OasesModel::releaseOasis($row['oid'], $row['kid'], $row['end_time'], true),
                'oasis released before simulated worker crash'
            );
            throw new RuntimeException('Simulated oasis worker crash.');
        });
        throw new RuntimeException('Simulated oasis worker crash was not propagated.');
    } catch (RuntimeException $e) {
        expect_same('Simulated oasis worker crash.', $e->getMessage(), 'oasis crash propagated');
    }
    expect_same(
        "$oasisOwner|$oasisVillage|1|1|1",
        (string)$db->fetchScalar(
            "SELECT CONCAT(o.owner, '|', o.did, '|', w.occupied, '|',
                (SELECT COUNT(*) FROM odelete WHERE id=$oasisCrashTask), '|',
                (SELECT attempts FROM scheduled_task_failures WHERE task_table='odelete' AND task_id=$oasisCrashTask))
             FROM odata o JOIN wdata w ON w.id=o.kid WHERE o.kid=$oasisTarget"
        ),
        'oasis crash rolls back release and preserves task'
    );
    expect_same(
        0,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeCrashId AND x=10 AND y=10"
        ),
        'oasis crash rolls back surrounding event'
    );
    expect_true($automation->processOasisDeletionTask($oasisCrashTask), 'oasis deletion retry processed');
    expect_same(
        '0|0|0|0',
        (string)$db->fetchScalar(
            "SELECT CONCAT(o.owner, '|', o.did, '|', w.occupied, '|',
                (SELECT COUNT(*) FROM odelete WHERE id=$oasisCrashTask))
             FROM odata o JOIN wdata w ON w.id=o.kid WHERE o.kid=$oasisTarget"
        ),
        'oasis retry releases oasis once'
    );
    $retryAbandonSurrounding = $db->query(
        "SELECT type, params, time FROM surrounding
         WHERE id>$surroundingBeforeCrashId AND x=10 AND y=10 ORDER BY id"
    );
    expect_same(1, $retryAbandonSurrounding->num_rows, 'oasis retry records one abandonment event');
    $retryAbandonSurrounding = $retryAbandonSurrounding->fetch_assoc();
    expect_same(NoticeHelper::SURROUNDING_OASIS_ABANDON, (int)$retryAbandonSurrounding['type'], 'oasis retry surrounding type');
    expect_same('', $retryAbandonSurrounding['params'], 'oasis retry surrounding payload');
    expect_same($oasisCrashTime, (int)$retryAbandonSurrounding['time'], 'oasis retry surrounding timestamp');
    expect_same(false, $automation->processOasisDeletionTask($oasisCrashTask), 'replayed oasis retry ignored');
    expect_same(
        1,
        (int)$db->fetchScalar(
            "SELECT COUNT(*) FROM surrounding WHERE id>$surroundingBeforeCrashId AND x=10 AND y=10"
        ),
        'replayed oasis retry cannot duplicate abandonment event'
    );
} finally {
    $db->query("DELETE FROM scheduled_task_failures WHERE task_table='odelete' AND task_id IN ($oasisTask, $oasisCrashTask, $oasisStaleTask)");
    $db->rollback();
    $db->query("DELETE FROM movement WHERE id=2000000007");
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE movement AUTO_INCREMENT=$movementAutoIncrement");
    $db->query("ALTER TABLE odelete AUTO_INCREMENT=$oasisDeletionAutoIncrement");
    $db->query("ALTER TABLE surrounding AUTO_INCREMENT=$surroundingAutoIncrement");
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
