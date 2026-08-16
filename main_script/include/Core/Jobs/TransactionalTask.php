<?php

namespace Core\Jobs;

use Core\Database\DB;

final class TransactionalTask
{
    private const MAX_ATTEMPTS = 5;
    private static $effectSavepointSequence = 0;
    private const TABLES = [
        'building_upgrade',
        'buyGoldMessages',
        'banQueue',
        'demolition',
        'movement',
        'research',
        'send',
        'training',
        'alliance_bonus_upgrade_queue',
        'voting_reward_queue',
        'player_references',
        'odelete',
        'traderoutes',
        'notificationQueue',
        'autoExtend',
    ];

    public static function consume(string $table, int $id, callable $effect, ?callable $beforeTaskLock = null): bool
    {
        return self::execute($table, $id, $effect, true, $beforeTaskLock);
    }

    public static function mutate(string $table, int $id, callable $effect, ?callable $beforeTaskLock = null): bool
    {
        return self::execute($table, $id, $effect, false, $beforeTaskLock);
    }

    private static function execute(
        string $table,
        int $id,
        callable $effect,
        bool $consume,
        ?callable $beforeTaskLock
    ): bool
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Unsupported transactional task table.');
        }

        $db = DB::getInstance();
        if (!$db->begin_transaction()) {
            throw new \RuntimeException('Unable to begin task transaction.');
        }

        try {
            if ($beforeTaskLock !== null) {
                $beforeTaskLock();
            }
            $result = $db->query("SELECT * FROM `$table` WHERE id=$id FOR UPDATE");
            if (!$result || !$result->num_rows) {
                $db->rollback();

                return false;
            }

            $row = $result->fetch_assoc();
            $effectSavepoint = 'openvillage_task_effect_' . (++self::$effectSavepointSequence);
            if (!$db->query("SAVEPOINT $effectSavepoint")) {
                throw new \RuntimeException('Unable to create task effect savepoint.');
            }
            try {
                $effect($row);
                if (!$db->query("RELEASE SAVEPOINT $effectSavepoint")) {
                    throw new \RuntimeException('Unable to release task effect savepoint.');
                }
            } catch (QuarantineTaskException $e) {
                if (!$db->query("ROLLBACK TO SAVEPOINT $effectSavepoint")) {
                    throw new \RuntimeException('Unable to roll back quarantined task effects.', 0, $e);
                }
                if (!$db->query("RELEASE SAVEPOINT $effectSavepoint")) {
                    throw new \RuntimeException('Unable to release quarantined task effect savepoint.', 0, $e);
                }
                self::quarantine($table, $id, $row, $e);
                if (!$db->commit()) {
                    throw new \RuntimeException('Unable to commit task quarantine.', 0, $e);
                }

                return true;
            }
            if ($consume) {
                $db->query("DELETE FROM `$table` WHERE id=$id");
                if ($db->affectedRows() !== 1) {
                    throw new \RuntimeException('Task disappeared before it could be consumed.');
                }
            }
            $escapedTable = $db->real_escape_string($table);
            $db->query("DELETE FROM scheduled_task_failures WHERE task_table='$escapedTable' AND task_id=$id");
            if (!$db->commit()) {
                throw new \RuntimeException('Unable to commit task transaction.');
            }

            return true;
        } catch (\Throwable $e) {
            $db->rollback();
            self::recordFailure($table, $id, $e);

            throw $e;
        }
    }

    private static function quarantine(
        string $table,
        int $id,
        array $payloadRow,
        QuarantineTaskException $error
    ): void
    {
        $db = DB::getInstance();
        $payload = json_encode($payloadRow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Unable to encode quarantined task payload.', 0, $error);
        }
        $escapedTable = $db->real_escape_string($table);
        $escapedPayload = $db->real_escape_string($payload);
        $escapedError = $db->real_escape_string(substr($error->getMessage(), 0, 1000));
        $now = time();
        if (!$db->query(
            "INSERT INTO scheduled_task_failures
                (task_table, task_id, attempts, payload, last_error, first_failed_at, last_failed_at)
             VALUES ('$escapedTable', $id, " . self::MAX_ATTEMPTS . ", '$escapedPayload', '$escapedError', $now, $now)
             ON DUPLICATE KEY UPDATE
                attempts=VALUES(attempts), payload=VALUES(payload), last_error=VALUES(last_error), last_failed_at=VALUES(last_failed_at)"
        )) {
            throw new \RuntimeException('Unable to persist quarantined task payload.', 0, $error);
        }
        if (!$db->query("DELETE FROM `$table` WHERE id=$id") || $db->affectedRows() !== 1) {
            throw new \RuntimeException('Unable to remove quarantined task from its live queue.', 0, $error);
        }
    }

    private static function recordFailure(string $table, int $id, \Throwable $error): void
    {
        $db = DB::getInstance();
        if (!$db->begin_transaction()) {
            return;
        }

        try {
            $result = $db->query("SELECT * FROM `$table` WHERE id=$id FOR UPDATE");
            if (!$result || !$result->num_rows) {
                $db->rollback();

                return;
            }
            $row = $result->fetch_assoc();
            $payload = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                $payload = '{}';
            }
            $escapedTable = $db->real_escape_string($table);
            $escapedPayload = $db->real_escape_string($payload);
            $escapedError = $db->real_escape_string(substr($error->getMessage(), 0, 1000));
            $now = time();
            $db->query(
                "INSERT INTO scheduled_task_failures
                    (task_table, task_id, attempts, payload, last_error, first_failed_at, last_failed_at)
                 VALUES ('$escapedTable', $id, 1, '$escapedPayload', '$escapedError', $now, $now)
                 ON DUPLICATE KEY UPDATE
                    attempts=attempts+1, payload=VALUES(payload), last_error=VALUES(last_error), last_failed_at=VALUES(last_failed_at)"
            );
            $attempts = (int)$db->fetchScalar(
                "SELECT attempts FROM scheduled_task_failures WHERE task_table='$escapedTable' AND task_id=$id"
            );
            if ($attempts >= self::MAX_ATTEMPTS) {
                $db->query("DELETE FROM `$table` WHERE id=$id");
            }
            $db->commit();
        } catch (\Throwable $ledgerError) {
            $db->rollback();
            \logError('Unable to record scheduled task failure: ' . $ledgerError->getMessage());
        }
    }
}
