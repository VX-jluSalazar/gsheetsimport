<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminGsheetsImportController extends ModuleAdminController
{
    public function initContent(): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules') . '&configure=gsheetsimport');
    }
}
