<?php
/**
 * Smoke tests for ModuleSoftphoneBackend (PHP 7.4+).
 *
 * Usage:
 *   php -f bin/SmokeTest.php -- \
 *     --base-url="http://127.0.0.1/pbxcore/api/module-softphone-backend/v1" \
 *     --username="201" --password="secret"
 *
 * Optional:
 *   --access-token="..."          Skip login and use provided token for /sub/*
 *   --channels="users-state,active-calls,contacts"
 *   --timeout=10                  HTTP timeout in seconds (default: 10)
 *   --max-wait=15                 Max wait (seconds) for expected message on /sub/* (default: 15)
 *   --max-requests=500            Max /sub/* requests per channel while draining buffer (default: 500)
 *   --callerid-number="74952293042" Phone number for ConnectorDB::getCallerId test
 */

declare(strict_types=1);

require_once('Globals.php');
use Modules\ModuleSoftphoneBackend\bin\ConnectorDB;
use MikoPBX\Common\Models\Sip;
use Modules\ModuleSoftphoneBackend\Models\PhoneBook;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(2);
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP cURL extension is required (ext-curl).\n");
    exit(2);
}

/**
 * @param array $argv
 * @return array<string,string>
 */
function parseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $i => $arg) {
        if ($i === 0) {
            continue;
        }
        if ($arg === '--') {
            continue;
        }
        if (strpos($arg, '--') !== 0) {
            continue;
        }
        $eqPos = strpos($arg, '=');
        if ($eqPos === false) {
            $key = substr($arg, 2);
            $args[$key] = '1';
            continue;
        }
        $key = substr($arg, 2, $eqPos - 2);
        $val = substr($arg, $eqPos + 1);
        $args[$key] = $val;
    }
    return $args;
}

/**
 * @param string $name
 * @param mixed $val
 * @return void
 */
function ok(string $name, $val = null): void
{
    if ($val === null) {
        fwrite(STDOUT, "[OK]   {$name}\n");
        return;
    }
    $printed = is_scalar($val) ? (string)$val : json_encode($val, JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, "[OK]   {$name}: {$printed}\n");
}

/**
 * @param string $name
 * @param string $message
 * @return void
 */
function fail(string $name, string $message): void
{
    fwrite(STDERR, "[FAIL] {$name}: {$message}\n");
}

/**
 * @param string $method
 * @param string $url
 * @param array<int,string> $headers
 * @param string|null $body
 * @param int $timeout
 * @return array{status:int, headers:string, body:string, err:string}
 */
function httpRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    $err = '';
    if ($raw === false) {
        $err = curl_error($ch);
        $raw = '';
    }

    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    // curl_close() is deprecated in PHP 8.5 (has no effect since PHP 8.0); suppress noise.
    @curl_close($ch);

    $rawHeaders = (string)substr((string)$raw, 0, $headerSize);
    $rawBody = (string)substr((string)$raw, $headerSize);

    return [
        'status' => $status,
        'headers' => $rawHeaders,
        'body' => $rawBody,
        'err' => $err,
    ];
}

/**
 * Extract header value (best-effort, case-insensitive).
 *
 * @param string $rawHeaders
 * @param string $headerName
 * @return string
 */
function extractHeaderValue(string $rawHeaders, string $headerName): string
{
    $name = preg_quote($headerName, '/');
    if (!preg_match('/^' . $name . ':\\s*([^\\r\\n]+)\\s*$/im', $rawHeaders, $m)) {
        return '';
    }
    $val = trim($m[1]);
    // Strip optional quotes for headers like ETag: "123"
    if (strlen($val) >= 2 && $val[0] === '"' && $val[strlen($val) - 1] === '"') {
        $val = substr($val, 1, -1);
    }
    return $val;
}

/**
 * @param string $json
 * @return array<string,mixed>|null
 */
