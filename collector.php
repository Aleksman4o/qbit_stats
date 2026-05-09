<?php

require_once __DIR__ . '/bootstrap.php';

function maybe_refresh_stats(array $config, bool $force = false): array
{
    $db = open_database($config);
    $latestBefore = get_latest_update($db);
    $refreshNeeded = $force || is_refresh_needed($db, $config);
    $db->close();

    if (!$refreshNeeded) {
        return [
            'requested' => $force,
            'needed' => false,
            'performed' => false,
            'in_progress' => false,
            'latest_update' => $latestBefore,
        ];
    }

    $lockHandle = fopen(get_refresh_lock_path($config), 'c+');
    if ($lockHandle === false) {
        return [
            'requested' => $force,
            'needed' => true,
            'performed' => false,
            'in_progress' => false,
            'latest_update' => $latestBefore,
            'error' => 'Unable to open refresh lock file',
        ];
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);

        return [
            'requested' => $force,
            'needed' => true,
            'performed' => false,
            'in_progress' => true,
            'latest_update' => $latestBefore,
        ];
    }

    $db = null;

    try {
        $db = open_database($config);

        if (!$force && !is_refresh_needed($db, $config)) {
            return [
                'requested' => $force,
                'needed' => false,
                'performed' => false,
                'in_progress' => false,
                'latest_update' => get_latest_update($db),
            ];
        }

        $summary = collect_stats($db, $config);

        return [
            'requested' => $force,
            'needed' => true,
            'performed' => true,
            'in_progress' => false,
            'latest_update' => get_latest_update($db),
            'summary' => $summary,
        ];
    } catch (Throwable $e) {
        return [
            'requested' => $force,
            'needed' => true,
            'performed' => false,
            'in_progress' => false,
            'latest_update' => $latestBefore,
            'error' => truncate_error_message($e->getMessage()),
        ];
    } finally {
        if ($db instanceof SQLite3) {
            $db->close();
        }

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function collect_stats(SQLite3 $db, array $config): array
{
    $snapshotTime = date('Y-m-d H:i:s');
    $instanceResults = [];
    $successCount = 0;
    $errorCount = 0;
    $debugEnabled = is_sync_debug_enabled($config);
    $instanceStates = load_instance_runtime_states($db, $config['instances']);
    $remoteSnapshots = fetch_instance_snapshots($config, $instanceStates);

    foreach ($config['instances'] as $instance) {
        $name = $instance['name'];
        $remoteResult = $remoteSnapshots[$name] ?? [
            'error' => 'No remote snapshot result available',
            'debug' => create_instance_debug_info($instanceStates[$name]['stored_sid'] ?? null),
        ];
        $debugInfo = $remoteResult['debug'] ?? [];

        try {
            if (!empty($remoteResult['error'])) {
                throw new RuntimeException($remoteResult['error']);
            }

            $persisted = persist_instance_snapshot($db, $instance, $snapshotTime, $remoteResult['snapshot']);
            $instanceResult = [
                'instance' => $name,
                'status' => 'ok',
                'message' => null,
                'torrent_count' => $persisted['torrent_count'],
                'full_update' => $remoteResult['snapshot']['sync']['full_update'],
                'rid_before' => $remoteResult['snapshot']['sync']['rid_before'],
                'rid_after' => $remoteResult['snapshot']['sync']['rid_after'],
            ];

            if ($debugEnabled) {
                $instanceResult['debug'] = $debugInfo;
            }

            $instanceResults[] = $instanceResult;
            $successCount++;
        } catch (Throwable $e) {
            persist_instance_error($db, $instance, $snapshotTime, truncate_error_message($e->getMessage()));

            $instanceResult = [
                'instance' => $name,
                'status' => 'error',
                'message' => truncate_error_message($e->getMessage()),
                'torrent_count' => 0,
                'full_update' => false,
                'rid_before' => null,
                'rid_after' => null,
            ];

            if ($debugEnabled && !empty($debugInfo)) {
                $instanceResult['debug'] = $debugInfo;
            }

            $instanceResults[] = $instanceResult;
            $errorCount++;
        }
    }

    cleanup_history($db, $config);

    return [
        'status' => $errorCount === 0 ? 'success' : ($successCount > 0 ? 'partial' : 'error'),
        'updated' => $snapshotTime,
        'success_count' => $successCount,
        'error_count' => $errorCount,
        'instances' => $instanceResults,
    ];
}

function load_instance_runtime_states(SQLite3 $db, array $instances): array
{
    $states = [];

    foreach ($instances as $instance) {
        $name = $instance['name'];
        $states[$name] = [
            'stored_sid' => load_stored_session($db, $name),
            'last_rid' => load_last_rid($db, $name),
        ];
    }

    return $states;
}

function fetch_instance_snapshots(array $config, array $instanceStates): array
{
    $results = [];
    $parallelRequests = [];

    foreach ($config['instances'] as $instance) {
        $name = $instance['name'];
        $state = $instanceStates[$name] ?? ['stored_sid' => null, 'last_rid' => 0];
        $debugInfo = create_instance_debug_info($state['stored_sid']);

        if (!empty($state['stored_sid'])) {
            $parallelRequests[$name] = [
                'instance' => $instance,
                'state' => $state,
                'debug' => $debugInfo,
            ];

            continue;
        }

        try {
            $results[$name] = fetch_instance_remote_snapshot($config, $instance, $state, $debugInfo);
        } catch (Throwable $e) {
            $results[$name] = [
                'error' => truncate_error_message($e->getMessage()),
                'debug' => $debugInfo,
            ];
        }
    }

    $parallelResponses = fetch_parallel_sync_responses($config, $parallelRequests);

    foreach ($parallelRequests as $name => $request) {
        $instance = $request['instance'];
        $state = $request['state'];
        $debugInfo = $request['debug'];

        try {
            $response = $parallelResponses[$name] ?? null;
            if ($response === null) {
                throw new RuntimeException('Parallel sync request did not return a response');
            }

            $debugInfo = $response['debug'];
            if ($response['error'] !== null) {
                throw new RuntimeException($response['error']);
            }

            if (in_array($response['http_code'], [401, 403], true)) {
                $results[$name] = fetch_instance_remote_snapshot($config, $instance, $state, $debugInfo);
                continue;
            }

            if ($response['http_code'] >= 400) {
                throw new RuntimeException(sprintf(
                    'sync/maindata failed for %s (HTTP %d)',
                    $name,
                    $response['http_code']
                ));
            }

            $mainData = decode_json_response($response['body'], 'sync/maindata', $name);
            $headers = create_instance_headers($instance);
            $settings = get_monitor_settings($config);
            $verifyCert = $instance['verify_cert'] ?? false;
            $sidCookie = $state['stored_sid'];
            $transfer = [];

            if (needs_transfer_fallback($mainData)) {
                $transfer = fetch_json_with_reauth(
                    $settings,
                    $instance,
                    $verifyCert,
                    $instance['url'] . '/api/v2/transfer/info',
                    'transfer/info',
                    'transfer',
                    $headers,
                    $sidCookie,
                    $debugInfo
                );
            }

            $results[$name] = [
                'snapshot' => build_instance_snapshot($state, $sidCookie, $mainData, $transfer),
                'debug' => $debugInfo,
            ];
        } catch (Throwable $e) {
            $results[$name] = [
                'error' => truncate_error_message($e->getMessage()),
                'debug' => $debugInfo,
            ];
        }
    }

    return $results;
}

function fetch_parallel_sync_responses(array $config, array $requests): array
{
    if (empty($requests)) {
        return [];
    }

    $settings = get_monitor_settings($config);
    $limit = min(max(1, $settings['parallel_sync_limit']), count($requests));
    $queue = array_values($requests);
    $index = 0;
    $multi = curl_multi_init();
    $contexts = [];
    $results = [];
    $running = 0;

    while (($index < count($queue)) || !empty($contexts) || ($running > 0)) {
        while (($index < count($queue)) && (count($contexts) < $limit)) {
            $request = $queue[$index++];
            $instance = $request['instance'];
            $state = $request['state'];
            $headers = create_instance_headers($instance);
            $verifyCert = $instance['verify_cert'] ?? false;

            $ch = create_basic_instance_curl_handle($instance, $settings, $verifyCert, $headers['default']);
            curl_setopt($ch, CURLOPT_URL, $instance['url'] . '/api/v2/sync/maindata?rid=' . urlencode((string)$state['last_rid']));
            curl_setopt($ch, CURLOPT_COOKIE, $state['stored_sid']);

            $code = curl_multi_add_handle($multi, $ch);
            if ($code !== CURLM_OK) {
                curl_close($ch);
                throw new RuntimeException(sprintf(
                    'Unable to queue sync request for %s: %s',
                    $instance['name'],
                    curl_multi_strerror($code)
                ));
            }

            $contexts[(int)$ch] = [
                'handle' => $ch,
                'instance' => $instance,
                'state' => $state,
                'debug' => $request['debug'],
                'started_at' => microtime(true),
            ];
        }

        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if (($status !== CURLM_OK) && ($status !== CURLM_CALL_MULTI_PERFORM)) {
            throw new RuntimeException('curl_multi_exec failed: ' . curl_multi_strerror($status));
        }

        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $key = (int)$ch;
            $context = $contexts[$key] ?? null;
            if ($context === null) {
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                continue;
            }

            $body = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = ($info['result'] === CURLE_OK) ? null : curl_error($ch);
            $elapsedMs = round((microtime(true) - $context['started_at']) * 1000, 1);
            $debugInfo = $context['debug'];
            $debugInfo['sync_http_code'] = $httpCode;
            $debugInfo['sync_ms'] = $elapsedMs;
            $debugInfo['sync_bytes'] = ($body === false) ? 0 : strlen($body);

            $results[$context['instance']['name']] = [
                'instance' => $context['instance'],
                'state' => $context['state'],
                'debug' => $debugInfo,
                'http_code' => $httpCode,
                'body' => ($body === false) ? null : $body,
                'error' => $error,
            ];

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            unset($contexts[$key]);
        }

        if ($running > 0) {
            $selectResult = curl_multi_select($multi, 1.0);
            if ($selectResult === -1) {
                usleep(10000);
            }
        }
    }

    curl_multi_close($multi);

    return $results;
}

