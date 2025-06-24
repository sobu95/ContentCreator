<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

$success = '';
$error = '';

if ($_POST) {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'save_model':
            $model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : null;
            $provider = $_POST['provider'] ?? 'gemini';
            $model_slug = trim($_POST['model_slug']);
            $label = trim($_POST['label']);
            try {
                if ($model_id) {
                    $stmt = $pdo->prepare("UPDATE api_models SET provider=?, model_slug=?, label=? WHERE id=?");
                    $stmt->execute([$provider, $model_slug, $label, $model_id]);
                    $success = 'Model został zaktualizowany.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO api_models (provider, model_slug, label) VALUES (?, ?, ?)");
                    $stmt->execute([$provider, $model_slug, $label]);
                    $success = 'Model został dodany.';
                }
            } catch(Exception $e) {
                $error = 'Błąd zapisu modelu: ' . $e->getMessage();
            }
            break;
        case 'delete_model':
            $model_id = intval($_POST['model_id']);
            try {
                $stmt = $pdo->prepare("DELETE FROM api_models WHERE id = ?");
                $stmt->execute([$model_id]);
                $success = 'Model został usunięty.';
            } catch(Exception $e) {
                $error = 'Błąd usuwania modelu: ' . $e->getMessage();
            }
            break;
    }
}

$stmt = $pdo->query("SELECT * FROM api_models ORDER BY label");
$models = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modele API - Generator treści SEO</title>
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
                <h1 class="h2">Modele API</h1>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modelModal">
                    <i class="fas fa-plus"></i> Dodaj model
                </button>
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

            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Provider</th>
                            <th>Model slug</th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($models as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['label']) ?></td>
                            <td><?= htmlspecialchars($m['provider']) ?></td>
                            <td><?= htmlspecialchars($m['model_slug']) ?></td>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-secondary" onclick='editModel(<?= json_encode($m) ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Usunąć model?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_model">
                                        <input type="hidden" name="model_id" value="<?= $m['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
                <input type="hidden" name="model_id" id="model_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="modelModalTitle">Nowy model</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Provider</label>
                        <select class="form-select" name="provider" id="provider">
                            <option value="gemini">gemini</option>
                            <option value="anthropic">anthropic</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Model slug *</label>
                        <input type="text" class="form-control" name="model_slug" id="model_slug" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Etykieta *</label>
                        <input type="text" class="form-control" name="label" id="label" required>
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
function editModel(model){
    document.getElementById('modelModalTitle').textContent='Edytuj model';
    document.getElementById('model_id').value = model.id;
    document.getElementById('provider').value = model.provider;
    document.getElementById('model_slug').value = model.model_slug;
    document.getElementById('label').value = model.label;
    var modal = new bootstrap.Modal(document.getElementById('modelModal'));
    modal.show();
}

document.getElementById('modelModal').addEventListener('hidden.bs.modal',function(){
    document.getElementById('modelModalTitle').textContent='Nowy model';
    document.getElementById('model_id').value='';
    document.getElementById('provider').value='gemini';
    document.getElementById('model_slug').value='';
    document.getElementById('label').value='';
});
</script>
</body>
</html>
