<?php
// Configuration de base
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

// Fonction pour envoyer une réponse JSON
function sendJsonResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

try {
    // Chargement des variables d'environnement
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();

    // Initialisation du client ViaDialog
    $client = new ViaDialogClient(
        $_ENV['VIAD_API_USERNAME'],
        $_ENV['VIAD_API_PASSWORD'],
        $_ENV['VIAD_API_COMPANY'],
        $_ENV['VIAD_API_GRANT_TYPE'],
        $_ENV['VIAD_API_SLUG']
    );

    // Vérification de la méthode HTTP
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Méthode HTTP non autorisée');
    }

    // Récupération et validation des données POST
    $postData = json_decode(file_get_contents('php://input'), true);
    $serviceId = $postData['serviceId'] ?? null;
    $newSdas = $postData['newSdas'] ?? [];

    if (!$serviceId || empty($newSdas)) {
        throw new Exception('Données invalides');
    }

    // Mise à jour du service avec les nouveaux SDA
    $updatedService = $client->updateServiceWithSda($serviceId, $newSdas);

    // Envoi de la réponse de succès
    sendJsonResponse([
        'success' => true,
        'message' => 'Service mis à jour avec succès',
        'updatedService' => $updatedService,
    ]);
} catch (ApiException $e) {
    // Gestion des erreurs spécifiques à l'API
    sendJsonResponse(['error' => 'Erreur API : ' . $e->getMessage()], 500);
} catch (Exception $e) {
    // Gestion des autres erreurs
    sendJsonResponse(['error' => 'Erreur : ' . $e->getMessage()], 500);
}
