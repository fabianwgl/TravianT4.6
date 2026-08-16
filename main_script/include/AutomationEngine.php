<?php
set_time_limit(0);
ini_set('mysql.connect_timeout', '0');
ini_set('max_execution_time', '0');
declare(ticks=1);

use Core\ErrorHandler;
use Core\Jobs;
use Core\Jobs\WorkerRegistry;

require(__DIR__ . "/bootstrap.php");
$automationLogFile = dirname(ERROR_LOG_FILE) . "/automation.log";
global $workerRegistry, $loop;
$workerRegistry = new WorkerRegistry();
$loop = TRUE;

function sig_handler($signal)
{
    global $workerRegistry, $loop;
    $loop = FALSE;
    try {
        $workerRegistry->signalAll(SIGTERM);
    } catch (\Throwable $e) {
        ErrorHandler::getInstance()->handleExceptions($e);
    }
}

pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGINT, "sig_handler");
pcntl_signal(SIGHUP, "sig_handler");
Jobs\Launcher::lunchJobs();

while ($loop) {
    sleep(1);
    pcntl_signal_dispatch();
    if (!$loop) {
        break;
    }
    $exitedWorkers = $workerRegistry->reapExited();
    if ($exitedWorkers) {
        foreach ($exitedWorkers as $identity => $state) {
            logError("Automation worker exited unexpectedly: $identity (PID {$state['pid']}).");
        }
        $loop = FALSE;
        $workerRegistry->signalAll(SIGTERM);
    }
}
$workerRegistry->shutdown(15);
