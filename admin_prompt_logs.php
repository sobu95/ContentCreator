<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

// Pobierz logi promptów z filtrami
$task_id = isset($_GET['task_id']) ? intval($_GET['task_id']) : null;
$prompt_type = isset($_GET['prompt_type']) ? $_GET['prompt_type'] : '';

$where_conditions = ['1=1'];
$params = [];

if ($task_id) {
    $where_conditions[] = 't.id = ?';
    $params[] = $task_id;
}

if ($prompt_type) {
    $where_conditions[] = 'pl.prompt_type = ?';
    $params[] = $prompt_type;
}

$stmt = $pdo->prepare("
    SELECT pl.*, ti.url, t.name as task_name, p.name as project_name, u.email as user_email
    FROM prompt_logs pl
    JOIN task_items ti ON pl.task_item_id = ti.id
    JOIN tasks t ON ti.task_id = t.id
    JOIN projects p ON t.project_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY pl.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$prompt_logs = $stmt->fetchAll();

// Pobierz listę zadań dla filtra
$stmt = $pdo->query("
    SELECT t.id, t.name, p.name as project_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    ORDER BY t.created_at DESC
    LIMIT 50
");
$tasks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logi promptów - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .prompt-preview {
            max-height: 150px;
            overflow-y: auto;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.8em;
        }
        .response-preview {
            max-height: 100px;
            overflow-y: auto;
            background-color: #e8f5e8;
            padding: 8px;
            border-radius: 5px;
            font-size: 0.8em;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Logi promptów</h1>
                </div>
                
                <!-- Filtry -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="task_id" class="form-label">Zadanie</label>
                                <select class="form-select" name="task_id" id="task_id">
                                    <option value="">Wszystkie zadania</option>
                                    <?php foreach ($tasks as $task): ?>
                                        <option value="<?= $task['id'] ?>" <?= $task_id == $task['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($task['project_name']) ?> - <?= htmlspecialchars($task['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="prompt_type" class="form-label">Typ promptu</label>
                                <select class="form-select" name="prompt_type" id="prompt_type">
                                    <option value="">Wszystkie typy</option>
                                    <option value="generate" <?= $prompt_type === 'generate' ? 'selected' : '' ?>>Generowanie</option>
                                    <option value="verify" <?= $prompt_type === 'verify' ? 'selected' : '' ?>>Weryfikacja</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary me-2">Filtruj</button>
                                    <a href="admin_prompt_logs.php" class="btn btn-outline-secondary">Wyczyść</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php if (empty($prompt_logs)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                        <h4>Brak logów promptów</h4>
                        <p class="text-muted">Nie znaleziono logów promptów spełniających kryteria.</p>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Logi promptów (<?= count($prompt_logs) ?>)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-sm">
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th>Użytkownik</th>
                                            <th>Zadanie</th>
                                            <th>URL</th>
                                            <th>Typ</th>
                                            <th>Prompt</th>
                                            <th>Odpowiedź</th>
                                            <th>Akcje</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($prompt_logs as $log): ?>
                                            <tr>
                                                <td>
                                                    <small><?= date('d.m.Y H:i:s', strtotime($log['created_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <small><?= htmlspecialchars($log['user_email']) ?></small>
                                                </td>
                                                <td>
                                                    <small><?= htmlspecialchars($log['task_name']) ?></small>
                                                </td>
                                                <td>
                                                    <div style="max-width: 150px; word-break: break-all; font-size: 0.7em;">
                                                        <?= htmlspecialchars($log['url']) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $log['prompt_type'] === 'generate' ? 'bg-primary' : 'bg-success' ?>">
                                                        <?= $log['prompt_type'] === 'generate' ? 'Gen' : 'Ver' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="prompt-preview">
                                                        <?= htmlspecialchars(substr($log['prompt_content'], 0, 200)) ?>
                                                        <?php if (strlen($log['prompt_content']) > 200): ?>...<?php endif; ?>
                                                    </div>
                                                    <small class="text-muted"><?= strlen($log['prompt_content']) ?> znaków</small>
                                                </td>
                                                <td>
                                                    <?php if ($log['api_response']): ?>
                                                        <div class="response-preview">
                                                            <?= htmlspecialchars(substr($log['api_response'], 0, 100)) ?>
                                                            <?php if (strlen($log['api_response']) > 100): ?>...<?php endif; ?>
                                                        </div>
                                                        <small class="text-muted"><?= strlen($log['api_response']) ?> znaków</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Brak odpowiedzi</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" 
                                                            onclick="viewPromptDetails(<?= $log['id'] ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Modal szczegółów promptu -->
    <div class="modal fade" id="promptModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Szczegóły promptu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="promptModalBody">
                        <!-- Treść będzie wczytana przez AJAX -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function escapeHtml(text) {
            if (!text) return '';
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        async function viewPromptDetails(logId) {
            document.getElementById('promptModalBody').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Ładowanie...</div>';
            
            const modal = new bootstrap.Modal(document.getElementById('promptModal'));
            modal.show();
            
            try {
                const response = await fetch('ajax_get_prompt_log.php?id=' + logId);
                const data = await response.json();
                
                if (data.error) {
                    document.getElementById('promptModalBody').innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
                } else {
                    let html = '<div class="row">';
                    html += '<div class="col-md-6">';
                    html += '<h6>Prompt (' + data.prompt_type + '):</h6>';
                    html += '<pre class="bg-light p-3" style="max-height: 400px; overflow-y: auto;">' + escapeHtml(data.prompt_content) + '</pre>';
                    html += '</div>';
                    html += '<div class="col-md-6">';
                    html += '<h6>Odpowiedź API:</h6>';
                    if (data.api_response) {
                        html += '<div class="bg-light p-3" style="max-height: 400px; overflow-y: auto;">' + escapeHtml(data.api_response) + '</div>';
                    } else {
                        html += '<p class="text-muted">Brak odpowiedzi</p>';
                    }
                    html += '</div>';
                    html += '</div>';
                    
                    document.getElementById('promptModalBody').innerHTML = html;
                }
            } catch (error) {
                document.getElementById('promptModalBody').innerHTML = '<div class="alert alert-danger">Błąd ładowania danych</div>';
            }
        }
    </script>
</body>
</html>
