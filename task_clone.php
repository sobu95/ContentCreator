<?php
require_once 'auth_check.php';

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: tasks.php');
    exit;
}

$task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
$model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : null;

// Verify ownership and get task info
$stmt = $pdo->prepare("
    SELECT t.project_id, t.content_type_id, t.strictness_level, t.name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.id = ? AND p.user_id = ?
");
$stmt->execute([$task_id, $_SESSION['user_id']]);
$task = $stmt->fetch();

if (!$task) {
    header('Location: tasks.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $model_slug = null;
    if ($model_id) {
        $stmt = $pdo->prepare("SELECT model_slug FROM api_models WHERE id = ?");
        $stmt->execute([$model_id]);
        $model_slug = $stmt->fetchColumn();
    }

    $slug_suffix = $model_slug ? " ($model_slug)" : ' (klon)';
    $new_name = $task['name'] . $slug_suffix;

    $stmt = $pdo->prepare("INSERT INTO tasks (project_id, content_type_id, model_id, name, strictness_level) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$task['project_id'], $task['content_type_id'], $model_id, $new_name, $task['strictness_level']]);
    $new_task_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT url, input_data, page_content FROM task_items WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $items = $stmt->fetchAll();

    foreach ($items as $item) {
        $insertItem = $pdo->prepare("INSERT INTO task_items (task_id, url, input_data, page_content, status) VALUES (?, ?, ?, ?, 'pending')");
        $insertItem->execute([$new_task_id, $item['url'], $item['input_data'], $item['page_content']]);
        $new_item_id = $pdo->lastInsertId();

        $insertQueue = $pdo->prepare("INSERT INTO task_queue (task_item_id) VALUES (?)");
        $insertQueue->execute([$new_item_id]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: tasks.php');
    exit;
}

header('Location: task_details.php?id=' . $new_task_id . '&cloned=1');
exit;
?>
