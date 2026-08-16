<?php

namespace Controller;

use Core\Session;
use const DIRECTORY_SEPARATOR;

function response($response)
{
    header("Content-Type: application/json; charset=UTF-8;");
    $response = json_encode($response, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT);
    echo $response;
    exit(0);
}



class AjaxCtrl extends AnyCtrl
{
    public function __construct()
    {
        parent::__construct();
        $response = ["response" => ['error' => FALSE, 'errorMsg' => NULL, 'data' => [],],];
        if (isset($_GET['cmd'])) {
            $cmd = filter_var($_GET['cmd'], FILTER_SANITIZE_STRING);
            $response = ["response" => ['error' => FALSE, 'errorMsg' => NULL, 'data' => [],],];
            if (in_array($cmd, ['paymentProviders', 'paymentRules', 'paymentWizard'], true)) {
                $response['response']['error'] = TRUE;
                $response['response']['errorMsg'] = 'Payments are disabled in this release.';
                response($response);
            }
            if (!in_array($cmd, ['news', 'configuration'])) {
                $this->checkAjaxToken($response);
            }
            if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . "Ajax" . DIRECTORY_SEPARATOR . $cmd . ".php")) {
                $response['response']['error'] = TRUE;
                $response['response']['errorMsg'] = "Parameter \"$cmd\" (ajax.php) is not valid in \"cmd\".";
                $response['response']['data'] = [];
                response($response);
            }
            $cmd = '\\Controller\\Ajax\\' . $cmd;
            $dispatcher = new $cmd($response['response']);
            if (method_exists($dispatcher, "dispatch")) {
                $dispatcher->dispatch();
            }
            response($response);
        } else {
            $response['response']['error'] = TRUE;
            $response['response']['errorMsg'] = "Parameter \"cmd\" can not be empty or null.";
            $response['response']['data'] = [];
            response($response);
        }
    }
    function checkAjaxToken(&$response)
    {
        $providedToken = $_POST['ajaxToken'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expectedToken = (string)$this->session->getAjaxToken();
        if ($providedToken === '' || !hash_equals($expectedToken, (string)$providedToken)) {
            $response['ajaxToken'] = NULL;
            $response['response']['error'] = TRUE;
            $response['response']['errorMsg'] = 'Invalid token.';
            response($response);
        }
        return TRUE;
    }
}
