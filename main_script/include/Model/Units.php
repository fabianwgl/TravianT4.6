<?php

namespace Model;

use Core\Database\DB;

class Units
{
    public static function debitIfAvailable(int $kid, array $units): bool
    {
        if ($kid <= 0) {
            return false;
        }

        $modify = [];
        $available = [];
        for ($i = 1; $i <= 11; ++$i) {
            $value = $units[$i] ?? 0;
            $amount = filter_var($value, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 0) {
                return false;
            }
            if ($amount === 0) {
                continue;
            }
            $modify[] = "u{$i}=u{$i}-$amount";
            $available[] = "u{$i}>=$amount";
        }
        if ($modify === []) {
            return false;
        }

        $db = DB::getInstance();
        $query = $db->query(
            "UPDATE units SET " . implode(',', $modify) .
            " WHERE kid=$kid AND " . implode(' AND ', $available)
        );

        return $query && $db->affectedRows() === 1;
    }

    public static function modifyUnits($kid, $units)
    {
        return self::debitIfAvailable((int)$kid, (array)$units);
    }
}
