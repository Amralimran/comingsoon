<?php
// assets/php/rtf_editor.php
require_once __DIR__ . '/../../apps/staff/auth.php';
require_login();

$rootDoc = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
if (!is_dir($rootDoc . '/apps') && is_dir('/volume1/web/apps')) {
    $rootDoc = '/volume1/web';
}

// Only HR can compose broadcast emails (adjust if you want a broader role).
$appRoles = get_app_roles('messages', get_logged_in_employee());
$isHr     = in_array('hr', $appRoles, true);
if (!$isHr) {
    header("Location: /apps/staff/index.php");
    exit;
}

// CSRF token for the send action
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$mailerParam = $_GET['mailer'] ?? 'false';
$isMailer = ($mailerParam === 'true' || $mailerParam === '1');
$recipientsParam = $_GET['recipients'] ?? '';
$resolvedEmails = [];

if ($isMailer && !empty($recipientsParam)) {
    $staffFile = $rootDoc . '/apps/hr/staff.json';
    $staffData = file_exists($staffFile) ? json_decode(file_get_contents($staffFile), true) : [];

    $tokens = array_map('trim', explode(',', $recipientsParam));

    foreach ($tokens as $token) {
        if ($token === '') continue;

        if (str_ends_with(strtolower($token), '.json')) {
            // Only allow known safe JSON files: recipients.json in messages, or
            // stafflist-style files. Resolve and check the path.
            $candidates = [
                $rootDoc . '/apps/messages/' . basename($token),
                $rootDoc . '/apps/staff/'   . basename($token),
            ];
            foreach ($candidates as $jsonPath) {
                if (!file_exists($jsonPath)) continue;
                $real = realpath($jsonPath);
                $allowedRoots = [
                    realpath($rootDoc . '/apps/messages'),
                    realpath($rootDoc . '/apps/staff'),
                ];
                $ok = false;
                foreach ($allowedRoots as $root) {
                    if ($root && $real && strpos($real, $root . DIRECTORY_SEPARATOR) === 0) {
                        $ok = true; break;
                    }
                }
                if (!$ok) continue;

                $jsonList = json_decode(file_get_contents($real), true);
                if (is_array($jsonList)) {
                    array_walk_recursive($jsonList, function($val) use (&$resolvedEmails) {
                        if (is_string($val) && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                            $resolvedEmails[] = $val;
                        }
                    });
                }
                break;
            }
            continue;
        }

        if (filter_var($token, FILTER_VALIDATE_EMAIL)) {
            $resolvedEmails[] = $token;
            continue;
        }

        $cleanId = str_pad(preg_replace('/[^0-9]/', '', $token), 3, '0', STR_PAD_LEFT);
        $empKey = 'employee-' . $cleanId;
        if (isset($staffData[$empKey]) && !empty($staffData[$empKey]['email'])) {
            $resolvedEmails[] = $staffData[$empKey]['email'];
            continue;
        }

        foreach ($staffData as $profile) {
            $dept  = strtolower($profile['department'] ?? '');
            $title = strtolower($profile['title'] ?? '');
            $role  = strtolower($profile['roles'] ?? '');
            if (($dept === strtolower($token) || $title === strtolower($token) || $role === strtolower($token))
                && !empty($profile['email'])) {
                $resolvedEmails[] = $profile['email'];
            }
        }
    }
}

