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
use MikoPBX\Common\Models\Extensions;
use Modules\ModuleSoftphoneBackend\Models\ModuleSoftphoneBackend;

/**
 * Module Softphone Backend Controller
 */
class ModuleSoftphoneBackendController extends BaseController
{
    public function indexAction(): void
    {
//        $module = ModuleSoftphoneBackend::findFirstById('ModuleSoftphoneBackend');
//        if ($module === null) {
//            $module = new ModuleSoftphoneBackend();
//            $module->id = 'ModuleSoftphoneBackend';
//        }
//
//        $this->view->module = $module;
//        $this->view->extensions = Extensions::find();
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

