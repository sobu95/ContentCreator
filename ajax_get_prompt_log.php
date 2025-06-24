<?php
require_once 'auth_check.php';
requireAdmin();

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$pdo = getDbConnection();

try {
    $stmt = $pdo->prepare("
        SELECT pl.*, ti.url, t.name as task_name, p.name as project_name, u.email as user_email
        FROM prompt_logs pl
        JOIN task_items ti ON pl.task_item_id = ti.id
        JOIN tasks t ON ti.task_id = t.id
        JOIN projects p ON t.project_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE pl.id = ?
    ");
    $stmt->execute([$_GET['id']]);
    $log = $stmt->fetch();
    
    if (!$log) {
        http_response_code(404);
        echo json_encode(['error' => 'Prompt log not found']);
        exit;
    }
    
    echo json_encode([
        'prompt_type' => $log['prompt_type'],
        'prompt_content' => $log['prompt_content'],
        'api_response' => $log['api_response'],
        'task_name' => $log['task_name'],
        'project_name' => $log['project_name'],
        'user_email' => $log['user_email'],
        'url' => $log['url'],
        'created_at' => $log['created_at']
    ]);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>