function fetch_instance_remote_snapshot(array $config, array $instance, array $state, array $debugInfo): array
{
    $settings = get_monitor_settings($config);
    $verifyCert = $instance['verify_cert'] ?? false;
    $headers = create_instance_headers($instance);
    $sidCookie = $state['stored_sid'];

    if (empty($sidCookie)) {
        $sidCookie = perform_instance_login($settings, $instance, $verifyCert, $headers, $debugInfo, 'initial');
    }

    $mainData = fetch_json_with_reauth(
        $settings,
        $instance,
        $verifyCert,
        $instance['url'] . '/api/v2/sync/maindata?rid=' . urlencode((string)$state['last_rid']),
        'sync/maindata',
        'sync',
        $headers,
        $sidCookie,
        $debugInfo
    );

    $transfer = [];
    if (needs_transfer_fallback($mainData)) {
        $transfer = fetch_json_with_reauth(
            $settings,
            $instance,
            $verifyCert,
            $instance['url'] . '/api/v2/transfer/info',
            'transfer/info',
            'transfer',
            $headers,
            $sidCookie,
            $debugInfo
        );
    }

    return [
        'snapshot' => build_instance_snapshot($state, $sidCookie, $mainData, $transfer),
        'debug' => $debugInfo,
    ];
}

function create_instance_debug_info(?string $storedSid): array
{
    return [
        'stored_sid_present' => $storedSid !== null,
        'stored_sid_name' => extract_sid_cookie_name($storedSid),
        'login_performed' => false,
        'reauth_performed' => false,
        'auth_attempts' => 0,
        'auth_http_codes' => [],
        'auth_reasons' => [],
        'auth_ms' => null,
        'session_cookie_name' => extract_sid_cookie_name($storedSid),
        'sid_changed' => false,
        'response_sid_seen' => false,
        'transfer_http_code' => null,
        'transfer_retry_http_code' => null,
        'transfer_ms' => null,
        'transfer_bytes' => null,
        'sync_http_code' => null,
        'sync_retry_http_code' => null,
        'sync_ms' => null,
        'sync_bytes' => null,
    ];
}

