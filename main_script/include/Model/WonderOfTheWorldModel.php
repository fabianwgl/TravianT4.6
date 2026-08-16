<?php

namespace Model;

use Core\Config;
use Core\Database\DB;
use Game\Formulas;
use function getCustom;
use function getGameSpeed;
use const MAP_SIZE;

class WonderOfTheWorldModel
{
    private const ATTACK_WAVE_DELAY_MS = 1000;

    /**
     * Captured T4-era two-wave Natar attack profile. The supported 10x world
     * uses these baseline armies; only nonstandard high-speed worlds scale it.
     */
    private const ATTACK_PROFILE = [
        5 => [
            [1 => 0, 3412, 2814, 0, 4156, 3553, 9, 0],
            [1 => 0, 35, 0, 0, 77, 33, 17, 10]
        ],

        10 => [
            [1 => 0, 4314, 3688, 0, 5265, 4621, 13, 0],
            [1 => 0, 65, 0, 0, 175, 77, 28, 17]
        ],

        15 => [
            [1 => 0, 4645, 4267, 0, 5659, 5272, 15, 0],
            [1 => 0, 99, 0, 0, 305, 134, 40, 25]
        ],

        20 => [
            [1 => 0, 6207, 5881, 0, 7625, 7225, 22, 0],
            [1 => 0, 144, 0, 0, 456, 201, 56, 36]
        ],

        25 => [
            [1 => 0, 6004, 5977, 0, 7400, 7277, 23, 0],
            [1 => 0, 152, 0, 0, 499, 220, 58, 37]
        ],

        30 => [
            [1 => 0, 7073, 7181, 0, 8730, 8713, 27, 0],
            [1 => 0, 183, 0, 0, 607, 268, 69, 45]
        ],

        35 => [
            [1 => 0, 7090, 7320, 0, 8762, 8856, 28, 0],
            [1 => 0, 186, 0, 0, 620, 278, 70, 45]
        ],

        40 => [
            [1 => 0, 7852, 6967, 0, 9606, 8667, 25, 0],
            [1 => 0, 146, 0, 0, 431, 190, 60, 37]
        ],

        45 => [
            [1 => 0, 8480, 8883, 0, 10490, 10719, 35, 0],
            [1 => 0, 223, 0, 0, 750, 331, 83, 54]
        ],

        50 => [
            [1 => 0, 8522, 9038, 0, 10551, 10883, 35, 0],
            [1 => 0, 224, 0, 0, 757, 335, 83, 54]
        ],

        55 => [
            [1 => 0, 8931, 8690, 0, 10992, 10624, 32, 0],
            [1 => 0, 219, 0, 0, 707, 312, 84, 54]
        ],

        60 => [
            [1 => 0, 12138, 13013, 0, 15040, 15642, 51, 0],
            [1 => 0, 318, 0, 0, 1079, 477, 118, 76]
        ],

        65 => [
            [1 => 0, 13397, 14619, 0, 16622, 17521, 58, 0],
            [1 => 0, 345, 0, 0, 1182, 522, 127, 83]
        ],

        70 => [
            [1 => 0, 16323, 17665, 0, 20240, 21201, 70, 0],
            [1 => 0, 424, 0, 0, 1447, 640, 157, 102]
        ],

        75 => [
            [1 => 0, 20739, 22796, 0, 25746, 27288, 91, 0],
            [1 => 0, 529, 0, 0, 1816, 803, 194, 127]
        ],

        80 => [
            [1 => 0, 21857, 24180, 0, 27147, 28914, 97, 0],
            [1 => 0, 551, 0, 0, 1898, 839, 202, 132]
        ],

        85 => [
            [1 => 0, 22476, 25007, 0, 27928, 29876, 100, 0],
            [1 => 0, 560, 0, 0, 1933, 855, 205, 134]
        ],

        90 => [
            [1 => 0, 31345, 35053, 0, 38963, 41843, 141, 0],
            [1 => 0, 771, 0, 0, 2668, 1180, 281, 184]
        ],

        95 => [
            [1 => 0, 31720, 35635, 0, 39443, 42506, 144, 0],
            [1 => 0, 771, 0, 0, 2671, 1181, 281, 184]
        ],

        96 => [
            [1 => 0, 32885, 37007, 0, 40897, 44130, 150, 0],
            [1 => 0, 795, 0, 0, 2757, 1219, 289, 190]
        ],

        97 => [
            [1 => 0, 32940, 37099, 0, 40968, 44235, 150, 0],
            [1 => 0, 794, 0, 0, 2755, 1219, 289, 190]
        ],
        98 => [
            [1 => 0, 33521, 37691, 0, 41686, 44953, 152, 0],
            [1 => 0, 812, 0, 0, 2816, 1246, 296, 194]
        ],
        99 => [
            [1 => 0, 36251, 40861, 0, 45089, 48714, 165, 0],
            [1 => 0, 872, 0, 0, 3025, 1338, 317, 208]
        ]
    ];

