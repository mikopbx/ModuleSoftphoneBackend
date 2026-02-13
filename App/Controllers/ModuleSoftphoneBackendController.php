<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by MikoPBX Team
 *
 */

namespace Modules\ModuleSoftphoneBackend\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleSoftphoneBackend\App\Forms\ModuleSoftphoneBackendForm;
use Modules\ModuleSoftphoneBackend\Lib\RestAPI\Controllers\ApiController;
use Modules\ModuleSoftphoneBackend\Models\ModuleSoftphoneBackend;

/**
 * Module Softphone Backend Controller
 */
class ModuleSoftphoneBackendController extends BaseController
{

    private string $moduleUniqueID = 'ModuleSoftphoneBackend';
    private string $moduleDir = '';

    /**
     * Basic initial class
     */
    public function initialize(): void
    {
        $footerCollection = $this->assets->collection('footerJS');
        $footerCollection->addJs('js/pbx/main/form.js', true);
        $footerCollection->addJs('js/vendor/datatable/dataTables.semanticui.js', true);
        $footerCollection->addJs("js/cache/$this->moduleUniqueID/module-softphone-backend.js", true);
        $footerCollection->addJs('js/vendor/jquery.tablednd.min.js', true);
        $footerCollection->addJs('js/vendor/vue.js', true);

        $headerCollectionCSS = $this->assets->collection('headerCSS');
        $headerCollectionCSS->addCss('css/vendor/datatable/dataTables.semanticui.min.css', true);
        $headerCollectionCSS->addCss('css/vendor/semantic/comment.css', true);
        $headerCollectionCSS->addCss('css/vendor/semantic/card.css', true);
        $headerCollectionCSS->addCss('css/vendor/semantic/list.css', true);

        $this->moduleDir = PbxExtensionUtils::getModuleDir($this->moduleUniqueID);
        $this->view->logoImagePath = '';
        $this->view->submitMode = null;
        parent::initialize();
    }

    public function indexAction(): void
    {
        $settings = ModuleSoftphoneBackend::findFirst();
        if ($settings === null) {
            $settings = new ModuleSoftphoneBackend();
            $settings->externalPort = '8988';
            $settings->useHttps = '0';
            $settings->urlPrefix = '';
        }

        $this->view->form = new ModuleSoftphoneBackendForm($settings);
        $this->view->pick("$this->moduleDir/App/Views/index");

        // Only generate auth token if module is enabled
        $module = \MikoPBX\Common\Models\PbxExtensionModules::findFirst("uniqid='ModuleSoftphoneBackend'");
        if ($module !== null && $module->disabled === '0') {
            $api = new ApiController();
            $api->initialize();
            $this->view->authDAta = $api->createLoginResponse('1', 'admin');
        } else {
            $this->view->authDAta = ['access_token' => ''];
        }
    }

    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            return;
        }

        $data = $this->request->getPost();

        $record = null;
        if (!empty($data['id'])) {
            $record = ModuleSoftphoneBackend::findFirstById($data['id']);
        }
        if ($record === null) {
            $record = ModuleSoftphoneBackend::findFirst();
        }
        if ($record === null) {
            $record = new ModuleSoftphoneBackend();
        }

        $record->externalPort = $data['externalPort'] ?: '8988';
        $record->useHttps = !empty($data['useHttps']) ? '1' : '0';
        // urlPrefix is not editable via form, preserved as-is

        if ($record->save()) {
            $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
            $this->view->success = true;
        } else {
            $this->flash->error(implode('<br>', $record->getMessages()));
            $this->view->success = false;
        }
    }

    /**
     * Regenerate URL prefix via AJAX
     */
    public function regeneratePrefixAction(): void
    {
        if (!$this->request->isPost()) {
            return;
        }

        $record = ModuleSoftphoneBackend::findFirst();
        if ($record === null) {
            $record = new ModuleSoftphoneBackend();
            $record->externalPort = '8988';
            $record->useHttps = '0';
        }

        $record->urlPrefix = strtr(base64_encode(random_bytes(24)), '+/', '-_');

        if ($record->save()) {
            $this->view->success = true;
            $this->view->data = ['urlPrefix' => $record->urlPrefix];
        } else {
            $this->flash->error(implode('<br>', $record->getMessages()));
            $this->view->success = false;
        }
    }
}
