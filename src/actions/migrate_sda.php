<?php
/**
 * Migration SDA - Ajout direct de numéros SDA à un service
 */

// Configuration de base
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Chargement de l'autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

/**
 * Envoie une réponse JSON
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

try {
    // Chargement des variables d'environnement
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();

    // Initialisation du client API
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

    // Récupération des données POST
    $postData = json_decode(file_get_contents('php://input'), true);
    $serviceId = $postData['serviceId'] ?? null;
    $newSdas = $postData['newSdas'] ?? [];

    // Validation des données
    if (!$serviceId || empty($newSdas)) {
        throw new Exception('Données invalides');
    }

    // Récupération du service avant modification
    $serviceDetailsBefore = $client->getServiceDetails($serviceId);
    $sdaCountBefore = isset($serviceDetailsBefore['sdaLists']) ? count($serviceDetailsBefore['sdaLists']) : 0;

    // Récupérer les SDA existants
    $existingSdas = [];
    if (isset($serviceDetailsBefore['sdaLists'])) {
        foreach ($serviceDetailsBefore['sdaLists'] as $sda) {
            $existingSdas[] = $sda['commercial'] ?? $sda['technique'] ?? '';
        }
    }

    // Filtrer les nouveaux SDA
    $sdaToAdd = [];
    $alreadyExists = [];
    
    foreach ($newSdas as $sda) {
        if (in_array($sda, $existingSdas)) {
            $alreadyExists[] = $sda;
        } else {
            $sdaToAdd[] = $sda;
        }
    }

    // Si tous les SDA existent déjà
    if (empty($sdaToAdd)) {
        sendJsonResponse([
            'success' => false,
            'error' => 'Tous les SDA demandés existent déjà sur ce service',
            'alreadyExists' => $alreadyExists,
            'totalSdaCount' => $sdaCountBefore
        ]);
    }

    // Mise à jour du service
    $updatedService = $client->updateServiceWithSda($serviceId, $sdaToAdd);

    // Récupération du service après modification
    $serviceDetailsAfter = $client->getServiceDetails($serviceId);
    $sdaCountAfter = isset($serviceDetailsAfter['sdaLists']) ? count($serviceDetailsAfter['sdaLists']) : 0;

    // Réponse de succès
    sendJsonResponse([
        'success' => true,
        'message' => 'Migration SDA réussie',
        'serviceId' => $serviceId,
        'sdaCountBefore' => $sdaCountBefore,
        'sdaCountAfter' => $sdaCountAfter,
        'totalSdaCount' => $sdaCountAfter,
        'addedSdaCount' => count($sdaToAdd),
        'requestedSdaCount' => count($newSdas),
        'addedSdas' => $sdaToAdd,
        'alreadyExists' => $alreadyExists
    ]);

} catch (ApiException $e) {
    sendJsonResponse(['error' => 'Erreur API : ' . $e->getMessage()], 500);
} catch (Exception $e) {
    sendJsonResponse(['error' => 'Erreur : ' . $e->getMessage()], 500);
}