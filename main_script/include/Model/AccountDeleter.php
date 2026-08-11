<?php

namespace Model;

use function array_sum;
use Core\Config;
use Core\Database\DB;
use Game\Buildings\BuildingAction;
use Game\Formulas;
use Game\Hero\HeroHelper;
use Game\Map\Map;
use Game\ResourcesHelper;
use Game\SpeedCalculator;
use function logError;
use function miliseconds;

class AccountDeleter
{
    public function isVillageDestroyAble($attacker_uid, $kid, $uid)
    {
        if ($attacker_uid > 1 && !getCustom("destroyVillageOnZeroPop")) {
            return 'disabled';
        }
        // some exception for speed servers
        /**
         * There are some exceptions that will prevent a village with zero population from being completely destroyed and removed from the map.
         * They are as follows:
         * When it is the last village of an account
         * When there is an unconquered artifact remaining in the village
         * When it is a World Wonder village
         * When the account only has one other village, and that village is a World Wonder village (an account cannot have a World Wonder as its only village)
         */
        $db = DB::getInstance();
        $isCapital = false;
        $find = $db->query("SELECT isWW, capital, isFarm FROM vdata WHERE kid={$kid}");
        if ($find->num_rows) {
            $find = $find->fetch_assoc();
            $isCapital = !empty($find['capital']);
            if ($find['isWW'] || $find['isFarm']) {
                return $find['isWW'] ? 'isWW' : 'isFarm';
            }
            if ($find['capital'] && !Config::getProperty("game", "changeCapitalOnZeroPop")) {
                return 'disabledCapitalOnZeroPop';
            }
        }
        $count = $db->fetchScalar("SELECT COUNT(kid) FROM vdata WHERE owner=" . (int)$uid);
        if ($count == 1) {
            return 'OnlyOneVillage';
        }
        //check if all other villages are ww villages
        $wwCount = $db->fetchScalar("SELECT COUNT(kid) FROM vdata WHERE owner=" . (int)$uid . " AND isWW=1");
        if ($wwCount == ($count - 1)) {
            return 'OnePlusWW';
        }
        //maybe this village has artifact..
        $find = $db->fetchScalar("SELECT COUNT(id) FROM artefacts WHERE uid=$uid AND kid=$kid") > 0;
        if ($find) {
            return 'ArtifactExists';
        }
        if (
            $isCapital
            && (int)$db->fetchScalar(
                "SELECT COUNT(kid) FROM vdata
                 WHERE owner=" . (int)$uid . " AND kid<>" . (int)$kid . " AND isWW=0 AND isFarm=0 AND isArtifact=0"
            ) === 0
        ) {
            return 'NoCapitalSuccessor';
        }
        return true;
    }

