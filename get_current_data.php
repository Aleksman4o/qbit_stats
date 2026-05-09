<?php

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/collector.php';
require_once __DIR__ . '/data_functions.php';

$forceRefresh = isset($_GET['force']) && $_GET['force'] === '1';
$runAsyncRefresh = false;

if ($forceRefresh || !function_exists('fastcgi_finish_request')) {
    $refresh = maybe_refresh_stats($config, $forceRefresh);
    $db = open_database($config);
    $data = get_current_data($db, $config, $refresh);
    $db->close();
} else {
    $db = open_database($config);
    $refresh = [
        'requested' => false,
        'needed' => is_refresh_needed($db, $config),
        'performed' => false,
        'in_progress' => false,
        'latest_update' => get_latest_update($db),
    ];

    $shouldUseAsyncBootstrap = $refresh['needed'] && empty($refresh['latest_update']);

    if ($shouldUseAsyncBootstrap) {
        $refresh['in_progress'] = true;
        $runAsyncRefresh = true;
        $data = get_current_data($db, $config, $refresh);
        $db->close();
    } else {
        $db->close();
        $refresh = maybe_refresh_stats($config, false);
        $db = open_database($config);
        $data = get_current_data($db, $config, $refresh);
        $db->close();
    }
}

if (!empty($refresh['error']) && empty($data['last_update'])) {
    http_response_code(503);
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($runAsyncRefresh) {
    fastcgi_finish_request();
    maybe_refresh_stats($config, false);
}