function create_instance_headers(array $instance): array
{
    $defaultHeaders = [
        'Referer: ' . $instance['url'] . '/',
        'Origin: ' . $instance['url'],
    ];

    return [
        'default' => $defaultHeaders,
        'login' => array_merge($defaultHeaders, [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        ]),
    ];
}

function perform_instance_login(array $settings, array $instance, bool $verifyCert, array $headers, array &$debugInfo, string $reason): string
{
    $sidCookie = null;
    $debugInfo['login_performed'] = true;
    $debugInfo['auth_attempts']++;
    $debugInfo['auth_reasons'][] = $reason;
    if ($reason !== 'initial') {
        $debugInfo['reauth_performed'] = true;
    }

    $ch = create_basic_instance_curl_handle($instance, $settings, $verifyCert, $headers['login']);
    curl_setopt($ch, CURLOPT_URL, $instance['url'] . '/api/v2/auth/login');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'username' => $instance['username'],
        'password' => $instance['password'],
    ]));
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$sidCookie, &$debugInfo) {
        $trimmed = ltrim($header);

        if (stripos($trimmed, 'Set-Cookie:') === 0 && stripos($trimmed, 'SID') !== false) {
            $cookieString = trim(substr($trimmed, strlen('Set-Cookie:')));
            $parts = explode(';', $cookieString);
            $sidPart = trim($parts[0]);

            if (stripos($sidPart, 'SID') !== false) {
                $sidCookie = $sidPart;
                $debugInfo['response_sid_seen'] = true;
                $debugInfo['session_cookie_name'] = extract_sid_cookie_name($sidCookie);
            }
        }

        return strlen($header);
    });

    $startedAt = microtime(true);
    $response = curl_exec($ch);
    $debugInfo['auth_ms'] = round((microtime(true) - $startedAt) * 1000, 1);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException(sprintf(
            'Auth request failed for %s: %s',
            $instance['name'],
            $error
        ));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $debugInfo['auth_http_codes'][] = $httpCode;

    if ($httpCode >= 400) {
        curl_close($ch);
        throw new RuntimeException(sprintf(
            'Auth failed for %s (HTTP %d)',
            $instance['name'],
            $httpCode
        ));
    }

    if ($sidCookie === null) {
        $cookies = curl_getinfo($ch, CURLINFO_COOKIELIST) ?: [];

        foreach ($cookies as $cookie) {
            $parts = explode("\t", $cookie);
            if (count($parts) < 7) {
                continue;
            }

            $name = trim($parts[count($parts) - 2]);
            $value = trim($parts[count($parts) - 1]);
            if (stripos($name, 'SID') !== false && $value !== '') {
                $sidCookie = $name . '=' . $value;
                break;
            }
        }
    }

    curl_close($ch);

    if ($sidCookie === null) {
        throw new RuntimeException(sprintf(
            'Auth for %s did not return a SID cookie',
            $instance['name']
        ));
    }

    $debugInfo['session_cookie_name'] = extract_sid_cookie_name($sidCookie);

    return $sidCookie;
}

