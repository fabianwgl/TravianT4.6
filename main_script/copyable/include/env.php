<?php
define("IS_DEV", false);
define("PUBLIC_PATH", dirname(__DIR__) . "/public/");
define("CONNECTION_FILE", __DIR__ . '/connection.php');
define("CONFIG_CUSTOM_FILE", __DIR__ . '/config.custom.php');
define("RUNTIME_PATH", __DIR__ . '/runtime');
define("ERROR_LOG_FILE", RUNTIME_PATH . '/error.log');
define("GLOBAL_CONFIG_FILE", dirname(__DIR__, 3) . '/sections/globalConfig.php');
define("BACKUP_PATH", RUNTIME_PATH . '/backups/');
define("FILTERING_PATH", dirname(__DIR__, 3) . '/filtering/');
