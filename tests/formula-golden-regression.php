<?php

declare(strict_types=1);

use Core\Config as CoreConfig;
use Controller\RallyPoint\Simulator;
use Game\Formulas;
use Model\ArtefactsModel;
use Model\NatarsModel;
use Model\WonderOfTheWorldModel;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

function golden_same(mixed $expected, mixed $actual, string $label): void
{
    if (is_array($expected) && is_array($actual) && array_keys($expected) === array_keys($actual)) {
        foreach ($expected as $key => $value) {
            golden_same($value, $actual[$key], "$label[$key]");
        }
        return;
    }
    if (is_numeric($expected) && is_numeric($actual) && (float)$expected === (float)$actual) {
        return;
    }
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, received %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function golden_close(float $expected, float $actual, float $tolerance, string $label): void
{
    if (abs($expected - $actual) > $tolerance) {
        throw new RuntimeException(sprintf(
            '%s: expected %.12f +/- %.12f, received %.12f',
            $label,
            $expected,
            $tolerance,
            $actual
        ));
    }
}

$config = CoreConfig::getInstance();
$start = (int)$config->game->start_time;

// These vectors are the supported 10x/25-radius world contract. Keep them
// explicit and side-effect free so a formula change produces a reviewable
// failure instead of silently changing game balance.
golden_same(10, (int)$config->game->speed, 'supported formula speed');
golden_same(25, (int)MAP_SIZE, 'supported formula map radius');
golden_same([40.0, 100.0, 50.0, 60.0], Formulas::buildingUpgradeCosts(1, 1), 'level-one woodcutter costs');
golden_same([4040.0, 10105.0, 5050.0, 6060.0], Formulas::buildingUpgradeCosts(1, 10), 'level-ten woodcutter costs');
golden_same(26.0, Formulas::buildingUpgradeTime(1, 1, 1), 'level-one woodcutter time');
golden_same([120, 100, 150, 30], Formulas::uTrainingCost(1), 'Roman legionnaire costs');
golden_same([95, 75, 40, 40], Formulas::uTrainingCost(11), 'Teuton clubswinger costs');
golden_same([100, 130, 55, 30], Formulas::uTrainingCost(21), 'Gaul phalanx costs');
golden_same(160, Formulas::uTrainingTime(1, 1), 'Roman legionnaire training time');
golden_same([820, 500, 1400, 340], Formulas::uResearchCost(1), 'Roman legionnaire research costs');
golden_same(24, Formulas::uSpeed(1), 'Roman legionnaire speed');
golden_same(60, Formulas::uCarry(11), 'Teuton clubswinger capacity');
golden_same(14000, Formulas::merchantCAP(1, 18), 'Roman level-18 merchant capacity');
golden_same(160, Formulas::merchantSpeed(1), 'Roman merchant speed');
golden_same(2800.0, Formulas::fieldProduction(10), 'level-ten resource production');
golden_same(80000.0, Formulas::storeCAP(20), 'level-twenty storage capacity');
golden_same(1000.0, Formulas::crannyCAP(10, 1), 'Roman level-ten cranny capacity');
golden_same(1500.0, Formulas::crannyCAP(10, 3), 'Gaul level-ten cranny capacity');
golden_same([100, 100, 100, 100], Formulas::getOasisProduction(1), 'single-resource oasis production');

golden_same(86400, Formulas::getProtectionBasicTime($start), 'initial beginner protection');
golden_same(97200, Formulas::getProtectionBasicTime($start + 86400), 'late-registration protection');
golden_same([1, 0], Formulas::buildingCpPop(1, 1, 2), 'woodcutter level-up population and culture');
golden_same(2, Formulas::buildingCropConsumption(1, 10), 'woodcutter upkeep');
golden_same(6, Formulas::buildingCP(1, 10), 'woodcutter culture points');
golden_same(200, Formulas::newVillageCP(2), 'second-village culture requirement');
golden_same(2000, Formulas::newVillageCP(4), 'fourth-village culture requirement');
golden_same(17, Formulas::countCPVillages(100000), 'culture-point village count');
golden_same(5, Formulas::getDistance(['x' => 0, 'y' => 0], ['x' => 3, 'y' => 4]), 'five-tile map distance');
golden_same(48, Formulas::heroSpeed(3, true), 'Gaul cavalry hero speed');
golden_same(28, Formulas::heroSpeed(1), 'Roman infantry hero speed');
golden_same(81, Formulas::wallPower(1, 20), 'Roman level-twenty wall power');
golden_same(64, Formulas::wallPower(3, 20), 'Gaul level-twenty wall power');
golden_same(10, Formulas::heroLevel(2750), 'hero level at 2750 experience');
golden_same(2750, Formulas::heroExperience(10), 'experience required for hero level ten');
golden_same(9900, Formulas::heroRegenerationTime(10), 'hero regeneration time');
golden_same([2000, 1800, 2800, 1200], Formulas::heroRegenerateCost(10, 1), 'Roman hero regeneration cost');
golden_same(8640, ArtefactsModel::getArtifactActivationTime(), 'artifact activation delay');
golden_same(8640, ArtefactsModel::getFoolArtifactChangeEffectInterval(), 'fool artifact change interval');

// Combat is intentionally checked through the production simulator, not a
// hand-written approximation, to protect the loss curve and casualty rules.
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
golden_close(0.290620131008, (float)$combat[0]['losses'][0], 0.000000000001, 'combat attacker loss ratio');
golden_same(1, $combat[0]['losses'][1], 'combat defender loss ratio');

golden_same([5, 10], WonderOfTheWorldModel::attackLevelsBetween(0, 10), 'crossed World Wonder attack levels');
golden_same([], WonderOfTheWorldModel::attackLevelsBetween(99, 100), 'no level-100 Natar attack');
golden_same(8640, WonderOfTheWorldModel::attackTravelSeconds(10), 'World Wonder attack travel time');
golden_same(1, WonderOfTheWorldModel::attackMultiplierForSpeed(10, false), 'World Wonder baseline multiplier');
golden_same(8640, NatarsModel::greyAreaAttackTravelSeconds(10), 'grey-area attack travel time');
golden_same(4, NatarsModel::greyAreaWaveDelayMilliseconds(14), 'last grey-area wave offset');
golden_same(2, count(WonderOfTheWorldModel::attackWavesForLevel(5)), 'World Wonder two-wave profile');
golden_same(3412, WonderOfTheWorldModel::attackWavesForLevel(5)[0][2], 'World Wonder clearing army');
golden_same(10, WonderOfTheWorldModel::attackWavesForLevel(5)[1][8], 'World Wonder demolition ballistae');

$attackProfile = [];
foreach (WonderOfTheWorldModel::attackLevelsBetween(0, 99) as $level) {
    $attackProfile[$level] = WonderOfTheWorldModel::attackWavesForLevel($level);
}
golden_same(
    'ba70e4e45ca6da8c3527fa9c746d710c4fca238217c6732253b98869dac1db9a',
    hash('sha256', json_encode($attackProfile)),
    'complete World Wonder Natar profile'
);

echo "Formula golden regressions passed.\n";
