<?php
define("ADMIN_PANEL", true);
require(__DIR__ . DIRECTORY_SEPARATOR . "Core" . DIRECTORY_SEPARATOR . "Dispatcher.php");
require(__DIR__ . DIRECTORY_SEPARATOR . "Core" . DIRECTORY_SEPARATOR . "AdminLog.php");
Template::getInstance()->load(Dispatcher::getInstance()->data, 'tpl/layout.tpl')->display();
