<?php
// API Proxy pour éviter les problèmes CORS
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

$targetUrl = $_POST['targetUrl'] ?? '';
$authHeader = $_POST['authHeader'] ?? '';
$payload = $_POST['payload'] ?? '';

if (empty($targetUrl) || empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'URL ou payload manquant']);
    exit;
}

// Initialisation cURL
$ch = curl_init($targetUrl);

// Configuration cURL
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Auth: ' . $authHeader
    ],
    CURLOPT_SSL_VERIFYPEER => false, // À retirer en production
    CURLOPT_TIMEOUT => 30
]);

// Exécution
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

// Retour de la réponse
if ($error) {
    http_response_code(500);
    echo json_encode([
        'error' => $error,
        'response' => $response
    ]);
} else {
    http_response_code($httpCode);
    echo json_encode([
        'httpCode' => $httpCode,
        'response' => $response
    ]);
}
