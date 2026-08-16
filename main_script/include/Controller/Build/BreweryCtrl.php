<?php
namespace Controller\Build;
use Controller\AnyCtrl;
use Core\Helper\TimezoneHelper;
use Core\Session;
use Core\Village;
use Game\Formulas;
use Game\GoldHelper;
use Model\BreweryModel;
use function isServerFinished;
use resources\View\PHPBatchView;
class BreweryCtrl extends AnyCtrl
{
    public function __construct($index)
    {
        parent::__construct();

        $session = Session::getInstance();
        $uid = $session->getPlayerId();
        $kid = Village::getInstance()->getKid();
        $breweryModel = new BreweryModel();
        if (isset($_GET['z']) && $_GET['z'] == $session->getChecker()) {
            if ($session->banned()) {
                $this->innerRedirect("InGameBannedPage");
            } else if (isServerFinished()) {
                $this->innerRedirect("InGameWinnerPage");
            } else if ($session->isInVacationMode()) {
                $this->redirect('options.php?s=4');
            } else {
                $session->changeChecker();
                $breweryModel->startFestival($uid, $kid);
                $this->redirect('build.php?id=' . $index);
            }
        }

        $status = $breweryModel->getFestivalStatus($uid);
        $this->view = new PHPBatchView("build/brewery");
        $this->view->vars['buildingIndex'] = $index;
        $this->view->vars['festivalDuration'] = Formulas::getFestivalDuration();
        $this->view->vars['festivalResources'] = Formulas::getFestivalResources();
        $this->view->vars['isFestival'] = $status['active'];
        $this->view->vars['npcButton'] = (new GoldHelper())->getExchangeResourcesButtonByCost($this->view->vars['festivalResources']);
        $this->view->vars['contractLinkButton'] = $this->getButton($index, $status['active']);
        if ($status['active']) {
            $this->view->vars['timeLeft'] = appendTimer($status['endsAt'] - time());
            $this->view->vars['endat'] = TimezoneHelper::date("H:i", $status['endsAt']);
        }
    }

    private function getButton($id, bool $festivalActive)
    {
        if ($festivalActive) {
            return '<span class="errorMessage">' . T("inGame", "one celebration is running") . '</span>';
        }
        $cost = Formulas::getFestivalResources();
        if(Village::getInstance()->isResourcesAvailable($cost)) {
            return getButton(["type" => "button", "class" => "green", "onclick" => "window.location.href = 'build.php?id=$id&z=" . Session::getInstance()->getChecker() . "'; return false;", 'value' => T("inGame", "run celebration"),], ["data" => ["type" => "button",]], T("inGame", "run celebration"));
        }
        $contract = Village::getInstance()->contractResourcesLink($cost);
        return $contract['text'];
    }
}
