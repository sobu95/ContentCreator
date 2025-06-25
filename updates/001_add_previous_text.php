<?php
require_once __DIR__ . '/../config.php';

$pdo = getDbConnection();

try {
    $pdo->exec("ALTER TABLE task_items ADD COLUMN previous_text TEXT NULL");
    echo "Added previous_text column to task_items.\n";
} catch (Exception $e) {
    echo "Update failed: " . $e->getMessage() . "\n";
    exit(1);
}