$toAddresses = !empty($resolvedEmails) ? implode(', ', array_unique($resolvedEmails)) : '';
$defaultSubject = $_GET['subject'] ?? ($isMailer ? 'Notice' : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RTF Message Editor & SMTP Mailer</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; background: #f4f6f8; color: #333; }
        .editor-container { max-width: 850px; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin: auto; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 13px; }
        .form-control { width: 100%; padding: 8px 12px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        
        .rtf-toolbar { display: flex; gap: 5px; align-items: center; margin-bottom: 0; background: #f8f9fa; padding: 6px; border: 1px solid #ccc; border-bottom: none; border-radius: 4px 4px 0 0; flex-wrap: wrap; }
        .rtf-btn { width: 32px; height: 32px; background: #fff; border: 1px solid #ccc; border-radius: 4px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 13px; }
        .rtf-btn:hover { background: #e9ecef; border-color: #adb5bd; }
        .rtf-select { height: 32px; padding: 0 8px; border: 1px solid #ccc; border-radius: 4px; background: #fff; font-size: 13px; cursor: pointer; }
        
        .editable-area { width: 100%; min-height: 250px; padding: 12px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 0 0 4px 4px; font-size: 14px; font-family: inherit; background: #fff; outline: none; overflow-y: auto; }
        
        .btn-group { display: flex; gap: 10px; margin-top: 20px; align-items: center; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #28a745; color: white; }
        .btn-primary:hover { background: #218838; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        
        /* Fading status line styling */
        #statusMessage {
            font-size: 13px;
            font-weight: bold;
            color: #28a745;
            opacity: 1;
            transition: opacity 1.5s ease-in-out;
            margin-left: 5px;
        }
        #statusMessage.fade-out {
            opacity: 0;
        }
    </style>
</head>
<body>

<div class="editor-container">
    <h2>Compose & Send Message (LAN SMTP)</h2>
    <form id="mailForm" onsubmit="sendEmail(event)">
        <div class="form-group">
            <label>To:</label>
            <input type="text" id="toField" name="to" class="form-control" value="<?= htmlspecialchars($toAddresses) ?>" required>
        </div>
        <div class="form-group">
            <label>Subject:</label>
            <input type="text" id="subjectField" name="subject" class="form-control" value="<?= htmlspecialchars($defaultSubject) ?>" required>
        </div>
        
        <div class="form-group">
            <label>Message Body:</label>
            <div class="rtf-toolbar">
                <button type="button" class="rtf-btn" onclick="formatText('bold')" title="Bold"><b>B</b></button>
                <button type="button" class="rtf-btn" onclick="formatText('italic')" title="Italic"><i>I</i></button>
                <button type="button" class="rtf-btn" onclick="formatText('underline')" title="Underline"><u>U</u></button>
                <span style="border-left: 1px solid #ccc; margin: 0 4px; height: 20px;"></span>
                <button type="button" class="rtf-btn" onclick="formatText('justifyLeft')" title="Align Left">⬅</button>
                <button type="button" class="rtf-btn" onclick="formatText('justifyCenter')" title="Align Center">⬌</button>
                <span style="border-left: 1px solid #ccc; margin: 0 4px; height: 20px;"></span>
                <button type="button" class="rtf-btn" onclick="formatText('insertUnorderedList')" title="Bullet List">•</button>
                <button type="button" class="rtf-btn" onclick="formatText('insertOrderedList')" title="Numbered List">1.</button>
                <span style="border-left: 1px solid #ccc; margin: 0 4px; height: 20px;"></span>
                <select class="rtf-select" onchange="setFontSize(this.value)" title="Font Size">
                    <option value="">Font Size</option>
                    <option value="1">Small (1)</option>
                    <option value="3">Normal (3)</option>
                    <option value="5">Large (5)</option>
                    <option value="7">Huge (7)</option>
                </select>
            </div>
            <div id="editorBody" class="editable-area" contenteditable="true"></div>
        </div>

        <div class="btn-group">
            <button type="submit" id="sendBtn" class="btn btn-primary">Send via LAN SMTP ✉️</button>
            <a href="/apps/messages/index.php" class="btn btn-secondary">Cancel ✖️</a>
            <span id="statusMessage"></span>
        </div>
    </form>
</div>

<script>
function formatText(command) {
    document.execCommand(command, false, null);
    document.getElementById('editorBody').focus();
}

function setFontSize(sizeVal) {
    if (!sizeVal) return;
    document.execCommand('fontSize', false, sizeVal);
    document.getElementById('editorBody').focus();
}

function sendEmail(event) {
    event.preventDefault();
    
    let sendBtn = document.getElementById('sendBtn');
    let statusMsg = document.getElementById('statusMessage');
    
    sendBtn.disabled = true;
    sendBtn.innerText = "Sending...";
    statusMsg.className = "";
    statusMsg.innerText = "";

    let formData = new FormData();
    formData.append('to', document.getElementById('toField').value);
    formData.append('subject', document.getElementById('subjectField').value);
    formData.append('message', document.getElementById('editorBody').innerHTML);
    formData.append('csrf_token', "<?= htmlspecialchars($csrfToken) ?>");   // ← moved here

    fetch('send_mail.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())  // Parse the JSON response properly
    .then(data => {
        sendBtn.disabled = false;
        sendBtn.innerText = "Send via LAN SMTP ✉️";
        
        if (data.status === 'success') {
            statusMsg.style.color = "#28a745";
            statusMsg.innerText = "✓ Message sent successfully!";
            
            setTimeout(() => {
                statusMsg.classList.add('fade-out');
            }, 1500);

            setTimeout(() => {
                window.location.href = '/apps/messages/index.php';
            }, 2500);
        } else {
            statusMsg.style.color = "#dc3545";
            statusMsg.innerText = "✗ " + data.message;
        }
    })
    .catch(err => {
        sendBtn.disabled = false;
        sendBtn.innerText = "Send via LAN SMTP ✉️";
        statusMsg.style.color = "#dc3545";
        statusMsg.innerText = "✗ Network or server error.";
    });
}
</script>

</body>
</html>