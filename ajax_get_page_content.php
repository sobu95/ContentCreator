<?php
require_once 'auth_check.php';
requireAdmin();

header('Content-Type: application/json');

if (!isset($_GET['task_item_id']) || !is_numeric($_GET['task_item_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid task item ID']);
    exit;
}

$task_item_id = intval($_GET['task_item_id']);
$pdo = getDbConnection();

try {
    $stmt = $pdo->prepare("SELECT page_content FROM task_items WHERE id = ?");
    $stmt->execute([$task_item_id]);
    $page_content = $stmt->fetchColumn();

    if ($page_content === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Content not found']);
        exit;
    }

    echo json_encode(['page_content' => $page_content]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
