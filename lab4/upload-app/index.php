<?php
// index.php — File upload form + list of uploaded files

require 'vendor/autoload.php';

use Aws\S3\S3Client;

define('BUCKET_NAME', 'cc-lab4-pub-k21');
define('DB_FILE', __DIR__ . '/uploads.db');
define('REGION', 'eu-central-1');

// Initialize SQLite database
function getDB(): PDO {
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_name TEXT NOT NULL,
        unique_name TEXT NOT NULL,
        url TEXT NOT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    return $db;
}

// Initialize S3 client
function getS3(): S3Client {
    return new S3Client([
        'region' => REGION,
        'version' => 'latest',
    ]);
}

$message = '';
$error = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fileToUpload'])) {
    $file = $_FILES['fileToUpload'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $originalName = basename($file['name']);
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);

        // Generate unique filename with timestamp + UUID
        $uniqueName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $s3Key = 'avatars/' . $uniqueName;

        try {
            $s3 = getS3();
            $result = $s3->putObject([
                'Bucket'     => BUCKET_NAME,
                'Key'        => $s3Key,
                'SourceFile' => $file['tmp_name'],
                'ACL'        => 'public-read',
                'ContentType'=> $file['type'],
            ]);

            $url = $result['ObjectURL'];

            // Save to database
            $db = getDB();
            $stmt = $db->prepare("INSERT INTO uploads (original_name, unique_name, url) VALUES (?, ?, ?)");
            $stmt->execute([$originalName, $uniqueName, $url]);

            $message = "File uploaded successfully!";
        } catch (Exception $e) {
            $error = "Upload failed: " . $e->getMessage();
        }
    } else {
        $error = "File upload error (code: " . $file['error'] . ")";
    }
}

// Get all uploaded files
$uploads = [];
try {
    $db = getDB();
    $uploads = $db->query("SELECT * FROM uploads ORDER BY uploaded_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "DB error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S3 File Uploader — Lab 4</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; min-height: 100vh; }
        header { background: #232f3e; color: white; padding: 16px 32px; }
        header h1 { font-size: 20px; }
        header p { font-size: 13px; opacity: 0.6; margin-top: 2px; }
        .container { max-width: 900px; margin: 32px auto; padding: 0 20px; }
        .card { background: white; border-radius: 12px; padding: 28px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 24px; }
        h2 { font-size: 18px; color: #232f3e; margin-bottom: 16px; }
        .upload-area { border: 2px dashed #ddd; border-radius: 8px; padding: 32px; text-align: center; margin-bottom: 16px; transition: border-color .2s; }
        .upload-area:hover { border-color: #ff9900; }
        input[type=file] { display: block; margin: 0 auto 16px; }
        .btn { background: #ff9900; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
        .btn:hover { background: #e88a00; }
        .alert-success { background: #e6f4ea; color: #2d7a4f; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .alert-error { background: #fce8e6; color: #c5221f; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 10px 12px; background: #f8f9fa; color: #555; font-size: 13px; border-bottom: 2px solid #eee; }
        td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 14px; vertical-align: middle; }
        td img { width: 48px; height: 48px; object-fit: cover; border-radius: 6px; }
        .filename { font-weight: 500; color: #333; }
        .unique { color: #888; font-size: 12px; }
        a.url-link { color: #0073bb; text-decoration: none; font-size: 12px; }
        a.url-link:hover { text-decoration: underline; }
        .empty { text-align: center; color: #aaa; padding: 32px; }
        .date { color: #999; font-size: 12px; }
    </style>
</head>
<body>

<header>
    <h1>☁️ S3 File Uploader</h1>
    <p>Cloud Computing Lab 4 · Bucket: <?= BUCKET_NAME ?></p>
</header>

<div class="container">

    <?php if ($message): ?>
        <div class="alert-success">✅ <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-error">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>📤 Upload Avatar</h2>
        <form method="POST" enctype="multipart/form-data">
            <div class="upload-area">
                <p style="color:#888;margin-bottom:12px;">Select an image to upload to S3</p>
                <input type="file" name="fileToUpload" id="fileToUpload" accept="image/*" required>
            </div>
            <button type="submit" class="btn">Upload to S3</button>
        </form>
    </div>

    <div class="card">
        <h2>📁 Uploaded Files (<?= count($uploads) ?>)</h2>
        <?php if (empty($uploads)): ?>
            <div class="empty">No files uploaded yet. Upload your first avatar!</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Preview</th>
                    <th>Original Name</th>
                    <th>Unique Name</th>
                    <th>S3 URL</th>
                    <th>Uploaded At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($uploads as $upload): ?>
                <tr>
                    <td><img src="<?= htmlspecialchars($upload['url']) ?>" alt="avatar" onerror="this.style.display='none'"></td>
                    <td class="filename"><?= htmlspecialchars($upload['original_name']) ?></td>
                    <td class="unique"><?= htmlspecialchars($upload['unique_name']) ?></td>
                    <td><a class="url-link" href="<?= htmlspecialchars($upload['url']) ?>" target="_blank">Open ↗</a></td>
                    <td class="date"><?= htmlspecialchars($upload['uploaded_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
