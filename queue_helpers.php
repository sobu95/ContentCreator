<?php
/**
 * Utility helpers for queue processing
 */

/**
 * Updates timestamp of the last queue processor run
 *
 * @param PDO $pdo Database connection
 */
function updateLastRunTimestamp(PDO $pdo) {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value)
            VALUES ('last_queue_run', NOW())
            ON DUPLICATE KEY UPDATE setting_value = NOW()"
        );
        $stmt->execute();
    } catch (Exception $e) {
        // Assumes logMessage is defined by the including script
        if (function_exists('logMessage')) {
            logMessage('Failed to update last run timestamp: ' . $e->getMessage(), 'error');
        }
    }
}

/**
 * Checks if there are pending queue items to process
 *
 * @param PDO $pdo Database connection
 * @return bool True when pending items exist
 */
function hasQueueItems(PDO $pdo) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) as count
         FROM task_queue tq
         WHERE tq.status = 'pending'
           AND tq.attempts < tq.max_attempts
           AND (tq.attempts > 0 OR tq.created_at <= NOW() - INTERVAL 1 MINUTE)"
    );
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['count'] > 0;
}
?>
