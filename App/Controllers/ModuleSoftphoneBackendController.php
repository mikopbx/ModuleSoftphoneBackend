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
use Modules\ModuleSoftphoneBackend\Lib\RestAPI\Controllers\ApiController;

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
       //  $headerCollectionCSS->addCss("css/cache/$this->moduleUniqueID/module-softphone-backend.css", true);
        $headerCollectionCSS->addCss('css/vendor/datatable/dataTables.semanticui.min.css', true);
        $headerCollectionCSS->addCss('css/vendor/semantic/comment.css', true);
        $headerCollectionCSS->addCss('css/vendor/semantic/card.css', true);
        $headerCollectionCSS->addCss('css/vendor/semantic/list.css', true);

        $this->moduleDir = PbxExtensionUtils::getModuleDir($this->moduleUniqueID);
        $this->view->logoImagePath = "{$this->url->get()}assets/img/cache/$this->moduleUniqueID/logo.svg";
        $this->view->submitMode = null;
        parent::initialize();
    }

    public function indexAction(): void
    {
        $this->view->pick("$this->moduleDir/App/Views/index");

        $api = new ApiController();
        $api->initialize();
        $this->view->authDAta = $api->createLoginResponse('1', 'admin');
    }

    public function saveAction(): void
    {
//        if (!$this->request->isPost()) {
//            $this->forward('module_softphone_backend/index');
//            return;
//        }
//
//        $id = $this->request->getPost('id');
//        $module = ModuleSoftphoneBackend::findFirstById($id);
//
//        if ($module === null) {
//            $module = new ModuleSoftphoneBackend();
//            $module->id = $id;
//        }
//
//        $module->disabled = $this->request->getPost('disabled');
//        $module->settings = $this->request->getPost('settings');
//
//        if ($module->save()) {
//            $this->flash->success('Settings saved successfully');
//        } else {
//            $this->flash->error('Error saving settings');
//        }
//
//        $this->forward('module_softphone_backend/index');
    }
}

