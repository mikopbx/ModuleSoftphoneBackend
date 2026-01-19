<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by MikoPBX Team
 *
 */

namespace Modules\ModuleSoftphoneBackend\Lib;

use MikoPBX\Common\Models\Fail2BanRules;
use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Configs\NginxConf;
use MikoPBX\Core\System\System;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use Modules\ModuleSoftphoneBackend\bin\ConnectorDB;
use Modules\ModuleSoftphoneBackend\Lib\RestAPI\Controllers\ApiController;

/**
 * Softphone Backend Configuration
 * Implements core configuration and REST API routing for the module
 */
class SoftphoneBackendConf extends ConfigClass
{
    public const MODULE_ID = 'ModuleSoftphoneBackend';
    
    // JWT Configuration
    public const JWT_ALGORITHM = 'HS256';
    public const ACCESS_TOKEN_EXPIRY = 3600;      // 1 hour
    public const REFRESH_TOKEN_EXPIRY = 2592000;  // 30 days
    
    // Security
    // Note: Secret file stored in /db (moduleDir/db) not /var/etc
    // because /var/etc is deleted on MikoPBX reboot
    public const SECRET_FILE_NAME = 'secret.key';
    public const MAX_LOGIN_ATTEMPTS = 5;
    public const LOCKOUT_TIME = 900;  // 15 minutes
    
    // Logging - will be set dynamically in getLogDir()
    public const SECURITY_LOG_FILE = 'security.log';
    
    // Cache TTL
    public const CACHE_TTL = 300;  // 5 minutes
    
    /**
     * Get log directory path
     * Uses System::getLogDir() to get configured log directory
     * 
     * @return string
     */
    public static function getLogDir(): string
    {
        $logDir = System::getLogDir() . '/' . self::MODULE_ID . '/';
        if (!is_dir($logDir)) {
            Util::mwMkdir($logDir);
            Util::addRegularWWWRights($logDir);
        }
        return $logDir;
    }

    /**
     * Get module data directory path (persistent storage)
     * Used for storing module configuration, cache, and persistent data
     * 
     * @return string
     */
    public function getDataDir(): string
    {
        $dataDir = $this->moduleDir . '/db';
        if (!is_dir($dataDir)) {
            Util::mwMkdir($dataDir);
        }
        return $dataDir;
    }

    /**
     * Get temporary/cache directory path
     * Uses configured temp directory from system config
     * 
     * @return string
     */
    public function getTempDir(): string
    {
        $dirsConfig = $this->di->getShared('config');
        $tempDir = $dirsConfig->path('core.tempDir') . '/' . self::MODULE_ID;
        if (!is_dir($tempDir)) {
            Util::mwMkdir($tempDir, true);
        }
        if (!file_exists($tempDir)) {
            $tempDir = '/tmp/';
        }
        return $tempDir;
    }

    /**
     * Get security log file path
     * 
     * @return string
     */
    public static function getSecurityLogPath(): string
    {
        return self::getLogDir() . self::SECURITY_LOG_FILE;
    }

    /**
     * Get secret key file path
     * Stored in /db directory to persist across reboots
     * 
     * @return string
     */
    public function getSecretKeyPath(): string
    {
        return $this->getDataDir() . '/' . self::SECRET_FILE_NAME;
    }

    /**
     * Register REST API routes for the module
     * Maps URL endpoints to API controller actions
     * 
     * Format: [ControllerClass, 'actionMethod', '/url/path', 'http_method', '/', is_public]
     * 
     * @return array[] REST API routes
     */
    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            // Authentication endpoints (public)
            [ApiController::class, 'loginAction', '/pbxcore/api/module-softphone-backend/v1/auth/login', 'post', '/', true],
            [ApiController::class, 'refreshAction', '/pbxcore/api/module-softphone-backend/v1/auth/refresh', 'post', '/', true],
            
            // Public health check
            [ApiController::class, 'healthAction', '/pbxcore/api/module-softphone-backend/v1/health', 'get', '/', true],
            [ApiController::class, 'checkMediaAccessAction', '/pbxcore/api/module-softphone-backend/v1/check-media-access', 'get', '/', true],