function tryJsonDecode(string $json): ?array
{
    $json = trim($json);
    if ($json === '') {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    return $data;
}

$args = parseArgs($argv);
$baseUrl = $args['base-url'] ?? 'http://127.0.0.1/pbxcore/api/module-softphone-backend/v1';
$baseUrl = rtrim($baseUrl, '/');
$username = $args['username'] ?? '';
$password = $args['password'] ?? '';

if(empty($username)){
    $res = Sip::findFirst('type="peer"');
    if($res){
        $username = $res->extension;
        $password = $res->secret;
    }
}

$accessToken = $args['access-token'] ?? '';
$timeout = (int)($args['timeout'] ?? 10);
if ($timeout < 1) {
    $timeout = 10;
}
$maxWait = (int)($args['max-wait'] ?? 15);
if ($maxWait < 1) {
    $maxWait = 15;
}
$maxRequests = (int)($args['max-requests'] ?? 500);
if ($maxRequests < 10) {
    $maxRequests = 500;
}

$channelsRaw = $args['channels'] ?? 'users-state,active-calls,contacts';
$channels = array_values(array_filter(array_map('trim', explode(',', $channelsRaw)), static function ($v) {
    return $v !== '';
}));

$callerIdNumber = $args['callerid-number'] ?? '74952293042';

$failed = false;

// 0) Health check
$health = httpRequest('GET', $baseUrl . '/health', ['Accept: application/json'], null, $timeout);
if ($health['err'] !== '') {
    $failed = true;
    fail('health', $health['err']);
} elseif ($health['status'] < 200 || $health['status'] >= 300) {
    $failed = true;
    fail('health', "HTTP {$health['status']} body=" . trim($health['body']));
} else {
    ok('health');
}

// 1) Login (if no token provided)
if ($accessToken === '') {
    if ($username === '' || $password === '') {
        $failed = true;
        fail('login', 'Missing --username/--password or --access-token');
    } else {
        $payload = json_encode(['username' => $username, 'password' => $password], JSON_UNESCAPED_SLASHES);
        $login = httpRequest(
            'POST',
            $baseUrl . '/auth/login',
            ['Accept: application/json', 'Content-Type: application/json'],
            $payload === false ? '{"username":"","password":""}' : $payload,
            $timeout
        );

        $loginJson = tryJsonDecode($login['body']);
        if ($login['err'] !== '') {
            $failed = true;
            fail('login', $login['err']);
        } elseif ($login['status'] !== 200) {
            $failed = true;
            fail('login', "HTTP {$login['status']} body=" . trim($login['body']));
        } elseif (!is_array($loginJson) || empty($loginJson['access_token'])) {
            $failed = true;
            fail('login', 'No access_token in response: ' . trim($login['body']));
        } else {
            $accessToken = (string)$loginJson['access_token'];
            ok('login', 'token_received: '.$accessToken);
        }
    }
} else {
    ok('login', 'skipped (access-token provided)');
}

// 2) Profile check (Authorization: Bearer ...)
if ($accessToken !== '') {
    $profile = httpRequest(
        'GET',
        $baseUrl . '/profile',
        ['Accept: application/json', 'Authorization: Bearer ' . $accessToken],
        null,
        $timeout
    );
    if ($profile['err'] !== '') {
        $failed = true;
        fail('profile', $profile['err']);
    } elseif ($profile['status'] !== 200) {
        $failed = true;
        fail('profile', "HTTP {$profile['status']} body=" . trim($profile['body']));
    } else {
        ok('profile');
    }
}

// 2.5) ConnectorDB::getCallerId() check
if ($callerIdNumber !== '') {
    try {
        $callerData = ConnectorDB::getCallerId($callerIdNumber);
        // ConnectorDB::getCallerId returns $resultArray['data'] only when result == "Success", otherwise [].
        // So success criteria here: we received a data array with expected keys.
        if (!is_array($callerData) || empty($callerData)) {
            $failed = true;
            fail('callerid', 'Empty response from ConnectorDB::getCallerId (result is not Success?)');
        } else {
            $expectedKeys = [
                'number', 'number_format', 'client', 'contact', 'caller_id', 'is_employee',
                'extension', 'ref', 'responsible'
            ];
            $missing = [];
            foreach ($expectedKeys as $k) {
                if (!array_key_exists($k, $callerData)) {
                    $missing[] = $k;
                }
            }
            if (!empty($missing)) {
                $failed = true;
                fail('callerid', 'Missing keys in response: ' . implode(',', $missing));
            } else {
                ok('callerid', 'Success');
            }
        }
    } catch (\Throwable $e) {
        $failed = true;
        fail('callerid', $e->getMessage());
    }
}

// 3) Pub/Sub smoke per channel
if ($accessToken !== '') {
    foreach ($channels as $channel) {
        $testId = 'smoke-' . $channel . '-' . date('YmdHis') . '-' . substr(md5((string)microtime(true)), 0, 8);
        $skipPublish = false;
        /** @var callable|null $matchMessage */
        $matchMessage = null;

        // Special test for contacts: create/update PhoneBook and trigger event via ConnectorDB::invoke()
        if ($channel === 'contacts' && $callerIdNumber !== '') {
            $skipPublish = true;

            $phoneIndex = ConnectorDB::getPhoneIndex($callerIdNumber);
            $changedTs = time();

            try {
                /** @var PhoneBook|null $data */
                $data = PhoneBook::findFirst(['conditions' => 'number = :number:', 'bind' => ['number' => $phoneIndex]]);
                if (!$data) {
                    $data = new PhoneBook();
                    $data->number = $phoneIndex;
                }
                $data->created = $changedTs;
                $data->changed = $changedTs;
                $data->client = 'МИКО ООО';
                $data->contact = '';
                $data->ref = '';
                $data->is_employee = 0;
                $data->number_rep = '+7 (495) 229-3042';

                if (!$data->save()) {
                    $failed = true;
                    fail('phonebook', 'Failed to save PhoneBook record');
                } else {
                    ok('phonebook', 'saved');
                }

                $invokeOk = ConnectorDB::invoke('startFindClientByPhone', [$callerIdNumber], false);
                if ($invokeOk !== true) {
                    $failed = true;
                    fail('invoke', 'ConnectorDB::invoke returned non-true');
                } else {
                    ok('invoke', 'startFindClientByPhone');
                }
            } catch (\Throwable $e) {
                $failed = true;
                fail('phonebook/invoke', $e->getMessage());
            }

            $matchMessage = static function (?array $decoded, string $body) use ($phoneIndex, $changedTs): bool {
                if (!is_array($decoded)) {
                    return false;
                }
                if ((string)($decoded['number'] ?? '') !== (string)$phoneIndex) {
                    return false;
                }
                if (($decoded['client'] ?? '') !== 'МИКО ООО') {
                    return false;
                }
                if (($decoded['number_rep'] ?? '') !== '+7 (495) 229-3042') {
                    return false;
                }
                $changed = (int)($decoded['changed'] ?? 0);
                if ($changed < ($changedTs - 2)) {
                    // allow small clock drift
                    return false;
                }
                return true;
            };
        } else {
            $payloadArr = [
                'event' => $channel . '-update',
                'ts' => time(),
                'test_id' => $testId,
            ];
            $matchMessage = static function (?array $decoded, string $body) use ($testId): bool {
                if (is_array($decoded) && isset($decoded['test_id']) && (string)$decoded['test_id'] === $testId) {
                    return true;
                }
                return strpos($body, $testId) !== false;
            };
        }

        if (!$skipPublish) {
            $payloadJson = json_encode($payloadArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payloadJson === false) {
                $failed = true;
                fail("pub/$channel", 'Failed to encode JSON payload');
                continue;
            }

            $pub = httpRequest(
                'POST',
                $baseUrl . '/pub/' . rawurlencode($channel),
                ['Accept: application/json', 'Content-Type: application/json'],
                $payloadJson,
                $timeout
            );
            if ($pub['err'] !== '') {
                $failed = true;
                fail("pub/$channel", $pub['err']);
                continue;
            }
            if ($pub['status'] < 200 || $pub['status'] >= 300) {
                $failed = true;
                fail("pub/$channel", "HTTP {$pub['status']} body=" . trim($pub['body']));
                continue;
            }
            ok("pub/$channel");
        }

        // Subscriber: token via query is the common case for WS/EventSource.
        // NOTE: "contacts" channel is configured with large message buffer (200), so first messages can be old.
        // We keep requesting until we observe our test_id (or timeout).
        $received = false;
        $lastErr = '';
        $deadline = microtime(true) + $maxWait;
        $attempt = 0;
        $cursorEtag = '';
        $cursorLastModified = '';

        while (microtime(true) < $deadline && $attempt < $maxRequests) {
            $attempt++;
            $subUrl = $baseUrl . '/sub/' . rawurlencode($channel)
                . '?authorization=' . rawurlencode($accessToken)
                . '&_t=' . rawurlencode((string)microtime(true));

            // Keep each request short to be able to drain buffered messages quickly.
            $perReqTimeout = $timeout;
            if ($channel === 'contacts') {
                $perReqTimeout = 1;
            } elseif ($perReqTimeout > 5) {
                $perReqTimeout = 5;
            }
            $subHeaders = ['Accept: application/json'];
            // Nchan long-poll cursoring is commonly done via ETag/Last-Modified with conditional requests:
            // - send If-None-Match with last ETag (and optionally If-Modified-Since)
            // - server returns next buffered message (200) or waits/returns 304 when nothing new
            if ($cursorEtag !== '') {
                $subHeaders[] = 'If-None-Match: ' . $cursorEtag;
            }
            if ($cursorLastModified !== '') {
                $subHeaders[] = 'If-Modified-Since: ' . $cursorLastModified;
            }
            $sub = httpRequest('GET', $subUrl, $subHeaders, null, $perReqTimeout);
            if ($sub['err'] !== '') {
                $lastErr = $sub['err'];
                usleep(200000);
                continue;
            }
            if ($sub['status'] === 304) {
                // Not modified: no new message after our cursor yet.
                $lastErr = 'No new message yet (304 Not Modified)';
                usleep(150000);
                continue;
            }
            if ($sub['status'] !== 200) {
                $bodyShort = trim($sub['body']);
                if (strlen($bodyShort) > 300) {
                    $bodyShort = substr($bodyShort, 0, 300) . '...';
                }
                $lastErr = "HTTP {$sub['status']} body=" . $bodyShort;
                usleep(200000);
                continue;
            }

            $body = trim($sub['body']);
            if ($body === '') {
                $lastErr = 'Empty body';
                usleep(200000);
                continue;
            }

            // Advance Nchan cursor from response headers.
            $etag = extractHeaderValue($sub['headers'], 'Etag');
            if ($etag === '') {
                $etag = extractHeaderValue($sub['headers'], 'ETag');
            }
            if ($etag !== '') {
                $cursorEtag = $etag;
            }
            $lm = extractHeaderValue($sub['headers'], 'Last-Modified');
            if ($lm !== '') {
                $cursorLastModified = $lm;
            }

            $decoded = tryJsonDecode($body);
            if ($matchMessage && $matchMessage($decoded, $body)) {
                $received = true;
                break;
            }

            // Most likely: buffered old event. Keep draining until we see our test_id.
            $lastErr = 'Received message does not match current test_id (buffered old event)';
            // Do NOT sleep here: for buffered channels (contacts) we want to drain fast.
        }

        if (!$received) {
            $failed = true;
            $failMsg = $lastErr !== '' ? $lastErr : 'No message received';
            $failMsg .= " (attempts={$attempt}, max_requests={$maxRequests}, max_wait={$maxWait}s, etag={$cursorEtag})";
            fail("sub/$channel", $failMsg);
        } else {
            ok("sub/$channel", 'message_received');
        }
    }
}

exit($failed ? 1 : 0);

