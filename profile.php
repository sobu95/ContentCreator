<?php
require_once 'auth_check.php';

$pdo = getDbConnection();

$success = '';
$error = '';

// Pobierz dane użytkownika
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Obsługa zmiany danych
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'change_email') {
        $new_email = trim($_POST['new_email']);
        $password = $_POST['password'];
        
        if (empty($new_email)) {
            $error = 'Nowy email jest wymagany.';
        } else {
            try {
                // Sprawdź hasło
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user_data = $stmt->fetch();
                
                if (!password_verify($password, $user_data['password'])) {
                    $error = 'Nieprawidłowe hasło.';
                } else {
                    // Sprawdź czy email już istnieje
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$new_email, $_SESSION['user_id']]);
                    
                    if ($stmt->fetch()) {
                        $error = 'Użytkownik z tym adresem email już istnieje.';
                    } else {
                        // Zaktualizuj email
                        $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                        $stmt->execute([$new_email, $_SESSION['user_id']]);
                        
                        $_SESSION['user_email'] = $new_email;
                        $user['email'] = $new_email;
                        $success = 'Email został zmieniony pomyślnie.';
                    }
                }
            } catch(Exception $e) {
                $error = 'Błąd zmiany emaila: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (strlen($new_password) < 6) {
            $error = 'Nowe hasło musi mieć co najmniej 6 znaków.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Nowe hasła nie są identyczne.';
        } else {
            try {
                // Sprawdź obecne hasło
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user_data = $stmt->fetch();
                
                if (!password_verify($current_password, $user_data['password'])) {
                    $error = 'Nieprawidłowe obecne hasło.';
                } else {
                    // Zaktualizuj hasło
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                    
                    $success = 'Hasło zostało zmienione pomyślnie.';
                }
            } catch(Exception $e) {
                $error = 'Błąd zmiany hasła: ' . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil użytkownika - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Profil użytkownika</h1>
                </div>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Zmiana adresu email</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="change_email">
                                    <div class="mb-3">
                                        <label for="current_email" class="form-label">Obecny email</label>
                                        <input type="email" class="form-control" id="current_email" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                                    </div>
                                    <div class="mb-3">
                                        <label for="new_email" class="form-label">Nowy email</label>
                                        <input type="email" class="form-control" id="new_email" name="new_email" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Potwierdź hasłem</label>
                                        <input type="password" class="form-control" id="password" name="password" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Zmień email</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Zmiana hasła</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Obecne hasło</label>
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">Nowe hasło</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                                        <div class="form-text">Hasło musi mieć co najmniej 6 znaków.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Potwierdź nowe hasło</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Zmień hasło</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>