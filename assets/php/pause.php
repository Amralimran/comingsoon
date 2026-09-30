<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
// assets/php/pause.php
require_once __DIR__ . '/../../apps/staff/auth.php';  // session, helpers, ADMIN_EMP_ID
require_once __DIR__ . '/alert_helper.php';
require_login();

header('Content-Type: application/json');

$rootDoc = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
if (!is_dir($rootDoc . '/apps') && is_dir('/volume1/web/apps')) {
    $rootDoc = '/volume1/web';
}

// POST + CSRF only. GET is no longer accepted.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "POST required."]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

if (!hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Invalid CSRF token."]);
    exit;
}

$requestedEmp = $input['emp']    ?? '';
$target       = $input['target'] ?? 'expiry';
$days         = intval($input['days'] ?? 30);

// Whitelist the target so nothing weird gets written into a filename.
$allowedTargets = ['expiry', 'timesheets'];
if (!in_array($target, $allowedTargets, true)) {
    echo json_encode(["status" => "error", "message" => "Invalid target."]);
    exit;
}

if ($days < 1 || $days > 365) {
    echo json_encode(["status" => "error", "message" => "Days must be between 1 and 365."]);
    exit;
}

// Policy: timesheet reminders are payroll-critical, so pause duration is capped.
// Other alert types can pause for longer.
$maxDays = ($target === 'timesheets') ? 7 : 30;
$capped = false;
if ($days > $maxDays) {
    $days = $maxDays;
    $capped = true;
}

// ---------------------------------------------------------------------------
// Authorization: caller can only pause their own alerts, or a role they hold.
// ---------------------------------------------------------------------------
$loggedEmp = get_logged_in_employee();
if ($loggedEmp === null) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "No valid session."]);
    exit;
}

$requestedEmpNorm = auth_normalize_emp_id($requestedEmp);
$isRoleTarget     = str_starts_with(strtolower(trim((string)$requestedEmp)), 'auth_');

if ($isRoleTarget) {
    // e.g. "auth_hr" — only members of that role can pause it.
    $roleName = str_replace('auth_', '', strtolower(trim($requestedEmp)));
    $authEntries = json_decode(
        file_get_contents($rootDoc . '/apps/staff/auth.json') ?: '[]',
        true
    );
    $holdsRole = false;
    if (is_array($authEntries)) {
        foreach ($authEntries as $entry) {
            if (!is_array($entry) || count($entry) < 2) continue;
            $eId = auth_normalize_emp_id($entry[0]);
            if ($eId !== $loggedEmp) continue;
            $roles = array_map('strtolower', array_map('trim', array_slice($entry, 1)));
            if (in_array($roleName, $roles, true)) {
                $holdsRole = true;
                break;
            }
        }
    }
    if (!$holdsRole && !is_admin()) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Access denied."]);
        exit;
    }
} else {
    // Direct employee target — must match the caller, unless admin.
    if (!is_admin() && $requestedEmpNorm !== $loggedEmp) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Access denied."]);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Determine which tracker directory holds the pause marker.
// ---------------------------------------------------------------------------
if ($isRoleTarget) {
    $trackerDir = $rootDoc . "/apps/personal/";
    $empKey     = 'auth_' . str_replace('auth_', '', strtolower(trim($requestedEmp)));
} else {
    $trackerDir = $rootDoc . "/staff/{$requestedEmpNorm}/";
    $empKey     = $requestedEmpNorm;
}

if (!is_dir($trackerDir)) {
    @mkdir($trackerDir, 0755, true);
}

$pauseUntil = date('Y-m-d', strtotime("+{$days} days"));
file_put_contents($trackerDir . ".pause_{$target}", $pauseUntil, LOCK_EX);

// ---------------------------------------------------------------------------
// Remove the relevant alerts (by type, not by substring).
// ---------------------------------------------------------------------------
if (!$isRoleTarget) {
    remove_alerts_of_type($rootDoc, $requestedEmpNorm, $target);
}

echo json_encode([
    "status"      => "success",
    "pause_until" => $pauseUntil,
    "capped"      => $capped,
    "max_days"    => $maxDays
]);
exit;