            // Protected endpoints (require authentication)
            [ApiController::class, 'getUsers', '/pbxcore/api/module-softphone-backend/v1/users', 'get', '/', true],
            [ApiController::class, 'getHistory', '/pbxcore/api/module-softphone-backend/v1/history', 'get', '/', true],
            [ApiController::class, 'profileAction', '/pbxcore/api/module-softphone-backend/v1/profile', 'get', '/', true],
            [ApiController::class, 'logoutAction', '/pbxcore/api/module-softphone-backend/v1/auth/logout', 'post', '/', true],
        ];
    }

    /**
     * Process after enable action in web interface
     * Called when module is enabled from PBX admin panel
     *
     * @return void
     */
    public function onAfterModuleEnable(): void
    {
        // Verify or generate secret key (stored in /db, not /var/etc)
        // /var/etc is deleted on MikoPBX reboot, so we store in /db
        $secretKeyPath = $this->getSecretKeyPath();
        if (!file_exists($secretKeyPath)) {
            $secret = bin2hex(random_bytes(32));
            file_put_contents($secretKeyPath, $secret, LOCK_EX);
            chmod($secretKeyPath, 0600);
            chown($secretKeyPath, 'www');
        }
    }

    /**
     * Process after disable action in web interface
     * Called when module is disabled from PBX admin panel
     *
     * @return void
     */
    public function onAfterModuleDisable(): void
    {
        // Module cleanup if needed
        // Currently no cleanup required
        $nginxConf = new NginxConf();
        $nginxConf->generateConf();
        $nginxConf->reStart();
    }

    /**
     * Create nginx location blocks for JWT-protected resources
     * 1. Media streaming (MP3 files)
     * 2. WebSocket proxying with JWT query parameter verification
     * 
     * All auth failures are logged via check-media-access API endpoint
     * 
     * @return string Nginx configuration string
     */
    public function createNginxLocations(): string
    {

        // Nchan channels:
        // - Publisher: local-only, no JWT
        // - Subscriber: JWT protected (token via query for WS/EventSource)
        // Channel name is taken from URI and restricted to [a-z0-9-], length 1..64.
        // Special channel settings:
        // - contacts: keep last 200 events
        return 'location = /pbxcore/api/module-softphone-backend/v1/pub/contacts {'.PHP_EOL."\t".
            'nchan_publisher;'.PHP_EOL."\t".
            'allow  127.0.0.1;'.PHP_EOL."\t".
            'deny all;'.PHP_EOL."\t".
            'nchan_channel_id "contacts";'.PHP_EOL."\t".
            'nchan_message_buffer_length 200;'.PHP_EOL."\t".
            'nchan_message_timeout 300m;'.PHP_EOL.
            '}'.PHP_EOL.
            PHP_EOL.
            'location = /pbxcore/api/module-softphone-backend/v1/sub/contacts {'.PHP_EOL.
            '    nchan_subscriber;'.PHP_EOL.
            '    nchan_channel_id "contacts";'.PHP_EOL.
            '    nchan_message_buffer_length 200;'.PHP_EOL.
            '    access_by_lua_block {'.PHP_EOL.
            '        -- For WS/EventSource token is usually passed via query param'.PHP_EOL.
            '        local token = ngx.var.arg_authorization or ngx.var.arg_token or ngx.var.http_authorization'.PHP_EOL.
            '        local client_ip = ngx.var.remote_addr'.PHP_EOL.
            PHP_EOL.
            '        if not token or token == "" then'.PHP_EOL.
            '            ngx.log(ngx.ERR, "NCHAN_SUB_ACCESS_DENIED: No token from ", client_ip)'.PHP_EOL.
            '            ngx.exit(401)'.PHP_EOL.
            '            return'.PHP_EOL.
            '        end'.PHP_EOL.
            PHP_EOL.
            '        -- Support both "Bearer <jwt>" and "<jwt>"'.PHP_EOL.
            '        local raw_token = token'.PHP_EOL.
            '        if string.match(token, "^Bearer%s+") then'.PHP_EOL.
            '            raw_token = string.gsub(token, "^Bearer%s+", "")'.PHP_EOL.
            '        end'.PHP_EOL.
            PHP_EOL.
            '        if not raw_token or raw_token == "" then'.PHP_EOL.
            '            ngx.log(ngx.ERR, "NCHAN_SUB_ACCESS_DENIED: Empty token from ", client_ip)'.PHP_EOL.
            '            ngx.exit(401)'.PHP_EOL.
            '            return'.PHP_EOL.
            '        end'.PHP_EOL.
            PHP_EOL.
            '        local res = ngx.location.capture("/internal/check-jwt-verify", {'.PHP_EOL.
            '            method = ngx.HTTP_GET,'.PHP_EOL.
            '            args = ngx.encode_args({ token = raw_token })'.PHP_EOL.
            '        })'.PHP_EOL.
            PHP_EOL.
            '        if res.status ~= 200 then'.PHP_EOL.
            '            ngx.log(ngx.ERR, "NCHAN_SUB_ACCESS_DENIED: Invalid token from ", client_ip)'.PHP_EOL.
            '            ngx.exit(401)'.PHP_EOL.
            '            return'.PHP_EOL.
            '        end'.PHP_EOL.
            '        ngx.log(ngx.INFO, "NCHAN_SUB_ACCESS_GRANTED: ", client_ip, " subscribed ", ngx.var.request_uri)'.PHP_EOL.
            '    }'.PHP_EOL.
            '}'.PHP_EOL.
            PHP_EOL.
            'location ~ /pbxcore/api/module-softphone-backend/v1/pub/(.*)$ {'.PHP_EOL."\t".
            'nchan_publisher;'.PHP_EOL."\t".
            'allow  127.0.0.1;'.PHP_EOL."\t".
            'deny all;'.PHP_EOL."\t".
            'nchan_channel_id "$1";'.PHP_EOL."\t".
            'nchan_message_buffer_length 1;'.PHP_EOL."\t".
            'nchan_message_timeout 300m;'.PHP_EOL.
            '}'.PHP_EOL.
            PHP_EOL.
            'location ~ /pbxcore/api/module-softphone-backend/v1/sub/(.*)$ {'.PHP_EOL.
            '    nchan_subscriber;'.PHP_EOL.
            '    nchan_channel_id "$1";'.PHP_EOL.
            '    access_by_lua_block {'.PHP_EOL.
            '        -- For WS/EventSource token is usually passed via query param'.PHP_EOL.
            '        local token = ngx.var.arg_authorization or ngx.var.arg_token or ngx.var.http_authorization'.PHP_EOL.
            '        local client_ip = ngx.var.remote_addr'.PHP_EOL.
            PHP_EOL.
            '        if not token or token == "" then'.PHP_EOL.
            '            ngx.log(ngx.ERR, "NCHAN_SUB_ACCESS_DENIED: No token from ", client_ip)'.PHP_EOL.
            '            ngx.exit(401)'.PHP_EOL.
            '            return'.PHP_EOL.
            '        end'.PHP_EOL.
            PHP_EOL.
            '        -- Support both "Bearer <jwt>" and "<jwt>"'.PHP_EOL.
            '        local raw_token = token'.PHP_EOL.
            '        if string.match(token, "^Bearer%s+") then'.PHP_EOL.
            '            raw_token = string.gsub(token, "^Bearer%s+", "")'.PHP_EOL.
            '        end'.PHP_EOL.
            PHP_EOL.
            '        if not raw_token or raw_token == "" then'.PHP_EOL.
            '            ngx.log(ngx.ERR, "NCHAN_SUB_ACCESS_DENIED: Empty token from ", client_ip)'.PHP_EOL.
            '            ngx.exit(401)'.PHP_EOL.
            '            return'.PHP_EOL.
            '        end'.PHP_EOL.
            PHP_EOL.
            '        local res = ngx.location.capture("/internal/check-jwt-verify", {'.PHP_EOL.
            '            method = ngx.HTTP_GET,'.PHP_EOL.
            '            args = ngx.encode_args({ token = raw_token })'.PHP_EOL.
            '        })'.PHP_EOL.
            PHP_EOL.
            '        if res.status ~= 200 then'.PHP_EOL.
            '            ngx.log(ngx.ERR, "NCHAN_SUB_ACCESS_DENIED: Invalid token from ", client_ip)'.PHP_EOL.
            '            ngx.exit(401)'.PHP_EOL.
            '            return'.PHP_EOL.
            '        end'.PHP_EOL.
            '        ngx.log(ngx.INFO, "NCHAN_SUB_ACCESS_GRANTED: ", client_ip, " subscribed ", ngx.var.request_uri)'.PHP_EOL.
            '    }'.PHP_EOL.
            '}'.PHP_EOL.
            "# ==================== MEDIA STREAMING ====================\n" .
            "location /pbxcore/softphone/recordings/ {\n" .
            "    alias /storage/usbdisk1/mikopbx/astspool/monitor/;\n" .
            "    \n" .
            "    access_by_lua_block {\n" .
            "        local token = ngx.var.http_authorization\n" .
            "        local client_ip = ngx.var.remote_addr\n" .
            "        \n" .
            "        if not token or token == \"\" then\n" .
            "            ngx.log(ngx.ERR, 'MEDIA_ACCESS_DENIED: No token from ', client_ip)\n" .
            "            ngx.exit(401)\n" .
            "            return\n" .
            "        end\n" .
            "        -- Check token format: Bearer {token}\n" .
            "        if not string.match(token, \"^Bearer \") then\n" .
            "            ngx.log(ngx.ERR, 'MEDIA_ACCESS_DENIED: Invalid token format from ', client_ip)\n" .
            "            ngx.exit(401)\n" .
            "            return\n" .
            "        end\n" .
            "        -- Extract raw JWT without \"Bearer \" prefix\n" .
            "        local raw_token = string.gsub(token, \"^Bearer%s+\", \"\")\n" .
            "        -- Make simple HTTP request using ngx.location.capture\n" .
            "        local res = ngx.location.capture('/internal/check-jwt-verify', {\n" .
            "            method = ngx.HTTP_GET,\n" .
            "            args = ngx.encode_args({ token = raw_token })\n" .
            "        })\n" .
            "        if res.status ~= 200 then\n" .
            "            ngx.log(ngx.ERR, 'MEDIA_ACCESS_DENIED: Invalid token from ', client_ip)\n" .
            "            ngx.exit(401)\n" .
            "            return\n" .
            "        end\n" .
            "        ngx.log(ngx.INFO, 'MEDIA_ACCESS_GRANTED: ', client_ip, ' accessed ', ngx.var.request_uri)\n" .
            "    }\n" .
            "    \n" .
            "    expires 7d;\n" .
            "    add_header Cache-Control \"private, max-age=604800\";\n" .
            "    types {\n" .
            "        audio/mpeg mp3;\n" .
            "    }\n" .
            "    add_header Content-Disposition \"inline\";\n" .
            "    location ~ \\.php$ {\n" .
            "        return 403;\n" .
            "    }\n" .
            "}\n" .
            "\n" .
            "# ==================== WEBSOCKET PROXYING ====================\n" .
            "location /pbxcore/softphone/ws {\n" .
            "    access_by_lua_block {\n" .
            "        local token = ngx.var.arg_authorization\n" .
            "        local client_ip = ngx.var.remote_addr\n" .
            "        \n" .
            "        if not token or token == \"\" then\n" .
            "            ngx.log(ngx.ERR, 'WEBSOCKET_ACCESS_DENIED: No token from ', client_ip)\n" .
            "            ngx.exit(401)\n" .
            "            return\n" .
            "        end\n" .
            "        -- Support both \"Bearer <jwt>\" and \"<jwt>\"\n" .
            "        local raw_token = token\n" .
            "        if string.match(token, \"^Bearer%s+\") then\n" .
            "            raw_token = string.gsub(token, \"^Bearer%s+\", \"\")\n" .
            "        end\n" .
            "        -- Verify JWT token via internal endpoint\n" .
            "        local res = ngx.location.capture('/internal/check-jwt-verify', {\n" .
            "            method = ngx.HTTP_GET,\n" .
            "            args = ngx.encode_args({ token = raw_token })\n" .
            "        })\n" .
            "        if res.status ~= 200 then\n" .
            "            ngx.log(ngx.ERR, 'WEBSOCKET_ACCESS_DENIED: Invalid token from ', client_ip)\n" .
            "            ngx.exit(401)\n" .
            "            return\n" .
            "        end\n" .
            "        ngx.log(ngx.INFO, 'WEBSOCKET_ACCESS_GRANTED: ', client_ip, ' connected')\n" .
            "    }\n" .
            "    \n" .
            "    proxy_pass http://127.0.0.1:".PbxSettings::getValueByKey('AJAMPort')."/asterisk/ws;\n" .
            "    proxy_http_version 1.1;\n" .
            "    proxy_set_header Upgrade \$http_upgrade;\n" .
            "    proxy_set_header Connection \"upgrade\";\n" .
            "    proxy_set_header Host \$host;\n" .
            "    proxy_set_header X-Real-IP \$remote_addr;\n" .
            "    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n" .
            "    proxy_read_timeout 86400;\n" .
            "}\n" .
            "\n" .
            "# ==================== INTERNAL JWT VERIFICATION ====================\n" .
            "location /internal/check-jwt-verify {\n" .
            "    internal;\n" .
            "    proxy_pass http://127.0.0.1/pbxcore/api/module-softphone-backend/v1/check-media-access;\n" .
            "    proxy_pass_request_body off;\n" .
            "    proxy_set_header Authorization \"Bearer \$arg_token\";\n" .
            "    proxy_set_header Content-Length \"\";\n" .
            "}\n";
    }

    /**
     * Generates additional fail2ban jail conf rules
     * Detects auth failures for:
     * - REST API login attempts
     * - Media file access attempts
     * - WebSocket connection attempts
     *
     * @return string
     */
    public function generateFail2BanFilters(): string
    {
        return "[INCLUDES]\n" .
            "before = common.conf\n" .
            "[Definition]\n" .
            "_daemon = \S*\n" .
            "failregex = ^%(__prefix_line)s\\[AUTH_FAILED\\].*From:\\s+<HOST>.*$\n" .
            // REST API: Invalid credentials
            "             ^%(__prefix_line)s\\[MEDIA_ACCESS_DENIED\\].*From:\\s+<HOST>.*$\n" .
            // Nginx Lua: Media streaming access denied
            "             ^%(__prefix_line)s.*MEDIA_ACCESS_DENIED:.*from\\s+<HOST>\\s*$\n" .
            // Nginx Lua: WebSocket access denied
            "             ^%(__prefix_line)s.*WEBSOCKET_ACCESS_DENIED:.*from\\s+<HOST>\\s*$\n" .
            // Nginx Lua: Nchan subscriber access denied
            "             ^%(__prefix_line)s.*NCHAN_SUB_ACCESS_DENIED:.*from\\s+<HOST>\\s*$\n" .
            // Legacy format: From + UserAgent + Fail auth
            "             ^%(__prefix_line)sFrom\\s+<HOST>.\\s+UserAgent:\\s+[a-zA-Z0-9 \\s\\.,:;\\+\\-_\\)\\(\\[\\]]*.*Fail\\s+auth\\s+http.$\n" .
            // Legacy format: From + UserAgent + File not found
            "             ^%(__prefix_line)sFrom\\s+<HOST>.\\s+UserAgent:\\s+[a-zA-Z0-9 \\s\\.,:;\\+\\-_\\)\\(\\[\\]]*.*File\\s+not\\s+found.$\n" .
            "ignoreregex =\n";
    }

    /**
     * Generates additional fail2ban jail conf rules
     * Detects auth failures for:
     * - REST API login attempts
     * - Media file access attempts
     * - WebSocket connection attempts
     *
     * @return string
     */
    public function generateFail2BanJails(): string
    {
        $textClass = MikoPBXVersion::getTextClass();
        $fileName = $textClass::uncamelize($this->moduleUniqueId, '_');
        [$max_retry, $find_time, $ban_time] = self::initProperty();
        $logDir = System::getLogDir();
        return  "[$fileName]".PHP_EOL.
                "enabled = true".PHP_EOL.
                "logpath = $logDir/system/messages".PHP_EOL.
                "          $logDir/nginx/error.log".PHP_EOL.
                "maxretry = $max_retry".PHP_EOL.
                "findtime = $find_time".PHP_EOL.
                "bantime = $ban_time".PHP_EOL.
                "logencoding = utf-8".PHP_EOL.
                "action = iptables-allports[name=$this->moduleUniqueId, protocol=all]";
    }

    public static function initProperty(): array
    {
        // Find the first rule with id '1'.
        /** @var Fail2BanRules $res */
        $res = Fail2BanRules::findFirst("id = '1'");

        // If rule exists, extract its properties.
        if ($res !== null) {
            $max_retry = (int) $res->maxretry;
            $find_time = (int) $res->findtime;
            $ban_time = (int) $res->bantime;
        } else {
            // If rule doesn't exist, use default values.
            $max_retry = 10;
            $find_time = 1800;
            $ban_time = 43200;
        }

        // Return an array of the properties.
        return array($max_retry, $find_time, $ban_time);
    }

    /**
     * Customize the incoming route context for a specific route
     *
     * @param string $rout_number The route number
     * @return string The generated incoming route context
     */
    public function generateIncomingRoutBeforeDial($rout_number): string
    {
        return "same => n,AGI($this->moduleDir/agi-bin/notify.php,in)" . PHP_EOL;
    }

    /**
     * Generate the outgoing route context for a specific route
     *
     * @param array $rout The route configuration array
     * @return string The generated outgoing route context
     */
    public function generateOutRoutContext(array $rout): string
    {
        return "same => n,AGI($this->moduleDir/agi-bin/notify.php,out)" . PHP_EOL;
    }

    /**
     * Returns module workers to start it at WorkerSafeScriptCore
     *
     * @return array
     */
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_PID_NOT_ALERT,
                'worker' => ConnectorDB::class,
            ],
        ];
    }

    /**
     * @param array $tasks
     */
    public function createCronTasks(array &$tasks): void
    {
        $tmpDir = $this->di->getShared('config')->path('core.tempDir') . '/'.self::MODULE_ID;
        $findPath   = Util::which('find');
        $tasks[]    = "*/1 * * * * $findPath $tmpDir -mmin +1 -type f -delete> /dev/null 2>&1".PHP_EOL;

        $phpPath   = Util::which('php');
        $tasks[]    = "*/1 * * * * $phpPath -f {$this->moduleDir}/bin/safe.php > /dev/null 2>&1".PHP_EOL;
    }
}

