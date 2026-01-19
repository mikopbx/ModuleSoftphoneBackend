<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleSoftphoneBackend\bin;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Exception;
use Modules\ModuleSoftphoneBackend\Lib\Logger;
use Modules\ModuleSoftphoneBackend\Lib\MikoPBXVersion;
use Modules\ModuleSoftphoneBackend\Lib\RestAPI\Controllers\ApiController;
use Modules\ModuleSoftphoneBackend\Models\PhoneBook;

require_once 'Globals.php';

class ConnectorDB extends WorkerBase
{
    private const MODULE_ID = 'ModuleSoftphoneBackend';
    private Logger $logger;

    /**
     * Handles the received signal.
     *
     * @param int $signal The signal to handle.
     *
     * @return void
     */
    public function signalHandler(int $signal): void
    {
        parent::signalHandler($signal);
        cli_set_process_title('SHUTDOWN_'.cli_get_process_title());
    }

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {

        $this->logger   = new Logger('ConnectorDB', self::MODULE_ID);
        $this->logger->writeInfo('Starting...');
        $beanstalk      = new BeanstalkClient(self::class);
        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while ($this->needRestart === false) {
            $beanstalk->wait();
            $this->logger->rotate();
        }
    }

    public function pingCallBack(BeanstalkClient $message): void
    {
        parent::pingCallBack($message);
        $this->logger->writeInfo('Get event... PING '.getmypid());

    }

    /**
     * Получение запросов на идентификацию номера телефона.
     * @param $tube
     * @return void
     */
    public function onEvents($tube): void
    {
        $data = [];
        try {
            $pathToData = $tube->getBody();
            if(file_exists($pathToData)) {
                $data = json_decode(file_get_contents($pathToData), true, 512, JSON_THROW_ON_ERROR);
                unlink($pathToData);
            }
        }catch (Exception $e){
            return;
        }
        $action = $data['action']??'';
        if($action === 'invoke'){
            $res_data = [];
            $funcName = $data['function']??'';
            if(method_exists($this, $funcName)){
                if(count($data['args']) === 0){
                    $res_data = $this->$funcName();
                }else{
                    $res_data = $this->$funcName(...$data['args']??[]);
                }
            }
            if(isset($data['need-ret'])){
                $tube->reply(serialize($res_data));
                if(!empty($res_data)){
                    $this->logger->writeInfo(['action' => $funcName, 'data' => $res_data]);
                }
            }
        }
    }


    /**
     * Выполнение меодов worker, запущенного в другом процессе.
     * @param string $function
     * @param array $args
     * @param bool $retVal
     * @return array|bool|mixed
     */
    public static function invoke(string $function, array $args = [], bool $retVal = true){
        $req = [
            'action'   => 'invoke',
            'function' => $function,
            'args'     => $args
        ];
        $client = new BeanstalkClient(self::class);
        try {
            if($retVal){
                $req['need-ret'] = true;
                $pathToData = self::saveInTmpFile($req);
                $result = $client->request($pathToData, 20);
            }else{
                $pathToData = self::saveInTmpFile($req);
                $client->publish($pathToData);
                return true;
            }
            $object = unserialize($result, ['allowed_classes' => [PBXApiResult::class]]);
        } catch (\Throwable $e) {
            $object = [];
        }
        return $object;
    }

    /**
     * Возвращает усеценный слева номер телефона.
     *
     * @param $number
     *
     * @return bool|string
     */
    public static function getPhoneIndex($number)
    {
        $numberRep = preg_replace('/\D+/', '', $number);
        if(!is_numeric(str_replace('+', '', $numberRep))){
            return $numberRep;
        }
        return substr($numberRep, -10);
    }

    /**
     * Сериализует данные и сохраняет их во временный файл.
     * @param array $data
     * @return string
     */
    private function saveResultInTmpFile(array $data):string
    {
        return self::saveInTmpFile($data);
    }

    /**
     * Сериализует данные и сохраняет их во временный файл.
     * @param array $data
     * @return string
     */
    public static function saveInTmpFile(array $data):string
    {
        try {
            $res_data = json_encode($data, JSON_THROW_ON_ERROR);
        }catch (\JsonException $e){
            return '';
        }
        $downloadCacheDir = '/tmp/';
        $tmpDir           = '/tmp/';
        $di = MikoPBXVersion::getDefaultDi();
        if ($di) {
            $dirsConfig = $di->getShared('config');
            $tmoDirName = $dirsConfig->path('core.tempDir') . '/'.self::MODULE_ID;
            Util::mwMkdir($tmoDirName);
            chown($tmoDirName, 'www');
            if (file_exists($tmoDirName)) {
                $tmpDir = $tmoDirName;
            }

            $downloadCacheDir = $dirsConfig->path('www.downloadCacheDir');
            if (!file_exists($downloadCacheDir)) {
                $downloadCacheDir = '';
            }
        }
        $fileBaseName = md5(microtime(true));
        // "temp-" in the filename is necessary for the file to be automatically deleted after 5 minutes.
        $filename = $tmpDir . '/temp-' . $fileBaseName;
        file_put_contents($filename, $res_data);
        if (!empty($downloadCacheDir)) {
            $linkName = $downloadCacheDir . '/' . $fileBaseName;
            // For automatic file deletion.
            // A file with such a symlink will be deleted after 5 minutes by cron.
            Util::createUpdateSymlink($filename, $linkName, true);
        }
        chown($filename, 'www');
        return $filename;
    }

    /**
     * Get the caller ID for a given number from CRM system
     *
     * @param string $number The phone number.
     * @return array.
     */
    public static function getCallerId(string $number): array
    {
        $result = [];
        $getNumberUrl = 'http://127.0.0.1:8224/getcallerid?number=' . $number;
        try {
            $client = new Client();
            $result = $client->get($getNumberUrl, [
                'connect_timeout' => 1.0,
                'timeout'         => 1.0,
            ]);
            $resultArray = json_decode($result->getBody(), true);
            if(($resultArray['result']??'') === 'Success'){
                $result = $resultArray['data'];
            }
        } catch (GuzzleException $e) {
            unset($e);
        }
        return $result;
    }

    public function startFindClientByPhone(string $number):void
    {
        $this->logger->writeInfo("Find phone $number...");

        $number = self::getPhoneIndex($number);
        $data = PhoneBook::findFirst(['conditions' => 'number = :number:', 'bind' => ['number' => $number]]);
        if(!$data || (time() - $data->changed > 3600)){
            $remData = self::getCallerId($number);
            $this->logger->writeInfo(["Not found phone $number... get from CRM", $remData]);
        }
        if(!empty($remData)){
            if(!$data){
                $this->logger->writeInfo(["Add new contact $number..."]);

                $data = new PhoneBook();
                $data->number = $number;
                $data->created = time();
            }
            $data->changed  = time();
            $data->client   = $remData['client']??'';
            $data->contact  = $remData['contact']??'';
            $data->ref      = $remData['ref']??'';
            $data->is_employee = ($remData['is_employee']??'')?1:0;
            $data->number_rep = $remData['number_format']??'';
            $data->save();

            $this->logger->writeInfo(["publishContactData $number..."]);
        }

        if($data){
            ApiController::publishContactData($data->toArray());
        }
    }
}

if(isset($argv) && count($argv) !== 1
    && Util::getFilePathByClassName(ConnectorDB::class) === $argv[0]){
    ConnectorDB::startWorker($argv??[]);
}
