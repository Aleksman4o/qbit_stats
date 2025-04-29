<?php
header('Content-Type: application/json');
$config = require __DIR__.'/config.php';
require __DIR__.'/data_functions.php';

$db = new SQLite3($config['db_path']);

// Если используется кэширование на стороне клиента
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $last_client_update = $_SERVER['HTTP_IF_MODIFIED_SINCE'];
    $last_server_update = $db->querySingle("SELECT last_update FROM instances ORDER BY last_update DESC LIMIT 1");
    
    if ($last_client_update === $last_server_update) {
        http_response_code(304); // Not Modified
        exit;
    }
}

$data = get_current_data($db, $config); // Передаём подключение к БД и конфиг

// Для следующей проверки свежести данных
header("Last-Modified: " . $data['last_update']);
echo json_encode($data);