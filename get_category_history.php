<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/data_functions.php';

$db = null;

try {
    $config = require __DIR__ . '/config.php';

    if (!isset($_GET['timestamp'])) {
        throw new InvalidArgumentException('Timestamp parameter is missing');
    }

    $timestamp = $_GET['timestamp'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp)) {
        throw new InvalidArgumentException('Invalid timestamp format');
    }

    $db = open_database($config);
    $data = get_category_history($db, $config, $timestamp);
    $db->close();
    $db = null;

    send_json_response([
        'timestamp' => $timestamp,
        'data' => $data,
    ]);
} catch (InvalidArgumentException $e) {
    send_json_response(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    if ($db instanceof SQLite3) {
        $db->close();
    }

    send_json_response([
        'error' => truncate_error_message($e->getMessage()),
    ], 500);
}
