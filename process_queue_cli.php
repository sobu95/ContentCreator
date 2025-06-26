#!/usr/bin/env php
<?php
/**
 * Dedykowany skrypt CLI dla przetwarzania kolejki zadań
 * Ten plik jest zoptymalizowany do uruchamiania przez cron
 */

// Sprawdź czy skrypt jest uruchamiany z linii komend
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line.');
}

// Ustaw ścieżkę do katalogu głównego aplikacji
$app_root = dirname(__FILE__);
chdir($app_root);

// Sprawdź czy plik konfiguracyjny istnieje
if (!file_exists($app_root . '/config.php')) {
    echo "ERROR: Configuration file not found at: " . $app_root . "/config.php\n";
    exit(1);
}

require_once $app_root . '/config.php';
require_once $app_root . '/queue_helpers.php';

/**
 * Loguje wiadomość do pliku i konsoli
 */
function logMessage($message, $type = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    
    // Wyświetl w konsoli
    echo $log_entry;
    
    // Zapisz do pliku log
    $log_dir = __DIR__ . '/logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $log_file = $log_dir . '/queue_cli_' . date('Y-m-d') . '.log';
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}


/**
 * Pobiera klucz API Gemini
 */
function getGeminiApiKey($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'gemini_api_key'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : null;
}

/**
 * Pobiera klucz API Anthropic
 */
function getAnthropicApiKey($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'anthropic_api_key'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : null;
}

// Główna logika
try {
    logMessage("Starting CLI queue processor");
    
    $pdo = getDbConnection();
    updateLastRunTimestamp($pdo);
    
    $gemini_key = getGeminiApiKey($pdo);
    $anthropic_key = getAnthropicApiKey($pdo);
    if (!$gemini_key && !$anthropic_key) {
        logMessage("ERROR: No API keys configured", 'error');
        exit(1);
    }
    
    // Sprawdź czy są zadania do przetworzenia
    if (!hasQueueItems($pdo)) {
        logMessage("No items in queue to process");
        exit(0);
    }
    
    logMessage("Found items in queue, calling main processor");
    
    // Wywołaj główny procesor
    $output = [];
    $return_code = 0;
    
    // Użyj tego samego interpretera PHP, który uruchomił ten skrypt
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($app_root . "/process_queue.php") . " 2>&1";
    exec($command, $output, $return_code);
    
    // Zaloguj wynik
    foreach ($output as $line) {
        logMessage("PROCESSOR: " . $line);
    }
    
    if ($return_code === 0) {
        logMessage("Queue processor completed successfully");
    } else {
        logMessage("Queue processor failed with return code: $return_code", 'error');
        exit($return_code);
    }
    
} catch (Exception $e) {
    logMessage("CRITICAL ERROR: " . $e->getMessage(), 'error');
    exit(1);
}

logMessage("CLI queue processor finished");
exit(0);
?>