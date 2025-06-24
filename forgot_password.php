<?php
session_start();
require_once 'csrf.php';

// Sprawdź czy aplikacja jest zainstalowana
if (!file_exists('config.php')) {
    header('Location: install.php');
    exit;
}

require_once 'config.php';
verify_csrf();

// Jeśli użytkownik jest już zalogowany, przekieruj do dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$success = '';
$error = '';

if ($_POST) {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $error = 'Email jest wymagany.';
    } else {
        try {
            $pdo = getDbConnection();
            
            // Sprawdź czy użytkownik istnieje
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Wygeneruj token resetowania
                $reset_token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Zapisz token w bazie danych
                $stmt = $pdo->prepare("
                    INSERT INTO password_resets (user_id, token, expires_at) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    token = VALUES(token), 
                    expires_at = VALUES(expires_at)
                ");
                $stmt->execute([$user['id'], $reset_token, $expires_at]);
                
                // W rzeczywistej aplikacji tutaj wysłałbyś email
                // Na potrzeby demonstracji wyświetlamy link
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $reset_token;
                
                $success = "Link do resetowania hasła został wygenerowany. W rzeczywistej aplikacji zostałby wysłany na email.<br><br>
                           <strong>Link resetowania:</strong><br>
                           <a href='$reset_link' class='btn btn-primary btn-sm'>Resetuj hasło</a><br><br>
                           <small class='text-muted'>Link jest ważny przez 1 godzinę.</small>";
            } else {
                // Nie ujawniamy czy email istnieje w systemie
                $success = "Jeśli podany email istnieje w systemie, link do resetowania hasła został wysłany.";
            }
            
        } catch(Exception $e) {
            $error = 'Błąd: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zapomniałem hasła - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card mt-5">
                    <div class="card-header">
                        <h3 class="mb-0">Zapomniałem hasła</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                        <?php else: ?>
                        
                        <p class="text-muted">Podaj swój adres email, a wyślemy Ci link do resetowania hasła.</p>
                        
                        <form method="POST">
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Wyślij link resetowania</button>
                        </form>
                        
                        <?php endif; ?>
                        
                        <hr>
                        <div class="text-center">
                            <a href="login.php">Powrót do logowania</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>