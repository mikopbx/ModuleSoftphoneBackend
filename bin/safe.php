<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2025 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

use MikoPBX\Core\System\Configs\NginxConf;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleSoftphoneBackend\Lib\SoftphoneBackendConf;
require_once 'Globals.php';

$moduleEnable = PbxExtensionUtils::isEnabled('ModuleSoftphoneBackend');
if(!$moduleEnable){
    print_r('Not Enabled'.PHP_EOL);
    exit(1);
}

$result = Processes::mwExec(Util::which('busybox')." grep -rn 'module-softphone-backend'  /etc/nginx/mikopbx/modules_locations/");
if($result === 1){
    print_r('Init nginx location'.PHP_EOL);
    $nginxConf = new NginxConf();
    $nginxConf->generateConf();
    $nginxConf->reStart();
}

$conf = new SoftphoneBackendConf();
$workers = $conf->getModuleWorkers();
foreach ($workers as $workerData) {
    $WorkerPID = Processes::getPidOfProcess($workerData['worker']);
    print_r($WorkerPID.PHP_EOL);
    if (empty($WorkerPID)) {
        Processes::processPHPWorker($workerData['worker']);
        SystemMessages::sysLogMsg('ModuleSoftphoneBackend', "Service {$workerData['worker']} started.", LOG_NOTICE);
    }else{
        // Проверка дубликата процесса.
        $allButLast = array_slice(explode(' ', $WorkerPID), 0, -1);
        if(!empty($allButLast)){
            // Завершаем дубликаты процессов.
            $bbPath = Util::which('busybox');
            shell_exec("$bbPath kill -SIGUSR2 ". implode(" ", $allButLast));
        }
    }
}
