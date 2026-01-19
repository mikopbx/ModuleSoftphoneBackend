<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by MikoPBX Team
 *
 */

namespace Modules\ModuleSoftphoneBackend\Lib\RestAPI\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\Sip;
use MikoPBX\Common\Providers\LoggerProvider;
use MikoPBX\Core\System\Util;
use MikoPBX\PBXCoreREST\Controllers\Modules\ModulesControllerBase;
use Modules\ModuleExtendedCDRs\Lib\GetReport;
use Modules\ModuleSoftphoneBackend\bin\ConnectorDB;
use Modules\ModuleSoftphoneBackend\Lib\AuthenticationProvider;
use Modules\ModuleSoftphoneBackend\Lib\CacheManager;
use Modules\ModuleSoftphoneBackend\Lib\JwtTokenManager;
use Modules\ModuleSoftphoneBackend\Lib\SoftphoneBackendConf;
use Throwable;

/**
 * API Controller - REST endpoint for Softphone Backend
 * Provides proper JWT Bearer token authentication
 */
class ApiController extends ModulesControllerBase
{
    private JwtTokenManager $tokenManager;
    private AuthenticationProvider $authProvider;
    private array $logger;

    /**
     * Internal endpoint для проверки доступа к медиа (для auth_request)
     * Возвращает 200 если JWT валиден, 401 если нет
     * 
     * Используется nginx для проверки авторизации перед:
     * - Раздачей MP3 файлов (/pbxcore/softphone/recordings/)
     * - Проксированием WebSocket (/pbxcore/softphone/ws)
     * 
     * curl 'http://127.0.0.1/pbxcore/api/module-softphone-backend/v1/check-media-access' \
     * -H 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'
     */
    public function checkMediaAccessAction(): void
    {
        $this->initialize();
        $headers = $this->getAuthorizationHeaders();

        // Проверить JWT
        if (!$this->authProvider->authenticate($headers)) {
            // Логировать попытку доступа без валидного токена
            $this->logSecurityEvent(
                'MEDIA_ACCESS_DENIED',
                'Unauthorized access attempt to media resource'
            );
            // auth_request получит 401 и nginx не раздаст файл
            $this->sendErrorResponse(401, 'Unauthorized');
            return;
        }

        // Токен валиден - auth_request успешен, nginx раздаст файл
        $this->logSecurityEvent(
            'MEDIA_ACCESS_GRANTED',
            "User {$this->authProvider->getCredentials()['username']} accessed media resource"
        );
        $this->sendResponse(['success' => true]);
    }

    public function initialize(): void
    {
        
        // Initialize JWT token manager with system secret
        $secret = $this->getSystemSecret();
        $this->tokenManager = new JwtTokenManager($secret);
        $this->authProvider = new AuthenticationProvider($this->tokenManager);
        $this->logger = [];
    }

    /**
     * Authentication endpoint - issues tokens
       curl 'http://127.0.0.1/pbxcore/api/module-softphone-backend/v1/auth/login' \
       -H 'Content-Type: application/json' \
       --data-raw '{"username":"204","password":"aa3684e"}';
     */
    public function loginAction(): void
    {
        $this->initialize();
        try {
            $data = $this->request->getJsonRawBody(true);

            // Validate input
            if (empty($data['username']) || empty($data['password'])) {
                $this->logSecurityEvent(
                    'AUTH_FAILED',
                    "Failed login attempt for empty user"
                );
                $this->sendErrorResponse(400, 'Invalid credentials provided');
                return;
            }

            // Authenticate user (implement your logic here)
            $userId = $this->authenticateUser($data['username'], $data['password']);

            if (!$userId) {
                $this->logSecurityEvent(
                    'AUTH_FAILED',
                    "Failed login attempt for user: {$data['username']}"
                );
                $this->sendErrorResponse(401, 'Invalid credentials');
                return;
            }
            $response = $this->createLoginResponse($userId, $data['username']);
            $this->sendResponse($response);
        } catch (Throwable $e) {
            $this->sendErrorResponse(500, 'Internal server error'.$e->getMessage());
        }
    }

    public function createLoginResponse($userId = '1', string $username = 'admin'): array
    {
        $payload = [
            'sub' => $userId,
            'username' => $username,
            'role' => 'user'
        ];
        $accessToken  = $this->tokenManager->createAccessToken($payload);
        $refreshToken = $this->tokenManager->createRefreshToken($payload);

        $this->logSecurityEvent(
            'AUTH_SUCCESS',
            "User $username logged in successfully"
        );
        return [
            'success' => true,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600
        ];
    }

