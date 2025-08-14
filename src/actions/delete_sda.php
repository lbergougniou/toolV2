<?php
/**
 * Delete SDA - Suppression de tous les SDA d'un service par lots de 50 et désactivation
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

/**
 * Supprime les SDA par lots pour éviter les timeouts
 */
function deleteSdaByBatches($client, $serviceDetails, $accessToken, $batchSize = 50) {
    $httpClient = new \GuzzleHttp\Client([
        'base_uri' => 'https://viaflow-dashboard.viadialog.com',
        'verify' => false,
        'curl' => [
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ],
    ]);

    $originalSdaCount = isset($serviceDetails['sdaLists']) ? count($serviceDetails['sdaLists']) : 0;
    $currentSdaList = $serviceDetails['sdaLists'] ?? [];
    $totalDeleted = 0;
    $batchNumber = 1;

    error_log("Début suppression par lots : $originalSdaCount SDA à supprimer par lots de $batchSize");

    // Tant qu'il reste des SDA à supprimer
    while (count($currentSdaList) > 0) {
        // Calculer la taille du lot actuel
        $currentBatchSize = min($batchSize, count($currentSdaList));
        
        // Supprimer les X premiers SDA du lot
        $sdaToRemove = array_slice($currentSdaList, 0, $currentBatchSize);
        $remainingSda = array_slice($currentSdaList, $currentBatchSize);
        
        error_log("Lot $batchNumber : suppression de $currentBatchSize SDA, reste " . count($remainingSda) . " SDA");
        
        // Préparer les données du service avec les SDA restants
        $serviceDetails['sdaLists'] = $remainingSda;
        
        // Si c'est le dernier lot, désactiver aussi le service
        if (count($remainingSda) === 0) {
            $serviceDetails['enable'] = false;
            error_log("Dernier lot : désactivation du service");
        }

        // Faire la requête de mise à jour
        try {
            $response = $httpClient->put('/gw/provisioning/api/via-services', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $serviceDetails,
                'timeout' => 60
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new Exception("Erreur HTTP lors du lot $batchNumber : " . $response->getStatusCode());
            }

            $totalDeleted += $currentBatchSize;
            $currentSdaList = $remainingSda;
            
            error_log("Lot $batchNumber terminé avec succès. Total supprimé : $totalDeleted");
            
            // Pause entre les lots pour éviter de surcharger l'API
            if (count($remainingSda) > 0) {
                sleep(1);
            }
            
        } catch (Exception $e) {
            error_log("Erreur lors du lot $batchNumber : " . $e->getMessage());
            throw $e;
        }
        
        $batchNumber++;
        
        // Sécurité : éviter les boucles infinies
        if ($batchNumber > 100) {
            throw new Exception("Trop de lots traités, arrêt de sécurité");
        }
    }

    return [
        'totalDeleted' => $totalDeleted,
        'batchesProcessed' => $batchNumber - 1,
        'originalCount' => $originalSdaCount
    ];
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

    // Validation des données
    if (!$serviceId) {
        throw new Exception('ServiceId manquant');
    }

    error_log("=== DÉBUT SUPPRESSION SDA PAR LOTS ===");
    error_log("Service ID: $serviceId");

    // Récupération du service avant modification
    $serviceDetailsBefore = $client->getServiceDetails($serviceId);
    $sdaCountBefore = isset($serviceDetailsBefore['sdaLists']) ? count($serviceDetailsBefore['sdaLists']) : 0;

    error_log("Service avant suppression : $sdaCountBefore SDA");

    if ($sdaCountBefore === 0) {
        // Aucun SDA à supprimer, juste désactiver le service
        error_log("Aucun SDA à supprimer, désactivation du service uniquement");
        
        $serviceDetailsBefore['enable'] = false;
        
        // Authentification et mise à jour
        $httpClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://viaflow-dashboard.viadialog.com',
            'verify' => false,
            'curl' => [
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            ],
        ]);

        $authResponse = $httpClient->post('/gw/auth/login', [
            'json' => [
                'username' => $_ENV['VIAD_API_USERNAME'],
                'password' => $_ENV['VIAD_API_PASSWORD'], 
                'company' => $_ENV['VIAD_API_COMPANY'],
                'grant_type' => $_ENV['VIAD_API_GRANT_TYPE'],
                'slug' => $_ENV['VIAD_API_SLUG'],
            ],
        ]);

        $authData = json_decode($authResponse->getBody(), true);
        $accessToken = $authData['access_token'] ?? null;

        if (!$accessToken) {
            throw new Exception("Impossible d'obtenir le token d'authentification");
        }

        $response = $httpClient->put('/gw/provisioning/api/via-services', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => $serviceDetailsBefore,
            'timeout' => 60
        ]);

        sendJsonResponse([
            'success' => true,
            'message' => 'Service désactivé avec succès',
            'serviceId' => $serviceId,
            'sdaCountBefore' => 0,
            'sdaCountAfter' => 0,
            'deletedSdaCount' => 0,
            'serviceDisabled' => true
        ]);
    }

    // Authentification pour la suppression par lots
    $httpClient = new \GuzzleHttp\Client([
        'base_uri' => 'https://viaflow-dashboard.viadialog.com',
        'verify' => false,
        'curl' => [
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ],
    ]);

    $authResponse = $httpClient->post('/gw/auth/login', [
        'json' => [
            'username' => $_ENV['VIAD_API_USERNAME'],
            'password' => $_ENV['VIAD_API_PASSWORD'], 
            'company' => $_ENV['VIAD_API_COMPANY'],
            'grant_type' => $_ENV['VIAD_API_GRANT_TYPE'],
            'slug' => $_ENV['VIAD_API_SLUG'],
        ],
    ]);

    $authData = json_decode($authResponse->getBody(), true);
    $accessToken = $authData['access_token'] ?? null;

    if (!$accessToken) {
        throw new Exception("Impossible d'obtenir le token d'authentification");
    }

    // Suppression par lots avec taille de lot de 50
    $batchSize = 50;
    $result = deleteSdaByBatches($client, $serviceDetailsBefore, $accessToken, $batchSize);

    error_log("Suppression terminée : " . $result['totalDeleted'] . " SDA supprimés en " . $result['batchesProcessed'] . " lots");

    // Vérification finale
    $serviceDetailsAfter = $client->getServiceDetails($serviceId);
    $sdaCountAfter = isset($serviceDetailsAfter['sdaLists']) ? count($serviceDetailsAfter['sdaLists']) : 0;
    $isEnabled = $serviceDetailsAfter['enable'] ?? true;

    error_log("Vérification finale : $sdaCountAfter SDA restants, service " . ($isEnabled ? 'activé' : 'désactivé'));

    sendJsonResponse([
        'success' => true,
        'message' => 'Suppression SDA par lots réussie',
        'serviceId' => $serviceId,
        'sdaCountBefore' => $sdaCountBefore,
        'sdaCountAfter' => $sdaCountAfter,
        'deletedSdaCount' => $result['totalDeleted'],
        'batchesProcessed' => $result['batchesProcessed'],
        'serviceDisabled' => !$isEnabled,
        'batchSize' => $batchSize
    ]);

} catch (ApiException $e) {
    error_log("Erreur API lors de la suppression : " . $e->getMessage());
    sendJsonResponse(['error' => 'Erreur API : ' . $e->getMessage()], 500);
} catch (Exception $e) {
    error_log("Erreur lors de la suppression : " . $e->getMessage());
    sendJsonResponse(['error' => 'Erreur : ' . $e->getMessage()], 500);
}