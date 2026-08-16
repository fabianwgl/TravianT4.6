<?php

namespace Model;

use Core\Database\DB;
use Game\Formulas;
use Game\ResourcesHelper;

class BreweryModel
{
    public function startFestival(int $uid, int $kid): bool
    {
        $db = DB::getInstance();
        if (!$db->begin_transaction()) {
            throw new \RuntimeException('Unable to begin Brewery festival transaction.');
        }

        try {
            if (!$this->startFestivalInTransaction($uid, $kid)) {
                if (!$db->rollback()) {
                    throw new \RuntimeException('Unable to roll back rejected Brewery festival.');
                }

                return false;
            }
            if (!$db->commit()) {
                throw new \RuntimeException('Unable to commit Brewery festival transaction.');
            }

            return true;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    public function getFestivalStatus(int $uid, ?int $at = null): array
    {
        $empty = [
            'startedAt' => 0,
            'endsAt' => 0,
            'active' => false,
        ];
        if ($uid <= 0) {
            return $empty;
        }

        $db = DB::getInstance();
        $result = $db->query(
            "SELECT race, brewery_festival_started_at, brewery_festival_ends_at
             FROM users WHERE id=$uid"
        );
        if (!$result) {
            throw new \RuntimeException("Unable to read Brewery festival status for player $uid.");
        }
        if ($result->num_rows !== 1) {
            return $empty;
        }

        $row = $result->fetch_assoc();
        $startedAt = (int)$row['brewery_festival_started_at'];
        $endsAt = (int)$row['brewery_festival_ends_at'];
        $effectiveAt = $at ?? time();

        return [
            'startedAt' => $startedAt,
            'endsAt' => $endsAt,
            'active' => (int)$row['race'] === 2
                && $startedAt > 0
                && $startedAt <= $effectiveAt
                && $endsAt > $effectiveAt,
        ];
    }

    public function getBattleEffects(int $uid, int $at): array
    {
        $empty = [
            'festivalActive' => false,
            'breweryLevel' => 0,
        ];
        if ($uid <= 0 || $at <= 0) {
            return $empty;
        }

        $db = DB::getInstance();
        $result = $db->query(
            "SELECT u.race, u.brewery_festival_started_at, u.brewery_festival_ends_at,
                    v.kid AS capital_kid, v.isWW, f.*
             FROM users u
             LEFT JOIN vdata v ON v.owner=u.id AND v.capital=1
             LEFT JOIN fdata f ON f.kid=v.kid
             WHERE u.id=$uid ORDER BY v.kid"
        );
        if (!$result) {
            throw new \RuntimeException("Unable to read Brewery battle effects for player $uid.");
        }
        if (!$result->num_rows) {
            return $empty;
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $account = $rows[0];
        if ((int)$account['race'] !== 2) {
            return $empty;
        }

        $startedAt = (int)$account['brewery_festival_started_at'];
        $endsAt = (int)$account['brewery_festival_ends_at'];
        $festivalActive = $startedAt > 0 && $startedAt <= $at && $endsAt > $at;
        $breweryLevel = 0;
        if (
            $festivalActive
            &&
            count($rows) === 1
            && $rows[0]['capital_kid'] !== null
            && (int)$rows[0]['isWW'] === 0
            && isset($rows[0]['f19t'])
        ) {
            $breweryLevel = $this->getBreweryLevel($rows[0]);
        }

        return [
            'festivalActive' => $festivalActive,
            'breweryLevel' => $breweryLevel,
        ];
    }

    private function startFestivalInTransaction(int $uid, int $kid): bool
    {
        if ($uid <= 0 || $kid <= 0) {
            return false;
        }

        $db = DB::getInstance();
        $ownerResult = $db->query(
            "SELECT id, race, brewery_festival_ends_at FROM users WHERE id=$uid FOR UPDATE"
        );
        if (!$ownerResult) {
            throw new \RuntimeException("Unable to lock player $uid before starting a Brewery festival.");
        }
        if ($ownerResult->num_rows !== 1) {
            return false;
        }
        $owner = $ownerResult->fetch_assoc();
        $startedAt = time();
        if ((int)$owner['race'] !== 2 || (int)$owner['brewery_festival_ends_at'] > $startedAt) {
            return false;
        }

        $villagesResult = $db->query(
            "SELECT kid, capital, isWW FROM vdata WHERE owner=$uid ORDER BY kid FOR UPDATE"
        );
        if (!$villagesResult) {
            throw new \RuntimeException("Unable to lock villages for player $uid before starting a Brewery festival.");
        }
        $capitalKid = 0;
        $capitalIsWonder = false;
        $capitalCount = 0;
        while ($village = $villagesResult->fetch_assoc()) {
            if ((int)$village['capital'] !== 1) {
                continue;
            }
            ++$capitalCount;
            $capitalKid = (int)$village['kid'];
            $capitalIsWonder = (int)$village['isWW'] === 1;
        }
        if ($capitalCount !== 1 || $capitalKid !== $kid || $capitalIsWonder) {
            return false;
        }

        $fieldsResult = $db->query("SELECT * FROM fdata WHERE kid=$kid FOR UPDATE");
        if (!$fieldsResult) {
            throw new \RuntimeException("Unable to lock Brewery state for village $kid.");
        }
        if ($fieldsResult->num_rows !== 1) {
            throw new \RuntimeException("Capital village $kid has no building state.");
        }
        if ($this->getBreweryLevel($fieldsResult->fetch_assoc()) < 1) {
            return false;
        }

        ResourcesHelper::settleVillageResourcesForUpdate($kid);
        $cost = array_map('intval', Formulas::getFestivalResources());
        $duration = (int)Formulas::getFestivalDuration();
        if (count($cost) !== 4 || min($cost) < 0 || $duration <= 0) {
            throw new \RuntimeException('Invalid Brewery festival economy configuration.');
        }
        $endsAt = $startedAt + $duration;
        $debited = $db->query(
            "UPDATE vdata
             SET wood=wood-{$cost[0]}, clay=clay-{$cost[1]}, iron=iron-{$cost[2]}, crop=crop-{$cost[3]}
             WHERE kid=$kid AND owner=$uid AND capital=1 AND isWW=0
               AND wood>={$cost[0]} AND clay>={$cost[1]} AND iron>={$cost[2]} AND crop>={$cost[3]}"
        );
        if (!$debited) {
            throw new \RuntimeException("Unable to debit Brewery festival resources from village $kid.");
        }
        if ($db->affectedRows() !== 1) {
            return false;
        }

        $started = $db->query(
            "UPDATE users
             SET brewery_festival_started_at=$startedAt, brewery_festival_ends_at=$endsAt
             WHERE id=$uid AND race=2 AND brewery_festival_ends_at<=$startedAt"
        );
        if (!$started || $db->affectedRows() !== 1) {
            throw new \RuntimeException("Unable to record Brewery festival for player $uid.");
        }

        return true;
    }

    private function getBreweryLevel(array $fields): int
    {
        $level = 0;
        for ($field = 19; $field <= 40; ++$field) {
            if ((int)$fields["f{$field}t"] === 35) {
                $level = max($level, (int)$fields["f{$field}"]);
            }
        }

        return $level;
    }
}
