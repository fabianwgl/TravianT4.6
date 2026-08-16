<?php

namespace Model;

use Core\Database\DB;
use Core\Jobs\TransactionalTask;
use Game\Formulas;
use Game\NoticeHelper;
use Game\ResourcesHelper;

class MarketPlaceProcessor
{
    public function processRow($row)
    {
        return TransactionalTask::consume('send', (int)$row['id'], function (array $lockedRow): void {
            if ((int)$lockedRow['mode'] === 1) {
                $this->processReturn($lockedRow);

                return;
            }
            $this->processGo($lockedRow);
        });
    }

    private function processGo($row)
    {
        $taskId = (int)$row['id'];
        $sourceKid = (int)$row['kid'];
        $destinationKid = (int)$row['to_kid'];
        $villages = $this->lockVillages([$sourceKid, $destinationKid], $taskId);
        if (!isset($villages[$sourceKid])) {
            throw new \RuntimeException("Merchant task $taskId source village $sourceKid does not exist.");
        }
        if (!isset($villages[$destinationKid])) {
            throw new \RuntimeException("Merchant task $taskId destination village $destinationKid does not exist.");
        }

        $m = new AutomationModel();
        $sender = $m->getUser($villages[$sourceKid]['owner'], 'id, reportFilters, name, race');
        if ($sender === false) {
            $owner = (int)$villages[$sourceKid]['owner'];
            throw new \RuntimeException("Merchant task $taskId source owner $owner does not exist.");
        }
        $receiver = $m->getUser($villages[$destinationKid]['owner'], 'id, reportFilters, name, race');
        if ($receiver === false) {
            $owner = (int)$villages[$destinationKid]['owner'];
            throw new \RuntimeException("Merchant task $taskId destination owner $owner does not exist.");
        }
        $resources = [
            1 => (int)$row['wood'],
            (int)$row['clay'],
            (int)$row['iron'],
            (int)$row['crop'],
        ];
        $report = [
            'sender' => [
                'uid' => $sender['id'],
                'uname' => $sender['name'],
                'kid' => $row['kid'],
            ],
            'receiver' => [
                'uid' => $receiver['id'],
                'uname' => $receiver['name'],
                'kid' => $row['kid'],
            ],
            'resources' => $resources,
            'timeTaken' => round(Formulas::getDistance($row['kid'], $row['to_kid']) / Formulas::merchantSpeed($sender['race']) * 3600),
        ];
        $reportType = [
            1 => NoticeHelper::TYPE_RESOURCES_MOST_WOOD,
            NoticeHelper::TYPE_RESOURCES_MOST_CLAY,
            NoticeHelper::TYPE_RESOURCES_MOST_IRON,
            NoticeHelper::TYPE_RESOURCES_MOST_CROP,
        ][array_search(max($resources), $resources)];
        $sender_reportFilters = explode(",", $sender['reportFilters']);
        if ($sender['id'] == $receiver['id']) {
            if ($sender_reportFilters[0] == 0) {
                NoticeHelper::addNotice(0, $sender['id'], $row['kid'], $row['to_kid'], $reportType, '', $report, $row['end_time']);
            }
        } else if ($sender_reportFilters[1] == 0) {
            NoticeHelper::addNotice(0, $sender['id'], $row['kid'], $row['to_kid'], $reportType, '', $report, $row['end_time']);
        }
        if ($sender['id'] != $receiver['id']) {
            MultiAccount::addMultiAccountLog($sender['id'], $receiver['id'], 1);
            $receiver_reportFilters = explode(",", $receiver['reportFilters']);
            if ($receiver_reportFilters[2] == 0) {
                NoticeHelper::addNotice(0, $receiver['id'], $row['kid'], $row['to_kid'], $reportType, '', $report, $row['end_time']);
            }
        }
        $db = DB::getInstance();
        ResourcesHelper::settleVillageResourcesForUpdate($destinationKid);
        $credited = $db->query(
            "UPDATE vdata
             SET wood=LEAST(maxstore, wood+{$resources[1]}),
                 clay=LEAST(maxstore, clay+{$resources[2]}),
                 iron=LEAST(maxstore, iron+{$resources[3]}),
                 crop=LEAST(maxcrop, crop+{$resources[4]})
             WHERE kid=$destinationKid"
        );
        if (!$credited) {
            throw new \RuntimeException("Merchant task $taskId could not credit destination village $destinationKid.");
        }
        if (!$this->returnMerchants($row, $report['timeTaken'])) {
            throw new \RuntimeException("Merchant task $taskId could not queue its return leg.");
        }
        $master = new MasterBuilder();
        $master->updateCommence($destinationKid, false);
    }

