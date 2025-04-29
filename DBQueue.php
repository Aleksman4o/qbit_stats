<?php
class DBQueue {
    private static $queue = [];
    private static $isProcessing = false;
    private static $dbPath;

    public static function init($dbPath) {
        self::$dbPath = $dbPath;
        register_shutdown_function([self::class, 'processQueue']);
    }

    public static function addQuery($query, $params = []) {
        self::$queue[] = [
            'query' => $query,
            'params' => $params,
            'timestamp' => microtime(true)
        ];
        
        if (!self::$isProcessing) {
            self::processQueue();
        }
    }

    public static function processQueue() {
        if (self::$isProcessing || empty(self::$queue)) return;
        
        self::$isProcessing = true;
        $db = new SQLite3(self::$dbPath);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode=WAL');
        
        try {
            while (!empty(self::$queue)) {
                $item = array_shift(self::$queue);
                
                try {
                    $stmt = $db->prepare($item['query']);
                    foreach ($item['params'] as $param => $value) {
                        $stmt->bindValue($param, $value);
                    }
                    $stmt->execute();
                } catch (Exception $e) {
                    error_log("Query failed: " . $item['query'] . " Error: " . $e->getMessage());
                    // Можно добавить повторную попытку или логирование
                }
            }
        } finally {
            $db->close();
            self::$isProcessing = false;
        }
    }
}