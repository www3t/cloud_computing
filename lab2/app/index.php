<?php
$host = 'mysql';
$db   = 'tododb';
$user = 'todouser';
$pass = 'todopass';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Add task
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['title'])) {
        $stmt = $pdo->prepare("INSERT INTO tasks (title) VALUES (?)");
        $stmt->execute([htmlspecialchars($_POST['title'])]);
        header('Location: /');
        exit;
    }

    // Delete task
    if (isset($_GET['delete'])) {
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([(int)$_GET['delete']]);
        header('Location: /');
        exit;
    }

    // Get all tasks
    $tasks = $pdo->query("SELECT * FROM tasks ORDER BY created_at DESC")->fetchAll();
    $connected = true;
} catch (PDOException $e) {
    $connected = false;
    $error = $e->getMessage();
    $tasks = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>To-Do List — AWS Lab 2</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { background: white; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); padding: 40px; width: 100%; max-width: 560px; }
        h1 { color: #232f3e; font-size: 28px; margin-bottom: 6px; }
        .subtitle { color: #888; font-size: 14px; margin-bottom: 28px; }
        .badge { display: inline-block; background: <?= $connected ? '#e6f4ea' : '#fce8e6' ?>; color: <?= $connected ? '#2d7a4f' : '#c5221f' ?>; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-bottom: 24px; }
        form { display: flex; gap: 10px; margin-bottom: 28px; }
        input[type=text] { flex: 1; border: 2px solid #e0e0e0; border-radius: 8px; padding: 12px 16px; font-size: 15px; outline: none; transition: border-color .2s; }
        input[type=text]:focus { border-color: #ff9900; }
        button[type=submit] { background: #ff9900; color: white; border: none; border-radius: 8px; padding: 12px 20px; font-size: 15px; font-weight: 600; cursor: pointer; transition: background .2s; }
        button[type=submit]:hover { background: #e88a00; }
        .task-list { list-style: none; }
        .task-item { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-radius: 8px; background: #f8f9fa; margin-bottom: 10px; border-left: 4px solid #ff9900; }
        .task-title { color: #333; font-size: 15px; }
        .task-meta { color: #aaa; font-size: 12px; margin-top: 2px; }
        .delete-btn { color: #c5221f; text-decoration: none; font-size: 18px; font-weight: bold; padding: 4px 8px; border-radius: 4px; transition: background .2s; }
        .delete-btn:hover { background: #fce8e6; }
        .empty { text-align: center; color: #aaa; padding: 30px; font-size: 15px; }
        .error { background: #fce8e6; color: #c5221f; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .footer { text-align: center; margin-top: 28px; color: #bbb; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <h1>📝 To-Do List</h1>
    <p class="subtitle">AWS Lab 2 — PHP + Docker on EC2</p>

    <div class="badge"><?= $connected ? '✅ MySQL Connected' : '❌ MySQL Disconnected' ?></div>

    <?php if (!$connected): ?>
        <div class="error">DB Error: <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="text" name="title" placeholder="Add a new task..." required>
        <button type="submit">Add</button>
    </form>

    <?php if (empty($tasks)): ?>
        <div class="empty">No tasks yet. Add your first one! 🚀</div>
    <?php else: ?>
        <ul class="task-list">
            <?php foreach ($tasks as $task): ?>
            <li class="task-item">
                <div>
                    <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                    <div class="task-meta"><?= date('d.m.Y H:i', strtotime($task['created_at'])) ?></div>
                </div>
                <a href="?delete=<?= $task['id'] ?>" class="delete-btn" title="Delete">×</a>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="footer">Running on Amazon EC2 · Powered by Docker</div>
</div>
</body>
</html>
