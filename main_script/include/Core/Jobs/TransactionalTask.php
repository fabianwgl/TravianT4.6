<?php

namespace Core\Jobs;

use Core\Database\DB;

final class TransactionalTask
{
    private const TABLES = [
        'building_upgrade',
        'demolition',
        'research',
        'send',
        'training',
        'alliance_bonus_upgrade_queue',
    ];

    public static function consume(string $table, int $id, callable $effect): bool
    {
        return self::execute($table, $id, $effect, true);
    }

    public static function mutate(string $table, int $id, callable $effect): bool
    {
        return self::execute($table, $id, $effect, false);
    }

    private static function execute(string $table, int $id, callable $effect, bool $consume): bool
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Unsupported transactional task table.');
        }

        $db = DB::getInstance();
        if (!$db->begin_transaction()) {
            throw new \RuntimeException('Unable to begin task transaction.');
        }

        try {
            $result = $db->query("SELECT * FROM `$table` WHERE id=$id FOR UPDATE");
            if (!$result || !$result->num_rows) {
                $db->rollback();

                return false;
            }

            $row = $result->fetch_assoc();
            $effect($row);
            if ($consume) {
                $db->query("DELETE FROM `$table` WHERE id=$id");
                if ($db->affectedRows() !== 1) {
                    throw new \RuntimeException('Task disappeared before it could be consumed.');
                }
            }
            if (!$db->commit()) {
                throw new \RuntimeException('Unable to commit task transaction.');
            }

            return true;
        } catch (\Throwable $e) {
            $db->rollback();

            throw $e;
        }
    }
}
