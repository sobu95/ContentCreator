<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

// Pobierz klucze API
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('gemini_api_key','anthropic_api_key')");
$stmt->execute();
$api_keys = [];
foreach ($stmt->fetchAll() as $row) {
    $api_keys[$row['setting_key']] = $row['setting_value'];
}
$gemini_api_key = $api_keys['gemini_api_key'] ?? null;
$anthropic_api_key = $api_keys['anthropic_api_key'] ?? null;

$test_result = '';
$error = '';

// Pobierz dostępne modele API
$stmt = $pdo->query("SELECT * FROM api_models ORDER BY label");
$language_models = $stmt->fetchAll();

if ($_POST && isset($_POST['test_api'])) {
    $provider = $_POST['provider'] ?? 'gemini';
    $api_key = $provider === 'anthropic' ? $anthropic_api_key : $gemini_api_key;

    if (!$api_key) {
        $error = 'Klucz API ' . ucfirst($provider) . ' nie jest skonfigurowany.';
    } else {
        try {
            $test_prompt = "Napisz krótki test w języku polskim - jedną linijkę tekstu.";

            $model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : null;
            $model = null;
            if ($model_id) {
                $stmt = $pdo->prepare("SELECT * FROM api_models WHERE id = ?");
                $stmt->execute([$model_id]);
                $model = $stmt->fetch();
            }

            if ($provider === 'anthropic') {
                $slug = $model['model_slug'] ?? 'claude-3-haiku-20240307';
                $url = 'https://api.anthropic.com/v1/messages';
                $data = [
                    'model' => $slug,
                    'messages' => [ ['role' => 'user', 'content' => $test_prompt] ],
                    'max_tokens' => 100,
                    'temperature' => 0.7
                ];
                $headers = [
                    'Content-Type: application/json',
                    'x-api-key: ' . $api_key,
                    'anthropic-version: 2023-06-01'
                ];
            } else {
                $slug = $model['model_slug'] ?? 'gemini-1.5-flash';
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$slug}:generateContent?key=" . $api_key;
                $data = [
                    'contents' => [[ 'parts' => [['text' => $test_prompt]] ]],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'topK' => 40,
                        'topP' => 0.95,
                        'maxOutputTokens' => 100,
                    ]
                ];
                $headers = [ 'Content-Type: application/json' ];
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($curl_error) {
                throw new Exception("cURL Error: $curl_error");
            }
            
            if ($http_code !== 200) {
                throw new Exception("HTTP Error $http_code: $response");
            }
            
            $result = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON response: " . json_last_error_msg());
            }
            
            if ($provider === 'anthropic') {
                if (isset($result['content'][0]['text'])) {
                    $test_result = $result['content'][0]['text'];
                } elseif (isset($result['error'])) {
                    throw new Exception("API Error: " . $result['error']['message']);
                } else {
                    throw new Exception("Unexpected response format: " . json_encode($result));
                }
            } else {
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    $test_result = $result['candidates'][0]['content']['parts'][0]['text'];
                } elseif (isset($result['error'])) {
                    throw new Exception("API Error: " . $result['error']['message']);
                } else {
                    throw new Exception("Unexpected response format: " . json_encode($result));
                }
            }
            
        } catch (Exception $e) {
            $error = 'Błąd testu API: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test API - Generator treści SEO</title>
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
                    <h1 class="h2">Test API</h1>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <h5>Błąd:</h5>
                        <pre><?= htmlspecialchars($error) ?></pre>
                    </div>
                <?php endif; ?>
                
                <?php if ($test_result): ?>
                    <div class="alert alert-success">
                        <h5>Test zakończony pomyślnie!</h5>
                        <p><strong>Odpowiedź API:</strong></p>
                        <div class="border p-3 bg-light">
                            <?= htmlspecialchars($test_result) ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Test połączenia z API</h5>
                    </div>
                    <div class="card-body">
                        <p>Wybierz dostawcę i model, aby przetestować połączenie:</p>

                            <form method="POST">
                                <?= csrf_field() ?>
                                <div class="mb-3">
                                    <label class="form-label">Provider</label>
                                    <select name="provider" class="form-select">
                                        <option value="gemini">Gemini<?= $gemini_api_key ? '' : ' (brak klucza)' ?></option>
                                        <option value="anthropic">Anthropic<?= $anthropic_api_key ? '' : ' (brak klucza)' ?></option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Model API</label>
                                    <select name="model_id" class="form-select">
                                        <?php foreach ($language_models as $lm): ?>
                                            <option value="<?= $lm['id'] ?>"><?= htmlspecialchars($lm['label']) ?> (<?= htmlspecialchars($lm['provider']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" name="test_api" class="btn btn-primary">
                                    <i class="fas fa-play"></i> Testuj API
                                </button>
                            </form>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Test procesora kolejki</h5>
                    </div>
                    <div class="card-body">
                        <p>Możesz przetestować procesor kolejki bezpośrednio w przeglądarce:</p>
                        <a href="process_queue.php" target="_blank" class="btn btn-info">
                            <i class="fas fa-external-link-alt"></i> Otwórz test procesora
                        </a>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Informacje debugowania</h5>
                    </div>
                    <div class="card-body">
                        <h6>Konfiguracja PHP:</h6>
                        <ul>
                            <li>cURL: <?= extension_loaded('curl') ? '<span class="text-success">Włączone</span>' : '<span class="text-danger">Wyłączone</span>' ?></li>
                            <li>JSON: <?= extension_loaded('json') ? '<span class="text-success">Włączone</span>' : '<span class="text-danger">Wyłączone</span>' ?></li>
                            <li>OpenSSL: <?= extension_loaded('openssl') ? '<span class="text-success">Włączone</span>' : '<span class="text-danger">Wyłączone</span>' ?></li>
                            <li>Max execution time: <?= ini_get('max_execution_time') ?>s</li>
                            <li>Memory limit: <?= ini_get('memory_limit') ?></li>
                        </ul>
                        
                        <h6>Status kolejki:</h6>
                        <?php
                        $stmt = $pdo->query("
                            SELECT 
                                status,
                                COUNT(*) as count
                            FROM task_queue 
                            GROUP BY status
                        ");
                        $queue_stats = $stmt->fetchAll();
                        ?>
                        <ul>
                            <?php foreach ($queue_stats as $stat): ?>
                                <li><?= ucfirst($stat['status']) ?>: <?= $stat['count'] ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>