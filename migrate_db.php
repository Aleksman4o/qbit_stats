<?php
$config = require __DIR__.'/config.php';
$db = new SQLite3($config['db_path']);

try {
    $db->exec('BEGIN TRANSACTION');
    
    // Добавляем новое поле
    $db->exec('ALTER TABLE categories ADD COLUMN uploaded_total INTEGER DEFAULT 0');
    
    // Для истории категорий (если используется)
    $db->exec('ALTER TABLE category_history ADD COLUMN uploaded_total INTEGER DEFAULT 0');
    
    $db->exec('COMMIT');
    echo "Миграция успешно выполнена\n";
} catch (Exception $e) {
    $db->exec('ROLLBACK');
    echo "Ошибка миграции: " . $e->getMessage() . "\n";
}