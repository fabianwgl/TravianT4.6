<?php

namespace Model;

use Core\Config;
use Core\Database\DB;
use Game\Buildings\BuildingHelper;
use Game\Formulas;
use Game\GoldHelper;
use Game\ResourcesHelper;
use function logError;

class MasterBuilder
{
    public function process($row)
    {
        $db = DB::getInstance();
        ResourcesHelper::updateVillageResources($row['kid'], true);
        $village = $db->query("SELECT capital, upkeep, isWW, pop, owner, kid, wood, woodp, clay, clayp, iron, ironp, crop, cropp, maxstore, maxcrop, lastmupdate FROM vdata WHERE kid={$row['kid']}");
        if (!$village->num_rows) {
            $db->query("DELETE FROM building_upgrade WHERE kid={$row['kid']}");
            //logError("MasterBuilder: Village does not exist.");
            return;
        }
        $village = $village->fetch_assoc();
        $player = $db->query("SELECT aid, race, gift_gold, bought_gold, plus FROM users WHERE id={$village['owner']} LIMIT 1");
        if (!$player->num_rows) {
            $db->query("DELETE FROM building_upgrade WHERE kid={$row['kid']}");
            //logError("MasterBuilder: Player does not exist.");
            return;
        }
        $player = $player->fetch_assoc();
        $buildings = $this->sortBuildings($row['kid']);
        $item_id = $buildings['buildings'][$row['building_field']]['item_id'];
        $normalQueueLength = (int)$db->fetchScalar("SELECT COUNT(id) FROM building_upgrade WHERE isMaster=0 AND building_field={$row['building_field']} AND kid={$row['kid']}");
        $level = self::queuedTargetLevel(
            (int)$buildings['buildings'][$row['building_field']]['level'],
            $normalQueueLength,
            0
        );
        $costs = Formulas::buildingUpgradeCosts($item_id, $level);
        $workers = $this->isWorkersBusy($player['race'],
            $player['plus'] >= time(),
            $row['kid'],
            $row['building_field'] <= 18,
            $item_id == 40);
        if ($workers['isBusy']) {
            $this->updateCommence((int)$row['kid'], false);
            return;
        }
        //ignore  ww here
        if ($item_id <> 40) {
            if (!$this->isResourcesAvailable($village, $costs)) {
                $this->updateCommence((int)$row['kid'], false);
                return;
            }
        }
        $db->query("DELETE FROM building_upgrade WHERE id={$row['id']}");
        if ($item_id <> 40) {
            $gold = Config::getInstance()->gold->masterBuilderGold;
            if (($player['gift_gold'] + $player['bought_gold']) < $gold) {
                $db->query("DELETE FROM building_upgrade WHERE isMaster=1 AND kid={$row['kid']}");
                logError('MasterBuilder: Not enough gold.');
                return;
            } else if ($gold > 0 && !GoldHelper::decreaseGold($village['owner'], $gold)) {
                $db->query("DELETE FROM building_upgrade WHERE isMaster=1 AND kid={$row['kid']}");
                logError('MasterBuilder: Unable to reduce gold.');
                return;
            }
        }
        $kid = $row['kid'];
        $commence = $row['commence'];
        if ($player['race'] == 1) {
            if ($item_id > 4 && $workers['buildsNum'] > 0) {
                $commence = $this->getLastCommence($kid);
            } else if ($item_id <= 4 && $workers['fieldsNum'] > 0) {
                $commence = $this->getLastCommence($kid);
            }
        } else {
            $commence = $this->getLastCommence($kid);
        }
        $commence += Formulas::buildingUpgradeTime($item_id, $level, $buildings['mainBuildingLevel'], $village['isWW']);
        $db->query("INSERT INTO building_upgrade (`kid`, `building_field`, `isMaster`, start_time, `commence`) VALUES ({$row['kid']},{$row['building_field']},0," . time() . ",$commence)");
        if ($item_id <> 40) {
            $db->query("UPDATE vdata SET wood=wood-{$costs[0]}, clay=clay-{$costs[1]}, iron=iron-{$costs[2]}, crop=crop-{$costs[3]} WHERE kid={$row['kid']}");
        }
    }

