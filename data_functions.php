<?php
function get_current_data($db, $config) {
    $data = [
        'instances' => [],
        'categories' => [],
        'last_update' => date('Y-m-d H:i:s')
    ];
    
    foreach ($config['instances'] as $instance) {
        $name = $instance['name'];
        $row = $db->querySingle("SELECT * FROM instances WHERE name = '$name'", true);
        $data['instances'][$name] = $row ?: [
            'name' => $name,
            'dl_speed' => 0,
            'up_speed' => 0,
            'dl_session' => 0,
            'up_session' => 0
        ];
    }
    
    $result = $db->query("SELECT 
        category,
        GROUP_CONCAT(instance_name, ', ') as instances,
        SUM(active_torrents) as active_torrents,
        SUM(dl_speed) as dl_speed,
        SUM(up_speed) as up_speed,
        SUM(total_size) as total_size,
        SUM(uploaded_session) as uploaded_session,
        SUM(uploaded_total) as uploaded_total
        FROM categories 
        GROUP BY category
        ORDER BY up_speed DESC");
        
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $data['categories'][] = $row;
    }
    
    return $data;
}