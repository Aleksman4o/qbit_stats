<?php
header('Content-Type: application/json');
$config = require __DIR__.'/config.php';
$db = new SQLite3($config['db_path']);

try {
    if (!isset($_GET['timestamp'])) {
        throw new Exception('Timestamp parameter is missing');
    }

    // Получаем полный timestamp из параметра
    $timestamp = $_GET['timestamp'];
    
    // Проверяем формат timestamp (должен содержать дату и время)
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp)) {
        throw new Exception('Invalid timestamp format');
    }

    // Экранируем для безопасности
    $timestamp = SQLite3::escapeString($timestamp);

    $result = $db->query("SELECT 
        category,
        SUM(active_torrents) as active_torrents,
        SUM(dl_speed) as dl_speed,
        SUM(up_speed) as up_speed,
        SUM(total_size) as total_size,
        SUM(uploaded_session) as uploaded_session,
        SUM(uploaded_total) as uploaded_total
        FROM category_history
        WHERE timestamp = '$timestamp'
        GROUP BY category
        ORDER BY up_speed DESC");

    if (!$result) {
        throw new Exception('Database query failed');
    }

    $data = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode($data);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}