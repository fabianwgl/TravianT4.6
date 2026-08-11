<?php

namespace Controller\Build;

use Controller\AnyCtrl;
use Core\Config;
use Core\Helper\TimezoneHelper;
use Core\Session;
use Core\Village;
use Game\Formulas;
use Game\GoldHelper;
use Model\CelebrationModel;
use resources\View\PHPBatchView;

class TownhallCntrl extends AnyCtrl
{
    public function __construct($index)
    {
        parent::__construct();
        $level = Village::getInstance()->getField($index)['level'];
        $uid = Session::getInstance()->getPlayerId();
        $kid = Village::getInstance()->getKid();
        $celebrationModel = new CelebrationModel();
        if (
            isset($_GET['z'], $_GET['type'])
            && $_GET['z'] == Session::getInstance()->getChecker()
        ) {
            if (Session::getInstance()->banned()) {
                $this->innerRedirect("InGameBannedPage");
            } else if (Config::getInstance()->dynamic->serverFinished) {
                $this->innerRedirect("InGameWinnerPage");
            } else if (Session::getInstance()->isInVacationMode()) {
                $this->redirect('options.php?s=4');
            } else {
                Session::getInstance()->changeChecker();
                $type = is_scalar($_GET['type']) ? (int)$_GET['type'] : 0;
                $celebrationModel->startCelebration($uid, $kid, $type);
                $this->redirect('build.php?id=' . $index);
            }
        }

        $rewards = $celebrationModel->getRewardPreview($uid, $kid);
        $this->view = new PHPBatchView("build/Townhall");
        $village = Village::getInstance();
        $helper = new GoldHelper();
        $this->view->vars['smallCelebration']['active'] = $rewards['small'] > 0;
        $this->view->vars['smallCelebration']['points'] = $rewards['small'];
        $this->view->vars['smallCelebration']['cost'] = Formulas::celebrationCost(FALSE);
        $this->view->vars['smallCelebration']['time'] = secondsToString(Formulas::celebrationTime(FALSE, $level));
        if (Village::getInstance()->isResourcesAvailable($this->view->vars['smallCelebration']['cost'])) {
            $this->view->vars['smallCelebration']['exchangeButton'] = $helper->getExchangeResourcesButtonByCost($this->view->vars['smallCelebration']['cost']);
        } else {
            $this->view->vars['smallCelebration']['exchangeButton'] = NULL;
        }
        {
            $button = $this->getButton($index, FALSE);
            $this->view->vars['smallCelebration']['contractLink'] = $button['text'];
            $this->view->vars['smallCelebration']['npc'] = $button['npc'];
        }
        $this->view->vars['bigCelebration']['active'] = $level >= 10 && $rewards['large'] > 0;
        $this->view->vars['bigCelebration']['points'] = $rewards['large'];
        $this->view->vars['bigCelebration']['cost'] = Formulas::celebrationCost(TRUE);
        $this->view->vars['bigCelebration']['time'] = secondsToString(Formulas::celebrationTime(TRUE, $level));
        if (Village::getInstance()->isResourcesAvailable($this->view->vars['bigCelebration']['cost'])) {
            $this->view->vars['bigCelebration']['exchangeButton'] = $helper->getExchangeResourcesButtonByCost($this->view->vars['bigCelebration']['cost']);
        } else {
            $this->view->vars['bigCelebration']['exchangeButton'] = NULL;
        }
        {
            $button = $this->getButton($index, TRUE);
            $this->view->vars['bigCelebration']['contractLink'] = $button['text'];
            $this->view->vars['bigCelebration']['npc'] = $button['npc'];
        }
        if ($rewards['small'] === 0 && $rewards['large'] === 0) {
            $this->view = NULL;
        }
        $this->view->vars['isCelebration'] = $this->isCelebrationRunning();
        if ($this->view->vars['isCelebration']) {
            $this->view->vars['type'] = Village::getInstance()->getCelebrationType();
            $this->view->vars['timeLeft'] = appendTimer(Village::getInstance()->getCelebration() - time());
            $this->view->vars['endat'] = TimezoneHelper::date("H:i", Village::getInstance()->getCelebration());
        }
    }

    private function getButton($id, $big)
    {
        if ($this->isCelebrationRunning()) {
            return [
                'npc' => null,
                'text' => '<div class="errorMessage">' . T("inGame", "one celebration is running") . '</div>',
            ];
        }
        $cost = Formulas::celebrationCost($big);
        if (Village::getInstance()->isResourcesAvailable($cost)) {
            return [
                'text' => getButton([
                    "type"    => "button",
                    "class"   => "green",
                    "onclick" => "window.location.href = 'build.php?id=$id&type=" . ($big ? 2 : 1) . "&z=" . Session::getInstance()->getChecker() . "'; return false;",
                    'value'   => T("inGame", "run celebration"),
                ],
                    ["data" => ["type" => "button",]],
                    T("inGame", "run celebration")),
                'npc' => null,
            ];
        }
        $contract = Village::getInstance()->contractResourcesLink($cost);
        $npc = null;
        if ($contract['code'] == -1) {
            $helper = new GoldHelper();
            $npc = $helper->getExchangeResourcesButtonByCost($cost);
        }
        return [
            'npc'  => $npc,
            'text' => $contract['text'],
        ];
    }

    private function isCelebrationRunning()
    {
        return Village::getInstance()->getCelebration() > time();
    }
}