    public static function attackLevelsBetween(int $fromLevel, int $toLevel): array
    {
        if ($toLevel <= $fromLevel) {
            return [];
        }

        return array_values(array_filter(
            array_keys(self::ATTACK_PROFILE),
            static fn(int $level): bool => $level > $fromLevel && $level <= $toLevel
        ));
    }

    public static function attackTravelSeconds(int $gameSpeed): int
    {
        return max((int)ceil(86400 / max(1, $gameSpeed)), 300);
    }

    public static function attackMultiplierForSpeed(int $gameSpeed, bool $instantFinish): int
    {
        $multiplier = (int)ceil(max(1, $gameSpeed) / ($instantFinish ? 250 : 350));
        if ($multiplier <= 1) {
            return 1;
        }
        if ($multiplier <= 3) {
            return 2;
        }
        if ($multiplier <= 10) {
            return 5;
        }

        return $multiplier;
    }

    public static function attackWavesForLevel(int $level, int $multiplier = 1): array
    {
        if (!isset(self::ATTACK_PROFILE[$level])) {
            return [];
        }

        $waves = [];
        foreach (self::ATTACK_PROFILE[$level] as $profile) {
            $units = array_fill(1, 11, 0);
            for ($unit = 1; $unit <= 8; ++$unit) {
                $units[$unit] = (int)ceil($profile[$unit] * max(1, $multiplier));
            }
            $waves[] = $units;
        }

        return $waves;
    }

    public function attackWWVillage(int $kid, int $level): int
    {
        if (!getCustom("attackWWOnLevelUp")) {
            return 0;
        }

        $waves = self::attackWavesForLevel(
            $level,
            self::attackMultiplierForSpeed(getGameSpeed(), isInstantFinishEnabled())
        );
        if (!$waves) {
            return 0;
        }

        $startTime = miliseconds();
        $travelTime = 1000 * self::attackTravelSeconds(getGameSpeed());
        $move = new MovementsModel();
        $db = DB::getInstance();
        $cap_kid = Formulas::xy2kid(0, 0);
        $scheduled = 0;
        if (!$db->begin_transaction()) {
            \logError('Unable to begin Natar World Wonder movement transaction.');

            return 0;
        }
        try {
            foreach ($waves as $wave => $units) {
                $movementId = $move->addMovement($cap_kid,
                    $kid,
                    5,
                    $units,
                    40,
                    40,
                    0,
                    0,
                    0,
                    MovementsModel::ATTACKTYPE_NORMAL,
                    $startTime,
                    $startTime + $travelTime + ($wave * self::ATTACK_WAVE_DELAY_MS));
                if (!$movementId) {
                    throw new \RuntimeException('Unable to persist Natar World Wonder movement.');
                }
                ++$scheduled;
            }
            if (!$db->commit()) {
                throw new \RuntimeException('Unable to commit Natar World Wonder movements.');
            }
        } catch (\Throwable $e) {
            $db->rollback();
            \logError($e->getMessage());

            return 0;
        }

        return $scheduled;
    }

