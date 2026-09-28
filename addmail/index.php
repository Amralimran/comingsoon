<?php
$containerName = 'mailserver'; // Update if your container name differs
$message = '';
$error = '';

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($password)) {
            $safeEmail = escapeshellarg($email);
            $safePass = escapeshellarg($password);
            $output = shell_exec("docker exec -t {$containerName} setup email add {$safeEmail} {$safePass} 2>&1");
            $message = "Account created successfully for: " . htmlspecialchars($email);
        } else {
            $error = "Please provide a valid email and password.";
        }
    } 
    elseif ($action === 'update') {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($password)) {
            $safeEmail = escapeshellarg($email);
            $safePass = escapeshellarg($password);
            // Uses the update command we discussed earlier
            $output = shell_exec("docker exec -t {$containerName} setup email update {$safeEmail} {$safePass} 2>&1");
            $message = "Password updated successfully for: " . htmlspecialchars($email);
        } else {
            $error = "Please provide a valid email and new password.";
        }
    }
}

// Fetch existing accounts list using docker-mailserver setup cli
$rawList = shell_exec("docker exec -t {$containerName} setup email list 2>&1");
$accounts = [];
if ($rawList) {
    $lines = explode("\n", trim($rawList));
    foreach ($lines as $line) {
        // Clean up formatting/ansi color codes if returned by the CLI
        $cleanLine = preg_replace('/\x1b\[[0-9;]*m/', '', $line);
        if (filter_var(trim($cleanLine), FILTER_VALIDATE_EMAIL)) {
            $accounts[] = trim($cleanLine);
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Mailserver Account Manager</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; background: #f9f9f9; }
        .card { background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        input, button { padding: 8px; margin-top: 5px; box-sizing: border-box; }
        input[type="email"], input[type="password"] { width: 100%; }
        button { background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .msg { color: green; font-weight: bold; margin-bottom: 10px; }
        .err { color: red; font-weight: bold; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; }
    </style>
</head>
<body>

    <h2>Mailserver Account Manager</h2>

    <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error); ?></div><?php endif; ?>

    <!-- Add New Account Card -->
    <div class="card">
        <h3>Add New Email Account</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <label>Email Address:</label>
            <input type="email" name="email" required placeholder="user@theimrans.tech">
            
            <label style="margin-top: 10px; display: block;">Password:</label>
            <input type="password" name="password" required placeholder="Enter password">
            
            <button type="submit" style="margin-top: 10px;">Create Email</button>
        </form>
    </div>

    <!-- Existing Accounts List & Password Update Card -->
    <div class="card">
        <h3>Existing Accounts & Password Update</h3>
        <?php if (empty($accounts)): ?>
            <p>No accounts found or unable to communicate with Docker container.</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>Email Account</th>
                    <th>Change Password</th>
                </tr>
                <?php foreach ($accounts as $acc): ?>
                <tr>
                    <td><?= htmlspecialchars($acc); ?></td>
                    <td>
                        <form method="POST" style="display: flex; gap: 5px;">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($acc); ?>">
                            <input type="password" name="password" placeholder="New Password" required style="width: 150px;">
                            <button type="submit">Update</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

</body>
</html>