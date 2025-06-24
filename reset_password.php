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

$error = '';
$success = '';
$valid_token = false;
$user_id = null;

// Sprawdź token
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT pr.user_id, u.email 
            FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.token = ? AND pr.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $reset_data = $stmt->fetch();
        
        if ($reset_data) {
            $valid_token = true;
            $user_id = $reset_data['user_id'];
        } else {
            $error = 'Link resetowania jest nieprawidłowy lub wygasł.';
        }
        
    } catch(Exception $e) {
        $error = 'Błąd: ' . $e->getMessage();
    }
} else {
    $error = 'Brak tokenu resetowania.';
}

// Obsługa zmiany hasła
if ($_POST && $valid_token) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (strlen($password) < 6) {
        $error = 'Hasło musi mieć co najmniej 6 znaków.';
    } elseif ($password !== $confirm_password) {
        $error = 'Hasła nie są identyczne.';
    } else {
        try {
            $pdo = getDbConnection();
            
            // Zaktualizuj hasło
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            // Usuń token resetowania
            $stmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            $success = 'Hasło zostało zmienione pomyślnie. Możesz się teraz zalogować.';
            $valid_token = false; // Ukryj formularz
            
        } catch(Exception $e) {
            $error = 'Błąd zmiany hasła: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resetowanie hasła - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card mt-5">
                    <div class="card-header">
                        <h3 class="mb-0">Resetowanie hasła</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                            <a href="login.php" class="btn btn-primary">Przejdź do logowania</a>
                        <?php elseif ($valid_token): ?>
                        
                        <p class="text-muted">Wprowadź nowe hasło dla swojego konta.</p>
                        
                        <form method="POST">
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label for="password" class="form-label">Nowe hasło</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                                <div class="form-text">Hasło musi mieć co najmniej 6 znaków.</div>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Potwierdź nowe hasło</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Zmień hasło</button>
                        </form>
                        
                        <?php else: ?>
                            <div class="text-center">
                                <a href="login.php" class="btn btn-secondary">Powrót do logowania</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>