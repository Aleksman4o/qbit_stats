<?php

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';

try {
    $db = open_database($config);
    $db->close();
    echo "Schema check completed successfully\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
