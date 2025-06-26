<?php
define('DB_HOST', 'mysql67.mydevil.net');
define('DB_NAME', 'm1259_seo');
define('DB_USER', 'm1259_seo');
define('DB_PASS', '.I52@-FwFkjQcSuye3491$oIU7[0N6');

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