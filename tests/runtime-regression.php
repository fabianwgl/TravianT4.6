<?php

declare(strict_types=1);

use Core\Config;
use Core\Database\DB;
use Core\Security\Password;
use Controller\RallyPoint\Simulator;
use Game\Buildings\BuildingHelper;
use Game\Formulas;

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
} finally {
    $db->rollback();
    $db->query("ALTER TABLE users AUTO_INCREMENT=$userAutoIncrement");
    $db->query("ALTER TABLE artefacts AUTO_INCREMENT=$artefactAutoIncrement");
}

echo "Runtime regression checks passed.\n";