    private function getLastCommence($kid)
    {
        $db = DB::getInstance();
        $commence = $db->fetchScalar("SELECT commence FROM building_upgrade WHERE kid=$kid AND isMaster=0 ORDER BY commence DESC LIMIT 1");
        if ($commence !== false) {
            return (int)$commence;
        }
        return time();
    }

    private function sortBuildings($kid)
    {
        $db = DB::getInstance();
        $buildings_db = $db->query("SELECT * FROM fdata WHERE kid={$kid}")->fetch_assoc();
        $whole_village_buildings = [];
        $mainBuildingLevel = 0;
        for ($i = 1; $i <= 40; $i++) {
            $whole_village_buildings[$i] = [
                'item_id' => $buildings_db['f' . $i . 't'],
                'level'   => $buildings_db['f' . $i],
            ];
            if ($whole_village_buildings[$i]['item_id'] == 15) {
                $mainBuildingLevel = $whole_village_buildings[$i]['level'];
            }
        }
        $whole_village_buildings[99] = [
            'item_id' => $buildings_db['f99t'],
            'level'   => $buildings_db['f99'],
        ];
        return [
            'buildings'         => $whole_village_buildings,
            'mainBuildingLevel' => $mainBuildingLevel,
        ];
    }

    private function isWorkersBusy($race, $hasPlus, $kid, $isField, $isWW)
    {
        $db = DB::getInstance();
        $workers = ['fieldsNum' => 0, 'buildsNum' => 0];
        $stmt = $db->query("SELECT building_field FROM building_upgrade WHERE isMaster=0 AND kid=$kid");
        while ($row = $stmt->fetch_assoc()) {
            ++$workers[$row['building_field'] <= 18 ? 'fieldsNum' : 'buildsNum'];
        }
        $maxTasks = $hasPlus ? 2 : 1; //its with wonder
        if ($isWW) {
            $maxTasks = 2;
        }
        if ($race == 1) {
            return [
                'fieldsNum'  => $workers['fieldsNum'],
                'buildsNum'  => $workers['buildsNum'],
                'isBusy'     => (($isField) ? ($workers['fieldsNum'] >= $maxTasks) : ($workers['buildsNum'] >= $maxTasks)) || ($workers['fieldsNum'] + $workers['buildsNum']) >= 3,
                'isPlusUsed' => ($hasPlus ? ($isField ? ($workers['fieldsNum'] > 0) : ($workers['buildsNum'] > 0)) : FALSE),
            ];
        }
        return [
            'fieldsNum'  => $workers['fieldsNum'],
            'buildsNum'  => $workers['buildsNum'],
            'isBusy'     => ($workers['buildsNum'] + $workers['fieldsNum']) >= $maxTasks,
            'isPlusUsed' => ($hasPlus ? (($workers['buildsNum'] + $workers['fieldsNum']) > 0) : FALSE),
        ];
    }

    private function isResourcesAvailable($current_resources, $costs)
    {
        $current_resources = [
            $current_resources['wood'],
            $current_resources['clay'],
            $current_resources['iron'],
            $current_resources['crop'],
        ];
        foreach ($costs as $k => $v) {
            if ($v > floor($current_resources[$k])) {
                return false;
            }
        }

        return true;
    }

    public static function queuedTargetLevel(int $builtLevel, int $normalQueueLength, int $masterTasksBefore): int
    {
        return $builtLevel + $normalQueueLength + $masterTasksBefore + 1;
    }