    public function createWWVillages()
    {
        $count = Config::getProperty("custom", "wwCount");
        $max = ceil(MAP_SIZE / (MAP_SIZE > 100 ? 4 : 2));
        $locations = [
            Formulas::xy2kid($max, -$max),
            Formulas::xy2kid(-5, -11),
            Formulas::xy2kid($max, 0),
            Formulas::xy2kid(0, -$max),
            Formulas::xy2kid(-$max, $max),
            Formulas::xy2kid(9, -8),
            Formulas::xy2kid(-12, 2),
            Formulas::xy2kid(11, 6),
            Formulas::xy2kid(0, $max),
            Formulas::xy2kid(-$max, -$max),
            Formulas::xy2kid($max, $max),
            Formulas::xy2kid(-$max, 0),
            Formulas::xy2kid(-2, 12),
        ];
        for ($i = 1; $i <= min(13, $count); ++$i) {
            $this->createWWVillage($locations[$i - 1]);
        }
    }

    public function createWWVillage($kid)
    {
        $register = new RegisterModel();
        if ($kid < 0) {
            $kid = $register->generateWWNatarsVillage();
            if (!$kid) return;
        }
        if ($kid > 0) {
            $register->createWWVillage($kid);
        }
    }

    public function findPositionsForWWPlan($count)
    {
        $locations = [
            'nw' => [[-18, 30], [-35, 4], [-45, 37]],
            'sw' => [[-26, -25], [-10, -57], [-55, -20]],
            'ne' => [[33, 12], [12, 33], [10, 56], [55, 20]],
            'se' => [[30, -20], [4, -35], [44, -37]],
        ];
        $eachSide = max(1, floor($count / 4));
        $return = [];
        $register = new RegisterModel();
        foreach ($locations as $side_name => $side_locations) {
            $current = 0;
            $max = $eachSide + ($side_name == 'ne' && ($count % 2 == 1) ? 1 : 0);
            foreach ($side_locations as $coordinate) {
                if (($kid = $register->getBestPosition(Formulas::xy2kid($coordinate[0], $coordinate[1]), 0))) {
                    array_push($return, $kid);
                }
                ++$current;
                if ($current >= $max) break;
            }
        }
        return $return;
    }

    public static function getWWTroops($isGrayArea)
    {
        if (getGameSpeed() <= 10) {
            $multiplier = getGameSpeed();
            if ($multiplier <= 3) $multiplier = 2;
            else if ($multiplier <= 10) $multiplier = 5;
        } else {
            $multiplier = ceil(getGameSpeed() / (isInstantFinishEnabled() ? 50 : 60));
        }
        /*
         * $units_inner = [1 => 11842, 29476, 11852, 71, 19984, 14104, 2989, 2714, 0, 0, 0];
         * $units_outer = [1 => 16578, 38785, 15154, 88, 26403, 18566, 5392, 2325, 0, 0, 0];
         */
        if ($isGrayArea) {
            $units = [
                1 => mt_rand(11000, 12000),
                mt_rand(28000, 30000),
                mt_rand(11000, 13000),
                mt_rand(70, 150),
                mt_rand(19000, 21000),
                mt_rand(14000, 16000),
                mt_rand(2000, 4000),
                mt_rand(2000, 3500),
                0,
                0,
                0
            ];
        } else {
            $units = [
                1 => mt_rand(16000, 18000),
                mt_rand(38000, 41000),
                mt_rand(15000, 17000),
                mt_rand(80, 260),
                mt_rand(26000, 30000),
                mt_rand(18000, 20000),
                mt_rand(5000, 7000),
                mt_rand(2000, 4000),
                0,
                0,
                0
            ];
        }
        return array_map(function ($x) use ($multiplier) {
            return round($x * $multiplier);
        },  $units);
    }
}
