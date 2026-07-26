<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/data_functions.php';

$db = null;

try {
    $config = require __DIR__ . '/config.php';
    $hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 0;
    $db = open_database($config);
    $data = get_history_data($db, $config, $hours);
    $db->close();
    $db = null;

    send_json_response($data);
} catch (Throwable $e) {
    if ($db instanceof SQLite3) {
        $db->close();
    }

    send_json_response([
        'error' => truncate_error_message($e->getMessage()),
    ], 500);
}
