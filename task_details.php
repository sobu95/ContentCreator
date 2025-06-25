<?php
require_once 'auth_check.php';

// Ustawienie kodowania wewnętrznego dla funkcji multibyte
mb_internal_encoding('UTF-8');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: tasks.php');
    exit;
}

$task_id = intval($_GET['id']);
$pdo = getDbConnection();

// Sprawdź czy zadanie należy do użytkownika
$stmt = $pdo->prepare("
    SELECT t.*, p.name as project_name, ct.name as content_type_name, ct.fields, am.label as api_model_label -- Pobieramy 'label' z 'api_models'
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    JOIN content_types ct ON t.content_type_id = ct.id
    LEFT JOIN api_models am ON t.model_id = am.id -- Tutaj łączymy z api_models przez model_id
    WHERE t.id = ? AND p.user_id = ?
");
$stmt->execute([$task_id, $_SESSION['user_id']]);
$task = $stmt->fetch();

if (!$task) {
    header('Location: tasks.php');
    exit;
}

$success = '';
$error = '';
if (isset($_GET['cloned'])) {
    $success = 'Zadanie zostało sklonowane i dodane do kolejki.';
}

$stmt = $pdo->query("SELECT id, label FROM api_models ORDER BY label");
$api_models = $stmt->fetchAll();

$stmt = $pdo->query("SELECT id, name FROM content_types ORDER BY name");
$content_types = $stmt->fetchAll();

// Obsługa regeneracji tekstu
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'regenerate') {
    $task_item_id = intval($_POST['task_item_id']);
    
    // Sprawdź czy element zadania należy do tego zadania
    $stmt = $pdo->prepare("SELECT id FROM task_items WHERE id = ? AND task_id = ?");
    $stmt->execute([$task_item_id, $task_id]);
    
    if ($stmt->fetch()) {
        try {
            $pdo->beginTransaction();

            // Zachowaj dotychczasowy tekst, jeśli istnieje
            $stmt = $pdo->prepare("SELECT verified_text, generated_text FROM generated_content WHERE task_item_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$task_item_id]);
            $existing = $stmt->fetch();
            $prev_text = $existing ? ($existing['verified_text'] ?: $existing['generated_text']) : null;
            if ($prev_text) {
                $stmt = $pdo->prepare("UPDATE task_items SET previous_text = ? WHERE id = ?");
                $stmt->execute([$prev_text, $task_item_id]);
            }

            // Usuń poprzednią treść
            $stmt = $pdo->prepare("DELETE FROM generated_content WHERE task_item_id = ?");
            $stmt->execute([$task_item_id]);
            
            // Resetuj status elementu zadania
            $stmt = $pdo->prepare("UPDATE task_items SET status = 'pending' WHERE id = ?");
            $stmt->execute([$task_item_id]);
            
            // Usuń stare wpisy z kolejki dla tego elementu
            $stmt = $pdo->prepare("DELETE FROM task_queue WHERE task_item_id = ?");
            $stmt->execute([$task_item_id]);
            
            // Dodaj do kolejki z wysokim priorytetem
            $stmt = $pdo->prepare("INSERT INTO task_queue (task_item_id, priority) VALUES (?, 100)");
            $stmt->execute([$task_item_id]);
            
            $pdo->commit();
            $success = 'Tekst został dodany do kolejki regeneracji z wysokim priorytetem.';
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = 'Błąd regeneracji: ' . $e->getMessage();
        }
    }
}

// Pobierz elementy zadania z wygenerowanymi treściami
$stmt = $pdo->prepare("
    SELECT ti.*, gc.generated_text, gc.verified_text, gc.status as content_status
    FROM task_items ti
    LEFT JOIN generated_content gc ON ti.id = gc.task_item_id
    WHERE ti.task_id = ?
    ORDER BY ti.id
");
$stmt->execute([$task_id]);
$task_items = $stmt->fetchAll();

$content_type_fields = json_decode($task['fields'], true);




?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Szczegóły zadania - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .content-preview {
            max-height: 200px;
            overflow-y: auto;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            font-size: 0.9em;
        }
        .url-column {
            max-width: 250px;
            word-break: break-all;
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
                    <h1 class="h2"><?= htmlspecialchars($task['name']) ?></h1>
                    <div>
                        <a href="tasks.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Powrót
                        </a>
                        <?php if (count($task_items) > 0): ?>
                            <a href="export_docx.php?task_id=<?= $task_id ?>" class="btn btn-success">
                                <i class="fas fa-download"></i> Eksport DOCX
                            </a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-primary" onclick="openCloneModal(<?= $task_id ?>, <?= $task['content_type_id'] ?>, <?= $task['model_id'] ? $task['model_id'] : 'null' ?>)">
                            <i class="fas fa-clone"></i> Klonuj
                        </button>
                    </div>
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
                
                <!-- Informacje o zadaniu -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Projekt:</strong><br>
                                <?= htmlspecialchars($task['project_name']) ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Typ treści:</strong><br>
                                <?= htmlspecialchars($task['content_type_name']) ?>
                            </div>
                            <div class="col-md-3">
    <strong>Model językowy:</strong><br>
    <!-- Zmieniono na pobieranie z api_models -->
    <?= $task['api_model_label'] ? htmlspecialchars($task['api_model_label']) : '<span class="text-muted">Brak wybranego</span>'; ?>
</div>
                            <div class="col-md-3">
                                <strong>Poziom naturalności:</strong><br>
                                <?= $task['strictness_level'] ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Status:</strong><br>
                                <?php
                                $status_classes = [
                                    'pending' => 'bg-warning',
                                    'processing' => 'bg-info',
                                    'completed' => 'bg-success',
                                    'failed' => 'bg-danger',
                                    'partial_failure' => 'bg-warning'
                                ];
                                $status_labels = [
                                    'pending' => 'Oczekuje',
                                    'processing' => 'Przetwarzanie',
                                    'completed' => 'Ukończone',
                                    'failed' => 'Błąd',
                                    'partial_failure' => 'Częściowo ukończone'
                                ];
                                ?>
                                <span class="badge <?= $status_classes[$task['status']] ?>">
                                    <?= $status_labels[$task['status']] ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Lista elementów zadania -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Elementy zadania (<?= count($task_items) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($task_items)): ?>
                            <p class="text-muted">Brak elementów w zadaniu.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th class="url-column">URL</th>
                                            <th>Status</th>
                                            <th>Wygenerowana treść</th>
                                            <th>Akcje</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($task_items as $item): ?>
                                            <tr>
                                                <td class="url-column">
                                                    <a href="<?= htmlspecialchars($item['url']) ?>" target="_blank" class="text-break">
                                                        <?= htmlspecialchars($item['url']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <?php
                                                    $item_status_classes = [
                                                        'pending' => 'bg-warning',
                                                        'processing' => 'bg-info',
                                                        'completed' => 'bg-success',
                                                        'failed' => 'bg-danger'
                                                    ];
                                                    $item_status_labels = [
                                                        'pending' => 'Oczekuje',
                                                        'processing' => 'Przetwarzanie',
                                                        'completed' => 'Ukończone',
                                                        'failed' => 'Błąd'
                                                    ];
                                                    ?>
                                                    <span class="badge <?= $item_status_classes[$item['status']] ?>">
                                                        <?= $item_status_labels[$item['status']] ?>
                                                    </span>
                                                </td>
                                                <td style="width: 70%;">
                                                    <?php if ($item['verified_text'] || $item['generated_text']): ?>
                                                        <?php
                                                            // Wybierz tekst do wyświetlenia i policzenia znaków
                                                            $displayed_text = $item['verified_text'] ?: $item['generated_text'];
                                                            $char_count = mb_strlen($displayed_text);
                                                        ?>
                                                        <div class="content-preview">
                                                            <?= $displayed_text ?>
                                                        </div>
                                                        <small class="text-muted">
                                                            Status: <?= $item['content_status'] === 'verified' ? 'Zweryfikowane' : 'Wygenerowane' ?> | (<?= $char_count ?> znaków ze spacjami)
                                                            <?php if ($item['verified_text'] && $item['generated_text'] && $item['verified_text'] !== $item['generated_text']): ?>
                                                                <br><span class="text-success"><i class="fas fa-check"></i> Tekst został zweryfikowany i poprawiony</span>
                                                            <?php elseif ($item['verified_text'] && $item['generated_text'] && $item['verified_text'] === $item['generated_text']): ?>
                                                                <br><span class="text-info"><i class="fas fa-info"></i> Tekst został zweryfikowany bez zmian</span>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Brak treści</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <?php if ($item['verified_text'] || $item['generated_text']): ?>
                                                            <button class="btn btn-sm btn-outline-primary" 
                                                                    onclick="viewContent(<?= $item['id'] ?>, '<?= htmlspecialchars($item['url']) ?>')">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <form method="POST" style="display: inline;">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="regenerate">
                                                            <input type="hidden" name="task_item_id" value="<?= $item['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-warning" 
                                                                    onclick="return confirm('Czy na pewno chcesz regenerować ten tekst? Poprzedni tekst zostanie zastąpiony.')">
                                                                <i class="fas fa-redo"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modal podglądu treści -->
    <div class="modal fade" id="contentModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contentModalTitle">Podgląd treści</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="contentModalBody">
                        <!-- Treść będzie wczytana przez AJAX -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
                </div>
            </div>
        </div>
</div>

    <!-- Modal klonowania zadania -->
    <div class="modal fade" id="cloneModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="task_clone.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="task_id" id="clone_task_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Klonuj zadanie</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="clone_content_type_id" class="form-label">Typ treści *</label>
                            <select class="form-select" name="content_type_id" id="clone_content_type_id" required>
                                <option value="">Wybierz typ treści</option>
                                <?php foreach ($content_types as $type): ?>
                                    <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="clone_model_id" class="form-label">Model AI *</label>
                            <select class="form-select" name="model_id" id="clone_model_id" required>
                                <?php foreach ($api_models as $m): ?>
                                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                        <button type="submit" class="btn btn-primary">Klonuj</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        async function viewContent(taskItemId, url) {
            document.getElementById('contentModalTitle').textContent = 'Podgląd treści - ' + url;
            document.getElementById('contentModalBody').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Ładowanie...</div>';
            
            const modal = new bootstrap.Modal(document.getElementById('contentModal'));
            modal.show();
            
            try {
                const response = await fetch('ajax_get_content.php?task_item_id=' + taskItemId);
                const data = await response.json();
                
                if (data.error) {
                    document.getElementById('contentModalBody').innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
                } else {
                    let html = '';
                    
                    if (data.verified_text) {
                        html += '<h6><i class="fas fa-check-circle text-success"></i> Zweryfikowana treść:</h6>';
                        html += '<div class="border p-3 mb-3 bg-light">' + data.verified_text + '</div>';
                        
                        if (data.generated_text && data.verified_text !== data.generated_text) {
                            html += '<h6><i class="fas fa-file-alt text-info"></i> Oryginalna wygenerowana treść:</h6>';
                            html += '<div class="border p-3 bg-white">' + data.generated_text + '</div>';
                        }
                    } else if (data.generated_text) {
                        html += '<h6><i class="fas fa-file-alt text-info"></i> Wygenerowana treść:</h6>';
                        html += '<div class="border p-3">' + data.generated_text + '</div>';
                    }
                    
                    document.getElementById('contentModalBody').innerHTML = html;
                }
            } catch (error) {
                document.getElementById('contentModalBody').innerHTML = '<div class="alert alert-danger">Błąd ładowania treści</div>';
            }
        }

        function openCloneModal(taskId, contentTypeId, modelId) {
            document.getElementById('clone_task_id').value = taskId;
            const typeSelect = document.getElementById('clone_content_type_id');
            const modelSelect = document.getElementById('clone_model_id');
            if (typeSelect) {
                typeSelect.value = contentTypeId || '';
            }
            if (modelSelect) {
                modelSelect.value = modelId || '';
            }
            const modal = new bootstrap.Modal(document.getElementById('cloneModal'));
            modal.show();
        }
    </script>
</body>
</html>