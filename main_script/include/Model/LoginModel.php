<?php
namespace Model;
use Core\Config;
use Core\Database\DB;
use Core\Database\GlobalDB;
use Core\Helper\Mailer;
use Core\Helper\WebService;
use Core\Security\Password;

class LoginModel
{
    private $authenticatedPasswordHash;

	public function findLogin($name)
	{
        $db = DB::getInstance();
        $name = $db->real_escape_string(htmlspecialchars($name, ENT_QUOTES));
		$LoginType = 0;
		$userRow = [];
		do {
			$find = $db->query("SELECT id, name, email, sit1Uid, sit2Uid, password, last_owner_login_time FROM users WHERE (name='$name' OR email='$name') LIMIT 1");
			if($find->num_rows) {
				$LoginType = 1;
				$userRow = $find->fetch_assoc();
				$find->free();
				break;
			}
			$find->free();
			$find = $db->query("SELECT id, token, password FROM activation WHERE (name='$name' OR email='$name') LIMIT 1");
			if($find->num_rows) {
				$LoginType = 2;
				$userRow = $find->fetch_assoc();
				$find->free();
				break;
			}
			$find->free();
			$activation = $this->getActivation(Config::getProperty("settings", "worldUniqueId"), $name);
			if($activation !== FALSE) {
				$LoginType = 3;
				$userRow = $activation;
				break;
			}
			//index api
		} while(FALSE);
		return ["type" => $LoginType, "row" => $userRow];
	}
	private function getActivation($worldId, $name){
		$globalDB = GlobalDB::getInstance();
        $worldId = $globalDB->real_escape_string($worldId);
		$name = $globalDB->real_escape_string($name);


		$find = $globalDB->query("SELECT id, name, password FROM activation WHERE worldId='$worldId' AND used=0 AND (name='$name' OR email='$name')");
		if($find->num_rows){
			return $find->fetch_assoc();
		}
		return false;
	}
	public function addNewPassword($row)
	{
		$new_pass = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
		$db = DB::getInstance();
		$db->run("DELETE FROM newproc WHERE uid=?", [(int)$row['id']]);
		$time = time();
		$cpw = bin2hex(random_bytes(15));
		$db->run(
            "INSERT INTO newproc (uid, cpw, npw, time) VALUES (?, ?, ?, ?)",
            [(int)$row['id'], $cpw, $new_pass, $time]
        );
		$link = WebService::get_base_url().'/password.php?cpw='.$cpw.'&npw='.(int)$row['id'];
		$html = vsprintf(T("Login", "pw_forgot_email"), [
			$row['name'], $row['name'], $row['email'], $new_pass,
			Config::getInstance()->settings->worldId, $link, $link,
		]);
		Mailer::sendEmail($row['email'], T("Login", "PasswordForgotten?"), $html);
	}

	public function findUserLoginById($uid)
	{
		$db = DB::getInstance();
		$uid = (int)$uid;

		return $db->query("SELECT id, sit1Uid, sit2Uid, password FROM users WHERE id=$uid LIMIT 1");
	}

	public function checkUserOrSitterLogin($uid, $password)
	{
		$user = $this->findUserLoginById($uid);
		if(!$user->num_rows) {
			$user->free();

			return 1;
		}
		$row = $user->fetch_assoc();
		$user->free();
		if($row['password'] == $password) {
			return 0;
		}
		if($row['sit1Uid'] && $this->getSitterPassword($row['sit1Uid']) == $password) {
			return 2;
		}
		if($row['sit2Uid'] && $this->getSitterPassword($row['sit2Uid']) == $password) {
			return 3;
		}

		return 1;
	}

	private function getSitterPassword($uid)
	{
		if(!$uid) {
			return FALSE;
		}
		$db = DB::getInstance();
		$row = $db->query("SELECT password FROM users WHERE id=$uid LIMIT 1");
		if($row->num_rows) {
			$result = $row->fetch_assoc();
			$row->free();

			return $result['password'];
		}
		$row->free();

		return FALSE;
	}

	/**
	 * @param $password
	 * @param $result
	 *
	 * @return int
	 * 0: success login
	 * 1: password wrong.
	 *
	 */
	public function checkLogin($password, $result)
	{
		if(Password::verify($password, $result['row']['password'])) {
            $this->authenticatedPasswordHash = $result['row']['password'];
            if ($result['type'] == 1 && Password::needsRehash($result['row']['password'])) {
                $this->authenticatedPasswordHash = Password::hash($password);
                DB::getInstance()->run(
                    "UPDATE users SET password=? WHERE id=?",
                    [$this->authenticatedPasswordHash, (int)$result['row']['id']]
                );
            }
			return 0;
		}
		if($result['type'] == 1) {
			if($result['row']['sit1Uid']) {
                $sitterPassword = $this->getSitterPassword($result['row']['sit1Uid']);
                if ($sitterPassword && Password::verify($password, $sitterPassword)) {
                    $this->authenticatedPasswordHash = $sitterPassword;
                    return 1;
                }
            }
			if($result['row']['sit2Uid']) {
                $sitterPassword = $this->getSitterPassword($result['row']['sit2Uid']);
                if ($sitterPassword && Password::verify($password, $sitterPassword)) {
                    $this->authenticatedPasswordHash = $sitterPassword;
                    return 2;
                }
            }
		}

		return 3;
	}

    public function getAuthenticatedPasswordHash()
    {
        return $this->authenticatedPasswordHash;
    }
}
