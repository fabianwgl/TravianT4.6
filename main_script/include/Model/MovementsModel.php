<?php

namespace Model;

use Core\Database\DB;
use Core\Log;

class MovementsModel
{
    const SORTTYPE_GOING = 0;
    const SORTTYPE_RETURN = 1;

    const ATTACKTYPE_SPY = 1;
    const ATTACKTYPE_REINFORCEMENT = 2;
    const ATTACKTYPE_NORMAL = 3;
    const ATTACKTYPE_RAID = 4;
    const ATTACKTYPE_SETTLERS = 5;
    const ATTACKTYPE_EVASION = 6;
    const ATTACKTYPE_ADVENTURE = 7;

    public function addMovement($kid, $to_kid, $race, $units, $ctar1, $ctar2, $spyType, $redeployHero, $mode, $attack_type, $start_time, $end_time, $data = NULL)
    {
        $normalizedUnits = [];
        for ($i = 1; $i <= 11; ++$i) {
            $value = $units[$i] ?? 0;
            $normalized = filter_var($value, FILTER_VALIDATE_INT);
            if ($normalized === false || $normalized < 0) {
                $this->logMovementFailure((int)$kid, (int)$attack_type, (array)$units);

                return 0;
            }
            $normalizedUnits[$i] = $normalized;
        }
        $db = DB::getInstance();
        try {
            $query = $db->run(
                "INSERT INTO movement
                    (kid, to_kid, race, u1, u2, u3, u4, u5, u6, u7, u8, u9, u10, u11,
                     ctar1, ctar2, spyType, redeployHero, mode, attack_type, start_time, end_time, data)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    (int)$kid,
                    (int)$to_kid,
                    (int)$race,
                    $normalizedUnits[1],
                    $normalizedUnits[2],
                    $normalizedUnits[3],
                    $normalizedUnits[4],
                    $normalizedUnits[5],
                    $normalizedUnits[6],
                    $normalizedUnits[7],
                    $normalizedUnits[8],
                    $normalizedUnits[9],
                    $normalizedUnits[10],
                    $normalizedUnits[11],
                    (int)$ctar1,
                    (int)$ctar2,
                    (int)$spyType,
                    $redeployHero ? 1 : 0,
                    (int)$mode,
                    (int)$attack_type,
                    (int)ceil($start_time),
                    (int)ceil($end_time),
                    (string)($data ?? ''),
                ]
            );
        } catch (\mysqli_sql_exception $e) {
            $query = false;
        }
        /*if($attack_type == self::ATTACKTYPE_NORMAL || $attack_type == self::ATTACKTYPE_RAID) {
            if($mode == 0) {
                Travian::Memcache()->delete("attacks".$to_kid);
            }
        }*/
        if (!$query) {
            $this->logMovementFailure((int)$kid, (int)$attack_type, $normalizedUnits);

            return 0;
        }
        $result = $db->lastInsertId();
        if (!$result) {
            $this->logMovementFailure((int)$kid, (int)$attack_type, $normalizedUnits);
        }
        return $result;
    }

    public function addMovementWithSourceMutation(
        callable $sourceMutation,
        $kid,
        $to_kid,
        $race,
        $units,
        $ctar1,
        $ctar2,
        $spyType,
        $redeployHero,
        $mode,
        $attack_type,
        $start_time,
        $end_time,
        $data = null
    ) {
        $db = DB::getInstance();
        if (!$db->begin_transaction()) {
            return 0;
        }

        try {
            if (!$sourceMutation()) {
                $db->rollback();

                return 0;
            }
            $movementId = $this->addMovement(
                $kid,
                $to_kid,
                $race,
                $units,
                $ctar1,
                $ctar2,
                $spyType,
                $redeployHero,
                $mode,
                $attack_type,
                $start_time,
                $end_time,
                $data
            );
            if (!$movementId) {
                $db->rollback();

                return 0;
            }
            if (!$db->commit()) {
                throw new \RuntimeException('Unable to commit movement dispatch.');
            }

            return $movementId;
        } catch (\Throwable $e) {
            $db->rollback();

            throw $e;
        }
    }

    private function logMovementFailure(int $kid, int $attackType, array $units): void
    {
        $db = DB::getInstance();
        $uid = $db->fetchScalar("SELECT owner FROM vdata WHERE kid=$kid");
        if ($uid !== false) {
            Log::addLog(
                $uid,
                'movementFailed' . $attackType,
                sprintf('Troops: %s', implode(',', array_values($units)))
            );
        }
    }

    public function modifyMovement($id, array $modify)
    {
        if (!$id || !sizeof($modify)) {
            return FALSE;
        }
        $db = DB::getInstance();

        return $db->query("UPDATE movement SET " . implode(",", $modify) . " WHERE id=$id");
    }

    public function deleteMovement($id)
    {
        $db = DB::getInstance();

        return $db->query("DELETE FROM movement WHERE id=$id");
    }

    public function deleteEnforce($id)
    {
        $db = DB::getInstance();

        return $db->query("DELETE FROM enforcement WHERE id=$id");
    }

    public function deleteTrapped($id)
    {
        $db = DB::getInstance();

        return $db->query("DELETE FROM trapped WHERE id=$id");
    }

    public function addTrapped($kid, $to_kid, $race, $units)
    {
        $db = DB::getInstance();
        $db->query("INSERT INTO trapped (kid, to_kid, race, u1, u2, u3, u4, u5, u6, u7, u8, u9, u10, u11) VALUES ($kid, $to_kid, $race, {$units[1]}, {$units[2]}, {$units[3]}, {$units[4]}, {$units[5]}, {$units[6]}, {$units[7]}, {$units[8]}, {$units[9]}, {$units[10]}, {$units[11]})");
    }

    public function isSameVillageReinforcementExists($kid, $to_kid)
    {
        $db = DB::getInstance();
        return $db->fetchScalar("SELECT id FROM enforcement WHERE kid=$kid AND to_kid=$to_kid");
    }

    public function isSameVillageTrappedExists($kid, $to_kid)
    {
        $db = DB::getInstance();
        return $db->fetchScalar("SELECT id FROM trapped WHERE kid=$kid AND to_kid=$to_kid");
    }

    public function setMovementMarkState($to_kid, $moveId, $state)
    {
        $db = DB::getInstance();
        $db->query("UPDATE movement SET markState=$state WHERE id=$moveId AND to_kid={$to_kid} AND mode=0 AND attack_type IN(3,4)");

        return $db->affectedRows() >= 1;
    }

    public function addEnforce($uid, $kid, $to_kid, $race, $units)
    {
        $db = DB::getInstance();

        return $db->query("INSERT INTO enforcement (uid, kid, to_kid, race, u1, u2, u3, u4, u5, u6, u7, u8, u9, u10, u11) VALUES ($uid, $kid, $to_kid, $race, {$units[1]}, {$units[2]}, {$units[3]}, {$units[4]}, {$units[5]}, {$units[6]}, {$units[7]}, {$units[8]}, {$units[9]}, {$units[10]}, {$units[11]})");
    }
}
