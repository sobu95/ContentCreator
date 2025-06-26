<?php
// Load sensitive configuration from environment variables or an optional
// `config.local.php` file which is ignored by version control.
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME'));
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS'));
}

if (!defined('GEMINI_API_KEY')) {
    $apiKey = getenv('GEMINI_API_KEY');
    if ($apiKey !== false) {
        define('GEMINI_API_KEY', $apiKey);
    }
}

// Whether cURL requests should verify SSL certificates.
// Defaults to true for security. Set to false in `config.local.php` or via the
// `CURL_VERIFY_SSL` environment variable if you need to bypass verification
// during local development.
if (!defined('CURL_VERIFY_SSL')) {
    $verify = getenv('CURL_VERIFY_SSL');
    if ($verify === false) {
        $verify = true; // secure default
    } else {
        $verify = filter_var($verify, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verify === null) {
            $verify = true;
        }
    }
    define('CURL_VERIFY_SSL', $verify);
}

function getDbConnection() {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die('Błąd połączenia z bazą danych: ' . $e->getMessage());
    }
}
?>
