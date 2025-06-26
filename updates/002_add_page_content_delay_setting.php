<?php
require_once __DIR__ . '/../config.php';

$pdo = getDbConnection();

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM settings WHERE setting_key = 'page_content_delay_seconds'");
    $stmt->execute();
    $exists = $stmt->fetch();
    if (!$exists || $exists['count'] == 0) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('page_content_delay_seconds', '2')");
        $stmt->execute();
        echo "Added page_content_delay_seconds setting.\n";
    } else {
        echo "page_content_delay_seconds setting already exists.\n";
    }
} catch (Exception $e) {
    echo "Update failed: " . $e->getMessage() . "\n";
    exit(1);
}
