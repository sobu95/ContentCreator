#!/usr/bin/env php
<?php
// Script for updating the database schema

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line.');
}

$app_root = dirname(__FILE__);
chdir($app_root);

if (!file_exists($app_root . '/config.php')) {
    echo "ERROR: Configuration file not found at: " . $app_root . "/config.php\n";
    exit(1);
}

require_once $app_root . '/config.php';

try {
    $pdo = getDbConnection();

    // Check task_items.previous_text
    $stmt = $pdo->query("SHOW COLUMNS FROM task_items LIKE 'previous_text'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE task_items ADD COLUMN previous_text TEXT NULL");
        echo "Added previous_text column to task_items.\n";
    } else {
        echo "task_items.previous_text already exists.\n";
    }

    // Check tasks.last_generated_text
    $stmt = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'last_generated_text'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN last_generated_text LONGTEXT NULL AFTER task_data");
        echo "Added last_generated_text column to tasks.\n";
    } else {
        echo "tasks.last_generated_text already exists.\n";
    }
} catch (Exception $e) {
    echo 'Update failed: ' . $e->getMessage() . "\n";
    exit(1);
}

