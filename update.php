<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

try {
    // Check if the column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'last_generated_text'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN last_generated_text LONGTEXT NULL AFTER task_data");
        echo "Added last_generated_text column to tasks table.";
    } else {
        echo "last_generated_text column already exists.";
    }
} catch (Exception $e) {
    echo 'Update failed: ' . $e->getMessage();
}
?>
