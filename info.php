<?php
// Aby upewnić się, że nazwa użytkownika nie jest widoczna publicznie
// Możesz dodać ochronę, jeśli chcesz (ale do sprawdzenia phpinfo nie jest to wymagane)
// if (!isset($_SERVER['PHP_AUTH_USER'])) {
//     header('WWW-Authenticate: Basic realm="My protected area"');
//     header('HTTP/1.0 401 Unauthorized');
//     echo "Unauthorized access.";
//     exit;
// }

phpinfo();
?>