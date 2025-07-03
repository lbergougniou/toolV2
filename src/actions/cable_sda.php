<?php
/**
 * Cable SDA - Mise à jour des numéros SDA d'un service
 * 
 * Ce script permet de mettre à jour les numéros SDA (Sélection Directe à l'Arrivée)
 * associés à un service ViaDialog existant.
 * 
 * Méthode HTTP acceptée : POST
 * Format des données : JSON
 * 
 * Structure attendue des données POST :
 * {
 *   "serviceId": "123456",
 *   "newSdas": ["0123456789", "0987654321"]
 * }
 */

// Configuration de base - Désactivation de l'affichage des erreurs en production
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Chargement de l'autoloader Composer pour les dépendances
require_once __DIR__ . '/../../vendor/autoload.php';

// Import des classes nécessaires
use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

/**
 * Envoie une réponse JSON avec le code de statut HTTP approprié
 * 
 * @param array $data Données à encoder en JSON
 * @param int $statusCode Code de statut HTTP (par défaut : 200)
 * @return void
 */
function sendJsonResponse($data, $statusCode = 200)
{
    // Définition du code de statut HTTP
    http_response_code($statusCode);
    
    // Définition du type de contenu
    header('Content-Type: application/json');
    
    // Envoi de la réponse JSON et arrêt du script
    echo json_encode($data);
    exit();
}

try {
    // Chargement des variables d'environnement depuis le fichier .env
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();

    // Initialisation du client API ViaDialog avec les credentials
    $client = new ViaDialogClient(
        $_ENV['VIAD_API_USERNAME'],    // Nom d'utilisateur API
        $_ENV['VIAD_API_PASSWORD'],    // Mot de passe API
        $_ENV['VIAD_API_COMPANY'],     // Identifiant de l'entreprise
        $_ENV['VIAD_API_GRANT_TYPE'],  // Type d'authentification
        $_ENV['VIAD_API_SLUG']         // Slug de l'API
    );

    // Vérification que la requête utilise la méthode POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Méthode HTTP non autorisée');
    }

    // Récupération et décodage des données JSON envoyées en POST
    $postData = json_decode(file_get_contents('php://input'), true);
    
    // Extraction et validation des paramètres requis
    $serviceId = $postData['serviceId'] ?? null;  // ID du service à modifier
    $newSdas = $postData['newSdas'] ?? [];        // Nouveaux numéros SDA

    // Validation des données d'entrée
    if (!$serviceId || empty($newSdas)) {
        throw new Exception('Données invalides');
    }

    // Appel à l'API pour mettre à jour le service avec les nouveaux SDA
    $updatedService = $client->updateServiceWithSda($serviceId, $newSdas);

    // Envoi de la réponse de succès avec les détails du service mis à jour
    sendJsonResponse([
        'success' => true,
        'message' => 'Service mis à jour avec succès',
        'updatedService' => $updatedService,
    ]);

} catch (ApiException $e) {
    // Gestion spécifique des erreurs de l'API ViaDialog
    sendJsonResponse(['error' => 'Erreur API : ' . $e->getMessage()], 500);
    
} catch (Exception $e) {
    // Gestion générale des autres types d'erreurs
    sendJsonResponse(['error' => 'Erreur : ' . $e->getMessage()], 500);
}