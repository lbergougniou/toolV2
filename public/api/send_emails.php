<?php

/**
 * API Endpoint pour l'envoi d'emails de test
 * Route : POST /public/api/send_emails.php
 *
 * @author Luc Bergougniou
 * @copyright 2025 Scorimmo
 */

// Headers pour l'API REST
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Gestion du preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Chargement de l'autoloader Composer et des classes
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Database/DatabaseConnection.php';
require_once __DIR__ . '/../../src/Email/EmailTestSender.php';
require_once __DIR__ . '/../../src/Logger/Logger.php';

use Database\DatabaseConnection;
use Email\EmailTestSender;
use App\Logger\Logger;

// Initialisation du logger
$logger = new Logger(__DIR__ . '/../../logs/send_emails_api.log', Logger::LEVEL_DEBUG);
$logger->info('=== Nouvelle requête API ===', [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'] ?? 'N/A'
]);

// Vérification de la méthode HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $logger->warning('Méthode HTTP non autorisée', ['method' => $_SERVER['REQUEST_METHOD']]);
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Méthode non autorisée. Utilisez POST.'
    ]);
    exit;
}

try {
    // Récupération des paramètres POST
    $input = file_get_contents('php://input');
    $logger->debug('Données POST reçues', ['raw_input' => $input]);

    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $jsonError = json_last_error_msg();
        $logger->error('JSON invalide', ['error' => $jsonError, 'input' => $input]);
        throw new Exception('JSON invalide : ' . $jsonError);
    }

    $logger->info('Données décodées', ['data' => $data]);

    // Validation des paramètres requis
    if (!isset($data['email_type'])) {
        $logger->error('Paramètre manquant : email_type');
        throw new Exception('Le paramètre email_type est requis');
    }

    $emailType = $data['email_type'];
    $quantity = isset($data['quantity']) ? intval($data['quantity']) : 1;
    $days = isset($data['days']) ? intval($data['days']) : 20;

    $logger->info('Paramètres validés', [
        'email_type' => $emailType,
        'quantity' => $quantity,
        'days' => $days
    ]);

    // Validation de la quantité (1-20)
    if ($quantity < 1 || $quantity > 20) {
        $logger->error('Quantité invalide', ['quantity' => $quantity]);
        throw new Exception('La quantité doit être entre 1 et 20');
    }

    // Connexion à la base de données
    $logger->debug('Tentative de connexion à la base de données');
    $db = DatabaseConnection::getInstance();
    $logger->info('Connexion à la base de données réussie');

    // Création du service d'envoi d'emails
    $logger->debug('Création du service EmailTestSender');
    $emailSender = new EmailTestSender($db);

    // Récupération et envoi des emails
    $logger->info('Début de la récupération et de l\'envoi des emails');
    $result = $emailSender->fetchAndSendEmails($emailType, $quantity, $days);

    $logger->info('Envoi terminé', [
        'success' => $result['success'],
        'emails_found' => $result['emails_found'] ?? 0,
        'success_count' => $result['success_count'] ?? 0
    ]);

    // Retour de la réponse
    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    // Gestion des erreurs
    $logger->critical('Erreur lors du traitement de la requête', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

$logger->info('=== Fin de la requête API ===');
