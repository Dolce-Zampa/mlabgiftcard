<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminMlabGiftCardController extends ModuleAdminController
{
    public function initContent()
    {
        if (!$this->viewAccess() || !$this->module->getPermission('configure', $this->context->employee)) {
            http_response_code(403);
            $this->errors[] = $this->trans('You do not have permission to view this.', [], 'Admin.Notifications.Error');
            $this->context->smarty->assign('content', '');
            return;
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->module->name,
        ]));
    }
}
