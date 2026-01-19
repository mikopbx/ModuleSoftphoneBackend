#!/usr/bin/php
<?php

use MikoPBX\Core\System\Util;
use MikoPBX\Core\Asterisk\AGI;
use Modules\ModuleSoftphoneBackend\bin\ConnectorDB;

require_once 'Globals.php';

$type = $argv[1]??'in';
try {
    $agi    = new AGI();
    if ($type === 'in') {
        $number = $agi->request['agi_callerid'];
    } else {
        $number = $agi->request['agi_extension'];
    }
    ConnectorDB::invoke('startFindClientByPhone', [$number], false);
} catch (\Throwable $e) {
    Util::sysLogMsg('ModuleCTIClient', $e->getMessage(), LOG_ERR);
}