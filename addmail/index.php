<?php
session_start();

// --- Logout ---
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// --- Admin password ---
$ADMIN_PASSWORD = getenv('ADMIN_PASSWORD');
if (empty($ADMIN_PASSWORD)) {
    die('ADMIN_PASSWORD environment variable is not set.');
}

// --- Auth gate ---
if (empty($_SESSION['authenticated'])) {
    if (($_POST['admin_password'] ?? '') === $ADMIN_PASSWORD) {
        $_SESSION['authenticated'] = true;
    } else {
        ?>
        <!DOCTYPE html><html><head>
        <title>Login</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <style>
            body{font-family:"Segoe UI", Arial, Helvetica, sans-serif;max-width:400px;margin:80px auto;padding:20px;background:#f9f9f9;}
            .card{background:#fff;padding:24px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.08);}
            input{width:100%;padding:10px;margin-top:8px;box-sizing:border-box;border:1px solid #ccc;border-radius:4px;}
            button{margin-top:5px;padding:5px 10px;background:#007bff;color:#fff;border:none;border-radius:4px;cursor:pointer;}
            button:hover{background:#0056b3;}
            .err{color:#dc3545;margin-top:10px;font-size:0.9em;}
        </style></head><body>
        <div class="card">
            <h2>Mail Admin Login</h2>
            <form method="POST">
                <input type="password" name="admin_password" placeholder="Admin password" required autofocus>
                <button type="submit">Login</button>
                <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                    <div class="err">Incorrect password.</div>
                <?php endif; ?>
            </form>
        </div>
        </body></html>
        <?php
        exit;
    }
}
// --- Configuration ---
$MAIL_DOMAIN = getenv('MAIL_DOMAIN') ?: 'theimrans.tech';

// --- Discover mailserver container ---
$DOCKER = '/usr/bin/docker';

$output = shell_exec("$DOCKER ps --filter 'name=mailserver' --format '{{.Names}}' 2>/dev/null | head -1");
$containerName = $output !== null ? trim($output) : '';
if (empty($containerName)) {
    die("⚠️ Mailserver container not found. Is it running?");
}

$message = '';
$error = '';

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add' || $action === 'update') {
    $email = trim($_POST['email'] ?? '');

    // Normalize: force every input to use the mail domain
    if (!empty($email)) {
        if (str_contains($email, '@')) {
            // Split into local part (before the first '@') and discard the rest
            $localPart = trim(explode('@', $email, 2)[0]);
            
            if ($localPart !== '') {
                $email = $localPart . '@' . $MAIL_DOMAIN;
            } else {
                // Input was just "@something" (empty local part) -> cleared
                $email = '';
            }
        } else {
            // Input had no '@' -> append mail domain
            $email = $email . '@' . $MAIL_DOMAIN;
        }
    }

    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($password)) {
            $safeEmail = escapeshellarg($email);
            $safePass  = escapeshellarg($password);
            $cmd = $action === 'add' ? 'add' : 'update';
            $output = shell_exec("$DOCKER exec -i {$containerName} setup email {$cmd} {$safeEmail} {$safePass} 2>&1");

            $lower = strtolower($output ?? '');
            if (str_contains($lower, 'error') || str_contains($lower, 'fail') || str_contains($lower, 'already exists')) {
                $error = "Command output: " . htmlspecialchars(trim($output));
            } else {
                $message = $action === 'add'
                    ? "Account created: " . htmlspecialchars($email)
                    : "Password updated: " . htmlspecialchars($email);
            }
        } else {
            $error = "Please provide a valid email and password.";
        }
    } elseif ($action === 'delete') {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $safeEmail = escapeshellarg($email);
            $output = shell_exec("$DOCKER exec -i {$containerName} setup email del {$safeEmail} 2>&1");
            $message = "Account deleted: " . htmlspecialchars($email);
        }
    }
}

// --- List accounts ---
$rawList = shell_exec("$DOCKER exec -i {$containerName} setup email list 2>&1");
$accounts = [];
if ($rawList) {
    // Strip ANSI color codes
    $clean = preg_replace('/\x1b\[[0-9;]*m/', '', $rawList);
    // Extract email addresses from anywhere in the output
    if (preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $clean, $matches)) {
        $accounts = array_values(array_unique($matches[0]));
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link href="https://theimrans.tech/assets/css/tabulator.min.css" rel="stylesheet">
    <script src="https://theimrans.tech/assets/js/tabulator.min.js"></script>
    <title>Mailserver Account Manager</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, Helvetica, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; background: #f9f9f9; color: #222; }
        .card { background: #fff; padding: 22px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        h2 { margin-top: 0; color: #777777; }
        h3 { margin-top: 0; color: #777777; }
        label { display: block; margin-top: 10px; font-size: 0.9em; color: #555; }
        input, button { padding: 6px; font-size: 0.9em; border-radius: 4px; border: 1px solid #ccc; }
        input[type="email"], input[type="password"] { width: 100%; }
        button { background: #007bff; color: white; border: none; cursor: pointer; }
        button:hover { background: #0056b3; }
        button.danger { background: #dc3545; }
        button.danger:hover { background: #b02a37; }
        .msg { color: #155724; background: #d4edda; padding: 5px 14px; border-radius: 6px; margin-bottom: 14px; }
        .err { color: #721c24; background: #f8d7da; padding: 5px 14px; border-radius: 6px; margin-bottom: 14px; white-space: pre-wrap; }
        table { width: 100%; border-collapse: collapse; margin-top: 5px; font-size: 0.8em; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
        th { background: #f1f3f5; font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.03em; color: #555; }
        .topbar { text-align: right; margin-bottom: 8px; }
        .topbar a { color: #a8a8a8; text-decoration: none; font-size: 0.9em; }
        .topbar a:hover { color: #dc3545; }
        .inline-form { display: flex; gap: 6px; align-items: center; }
        .inline-form input[type="password"] { width: 140px; }
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .logout-link {
            color: #a8a8a8;
            text-decoration: none;
            font-size: 0.9em;
        }

        .logout-link:hover {
            color: #dc3545;
        }

    </style>
</head>
<body>

    
    <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card">
        <div class="header-row">
            <h3>Mailserver Account Manager</h3>
            <a href="?logout=1" class="logout-link">Logout</a>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="add">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="user@<?= htmlspecialchars($MAIL_DOMAIN) ?>">
            <label>Password</label>
            <input type="password" name="password" required placeholder="Enter password" minlength="8">
            <button type="submit" style="margin-top:14px;">Create New Email</button>
        </form>
    </div>

    <div class="card">
        <h3>Existing Accounts (<span id="account-count"><?= count($accounts); ?></span>)</h3>
        <div id="accounts-table"></div>
    </div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Account data injected from PHP
    const accounts = <?= json_encode(array_map(fn($e) => ['email' => $e], $accounts)) ?>;

    const table = new Tabulator("#accounts-table", {
    data: accounts,
    layout: "fitColumns",
    initialSort: [
        { column: "email", dir: "asc" }
    ],
    pagination: "local",
    paginationSize: 10,
    paginationSizeSelector: [5, 10, 20, 50, true],
    placeholder: "No accounts found.",
    columns: [
            {
                title: "Email",
                field: "email",
                sorter: "string",
                headerFilter: "input",
                widthGrow: 2
            },
            {
                title: "Change Password",
                field: "email",
                headerSort: false,
                formatter: function (cell) {
                    const email = cell.getValue();
                    return `<form method="POST" class="inline-form" style="margin:0;">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="email" value="${escapeHtml(email)}">
                        <input type="password" name="password" placeholder="New password" required minlength="8"
                               style="width:130px; padding:6px; font-size:0.9em; border:1px solid #ccc; border-radius:4px;">
                        <button type="submit" style="padding:6px 10px; font-size:0.9em;">Update</button>
                    </form>`;
                },
                widthGrow: 2
            },
            {
                title: "",
                field: "email",
                headerSort: false,
                formatter: function (cell) {
                    const email = cell.getValue();
                    return `<form method="POST" style="margin:0;"
                              onsubmit="return confirm('Delete ${escapeHtml(email).replace(/'/g, "\\'")}? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="email" value="${escapeHtml(email)}">
                        <button type="submit" class="danger" 
                                style="padding:6px 10px; font-size:0.9em;">Delete</button>
                    </form>`;
                },
                width: 90,
                hozAlign: "center"
            }
        ]
    });

    // Simple HTML escape for inline form values
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});
</script>
</body>
</html>
