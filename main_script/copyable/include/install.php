<?php
if(!(php_sapi_name() == 'cli')){
    exit("CLI Only!");
}
define("IS_INSTALLER", true);
require __DIR__ . "/env.php";
require dirname(__DIR__, 2) . "/include/bootstrap.php";
require __DIR__ . '/migrate.php';
use Core\Config;
use Core\Database\DB;
use Model\InstallerModel;
run_schema_migrations();
run_global_schema_migrations();
mt_srand(make_seed());
class shell_installer
{
    public function __construct($password)
    {
        if (empty($password)) {
            throw new InvalidArgumentException('An initial administrator password is required.');
        }
        echo 'before install.';
        if (Config::getInstance()->dynamic->installed) {
            echo 'Installation is completed.' . PHP_EOL;
        } else {
            echo 'Begin installation...';
            ini_set('memory_limit', -1);
            set_time_limit(0);
            //fclose(fopen("installation.log", "w"));
            $installer = new InstallerModel();
            $installer->mapToArray();
            $installer->createRestOfTheMap();
            $installer->pushMapToDB();
            $installer->finalize($password);
            $db = DB::getInstance();
            $db->query("UPDATE odata SET lasttrain=" . Config::getProperty('game', 'start_time'));
            exit();
        }
    }
}
new shell_installer(trim($argv[2] ?? ''));