    public static function calculateResourceWait(array $resources, array $production, array $costs): ?int
    {
        $timeNeeded = 0;
        for ($i = 0; $i < 4; ++$i) {
            $available = floor((float)$resources[$i]);
            $required = (float)$costs[$i];
            if ($available >= $required) {
                continue;
            }
            $hourlyProduction = (float)$production[$i];
            if ($hourlyProduction <= 0) {
                return null;
            }
            $timeNeeded = max(
                $timeNeeded,
                (int)ceil(($required - $available) * 3600 / $hourlyProduction)
            );
        }

        return $timeNeeded;
    }

    private static function advanceResources(array &$resources, array $production, array $capacity, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        for ($i = 0; $i < 4; ++$i) {
            $resources[$i] = min(
                (float)$capacity[$i],
                (float)$resources[$i] + ((float)$production[$i] * $seconds / 3600)
            );
        }
    }

    public function updateCommence($kid, $update = true, $simple = false)
    {
        $kid = (int)$kid;
        $now = time();
        $maxTime = $now + 100 * 86400;
        $helper = new BuildingHelper();
        $db = DB::getInstance();
        if ($update) {
            ResourcesHelper::updateVillageResources($kid, $simple);
        }
        $masterBuilders = $db->query("SELECT * FROM building_upgrade WHERE isMaster=1 AND kid={$kid} ORDER BY id");
        if (!$masterBuilders->num_rows) {
            return false;
        }
        $village = $db->query("SELECT capital, upkeep, isWW, pop, owner, kid, wood, woodp, clay, clayp, iron, ironp, crop, cropp, maxstore, maxcrop, lastmupdate FROM vdata WHERE kid=$kid");
        if (!$village->num_rows) {
            return false;
        }
        $village = $village->fetch_assoc();
        $player = $db->query("SELECT aid, race, plus FROM users WHERE id={$village['owner']} LIMIT 1");
        if (!$player->num_rows) {
            return false;
        }
        $player = $player->fetch_assoc();
        $buildings = $this->sortBuildings($kid);
        $normalQueueLengths = [];
        $normalQueue = $db->query("SELECT building_field, COUNT(id) AS queue_length FROM building_upgrade WHERE isMaster=0 AND kid=$kid GROUP BY building_field");
        while ($normalRow = $normalQueue->fetch_assoc()) {
            $normalQueueLengths[(int)$normalRow['building_field']] = (int)$normalRow['queue_length'];
        }
        if (!$village['isWW']) {
            $wwLevel = -1;
        } else {
            $wwLevel = (int)$buildings['buildings'][99]['level'] + ($normalQueueLengths[99] ?? 0);
        }
        $masterQueueLengths = [];
        $projectedCropLoading = $this->getCropLoading($kid, $village['isWW'], $buildings['buildings']);
        $projectedResources = [
            (float)$village['wood'],
            (float)$village['clay'],
            (float)$village['iron'],
            (float)$village['crop'],
        ];
        $production = [
            (float)$village['woodp'],
            (float)$village['clayp'],
            (float)$village['ironp'],
            (float)$village['cropp'] - (float)$village['pop'] - (float)$village['upkeep'],
        ];
        $capacity = [
            (float)$village['maxstore'],
            (float)$village['maxstore'],
            (float)$village['maxstore'],
            (float)$village['maxcrop'],
        ];
        $projectedResourceTime = $now;
        $previousCommence = $now;
        $queryBatch = [];
        while ($row = $masterBuilders->fetch_assoc()) {
            $field = (int)$row['building_field'];
            $item_id = (int)$buildings['buildings'][$field]['item_id'];
            $level = self::queuedTargetLevel(
                (int)$buildings['buildings'][$field]['level'],
                $normalQueueLengths[$field] ?? 0,
                $masterQueueLengths[$field] ?? 0
            );
            $commence = max($now, $previousCommence);
            if ($level > Formulas::buildingMaxLvl($item_id, $village['capital'])) {
                $this->deleteProcess($row['id'], $kid, $field, $level);
                continue;
            }
            if ($field > 18 && $level == 1 && $helper->canCreateNewBuild($village['capital'],
                    $player['race'],
                    $item_id,
                    $buildings['buildings'],
                    true) <> 1) {
                $this->deleteProcess($row['id'], $kid, $field, $level);
                continue;
            }
            if ($helper->checkArtifactDependencies($player['aid'],
                    $village['owner'],
                    $kid,
                    $item_id,
                    $village['isWW'],
                    $wwLevel) <> 0) {
                $this->deleteProcess($row['id'], $kid, $field, $level);
                continue;
            }
            $freeCrop = (float)$village['cropp'] - (float)$village['pop'] - $projectedCropLoading;
            if ($helper->checkDependencies($item_id,
                    $level,
                    $village['isWW'],
                    $freeCrop,
                    $village['maxstore'],
                    $village['maxcrop']) <> 0) {
                $this->deleteProcess($row['id'], $kid, $field, $level);
                continue;
            }
            $workers = $this->isWorkersBusy($player['race'],
                $player['plus'] >= $now,
                $kid,
                $field <= 18,
                $item_id == 40);
            if ($workers['isBusy']) {
                $endTime = (int)$db->fetchScalar("SELECT commence FROM building_upgrade WHERE isMaster=0 AND kid=$kid ORDER BY commence ASC LIMIT 1");
                $commence = max($commence, $endTime);
            }
            self::advanceResources(
                $projectedResources,
                $production,
                $capacity,
                $commence - $projectedResourceTime
            );
            $projectedResourceTime = $commence;

            // World Wonder resources are reserved when the queue entry is created.
            if ($item_id <> 40) {
                $cost = Formulas::buildingUpgradeCosts($item_id, $level);
                $wait = self::calculateResourceWait($projectedResources, $production, $cost);
                if ($wait === null || $commence + $wait > $maxTime) {
                    $commence = $maxTime;
                } else {
                    self::advanceResources($projectedResources, $production, $capacity, $wait);
                    $commence += $wait;
                    for ($i = 0; $i < 4; ++$i) {
                        $projectedResources[$i] -= $cost[$i];
                    }
                }
                $projectedResourceTime = $commence;
            }
            if ($commence != (int)$row['commence']) {
                $queryBatch[] = "UPDATE building_upgrade SET commence={$commence} WHERE id={$row['id']}";
            }
            $previousCommence = $commence;
            $masterQueueLengths[$field] = ($masterQueueLengths[$field] ?? 0) + 1;
            $projectedCropLoading += Formulas::buildingCropConsumption($item_id, $level, $village['isWW']);
            if ($field === 99) {
                ++$wwLevel;
            }
        }
        if (sizeof($queryBatch)) {
            foreach ($queryBatch as $query) {
                $db->query($query);
            }
        }

        return true;
    }

    private function deleteProcess($processId, $wid, $building_field, $level)
    {
        $db = DB::getInstance();
        if ($level == 1 && $building_field > 18 && $building_field < 99) {
            $db->query("UPDATE fdata SET f{$building_field}t=0 WHERE kid=" . $wid);
        }

        return $db->query("DELETE FROM building_upgrade WHERE id=" . $processId);
    }

    private function getCropLoading($kid, $isWW, $buildings)
    {
        $db = DB::getInstance();
        $upgrades = $db->query("SELECT building_field FROM building_upgrade WHERE isMaster=0 AND kid=" . $kid);
        if (!$upgrades->num_rows) {
            return 0;
        }
        $cu = 0;
        $tmp = [];
        while ($row = $upgrades->fetch_assoc()) {
            if (!isset($tmp[$row['building_field']])) {
                $tmp[$row['building_field']] = 0;
            }
            ++$tmp[$row['building_field']];
            $level = $buildings[$row['building_field']]['level'] + $tmp[$row['building_field']];
            $cu += Formulas::buildingCropConsumption($buildings[$row['building_field']]['item_id'], $level, $isWW);
        }

        return $cu;
    }

}
