<?php

namespace Controller;

use Core\Database\DB;
use Core\Locale;
use Core\Security\Password;
use resources\View\OutOfGameView;

class PasswordCtrl extends OutOfGameCtrl
{
    public function __construct()
    {
        $this->view = new OutOfGameView();
        $this->view->vars['titleInHeader'] = T("Login", "Login");
        $this->view->vars['bodyCssClass'] = 'perspectiveBuildings';
        $this->view->vars['contentCssClass'] = 'login';
        $npw = isset($_GET['npw']) && ctype_digit((string)$_GET['npw']) ? (int)$_GET['npw'] : null;
        $cpw = isset($_GET['cpw']) && preg_match('/^[a-f0-9]{30}$/D', (string)$_GET['cpw']) === 1
            ? (string)$_GET['cpw']
            : null;
        $this->view->vars['content'] .= '<div id="passwordForgotten"><h4>' . T("Login", "PasswordForgotten?") . '</h4>';
        if ($npw === NULL || $cpw === NULL) {
            goto finalize;
        }
        $db = DB::getInstance();

        $find = $db->run(
            "SELECT * FROM newproc WHERE cpw=? AND uid=? AND time>=? LIMIT 1",
            [$cpw, $npw, time() - 3600]
        )->get_result();
        if ($find->num_rows) {
            $row = $find->fetch_assoc();
            $password = Password::hash($row['npw']);
            $query = $db->run("DELETE FROM newproc WHERE uid=? AND cpw=?", [(int)$row['uid'], $cpw]);
            if ($query && $db->affectedRows()) {
                $db->run("UPDATE users SET password=? WHERE id=?", [$password, (int)$row['uid']]);
            }
            $this->view->vars['content'] .= T("Login", "PasswordChangedSuccessfully");
        } else {
            $this->view->vars['content'] .= T("Login", "PasswordFail");
        }
        finalize:
        $this->view->vars['content'] .= '</div>';
    }
}