    /**
     * Token refresh endpoint
     * POST /pbxcore/api/module-softphone-backend/v1/auth/refresh
     * 
     * Header: Authorization: Bearer {refresh_token}
     * 
     * Response (200):
     * {
     *   "access_token": "eyJhbGciOiJIUzI1NiIs...",
     *   "token_type": "Bearer",
     *   "expires_in": 3600
     * }
     */
    public function refreshAction(): void
    {
        $this->initialize();
        // Get headers from request
        $headers = $this->getAuthorizationHeaders();

        // Authenticate with refresh token
        if (!$this->authProvider->authenticate($headers)) {
            $this->sendErrorResponse(401, 'Invalid or missing token');
            return;
        }

        // Verify it's a refresh token
        if (!$this->authProvider->isRefreshToken()) {
            $this->sendErrorResponse(403, 'Invalid token type. Refresh token required');
            return;
        }

        // Create new access token
        $credentials = $this->authProvider->getCredentials();
        $payload = [
            'sub' => $credentials['sub'] ?? null,
            'username' => $credentials['username'] ?? null,
            'role' => $credentials['role'] ?? 'user'
        ];

        if (!$payload['sub']) {
            $this->sendErrorResponse(500, 'Internal server error');
            return;
        }

        $accessToken = $this->tokenManager->createAccessToken($payload);

        $response = [
            'success' => true,
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600
        ];

        $this->sendResponse($response);
    }

    /**
     * Protected endpoint example - get user profile
     * GET /pbxcore/api/module-softphone-backend/v1/profile
     * 
     * Header: Authorization: Bearer {access_token}
     */
    public function profileAction(): void
    {
        $this->initialize();
        $headers = $this->getAuthorizationHeaders();

        if (!$this->authProvider->authenticate($headers)) {
            $this->sendErrorResponse(401, 'Unauthorized. Token required');
            return;
        }

        if (!$this->authProvider->isAccessToken()) {
            $this->sendErrorResponse(403, 'Invalid token type');
            return;
        }

        $userId = $this->authProvider->getUserId();
        $credentials = $this->authProvider->getCredentials();

        $response = [
            'success' => true,
            'user' => [
                'id' => $userId,
                'username' => $credentials['username'] ?? null,
                'role' => $this->authProvider->getUserRole()
            ]
        ];

        $this->sendResponse($response);
    }

    /**
     * Protected endpoint example - get user profile
     * GET /pbxcore/api/module-softphone-backend/v1/users
     * curl http://localhost/pbxcore/api/module-softphone-backend/v1/users   -H "Authorization: Bearer $ACCESS_TOKEN"
     * Header: Authorization: Bearer {access_token}
     */
    public function getUsers(): void
    {
        $this->initialize();
        $headers = $this->getAuthorizationHeaders();

        if (!$this->authProvider->authenticate($headers)) {
            $this->sendErrorResponse(401, 'Unauthorized. Token required');
            return;
        }

        if (!$this->authProvider->isAccessToken()) {
            $this->sendErrorResponse(403, 'Invalid token type');
            return;
        }

        $userId      = $this->authProvider->getUserId();
        $credentials = $this->authProvider->getCredentials();

        $response = [
            'success' => true,
            'user' => [
                'id' => $userId,
                'username' => $credentials['username'] ?? null,
                'role' => $this->authProvider->getUserRole()
            ],
            'statuses' => CacheManager::getCacheData('getUsersStates')
        ];

        $this->sendResponse($response);
    }

