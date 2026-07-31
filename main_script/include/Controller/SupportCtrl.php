<?php

namespace Controller;

use resources\View\OutOfGameView;

class SupportCtrl extends OutOfGameCtrl
{
    public function __construct()
    {
        $this->view = new OutOfGameView();
        $this->view->vars['titleInHeader'] = T('Support', 'Support');
        $this->view->vars['bodyCssClass'] = 'perspectiveBuildings';
        $this->view->vars['contentCssClass'] = 'support';
        $this->view->vars['content'] = '<div class="roundedCornersBox big">'
            . '<h4><div class="statusMessage">OpenVillage support</div></h4>'
            . '<div class="contractWrapper"><p>Review the local documentation first. '
            . 'For account help, sign in and message the world operator.</p>'
            . '<p><a class="arrow" href="/docs/">Rules and operations</a></p></div></div>';
    }
}
