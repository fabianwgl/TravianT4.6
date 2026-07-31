<?php

namespace Core\Helper;

use Core\Database\GlobalDB;

class Mailer
{
    public static function sendAdminReport($subject, $html)
    {
        global $globalConfig;
        $address = $globalConfig['staticParameters']['adminEmail'] ?? '';
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        return self::sendEmail($address, $subject, $html);
    }

    public static function sendBatch($to, $subject, $html)
    {
        $db = GlobalDB::getInstance();
        $html = $db->real_escape_string($html);
        $string = [];
        $basic = "('%s', '%s', '%s')";
        foreach ($to as $v) {
            $string[] = sprintf($basic, $v, $subject, $html, 99999);
        }
        $db->query("INSERT INTO mailServer (toEmail, subject, html) VALUES " . implode(",", $string));
        return $db->affectedRows();
    }

    public static function sendEmail($to, $subject, $html, $priority = 0, ?string $deliveryKey = null): bool
    {
        $db = GlobalDB::getInstance();
        $escapedTo = $db->real_escape_string($to);
        $escapedSubject = $db->real_escape_string($subject);
        $escapedHtml = $db->real_escape_string($html);
        if ($deliveryKey === null) {
            $query = "INSERT INTO mailServer (toEmail, subject, html, priority)
                VALUES ('$escapedTo', '$escapedSubject', '$escapedHtml', $priority)";
        } else {
            $escapedKey = $db->real_escape_string($deliveryKey);
            $query = "INSERT INTO mailServer (toEmail, subject, html, delivery_key, priority)
                VALUES ('$escapedTo', '$escapedSubject', '$escapedHtml', '$escapedKey', $priority)
                ON DUPLICATE KEY UPDATE id=id";
        }

        return $db->query($query) !== false;
    }
}