function fetch_json_with_reauth(array $settings, array $instance, bool $verifyCert, string $url, string $context, string $phase, array $headers, ?string &$sidCookie, array &$debugInfo): array
{
    $retry = false;

    while (true) {
        if (empty($sidCookie)) {
            $sidCookie = perform_instance_login($settings, $instance, $verifyCert, $headers, $debugInfo, 'initial');
        }

        $response = send_instance_request($instance, $settings, $verifyCert, $url, $headers['default'], $sidCookie);
        $codeKey = $phase . ($retry ? '_retry_http_code' : '_http_code');
        $debugInfo[$codeKey] = $response['http_code'];
        $debugInfo[$phase . '_ms'] = $response['ms'];
        $debugInfo[$phase . '_bytes'] = $response['bytes'];

        if (($response['http_code'] === 401 || $response['http_code'] === 403) && !$retry) {
            $sidCookie = perform_instance_login($settings, $instance, $verifyCert, $headers, $debugInfo, 'reauth-' . $phase);
            $retry = true;
            continue;
        }

        if ($response['error'] !== null) {
            throw new RuntimeException($response['error']);
        }

        if ($response['http_code'] >= 400) {
            throw new RuntimeException(sprintf(
                '%s failed for %s (HTTP %d)',
                $context,
                $instance['name'],
                $response['http_code']
            ));
        }

        return decode_json_response($response['body'], $context, $instance['name']);
    }
}

function send_instance_request(array $instance, array $settings, bool $verifyCert, string $url, array $headers, ?string $sidCookie = null): array
{
    $ch = create_basic_instance_curl_handle($instance, $settings, $verifyCert, $headers);
    curl_setopt($ch, CURLOPT_URL, $url);
    if ($sidCookie !== null && $sidCookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $sidCookie);
    }

    $startedAt = microtime(true);
    $body = curl_exec($ch);
    $elapsedMs = round((microtime(true) - $startedAt) * 1000, 1);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = ($body === false) ? curl_error($ch) : null;
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'body' => ($body === false) ? null : $body,
        'error' => $error,
        'ms' => $elapsedMs,
        'bytes' => ($body === false || $body === null) ? 0 : strlen($body),
    ];
}

function create_basic_instance_curl_handle(array $instance, array $settings, bool $verifyCert, array $headers)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ACCEPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => $instance['ssl'] ? $verifyCert : false,
        CURLOPT_SSL_VERIFYHOST => ($instance['ssl'] && $verifyCert) ? 2 : 0,
        CURLOPT_CONNECTTIMEOUT => $settings['connect_timeout_seconds'],
        CURLOPT_TIMEOUT => $settings['request_timeout_seconds'],
        CURLOPT_HTTPHEADER => $headers,
    ]);

    return $ch;
}

function decode_json_response(?string $body, string $context, string $instanceName): array
{
    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf(
            '%s returned invalid JSON for %s',
            $context,
            $instanceName
        ));
    }

    return $decoded;
}

function needs_transfer_fallback(array $mainData): bool
{
    $serverState = $mainData['server_state'] ?? null;
    if (!is_array($serverState)) {
        return true;
    }

    foreach (['dl_info_speed', 'up_info_speed', 'dl_info_data', 'up_info_data'] as $field) {
        if (!array_key_exists($field, $serverState)) {
            return true;
        }
    }

    return false;
}

function build_instance_snapshot(array $state, ?string $sidCookie, array $mainData, array $transfer): array
{
    $ridRaw = $mainData['rid'] ?? null;

    return [
        'stored_sid' => $state['stored_sid'],
        'sid_cookie' => $sidCookie,
        'transfer' => $transfer,
        'server_state' => is_array($mainData['server_state'] ?? null) ? $mainData['server_state'] : [],
        'torrents_data' => is_array($mainData['torrents'] ?? null) ? $mainData['torrents'] : [],
        'torrents_removed' => is_array($mainData['torrents_removed'] ?? null) ? array_values($mainData['torrents_removed']) : [],
        'sync' => [
            'full_update' => (bool)($mainData['full_update'] ?? false),
            'rid_before' => (int)$state['last_rid'],
            'rid_after' => is_numeric($ridRaw) ? (int)$ridRaw : (int)$state['last_rid'],
        ],
    ];
}

function extract_sid_cookie_name(?string $cookie): ?string
{
    if ($cookie === null || $cookie === '') {
        return null;
    }

    $parts = explode('=', $cookie, 2);

    return $parts[0] !== '' ? $parts[0] : null;
}

function load_stored_session(SQLite3 $db, string $instanceName): ?string
{
    $stmt = $db->prepare('SELECT sid FROM instance_sessions WHERE instance_name = :instance_name');
    $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    return $row && !empty($row['sid']) ? $row['sid'] : null;
}

function save_instance_session(SQLite3 $db, string $instanceName, string $sidCookie, string $snapshotTime): void
{
    $stmt = $db->prepare('INSERT OR REPLACE INTO instance_sessions (instance_name, sid, last_login) VALUES (:instance_name, :sid, :last_login)');
    $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
    $stmt->bindValue(':sid', $sidCookie, SQLITE3_TEXT);
    $stmt->bindValue(':last_login', $snapshotTime, SQLITE3_TEXT);
    $stmt->execute();
}

