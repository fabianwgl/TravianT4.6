<?php

namespace Core\Helper;

use Core\Config;
use Core\Database\DB;
use Core\Database\GlobalDB;
use function getWorldId;
use function getWorldUniqueId;

class Notification
{
    public static function notify($subject, $mainText)
    {
        $mainText = nl2br($mainText);
        $text = '<b>' . $subject . '</b>';
        $text .= "<br />";
        $text .= $mainText;
        $text .= "<br />";
        $text .= '<a href="' . WebService::getJustSubDomain() . '">» Go to game (' . getWorldId() . ')</a>';
        $breaks = array("<br />", "<br>", "<br/>");
        $text = str_ireplace($breaks, "\r\n", $text);
        $db = DB::getInstance();
        $db->query("INSERT INTO `notificationQueue`(`message`, `time`) VALUES ('" . $db->real_escape_string($text) . "', '" . time() . "')");
    }

    public static function RealTimeNotify($subject, $mainText)
    {
        $mainText = nl2br($mainText);
        $text = '<b>' . $subject . '</b>';
        $text .= "<br />";
        $text .= $mainText;
        $text .= "<br />";
        $text .= '<a href="' . WebService::getJustSubDomain() . '">» Go to game (' . getWorldId() . ')</a>';
        $breaks = array("<br />", "<br>", "<br/>");
        $text = str_ireplace($breaks, "\r\n", $text);
        self::notifyReal($text);
    }

    public static function deliveryKey(int $queueId): string
    {
        return 'notificationQueue:' . getWorldUniqueId() . ':' . $queueId;
    }

    public static function notifyReal($text, ?string $deliveryKey = null)
    {
        $db = GlobalDB::getInstance();
        $text = self::bbCode($text);
        $message = $db->real_escape_string($text);
        $time = time();
        if ($deliveryKey === null) {
            $query = "INSERT INTO `notifications`(`message`, `time`) VALUES ('$message', '$time')";
        } else {
            $key = $db->real_escape_string($deliveryKey);
            $query = "INSERT INTO `notifications`(`message`, `delivery_key`, `time`) VALUES ('$message', '$key', '$time')
                ON DUPLICATE KEY UPDATE id=id";
        }
        if (!$db->query($query)) {
            throw new \RuntimeException('Unable to persist global notification.');
        }
    }

    private static function bbCode($input)
    {
        $input = preg_replace_callback("#\[UID=(.*?)\]#is",
            function ($matches) {
                $uid = (int)$matches[0];
                $db = DB::getInstance();
                $find = $db->query("SELECT id, name FROM users WHERE id='$uid'");
                if (!$find->num_rows) {
                    return '<span style="font-style:italic;">' . T("Global", "Player not found") . '</span>';
                }
                $find = $find->fetch_assoc();
                return '<a href="spieler.php?uid=' . $find['id'] . '">' . $find['name'] . '</a>';
            },
            $input);
        return $input;
    }
}
