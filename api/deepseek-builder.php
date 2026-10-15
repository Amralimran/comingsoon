<?php
// deepseek-builder.php
// OpenAI-compatible endpoint for MacroDroid AI Macro Builder.

// --- Load API Key ---
$apiKey = getenv('DEEPSEEK_API_KEY');
if (empty($apiKey)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'DEEPSEEK_API_KEY not set']]);
    exit;
}

// --- Read JSON body (not form data) ---
$input = file_get_contents('php://input');
$request = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE || !$request) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'Invalid JSON body']]);
    exit;
}

// --- Extract the user's prompt ---
$userMessage = '';
if (isset($request['messages']) && is_array($request['messages'])) {
    // Get the last user message (the description)
    foreach (array_reverse($request['messages']) as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $userMessage = $msg['content'] ?? '';
            break;
        }
    }
}

if (empty(trim($userMessage))) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'No user message found']]);
    exit;
}

// --- Send to DeepSeek ---
$payload = json_encode([
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'user', 'content' => $userMessage]
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
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// --- Handle errors ---
if ($response === false || $httpCode !== 200) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'DeepSeek error: ' . ($curlError ?: 'HTTP ' . $httpCode)]]);
    exit;
}

$data = json_decode($response, true);
$reply = $data['choices'][0]['message']['content'] ?? '';

// --- Return OpenAI-compatible JSON ---
header('Content-Type: application/json');
echo json_encode([
    'id' => 'chatcmpl-' . uniqid(),
    'object' => 'chat.completion',
    'created' => time(),
    'model' => 'deepseek-chat',
    'choices' => [
        [
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => $reply
            ],
            'finish_reason' => 'stop'
        ]
    ],
    'usage' => [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0
    ]
]);
