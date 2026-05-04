<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Poppy Storage - Admin</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f4f4f4; }
        form { margin: 20px 0; }
        input, button { padding: 8px; margin: 4px 0; }
        button { background: #007bff; color: white; border: none; cursor: pointer; }
        button:hover { background: #0056b3; }
        .delete { background: #dc3545; }
        .delete:hover { background: #c82333; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>Poppy Storage - Buckets</h1>

    <form method="POST" action="/admin/create">
        <h3>Create New Bucket</h3>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="text" name="name" placeholder="Bucket name (alphanumeric)" required pattern="[a-zA-Z0-9_-]+">
        <button type="submit">Create Bucket</button>
    </form>

    <table>
        <tr>
            <th>Name</th>
            <th>API Key (hashed)</th>
            <th>Total Size</th>
            <th>Actions</th>
        </tr>
        <?php foreach (load_buckets() as $b): ?>
        <tr>
            <td><?= htmlspecialchars($b['name']) ?></td>
            <td><code style="font-size:11px"><?= htmlspecialchars(substr($b['key'], 0, 40)) ?>...</code></td>
            <td><?= format_size(calculate_bucket_size($b['name'])) ?></td>
            <td>
                <form method="POST" action="/admin/delete" style="display:inline" onsubmit="return confirm('Delete this bucket and all its files?')">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="name" value="<?= htmlspecialchars($b['name']) ?>">
                    <button type="submit" class="delete" style="border:none;cursor:pointer">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <p><small>Note: API keys are hashed (BCRYPT). The plaintext key is only shown once on creation.</small></p>
</body>
</html>
