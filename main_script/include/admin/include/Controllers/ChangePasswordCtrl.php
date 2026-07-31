<?php

use Core\Config;
use Core\Database\DB;
use Core\Database\GlobalDB;
use Core\Helper\Notification;
use Core\Helper\WebService;
use Core\Security\Password;

class ChangePasswordCtrl
{
    public function __construct()
    {
        $params = ['new_password' => '', 'error' => null];
        if (WebService::isPost()) {
            $params['new_password'] = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            if (empty($params['new_password'])) {
                $params['error'] = 'fill the form';
            } else {
                $params['error'] = 'Your new password is: "'.$params['new_password'].'".';
                AdminLog::getInstance()->addLog("Changed password!");
                $db = DB::getInstance();
                $passwordHash = Password::hash($params['new_password']);
                $uid = (int)$_SESSION[WebService::fixSessionPrefix('uid')];
                $db->run("UPDATE users SET password=? WHERE id=?", [$passwordHash, $uid]);
                $_SESSION[WebService::fixSessionPrefix('pw')] = $passwordHash;
            }
        }
        $dispatcher = Dispatcher::getInstance();
        $dispatcher->appendContent(Template::getInstance()->load($params, 'tpl/changePassword.tpl')->getAsString());
    }
}
