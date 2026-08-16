<?php

namespace Model;

use Core\Config;
use Core\Database\DB;
use Core\Jobs\TransactionalTask;
use Game\GoldHelper;

class AutoExtendModel
{
    public function processAutoExtend()
    {
        $db = DB::getInstance();
        $timeMinimum = ceil(Config::getInstance()->gold->plusAccountDurationSeconds / 7);
        $now = time();
        $time = $now - 3600;
        $result = $db->query("SELECT id FROM autoExtend WHERE finished=0 AND lastChecked < $time AND commence <= " . ($now + $timeMinimum) . " AND IF(enabled=0, commence < $now, true) ORDER BY lastChecked ASC LIMIT 100");
        while ($row = $result->fetch_assoc()) {
            $this->processAutoExtendTask((int)$row['id'], $now);
        }
    }

    public function processAutoExtendTask(int $id, ?int $now = null): bool
    {
        $now ??= time();
        $config = Config::getInstance();
        $timeMinimum = (int)ceil($config->gold->plusAccountDurationSeconds / 7);
        $processed = false;

        TransactionalTask::mutate('autoExtend', $id, function (array $row) use (
            $config,
            $id,
            $now,
            $timeMinimum,
            &$processed
        ): void {
            if (
                (int)$row['finished'] !== 0
                || (int)$row['lastChecked'] >= $now - 3600
                || (int)$row['commence'] > $now + $timeMinimum
                || ((int)$row['enabled'] === 0 && (int)$row['commence'] >= $now)
            ) {
                return;
            }

            $processed = true;
            $db = DB::getInstance();
            $uid = (int)$row['uid'];
            $type = (int)$row['type'];
            $commence = (int)$row['commence'];
            if ($commence < $now) {
                $db->query("DELETE FROM autoExtend WHERE id=$id");
                if ($db->affectedRows() !== 1) {
                    throw new \RuntimeException('Auto-extension task disappeared before expiry cleanup.');
                }
                if ($type > 1) {
                    (new VillageModel())->updateUserVillageResources($uid, true);
                }

                return;
            }

            $db->query("UPDATE autoExtend SET lastChecked=$now WHERE id=$id");
            if ($type === 1) {
                $cost = (int)$config->gold->plusGold;
                $duration = (int)$config->gold->plusAccountDurationSeconds;
                $column = 'plus';
            } elseif ($type >= 2 && $type <= 5) {
                $cost = (int)$config->gold->productionBoostGold;
                $duration = (int)$config->gold->productionBoostDurationSeconds;
                $column = 'b' . ($type - 1);
            } else {
                return;
            }

            $infoBox = new InfoBoxModel();
            if (!GoldHelper::decreaseGold($uid, $cost)) {
                if (!$infoBox->hasInfoByType($uid, $type)) {
                    $infoBox->addInfo($uid, 0, $type, '', $commence - 86400, $commence + 86400);
                }

                return;
            }

            $showTo = $commence + $duration;
            $db->query("UPDATE users SET $column=$showTo WHERE id=$uid");
            if ($db->affectedRows() !== 1) {
                throw new \RuntimeException('Auto-extension owner disappeared before the benefit was applied.');
            }
            $infoBox->deleteInfoByType($uid, $type);
            $db->query(
                "UPDATE autoExtend
                 SET lastChecked=0, finished=0, commence=$showTo, enabled=1
                 WHERE id=$id"
            );
            if ($db->affectedRows() !== 1) {
                throw new \RuntimeException('Auto-extension task disappeared before it could be rescheduled.');
            }
        });

        return $processed;
    }

    private function set($uid, $type, $commence, $enabled)
    {
        $enabled = $enabled ? 1 : 0;
        $db = DB::getInstance();
        $id = $db->fetchScalar("SELECT id FROM autoExtend WHERE type=$type AND uid=$uid");
        $finished = $commence < time() ? 1 : 0;
        if ($id) {
            $db->query("UPDATE autoExtend SET lastChecked=0, finished=$finished, commence=$commence, enabled=$enabled WHERE id=$id");
        } else {
            $db->query("INSERT INTO autoExtend (uid, type, commence, enabled, finished) VALUES ($uid, $type, $commence, $enabled, $finished)");
        }
    }

    public function setAutoExtendState($uid, $type, $result, $endTime)
    {
        $uid = (int)$uid;
        $type = (int)$type;
        $m = new InfoBoxModel();
        $m->deleteInfoByType($uid, $type);
        if (!$result) {
            $m->addInfo($uid, 0, $type, '', $endTime - 86400, $endTime + 86400);
        }
        $this->set($uid, $type, $endTime, $result);
    }

    public function hasAutoExtend($uid, $type)
    {
        $db = DB::getInstance();
        $uid = (int)$uid;
        $type = (int)$type;
        return (int)$db->fetchScalar("SELECT COUNT(id) FROM autoExtend WHERE uid=$uid AND type=$type AND enabled=1") >= 1;
    }
}
