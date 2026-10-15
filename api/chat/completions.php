<?php
file_put_contents('/tmp/macrodroid-request.log', date('c') . ' URI: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . PHP_EOL, FILE_APPEND);
// deepseek-bridge.php
// Receives text from MacroDroid, relays to DeepSeek, returns plain text response.

// --- Configuration ---
$apiKey = getenv('DEEPSEEK_API_KEY');
if (empty($apiKey)) {
    http_response_code(500);
    echo 'Error: DEEPSEEK_API_KEY environment variable is not set.';
    exit;
}

$expectedSecret = getenv('BRIDGE_SECRET');
if (empty($expectedSecret)) {
    http_response_code(500);
    echo 'Error: BRIDGE_SECRET environment variable is not set.';
    exit;
}

// --- Validate client secret ---
$clientSecret = $_POST['secret'] ?? $_SERVER['HTTP_X_BRIDGE_SECRET'] ?? '';
if (!hash_equals($expectedSecret, $clientSecret)) {
    http_response_code(403);
    echo 'Error: Forbidden.';
    exit;
}

// --- Validate input ---
$userText = $_POST['text'] ?? '';
if (empty(trim($userText))) {
    http_response_code(400);
    echo 'Error: No text provided.';
    exit;
}

// --- Prepare DeepSeek request ---
$payload = json_encode([
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'user', 'content' => $userText]
    ],
    'temperature' => 0.7,
    'stream' => false
]);

$ch = curl_init('https://api.deepseek.com/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// --- Handle errors ---
if ($response === false) {
    http_response_code(502);
    echo 'Error: Could not reach AI service. ' . $curlError;
    exit;
}

if ($httpCode !== 200) {
    http_response_code($httpCode);
    $errBody = json_decode($response, true);
    echo 'Error: API returned ' . $httpCode . '. ' . ($errBody['error']['message'] ?? 'Unknown error');
    exit;
}

// --- Extract and return reply ---
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo 'Error: Invalid response format.';
    exit;
}

echo $data['choices'][0]['message']['content'] ?? '';