    private function returnMerchants($row, $timeTaken): bool
    {
        return $this->insert(
            $row['to_kid'],
            $row['kid'],
            $row['wood'],
            $row['clay'],
            $row['iron'],
            $row['crop'],
            $row['x'] - 1,
            1,
            $row['end_time'] + $timeTaken
        );
    }

    protected function insert($kid, $to_kid, $r1, $r2, $r3, $r4, $x2, $mode, $end_time): bool
    {
        $db = DB::getInstance();

        return (bool)$db->query(
            "INSERT INTO send (`kid`, `to_kid`, `wood`, `clay`, `iron`, `crop`, `x`, `mode`, `end_time`)
             VALUES ($kid, $to_kid, $r1, $r2, $r3, $r4, $x2, $mode, $end_time)"
        );
    }

    private function processReturn($row)
    {
        if (!$row['x']) {
            return;
        }
        $taskId = (int)$row['id'];
        $sourceKid = (int)$row['to_kid'];
        $destinationKid = (int)$row['kid'];
        $villages = $this->lockVillages([$sourceKid, $destinationKid], $taskId);
        if (!isset($villages[$sourceKid])) {
            throw new \RuntimeException("Merchant return task $taskId source village $sourceKid does not exist.");
        }
        if (!isset($villages[$destinationKid])) {
            throw new \RuntimeException("Merchant return task $taskId destination village $destinationKid does not exist.");
        }

        $m = new AutomationModel();
        ResourcesHelper::settleVillageResourcesForUpdate($sourceKid);
        $res = $m->getVillage($sourceKid, 'owner, wood, clay, iron, crop');
        if ($res === false) {
            throw new \RuntimeException("Merchant return task $taskId source village $sourceKid disappeared.");
        }
        $resources = array_map(function ($x) {
            return floor(max(0, $x));
        }, [
            1 => min($res['wood'], $row['wood']),
            min($res['clay'], $row['clay']),
            min($res['iron'], $row['iron']),
            max(min($res['crop'], $row['crop']), 0),
        ]);
        $owner = $m->getUser($res['owner'], 'race');
        if ($owner === false) {
            $ownerId = (int)$res['owner'];
            throw new \RuntimeException("Merchant return task $taskId source owner $ownerId does not exist.");
        }
        $speed = Formulas::merchantSpeed($owner['race']);
        $end_time = $row['end_time'] + round(Formulas::getDistance($row['kid'], $row['to_kid']) / $speed * 3600);
        $db = DB::getInstance();
        $debited = $db->query(
            "UPDATE vdata
             SET wood=wood-{$resources[1]}, clay=clay-{$resources[2]},
                 iron=iron-{$resources[3]}, crop=crop-{$resources[4]}
             WHERE kid=$sourceKid"
        );
        if (!$debited || (array_sum($resources) > 0 && $db->affectedRows() !== 1)) {
            throw new \RuntimeException("Merchant return task $taskId could not debit source village $sourceKid.");
        }
        if (!$this->insert(
            $sourceKid,
            $destinationKid,
            $resources[1],
            $resources[2],
            $resources[3],
            $resources[4],
            $row['x'],
            0,
            $end_time
        )) {
            throw new \RuntimeException("Merchant return task $taskId could not queue its next outbound leg.");
        }
        $master = new MasterBuilder();
        $master->updateCommence($sourceKid, false, false);
    }

    private function lockVillages(array $kids, int $taskId): array
    {
        $kids = array_values(array_unique(array_map('intval', $kids)));
        sort($kids, SORT_NUMERIC);
        $result = DB::getInstance()->query(
            'SELECT kid, owner FROM vdata WHERE kid IN (' . implode(',', $kids) . ') ORDER BY kid FOR UPDATE'
        );
        if (!$result) {
            throw new \RuntimeException("Merchant task $taskId could not lock its villages.");
        }

        $villages = [];
        while ($village = $result->fetch_assoc()) {
            $villages[(int)$village['kid']] = $village;
        }

        return $villages;
    }
}