function clear_instance_session(SQLite3 $db, string $instanceName): void
{
    $stmt = $db->prepare('DELETE FROM instance_sessions WHERE instance_name = :instance_name');
    $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
    $stmt->execute();
}

function load_last_rid(SQLite3 $db, string $instanceName): int
{
    $stmt = $db->prepare('SELECT last_rid FROM instance_sync_state WHERE instance_name = :instance_name');
    $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    return $row ? (int)$row['last_rid'] : 0;
}

function persist_instance_snapshot(SQLite3 $db, array $instance, string $snapshotTime, array $snapshot): array
{
    $serverState = $snapshot['server_state'];
    $transfer = $snapshot['transfer'];
    $resetSyncSession = should_reset_sync_session($snapshot);

    $db->exec('BEGIN IMMEDIATE');

    try {
        if ($resetSyncSession) {
            clear_instance_session($db, $instance['name']);
        } elseif (!empty($snapshot['sid_cookie']) && ($snapshot['sid_cookie'] !== ($snapshot['stored_sid'] ?? null))) {
            save_instance_session($db, $instance['name'], $snapshot['sid_cookie'], $snapshotTime);
        }

        $syncState = $db->prepare('INSERT OR REPLACE INTO instance_sync_state (instance_name, last_rid) VALUES (:instance_name, :last_rid)');
        $syncState->bindValue(':instance_name', $instance['name'], SQLITE3_TEXT);
        $syncState->bindValue(':last_rid', $resetSyncSession ? 0 : (int)$snapshot['sync']['rid_after'], SQLITE3_INTEGER);
        $syncState->execute();

        ensure_instance_row($db, $instance['name']);

        if ($snapshot['sync']['full_update']) {
            $torrentCount = persist_full_instance_snapshot_data(
                $db,
                $instance['name'],
                $snapshotTime,
                $snapshot['torrents_data']
            );
        } else {
            $torrentCount = persist_incremental_instance_snapshot_data(
                $db,
                $instance['name'],
                $snapshotTime,
                $snapshot['torrents_data'],
                $snapshot['torrents_removed']
            );
        }

        $refreshCategories = $db->prepare('UPDATE categories SET last_update = :last_update WHERE instance_name = :instance_name');
        $refreshCategories->bindValue(':instance_name', $instance['name'], SQLITE3_TEXT);
        $refreshCategories->bindValue(':last_update', $snapshotTime, SQLITE3_TEXT);
        $refreshCategories->execute();

        $updateInstance = $db->prepare('UPDATE instances
            SET dl_speed = :dl_speed,
                up_speed = :up_speed,
                dl_session = :dl_session,
                up_session = :up_session,
                last_update = :last_update,
                status = :status,
                last_error = NULL,
                last_attempt = :last_attempt,
                last_success = :last_success
            WHERE name = :name');
        $updateInstance->bindValue(':name', $instance['name'], SQLITE3_TEXT);
        $updateInstance->bindValue(':dl_speed', (int)($serverState['dl_info_speed'] ?? $transfer['dl_info_speed'] ?? 0), SQLITE3_INTEGER);
        $updateInstance->bindValue(':up_speed', (int)($serverState['up_info_speed'] ?? $transfer['up_info_speed'] ?? 0), SQLITE3_INTEGER);
        $updateInstance->bindValue(':dl_session', (int)($serverState['dl_info_data'] ?? $transfer['dl_info_data'] ?? 0), SQLITE3_INTEGER);
        $updateInstance->bindValue(':up_session', (int)($serverState['up_info_data'] ?? $transfer['up_info_data'] ?? 0), SQLITE3_INTEGER);
        $updateInstance->bindValue(':last_update', $snapshotTime, SQLITE3_TEXT);
        $updateInstance->bindValue(':status', 'ok', SQLITE3_TEXT);
        $updateInstance->bindValue(':last_attempt', $snapshotTime, SQLITE3_TEXT);
        $updateInstance->bindValue(':last_success', $snapshotTime, SQLITE3_TEXT);
        $updateInstance->execute();

        insert_category_history_snapshot($db, $instance['name'], $snapshotTime);

        $insertSpeedHistory = $db->prepare('INSERT INTO speed_history
            (instance_name, dl_speed, up_speed, timestamp)
            VALUES (:instance_name, :dl_speed, :up_speed, :timestamp)');
        $insertSpeedHistory->bindValue(':instance_name', $instance['name'], SQLITE3_TEXT);
        $insertSpeedHistory->bindValue(':dl_speed', (int)($serverState['dl_info_speed'] ?? $transfer['dl_info_speed'] ?? 0), SQLITE3_INTEGER);
        $insertSpeedHistory->bindValue(':up_speed', (int)($serverState['up_info_speed'] ?? $transfer['up_info_speed'] ?? 0), SQLITE3_INTEGER);
        $insertSpeedHistory->bindValue(':timestamp', $snapshotTime, SQLITE3_TEXT);
        $insertSpeedHistory->execute();

        $db->exec('COMMIT');

        return [
            'torrent_count' => $torrentCount,
        ];
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function should_reset_sync_session(array $snapshot): bool
{
    if (empty($snapshot['stored_sid']) || empty($snapshot['sid_cookie'])) {
        return false;
    }

    if ($snapshot['sid_cookie'] !== $snapshot['stored_sid']) {
        return false;
    }

    if (empty($snapshot['sync']['full_update'])) {
        return false;
    }

    $ridBefore = (int)($snapshot['sync']['rid_before'] ?? 0);
    $ridAfter = (int)($snapshot['sync']['rid_after'] ?? 0);

    return $ridBefore > 0 && $ridAfter <= 1;
}

function persist_full_instance_snapshot_data(SQLite3 $db, string $instanceName, string $snapshotTime, array $torrentsData): int
{
    $deleteTorrents = $db->prepare('DELETE FROM torrents WHERE instance_name = :instance_name');
    $deleteTorrents->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
    $deleteTorrents->execute();

    $deleteCategories = $db->prepare('DELETE FROM categories WHERE instance_name = :instance_name');
    $deleteCategories->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
    $deleteCategories->execute();

    $categories = [];
    $torrents = [];

    foreach ($torrentsData as $hash => $torrent) {
        $row = normalize_torrent_row($hash, null, $torrent);
        $torrents[$hash] = $row;
        apply_torrent_category_delta($categories, $row, 1);
    }

    upsert_torrent_rows($db, $instanceName, $torrents);
    upsert_category_rows($db, $instanceName, $snapshotTime, $categories);

    return count($torrents);
}

function persist_incremental_instance_snapshot_data(SQLite3 $db, string $instanceName, string $snapshotTime, array $torrentsData, array $torrentsRemoved): int
{
    $changedHashes = array_values(array_unique(array_merge(array_keys($torrentsData), $torrentsRemoved)));
    $existingTorrents = load_torrent_rows_by_hashes($db, $instanceName, $changedHashes);
    $categoryDeltas = [];
    $deletedHashes = [];
    $upsertRows = [];

    foreach ($torrentsRemoved as $hash) {
        if (!isset($existingTorrents[$hash])) {
            continue;
        }

        apply_torrent_category_delta($categoryDeltas, $existingTorrents[$hash], -1);
        $deletedHashes[] = $hash;
    }

    foreach ($torrentsData as $hash => $torrent) {
        $oldRow = $existingTorrents[$hash] ?? null;
        $newRow = normalize_torrent_row($hash, $oldRow, $torrent);

        if ($oldRow !== null) {
            apply_torrent_category_delta($categoryDeltas, $oldRow, -1);
        }

        apply_torrent_category_delta($categoryDeltas, $newRow, 1);
        $upsertRows[$hash] = $newRow;
    }

    delete_torrent_rows($db, $instanceName, $deletedHashes);
    upsert_torrent_rows($db, $instanceName, $upsertRows);

    if (!empty($categoryDeltas)) {
        $existingCategories = load_category_rows_by_names($db, $instanceName, array_keys($categoryDeltas));
        $updatedCategories = [];

        foreach ($categoryDeltas as $category => $delta) {
            $current = $existingCategories[$category] ?? create_empty_category_stats();
            $updated = add_category_stats($current, $delta);

            if (category_stats_are_empty($updated)) {
                $deleteCategory = $db->prepare('DELETE FROM categories WHERE instance_name = :instance_name AND category = :category');
                $deleteCategory->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
                $deleteCategory->bindValue(':category', $category, SQLITE3_TEXT);
                $deleteCategory->execute();
                continue;
            }

            $updatedCategories[$category] = $updated;
        }

        upsert_category_rows($db, $instanceName, $snapshotTime, $updatedCategories);
    }

    return get_instance_torrent_count($db, $instanceName);
}

function normalize_torrent_row(string $hash, ?array $current, array $torrent): array
{
    $row = $current ?? create_default_torrent_row($hash);
    $fieldMap = [
        'category' => 'category',
        'state' => 'state',
        'dlspeed' => 'dlspeed',
        'upspeed' => 'upspeed',
        'size' => 'size',
        'uploaded' => 'uploaded_total',
        'uploaded_session' => 'uploaded_session',
    ];

    foreach ($fieldMap as $apiField => $localField) {
        if (!array_key_exists($apiField, $torrent)) {
            continue;
        }

        $value = $torrent[$apiField];
        if ($localField === 'category') {
            $row[$localField] = ($value !== null && $value !== '') ? (string)$value : 'Uncategorized';
            continue;
        }

        if ($localField === 'state') {
            $row[$localField] = ($value !== null && $value !== '') ? (string)$value : 'unknown';
            continue;
        }

        $row[$localField] = (int)($value ?? 0);
    }

    return $row;
}

function create_default_torrent_row(string $hash): array
{
    return [
        'hash' => $hash,
        'category' => 'Uncategorized',
        'state' => 'unknown',
        'dlspeed' => 0,
        'upspeed' => 0,
        'size' => 0,
        'uploaded_total' => 0,
        'uploaded_session' => 0,
    ];
}

function load_torrent_rows_by_hashes(SQLite3 $db, string $instanceName, array $hashes): array
{
    if (empty($hashes)) {
        return [];
    }

    $placeholders = [];
    foreach (array_values($hashes) as $index => $hash) {
        $placeholders[] = ':hash_' . $index;
    }

    $stmt = $db->prepare('SELECT
        hash,
        category,
        state,
        dlspeed,
        upspeed,
        size,
        uploaded_total,
        uploaded_session
        FROM torrents
        WHERE instance_name = :instance_name
          AND hash IN (' . implode(', ', $placeholders) . ')');
    $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);

    foreach (array_values($hashes) as $index => $hash) {
        $stmt->bindValue(':hash_' . $index, $hash, SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[$row['hash']] = [
            'hash' => $row['hash'],
            'category' => $row['category'] !== '' ? $row['category'] : 'Uncategorized',
            'state' => $row['state'] !== '' ? $row['state'] : 'unknown',
            'dlspeed' => (int)$row['dlspeed'],
            'upspeed' => (int)$row['upspeed'],
            'size' => (int)$row['size'],
            'uploaded_total' => (int)$row['uploaded_total'],
            'uploaded_session' => (int)$row['uploaded_session'],
        ];
    }

    return $rows;
}

function delete_torrent_rows(SQLite3 $db, string $instanceName, array $hashes): void
{
    if (empty($hashes)) {
        return;
    }

    $stmt = $db->prepare('DELETE FROM torrents WHERE instance_name = :instance_name AND hash = :hash');

    foreach ($hashes as $hash) {
        $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->execute();
    }
}

function upsert_torrent_rows(SQLite3 $db, string $instanceName, array $rows): void
{
    if (empty($rows)) {
        return;
    }

    $stmt = $db->prepare('INSERT OR REPLACE INTO torrents
        (instance_name, hash, category, state, dlspeed, upspeed, size, uploaded_total, uploaded_session)
        VALUES (:instance_name, :hash, :category, :state, :dlspeed, :upspeed, :size, :uploaded_total, :uploaded_session)');

    foreach ($rows as $hash => $row) {
        $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':category', $row['category'], SQLITE3_TEXT);
        $stmt->bindValue(':state', $row['state'], SQLITE3_TEXT);
        $stmt->bindValue(':dlspeed', (int)$row['dlspeed'], SQLITE3_INTEGER);
        $stmt->bindValue(':upspeed', (int)$row['upspeed'], SQLITE3_INTEGER);
        $stmt->bindValue(':size', (int)$row['size'], SQLITE3_INTEGER);
        $stmt->bindValue(':uploaded_total', (int)$row['uploaded_total'], SQLITE3_INTEGER);
        $stmt->bindValue(':uploaded_session', (int)$row['uploaded_session'], SQLITE3_INTEGER);
        $stmt->execute();
    }
}

function load_category_rows_by_names(SQLite3 $db, string $instanceName, array $categories): array
{
    if (empty($categories)) {
        return [];
    }

    $placeholders = [];
    foreach (array_values($categories) as $index => $category) {
        $placeholders[] = ':category_' . $index;
    }

    $stmt = $db->prepare('SELECT
        category,
        active_torrents,
        dl_speed,
        up_speed,
        total_size,
        uploaded_session,
        uploaded_total
        FROM categories
        WHERE instance_name = :instance_name
          AND category IN (' . implode(', ', $placeholders) . ')');
    $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);

    foreach (array_values($categories) as $index => $category) {
        $stmt->bindValue(':category_' . $index, $category, SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[$row['category']] = [
            'active_torrents' => (int)$row['active_torrents'],
            'dl_speed' => (int)$row['dl_speed'],
            'up_speed' => (int)$row['up_speed'],
            'total_size' => (int)$row['total_size'],
            'uploaded_session' => (int)$row['uploaded_session'],
            'uploaded_total' => (int)$row['uploaded_total'],
        ];
    }

    return $rows;
}

function upsert_category_rows(SQLite3 $db, string $instanceName, string $snapshotTime, array $categories): void
{
    if (empty($categories)) {
        return;
    }

    $stmt = $db->prepare('INSERT OR REPLACE INTO categories
        (instance_name, category, active_torrents, dl_speed, up_speed, total_size, uploaded_session, uploaded_total, last_update)
        VALUES (:instance_name, :category, :active_torrents, :dl_speed, :up_speed, :total_size, :uploaded_session, :uploaded_total, :last_update)');

    foreach ($categories as $category => $stats) {
        $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
        $stmt->bindValue(':category', $category, SQLITE3_TEXT);
        $stmt->bindValue(':active_torrents', (int)$stats['active_torrents'], SQLITE3_INTEGER);
        $stmt->bindValue(':dl_speed', (int)$stats['dl_speed'], SQLITE3_INTEGER);
        $stmt->bindValue(':up_speed', (int)$stats['up_speed'], SQLITE3_INTEGER);
        $stmt->bindValue(':total_size', (int)$stats['total_size'], SQLITE3_INTEGER);
        $stmt->bindValue(':uploaded_session', (int)$stats['uploaded_session'], SQLITE3_INTEGER);
        $stmt->bindValue(':uploaded_total', (int)$stats['uploaded_total'], SQLITE3_INTEGER);
        $stmt->bindValue(':last_update', $snapshotTime, SQLITE3_TEXT);
        $stmt->execute();
    }
}

function load_all_current_categories(SQLite3 $db, string $instanceName): array
{
    $stmt = $db->prepare('SELECT
        category,
        active_torrents,
        dl_speed,
        up_speed,
        total_size,
        uploaded_session,
        uploaded_total
        FROM categories
        WHERE instance_name = :instance_name
        ORDER BY category ASC');
    $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
    $result = $stmt->execute();
    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = [
            'category' => $row['category'],
            'active_torrents' => (int)$row['active_torrents'],
            'dl_speed' => (int)$row['dl_speed'],
            'up_speed' => (int)$row['up_speed'],
            'total_size' => (int)$row['total_size'],
            'uploaded_session' => (int)$row['uploaded_session'],
            'uploaded_total' => (int)$row['uploaded_total'],
        ];
    }

    return $rows;
}

function insert_category_history_snapshot(SQLite3 $db, string $instanceName, string $snapshotTime): void
{
    $stmt = $db->prepare('INSERT INTO category_history
        (instance_name, category, active_torrents, dl_speed, up_speed, total_size, uploaded_session, uploaded_total, timestamp)
        SELECT
            instance_name,
            category,
            active_torrents,
            dl_speed,
            up_speed,
            total_size,
            uploaded_session,
            uploaded_total,
            :timestamp
        FROM categories
        WHERE instance_name = :instance_name');
    $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', $snapshotTime, SQLITE3_TEXT);
    $stmt->execute();
}

function get_instance_torrent_count(SQLite3 $db, string $instanceName): int
{
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM torrents WHERE instance_name = :instance_name');
    $stmt->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);

    return (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['total'];
}

function create_empty_category_stats(): array
{
    return [
        'active_torrents' => 0,
        'dl_speed' => 0,
        'up_speed' => 0,
        'total_size' => 0,
        'uploaded_session' => 0,
        'uploaded_total' => 0,
    ];
}

function apply_torrent_category_delta(array &$categories, array $torrent, int $direction): void
{
    $category = $torrent['category'] ?? 'Uncategorized';
    if (!isset($categories[$category])) {
        $categories[$category] = create_empty_category_stats();
    }

    $stats = get_torrent_category_stats($torrent);
    foreach ($stats as $field => $value) {
        $categories[$category][$field] += $direction * $value;
    }
}

function get_torrent_category_stats(array $torrent): array
{
    return [
        'active_torrents' => is_torrent_active($torrent) ? 1 : 0,
        'dl_speed' => (int)$torrent['dlspeed'],
        'up_speed' => (int)$torrent['upspeed'],
        'total_size' => (int)$torrent['size'],
        'uploaded_session' => (int)$torrent['uploaded_session'],
        'uploaded_total' => (int)$torrent['uploaded_total'],
    ];
}

function add_category_stats(array $base, array $delta): array
{
    $result = $base;

    foreach ($delta as $field => $value) {
        $result[$field] = (int)($result[$field] ?? 0) + (int)$value;
        if ($result[$field] < 0) {
            $result[$field] = 0;
        }
    }

    return $result;
}

function category_stats_are_empty(array $stats): bool
{
    foreach ($stats as $value) {
        if ((int)$value !== 0) {
            return false;
        }
    }

    return true;
}

function persist_instance_error(SQLite3 $db, array $instance, string $snapshotTime, string $message): void
{
    $db->exec('BEGIN IMMEDIATE');

    try {
        ensure_instance_row($db, $instance['name']);

        $deleteCategories = $db->prepare('DELETE FROM categories WHERE instance_name = :instance_name');
        $deleteCategories->bindValue(':instance_name', $instance['name'], SQLITE3_TEXT);
        $deleteCategories->execute();

        $updateInstance = $db->prepare('UPDATE instances
            SET dl_speed = 0,
                up_speed = 0,
                dl_session = 0,
                up_session = 0,
                status = :status,
                last_error = :last_error,
                last_attempt = :last_attempt
            WHERE name = :name');
        $updateInstance->bindValue(':name', $instance['name'], SQLITE3_TEXT);
        $updateInstance->bindValue(':status', 'error', SQLITE3_TEXT);
        $updateInstance->bindValue(':last_error', $message, SQLITE3_TEXT);
        $updateInstance->bindValue(':last_attempt', $snapshotTime, SQLITE3_TEXT);
        $updateInstance->execute();

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function ensure_instance_row(SQLite3 $db, string $instanceName): void
{
    $stmt = $db->prepare('INSERT OR IGNORE INTO instances (name) VALUES (:name)');
    $stmt->bindValue(':name', $instanceName, SQLITE3_TEXT);
    $stmt->execute();
}

function is_torrent_active(array $torrent): bool
{
    return in_array((string)($torrent['state'] ?? ''), ['uploading', 'downloading'], true);
}

function cleanup_history(SQLite3 $db, array $config): void
{
    $settings = get_monitor_settings($config);
    $cutoff = date('Y-m-d H:i:s', time() - ($settings['history_retention_days'] * 86400));

    $deleteCategoryHistory = $db->prepare('DELETE FROM category_history WHERE timestamp < :cutoff');
    $deleteCategoryHistory->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
    $deleteCategoryHistory->execute();

    $deleteSpeedHistory = $db->prepare('DELETE FROM speed_history WHERE timestamp < :cutoff');
    $deleteSpeedHistory->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
    $deleteSpeedHistory->execute();
}
