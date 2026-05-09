<?php
function parseClientsConfig() {
    $iniFile = __DIR__ . '/../data/config.ini';
    if (!file_exists($iniFile)) {
        throw new Exception("Config file config.ini not found at: " . realpath($iniFile));
    }

    $iniContent = parse_ini_file($iniFile, true, INI_SCANNER_TYPED);
    if ($iniContent === false) {
        throw new Exception("Failed to parse INI file. Check file syntax.");
    }

    $instances = [];
    foreach ($iniContent as $section => $client) {
        // Пропускаем секции без обязательных полей
        if (!isset($client['client']) || !isset($client['hostname']) || !isset($client['port'])) {
            continue;
        }

        // Пропускаем не-qbittorrent клиенты и исключенные
        if ($client['client'] !== 'qbittorrent' || ($client['exclude'] ?? 0) == 1) {
            continue;
        }

        $protocol = ($client['ssl'] ?? 0) == 1 ? 'https://' : 'http://';
        $url = $protocol . $client['hostname'] . ':' . $client['port'];
        $verifyCert = (bool)($client['verify_cert'] ?? false);

        $instances[] = [
            'name' => $client['comment'] ?? 'Client ' . ($client['id'] ?? 'unknown'),
            'url' => $url,
            'username' => $client['login'] ?? '',
            'password' => $client['password'] ?? '',
            'id' => $client['id'] ?? null,
            'ssl' => (bool)($client['ssl'] ?? false),
            'verify_cert' => $verifyCert
        ];
    }

    if (empty($instances)) {
        throw new Exception("No valid qBittorrent clients configured. Check client= values in config.ini");
    }

    return [
        'instances' => $instances,
        'db_path' => __DIR__ . '/qbittorrent_stats.db',
        'settings' => [
            'refresh_ttl_seconds' => 60,
            'history_hours_default' => 6,
            'history_hours_options' => [1, 6, 24],
            'history_retention_days' => 7,
            'connect_timeout_seconds' => 5,
            'request_timeout_seconds' => 30,
            'parallel_sync_limit' => 7,
            'debug_sync' => false,
            'lock_path' => __DIR__ . '/qbittorrent_stats.lock',
        ],
    ];
}

return parseClientsConfig();
