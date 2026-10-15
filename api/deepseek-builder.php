<?php
// deepseek-builder.php
// Acts as an OpenAI-compatible endpoint for MacroDroid's AI Macro Builder.

// --- Load API Key ---
$apiKey = getenv('DEEPSEEK_API_KEY');
if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['error' => ['message' => 'DEEPSEEK_API_KEY not set']]);
    exit;
}

// --- Read the JSON body from MacroDroid ---
$input = file_get_contents('php://input');
$request = json_decode($input, true);

// --- Extract the user's prompt (the last message) ---
$userMessage = '';
if (isset($request['messages']) && is_array($request['messages'])) {
    // Get the last message, which should be the user's description
    $lastMessage = end($request['messages']);
    $userMessage = $lastMessage['content'] ?? '';
}

if (empty($userMessage)) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'No prompt found in request']]);
    exit;
}

// --- Prepare the request to DeepSeek ---
$deepSeekPayload = json_encode([
    'model' => 'deepseek-chat', // Use the fast, standard model
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
    CURLOPT_POSTFIELDS => $deepSeekPayload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 60 // AI generation can take a bit longer
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// --- Handle Errors ---
if ($response === false || $httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => ['message' => 'DeepSeek API error: ' . ($curlError ?: 'HTTP ' . $httpCode)]]);
    exit;
}

$deepSeekData = json_decode($response, true);
$replyContent = $deepSeekData['choices'][0]['message']['content'] ?? '';

// --- Repackage the response in OpenAI format for MacroDroid ---
$openAiStyleResponse = [
    'id' => 'chatcmpl-' . uniqid(),
    'object' => 'chat.completion',
    'created' => time(),
    'model' => 'deepseek-chat',
    'choices' => [
        [
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => $replyContent
            ],
            'finish_reason' => 'stop'
        ]
    ],
    'usage' => [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0
    ]
];

header('Content-Type: application/json');
echo json_encode($openAiStyleResponse);
