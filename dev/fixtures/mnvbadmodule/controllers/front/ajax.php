<?php
/**
 * Test fixture (see mnvbadmodule.php). Not shipped.
 */
class MnvbadmoduleAjaxModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->ajaxDie(json_encode(array('ok' => true)));   // removed_method Controller::ajaxDie (inherited)
    }
}
