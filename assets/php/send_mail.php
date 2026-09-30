<?php
// assets/php/send_mail.php
require_once __DIR__ . '/../../apps/staff/auth.php';
require_login();

header('Content-Type: application/json');

$rootDoc = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
if (!is_dir($rootDoc . '/apps') && is_dir('/volume1/web/apps')) {
    $rootDoc = '/volume1/web';
}

// CSRF
$postedToken = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Invalid CSRF token."]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "POST required."]);
    exit;
}

// Load SMTP config
$smtpFile = $rootDoc . '/apps/env/smtp.json';
if (!file_exists($smtpFile)) {
    echo json_encode(["status" => "error", "message" => "SMTP configuration not found."]);
    exit;
}
$smtp = json_decode(file_get_contents($smtpFile), true);
if (!is_array($smtp) || empty($smtp['host']) || empty($smtp['username'])) {
    echo json_encode(["status" => "error", "message" => "SMTP configuration is invalid."]);
    exit;
}

$to           = trim($_POST['to']          ?? '');
$subject      = trim($_POST['subject']     ?? 'Notice');
$messageHtml  = $_POST['message']          ?? '';

if (empty($to) || empty($messageHtml)) {
    echo json_encode(["status" => "error", "message" => "Recipient and message body cannot be empty."]);
    exit;
}

// Validate recipients
$validRecipients = [];
foreach (explode(',', $to) as $token) {
    $clean = trim($token);
    if (filter_var($clean, FILTER_VALIDATE_EMAIL)) {
        $validRecipients[] = $clean;
    }
}
$validRecipients = array_unique($validRecipients);

if (empty($validRecipients)) {
    echo json_encode(["status" => "error", "message" => "No valid recipient email addresses found."]);
    exit;
}

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = $smtp['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtp['username'];
    $mail->Password   = $smtp['password'];
    $mail->Port       = (int)($smtp['port'] ?? 2525);
    $mail->SMTPSecure = ($smtp['encryption'] ?? 'starttls') === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;

    $mail->setFrom(
        $smtp['from_email'] ?? 'admin@wowdesign.com.hk',
        $smtp['from_name']  ?? 'WowDesign Portal'
    );

    foreach ($validRecipients as $rcpt) {
        $mail->addAddress($rcpt);
    }

    if (!empty($smtp['bcc_email'])) {
        $mail->addBCC($smtp['bcc_email']);
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $messageHtml;
    $mail->AltBody = strip_tags($messageHtml);

    $mail->send();
    echo json_encode(["status" => "success", "message" => "Message sent."]);

} catch (Exception $e) {
    error_log("send_mail error: " . $mail->ErrorInfo);
    echo json_encode([
        "status" => "error",
        "message" => "Mailer error. Check server logs."
    ]);
}