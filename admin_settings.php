<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

// Określ ścieżkę bazową dla crona
// __DIR__ daje /home/goupcomp/domains/goup.com.pl/private_html/content
$base_cron_path = __DIR__; 

$success = '';
$error = '';

// Obsługa akcji administracyjnych
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_settings':
            $gemini_api_key = trim($_POST['gemini_api_key']);
            $anthropic_api_key = trim($_POST['anthropic_api_key']);
            $processing_delay = intval($_POST['processing_delay_minutes']);
            $page_content_delay = intval($_POST['page_content_delay_seconds']);
            
            try {
                // Zapisz klucz API Gemini
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('gemini_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$gemini_api_key]);

                // Zapisz klucz API Anthropic Claude
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('anthropic_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$anthropic_api_key]);
                
                // Zapisz opóźnienie przetwarzania
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('processing_delay_minutes', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$processing_delay]);

                // Zapisz opóźnienie pobierania treści stron
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('page_content_delay_seconds', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$page_content_delay]);
                
                $success = 'Ustawienia zostały zapisane.';
            } catch(Exception $e) {
                $error = 'Błąd zapisywania ustawień: ' . $e->getMessage();
            }
            break;

        case 'save_model':
            $model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : null;
            $name = trim($_POST['name']);
            $endpoint = trim($_POST['endpoint']);
            $max_tokens = intval($_POST['max_output_tokens']);
            $config = json_encode([
                'temperature' => floatval($_POST['temperature']),
                'topK' => intval($_POST['topK']),
                'topP' => floatval($_POST['topP'])
            ]);

            try {
                if ($model_id) {
                    $stmt = $pdo->prepare("UPDATE language_models SET name=?, endpoint=?, max_output_tokens=?, generation_config=? WHERE id=?");
                    $stmt->execute([$name, $endpoint, $max_tokens, $config, $model_id]);
                    $success = 'Model został zaktualizowany.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO language_models (name, endpoint, max_output_tokens, generation_config) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $endpoint, $max_tokens, $config]);
                    $success = 'Model został dodany.';
                }
            } catch(Exception $e) {
                $error = 'Błąd zapisu modelu: ' . $e->getMessage();
            }
            break;

        case 'delete_model':
            $model_id = intval($_POST['model_id']);
            try {
                $stmt = $pdo->prepare("DELETE FROM language_models WHERE id = ?");
                $stmt->execute([$model_id]);
                $success = 'Model został usunięty.';
            } catch(Exception $e) {
                $error = 'Błąd usuwania modelu: ' . $e->getMessage();
            }
            break;
            
        case 'clear_logs':
            try {
                $stmt = $pdo->prepare("DELETE FROM prompt_logs");
                $stmt->execute();
                $success = 'Wszystkie logi promptów zostały usunięte.';
            } catch(Exception $e) {
                $error = 'Błąd usuwania logów: ' . $e->getMessage();
            }
            break;
            
        case 'clear_old_tasks':
            try {
                $pdo->beginTransaction();
                
                // Usuń zadania starsze niż 30 dni wraz z powiązanymi danymi
                $stmt = $pdo->prepare("
                    DELETE pl FROM prompt_logs pl
                    JOIN task_items ti ON pl.task_item_id = ti.id
                    JOIN tasks t ON ti.task_id = t.id
                    WHERE t.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                ");
                $stmt->execute();
                
                $stmt = $pdo->prepare("
                    DELETE gc FROM generated_content gc
                    JOIN task_items ti ON gc.task_item_id = ti.id
                    JOIN tasks t ON ti.task_id = t.id
                    WHERE t.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                ");
                $stmt->execute();
                
                $stmt = $pdo->prepare("
                    DELETE tq FROM task_queue tq
                    JOIN task_items ti ON tq.task_item_id = ti.id
                    JOIN tasks t ON ti.task_id = t.id
                    WHERE t.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                ");
                $stmt->execute();
                
                $stmt = $pdo->prepare("
                    DELETE ti FROM task_items ti
                    JOIN tasks t ON ti.task_id = t.id
                    WHERE t.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                ");
                $stmt->execute();
                
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
                $stmt->execute();
                
                $pdo->commit();
                $success = 'Stare zadania (starsze niż 30 dni) zostały usunięte.';
            } catch(Exception $e) {
                $pdo->rollBack();
                $error = 'Błąd usuwania starych zadań: ' . $e->getMessage();
            }
            break;
            
        case 'process_page_content':
            try {
                // Uruchom skrypt pobierania treści stron
                $output = [];
                $return_code = 0;
                $command = "/usr/bin/php " . escapeshellarg(__DIR__ . "/process_page_content.php") . " 2>&1";
                exec($command, $output, $return_code);
                
                if ($return_code === 0) {
                    $success = 'Pobieranie treści stron zostało uruchomione. Sprawdź logi aby zobaczyć postęp.';
                } else {
                    $error = 'Błąd uruchamiania pobierania treści: ' . implode("\n", $output);
                }
            } catch(Exception $e) {
                $error = 'Błąd uruchamiania pobierania treści: ' . $e->getMessage();
            }
            break;
    }
}

// Pobierz aktualne ustawienia
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings_raw = $stmt->fetchAll();

$settings = [];
foreach ($settings_raw as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

// Pobierz statystyki systemu
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
$user_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM projects");
$project_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM tasks");
$task_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM generated_content");
$content_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM task_queue WHERE status = 'pending'");
$queue_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM prompt_logs");
$logs_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM task_items WHERE page_content IS NULL OR page_content = ''");
$missing_content_count = $stmt->fetch()['count'];

// Pobierz informacje o ostatnich uruchomieniach procesów
$last_runs = [];

// Ostatnie uruchomienie procesora kolejki
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'last_queue_run'");
$stmt->execute();
$result = $stmt->fetch();
$last_runs['queue'] = $result ? $result['setting_value'] : null;

// Ostatnie uruchomienie pobierania treści stron
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'last_page_content_run'");
$stmt->execute();
$result = $stmt->fetch();
$last_runs['page_content'] = $result ? $result['setting_value'] : null;

// Sprawdź czy istnieją pliki logów
$log_dir = __DIR__ . '/logs';
$log_files = [];
if (is_dir($log_dir)) {
    $files = scandir($log_dir);
    foreach ($files as $file) {
        if (preg_match('/^(queue|page_content)_(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
            $type = $matches[1];
            $date = $matches[2];
            $log_files[$type][$date] = [
                'file' => $file,
                'size' => filesize($log_dir . '/' . $file),
                'modified' => filemtime($log_dir . '/' . $file)
            ];
        }
    }
}

// Pobierz modele językowe
$stmt = $pdo->query("SELECT * FROM language_models ORDER BY name");
$language_models = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ustawienia systemu - Generator treści SEO</title>
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
                    <h1 class="h2">Ustawienia systemu</h1>
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
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Konfiguracja API</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="save_settings">
                                    <div class="mb-3">
                                        <label for="gemini_api_key" class="form-label">Klucz API Google Gemini *</label>
                                        <input type="password" class="form-control" id="gemini_api_key" name="gemini_api_key"
                                               value="<?= htmlspecialchars($settings['gemini_api_key'] ?? '') ?>" required>
                                        <div class="form-text">
                                            Klucz API potrzebny do generowania treści. Możesz go uzyskać w
                                            <a href="https://makersuite.google.com/app/apikey" target="_blank">Google AI Studio</a>.
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="anthropic_api_key" class="form-label">Klucz API Anthropic Claude</label>
                                        <input type="password" class="form-control" id="anthropic_api_key" name="anthropic_api_key"
                                               value="<?= htmlspecialchars($settings['anthropic_api_key'] ?? '') ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="processing_delay_minutes" class="form-label">Opóźnienie przetwarzania (minuty)</label>
                                        <input type="number" class="form-control" id="processing_delay_minutes" name="processing_delay_minutes"
                                               value="<?= htmlspecialchars($settings['processing_delay_minutes'] ?? '1') ?>" min="0" max="60">
                                        <div class="form-text">
                                            Czas oczekiwania przed pierwszą próbą przetwarzania zadania (w minutach).
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="page_content_delay_seconds" class="form-label">Opóźnienie pobierania treści (sekundy)</label>
                                        <input type="number" class="form-control" id="page_content_delay_seconds" name="page_content_delay_seconds"
                                               value="<?= htmlspecialchars($settings['page_content_delay_seconds'] ?? '2') ?>" min="0" max="30">
                                        <div class="form-text">
                                            Pauza między kolejnymi żądaniami pobierania treści stron (w sekundach).
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Zapisz ustawienia</button>
                                </form>
                            </div>
                        </div>

                        <div class="card mt-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Modele językowe</h5>
                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modelModal">
                                    <i class="fas fa-plus"></i> Dodaj model
                                </button>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Nazwa</th>
                                                <th>Endpoint</th>
                                                <th>Maks. tokenów</th>
                                                <th>Akcje</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($language_models as $lm): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($lm['name']) ?></td>
                                                    <td><?= htmlspecialchars($lm['endpoint']) ?></td>
                                                    <td><?= $lm['max_output_tokens'] ?></td>
                                                    <td>
                                                        <div class="btn-group">
                                                            <button class="btn btn-sm btn-outline-secondary" onclick='editModel(<?= json_encode($lm) ?>)'>
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <form method="POST" style="display:inline-block;" onsubmit="return confirm('Usunąć model?')">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete_model">
                                                                <input type="hidden" name="model_id" value="<?= $lm['id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Monitoring procesów -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">Monitoring procesów</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-cogs"></i> Procesor kolejki</h6>
                                        <?php if ($last_runs['queue']): ?>
                                            <p class="text-success">
                                                <i class="fas fa-check-circle"></i>
                                                Ostatnie uruchomienie: <?= date('d.m.Y H:i:s', strtotime($last_runs['queue'])) ?>
                                            </p>
                                        <?php else: ?>
                                            <p class="text-warning">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                Brak informacji o ostatnim uruchomieniu
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($log_files['queue'])): ?>
                                            <small class="text-muted">
                                                Dostępne logi: 
                                                <?php foreach (array_slice($log_files['queue'], -3, 3, true) as $date => $info): ?>
                                                    <span class="badge bg-secondary"><?= $date ?></span>
                                                <?php endforeach; ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-download"></i> Pobieranie treści stron</h6>
                                        <?php if ($last_runs['page_content']): ?>
                                            <p class="text-success">
                                                <i class="fas fa-check-circle"></i>
                                                Ostatnie uruchomienie: <?= date('d.m.Y H:i:s', strtotime($last_runs['page_content'])) ?>
                                            </p>
                                        <?php else: ?>
                                            <p class="text-warning">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                Brak informacji o ostatnim uruchomieniu
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($log_files['page_content'])): ?>
                                            <small class="text-muted">
                                                Dostępne logi: 
                                                <?php foreach (array_slice($log_files['page_content'], -3, 3, true) as $date => $info): ?>
                                                    <span class="badge bg-secondary"><?= $date ?></span>
                                                <?php endforeach; ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">Zarządzanie treścią stron</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Pobieranie treści stron</h6>
                                        <p class="text-muted">Uruchom pobieranie treści dla zadań, które nie mają jeszcze pobranej treści strony.</p>
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="process_page_content">
                                            <button type="submit" class="btn btn-info">
                                                <i class="fas fa-download"></i> Pobierz treści stron (<?= $missing_content_count ?>)
                                            </button>
                                        </form>
                                        <div class="mt-2">
                                            <a href="process_page_content.php" target="_blank" class="btn btn-sm btn-outline-info">
                                                <i class="fas fa-external-link-alt"></i> Test w przeglądarce
                                            </a>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Informacje</h6>
                                        <p class="text-muted">Zadania bez pobranej treści strony nie będą przetwarzane przez główny procesor kolejki.</p>
                                        <p class="text-muted">Pobieranie treści można uruchamiać ręcznie lub automatycznie przez cron.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">Zarządzanie danymi</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Czyszczenie logów</h6>
                                        <p class="text-muted">Usuń wszystkie logi promptów z bazy danych.</p>
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="clear_logs">
                                            <button type="submit" class="btn btn-warning" 
                                                    onclick="return confirm('Czy na pewno chcesz usunąć wszystkie logi promptów?')">
                                                <i class="fas fa-trash"></i> Usuń logi (<?= $logs_count ?>)
                                            </button>
                                        </form>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Czyszczenie starych zadań</h6>
                                        <p class="text-muted">Usuń zadania starsze niż 30 dni wraz z powiązanymi danymi.</p>
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="clear_old_tasks">
                                            <button type="submit" class="btn btn-danger" 
                                                    onclick="return confirm('Czy na pewno chcesz usunąć wszystkie zadania starsze niż 30 dni?')">
                                                <i class="fas fa-trash"></i> Usuń stare zadania
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">Informacje o systemie</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Wersja PHP</h6>
                                        <p><?= phpversion() ?></p>
                                        
                                        <h6>Rozszerzenia PHP</h6>
                                        <ul class="list-unstyled">
                                            <li>
                                                <i class="fas fa-<?= extension_loaded('pdo') ? 'check text-success' : 'times text-danger' ?>"></i>
                                                PDO
                                            </li>
                                            <li>
                                                <i class="fas fa-<?= extension_loaded('curl') ? 'check text-success' : 'times text-danger' ?>"></i>
                                                cURL
                                            </li>
                                            <li>
                                                <i class="fas fa-<?= extension_loaded('zip') ? 'check text-success' : 'times text-danger' ?>"></i>
                                                ZIP
                                            </li>
                                            <li>
                                                <i class="fas fa-<?= extension_loaded('json') ? 'check text-success' : 'times text-danger' ?>"></i>
                                                JSON
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Limity PHP</h6>
                                        <ul class="list-unstyled">
                                            <li>Max execution time: <?= ini_get('max_execution_time') ?>s</li>
                                            <li>Memory limit: <?= ini_get('memory_limit') ?></li>
                                            <li>Upload max filesize: <?= ini_get('upload_max_filesize') ?></li>
                                            <li>Post max size: <?= ini_get('post_max_size') ?></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Statystyki systemu</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3">
                                            <h4 class="text-primary"><?= $user_count ?></h4>
                                            <small>Użytkownicy</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3">
                                            <h4 class="text-success"><?= $project_count ?></h4>
                                            <small>Projekty</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3">
                                            <h4 class="text-info"><?= $task_count ?></h4>
                                            <small>Zadania</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3">
                                            <h4 class="text-warning"><?= $content_count ?></h4>
                                            <small>Treści</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="text-center">
                                    <h5 class="text-danger"><?= $queue_count ?></h5>
                                    <small>Zadania w kolejce</small>
                                </div>
                                
                                <hr>
                                
                                <div class="text-center">
                                    <h5 class="text-secondary"><?= $logs_count ?></h5>
                                    <small>Logi promptów</small>
                                </div>
                                
                                <hr>
                                
                                <div class="text-center">
                                    <h5 class="text-warning"><?= $missing_content_count ?></h5>
                                    <small>Brak treści stron</small>
                                </div>
                            </div>
                        </div>
                        
                                                <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">Zarządzanie kolejką</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">
                                    Aby uruchomić przetwarzanie kolejki, wykonaj poniższą komendę na serwerze:
                                </p>
                                <pre><code>/usr/bin/php <?= htmlspecialchars($base_cron_path) ?>/process_queue_cli.php</code></pre>
                                
                                <p class="text-muted mt-3">
                                    Lub dodaj do cron-a dla automatycznego przetwarzania (np. co minutę):
                                </p>
                                <pre><code>* * * * * /usr/bin/php <?= htmlspecialchars($base_cron_path) ?>/process_queue_cli.php >> <?= htmlspecialchars($base_cron_path) ?>/logs/queue_cron.log 2>&1</code></pre>
                                
                                <p class="text-muted mt-3">
                                    Dla pobierania treści stron (np. co 5 minut):
                                </p>
                                <pre><code>*/5 * * * * /usr/bin/php <?= htmlspecialchars($base_cron_path) ?>/process_page_content.php >> <?= htmlspecialchars($base_cron_path) ?>/logs/page_content_cron.log 2>&1</code></pre>

                                <p class="alert alert-info small mt-3">
                                    Powyższe komendy zakładają, że `php` jest dostępne jako `/usr/bin/php` w środowisku Crona.<br>
                                    Jeśli napotkasz problemy, spróbuj użyć `which php` na serwerze, aby znaleźć dokładną ścieżkę do interpretera PHP, lub skontaktuj się z administratorem hostingu.<br>
                                    `>> ... 2>&1` przekierowuje wyjście i błędy do plików logów, co jest bardzo pomocne w debugowaniu Crona.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="modelModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_model">
                    <input type="hidden" name="model_id" id="model_id" value="">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modelModalTitle">Nowy model</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nazwa *</label>
                            <input type="text" class="form-control" name="name" id="model_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Endpoint *</label>
                            <input type="text" class="form-control" name="endpoint" id="model_endpoint" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Maks. tokenów *</label>
                            <input type="number" class="form-control" name="max_output_tokens" id="model_tokens" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Temperature</label>
                            <input type="number" step="0.1" class="form-control" name="temperature" id="model_temperature" value="0.7">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">topK</label>
                            <input type="number" class="form-control" name="topK" id="model_topK" value="40">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">topP</label>
                            <input type="number" step="0.01" class="form-control" name="topP" id="model_topP" value="0.95">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                        <button type="submit" class="btn btn-primary">Zapisz</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editModel(model) {
            document.getElementById('modelModalTitle').textContent = 'Edytuj model';
            document.getElementById('model_id').value = model.id;
            document.getElementById('model_name').value = model.name;
            document.getElementById('model_endpoint').value = model.endpoint;
            document.getElementById('model_tokens').value = model.max_output_tokens;
            const cfg = model.generation_config ? JSON.parse(model.generation_config) : {};
            document.getElementById('model_temperature').value = cfg.temperature ?? 0.7;
            document.getElementById('model_topK').value = cfg.topK ?? 40;
            document.getElementById('model_topP').value = cfg.topP ?? 0.95;
            var modal = new bootstrap.Modal(document.getElementById('modelModal'));
            modal.show();
        }

        document.getElementById('modelModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('modelModalTitle').textContent = 'Nowy model';
            document.getElementById('model_id').value = '';
            document.getElementById('model_name').value = '';
            document.getElementById('model_endpoint').value = '';
            document.getElementById('model_tokens').value = '20000';
            document.getElementById('model_temperature').value = '0.7';
            document.getElementById('model_topK').value = '40';
            document.getElementById('model_topP').value = '0.95';
        });
    </script>
</body>
</html>