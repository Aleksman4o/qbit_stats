<?php
function parseIniInteger($value): ?int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT);

    return $parsed === false ? null : $parsed;
}

function parseClientsConfig(?string $iniFileOverride = null) {
    $iniCandidates = $iniFileOverride !== null
        ? [$iniFileOverride]
        : [
            __DIR__ . '/../data/config.ini',
            __DIR__ . '/../../data/config.ini',
        ];
    $iniFile = null;

    foreach ($iniCandidates as $candidate) {
        if (is_file($candidate)) {
            $iniFile = $candidate;
            break;
        }
    }

    if ($iniFile === null) {
        throw new Exception(
            "Config file config.ini not found. Checked: " . implode(', ', $iniCandidates)
        );
    }

    $iniContent = parse_ini_file($iniFile, true, INI_SCANNER_TYPED);
    if ($iniContent === false) {
        throw new Exception("Failed to parse INI file. Check file syntax.");
    }

    $activeClientCount = null;
    if (array_key_exists('qt', $iniContent['other'] ?? [])) {
        $activeClientCount = parseIniInteger($iniContent['other']['qt']);

        if ($activeClientCount === null || $activeClientCount < 0) {
            throw new Exception("Invalid [other] qt in config.ini. Expected a non-negative client count.");
        }
    }

    $clientSections = $iniContent;
    if ($activeClientCount !== null) {
        $clientSections = [];

        for ($sectionNumber = 1; $sectionNumber <= $activeClientCount; $sectionNumber++) {
            $section = 'torrent-client-' . $sectionNumber;
            if (isset($iniContent[$section]) && is_array($iniContent[$section])) {
                $clientSections[$section] = $iniContent[$section];
            }
        }
    }

    $instances = [];
    foreach ($clientSections as $section => $client) {
        // Пропускаем секции без обязательных полей
        if (!isset($client['client']) || !isset($client['hostname']) || !isset($client['port'])) {
            continue;
        }

        // Пропускаем не-qbittorrent клиенты и исключенные
        if ($client['client'] !== 'qbittorrent' || ($client['exclude'] ?? 0) == 1) {
            continue;
        }

        $clientId = parseIniInteger($client['id'] ?? null);

        $protocol = ($client['ssl'] ?? 0) == 1 ? 'https://' : 'http://';
        $url = $protocol . $client['hostname'] . ':' . $client['port'];
        $verifyCert = (bool)($client['verify_cert'] ?? false);

        $instances[] = [
            'name' => $client['comment'] ?? 'Client ' . ($client['id'] ?? 'unknown'),
            'url' => $url,
            'username' => $client['login'] ?? '',
            'password' => $client['password'] ?? '',
            'id' => $clientId,
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
            'refresh_ttl_seconds' => 30,
            'dashboard_poll_interval_seconds' => 30,
            'history_hours_default' => 6,
            'history_hours_options' => [1, 6, 24],
            'history_retention_days' => 7,
            'connect_timeout_seconds' => 5,
            'request_timeout_seconds' => 50,
            'parallel_sync_limit' => 20,
            'debug_sync' => false,
            'lock_path' => __DIR__ . '/qbittorrent_stats.lock',
        ],
    ];
}

if (defined('QBIT_STATS_CONFIG_LIBRARY_ONLY') && QBIT_STATS_CONFIG_LIBRARY_ONLY) {
    return null;
}

return parseClientsConfig();
