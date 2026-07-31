<?php
set_time_limit(0);
ini_set('mysql.connect_timeout', '0');
ini_set('max_execution_time', '0');
declare(ticks=1);

use Core\ErrorHandler;
use Core\Jobs;

require(__DIR__ . "/bootstrap.php");
$automationLogFile = dirname(ERROR_LOG_FILE) . "/automation.log";
global $PIDs, $loop;
$PIDs = [];
$loop = TRUE;

function sig_handler($signal)
{
    global $PIDs, $loop;
    $loop = FALSE;
    foreach ($PIDs as $k => $v) {
        try {
            posix_kill($v, SIGTERM);
            unset($PIDs[$k]);
        } catch (\Throwable $e) {
            ErrorHandler::getInstance()->handleExceptions($e);
        }
    }
}

pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGINT, "sig_handler");
pcntl_signal(SIGHUP, "sig_handler");
Jobs\Launcher::lunchJobs();

while ($loop) {
    sleep(1);
    pcntl_signal_dispatch();
}
