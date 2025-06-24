<?php
/**
 * Skrypt do pobierania treści stron dla zadań
 * Uruchamiany przed głównym procesorem kolejki
 */

require_once 'config.php';
require_once 'fetch_page_content.php';

// Zmienna globalna do określania trybu (CLI vs WWW)
$is_cli_mode = php_sapi_name() === 'cli';

/**
 * Loguje wiadomość do konsoli/przeglądarki i do pliku logu.
 */
function logPageContentMessage($message, $type = 'info') {
    global $is_cli_mode;

    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    
    // Zapisz do konsoli/terminala (jeśli skrypt jest uruchamiany w CLI)
    if ($is_cli_mode) {
        echo $log_entry;
    } else {
        // Dla WWW, wyślij do przeglądarki w HTML
        $class = '';
        if ($type === 'error') $class = 'error';
        if ($type === 'success') $class = 'success';
        echo "<div class='log-entry {$class}'>[{$timestamp}] {$message}</div>\n";
        // Wypłucz bufor, aby wiadomości pojawiały się od razu
        ob_flush();
        flush();
    }

    // Zapisz do pliku log (zawsze)
    $log_dir = __DIR__ . '/logs';
    if (!file_exists($log_dir)) {
        if (!mkdir($log_dir, 0755, true)) {
            error_log("Failed to create log directory: $log_dir");
            return;
        }
    }
    $log_file = $log_dir . '/page_content_' . date('Y-m-d') . '.log';
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Zapisuje timestamp ostatniego uruchomienia procesora treści stron
 */
function updateLastPageContentRunTimestamp($pdo) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES ('last_page_content_run', NOW()) 
            ON DUPLICATE KEY UPDATE setting_value = NOW()
        ");
        $stmt->execute();
    } catch (Exception $e) {
        logPageContentMessage("Failed to update last page content run timestamp: " . $e->getMessage(), 'error');
    }
}

// Start buforowania wyjścia dla trybu WWW
if (!$is_cli_mode) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Page Content Processor</title>
        <meta charset="UTF-8">
        <style>
            body { font-family: monospace; margin: 20px; background-color: #f8f8f8; color: #333; }
            h1 { color: #0056b3; }
            .log-entry { 
                background: #fff; 
                padding: 8px 12px; 
                margin-bottom: 5px; 
                border-radius: 4px; 
                box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
                word-wrap: break-word;
            }
            .error { background-color: #ffe0e0; color: #d9534f; border-left: 4px solid #d9534f; }
            .success { background-color: #e0ffe0; color: #5cb85c; border-left: 4px solid #5cb85c; }
            .info { border-left: 4px solid #007bff; }
            .container { max-width: 900px; margin: 0 auto; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Page Content Processor</h1>
            <p>Processing page content for task items...</p>
    <?php
}

logPageContentMessage("Starting page content processor. Mode: " . ($is_cli_mode ? "CLI" : "WWW"));

try {
    $pdo = getDbConnection();
    updateLastPageContentRunTimestamp($pdo);
    
    // Pobierz elementy zadań, które nie mają jeszcze pobranej treści strony
    $stmt = $pdo->prepare("
        SELECT ti.id, ti.url, ti.task_id
        FROM task_items ti
        WHERE (ti.page_content IS NULL OR ti.page_content = '')
        AND ti.status = 'pending'
        ORDER BY ti.created_at ASC
        LIMIT 10
    ");
    $stmt->execute();
    $task_items = $stmt->fetchAll();
    
    if (empty($task_items)) {
        logPageContentMessage("No task items need page content processing");
    } else {
        logPageContentMessage("Found " . count($task_items) . " task items to process");
        
        foreach ($task_items as $item) {
            logPageContentMessage("Processing page content for task item ID: {$item['id']}, URL: {$item['url']}");
            
            try {
                $page_content = fetchAndSavePageContent($pdo, $item['id'], $item['url']);
                
                if (strpos($page_content, 'Nie udało się pobrać') === 0 || strpos($page_content, 'Błąd') === 0) {
                    logPageContentMessage("Failed to fetch content for {$item['url']}: $page_content", 'error');
                    
                    // Oznacz element jako failed
                    $stmt = $pdo->prepare("UPDATE task_items SET status = 'failed' WHERE id = ?");
                    $stmt->execute([$item['id']]);
                } else {
                    logPageContentMessage("Successfully fetched content for {$item['url']} (" . strlen($page_content) . " characters)", 'success');
                }
                
                // Krótkie opóźnienie między żądaniami
                sleep(1);
                
            } catch (Exception $e) {
                logPageContentMessage("Error processing task item {$item['id']}: " . $e->getMessage(), 'error');
                
                // Oznacz element jako failed
                $stmt = $pdo->prepare("UPDATE task_items SET status = 'failed' WHERE id = ?");
                $stmt->execute([$item['id']]);
            }
        }
    }
    
} catch (Exception $e) {
    logPageContentMessage("CRITICAL ERROR: " . $e->getMessage(), 'error');
    if (!$is_cli_mode) { echo "</div></body></html>"; ob_end_flush(); }
    exit(1);
}

logPageContentMessage("Page content processor finished");

if (!$is_cli_mode) {
    echo "</div></body></html>";
    ob_end_flush();
}

?>