    /**
     * @param      $kid
     * @param bool $full => is account deletion or not!
     */
    public function deleteVillage($kid, $full = false, $reSpawn = true)
    {
        $db = DB::getInstance();
        if (!$db->begin_transaction()) {
            throw new \RuntimeException("Unable to begin village $kid deletion transaction.");
        }

        try {
            $deleted = $this->deleteVillageInTransaction((int)$kid, (bool)$full, (bool)$reSpawn);
            if (!$db->commit()) {
                throw new \RuntimeException("Unable to commit village $kid deletion transaction.");
            }

            return $deleted;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    private function deleteVillageInTransaction(int $kid, bool $full, bool $reSpawn)
    {
        $profile = [];
        $addProfile = function ($name) use (&$profile) {
            $profile[$name] = microtime(true);
        };
        $endProfile = function ($name) use (&$profile) {
            $profile[$name] = microtime(true) - $profile[$name];
        };
        $db = DB::getInstance();
        $vdata = $db->query("SELECT owner FROM vdata WHERE kid=$kid");
        if (!$vdata->num_rows) {
            $fieldType = $db->fetchScalar("SELECT fieldtype FROM wdata WHERE id={$kid}");
            if ($fieldType > 0) {
                $db->query("UPDATE wdata SET occupied=0 WHERE id=$kid");
                $db->query("UPDATE available_villages SET occupied=0 WHERE kid=$kid");
            }
            return false;
        }
        $owner = (int)$vdata->fetch_assoc()['owner'];
        $ownerResult = $db->query("SELECT id FROM users WHERE id=$owner FOR UPDATE");
        if (!$ownerResult) {
            throw new \RuntimeException("Unable to lock player $owner before deleting village $kid.");
        }
        $vdata = $db->query(
            "SELECT owner, capital, isWW, expandedfrom, pop, fieldtype
             FROM vdata WHERE kid=$kid AND owner=$owner FOR UPDATE"
        );
        if (!$vdata) {
            throw new \RuntimeException("Unable to lock village $kid before deletion.");
        }
        if (!$vdata->num_rows) {
            return false;
        }
        $vdata = $vdata->fetch_assoc();
        $capital = (int)$vdata['capital'];
        $capitalKid = 0;
        if (!$full) {
            if ($capital) {
                $capitalKid = $this->findCapitalSuccessor($owner, (int)$kid);
                if (!$capitalKid) {
                    return false;
                }
            }
        }
        if($full && $vdata['capital'] == 1){
            (new VillageModel())->captureVillage($vdata['owner'], $kid, 100, 1, 0, 5, Formulas::xy2kid(0,0));
            $buildings = (new VillageModel())->getBuildingsAssoc($kid);
            for ($i = 1; $i <= 18; ++$i) {
                if ($buildings[$i]['level'] > 10) {
                    BuildingAction::downgrade($kid, $i, $buildings[$i]['level'] - 10);
                }
            }
            for ($i = 19; $i <= 40; ++$i) {
                if (in_array($buildings[$i]['item_id'], [34, 35])) {
                    BuildingAction::downgrade($kid, $i, 0, true);
                }
            }
            ResourcesHelper::updateVillageResources($kid, FALSE);
            $db->query("UPDATE vdata SET capital=0 WHERE kid=$kid");
            return true;
        }
        $db->query("DELETE FROM vdata WHERE kid=$kid");
        $db->query("DELETE FROM fdata WHERE kid=$kid");
        $db->query("DELETE FROM research WHERE kid=$kid");
        $db->query("DELETE FROM tdata WHERE kid=$kid");
        $db->query("DELETE FROM smithy WHERE kid=$kid");
        $db->query("DELETE FROM training WHERE kid=$kid");
        $db->query("DELETE FROM odelete WHERE kid=$kid");
        $db->query("DELETE FROM traderoutes WHERE (kid=$kid OR to_kid=$kid)");
        $db->query("DELETE FROM send WHERE kid=$kid OR (to_kid=$kid AND mode=1)");
        $db->query("DELETE FROM market WHERE kid=$kid");
        $db->query("DELETE FROM building_upgrade WHERE kid=$kid");
        $db->query("DELETE FROM demolition WHERE kid=$kid");
        $find = $db->query("SELECT * FROM send WHERE to_kid=$kid AND mode=0");
        while ($row = $find->fetch_assoc()) {
            $db->query("UPDATE send SET mode=1, kid={$row['to_kid']}, to_kid={$row['kid']} WHERE id={$row['id']}");
            //return merchants.
        }
        if ($full) {
            $db->query("UPDATE users SET kid=0 WHERE id=$owner");
        }
        $addProfile('deleteVillage:deleteFarmlist');
        $farmLists = $db->query("SELECT id FROM farmlist WHERE kid=$kid");
        while ($list = $farmLists->fetch_assoc()) {
            $db->query("DELETE FROM raidlist WHERE lid={$list['id']}");
        }
        $db->query("DELETE FROM farmlist WHERE kid=$kid");
        $db->query("DELETE FROM raidlist WHERE kid=$kid");
        $endProfile('deleteVillage:deleteFarmlist');
        $addProfile('deleteVillage:cancelMovements');
        $db->query("DELETE FROM movement WHERE (kid=$kid AND mode=0) OR (mode=1 AND to_kid=$kid)");
        //return all movements.
        $find = $db->query("SELECT * FROM movement WHERE to_kid=$kid AND mode=0");
        while ($row = $find->fetch_assoc()) {
            self::cancelMovement($row['id'], $row['to_kid'], $row['kid']);
        }
        $endProfile('deleteVillage:cancelMovements');
        $addProfile('deleteVillage:freeOases');
        $oases = $db->query("SELECT kid FROM odata WHERE did=$kid");
        $oasesIds = [];
        while ($row = $oases->fetch_assoc()) {
            $oasesIds[] = $row['kid'];
            $db->query("UPDATE wdata SET occupied=0 WHERE id={$row['kid']}");
            Map::OccupyOrLeaveOasisCacheRemove($row['kid']);
        }
        $endProfile('deleteVillage:freeOases');
        $db->query("UPDATE odata SET owner=0, did=0 WHERE did=$kid");
        $db->query("DELETE FROM units WHERE kid=$kid");
        $db->query("DELETE FROM enforcement WHERE kid=$kid");
        $db->query("DELETE FROM trapped WHERE kid=$kid");
        $find = $oasesIds;
        $find[] = $kid;
        $addProfile('deleteVillage:returnEnforcement');
        $enforces = $db->query("SELECT * FROM enforcement WHERE to_kid IN(" . implode(",", $find) . ")");
        while ($enforce = $enforces->fetch_assoc()) {
            self::returnTrappedOrEnforcementRow($enforce, true);
        }
        $endProfile('deleteVillage:returnEnforcement');
        $addProfile('deleteVillage:returnTraps');
        $traps = $db->query("SELECT * FROM trapped WHERE to_kid=$kid");
        while ($trapped = $traps->fetch_assoc()) {
            self::returnTrappedOrEnforcementRow($trapped, false);
        }
        $endProfile('deleteVillage:returnTraps');
        if (!$full) {
            $m = new VillageModel();
            if ($capital) {
                $addProfile('deleteVillage:removeUnavailableCapitalBuildings');
                $db->query("UPDATE vdata SET capital=0 WHERE owner=$owner");
                $db->query("UPDATE vdata SET capital=1 WHERE kid=$capitalKid AND owner=$owner");
                if ($db->affectedRows() !== 1) {
                    throw new \RuntimeException("Unable to promote village $capitalKid as the capital of player $owner.");
                }
                $m->removeUnavailableCapitalBuildings($owner, $capitalKid);
                $endProfile('deleteVillage:removeUnavailableCapitalBuildings');
            } else {
                $capitalKid = (int)$db->fetchScalar(
                    "SELECT kid FROM vdata WHERE capital=1 AND owner=$owner ORDER BY kid LIMIT 1 FOR UPDATE"
                );
            }
            $db->query("UPDATE users SET profileCacheVersion=profileCacheVersion+1, total_pop=total_pop-{$vdata['pop']}, total_villages=total_villages-1 WHERE id={$owner}");
            if ($capitalKid > 0) {
                $db->query("UPDATE users SET kid=$capitalKid WHERE id=$owner AND kid=" . (int)$kid);
            }
            $hero = $db->query("SELECT uid, kid FROM hero WHERE kid=$kid");
            if ($hero->num_rows && $capitalKid > 0) {
                $hero = $hero->fetch_assoc();
                $db->query("UPDATE hero SET kid=$capitalKid, health=0 WHERE uid=" . (int)$hero['uid']);
            }
        }
        $this->rematchExpands($kid);
        $db->query("UPDATE wdata SET occupied=0 WHERE id=$kid");
        $db->query("UPDATE available_villages SET occupied=0 WHERE kid=$kid");
        $addProfile('deleteVillage:villageDestroyOrCaptureOrNewVillageUpdate');
        Map::villageDestroyOrCaptureOrNewVillageUpdate($kid);
        $endProfile('deleteVillage:villageDestroyOrCaptureOrNewVillageUpdate');
        if($full){
            if($db->fetchScalar("SELECT COUNT(id) FROM artefacts WHERE kid=$kid") > 0) {
                $db->query("UPDATE artefacts SET kid=0 WHERE kid=$kid");
                $addProfile('deleteVillage:reSpawnMissingArtifacts');
                $artModel = new ArtefactsModel();
                $artModel->reSpawnMissingArtifacts();
                $endProfile('deleteVillage:reSpawnMissingArtifacts');
            }
        }
        if (array_sum($profile) > 0.500) {
            logError(print_r($profile, true));
        }
        return true;
    }

    private function findCapitalSuccessor(int $owner, int $excludedKid): int
    {
        $db = DB::getInstance();
        $candidates = $db->query(
            "SELECT kid FROM vdata
             WHERE owner=$owner AND kid<>$excludedKid AND isWW=0 AND isFarm=0 AND isArtifact=0
             ORDER BY pop DESC, kid ASC FOR UPDATE"
        );
        $fallbackKid = 0;
        while ($candidate = $candidates->fetch_assoc()) {
            $candidateKid = (int)$candidate['kid'];
            if (!$fallbackKid) {
                $fallbackKid = $candidateKid;
            }
            $fields = $db->query("SELECT * FROM fdata WHERE kid=$candidateKid FOR UPDATE");
            if (!$fields->num_rows) {
                continue;
            }
            $fields = $fields->fetch_assoc();
            for ($field = 19; $field <= 40; ++$field) {
                if ((int)$fields["f{$field}"] > 0 && in_array((int)$fields["f{$field}t"], [25, 26, 44], true)) {
                    return $candidateKid;
                }
            }
        }
        return $fallbackKid;
    }

    public function rematchExpands($kid)
    {
        $db = DB::getInstance();
        $db->query("UPDATE vdata SET expandedfrom=0 WHERE expandedfrom={$kid}");
    }

    public function cancelMovement($id, $kid, $to_kid)
    {
        $miliseconds = miliseconds();
        $db = DB::getInstance();
        $db->query("UPDATE movement SET kid={$kid}, to_kid={$to_kid}, mode=1, end_time=(" . (2 * $miliseconds) . "-start_time), start_time=" . $miliseconds . " WHERE id={$id}");
    }

    public function returnTrappedOrEnforcementRow($enforce, $isEnforce, $time = -1)
    {
        $db = DB::getInstance();
        if ($isEnforce) {
            $count = $db->fetchScalar("SELECT COUNT(id) FROM enforcement WHERE id={$enforce['id']}");
        } else {
            $count = $db->fetchScalar("SELECT COUNT(id) FROM trapped WHERE id={$enforce['id']}");
        }
        if ($count <= 0) {
            return false;
        }
        if ($time == -1) {
            $time = miliseconds();
        }
        $helper = new HeroHelper();
        $calculator = new SpeedCalculator();
        $calculator->setFrom($enforce['to_kid']);
        $calculator->setTo($enforce['kid']);
        $calculator->isReturn();
        if ($enforce['u8']) {
            $calculator->hasCata();
        }
        $units = [];
        $speeds = [];
        $units_id = [];
        for ($i = 1; $i <= 11; ++$i) {
            $units[$i] = (int)$enforce['u' . $i];
            if (!$enforce['u' . $i] || $i == 11) {
                continue;
            }
            $speeds[] = Formulas::uSpeed(nrToUnitId($i, $enforce['race']));
            $units_id[] = (nrToUnitId($i, $enforce['race']));
        }
        $db = DB::getInstance();
        if ($enforce['u11']) {
            if (array_sum($units) > 1) {
                $calculator->troopsWithHero();
            }
            //has hero.
            if (!$isEnforce) {
                $enforce['uid'] = 0;
                $uid = $db->fetchScalar("SELECT owner FROM vdata WHERE kid={$enforce['kid']}");
                if ($uid) {
                    $enforce['uid'] = $uid;
                }
            }
            $inventory = $db->query("SELECT * FROM inventory WHERE uid={$enforce['uid']}");
            if ($inventory->num_rows) {
                $inventory = $inventory->fetch_assoc();
                $calculator->setLeftHand($inventory['leftHand']);
                $calculator->setShoes($inventory['shoes']);
                $calculator->hasHero();
                $to_kid_owner = (int)$db->fetchScalar("SELECT owner FROM vdata WHERE kid={$enforce['to_kid']}");
                if ($to_kid_owner == $enforce['uid']) {
                    $calculator->isOwn();
                } else {
                    $aid = (int)$db->fetchScalar("SELECT aid FROM users WHERE id={$enforce['uid']}");
                    if ($aid > 0) {
                        $to_aid = (int)$db->fetchScalar("SELECT aid FROM users WHERE id={$to_kid_owner}");
                        if ($aid == $to_aid) {
                            $calculator->isAlliance();
                        } else {
                            $diplomacy = $db->fetchScalar("SELECT COUNT(id) FROM diplomacy WHERE accepted=1 AND type=1 AND ((aid1=$aid AND aid2=$to_aid) OR (aid1=$to_aid AND aid2=$aid))");
                            if ($diplomacy > 0) {
                                $calculator->isAlliance();
                            }
                        }
                    }
                }
            }
            $speeds[] = $helper->calcTotalSpeed($enforce['race'],
                $inventory['horse'],
                $inventory['shoes'],
                $calculator->isCavalryOnly($units_id));
        }
        $calculator->setMinSpeed($speeds);
        $move = new MovementsModel();
        if ($isEnforce) {
            $stmtSuccess = $db->query("DELETE FROM enforcement WHERE id={$enforce['id']}");
        } else {
            $stmtSuccess = $db->query("DELETE FROM trapped WHERE id={$enforce['id']}");
        }
        if ($stmtSuccess && $db->affectedRows()) {
            $move->addMovement($enforce['to_kid'],
                $enforce['kid'],
                $enforce['race'],
                $units,
                0,
                0,
                0,
                0,
                1,
                MovementsModel::ATTACKTYPE_REINFORCEMENT,
                $time,
                $time + 1000 * $calculator->calc(),
                null);
            ResourcesHelper::updateVillageResources($enforce['kid'], false);
            if ($isEnforce) {
                ResourcesHelper::updateVillageResources($enforce['to_kid'], false);
            }
            return true;
        }
        return false;
    }
}
