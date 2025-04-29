<?php
header('Content-Type: application/json');

$config = require __DIR__.'/config.php';
$instances = $config['instances'];

$db = new SQLite3($config['db_path']);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('BEGIN TRANSACTION');

try {
    $time = date('Y-m-d H:i:s');
    
    foreach ($instances as $instance) {
        // Аутентификация и получение данных (остаётся без изменений)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $instance['url'].'/api/v2/auth/login');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $instance['ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $instance['ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'username' => $instance['username'],
            'password' => $instance['password']
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        
        if (strpos($response, 'Ok.') === false) {
            throw new Exception("Auth failed for {$instance['name']}");
        }
        
        curl_setopt($ch, CURLOPT_URL, $instance['url'].'/api/v2/transfer/info');
        $transfer = json_decode(curl_exec($ch), true);
        
        curl_setopt($ch, CURLOPT_URL, $instance['url'].'/api/v2/torrents/info');
        $torrents = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        // Сохранение данных инстанса (остаётся без изменений)
        $stmt = $db->prepare('INSERT OR REPLACE INTO instances 
                            (name, dl_speed, up_speed, dl_session, up_session, last_update)
                            VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bindValue(1, $instance['name']);
        $stmt->bindValue(2, $transfer['dl_info_speed']);
        $stmt->bindValue(3, $transfer['up_info_speed']);
        $stmt->bindValue(4, $transfer['dl_info_data']);
        $stmt->bindValue(5, $transfer['up_info_data']);
        $stmt->bindValue(6, $time);
        $stmt->execute();
        
        // Обработка категорий
        $categories = [];
        foreach ($torrents as $torrent) {
            $category = $torrent['category'] ?? 'Uncategorized';
            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'active' => 0,
                    'dl_speed' => 0,
                    'up_speed' => 0,
                    'total_size' => 0,
                    'uploaded_session' => 0,
                    'uploaded_total' => 0
                ];
            }
            
            if (in_array($torrent['state'], ['uploading', 'downloading'])) {
                $categories[$category]['active']++;
            }
            
            $categories[$category]['dl_speed'] += $torrent['dlspeed'];
            $categories[$category]['up_speed'] += $torrent['upspeed'];
            $categories[$category]['total_size'] += $torrent['size'];
            $categories[$category]['uploaded_session'] += $torrent['uploaded_session'];
            $categories[$category]['uploaded_total'] += $torrent['uploaded'];
        }
        
        // Сохранение текущих категорий
        foreach ($categories as $category => $stats) {
            $stmt = $db->prepare('INSERT OR REPLACE INTO categories
                    (instance_name, category, active_torrents, dl_speed, up_speed, 
                     total_size, uploaded_session, uploaded_total, last_update)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bindValue(1, $instance['name']);
            $stmt->bindValue(2, $category);
            $stmt->bindValue(3, $stats['active']);
            $stmt->bindValue(4, $stats['dl_speed']);
            $stmt->bindValue(5, $stats['up_speed']);
            $stmt->bindValue(6, $stats['total_size']);
            $stmt->bindValue(7, $stats['uploaded_session']);
            $stmt->bindValue(8, $stats['uploaded_total']);
            $stmt->bindValue(9, $time);
            $stmt->execute();
            
            // Сохранение истории категорий
            $stmt = $db->prepare('INSERT INTO category_history
                    (instance_name, category, active_torrents, dl_speed, up_speed, 
                     total_size, uploaded_session, uploaded_total, timestamp)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bindValue(1, $instance['name']);
            $stmt->bindValue(2, $category);
            $stmt->bindValue(3, $stats['active']);
            $stmt->bindValue(4, $stats['dl_speed']);
            $stmt->bindValue(5, $stats['up_speed']);
            $stmt->bindValue(6, $stats['total_size']);
            $stmt->bindValue(7, $stats['uploaded_session']);
            $stmt->bindValue(8, $stats['uploaded_total']);
            $stmt->bindValue(9, $time);
            $stmt->execute();
        }
        
        // Сохранение истории скоростей (остаётся без изменений)
        $stmt = $db->prepare('INSERT INTO speed_history 
                         (instance_name, dl_speed, up_speed, timestamp)
                         VALUES (?, ?, ?, ?)');
        $stmt->bindValue(1, $instance['name']);
        $stmt->bindValue(2, $transfer['dl_info_speed']);
        $stmt->bindValue(3, $transfer['up_info_speed']);
        $stmt->bindValue(4, $time);
        $stmt->execute();
    }
    
    // Очистка старых данных (храним 7 дней)
    $db->exec("DELETE FROM category_history WHERE timestamp < datetime('now', '-7 days')");
    $db->exec("DELETE FROM speed_history WHERE timestamp < datetime('now', '-7 days')");
    
    $db->exec('COMMIT');
    echo json_encode(['status' => 'success', 'updated' => $time]);
    
} catch (Exception $e) {
    $db->exec('ROLLBACK');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}