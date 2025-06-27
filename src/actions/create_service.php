<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

// Configuration CORS et headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Gestion des requêtes OPTIONS pour CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Vérification de la méthode HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit();
}

// Chargement des variables d'environnement
try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();
} catch (Exception $e) {
    error_log('Erreur lors du chargement des variables d\'environnement : ' . $e->getMessage());
    echo json_encode(['error' => 'Erreur de configuration']);
    exit();
}

// Chargement de la configuration des services
$configFile = __DIR__ . '/../../config/services.json';
if (!file_exists($configFile)) {
    error_log('Fichier de configuration des services non trouvé : ' . $configFile);
    echo json_encode(['error' => 'Configuration des services non trouvée']);
    exit();
}

$configContent = file_get_contents($configFile);
$servicesConfig = json_decode($configContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log('Erreur lors du parsing de la configuration : ' . json_last_error_msg());
    echo json_encode(['error' => 'Configuration des services invalide']);
    exit();
}

// Lecture des données POST
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'Données JSON invalides']);
    exit();
}

// Validation des données
if (!isset($data['serviceType']) || !isset($data['idPdv'])) {
    echo json_encode(['error' => 'Données manquantes (serviceType ou idPdv)']);
    exit();
}

$serviceType = trim($data['serviceType']);
$idPdv = trim($data['idPdv']);
$sdaNumber = isset($data['sdaNumber']) ? trim($data['sdaNumber']) : '';

// Validation du type de service
if (!isset($servicesConfig[$serviceType])) {
    echo json_encode(['error' => 'Type de service non reconnu : ' . $serviceType]);
    exit();
}

// Validation de l'ID PDV
if (empty($idPdv) || !preg_match('/^[A-Za-z0-9_-]+$/', $idPdv)) {
    echo json_encode(['error' => 'ID PDV invalide. Seuls les lettres, chiffres, tirets et underscores sont autorisés.']);
    exit();
}

// Validation du numéro SDA si fourni
if (!empty($sdaNumber) && !preg_match('/^\+33[0-9]{9}$/', $sdaNumber)) {
    echo json_encode(['error' => 'Numéro SDA invalide. Format attendu : +33xxxxxxxxx']);
    exit();
}

try {
    // Initialisation du client ViaDialog
    $client = new ViaDialogClient(
        $_ENV['VIAD_API_USERNAME'],
        $_ENV['VIAD_API_PASSWORD'],
        $_ENV['VIAD_API_COMPANY'],
        $_ENV['VIAD_API_GRANT_TYPE'],
        $_ENV['VIAD_API_SLUG']
    );

    $serviceConfig = $servicesConfig[$serviceType];
    
    // Génération du nom du service
    $serviceName = str_replace('{{id_PDV}}', $idPdv, $serviceConfig['config']['name_format']);
    
    // Vérification de l'unicité du nom
    $finalServiceName = ensureUniqueServiceName($client, $serviceName);
    
    // Préparation du payload
    $payload = $serviceConfig['viad_payload'];
    $payload['label'] = $finalServiceName;
    
    // Gestion du numéro SDA pour les services sortants
    if (isset($serviceConfig['config']['has_sda_field']) && $serviceConfig['config']['has_sda_field']) {
        // Remplacer les placeholders par le numéro SDA (ou chaîne vide si anonyme)
        $payload['clinum'] = $sdaNumber;
        $payload['numDisplayed'] = $sdaNumber;
        
        error_log("Service sortant configuré avec SDA: " . ($sdaNumber ?: 'anonyme'));
    }
    
    // Log du payload pour debugging
    error_log('Payload à envoyer : ' . json_encode($payload, JSON_PRETTY_PRINT));
    
    // Création du service via la nouvelle méthode
    $response = $client->createService($payload);
    
    $serviceId = $response['id'] ?? null;
    if (!$serviceId) {
        throw new ApiException("ID du service non retourné par l'API");
    }
    
    // Gestion des webhooks
    $webhookResults = [];
    if (isset($serviceConfig['config']['webhook_ids']) && !empty($serviceConfig['config']['webhook_ids'])) {
        try {
            $webhookResults = $client->addServiceToWebhooks($serviceId, $serviceConfig['config']['webhook_ids']);
            error_log('Webhooks traités : ' . json_encode($webhookResults, JSON_PRETTY_PRINT));
        } catch (Exception $webhookException) {
            error_log('Erreur lors de la gestion des webhooks : ' . $webhookException->getMessage());
            // On continue même si les webhooks échouent
        }
    }
    
    // Réponse de succès
    echo json_encode([
        'success' => true,
        'serviceName' => $finalServiceName,
        'serviceId' => $serviceId,
        'serviceDetails' => [
            'label' => $response['label'] ?? $finalServiceName,
            'product' => $response['product'] ?? 'VIACONTACT',
            'status' => $response['status'] ?? 'OPEN',
            'enable' => $response['enable'] ?? true
        ],
        'webhookResults' => $webhookResults,
        'originalResponse' => $response
    ]);

} catch (ApiException $e) {
    error_log('Erreur API ViaDialog : ' . $e->getMessage());
    echo json_encode(['error' => 'Erreur API : ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log('Erreur inattendue : ' . $e->getMessage());
    echo json_encode(['error' => 'Erreur inattendue : ' . $e->getMessage()]);
}

/**
 * Vérifie l'unicité du nom de service et ajoute un suffixe si nécessaire
 */
function ensureUniqueServiceName(ViaDialogClient $client, string $baseName): string
{
    try {
        // Récupération de tous les services pour vérifier l'unicité
        $existingServices = $client->getServiceList([], 1000);
        $existingNames = array_map(function($service) {
            return $service->getLabel();
        }, $existingServices);
        
        $finalName = $baseName;
        $counter = 1;
        
        // Tant que le nom existe, on incrémente le compteur
        while (in_array($finalName, $existingNames)) {
            $counter++;
            $finalName = $baseName . '-' . $counter;
        }
        
        if ($counter > 1) {
            error_log("Nom de service modifié de '$baseName' vers '$finalName' pour éviter les doublons");
        }
        
        return $finalName;
        
    } catch (Exception $e) {
        error_log('Erreur lors de la vérification d\'unicité : ' . $e->getMessage());
        // En cas d'erreur, on retourne le nom de base
        return $baseName;
    }
}
?>