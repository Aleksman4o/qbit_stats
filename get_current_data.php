<?php

require_once __DIR__ . '/bootstrap.php';

$db = null;
$runAsyncRefresh = false;

try {
    $config = require __DIR__ . '/config.php';
    require_once __DIR__ . '/collector.php';
    require_once __DIR__ . '/data_functions.php';

    $forceRefresh = isset($_GET['force']) && $_GET['force'] === '1';

    if ($forceRefresh || !function_exists('fastcgi_finish_request')) {
        $refresh = maybe_refresh_stats($config, $forceRefresh);
        $db = open_database($config);
        $data = get_current_data($db, $config, $refresh);
        $db->close();
        $db = null;
    } else {
        $db = open_database($config);
        $refresh = [
            'requested' => false,
            'needed' => is_refresh_needed($db, $config),
            'performed' => false,
            'in_progress' => false,
            'latest_update' => get_latest_update($db, $config),
        ];

        $shouldUseAsyncBootstrap = $refresh['needed'] && empty($refresh['latest_update']);

        if ($shouldUseAsyncBootstrap) {
            $refresh['in_progress'] = true;
            $runAsyncRefresh = true;
            $data = get_current_data($db, $config, $refresh);
            $db->close();
            $db = null;
        } else {
            $db->close();
            $db = null;
            $refresh = maybe_refresh_stats($config, false);
            $db = open_database($config);
            $data = get_current_data($db, $config, $refresh);
            $db->close();
            $db = null;
        }
    }

    $statusCode = !empty($refresh['error']) && empty($data['last_update']) ? 503 : 200;
} catch (Throwable $e) {
    if ($db instanceof SQLite3) {
        $db->close();
    }

    send_json_response([
        'error' => truncate_error_message($e->getMessage()),
    ], 500);
    exit;
}

send_json_response($data, $statusCode);

if ($runAsyncRefresh) {
    fastcgi_finish_request();
    maybe_refresh_stats($config, false);
}
