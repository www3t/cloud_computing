<?php
// index.php — Task Manager CRUD App
// Reads from RDS Read Replica, Writes to RDS Master, DynamoDB for activity log

require 'vendor/autoload.php';

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

// ─── CONFIG ───────────────────────────────────────────────────────────────────
define('DB_MASTER_HOST', getenv('DB_MASTER_HOST') ?: 'YOUR_RDS_MASTER_ENDPOINT');
define('DB_REPLICA_HOST', getenv('DB_REPLICA_HOST') ?: 'YOUR_RDS_REPLICA_ENDPOINT');
define('DB_NAME',   'project_db');
define('DB_USER',   'admin');
define('DB_PASS',   getenv('DB_PASS') ?: 'YourPassword123!');
define('AWS_REGION','eu-central-1');
define('DYNAMO_TABLE', 'ActivityLog');

// ─── DB CONNECTIONS ───────────────────────────────────────────────────────────
function getMaster(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO("mysql:host=" . DB_MASTER_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    return $pdo;
}

function getReplica(): PDO {
    static $pdo;
    if (!$pdo) {
        try {
            $pdo = new PDO("mysql:host=" . DB_REPLICA_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
                DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Exception $e) {
            // fallback to master if replica unavailable
            $pdo = getMaster();
        }
    }
    return $pdo;
}

function getDynamo(): DynamoDbClient {
    static $client;
    if (!$client) {
        $client = new DynamoDbClient([
            'region'  => AWS_REGION,
            'version' => 'latest',
        ]);
    }
    return $client;
}

// ─── INIT TABLES ─────────────────────────────────────────────────────────────
function initTables(): void {
    $db = getMaster();
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS todos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        category_id INT NOT NULL,
        status ENUM('pending','in_progress','done') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    )");
    // Seed categories if empty
    $count = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($count == 0) {
        $db->exec("INSERT INTO categories (name) VALUES ('Work'),('Personal'),('Shopping')");
        $db->exec("INSERT INTO todos (title, category_id, status) VALUES
            ('Set up AWS RDS', 1, 'done'),
            ('Configure Read Replica', 1, 'in_progress'),
            ('Write lab report', 1, 'pending'),
            ('Buy groceries', 3, 'pending'),
            ('Call dentist', 2, 'pending'),
            ('Deploy application', 1, 'in_progress')");
    }
}

// ─── DYNAMO LOGGING ──────────────────────────────────────────────────────────
function logActivity(string $action, string $details): void {
    try {
        $dynamo = getDynamo();
        $marshaler = new Marshaler();
        $dynamo->putItem([
            'TableName' => DYNAMO_TABLE,
            'Item' => $marshaler->marshalItem([
                'id'        => uniqid('log_', true),
                'action'    => $action,
                'details'   => $details,
                'timestamp' => date('Y-m-d H:i:s'),
                'user_ip'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]),
        ]);
    } catch (Exception $e) {
        // DynamoDB logging is optional, don't break app
    }
}

// ─── HANDLE ACTIONS ──────────────────────────────────────────────────────────
$message = '';
$error = '';

try {
    initTables();

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // CREATE todo
    if ($action === 'create_todo' && !empty($_POST['title']) && !empty($_POST['category_id'])) {
        $stmt = getMaster()->prepare("INSERT INTO todos (title, category_id, status) VALUES (?, ?, 'pending')");
        $stmt->execute([htmlspecialchars($_POST['title']), (int)$_POST['category_id']]);
        logActivity('CREATE_TODO', "Created todo: " . $_POST['title']);
        $message = "Task created successfully!";
    }

    // UPDATE status
    if ($action === 'update_status' && !empty($_POST['id']) && !empty($_POST['status'])) {
        $stmt = getMaster()->prepare("UPDATE todos SET status = ? WHERE id = ?");
        $stmt->execute([$_POST['status'], (int)$_POST['id']]);
        logActivity('UPDATE_TODO', "Updated todo #{$_POST['id']} status to {$_POST['status']}");
        $message = "Task updated!";
    }

    // DELETE todo
    if ($action === 'delete_todo' && !empty($_GET['id'])) {
        $stmt = getMaster()->prepare("DELETE FROM todos WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        logActivity('DELETE_TODO', "Deleted todo #{$_GET['id']}");
        $message = "Task deleted!";
    }

    // CREATE category
    if ($action === 'create_category' && !empty($_POST['name'])) {
        $stmt = getMaster()->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([htmlspecialchars($_POST['name'])]);
        logActivity('CREATE_CATEGORY', "Created category: " . $_POST['name']);
        $message = "Category created!";
    }

    // READ — from replica
    $filter = $_GET['filter'] ?? 'all';
    $categoryFilter = (int)($_GET['category'] ?? 0);

    $sql = "SELECT t.id, t.title, t.status, t.created_at, c.name as category_name
            FROM todos t JOIN categories c ON t.category_id = c.id";
    $params = [];
    $where = [];
    if ($filter !== 'all') { $where[] = "t.status = ?"; $params[] = $filter; }
    if ($categoryFilter > 0) { $where[] = "t.category_id = ?"; $params[] = $categoryFilter; }
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY t.created_at DESC";

    $stmt = getReplica()->prepare($sql);
    $stmt->execute($params);
    $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = getReplica()->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats = getReplica()->query("SELECT status, COUNT(*) as cnt FROM todos GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

    // DynamoDB logs
    $logs = [];
    try {
        $dynamo = getDynamo();
        $result = $dynamo->scan(['TableName' => DYNAMO_TABLE, 'Limit' => 10]);
        $marshaler = new Marshaler();
        foreach ($result['Items'] as $item) {
            $logs[] = $marshaler->unmarshalItem($item);
        }
        usort($logs, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        $logs = array_slice($logs, 0, 10);
    } catch (Exception $e) {
        $logs = [];
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    $todos = $categories = $logs = [];
    $stats = [];
    $filter = 'all';
    $categoryFilter = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Manager — AWS Lab 5</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #333; }
        header { background: #232f3e; color: white; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; }
        header h1 { font-size: 20px; }
        .badge { background: #ff9900; color: white; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; margin-left: 8px; }
        .container { max-width: 1200px; margin: 24px auto; padding: 0 20px; display: grid; grid-template-columns: 300px 1fr; gap: 24px; }
        .card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); margin-bottom: 20px; }
        h2 { font-size: 16px; color: #232f3e; margin-bottom: 16px; font-weight: 700; }
        input, select, textarea { width: 100%; border: 2px solid #e0e0e0; border-radius: 8px; padding: 10px 12px; font-size: 14px; margin-bottom: 10px; outline: none; transition: border-color .2s; }
        input:focus, select:focus { border-color: #ff9900; }
        .btn { background: #ff9900; color: white; border: none; border-radius: 8px; padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; width: 100%; transition: background .2s; }
        .btn:hover { background: #e88a00; }
        .btn-sm { width: auto; padding: 6px 12px; font-size: 12px; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; }
        .btn-danger { background: #fce8e6; color: #c5221f; }
        .btn-danger:hover { background: #f5c6c3; }
        .btn-success { background: #e6f4ea; color: #2d7a4f; }
        .btn-success:hover { background: #c6e8cc; }
        .btn-blue { background: #e8f0fe; color: #1a73e8; }
        .btn-blue:hover { background: #c5d8fc; }
        .alert { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #e6f4ea; color: #2d7a4f; }
        .alert-error { background: #fce8e6; color: #c5221f; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px; }
        .stat { background: white; border-radius: 10px; padding: 14px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .stat .num { font-size: 28px; font-weight: 700; color: #ff9900; }
        .stat .lbl { font-size: 12px; color: #888; margin-top: 2px; }
        .filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .filter-btn { padding: 6px 14px; border-radius: 20px; border: 2px solid #e0e0e0; background: white; font-size: 13px; cursor: pointer; text-decoration: none; color: #555; transition: all .2s; }
        .filter-btn.active, .filter-btn:hover { border-color: #ff9900; color: #ff9900; background: #fff8f0; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 10px 12px; background: #f8f9fa; color: #555; font-size: 13px; border-bottom: 2px solid #eee; }
        td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 14px; vertical-align: middle; }
        .status-badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-in_progress { background: #cfe2ff; color: #084298; }
        .status-done { background: #d1e7dd; color: #0a3622; }
        .cat-badge { background: #f0f2f5; color: #555; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
        .empty { text-align: center; color: #aaa; padding: 32px; }
        .db-info { font-size: 11px; color: #aaa; margin-top: 4px; }
        .log-item { padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        .log-action { font-weight: 600; color: #232f3e; }
        .log-time { color: #aaa; font-size: 11px; }
        .actions { display: flex; gap: 6px; align-items: center; }
    </style>
</head>
<body>

<header>
    <div>
        <h1>📋 Task Manager <span class="badge">AWS Lab 5</span></h1>
    </div>
    <div style="font-size:12px;opacity:.6;">
        Writes → RDS Master &nbsp;|&nbsp; Reads → RDS Replica &nbsp;|&nbsp; Logs → DynamoDB
    </div>
</header>

<div style="max-width:1200px;margin:0 auto;padding:16px 20px 0;">
    <?php if ($message): ?><div class="alert alert-success">✅ <?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>
</div>

<div class="container">

    <!-- LEFT SIDEBAR -->
    <div>
        <!-- Stats -->
        <div class="stats">
            <div class="stat">
                <div class="num"><?= array_sum(array_values($stats)) ?></div>
                <div class="lbl">Total</div>
            </div>
            <div class="stat">
                <div class="num"><?= $stats['pending'] ?? 0 ?></div>
                <div class="lbl">Pending</div>
            </div>
            <div class="stat">
                <div class="num"><?= $stats['done'] ?? 0 ?></div>
                <div class="lbl">Done</div>
            </div>
        </div>

        <!-- Add Task -->
        <div class="card">
            <h2>➕ Add Task</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_todo">
                <input type="text" name="title" placeholder="Task title..." required>
                <select name="category_id" required>
                    <option value="">Select category...</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn">Add Task</button>
            </form>
            <p class="db-info">✍️ Writes to: RDS Master</p>
        </div>

        <!-- Add Category -->
        <div class="card">
            <h2>🏷️ Add Category</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_category">
                <input type="text" name="name" placeholder="Category name..." required>
                <button type="submit" class="btn">Add Category</button>
            </form>
            <p class="db-info">✍️ Writes to: RDS Master</p>
        </div>

        <!-- DynamoDB Activity Log -->
        <div class="card">
            <h2>📊 Activity Log <span class="badge">DynamoDB</span></h2>
            <?php if (empty($logs)): ?>
                <div class="empty" style="padding:16px;">No activity yet</div>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <div class="log-item">
                    <span class="log-action"><?= htmlspecialchars($log['action'] ?? '') ?></span><br>
                    <span style="color:#555"><?= htmlspecialchars($log['details'] ?? '') ?></span><br>
                    <span class="log-time"><?= htmlspecialchars($log['timestamp'] ?? '') ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <p class="db-info" style="margin-top:8px;">📖 Reads from: DynamoDB</p>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div>
        <div class="card">
            <h2>📋 Tasks <span style="color:#888;font-weight:400;font-size:13px;">(<?= count($todos) ?> results — read from RDS Replica)</span></h2>

            <!-- Filters -->
            <div class="filters">
                <a href="?" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">All</a>
                <a href="?filter=pending" class="filter-btn <?= $filter === 'pending' ? 'active' : '' ?>">Pending</a>
                <a href="?filter=in_progress" class="filter-btn <?= $filter === 'in_progress' ? 'active' : '' ?>">In Progress</a>
                <a href="?filter=done" class="filter-btn <?= $filter === 'done' ? 'active' : '' ?>">Done</a>
                <?php foreach ($categories as $cat): ?>
                <a href="?category=<?= $cat['id'] ?>" class="filter-btn <?= $categoryFilter === (int)$cat['id'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($cat['name']) ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($todos)): ?>
                <div class="empty">No tasks found. Add your first task! 🚀</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($todos as $todo): ?>
                    <tr>
                        <td style="color:#aaa"><?= $todo['id'] ?></td>
                        <td><strong><?= htmlspecialchars($todo['title']) ?></strong></td>
                        <td><span class="cat-badge"><?= htmlspecialchars($todo['category_name']) ?></span></td>
                        <td><span class="status-badge status-<?= $todo['status'] ?>"><?= str_replace('_', ' ', $todo['status']) ?></span></td>
                        <td style="color:#aaa;font-size:12px;"><?= date('d.m.Y', strtotime($todo['created_at'])) ?></td>
                        <td>
                            <div class="actions">
                                <!-- Update status form -->
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= $todo['id'] ?>">
                                    <select name="status" onchange="this.form.submit()" style="width:auto;margin:0;padding:4px 8px;font-size:12px;">
                                        <option value="pending" <?= $todo['status']==='pending'?'selected':'' ?>>Pending</option>
                                        <option value="in_progress" <?= $todo['status']==='in_progress'?'selected':'' ?>>In Progress</option>
                                        <option value="done" <?= $todo['status']==='done'?'selected':'' ?>>Done</option>
                                    </select>
                                </form>
                                <a href="?action=delete_todo&id=<?= $todo['id'] ?>"
                                   class="btn-sm btn-danger"
                                   onclick="return confirm('Delete this task?')">Delete</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <p class="db-info" style="margin-top:12px;">📖 Reads from: RDS Read Replica</p>
        </div>
    </div>
</div>

</body>
</html>
