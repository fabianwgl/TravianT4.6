<?php

namespace Controller;

class SupportFormCtrl extends GameCtrl
{
    public function __construct()
    {
        parent::__construct();
        $this->redirect('messages.php?t=1&id=2');
    }
}
