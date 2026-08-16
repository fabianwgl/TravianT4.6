<?php

namespace Model;

use Core\Database\DB;
use Game\Formulas;
use Game\ResourcesHelper;

class CelebrationModel
{
    public function startCelebration(int $uid, int $kid, int $type): bool
    {
        $db = DB::getInstance();
        if (!$db->begin_transaction()) {
            throw new \RuntimeException('Unable to begin celebration transaction.');
        }

        try {
            if (!$this->startCelebrationInTransaction($uid, $kid, $type)) {
                if (!$db->rollback()) {
                    throw new \RuntimeException('Unable to roll back rejected celebration.');
                }

                return false;
            }
            if (!$db->commit()) {
                throw new \RuntimeException('Unable to commit celebration transaction.');
            }

            return true;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    public function getRewardPreview(int $uid, int $kid): array
    {
        $empty = ['small' => 0, 'large' => 0];
        if ($uid <= 0 || $kid <= 0) {
            return $empty;
        }

        $db = DB::getInstance();
        $result = $db->query(
            "SELECT v.kid AS village_kid, v.isWW, f.*
             FROM vdata v JOIN fdata f ON f.kid=v.kid
             WHERE v.owner=$uid ORDER BY v.kid"
        );
        if (!$result) {
            throw new \RuntimeException("Unable to calculate celebration rewards for player $uid.");
        }

        $villages = [];
        while ($row = $result->fetch_assoc()) {
            $villageKid = (int)$row['village_kid'];
            $villages[$villageKid] = [
                'isWW' => (int)$row['isWW'] === 1,
                'fields' => $row,
            ];
        }
        if (!isset($villages[$kid])) {
            return $empty;
        }

        return $this->calculateRewards($villages, $kid);
    }

    private function startCelebrationInTransaction(int $uid, int $kid, int $type): bool
    {
        if ($uid <= 0 || $kid <= 0 || !in_array($type, [1, 2], true)) {
            return false;
        }

        $db = DB::getInstance();
        $ownerResult = $db->query("SELECT id FROM users WHERE id=$uid FOR UPDATE");
        if (!$ownerResult) {
            throw new \RuntimeException("Unable to lock player $uid before starting a celebration.");
        }
        if (!$ownerResult->num_rows) {
            return false;
        }

        $villageResult = $db->query(
            "SELECT kid, isWW, celebration
             FROM vdata WHERE owner=$uid ORDER BY kid FOR UPDATE"
        );
        if (!$villageResult) {
            throw new \RuntimeException("Unable to lock villages for player $uid before starting a celebration.");
        }
        $villages = [];
        while ($village = $villageResult->fetch_assoc()) {
            $villages[(int)$village['kid']] = [
                'isWW' => (int)$village['isWW'] === 1,
                'celebration' => (int)$village['celebration'],
            ];
        }
        if (!isset($villages[$kid])) {
            return false;
        }

        $kidList = implode(',', array_map('intval', array_keys($villages)));
        $fieldsResult = $db->query(
            "SELECT * FROM fdata WHERE kid IN ($kidList) ORDER BY kid FOR UPDATE"
        );
        if (!$fieldsResult) {
            throw new \RuntimeException("Unable to lock building state for player $uid before starting a celebration.");
        }
        while ($fields = $fieldsResult->fetch_assoc()) {
            $villageKid = (int)$fields['kid'];
            if (isset($villages[$villageKid])) {
                $villages[$villageKid]['fields'] = $fields;
            }
        }
        foreach ($villages as $village) {
            if (!isset($village['fields'])) {
                throw new \RuntimeException("Player $uid has a village without building state.");
            }
        }

        $startedAt = time();
        $townHallLevel = $this->getTownHallLevel($villages[$kid]['fields']);
        if (
            $townHallLevel < 1
            || ($type === 2 && $townHallLevel < 10)
            || $villages[$kid]['celebration'] > $startedAt
        ) {
            return false;
        }

        $rewards = $this->calculateRewards($villages, $kid);
        $reward = $type === 2 ? $rewards['large'] : $rewards['small'];
        if ($reward <= 0) {
            return false;
        }

        ResourcesHelper::settleVillageResourcesForUpdate($kid);
        $cost = array_map('intval', Formulas::celebrationCost($type === 2));
        $endsAt = $startedAt + (int)Formulas::celebrationTime($type === 2, $townHallLevel);
        $updated = $db->query(
            "UPDATE vdata
             SET wood=wood-{$cost[0]}, clay=clay-{$cost[1]}, iron=iron-{$cost[2]}, crop=crop-{$cost[3]},
                 celebration=$endsAt, type=$type
             WHERE kid=$kid AND owner=$uid AND celebration<=$startedAt
               AND wood>={$cost[0]} AND clay>={$cost[1]} AND iron>={$cost[2]} AND crop>={$cost[3]}"
        );
        if (!$updated) {
            throw new \RuntimeException("Unable to update village $kid for a celebration.");
        }
        if ($db->affectedRows() !== 1) {
            return false;
        }

        $credited = $db->query("UPDATE users SET cp=cp+$reward WHERE id=$uid");
        if (!$credited || $db->affectedRows() !== 1) {
            throw new \RuntimeException("Unable to credit celebration Culture Points to player $uid.");
        }

        $questMax = (new DailyQuestModel())->getStepCount(10);
        if (!$db->query(
            "INSERT INTO daily_quest (uid, qst10) VALUES ($uid, 1)
             ON DUPLICATE KEY UPDATE qst10=LEAST(qst10+1, $questMax)"
        )) {
            throw new \RuntimeException("Unable to advance the celebration quest for player $uid.");
        }

        return true;
    }

    private function calculateRewards(array $villages, int $destinationKid): array
    {
        $smallCulturePoints = 0;
        $largeCulturePoints = 0;
        foreach ($villages as $kid => $village) {
            if ($village['isWW']) {
                continue;
            }
            $culturePoints = $this->calculateTheoreticalCulturePoints($village['fields']);
            $largeCulturePoints += $culturePoints;
            if ((int)$kid === $destinationKid) {
                $smallCulturePoints = $culturePoints;
            }
        }

        return [
            'small' => (int)Formulas::getCelebrationMaxCP(false, $smallCulturePoints),
            'large' => (int)Formulas::getCelebrationMaxCP(true, $largeCulturePoints),
        ];
    }

    private function calculateTheoreticalCulturePoints(array $fields): int
    {
        $culturePoints = 0;
        for ($field = 1; $field <= 40; ++$field) {
            $culturePoints += Formulas::buildingCP(
                (int)$fields["f{$field}t"],
                (int)$fields["f{$field}"]
            );
        }

        return $culturePoints;
    }

    private function getTownHallLevel(array $fields): int
    {
        $level = 0;
        for ($field = 19; $field <= 40; ++$field) {
            if ((int)$fields["f{$field}t"] === 24) {
                $level = max($level, (int)$fields["f{$field}"]);
            }
        }

        return $level;
    }
}
