<?php
header('Content-Type: application/json');

$config = require __DIR__.'/config.php';
$db = new SQLite3($config['db_path']);

$lastUpdate = $db->querySingle("SELECT last_update FROM instances ORDER BY last_update DESC LIMIT 1");

// Если клиент прислал If-Modified-Since и данные не изменились - возвращаем 304
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $clientTime = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
    $serverTime = strtotime($lastUpdate);
    
    if ($clientTime >= $serverTime) {
        http_response_code(304); // Not Modified
        exit;
    }
}

// Иначе отправляем новые данные
header("Last-Modified: $lastUpdate");
echo json_encode(['last_update' => $lastUpdate]);