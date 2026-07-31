<?php
global $globalConfig;

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
};

$baseUrl = rtrim($env('APP_URL', 'http://127.0.0.1:8080'), '/') . '/';
$globalConfig = [];
$globalConfig['staticParameters'] = [];
$globalConfig['staticParameters']['default_language'] = $env('APP_LANGUAGE', 'en');
$globalConfig['staticParameters']['default_timezone'] = $env('APP_TIMEZONE', 'UTC');
$globalConfig['staticParameters']['default_direction'] = 'LTR';
$globalConfig['staticParameters']['default_dateFormat'] = 'Y-m-d';
$globalConfig['staticParameters']['default_timeFormat'] = 'H:i';
$globalConfig['staticParameters']['indexUrl'] = $baseUrl;
$globalConfig['staticParameters']['forumUrl'] = $baseUrl;
$globalConfig['staticParameters']['answersUrl'] = $baseUrl . 'docs/';
$globalConfig['staticParameters']['helpUrl'] = $baseUrl . 'docs/';
$globalConfig['staticParameters']['adminEmail'] = $env('ADMIN_EMAIL');
$globalConfig['staticParameters']['session_timeout'] = (int)$env('SESSION_TIMEOUT', '21600');
$globalConfig['staticParameters']['default_payment_location'] = 0;
$globalConfig['staticParameters']['global_css_class'] = 'openvillage';
$globalConfig['staticParameters']['gpacks'] = require(__DIR__ . "/gpack/gpack.php");
$globalConfig['staticParameters']['recaptcha_public_key'] = $env('RECAPTCHA_SITE_KEY');
$globalConfig['staticParameters']['recaptcha_private_key'] = $env('RECAPTCHA_SECRET_KEY');
$globalConfig['cachingServers'] = [];
$globalConfig['dataSources'] = [];
$globalConfig['dataSources']['globalDB']['hostname'] = $env('GLOBAL_DB_HOST', 'database');
$globalConfig['dataSources']['globalDB']['username'] = $env('GLOBAL_DB_USER', 'openvillage');
$globalConfig['dataSources']['globalDB']['password'] = $env('GLOBAL_DB_PASSWORD', 'local-game-password');
$globalConfig['dataSources']['globalDB']['database'] = $env('GLOBAL_DB_NAME', 'openvillage_global');
$globalConfig['dataSources']['globalDB']['charset'] = 'utf8mb4';

