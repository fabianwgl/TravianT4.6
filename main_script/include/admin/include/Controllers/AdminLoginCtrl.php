<?php

use Core\Database\DB;
use Core\Helper\Notification;
use Core\Helper\WebService;
use Core\Security\Password;

class AdminLoginCtrl
{
    public function __construct()
    {
        $params['error'] = '';
        if (WebService::isPost() && isset($_POST['name'])) {
            $username = filter_var(trim($_POST['name']), FILTER_SANITIZE_STRING);
            $password = filter_var($_POST['pw'], FILTER_SANITIZE_STRING);
            if (empty($username) || empty($password)) {
                $params['error'] = 'fill the form';
            } else if(recaptcha_check_answer()) {
                $db = DB::getInstance();
                $username = $db->real_escape_string(htmlspecialchars($username, ENT_QUOTES));
                $find = $db->query("SELECT id, password FROM users WHERE name='$username' AND (access=2 OR id IN (0, 2)) LIMIT 1");
                $row = $find->num_rows ? $find->fetch_assoc() : null;
                if ($row && Password::verify($password, $row['password'])) {
                    $passwordHash = $row['password'];
                    if (Password::needsRehash($passwordHash)) {
                        $passwordHash = Password::hash($password);
                        $db->run("UPDATE users SET password=? WHERE id=?", [$passwordHash, (int)$row['id']]);
                    }
                    session_regenerate_id(true);
                    $ip = WebService::ipAddress();
                    $_SESSION[WebService::fixSessionPrefix('uid')] = (int)$row['id'];
                    $_SESSION[WebService::fixSessionPrefix('user')] = $username;
                    $_SESSION[WebService::fixSessionPrefix('pw')] = $passwordHash;
                    $_SESSION[WebService::fixSessionPrefix('ip')] = $ip;
                    $db->query("UPDATE users SET last_login_time=" . time() . " WHERE id=" . $_SESSION[WebService::fixSessionPrefix('uid')]);
                    $db->query("INSERT INTO log_ip (uid, ip, time) VALUES ({$_SESSION[WebService::fixSessionPrefix('uid')]}, '".ip2long($ip)."', " . time() . ")");
                    WebService::redirect("admin.php?loggedIn=true");
                } else {
                    $ip = WebService::ipAddress();
                    Notification::notify("Administrator login notification", "IP: $ip tried to log in with username <u>$username</u> but access was denied!");
                    AdminLog::getInstance()->addLog("<font color=\"red\"><b>IP: $ip tried to log in with username <u>$username</u> but access was denied!</b></font>");
                    $params['error'] = 'unknown login';
                }
            }
        }
        $dispatcher = Dispatcher::getInstance();
        $dispatcher->appendContent(Template::getInstance()->load($params, 'tpl/login.tpl')->getAsString());
    }
}