    /**
     * Protected endpoint example - get user profile
     * GET /pbxcore/api/module-softphone-backend/v1/history
     * curl http://localhost/pbxcore/api/module-softphone-backend/v1/history   -H "Authorization: Bearer $ACCESS_TOKEN"
     * Header: Authorization: Bearer {access_token}
     */
    public function getHistory(): void
    {
        $this->initialize();
        $headers = $this->getAuthorizationHeaders();

        if (!$this->authProvider->authenticate($headers)) {
            $this->sendErrorResponse(401, 'Unauthorized. Token required');
            return;
        }

        if (!$this->authProvider->isAccessToken()) {
            $this->sendErrorResponse(403, 'Invalid token type');
            return;
        }

        $userId      = $this->authProvider->getUserId();
        $credentials = $this->authProvider->getCredentials();

        if(class_exists('\Modules\ModuleExtendedCDRs\Lib\GetReport')){
            // Получаем дату из запроса
            $inputDate = $this->request->get('date');
            if ($inputDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $inputDate)) {
                $date = \DateTime::createFromFormat('Y-m-d', $inputDate);
                if (!$date || $date->format('Y-m-d') !== $inputDate) {
                    // Некорректная дата — используем сегодня
                    $date = new \DateTime();
                }
            } else {
                // Пустая или неверного формата — используем сегодня
                $date = new \DateTime();
            }
            $dataStart = $date->format('d/m/Y');
            $dateEnd = clone $date;
            $dateEnd->modify('+1 day');
            $dataEnd = $dateEnd->format('d/m/Y');

            $filter = [
                'dateRangeSelector' => "$dataStart - $dataEnd",
                'minBilSec' => '0',
                'globalSearch' => $credentials['username']??'',
                'typeCall' => 'all-calls',
                'additionalFilter' => ''
            ];
            $gr = new GetReport();
            $view = $gr->history(json_encode($filter, JSON_UNESCAPED_SLASHES));
            $numbers = [];

            // $view format may be:
            // - ['recordsFiltered' => ..., 'data' => [ ...rows... ], ...]
            // - [ ...rows... ]
            // Each row is an array where:
            // - [1] = src number
            // - [2] = dst number
            // - [4] = legs array (each leg has src_num/dst_num)
            if (is_object($view)) {
                $view = (array)$view;
            }

            $rows = [];
            if (is_array($view) && isset($view['data']) && is_array($view['data'])) {
                $rows = $view['data'];
            } elseif (is_array($view)) {
                $rows = $view;
            }

            $addNumber = static function (array &$numbers, $value): void {
                if (!is_string($value) && !is_int($value)) {
                    return;
                }
                $value = (string)$value;
                if ($value === '') {
                    return;
                }
                $idx = ConnectorDB::getPhoneIndex($value);
                if ($idx !== '') {
                    $numbers[$idx] = '';
                }
            };

            foreach ($rows as $tmpCdr) {
                if (!is_array($tmpCdr)) {
                    continue;
                }

                $addNumber($numbers, $tmpCdr[1] ?? null);
                $addNumber($numbers, $tmpCdr[2] ?? null);

                $legs = $tmpCdr[4] ?? null;
                if (!is_array($legs)) {
                    continue;
                }

                foreach ($legs as $leg) {
                    if (!is_array($leg)) {
                        continue;
                    }
                    $addNumber($numbers, $leg['dst_num'] ?? null);
                    $addNumber($numbers, $leg['src_num'] ?? null);
                }
            }

            $numbers = array_keys($numbers);
            foreach ($numbers as $number){
                ConnectorDB::invoke('startFindClientByPhone', [$number], false);
            }
        }else{
            $view = [];
        }
        $response = [
            'success' => true,
            'user' => [
                'id' => $userId,
                'username' => $credentials['username'] ?? null,
                'role' => $this->authProvider->getUserRole(),
                'mobile' => $this->getMobileNumber($credentials['username']??'')
            ],
            'history' => $view
        ];
        $this->sendResponse($response);
    }

    private function getMobileNumber(string $innerNumber): string
    {
        $mobile = '';
        $filter = [
            'number=:number:',
            'bind' => [
                'number' => $innerNumber
            ],
            'columns' => 'userid'
        ];
        $extenUserId = Extensions::findFirst($filter);
        if($extenUserId){
            $filter=[
                'userid=:userid: AND type=:type:',
                'bind' => [
                    'userid' => $extenUserId->userid,
                    'type'=> Extensions::TYPE_EXTERNAL
                ],
                'columns' => 'number'
            ];
            $extenNumber = Extensions::findFirst($filter);
            if($extenNumber){
                $mobile = substr($extenNumber->number, -10);
            }
        }
        return $mobile;
    }

    /**
     * Health check endpoint - no authentication required
     * GET /pbxcore/api/module-softphone-backend/v1/health
     */
    public function healthAction(): void
    {
        $this->initialize();
        $response = [
            'success' => true,
            'status' => 'ok',
            'timestamp' => time()
        ];

        $this->sendResponse($response);
    }

    /**
     * Logout endpoint
     * POST /pbxcore/api/module-softphone-backend/v1/auth/logout
     * 
     * Header: Authorization: Bearer {access_token}
     */
    public function logoutAction(): void
    {
        $this->initialize();
        $headers = $this->getAuthorizationHeaders();

        if (!$this->authProvider->authenticate($headers)) {
            $this->sendErrorResponse(401, 'Unauthorized');
            return;
        }

        $userId = $this->authProvider->getUserId();
        $this->logSecurityEvent(
            'LOGOUT',
            "User ID {$userId} logged out"
        );

        $response = [
            'success' => true,
            'message' => 'Logged out successfully'
        ];

        $this->sendResponse($response);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Extract authorization headers from request
     */
    private function getAuthorizationHeaders(): array
    {
        return [
            'Authorization' => $this->request->getHeader('Authorization') ?? ''
        ];
    }

    /**
     * Authenticate user (implement your auth logic)
     * This is a placeholder - replace with actual user verification
     */
    private function authenticateUser(string $username, string $password): ?int
    {
        // This example accepts any non-empty credentials
        if (!empty($username) && !empty($password)) {
            // Return user ID (in real app, fetch from database)
            $filter = [
                'extension=:extension: AND secret=:secret:',
                'bind'=> [
                    'extension' => $username,
                    'secret' => $password
                ]
            ];
            $userData = Sip::findFirst($filter);
            return ($userData)?1:null;
        }

        return null;
    }

    /**
     * Get system secret for token signing
     * Reads from securely stored configuration file in /db
     */
    private function getSystemSecret(): string
    {
        // Get secret key path from config ($moduleDir/db/secret.key)
        // This persists across reboots (not stored in /var/etc)
        $conf = new SoftphoneBackendConf();
        $secretFile = $conf->getSecretKeyPath();

        if (file_exists($secretFile)) {
            $secret = trim(file_get_contents($secretFile));
            if (!empty($secret)) {
                return $secret;
            }
        }

        // Generate and store new secret if not exists
        // This should normally be created in onAfterModuleEnable()
        error_log("Generating new secret at: " . $secretFile);
        $secret = bin2hex(random_bytes(32));
        $dataDir = $conf->getDataDir();
        
        // Ensure directory exists and file is created
        try {
            if (!is_dir($dataDir)) {
                Util::mwMkdir($dataDir);
                error_log("Created directory: " . $dataDir);
            }
            file_put_contents($secretFile, $secret, LOCK_EX);
            @chmod($secretFile, 0600);
            error_log("Secret file created successfully at: " . $secretFile);
        } catch (Throwable $e) {
            error_log("Failed to create secret file: " . $e->getMessage());
        }

        return $secret;
    }

    /**
     * Send success response
     */
    private function sendResponse(array $data): void
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            echo $json;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Response encoding error']);
        }

        $this->response->setContentType('application/json');
        $this->response->sendHeaders();
        $this->response->sendRaw();
    }

    /**
     * Send error response
     */
    private function sendErrorResponse(int $statusCode, string $message): void
    {
        $this->response->setStatusCode($statusCode);

        $response = [
            'success' => false,
            'error' => $message,
            'timestamp' => time()
        ];

        try {
            $json = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            echo $json;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Error']);
        }

        $this->response->setContentType('application/json');
        $this->response->sendHeaders();
        $this->response->sendRaw();
    }

    /**
     * Log security events
     */
    private function logSecurityEvent(string $event, string $message): void
    {
        try {
            $logger = $this->di->getShared(LoggerProvider::SERVICE_NAME);
            $remoteAddress = $this->request->getClientAddress(true);

            $logMessage = "[{$event}] {$message} | From: {$remoteAddress} | Time: " . date('Y-m-d H:i:s');
            $logger->notice($logMessage);
        } catch (Throwable $e) {
            // Silently fail if logger not available
        }
    }


    /**
     * @param $data
     * @return void
     */
    public static function publishUserStates($data):void{
        if(!file_exists('/etc/nginx/mikopbx/modules_locations/ModuleSoftphoneBackend.conf')){
            return;
        }
        try {
            $client = new Client([
                 'base_uri'        => 'http://127.0.0.1/pbxcore/api/module-softphone-backend/v1/',
                 'connect_timeout' => 1.0,
                 'timeout'         => 1.0,
            ]);
            $client->post('pub/users-state', [
                'connect_timeout' => 1.0,
                'timeout'         => 1.0,
                'json' => $data,
            ]);
        } catch (GuzzleException $e) {
            unset($e);
        }
    }

   /**
     * @param $data
     * @return void
     */
    public static function publishActiveCalls($data):void{
        if(!file_exists('/etc/nginx/mikopbx/modules_locations/ModuleSoftphoneBackend.conf')){
            return;
        }
        try {
            $client = new Client([
                 'base_uri'        => 'http://127.0.0.1/pbxcore/api/module-softphone-backend/v1/',
                 'connect_timeout' => 1.0,
                 'timeout'         => 1.0,
            ]);
            $client->post('pub/active-calls', [
                'connect_timeout' => 1.0,
                'timeout'         => 1.0,
                'json' => $data,
            ]);
        } catch (GuzzleException $e) {
            unset($e);
        }
    }

    /**
     * @param $data
     * @return void
     */
    public static function publishContactData($data):void{
        if(!file_exists('/etc/nginx/mikopbx/modules_locations/ModuleSoftphoneBackend.conf')){
            return;
        }
        try {
            $client = new Client([
                                     'base_uri'        => 'http://127.0.0.1/pbxcore/api/module-softphone-backend/v1/',
                                     'connect_timeout' => 1.0,
                                     'timeout'         => 1.0,
                                 ]);
            $client->post('pub/contacts', [
                'connect_timeout' => 1.0,
                'timeout'         => 1.0,
                'json' => $data,
            ]);
        } catch (GuzzleException $e) {
            unset($e);
        }
    }
}

