<?php
/**
 * Proxy HTTP — Contournement CORS
 *
 * Ce script sert d'intermédiaire entre le navigateur et une API distante.
 * Le navigateur ne peut pas appeler directement certaines URLs externes à cause
 * des restrictions CORS. En passant par ce proxy côté serveur PHP, la requête
 * est transmise sans blocage.
 *
 * Utilisation : POST avec les champs suivants
 *   - targetUrl  : URL de destination à appeler
 *   - authHeader : Valeur du header Auth (token d'authentification)
 *   - payload    : Corps JSON à envoyer
 *
 * Retourne un JSON : { httpCode, response } ou { error, httpCode }
 */

header('Content-Type: application/json');

$targetUrl  = $_POST['targetUrl']  ?? '';
$authHeader = $_POST['authHeader'] ?? '';
$payload    = $_POST['payload']    ?? '';

if (empty($targetUrl) || empty($payload)) {
    echo json_encode(['error' => 'Paramètres manquants', 'httpCode' => 400]);
    exit;
}

// Envoi de la requête vers l'URL cible
$ch = curl_init($targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Auth: ' . $authHeader,
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Désactivé en local

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
unset($ch);

if ($curlError) {
    echo json_encode(['error' => $curlError, 'httpCode' => 0]);
    exit;
}

echo json_encode([
    'httpCode' => $httpCode,
    'response' => $response,
]);
